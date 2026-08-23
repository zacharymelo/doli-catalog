<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/dolicatalogbrowser.class.php
 * \ingroup dolicatalog
 * \brief   Read-only catalog access for the Doli Catalog browser.
 *
 * Every query here reads native Dolibarr tables only (llx_categorie,
 * llx_categorie_product, llx_product, llx_product_stock). Nothing in this
 * class writes to a core table.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

/**
 * Catalog browsing and search for the Doli Catalog modal.
 */
class DoliCatalogBrowser
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Last error message */
	public $error = '';

	/** @var string[] Error stack */
	public $errors = array();

	/** Product-type category discriminator in llx_categorie.type */
	const CATEGORY_TYPE_PRODUCT = 0;

	/** Hard ceiling on rows returned regardless of configuration */
	const MAX_ROWS_HARD_LIMIT = 500;

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
	 * Normalise a browsing mode.
	 *
	 * 'sell' restricts to items flagged for sale, 'buy' to items flagged for
	 * purchase, and 'bom' applies neither: a manufactured sub-assembly is often
	 * neither sold nor purchased, so filtering it out would hide real components.
	 *
	 * @param  string $mode Raw mode
	 * @return string       One of 'sell', 'buy', 'bom'
	 */
	public static function normaliseMode($mode)
	{
		return in_array($mode, array('buy', 'bom'), true) ? $mode : 'sell';
	}

	/**
	 * Configured page size, clamped to something sane.
	 *
	 * @return int Number of rows to return per query
	 */
	public function getRowLimit()
	{
		$limit = getDolGlobalInt('DOLICATALOG_MAX_RESULTS', 50);
		if ($limit < 1) {
			$limit = 50;
		}
		return min($limit, self::MAX_ROWS_HARD_LIMIT);
	}

	/**
	 * Root categories to show at the top level of the browser.
	 *
	 * Honours DOLICATALOG_ROOT_CATEGORIES when set; otherwise every category
	 * with no parent is treated as a root.
	 *
	 * @return array<int,array{id:int,label:string,color:string,description:string,position:int}> Root categories
	 */
	public function getRootCategories()
	{
		$configured = trim(getDolGlobalString('DOLICATALOG_ROOT_CATEGORIES', ''));

		$sql = "SELECT c.rowid, c.label, c.color, c.description, c.position";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
		$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";

		if ($configured !== '') {
			$ids = array();
			foreach (explode(',', $configured) as $chunk) {
				$chunk = (int) trim($chunk);
				if ($chunk > 0) {
					$ids[] = $chunk;
				}
			}
			if (empty($ids)) {
				return array();
			}
			$sql .= " AND c.rowid IN (".$this->db->sanitize(implode(',', $ids)).")";
		} else {
			$sql .= " AND (c.fk_parent = 0 OR c.fk_parent IS NULL)";
		}

		$sql .= " ORDER BY c.position ASC, c.label ASC";

		return $this->fetchCategoryRows($sql);
	}

	/**
	 * Direct children of a category.
	 *
	 * @param  int $parentId Parent category id
	 * @return array<int,array{id:int,label:string,color:string,description:string,position:int}> Child categories
	 */
	public function getChildCategories($parentId)
	{
		$parentId = (int) $parentId;
		if ($parentId <= 0) {
			return array();
		}

		$sql = "SELECT c.rowid, c.label, c.color, c.description, c.position";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
		$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";
		$sql .= " AND c.fk_parent = ".$parentId;
		$sql .= " ORDER BY c.position ASC, c.label ASC";

		return $this->fetchCategoryRows($sql);
	}

	/**
	 * Run a category SELECT and normalise the rows.
	 *
	 * @param  string $sql Query returning rowid, label, color, description, position
	 * @return array<int,array{id:int,label:string,color:string,description:string,position:int}> Normalised rows
	 */
	private function fetchCategoryRows($sql)
	{
		$out = array();

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return $out;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$out[] = array(
				'id' => (int) $obj->rowid,
				'label' => (string) $obj->label,
				'color' => (string) $obj->color,
				'description' => (string) $obj->description,
				'position' => (int) $obj->position,
			);
		}
		$this->db->free($resql);

		return $out;
	}

	/**
	 * Breadcrumb trail from the root down to the given category.
	 *
	 * Walks fk_parent upwards with a depth guard so a corrupted cycle in
	 * llx_categorie cannot spin forever.
	 *
	 * @param  int $catId Category id
	 * @return array<int,array{id:int,label:string}> Trail ordered root first
	 */
	public function getBreadcrumb($catId)
	{
		$catId = (int) $catId;
		$trail = array();
		$seen = array();
		$guard = 0;

		while ($catId > 0 && $guard < 64) {
			$guard++;
			if (isset($seen[$catId])) {
				break; // cycle in the category tree
			}
			$seen[$catId] = true;

			$sql = "SELECT c.rowid, c.label, c.fk_parent";
			$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
			$sql .= " WHERE c.rowid = ".$catId;
			$sql .= " AND c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
			$sql .= " AND c.entity IN (".getEntity('category').")";

			$resql = $this->db->query($sql);
			if (!$resql) {
				break;
			}
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if (!$obj) {
				break;
			}

			array_unshift($trail, array('id' => (int) $obj->rowid, 'label' => (string) $obj->label));
			$catId = (int) $obj->fk_parent;
		}

		return $trail;
	}

	/**
	 * All descendant category ids of a category, including the category itself.
	 *
	 * Iterative breadth-first descent rather than a recursive CTE, so the
	 * module stays portable across MySQL, MariaDB and PostgreSQL.
	 *
	 * @param  int $catId Category id
	 * @return int[]      Category ids
	 */
	public function getDescendantIds($catId)
	{
		$catId = (int) $catId;
		if ($catId <= 0) {
			return array();
		}

		$all = array($catId => $catId);
		$frontier = array($catId);
		$guard = 0;

		while (!empty($frontier) && $guard < 64) {
			$guard++;

			$sql = "SELECT c.rowid FROM ".MAIN_DB_PREFIX."categorie as c";
			$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
			$sql .= " AND c.entity IN (".getEntity('category').")";
			$sql .= " AND c.fk_parent IN (".$this->db->sanitize(implode(',', $frontier)).")";

			$resql = $this->db->query($sql);
			if (!$resql) {
				break;
			}

			$next = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$id = (int) $obj->rowid;
				if (!isset($all[$id])) {
					$all[$id] = $id;
					$next[] = $id;
				}
			}
			$this->db->free($resql);

			$frontier = $next;
		}

		return array_values($all);
	}

	/**
	 * Number of distinct products reachable under a category, subcategories included.
	 *
	 * @param  int                                        $catId   Category id
	 * @param  array{mode?:string,type?:int}              $filters Same filter shape as listProducts()
	 * @return int                                                 Product count
	 */
	public function countProductsInCategory($catId, $filters = array())
	{
		$ids = $this->getDescendantIds($catId);
		if (empty($ids)) {
			return 0;
		}

		$mode = self::normaliseMode(isset($filters['mode']) ? $filters['mode'] : '');
		$supplier = isset($filters['supplier']) ? (int) $filters['supplier'] : 0;

		$sql = "SELECT COUNT(DISTINCT p.rowid) as cnt";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie_product as cp";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = cp.fk_product";
		// The supplier filter must narrow the count too, otherwise a folder
		// advertises more items than opening it actually shows.
		if ($supplier > 0) {
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_fournisseur_price as pfp";
			$sql .= " ON pfp.fk_product = p.rowid AND pfp.fk_soc = ".$supplier;
		}
		$sql .= " WHERE cp.fk_categorie IN (".$this->db->sanitize(implode(',', $ids)).")";
		$sql .= " AND p.entity IN (".getEntity('product').")";
		$sql .= $this->saleStatusClause($mode);

		if (isset($filters['type']) && $filters['type'] >= 0) {
			$sql .= " AND p.fk_product_type = ".((int) $filters['type']);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ? (int) $obj->cnt : 0;
	}

	/**
	 * List products for the browser.
	 *
	 * @param array{
	 *   category?:int,
	 *   search?:string,
	 *   mode?:string,
	 *   type?:int,
	 *   warehouse?:int,
	 *   supplier?:int,
	 *   deep?:int,
	 *   ids?:int[],
	 *   userid?:int,
	 *   limit?:int
	 * } $filters Query filters
	 *
	 * @return array{rows:array<int,array<string,mixed>>,truncated:bool,total:int} Result set
	 */
	public function listProducts($filters = array())
	{
		global $user;

		$limit = isset($filters['limit']) ? (int) $filters['limit'] : $this->getRowLimit();
		$limit = max(1, min($limit, self::MAX_ROWS_HARD_LIMIT));

		$mode = self::normaliseMode(isset($filters['mode']) ? $filters['mode'] : '');
		$warehouse = isset($filters['warehouse']) ? (int) $filters['warehouse'] : 0;
		$supplier = isset($filters['supplier']) ? (int) $filters['supplier'] : 0;
		$userid = isset($filters['userid']) ? (int) $filters['userid'] : (is_object($user) ? (int) $user->id : 0);

		$sql = "SELECT DISTINCT p.rowid, p.ref, p.label, p.description, p.barcode,";
		$sql .= " p.price, p.price_ttc, p.price_base_type, p.tva_tx, p.fk_product_type,";
		$sql .= " p.duration, p.fk_unit, p.stock, p.tosell, p.tobuy, p.entity, p.cost_price";
		if ($warehouse > 0) {
			$sql .= ", ps.reel as stock_warehouse";
		}
		// On a purchase document the sale price is meaningless; surface the
		// best supplier unit price instead (scoped to the supplier when filtered).
		if ($mode === 'buy') {
			$sql .= ", pfpmin.buyprice as buyprice";
		}

		$sql .= " FROM ".MAIN_DB_PREFIX."product as p";

		// Category scoping.
		$catIds = array();
		if (!empty($filters['category'])) {
			$catId = (int) $filters['category'];
			$deep = !empty($filters['deep']);
			$catIds = $deep ? $this->getDescendantIds($catId) : array($catId);
		}
		if (!empty($catIds)) {
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie_product as cp ON cp.fk_product = p.rowid";
		}

		if ($warehouse > 0) {
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_product = p.rowid AND ps.fk_entrepot = ".$warehouse;
		}

		if ($mode === 'buy') {
			// One grouped subquery rather than a per-row price lookup.
			$sub = "SELECT fk_product, MIN(unitprice) as buyprice";
			$sub .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
			$sub .= " WHERE entity IN (".getEntity('product').")";
			if ($supplier > 0) {
				$sub .= " AND fk_soc = ".$supplier;
			}
			$sub .= " GROUP BY fk_product";

			// A supplier filter restricts the rows; without it the price is just decoration.
			$sql .= ($supplier > 0 ? " INNER JOIN (" : " LEFT JOIN (").$sub.") as pfpmin ON pfpmin.fk_product = p.rowid";
		} elseif ($supplier > 0) {
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_fournisseur_price as pfp ON pfp.fk_product = p.rowid AND pfp.fk_soc = ".$supplier;
		}

		$sql .= " WHERE p.entity IN (".getEntity('product').")";
		$sql .= $this->saleStatusClause($mode);

		if (!empty($catIds)) {
			$sql .= " AND cp.fk_categorie IN (".$this->db->sanitize(implode(',', $catIds)).")";
		}

		if (isset($filters['type']) && $filters['type'] >= 0) {
			$sql .= " AND p.fk_product_type = ".((int) $filters['type']);
		}

		// Explicit id list (favourites / recents rehydration).
		if (!empty($filters['ids']) && is_array($filters['ids'])) {
			$clean = array();
			foreach ($filters['ids'] as $one) {
				$one = (int) $one;
				if ($one > 0) {
					$clean[] = $one;
				}
			}
			if (empty($clean)) {
				return array('rows' => array(), 'truncated' => false, 'total' => 0);
			}
			$sql .= " AND p.rowid IN (".$this->db->sanitize(implode(',', $clean)).")";
		}

		// Free-text search across ref, label, description and barcode.
		$search = isset($filters['search']) ? trim((string) $filters['search']) : '';
		if ($search !== '') {
			$needle = $this->db->escape($this->db->escapeforlike($search));
			$sql .= " AND (p.ref LIKE '%".$needle."%'";
			$sql .= " OR p.label LIKE '%".$needle."%'";
			$sql .= " OR p.description LIKE '%".$needle."%'";
			$sql .= " OR p.barcode LIKE '%".$needle."%')";
		}

		$sql .= " ORDER BY p.ref ASC";
		// Fetch one extra row so we can tell the caller the list was cut short.
		$sql .= $this->db->plimit($limit + 1, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return array('rows' => array(), 'truncated' => false, 'total' => 0);
		}

		$rows = array();
		$count = 0;
		$truncated = false;

		while ($obj = $this->db->fetch_object($resql)) {
			$count++;
			if ($count > $limit) {
				$truncated = true;
				break;
			}

			if ($mode === 'buy') {
				// Best supplier price, else the product's own cost price.
				$unit = (isset($obj->buyprice) && $obj->buyprice !== null)
					? (float) $obj->buyprice
					: (float) $obj->cost_price;
				$unitTtc = 0.0; // no reliable tax-inclusive purchase price to show
			} elseif ($mode === 'bom') {
				// A BOM cares about what a component costs, not what it sells for.
				$unit = (float) $obj->cost_price;
				$unitTtc = 0.0;
			} else {
				$unit = (float) $obj->price;
				$unitTtc = (float) $obj->price_ttc;
			}

			$rows[] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'label' => (string) $obj->label,
				'description' => dol_trunc((string) $obj->description, 160),
				'barcode' => (string) $obj->barcode,
				'type' => (int) $obj->fk_product_type,
				'price' => $unit,
				'price_ttc' => $unitTtc,
				'price_base_type' => (string) $obj->price_base_type,
				'tva_tx' => (float) $obj->tva_tx,
				'duration' => (string) $obj->duration,
				'fk_unit' => (int) $obj->fk_unit,
				'stock' => ($warehouse > 0)
					? (float) (isset($obj->stock_warehouse) ? $obj->stock_warehouse : 0)
					: (float) $obj->stock,
			);
		}
		$this->db->free($resql);

		// Decorate with per-user flags and thumbnails only for the rows we kept.
		$this->decorateRows($rows, $userid);

		return array('rows' => $rows, 'truncated' => $truncated, 'total' => count($rows));
	}

	/**
	 * Attach favourite flags and thumbnail URLs to a result set.
	 *
	 * @param  array<int,array<string,mixed>> $rows   Rows to decorate, by reference
	 * @param  int                            $userid User id for favourite lookup
	 * @return void
	 */
	private function decorateRows(&$rows, $userid)
	{
		if (empty($rows)) {
			return;
		}

		$ids = array();
		foreach ($rows as $r) {
			$ids[] = (int) $r['id'];
		}

		$favs = $this->getFavoriteIds($userid);
		$showImages = getDolGlobalInt('DOLICATALOG_SHOW_IMAGES', 1);

		foreach ($rows as $k => $r) {
			$rows[$k]['is_favorite'] = in_array((int) $r['id'], $favs, true) ? 1 : 0;
			$rows[$k]['image'] = $showImages ? $this->getThumbnailUrl((int) $r['id'], (string) $r['ref']) : '';
		}
	}

	/**
	 * URL of the first photo of a product, or an empty string when it has none.
	 *
	 * Mirrors the two layouts Dolibarr supports for the product photo directory.
	 *
	 * @param  int    $productId Product id
	 * @param  string $ref       Product ref
	 * @return string            viewimage.php URL or ''
	 */
	public function getThumbnailUrl($productId, $ref)
	{
		global $conf;

		include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$base = $conf->product->dir_output;
		if (empty($base)) {
			return '';
		}

		if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {
			$relative = get_exdir($productId, 2, 0, 0, null, 'product').$productId.'/photos';
		} else {
			$relative = dol_sanitizeFileName($ref);
		}

		$dir = $base.'/'.$relative;
		if (!is_dir($dir)) {
			return '';
		}

		// Prefer a generated thumbnail; fall back to the original image.
		$thumbs = dol_dir_list($dir.'/thumbs', 'files', 0, '\.(jpg|jpeg|png|gif|webp)$', '', 'name', SORT_ASC, 0, 1);
		if (!empty($thumbs)) {
			$file = $relative.'/thumbs/'.$thumbs[0]['name'];
		} else {
			$originals = dol_dir_list($dir, 'files', 0, '\.(jpg|jpeg|png|gif|webp)$', '', 'name', SORT_ASC, 0, 1);
			if (empty($originals)) {
				return '';
			}
			$file = $relative.'/'.$originals[0]['name'];
		}

		return DOL_URL_ROOT.'/viewimage.php?modulepart=product&entity='.((int) $conf->entity).'&file='.urlencode($file);
	}

	/**
	 * Product ids favourited by a user.
	 *
	 * @param  int   $userid User id
	 * @return int[]         Product ids
	 */
	public function getFavoriteIds($userid)
	{
		$userid = (int) $userid;
		if ($userid <= 0 || !getDolGlobalInt('DOLICATALOG_ENABLE_FAVORITES', 1)) {
			return array();
		}

		$sql = "SELECT f.fk_product FROM ".MAIN_DB_PREFIX."dolicatalog_favorite as f";
		$sql .= " WHERE f.fk_user = ".$userid;
		$sql .= " AND f.entity IN (".getEntity('dolicatalog').")";

		$out = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = (int) $obj->fk_product;
			}
			$this->db->free($resql);
		}

		return $out;
	}

	/**
	 * Product ids most recently picked by a user, most recent first.
	 *
	 * @param  int   $userid User id
	 * @return int[]         Product ids
	 */
	public function getRecentIds($userid)
	{
		$userid = (int) $userid;
		if ($userid <= 0 || !getDolGlobalInt('DOLICATALOG_ENABLE_RECENT', 1)) {
			return array();
		}

		$limit = getDolGlobalInt('DOLICATALOG_RECENT_COUNT', 12);
		if ($limit < 1) {
			$limit = 12;
		}

		$sql = "SELECT r.fk_product FROM ".MAIN_DB_PREFIX."dolicatalog_recent as r";
		$sql .= " WHERE r.fk_user = ".$userid;
		$sql .= " AND r.entity IN (".getEntity('dolicatalog').")";
		$sql .= " ORDER BY r.date_last DESC";
		$sql .= $this->db->plimit($limit, 0);

		$out = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = (int) $obj->fk_product;
			}
			$this->db->free($resql);
		}

		return $out;
	}

	/**
	 * Toggle a product in a user's favourites.
	 *
	 * @param  int $userid    User id
	 * @param  int $productId Product id
	 * @return int            1 if now a favourite, 0 if removed, -1 on error
	 */
	public function toggleFavorite($userid, $productId)
	{
		global $conf;

		$userid = (int) $userid;
		$productId = (int) $productId;
		if ($userid <= 0 || $productId <= 0) {
			$this->error = 'BadParameters';
			return -1;
		}

		$this->db->begin();

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."dolicatalog_favorite";
		$sql .= " WHERE fk_user = ".$userid;
		$sql .= " AND fk_product = ".$productId;
		$sql .= " AND entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$existing = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if ($existing) {
			$del = "DELETE FROM ".MAIN_DB_PREFIX."dolicatalog_favorite WHERE rowid = ".((int) $existing->rowid);
			if (!$this->db->query($del)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
			$this->db->commit();
			return 0;
		}

		$ins = "INSERT INTO ".MAIN_DB_PREFIX."dolicatalog_favorite (entity, fk_user, fk_product, date_creation)";
		$ins .= " VALUES (".((int) $conf->entity).", ".$userid.", ".$productId.", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($ins)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Record that a user picked a set of products, and trim their history.
	 *
	 * @param  int   $userid     User id
	 * @param  int[] $productIds Product ids that were just added to a document
	 * @return void
	 */
	public function recordRecent($userid, $productIds)
	{
		global $conf;

		$userid = (int) $userid;
		if ($userid <= 0 || empty($productIds) || !getDolGlobalInt('DOLICATALOG_ENABLE_RECENT', 1)) {
			return;
		}

		$now = $this->db->idate(dol_now());

		foreach ($productIds as $pid) {
			$pid = (int) $pid;
			if ($pid <= 0) {
				continue;
			}

			$sql = "SELECT rowid, pick_count FROM ".MAIN_DB_PREFIX."dolicatalog_recent";
			$sql .= " WHERE fk_user = ".$userid." AND fk_product = ".$pid;
			$sql .= " AND entity = ".((int) $conf->entity);

			$resql = $this->db->query($sql);
			$row = $resql ? $this->db->fetch_object($resql) : null;
			if ($resql) {
				$this->db->free($resql);
			}

			if ($row) {
				$upd = "UPDATE ".MAIN_DB_PREFIX."dolicatalog_recent";
				$upd .= " SET pick_count = ".((int) $row->pick_count + 1).", date_last = '".$now."'";
				$upd .= " WHERE rowid = ".((int) $row->rowid);
				$this->db->query($upd);
			} else {
				$ins = "INSERT INTO ".MAIN_DB_PREFIX."dolicatalog_recent (entity, fk_user, fk_product, pick_count, date_last)";
				$ins .= " VALUES (".((int) $conf->entity).", ".$userid.", ".$pid.", 1, '".$now."')";
				$this->db->query($ins);
			}
		}

		$this->trimRecent($userid);
	}

	/**
	 * SQL fragment restricting products by their sale/purchase flags.
	 *
	 * @param  string $mode Normalised mode
	 * @return string       SQL fragment, possibly empty
	 */
	private function saleStatusClause($mode)
	{
		if ($mode === 'buy') {
			return " AND p.tobuy = 1";
		}
		if ($mode === 'bom') {
			return ""; // components need no sale or purchase flag
		}

		return " AND p.tosell = 1";
	}

	/**
	 * Drop history rows beyond the configured retention count for a user.
	 *
	 * @param  int $userid User id
	 * @return void
	 */
	private function trimRecent($userid)
	{
		global $conf;

		$keep = getDolGlobalInt('DOLICATALOG_RECENT_COUNT', 12);
		if ($keep < 1) {
			$keep = 12;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."dolicatalog_recent";
		$sql .= " WHERE fk_user = ".((int) $userid);
		$sql .= " AND entity = ".((int) $conf->entity);
		$sql .= " ORDER BY date_last DESC";
		$sql .= $this->db->plimit(1000, $keep);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return;
		}

		$stale = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$stale[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		if (!empty($stale)) {
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."dolicatalog_recent WHERE rowid IN (".$this->db->sanitize(implode(',', $stale)).")");
		}
	}
}
