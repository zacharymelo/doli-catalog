<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/debug.php
 * \ingroup dolicatalog
 * \brief   Diagnostics for the Doli Catalog module.
 *          Gated by admin permission plus DOLICATALOG_DEBUG_MODE.
 *
 * Modes (via ?mode=):
 *   overview  - module status, hook registration, table health (default)
 *   catalog   - walk the configured category roots and count products
 *   doctypes  - the six supported document types and the caller's rights on each
 *   settings  - every DOLICATALOG_* constant
 *   classes   - class loading and method availability
 *   sql       - run a read-only diagnostic query (?mode=sql&q=SELECT...)
 *   all       - everything at once
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res && file_exists('../../../../main.inc.php')) {
	$res = @include '../../../../main.inc.php';
}
if (!$res) {
	http_response_code(500);
	exit;
}

if (empty($user->admin)) {
	http_response_code(403);
	print 'Admin only';
	exit;
}
if (!getDolGlobalInt('DOLICATALOG_DEBUG_MODE')) {
	http_response_code(403);
	print 'Debug mode not enabled. Go to Home > Setup > Modules > Doli Catalog > Setup and enable Debug Mode.';
	exit;
}

header('Content-Type: text/plain; charset=utf-8');

dol_include_once('/dolicatalog/class/dolicatalogbrowser.class.php');
dol_include_once('/dolicatalog/class/dolicataloglineadder.class.php');
dol_include_once('/dolicatalog/lib/dolicatalog.lib.php');

$mode = GETPOST('mode', 'alpha') ?: 'overview';
$run_all = ($mode === 'all');

$MODULE_NAME = 'dolicatalog';
$MODULE_UPPER = 'DOLICATALOG';
$TABLES = array('dolicatalog_favorite', 'dolicatalog_recent');
$HOOK_CONTEXTS = array('propalcard', 'ordercard', 'invoicecard', 'supplier_proposalcard', 'ordersuppliercard', 'invoicesuppliercard', 'bomcard');

print "=== DOLICATALOG DEBUG DIAGNOSTICS ===\n";
print "Dolibarr: ".(defined('DOL_VERSION') ? DOL_VERSION : 'unknown')."\n";
print "Module version: ".dolicatalogGetVersion()."\n";
print "Entity: ".((int) $conf->entity)."\n";
print "Mode: ".$mode."\n";
print "Usage: ?mode=overview|catalog|doctypes|settings|classes|sql|all\n";
print str_repeat('=', 62)."\n\n";


// ---------------------------------------------------------------- OVERVIEW
if ($mode === 'overview' || $run_all) {
	print "--- MODULE STATUS ---\n";
	print "  isModEnabled('dolicatalog'): ".(isModEnabled('dolicatalog') ? 'YES' : 'NO')."\n";
	print "  isModEnabled('product'):     ".(isModEnabled('product') ? 'YES' : 'NO')."\n";
	print "  isModEnabled('service'):     ".(isModEnabled('service') ? 'YES' : 'NO')."\n";
	print "  isModEnabled('categorie'):   ".(isModEnabled('categorie') ? 'YES' : 'NO')."\n";
	print "  isModEnabled('stock'):       ".(isModEnabled('stock') ? 'YES' : 'NO')."\n";

	print "\n--- HOOK REGISTRATION ---\n";
	// $conf->modules_parts['hooks'] is keyed by MODULE name, and each value is
	// the list of contexts that module subscribes to.
	$registered = array();
	if (!empty($conf->modules_parts['hooks'][$MODULE_NAME])) {
		$registered = $conf->modules_parts['hooks'][$MODULE_NAME];
		$registered = is_array($registered) ? $registered : array($registered);
	}
	foreach ($HOOK_CONTEXTS as $ctx) {
		printf("  %-24s %s\n", $ctx, in_array($ctx, $registered, true) ? 'OK' : 'NOT REGISTERED');
	}
	$unexpected = array_diff($registered, $HOOK_CONTEXTS);
	if (!empty($unexpected)) {
		print "  (also registered, not used by this module: ".implode(', ', $unexpected).")\n";
	}
	if (empty($registered)) {
		print "  Raw MAIN_MODULE_DOLICATALOG_HOOKS const: ".getDolGlobalString('MAIN_MODULE_DOLICATALOG_HOOKS', '(unset)')."\n";
		print "  If this is unset, disable and re-enable the module.\n";
	}

	print "\n--- ACTIONS CLASS ---\n";
	$actionsFile = dol_buildpath('/dolicatalog/class/actions_dolicatalog.class.php');
	print "  File exists: ".(file_exists($actionsFile) ? 'YES' : 'NO')."\n";
	if (file_exists($actionsFile)) {
		include_once $actionsFile;
		print "  Class exists: ".(class_exists('ActionsDoliCatalog') ? 'YES' : 'NO')."\n";
		foreach (array('formCreateProductOptions', 'formCreateProductSupplierOptions', 'formAddObjectLine') as $m) {
			print "  method ".$m."(): ".(method_exists('ActionsDoliCatalog', $m) ? 'defined' : 'MISSING')."\n";
		}
	}

	print "\n--- DATABASE TABLES ---\n";
	foreach ($TABLES as $tbl) {
		$resql = $db->query("SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX.$tbl);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			print "  ".MAIN_DB_PREFIX.$tbl.": ".$obj->cnt." rows\n";
		} else {
			print "  ".MAIN_DB_PREFIX.$tbl.": TABLE MISSING OR ERROR\n";
		}
	}

	print "\n--- ASSETS ---\n";
	foreach (array('/dolicatalog/js/dolicatalog.js', '/dolicatalog/css/dolicatalog.css') as $asset) {
		print "  ".$asset.": ".(file_exists(dol_buildpath($asset)) ? 'EXISTS' : 'MISSING')."\n";
	}

	print "\n";
}


