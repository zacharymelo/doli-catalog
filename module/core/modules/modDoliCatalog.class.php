<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modDoliCatalog.class.php
 * \ingroup dolicatalog
 * \brief   Module descriptor for Doli Catalog.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Doli Catalog — a visual, tree-based product and service picker for document line entry.
 */
class modDoliCatalog extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		$this->db = $db;

		$this->numero = 500410;
		$this->rights_class = 'dolicatalog';
		$this->family = 'products';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = 'Visual, tree-based product and service picker for quotes, orders, invoices and bills of materials';
		$this->descriptionlong = 'Adds a "Browse catalog" button to the line entry form of customer proposals, sales orders, customer invoices, supplier proposals, purchase orders, supplier invoices and bills of materials. Browse native Dolibarr category trees, search across the catalog, select several items with quantities and add them all in one action. Reads native tables only — no core modification.';

		$this->editor_name = 'Zachary Melo';
		$this->editor_url = '';

		$this->version = '1.8.4';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'product';

		// Module parts.
		$this->module_parts = array(
			'triggers' => 0,
			// The stylesheet is NOT registered here. module_parts emits a plain
			// <link> with no query string, so browsers cache it indefinitely and
			// every deploy needed a manual hard reload before new styles applied.
			// It is emitted instead by dolicatalogStylesheetTag(), keyed to the
			// file's modification time.
			'hooks' => array(
				'data' => array(
					'propalcard',
					'ordercard',
					'invoicecard',
					'supplier_proposalcard',
					'ordersuppliercard',
					'invoicesuppliercard',
					'bomcard',
					// Category filter strip above the native product list.
					'productservicelist',
				),
				'entity' => '0',
			),
		);

		$this->dirs = array();

		$this->config_page_url = array('setup.php@dolicatalog');

		// Products and Categories must both be on for the picker to have anything to show.
		$this->depends = array('modProduct', 'modCategorie');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array('dolicatalog@dolicatalog');

		$this->phpmin = array(7, 3);
		$this->need_dolibarr_version = array(19, 0);

		// Default configuration applied on activation.
		// The last value of each row is deleteonunactive. It is 0 deliberately.
		// Dolibarr's delete_const() drops any constant flagged 1 when the module is
		// disabled, and several changes here need a disable/enable cycle to take
		// effect (menus and hook contexts are only written at activation). Flagging
		// these 1 would wipe every configured setting during a routine upgrade.
		$this->const = array(
			array('DOLICATALOG_SHOW_STOCK', 'chaine', '1', 'Show stock column in the picker', 0, 'current', 0),
			array('DOLICATALOG_SHOW_TTC', 'chaine', '0', 'Show price incl. tax in the picker', 0, 'current', 0),
			array('DOLICATALOG_SHOW_IMAGES', 'chaine', '1', 'Show product thumbnails in the picker', 0, 'current', 0),
			array('DOLICATALOG_SHOW_DURATION', 'chaine', '1', 'Show service duration in the picker', 0, 'current', 0),
			array('DOLICATALOG_ENABLE_FAVORITES', 'chaine', '1', 'Enable per-user favourites', 0, 'current', 0),
			array('DOLICATALOG_ENABLE_RECENT', 'chaine', '1', 'Enable per-user recently used', 0, 'current', 0),
			array('DOLICATALOG_HIDE_EMPTY_CATEGORIES', 'chaine', '0', 'Hide categories with no products', 0, 'current', 0),
			array('DOLICATALOG_MAX_RESULTS', 'chaine', '50', 'Maximum rows returned per query', 0, 'current', 0),
			array('DOLICATALOG_RECENT_COUNT', 'chaine', '12', 'Number of recent items to keep per user', 0, 'current', 0),
			array('DOLICATALOG_DEFAULT_QTY', 'chaine', '1', 'Default quantity pre-filled for a picked item', 0, 'current', 0),
			array('DOLICATALOG_BUTTON_ICON', 'chaine', 'fa-th-large', 'Font Awesome class used for the trigger button', 0, 'current', 0),
			array('DOLICATALOG_ROOT_CATEGORIES', 'chaine', '', 'Comma separated category ids to show as roots (empty = all roots)', 0, 'current', 0),
			array('DOLICATALOG_LIST_TREE', 'chaine', '0', 'Show a category filter strip above the product list', 0, 'current', 0),
			array('DOLICATALOG_ATTRIBUTE_ROOTS', 'chaine', '', 'Category ids whose children name an attribute', 0, 'current', 0),
			array('DOLICATALOG_MAX_FACETS', 'chaine', '200', 'Maximum tag filters shown before the list is truncated', 0, 'current', 0),
			array('DOLICATALOG_ARCHIVED_CATEGORY', 'chaine', '0', 'Category marking a product as archived and hidden from the catalog', 0, 'current', 0),
			array('DOLICATALOG_DEBUG_MODE', 'chaine', '0', 'Expose the diagnostic endpoint', 0, 'current', 0),
		);

		// Single permission: may this user open the catalog browser at all.
		$r = 0;
		$r++;
		$this->rights[$r][0] = 500410;
		$this->rights[$r][1] = 'Use the Doli Catalog catalog browser';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 1; // enabled by default for all users
		$this->rights[$r][4] = 'picker';
		$this->rights[$r][5] = 'use';

		// No menu entries: the module surfaces itself inside existing document pages.
		// A standalone browse page, so the catalogue can be explored outside a
		// document.
		//
		// It nests inside the native Products group (fk_leftmenu=product),
		// alongside List, Stocks and Statistics, rather than standing as its own
		// section header next to Products and Services. It is one more way to
		// look at products, not a peer of the whole product area. No prefix icon
		// for the same reason: its siblings in that group do not carry one.
		$this->menu = array(
			array(
				'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=product',
				'type'     => 'left',
				'titre'    => 'DoliCatalogBrowseMenu',
				'mainmenu' => 'products',
				'leftmenu' => 'dolicatalog_browse',
				'url'      => '/dolicatalog/browse.php',
				'langs'    => 'dolicatalog@dolicatalog',
				'position' => 990,
				'enabled'  => 'isModEnabled("dolicatalog")',
				'perms'    => '$user->hasRight("dolicatalog", "picker", "use")',
				'target'   => '',
				'user'     => 0,
			),
		);
		$this->tabs = array();
	}

	/**
	 * Enable the module: create tables, then run the standard init.
	 *
	 * @param  string $options Options
	 * @return int             1 on success, <=0 on failure
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/dolicatalog/sql/');
		if ($result < 0) {
			return -1;
		}

		// Upgrade cleanup. Releases up to 1.3.0 registered the stylesheet through
		// module_parts. delete_module_parts() only removes constants for keys
		// still present in the descriptor, so dropping 'css' from it orphaned
		// this constant and Dolibarr kept emitting a second, unversioned <link>
		// that browsers cache indefinitely. Remove it explicitly.
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."const";
		$sql .= " WHERE ".$this->db->decrypt('name')." = '".$this->db->escape($this->const_name.'_CSS')."'";
		$this->db->query($sql);

		$this->delete_menus();

		return $this->_init(array(), $options);
	}

	/**
	 * Disable the module.
	 *
	 * @param  string $options Options
	 * @return int             1 on success, <=0 on failure
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
