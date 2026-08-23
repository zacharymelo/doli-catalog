<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/actions_dolicatalog.class.php
 * \ingroup dolicatalog
 * \brief   Hook handlers that surface the catalog picker inside document pages.
 */

dol_include_once('/dolicatalog/class/dolicataloglineadder.class.php');
dol_include_once('/dolicatalog/lib/dolicatalog.lib.php');

/**
 * Injects the Doli Catalog trigger button and modal shell into the line entry
 * form of every supported commercial document.
 */
class ActionsDoliCatalog
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Last error message */
	public $error = '';

	/** @var string[] Error stack */
	public $errors = array();

	/** @var array<string,mixed> Hook data results */
	public $results = array();

	/** @var string Hook HTML output */
	public $resprints = '';

	/** @var bool Guard so the modal shell is only emitted once per page */
	private static $rendered = false;

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
	 * Line entry form on customer-facing documents.
	 *
	 * @param  array<string,mixed> $parameters  Hook parameters
	 * @param  CommonObject        $object      Current document
	 * @param  string              $action      Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                              Always 0
	 */
	public function formCreateProductOptions($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = $this->renderPicker($object, 'sell');

		return 0;
	}

	/**
	 * Line entry form on supplier-facing documents.
	 *
	 * @param  array<string,mixed> $parameters  Hook parameters
	 * @param  CommonObject        $object      Current document
	 * @param  string              $action      Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                              Always 0
	 */
	public function formCreateProductSupplierOptions($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = $this->renderPicker($object, 'buy');

		return 0;
	}

	/**
	 * Line entry form on a bill of materials.
	 *
	 * BOM ships its own line templates, and its objectline_create template fires
	 * no hooks, so the picker attaches to bom_card.php's formAddObjectLine
	 * instead. Two constraints apply here:
	 *   1. bom_card.php never prints $hookmanager->resPrint for this hook, so the
	 *      markup has to be echoed directly rather than returned in resprints.
	 *   2. The caller runs the native add-line form only when the hook result is
	 *      empty, so this must return 0 or the standard form disappears.
	 *
	 * @param  array<string,mixed> $parameters  Hook parameters
	 * @param  CommonObject        $object      Current document
	 * @param  string              $action      Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                              Always 0, to keep the native form
	 */
	public function formAddObjectLine($parameters, &$object, &$action, $hookmanager)
	{
		if (!is_object($object) || empty($object->element) || $object->element !== 'bom') {
			return 0;
		}

		print $this->renderPicker($object, 'bom', 'tablerow');

		return 0;
	}

	/**
	 * Build the trigger button, modal shell and bootstrap payload.
	 *
	 * Returns an empty string whenever the picker should not appear: module off,
	 * user lacks rights, document type unsupported, or document not editable.
	 *
	 * @param  CommonObject $object Current document
	 * @param  string       $mode   'sell' or 'buy'
	 * @return string               HTML to print, or ''
	 */
	private function renderPicker($object, $mode, $wrap = 'inline')
	{
		global $conf, $langs, $user;

		if (!isModEnabled('dolicatalog')) {
			return '';
		}
		if (self::$rendered) {
			return '';
		}
		if (!is_object($object) || empty($object->id)) {
			return '';
		}

		$type = $object->element;
		$def = DoliCatalogLineAdder::getTypeDef($type);
		if (empty($def)) {
			return '';
		}

		// Native permissions decide: the picker never grants more than the page does.
		if (!$user->hasRight('dolicatalog', 'picker', 'use')) {
			return '';
		}
		if (!$user->hasRight('produit', 'lire') && !$user->hasRight('service', 'lire')) {
			return '';
		}
		if (!DoliCatalogLineAdder::userCanCreate($type, $user)) {
			return '';
		}

		self::$rendered = true;

		$langs->loadLangs(array('dolicatalog@dolicatalog', 'products', 'stocks'));

		$socid = 0;
		if ($mode === 'bom') {
			$socid = 0; // a BOM has no thirdparty to price against
		} elseif (!empty($object->socid)) {
			$socid = (int) $object->socid;
		} elseif (!empty($object->thirdparty->id)) {
			$socid = (int) $object->thirdparty->id;
		}

		$config = array(
			'docType' => $type,
			'docId' => (int) $object->id,
			'mode' => $mode,
			'socid' => $socid,
			'token' => newToken(),
			'urlCatalog' => dol_buildpath('/dolicatalog/ajax/catalog.php', 1),
			'urlAddLines' => dol_buildpath('/dolicatalog/ajax/addlines.php', 1),
			'urlFavorite' => dol_buildpath('/dolicatalog/ajax/favorite.php', 1),
			// Product card, opened in a new tab from the picker.
			'urlProduct' => dol_buildpath('/product/card.php', 1),
			'showStock' => getDolGlobalInt('DOLICATALOG_SHOW_STOCK', 1),
			'showTtc' => in_array($mode, array('buy', 'bom'), true) ? 0 : getDolGlobalInt('DOLICATALOG_SHOW_TTC', 0),
			'showImages' => getDolGlobalInt('DOLICATALOG_SHOW_IMAGES', 1),
			'showDuration' => getDolGlobalInt('DOLICATALOG_SHOW_DURATION', 1),
			'enableFavorites' => getDolGlobalInt('DOLICATALOG_ENABLE_FAVORITES', 1),
			'enableRecent' => getDolGlobalInt('DOLICATALOG_ENABLE_RECENT', 1),
			'hideEmpty' => getDolGlobalInt('DOLICATALOG_HIDE_EMPTY_CATEGORIES', 0),
			'defaultQty' => max(1, getDolGlobalInt('DOLICATALOG_DEFAULT_QTY', 1)),
			'currency' => $conf->currency,
			'labels' => $this->buildLabels($langs),
		);

		$icon = getDolGlobalString('DOLICATALOG_BUTTON_ICON', 'fa-th-large');
		$icon = preg_replace('/[^a-z0-9\- ]/i', '', $icon);

		$out = "\n<!-- BEGIN dolicatalog trigger -->\n";
		// Inside a lines table the markup must be a real row, not loose HTML.
		if ($wrap === 'tablerow') {
			$out .= '<tr class="dolicatalog-triggerrow"><td colspan="12">';
		}
		$out .= '<span class="dolicatalog-trigger-wrap">';
		$tooltipKey = 'DoliCatalogBrowseCatalogTooltip';
		if ($mode === 'buy') {
			$tooltipKey = 'DoliCatalogBrowseCatalogTooltipBuy';
		} elseif ($mode === 'bom') {
			$tooltipKey = 'DoliCatalogBrowseCatalogTooltipBom';
		}
		$out .= '<button type="button" id="dolicatalog-open" class="button dolicatalog-trigger" title="'.dol_escape_htmltag($langs->trans($tooltipKey)).'">';
		$out .= '<span class="fa '.dol_escape_htmltag($icon).' paddingright"></span>';
		$out .= dol_escape_htmltag($langs->trans('DoliCatalogBrowseCatalog'));
		$out .= '</button>';
		$out .= '</span>';

		// Modal shell — contents are built client side.
		$out .= '<div id="dolicatalog-overlay" class="dolicatalog-overlay" hidden>';
		$out .= '<div class="dolicatalog-modal" role="dialog" aria-modal="true" aria-labelledby="dolicatalog-title">';
		$out .= '<div class="dolicatalog-header">';
		$out .= '<h2 id="dolicatalog-title">'.dol_escape_htmltag($langs->trans('DoliCatalogTitle')).'</h2>';
		$out .= '<button type="button" class="dolicatalog-close" id="dolicatalog-close" aria-label="'.dol_escape_htmltag($langs->trans('Close')).'">&times;</button>';
		$out .= '</div>';
		$out .= '<div class="dolicatalog-toolbar">';
		$out .= '<input type="search" id="dolicatalog-search" class="dolicatalog-search" placeholder="'.dol_escape_htmltag($langs->trans('DoliCatalogSearchPlaceholder')).'" title="'.dol_escape_htmltag($langs->trans('DoliCatalogSearchTooltip')).'" autocomplete="off">';
		$out .= '<select id="dolicatalog-type" class="dolicatalog-filter" title="'.dol_escape_htmltag($langs->trans('DoliCatalogTypeFilterTooltip')).'">';
		$out .= '<option value="-1">'.dol_escape_htmltag($langs->trans('DoliCatalogAllTypes')).'</option>';
		$out .= '<option value="0">'.dol_escape_htmltag($langs->trans('Products')).'</option>';
		$out .= '<option value="1">'.dol_escape_htmltag($langs->trans('Services')).'</option>';
		$out .= '</select>';
		$out .= $this->renderWarehouseFilter($langs);
		if ($mode === 'buy' && $socid > 0) {
			$out .= '<label class="dolicatalog-supplier-only" title="'.dol_escape_htmltag($langs->trans('DoliCatalogThisSupplierOnlyTooltip')).'">';
			$out .= '<input type="checkbox" id="dolicatalog-supplier-only" checked> ';
			$out .= dol_escape_htmltag($langs->trans('DoliCatalogThisSupplierOnly'));
			$out .= '</label>';
		}
		$out .= '</div>';
		$out .= '<div class="dolicatalog-breadcrumb" id="dolicatalog-breadcrumb"></div>';
		$out .= '<div class="dolicatalog-body"><div class="dolicatalog-results" id="dolicatalog-results"></div></div>';
		$out .= '<div class="dolicatalog-footer">';
		$out .= '<div class="dolicatalog-selection" id="dolicatalog-selection"></div>';
		$out .= '<div class="dolicatalog-actions">';
		$out .= '<button type="button" class="button button-cancel" id="dolicatalog-cancel" title="'.dol_escape_htmltag($langs->trans('DoliCatalogCancelTooltip')).'">'.dol_escape_htmltag($langs->trans('Cancel')).'</button>';
		$out .= '<button type="button" class="button" id="dolicatalog-add" disabled title="'.dol_escape_htmltag($langs->trans('DoliCatalogAddSelectedTooltip')).'">'.dol_escape_htmltag($langs->trans('DoliCatalogAddSelected')).'</button>';
		$out .= '</div>';
		$out .= '</div>';
		$out .= '</div>';
		$out .= '</div>';

		$out .= '<script type="application/json" id="dolicatalog-config">'.json_encode($config).'</script>';
		$out .= '<script src="'.dol_buildpath('/dolicatalog/js/dolicatalog.js', 1).'?v='.urlencode(dolicatalogAssetVersion('/dolicatalog/js/dolicatalog.js')).'"></script>';
		if ($wrap === 'tablerow') {
			$out .= '</td></tr>';
		}
		$out .= "\n<!-- END dolicatalog trigger -->\n";

		return $out;
	}

	/**
	 * Warehouse filter, shown only when the stock module is active.
	 *
	 * @param  Translate $langs Translation handler
	 * @return string           HTML select, or ''
	 */
	private function renderWarehouseFilter($langs)
	{
		if (!isModEnabled('stock') || !getDolGlobalInt('DOLICATALOG_SHOW_STOCK', 1)) {
			return '';
		}

		require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';

		$formproduct = new FormProduct($this->db);
		$html = '<span class="dolicatalog-warehouse" title="'.dol_escape_htmltag($langs->trans('DoliCatalogWarehouseTooltip')).'">';
		// forcecombo = 1 renders a plain <select> instead of a select2 widget.
		// select2 appends its dropdown panel to <body>, which puts it underneath
		// this modal's overlay; a native select is drawn by the browser and always
		// paints on top, so the picker cannot trap it behind the dialog.
		$html .= $formproduct->selectWarehouses(
			-1,
			'dolicatalog_warehouse',
			'',
			1,
			0,
			0,
			$langs->trans('DoliCatalogAllWarehouses'),
			0,
			1
		);
		$html .= '</span>';

		return $html;
	}

	/**
	 * Strings the client-side code needs.
	 *
	 * @param  Translate $langs Translation handler
	 * @return array<string,string> Label map
	 */
	private function buildLabels($langs)
	{
		$keys = array(
			'Ref', 'Label', 'PriceUHT', 'PriceUTTC', 'CostPrice', 'Stock', 'Qty', 'Duration', 'Close', 'Cancel',
			'DoliCatalogTitle', 'DoliCatalogLoading', 'DoliCatalogNoResults', 'DoliCatalogRoot',
			'DoliCatalogFavorites', 'DoliCatalogRecent', 'DoliCatalogAddSelected', 'DoliCatalogSelected',
			'DoliCatalogTruncated', 'DoliCatalogCategories', 'DoliCatalogItems', 'DoliCatalogError',
			'DoliCatalogAddFavorite', 'DoliCatalogRemoveFavorite', 'DoliCatalogAdded',
			'DoliCatalogNothingSelected', 'DoliCatalogSearchAll', 'DoliCatalogClearSelection',
			'DoliCatalogEmptyCategory', 'DoliCatalogAdding', 'DoliCatalogMatchingCategories',
			'DoliCatalogOpenProduct', 'DoliCatalogThisSupplierOnly',
			'DoliCatalogOpenCategory', 'DoliCatalogSelectItem', 'DoliCatalogQtyTooltip',
			'DoliCatalogStockWarehouse', 'DoliCatalogStockAll', 'DoliCatalogServiceNoStock',
			'DoliCatalogClearSelectionTooltip', 'DoliCatalogRemoveItem',
			'DoliCatalogFavoritesTooltip', 'DoliCatalogRecentTooltip', 'BarCode',
		);

		$out = array();
		foreach ($keys as $k) {
			$out[$k] = $langs->transnoentities($k);
		}

		return $out;
	}
}
