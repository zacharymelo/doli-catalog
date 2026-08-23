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

		$this->version = '1.0.1';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'product';

		// Module parts.
		$this->module_parts = array(
			'triggers' => 0,
			'css' => array('/dolicatalog/css/dolicatalog.css'),
			'hooks' => array(
				'data' => array(
					'propalcard',
					'ordercard',
					'invoicecard',
					'supplier_proposalcard',
					'ordersuppliercard',
					'invoicesuppliercard',
					'bomcard',
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
		$this->const = array(
			array('DOLICATALOG_SHOW_STOCK', 'chaine', '1', 'Show stock column in the picker', 0, 'current', 1),
			array('DOLICATALOG_SHOW_TTC', 'chaine', '0', 'Show price incl. tax in the picker', 0, 'current', 1),
			array('DOLICATALOG_SHOW_IMAGES', 'chaine', '1', 'Show product thumbnails in the picker', 0, 'current', 1),
			array('DOLICATALOG_SHOW_DURATION', 'chaine', '1', 'Show service duration in the picker', 0, 'current', 1),
			array('DOLICATALOG_ENABLE_FAVORITES', 'chaine', '1', 'Enable per-user favourites', 0, 'current', 1),
			array('DOLICATALOG_ENABLE_RECENT', 'chaine', '1', 'Enable per-user recently used', 0, 'current', 1),
			array('DOLICATALOG_HIDE_EMPTY_CATEGORIES', 'chaine', '0', 'Hide categories with no products', 0, 'current', 1),
			array('DOLICATALOG_MAX_RESULTS', 'chaine', '50', 'Maximum rows returned per query', 0, 'current', 1),
			array('DOLICATALOG_RECENT_COUNT', 'chaine', '12', 'Number of recent items to keep per user', 0, 'current', 1),
			array('DOLICATALOG_DEFAULT_QTY', 'chaine', '1', 'Default quantity pre-filled for a picked item', 0, 'current', 1),
			array('DOLICATALOG_BUTTON_ICON', 'chaine', 'fa-th-large', 'Font Awesome class used for the trigger button', 0, 'current', 1),
			array('DOLICATALOG_ROOT_CATEGORIES', 'chaine', '', 'Comma separated category ids to show as roots (empty = all roots)', 0, 'current', 1),
			array('DOLICATALOG_DEBUG_MODE', 'chaine', '0', 'Expose the diagnostic endpoint', 0, 'current', 1),
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
		$this->menu = array();
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
