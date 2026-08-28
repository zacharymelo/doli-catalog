<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/dolicataloglineadder.class.php
 * \ingroup dolicatalog
 * \brief   Resolves prices and appends picked products as document lines.
 *
 * The six supported document classes each expose addline() with a different
 * positional signature, so every type gets its own explicit adapter below
 * rather than a generic call. Price and VAT resolution mirrors what the native
 * card pages do when a predefined product is chosen.
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';

/**
 * Adds picked catalog items to a commercial document.
 */
class DoliCatalogLineAdder
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Last error message */
	public $error = '';

	/** @var string[] Error stack */
	public $errors = array();

	/**
	 * Document types the picker can add lines to.
	 *
	 * mode  — 'sell' uses customer pricing, 'buy' uses supplier pricing
	 * perm  — [module, object, action] triplet checked with User::hasRight()
	 *
	 * @var array<string,array{class:string,path:string,mode:string,perm:array{0:string,1:string,2:string},context:string}>
	 */
	private static $TYPES = array(
		'propal' => array(
			'class' => 'Propal',
			'path' => '/comm/propal/class/propal.class.php',
			'mode' => 'sell',
			'perm' => array('propal', 'creer', ''),
			'context' => 'propalcard',
		),
		'commande' => array(
			'class' => 'Commande',
			'path' => '/commande/class/commande.class.php',
			'mode' => 'sell',
			'perm' => array('commande', 'creer', ''),
			'context' => 'ordercard',
		),
		'facture' => array(
			'class' => 'Facture',
			'path' => '/compta/facture/class/facture.class.php',
			'mode' => 'sell',
			'perm' => array('facture', 'creer', ''),
			'context' => 'invoicecard',
		),
		'supplier_proposal' => array(
			'class' => 'SupplierProposal',
			'path' => '/supplier_proposal/class/supplier_proposal.class.php',
			'mode' => 'buy',
			'perm' => array('supplier_proposal', 'creer', ''),
			'context' => 'supplier_proposalcard',
		),
		'order_supplier' => array(
			'class' => 'CommandeFournisseur',
			'path' => '/fourn/class/fournisseur.commande.class.php',
			'mode' => 'buy',
			'perm' => array('fournisseur', 'commande', 'creer'),
			'context' => 'ordersuppliercard',
		),
		'invoice_supplier' => array(
			'class' => 'FactureFournisseur',
			'path' => '/fourn/class/fournisseur.facture.class.php',
			'mode' => 'buy',
			'perm' => array('fournisseur', 'facture', 'creer'),
			'context' => 'invoicesuppliercard',
		),
		// A bill of materials has no thirdparty and no pricing: lines are just
		// a component and a quantity, so it uses its own 'bom' mode.
		'bom' => array(
			'class' => 'BOM',
			'path' => '/bom/class/bom.class.php',
			'mode' => 'bom',
			'perm' => array('bom', 'write', ''),
			'context' => 'bomcard',
		),
	);

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Descriptor for a supported document type.
	 *
	 * @param  string     $type Document type key
	 * @return array<string,mixed>|null Descriptor, or null when unsupported
	 */
	public static function getTypeDef($type)
	{
		return isset(self::$TYPES[$type]) ? self::$TYPES[$type] : null;
	}

	/**
	 * All supported document type keys.
	 *
	 * @return string[] Type keys
	 */
	public static function getSupportedTypes()
	{
		return array_keys(self::$TYPES);
	}

	/**
	 * Map a hook context back to a document type key.
	 *
	 * @param  string      $context Hook context, e.g. 'ordercard'
	 * @return string|null          Document type key, or null
	 */
	public static function typeFromContext($context)
	{
		foreach (self::$TYPES as $key => $def) {
			if ($def['context'] === $context) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Whether the current user may add lines to this document type.
	 *
	 * @param  string $type Document type key
	 * @param  User   $user User to check
	 * @return bool         True when permitted
	 */
	public static function userCanCreate($type, $user)
	{
		$def = self::getTypeDef($type);
		if (empty($def)) {
			return false;
		}
		list($mod, $obj, $act) = $def['perm'];

		return $act === '' ? (bool) $user->hasRight($mod, $obj) : (bool) $user->hasRight($mod, $obj, $act);
	}

	/**
	 * Load and fetch a document.
	 *
	 * @param  string $type Document type key
	 * @param  int    $id   Document id
	 * @return CommonObject|null Fetched document, or null when not found
	 */
	public function fetchDocument($type, $id)
	{
		$def = self::getTypeDef($type);
		if (empty($def) || $id <= 0) {
			$this->error = 'UnsupportedDocumentType';
			return null;
		}

		require_once DOL_DOCUMENT_ROOT.$def['path'];

		$classname = $def['class'];
		/** @var CommonObject $object */
		$object = new $classname($this->db);
		if ($object->fetch($id) <= 0) {
			$this->error = 'DocumentNotFound';
			return null;
		}
		// A BOM has no linked thirdparty; everything else prices against one.
		if ($def['mode'] !== 'bom' && method_exists($object, 'fetch_thirdparty')) {
			$object->fetch_thirdparty();
		}

		return $object;
	}

	/**
	 * Whether a document is still a draft and will accept new lines.
	 *
	 * Commercial documents expose the legacy $statut; BOM exposes only $status.
	 * Draft is 0 for every type the picker supports.
	 *
	 * @param  CommonObject $object Document
	 * @return bool                 True when the document is a draft
	 */
	public static function isDraft($object)
	{
		if (isset($object->status) && $object->status !== null && $object->status !== '') {
			return ((int) $object->status) === 0;
		}
		if (isset($object->statut) && $object->statut !== null && $object->statut !== '') {
			return ((int) $object->statut) === 0;
		}

		// Unknown state: refuse rather than risk writing to a validated document.
		return false;
	}

	/**
	 * Append picked items to a document.
	 *
	 * @param  string                                          $type  Document type key
	 * @param  CommonObject                                    $object Fetched document
	 * @param  array<int,array{id:int,qty:float}>              $items  Picked items
	 * @param  User                                            $user   Acting user
	 * @return array{added:int,failed:int,messages:string[],productids:int[]} Outcome
	 */
	public function addLines($type, $object, $items, $user)
	{
		$def = self::getTypeDef($type);
		$result = array('added' => 0, 'failed' => 0, 'messages' => array(), 'productids' => array());

		if (empty($def)) {
			$result['messages'][] = 'UnsupportedDocumentType';
			return $result;
		}

		$this->db->begin();
		$hadError = false;

		foreach ($items as $item) {
			$productId = (int) $item['id'];
			$qty = (float) $item['qty'];

			if ($productId <= 0) {
				continue;
			}
			if ($qty == 0) {
				$qty = 1;
			}

			$product = new Product($this->db);
			if ($product->fetch($productId) <= 0) {
				$result['failed']++;
				$result['messages'][] = 'ProductNotFound:'.$productId;
				continue;
			}

			// Refuse to sell what is not for sale, or buy what is not for purchase.
			// A BOM component needs neither flag: sub-assemblies often have both off.
			if ($def['mode'] === 'sell' && empty($product->status)) {
				$result['failed']++;
				$result['messages'][] = 'ProductNotForSale:'.$product->ref;
				continue;
			}
			if ($def['mode'] === 'buy' && empty($product->status_buy)) {
				$result['failed']++;
				$result['messages'][] = 'ProductNotForPurchase:'.$product->ref;
				continue;
			}

			if ($def['mode'] === 'bom') {
				$priced = $this->basePricing($product); // unused by addLine(), kept for a uniform call
			} elseif ($def['mode'] === 'buy') {
				$priced = $this->resolveSupplierPrice($object, $product, $qty);
			} else {
				$priced = $this->resolveCustomerPrice($object, $product, $qty);
			}

			$ret = $this->callAddline($type, $object, $product, $priced, $qty);

			if ($ret > 0) {
				$result['added']++;
				$result['productids'][] = $productId;
			} else {
				$hadError = true;
				$result['failed']++;
				$msg = !empty($object->error) ? $object->error : 'AddLineFailed';
				$result['messages'][] = $product->ref.': '.$msg;
			}
		}

		if ($hadError && $result['added'] === 0) {
			$this->db->rollback();
			return $result;
		}

		$this->db->commit();

		return $result;
	}

	/**
	 * Dispatch to the right addline() signature for this document type.
	 *
	 * @param  string        $type    Document type key
	 * @param  CommonObject  $object  Document
	 * @param  Product       $product Product being added
	 * @param  array<string,mixed> $p Resolved pricing from resolve*Price()
	 * @param  float         $qty     Quantity
	 * @return int                    >0 on success
	 */
	private function callAddline($type, $object, $product, $p, $qty)
	{
		$desc = $p['desc'];
		$label = '';
		$linetype = (int) $product->type;
		$fk_unit = !empty($product->fk_unit) ? (int) $product->fk_unit : null;

		switch ($type) {
			case 'propal':
				// addline($desc, $pu_ht, $qty, $txtva, $txlocaltax1, $txlocaltax2, $fk_product,
				//         $remise_percent, $price_base_type, $pu_ttc, $info_bits, $type, $rang,
				//         $special_code, $fk_parent_line, $fk_fournprice, $pa_ht, $label,
				//         $date_start, $date_end, $array_options, $fk_unit, $origin,
				//         $origin_id, $pu_ht_devise)
				return $object->addline(
					$desc,
					$p['pu_ht'],
					$qty,
					$p['tva_tx'],
					$p['localtax1_tx'],
					$p['localtax2_tx'],
					$product->id,
					$p['remise_percent'],
					$p['price_base_type'],
					$p['pu_ttc'],
					$p['info_bits'],
					$linetype,
					-1,
					0,
					0,
					0,
					0,
					$label,
					'',
					'',
					array(),
					$fk_unit,
					'',
					0,
					$p['pu_ht_devise']
				);

			case 'commande':
				// addline($desc, $pu_ht, $qty, $txtva, $txlocaltax1, $txlocaltax2, $fk_product,
				//         $remise_percent, $info_bits, $fk_remise_except, $price_base_type, $pu_ttc,
				//         $date_start, $date_end, $type, $rang, $special_code, $fk_parent_line,
				//         $fk_fournprice, $pa_ht, $label, $array_options, $fk_unit,
				//         $origin, $origin_id, $pu_ht_devise)
				return $object->addline(
					$desc,
					$p['pu_ht'],
					$qty,
					$p['tva_tx'],
					$p['localtax1_tx'],
					$p['localtax2_tx'],
					$product->id,
					$p['remise_percent'],
					$p['info_bits'],
					0,
					$p['price_base_type'],
					$p['pu_ttc'],
					'',
					'',
					$linetype,
					-1,
					0,
					0,
					null,
					0,
					$label,
					array(),
					$fk_unit,
					'',
					0,
					$p['pu_ht_devise']
				);

			case 'facture':
				// addline($desc, $pu_ht, $qty, $txtva, $txlocaltax1, $txlocaltax2, $fk_product,
				//         $remise_percent, $date_start, $date_end, $fk_code_ventilation, $info_bits,
				//         $fk_remise_except, $price_base_type, $pu_ttc, $type, $rang, $special_code,
				//         $origin, $origin_id, $fk_parent_line, $fk_fournprice, $pa_ht, $label,
				//         $array_options, $situation_percent, $fk_prev_id, $fk_unit,
				//         $pu_ht_devise)
				return $object->addline(
					$desc,
					$p['pu_ht'],
					$qty,
					$p['tva_tx'],
					$p['localtax1_tx'],
					$p['localtax2_tx'],
					$product->id,
					$p['remise_percent'],
					'',
					'',
					0,
					$p['info_bits'],
					0,
					$p['price_base_type'],
					$p['pu_ttc'],
					$linetype,
					-1,
					0,
					'',
					0,
					0,
					null,
					0,
					$label,
					array(),
					100,
					0,
					$fk_unit,
					$p['pu_ht_devise']
				);

			case 'supplier_proposal':
				// addline($desc, $pu_ht, $qty, $txtva, $txlocaltax1, $txlocaltax2, $fk_product,
				//         $remise_percent, $price_base_type, $pu_ttc, $info_bits, $type, $rang,
				//         $special_code, $fk_parent_line, $fk_fournprice, $pa_ht, $label,
				//         $array_options, $ref_supplier, $fk_unit)
				return $object->addline(
					$desc,
					$p['pu_ht'],
					$qty,
					$p['tva_tx'],
					$p['localtax1_tx'],
					$p['localtax2_tx'],
					$product->id,
					$p['remise_percent'],
					$p['price_base_type'],
					$p['pu_ttc'],
					$p['info_bits'],
					$linetype,
					-1,
					0,
					0,
					(int) $p['fk_fournprice'],
					0,
					$label,
					array(),
					$p['ref_supplier'],
					$fk_unit
				);

			case 'order_supplier':
				// addline($desc, $pu_ht, $qty, $txtva, $txlocaltax1, $txlocaltax2, $fk_product,
				//         $fk_prod_fourn_price, $ref_supplier, $remise_percent, $price_base_type,
				//         $pu_ttc, $type, $info_bits, $notrigger, $date_start, $date_end,
				//         $array_options, $fk_unit)
				return $object->addline(
					$desc,
					$p['pu_ht'],
					$qty,
					$p['tva_tx'],
					$p['localtax1_tx'],
					$p['localtax2_tx'],
					$product->id,
					(int) $p['fk_fournprice'],
					$p['ref_supplier'],
					$p['remise_percent'],
					$p['price_base_type'],
					$p['pu_ttc'],
					$linetype,
					$p['info_bits'],
					0,
					null,
					null,
					array(),
					$fk_unit
				);

			case 'invoice_supplier':
				// Note the different argument order for supplier invoices:
				// addline($desc, $pu, $txtva, $txlocaltax1, $txlocaltax2, $qty, $fk_product,
				//         $remise_percent, $date_start, $date_end, $fk_code_ventilation, $info_bits,
				//         $price_base_type, $type, $rang, $notrigger, $array_options, $fk_unit,
				//         $origin_id, $pu_devise, $ref_supplier)
				return $object->addline(
					$desc,
					$p['pu_ht'],
					$p['tva_tx'],
					$p['localtax1_tx'],
					$p['localtax2_tx'],
					$qty,
					$product->id,
					$p['remise_percent'],
					0,
					0,
					0,
					$p['info_bits'],
					$p['price_base_type'],
					$linetype,
					-1,
					0,
					array(),
					$fk_unit,
					0,
					0,
					$p['ref_supplier']
				);

			case 'bom':
				// BOM::addLine($fk_product, $qty, $qty_frozen, $disable_stock_change,
				//              $efficiency, $position, $fk_bom_child, $import_key, $fk_unit)
				// No price arguments at all: a BOM line is a component and a quantity.
				return $object->addLine(
					$product->id,
					$qty,
					0,
					0,
					1.0,
					-1,
					null,
					null,
					(int) $fk_unit
				);
		}

		$this->error = 'UnsupportedDocumentType';

		return -1;
	}

	/**
	 * A fixed foreign-currency price for this product, if one is configured.
	 *
	 * The Fixed Price module pins a selling price per currency, overriding the
	 * exchange-rate conversion. It applies that by injecting into $_POST from a
	 * doActions hook on the proposal, order and invoice card pages - a path this
	 * picker never takes, because it calls addline() from its own endpoint rather
	 * than going through a card page. Without this lookup its prices would simply
	 * not apply to anything added from the catalogue.
	 *
	 * Reads its table directly and only when the module is enabled, so nothing
	 * here depends on it being installed.
	 *
	 * @param  CommonObject $object  Document being added to
	 * @param  Product      $product Product being added
	 * @return float                 Fixed price in the document currency, or 0
	 */
	private function fixedCurrencyPrice($object, $product)
	{
		global $conf;

		if (!isModEnabled('fixedprice')) {
			return 0;
		}
		if (empty($object->multicurrency_code) || $object->multicurrency_code === $conf->currency) {
			return 0;
		}

		$sql = "SELECT fixed_price_ht FROM ".MAIN_DB_PREFIX."product_fixed_price";
		$sql .= " WHERE fk_product = ".((int) $product->id);
		$sql .= " AND multicurrency_code = '".$this->db->escape($object->multicurrency_code)."'";
		$sql .= " AND enabled = 1";
		$sql .= " AND entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) === 0) {
			return 0;
		}
		$o = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return (float) $o->fixed_price_ht;
	}

	/**
	 * Baseline pricing structure.
	 *
	 * @param  Product $product Product
	 * @return array<string,mixed> Defaults
	 */
	private function basePricing($product)
	{
		return array(
			'desc' => (string) $product->description,
			'pu_ht' => (float) $product->price,
			'pu_ttc' => (float) $product->price_ttc,
			'price_base_type' => !empty($product->price_base_type) ? $product->price_base_type : 'HT',
			'tva_tx' => (float) $product->tva_tx,
			'localtax1_tx' => 0,
			'localtax2_tx' => 0,
			'remise_percent' => 0,
			'info_bits' => 0,
			'fk_fournprice' => 0,
			'ref_supplier' => '',
			// Foreign-currency unit price. 0 means "derive it from the document
			// rate", which is what core does; a non-zero value pins it.
			'pu_ht_devise' => 0,
		);
	}

	/**
	 * Resolve the selling price of a product for the document's customer.
	 *
	 * Follows the same precedence the native card pages use: per-customer price,
	 * then price level (multiprice), then price-by-quantity, then the base price.
	 *
	 * @param  CommonObject $object  Document
	 * @param  Product      $product Product
	 * @param  float        $qty     Quantity, used for price-by-quantity tiers
	 * @return array<string,mixed>   Resolved pricing
	 */
	private function resolveCustomerPrice($object, $product, $qty)
	{
		global $mysoc;

		$p = $this->basePricing($product);
		$thirdparty = $object->thirdparty;

		$tva_npr = 0;
		$pricebycustomerexist = false;

		// Per-customer price table.
		if (getDolGlobalString('PRODUIT_CUSTOMER_PRICES_AND_MULTIPRICES') || getDolGlobalString('PRODUIT_CUSTOMER_PRICES')) {
			require_once DOL_DOCUMENT_ROOT.'/product/class/productcustomerprice.class.php';

			$prodcustprice = new ProductCustomerPrice($this->db);
			$filter = array('t.fk_product' => (string) $product->id, 't.fk_soc' => (string) $thirdparty->id);

			if ($prodcustprice->fetchAll('', '', 0, 0, $filter) >= 0 && count($prodcustprice->lines) > 0) {
				$date_now = (int) floor(dol_now() / 86400) * 86400;
				foreach ($prodcustprice->lines as $custprice_line) {
					if ($custprice_line->date_begin <= $date_now && (empty($custprice_line->date_end) || $date_now <= $custprice_line->date_end)) {
						$pricebycustomerexist = true;
						$p['pu_ht'] = $custprice_line->price;
						$p['pu_ttc'] = $custprice_line->price_ttc;
						$p['price_base_type'] = $custprice_line->price_base_type;
						$p['tva_tx'] = $custprice_line->tva_tx;
						if ($custprice_line->default_vat_code && !preg_match('/\(.*\)/', (string) $p['tva_tx'])) {
							$p['tva_tx'] .= ' ('.$custprice_line->default_vat_code.')';
						}
						$tva_npr = $custprice_line->recuperableonly;
						break;
					}
				}
			}
		}

		// Price level (multiprice) — applies when no customer-specific price won.
		$useLevel = getDolGlobalString('PRODUIT_MULTIPRICES')
			|| (getDolGlobalString('PRODUIT_CUSTOMER_PRICES_AND_MULTIPRICES') && !$pricebycustomerexist);

		if ($useLevel && !empty($thirdparty->price_level)) {
			$level = (int) $thirdparty->price_level;
			if (isset($product->multiprices[$level])) {
				$p['pu_ht'] = $product->multiprices[$level];
				$p['pu_ttc'] = isset($product->multiprices_ttc[$level]) ? $product->multiprices_ttc[$level] : $p['pu_ttc'];
				$p['price_base_type'] = isset($product->multiprices_base_type[$level]) ? $product->multiprices_base_type[$level] : $p['price_base_type'];

				if (getDolGlobalString('PRODUIT_MULTIPRICES_USE_VAT_PER_LEVEL')) {
					if (isset($product->multiprices_tva_tx[$level])) {
						$p['tva_tx'] = $product->multiprices_tva_tx[$level];
					}
					if (isset($product->multiprices_recuperableonly[$level])) {
						$tva_npr = $product->multiprices_recuperableonly[$level];
					}
				}
			}
		}

		// Price by quantity — pick the highest tier the quantity qualifies for.
		if (getDolGlobalString('PRODUIT_CUSTOMER_PRICES_BY_QTY') && !empty($product->prices_by_qty[0]) && !empty($product->prices_by_qty_list[0])) {
			$bestQty = -1;
			foreach ($product->prices_by_qty_list[0] as $tier) {
				if ($qty >= $tier['quantity'] && $tier['quantity'] > $bestQty) {
					$bestQty = $tier['quantity'];
					$p['pu_ht'] = $tier['unitprice'];
					$p['remise_percent'] = $tier['remise_percent'];
					$p['price_base_type'] = 'HT';
				}
			}
		}

		// VAT falls back to the seller/buyer country rules when not pinned above.
		if (is_object($mysoc) && is_object($thirdparty)) {
			$default_tva = get_default_tva($mysoc, $thirdparty, $product->id);
			if ($default_tva !== '' && $default_tva !== null && !$pricebycustomerexist) {
				$p['tva_tx'] = $default_tva;
			}
			$default_npr = get_default_npr($mysoc, $thirdparty, $product->id);
			if ($default_npr !== '' && $default_npr !== null && !$pricebycustomerexist) {
				$tva_npr = $default_npr;
			}

			$p['localtax1_tx'] = get_localtax($p['tva_tx'], 1, $thirdparty, $mysoc, $tva_npr);
			$p['localtax2_tx'] = get_localtax($p['tva_tx'], 2, $thirdparty, $mysoc, $tva_npr);
		}

		if (empty($p['tva_tx'])) {
			$tva_npr = 0;
		}
		$p['info_bits'] = $tva_npr ? 1 : 0;

		// A pinned currency price overrides the converted one, matching what the
		// Fixed Price module does on the card pages.
		$p['pu_ht_devise'] = $this->fixedCurrencyPrice($object, $product);

		$p['desc'] = $this->buildDescription($product);

		return $p;
	}

	/**
	 * Resolve the purchase price of a product for the document's supplier.
	 *
	 * @param  CommonObject $object  Document
	 * @param  Product      $product Product
	 * @param  float        $qty     Quantity
	 * @return array<string,mixed>   Resolved pricing
	 */
	private function resolveSupplierPrice($object, $product, $qty)
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';

		$p = $this->basePricing($product);
		$thirdparty = $object->thirdparty;
		$socid = is_object($thirdparty) ? (int) $thirdparty->id : 0;

		// A purchase line has no customer price; start from cost, not sale price.
		$p['pu_ht'] = (float) $product->cost_price;
		$p['pu_ttc'] = 0;
		$p['price_base_type'] = 'HT';

		$prodfourn = new ProductFournisseur($this->db);
		$found = $prodfourn->find_min_price_product_fournisseur($product->id, $qty, $socid);

		if ($found > 0 && !empty($prodfourn->product_fourn_price_id)) {
			$p['pu_ht'] = (float) $prodfourn->fourn_unitprice;
			$p['remise_percent'] = (float) $prodfourn->fourn_remise_percent;
			$p['fk_fournprice'] = (int) $prodfourn->product_fourn_price_id;
			$p['ref_supplier'] = (string) $prodfourn->ref_supplier;
			if ($prodfourn->fourn_tva_tx !== '' && $prodfourn->fourn_tva_tx !== null) {
				$p['tva_tx'] = $prodfourn->fourn_tva_tx;
			}
		}

		// Purchase VAT follows the buyer/seller pair, reversed relative to a sale.
		if (is_object($mysoc) && is_object($thirdparty)) {
			$default_tva = get_default_tva($thirdparty, $mysoc, $product->id, (int) $p['fk_fournprice']);
			if ($default_tva !== '' && $default_tva !== null) {
				$p['tva_tx'] = $default_tva;
			}
			$tva_npr = get_default_npr($thirdparty, $mysoc, $product->id, (int) $p['fk_fournprice']);

			$p['localtax1_tx'] = get_localtax($p['tva_tx'], 1, $mysoc, $thirdparty, $tva_npr);
			$p['localtax2_tx'] = get_localtax($p['tva_tx'], 2, $mysoc, $thirdparty, $tva_npr);
			$p['info_bits'] = $tva_npr ? 1 : 0;
		}

		$p['desc'] = $this->buildDescription($product);

		return $p;
	}

	/**
	 * Line description for a product, honouring the "hide description" setting.
	 *
	 * @param  Product $product Product
	 * @return string           Description text
	 */
	private function buildDescription($product)
	{
		if (getDolGlobalString('PRODUIT_DESC_IN_FORM_ACCORDING_TO_DEVICE') === '0') {
			return '';
		}

		return (string) $product->description;
	}
}