// ----------------------------------------------------------------- CATALOG
if ($mode === 'catalog' || $run_all) {
	print "--- CATALOG TREE ---\n";

	$catalog = new DoliCatalogBrowser($db);
	$configured = getDolGlobalString('DOLICATALOG_ROOT_CATEGORIES', '');
	print "  DOLICATALOG_ROOT_CATEGORIES: ".($configured !== '' ? $configured : '(empty - all top level categories)')."\n";
	print "  Row limit: ".$catalog->getRowLimit()."\n\n";

	$roots = $catalog->getRootCategories();
	if (empty($roots)) {
		print "  No root categories found. Check that product categories exist and are visible.\n";
	}

	foreach ($roots as $root) {
		$children = $catalog->getChildCategories($root['id']);
		$count = $catalog->countProductsInCategory($root['id'], array('mode' => 'sell'));
		printf("  [%d] %-38s children=%-3d products(deep)=%d\n", $root['id'], dol_trunc($root['label'], 36), count($children), $count);

		foreach ($children as $child) {
			$ccount = $catalog->countProductsInCategory($child['id'], array('mode' => 'sell'));
			printf("       └ [%d] %-32s products(deep)=%d\n", $child['id'], dol_trunc($child['label'], 30), $ccount);
		}
	}

	$resql = $db->query("SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."categorie WHERE type = 0 AND entity IN (".getEntity('category').")");
	if ($resql) {
		$obj = $db->fetch_object($resql);
		print "\n  Total product categories in this entity: ".$obj->cnt."\n";
	}
	$resql = $db->query("SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."categorie_product");
	if ($resql) {
		$obj = $db->fetch_object($resql);
		print "  Total category/product links: ".$obj->cnt."\n";
	}

	print "\n";
}


// ---------------------------------------------------------------- DOCTYPES
if ($mode === 'doctypes' || $run_all) {
	print "--- SUPPORTED DOCUMENT TYPES ---\n";
	printf("  %-20s %-24s %-6s %-10s %s\n", 'TYPE', 'CLASS', 'MODE', 'CAN CREATE', 'CLASS FILE');

	foreach (DoliCatalogLineAdder::getSupportedTypes() as $type) {
		$def = DoliCatalogLineAdder::getTypeDef($type);
		$can = DoliCatalogLineAdder::userCanCreate($type, $user) ? 'YES' : 'no';
		$fileok = file_exists(DOL_DOCUMENT_ROOT.$def['path']) ? 'OK' : 'MISSING';
		printf("  %-20s %-24s %-6s %-10s %s\n", $type, $def['class'], $def['mode'], $can, $fileok);
	}

	print "\n  Current user: ".$user->login." (admin=".((int) $user->admin).")\n";
	print "  dolicatalog->picker->use: ".($user->hasRight('dolicatalog', 'picker', 'use') ? 'YES' : 'NO')."\n";
	print "  produit->lire:            ".($user->hasRight('produit', 'lire') ? 'YES' : 'NO')."\n";
	print "\n";
}


// ---------------------------------------------------------------- SETTINGS
if ($mode === 'settings' || $run_all) {
	print "--- DOLICATALOG SETTINGS ---\n";

	$sql = "SELECT name, value FROM ".MAIN_DB_PREFIX."const";
	$sql .= " WHERE name LIKE 'DOLICATALOG%'";
	$sql .= " AND entity IN (0, ".((int) $conf->entity).")";
	$sql .= " ORDER BY name";

	$resql = $db->query($sql);
	if ($resql) {
		$n = 0;
		while ($row = $db->fetch_object($resql)) {
			$n++;
			printf("  %-40s = %s\n", $row->name, $row->value);
		}
		if ($n === 0) {
			print "  (no constants stored - module may never have been activated)\n";
		}
	}
	print "\n";
}


// ----------------------------------------------------------------- CLASSES
if ($mode === 'classes' || $run_all) {
	print "--- CLASS LOADING ---\n";

	$classes = array(
		'DoliCatalogBrowser' => array('/dolicatalog/class/dolicatalogbrowser.class.php', array('getRootCategories', 'getChildCategories', 'getBreadcrumb', 'getDescendantIds', 'listProducts', 'countProductsInCategory', 'toggleFavorite', 'recordRecent')),
		'DoliCatalogLineAdder' => array('/dolicatalog/class/dolicataloglineadder.class.php', array('fetchDocument', 'addLines', 'getTypeDef', 'userCanCreate')),
		'ActionsDoliCatalog' => array('/dolicatalog/class/actions_dolicatalog.class.php', array('formCreateProductOptions', 'formCreateProductSupplierOptions', 'formAddObjectLine')),
	);

	foreach ($classes as $classname => $spec) {
		list($path, $methods) = $spec;
		print "  ".$classname.":\n";
		print "    include: ".(dol_include_once($path) !== false ? 'OK' : 'FAILED')."\n";
		print "    class_exists: ".(class_exists($classname) ? 'YES' : 'NO')."\n";
		if (class_exists($classname)) {
			$missing = array();
			foreach ($methods as $m) {
				if (!method_exists($classname, $m)) {
					$missing[] = $m;
				}
			}
			print "    methods: ".(empty($missing) ? 'ALL PRESENT' : 'MISSING: '.implode(', ', $missing))."\n";
		}
	}
	print "\n";
}


// --------------------------------------------------------------------- SQL
if ($mode === 'sql') {
	$q = GETPOST('q', 'restricthtml');
	print "--- SQL QUERY ---\n";

	if (empty($q)) {
		print "Usage: ?mode=sql&q=SELECT+rowid,label+FROM+".MAIN_DB_PREFIX."categorie+WHERE+type=0+LIMIT+10\n\n";
		print "Useful queries:\n";
		print "  ?mode=sql&q=SELECT rowid,fk_parent,label,visible FROM ".MAIN_DB_PREFIX."categorie WHERE type=0 ORDER BY fk_parent,label LIMIT 50\n";
		print "  ?mode=sql&q=SELECT fk_categorie,COUNT(*) c FROM ".MAIN_DB_PREFIX."categorie_product GROUP BY fk_categorie ORDER BY c DESC LIMIT 20\n";
		print "  ?mode=sql&q=SELECT rowid,ref,label,tosell,tobuy,fk_product_type FROM ".MAIN_DB_PREFIX."product LIMIT 20\n";
		print "  ?mode=sql&q=SELECT rowid,fk_user,fk_product FROM ".MAIN_DB_PREFIX."dolicatalog_favorite LIMIT 20\n";
		print "  ?mode=sql&q=SELECT rowid,fk_user,fk_product,pick_count,date_last FROM ".MAIN_DB_PREFIX."dolicatalog_recent ORDER BY date_last DESC LIMIT 20\n";
	} else {
		$q = trim($q);
		if (stripos($q, 'SELECT') !== 0) {
			print "ERROR: Only SELECT queries are allowed.\n";
		} elseif (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE)\b/i', $q)) {
			print "ERROR: Query contains a blocked keyword.\n";
		} else {
			if (stripos($q, 'LIMIT') === false) {
				$q .= ' LIMIT 50';
			}
			print "Query: ".$q."\n\n";

			$resql = $db->query($q);
			if ($resql) {
				$first = true;
				$n = 0;
				while ($row = $db->fetch_array($resql)) {
					// fetch_array returns both numeric and named keys; keep the named ones.
					$named = array();
					foreach ($row as $k => $v) {
						if (!is_int($k)) {
							$named[$k] = $v;
						}
					}
					if ($first) {
						print implode("\t", array_keys($named))."\n";
						print str_repeat('-', 80)."\n";
						$first = false;
					}
					$n++;
					$vals = array();
					foreach ($named as $v) {
						$vals[] = ($v === null) ? 'NULL' : (strlen((string) $v) > 40 ? substr((string) $v, 0, 40).'...' : $v);
					}
					print implode("\t", $vals)."\n";
				}
				print "\n".$n." rows returned.\n";
			} else {
				print "SQL ERROR: ".$db->lasterror()."\n";
			}
		}
	}
	print "\n";
}

print "=== END DEBUG ===\n";
