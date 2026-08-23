<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tools/bulk-tag.php
 * \brief   Bulk-assign Dolibarr product categories from rules. CLI only.
 *
 * A standalone maintenance script, NOT part of the installable module. It is
 * excluded from the deployment zip (build.sh packages module/ only).
 *
 * Tagging is ADDITIVE. It uses Categorie::add_type(), never
 * Product::setCategories() — the latter REPLACES a product's whole category
 * set and would silently erase tagging that already exists.
 *
 * Nothing is written unless --apply is passed. The default is a dry run.
 *
 * Usage:
 *   php bulk-tag.php --report
 *   php bulk-tag.php --rules=rules.json
 *   php bulk-tag.php --rules=rules.json --apply --create-missing
 *   php bulk-tag.php --csv=map.csv --apply
 *
 * Options:
 *   --report            Show tagging coverage and exit. Never writes.
 *   --rules=FILE        JSON rule file (see below).
 *   --csv=FILE          CSV of "ref,category path" pairs, one per line.
 *   --apply             Actually write. Without it, the script only reports.
 *   --create-missing    Create categories named in rules that do not exist.
 *   --only-untagged     Consider only products currently in zero categories.
 *   --type=0|1          Restrict to products (0) or services (1).
 *   --limit=N           Process at most N products.
 *   --entity=N          Entity to operate on (default: 1).
 *
 * Rule file format:
 *   {
 *     "rules": [
 *       {"match": "prefix",   "value": "LAP-",    "category": "Hardware/Laptops"},
 *       {"match": "regex",    "value": "^DOCK-",  "category": "Hardware/Accessories/Docking stations"},
 *       {"match": "label",    "value": "licence", "category": "Software/Licences"},
 *       {"match": "supplier", "value": 12,        "category": "Suppliers/Acme"},
 *       {"match": "type",     "value": 1,         "category": "Services"},
 *       {"match": "all",      "value": "",        "category": "Needs review"}
 *     ]
 *   }
 *
 * Every matching rule is applied, because a product may legitimately belong to
 * several categories. Category paths are "/"-separated and resolved from the
 * root of the product category tree.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit("This script is CLI only.\n");
}

define('NOSESSION', 1);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Locate Dolibarr's master.inc.php: alongside a normal custom/<module>/tools
// layout, or via DOLIBARR_ROOT for unusual installs.
$candidates = array(
	getenv('DOLIBARR_ROOT') ? rtrim(getenv('DOLIBARR_ROOT'), '/').'/master.inc.php' : null,
	__DIR__.'/../../../master.inc.php',
	__DIR__.'/../../master.inc.php',
	'/var/www/html/master.inc.php',
);
$loaded = false;
foreach ($candidates as $c) {
	if ($c && file_exists($c)) {
		require_once $c;
		$loaded = true;
		break;
	}
}
if (!$loaded) {
	exit("Could not locate master.inc.php. Set DOLIBARR_ROOT=/path/to/htdocs\n");
}

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

/**
 * Read a --name=value style flag from argv.
 *
 * @param  string      $name    Option name without dashes
 * @param  string|null $default Value when the option is absent
 * @return string|null          Option value, '' for a bare flag
 */
function opt($name, $default = null)
{
	foreach ($GLOBALS['argv'] as $arg) {
		if ($arg === '--'.$name) {
			return '';
		}
		if (strpos($arg, '--'.$name.'=') === 0) {
			return substr($arg, strlen($name) + 3);
		}
	}
	return $default;
}

/**
 * Whether a bare flag was supplied.
 *
 * @param  string $name Option name without dashes
 * @return bool         True when present
 */
function flag($name)
{
	return opt($name) !== null;
}

$doApply       = flag('apply');
$doReport      = flag('report');
$createMissing = flag('create-missing');
$onlyUntagged  = flag('only-untagged');
$rulesFile     = opt('rules');
$csvFile       = opt('csv');
$typeFilter    = opt('type');
$limit         = (int) opt('limit', 0);
$entity        = (int) opt('entity', 1);

$conf->entity = $entity;

$user = new User($db);
if ($user->fetch(0, 'admin') <= 0) {
	// Fall back to the lowest-id admin when the login is not literally "admin".
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE admin = 1 ORDER BY rowid LIMIT 1");
	$row = $resql ? $db->fetch_object($resql) : null;
	if (!$row || $user->fetch($row->rowid) <= 0) {
		exit("Could not load an admin user to attribute the changes to.\n");
	}
}
$user->getrights();

print "Dolibarr ".(defined('DOL_VERSION') ? DOL_VERSION : '?')."  entity=".$entity."  acting as: ".$user->login."\n";
print str_repeat('=', 72)."\n";


// ---------------------------------------------------------------- coverage

/**
 * Report how much of the catalogue is currently categorised.
 *
 * @param  DoliDB $db     Database handler
 * @param  int    $entity Entity id
 * @return void
 */
function reportCoverage($db, $entity)
{
	$scope = " FROM ".MAIN_DB_PREFIX."product p WHERE p.entity IN (".getEntity('product').")";

	$total = $tagged = 0;
	$resql = $db->query("SELECT COUNT(*) c".$scope);
	if ($resql && ($o = $db->fetch_object($resql))) {
		$total = (int) $o->c;
	}

	$resql = $db->query("SELECT COUNT(DISTINCT cp.fk_product) c"
		." FROM ".MAIN_DB_PREFIX."categorie_product cp"
		." INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cp.fk_product"
		." WHERE p.entity IN (".getEntity('product').")");
	if ($resql && ($o = $db->fetch_object($resql))) {
		$tagged = (int) $o->c;
	}

	$untagged = $total - $tagged;
	$pct = $total > 0 ? round(($tagged / $total) * 100, 1) : 0;

	print "CATALOGUE COVERAGE\n";
	printf("  products+services : %d\n", $total);
	printf("  in >=1 category   : %d (%s%%)\n", $tagged, $pct);
	printf("  untagged          : %d\n", $untagged);

	if ($untagged > 0) {
		print "\n  Untagged (first 40):\n";
		$resql = $db->query("SELECT p.rowid, p.ref, p.label, p.fk_product_type"
			.$scope
			." AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product cp WHERE cp.fk_product = p.rowid)"
			." ORDER BY p.ref LIMIT 40");
		if ($resql) {
			while ($o = $db->fetch_object($resql)) {
				printf("    %-18s %-40s %s\n", $o->ref, dol_trunc($o->label, 38), $o->fk_product_type == 1 ? '(service)' : '');
			}
		}
	}
	print "\n";
}

if ($doReport) {
	reportCoverage($db, $entity);
	exit(0);
}


// ------------------------------------------------------------------- rules

$rules = array();

if ($rulesFile) {
	if (!file_exists($rulesFile)) {
		exit("Rule file not found: $rulesFile\n");
	}
	$parsed = json_decode(file_get_contents($rulesFile), true);
	if (!is_array($parsed) || empty($parsed['rules'])) {
		exit("Rule file is not valid JSON with a 'rules' array.\n");
	}
	$rules = $parsed['rules'];
}

if ($csvFile) {
	if (!file_exists($csvFile)) {
		exit("CSV file not found: $csvFile\n");
	}
	$fh = fopen($csvFile, 'r');
	$lineno = 0;
	while (($row = fgetcsv($fh)) !== false) {
		$lineno++;
		if (count($row) < 2 || $row[0] === '' || $row[0][0] === '#') {
			continue;
		}
		$rules[] = array('match' => 'ref', 'value' => trim($row[0]), 'category' => trim($row[1]));
	}
	fclose($fh);
	print "Loaded ".$lineno." CSV line(s).\n";
}

if (empty($rules)) {
	exit("Nothing to do. Pass --rules=FILE, --csv=FILE, or --report.\n");
}

print "Loaded ".count($rules)." rule(s). Mode: ".($doApply ? "APPLY (will write)" : "DRY RUN (no writes)")."\n\n";


// -------------------------------------------------------- category resolve

$categoryCache = array();
$wouldCreate = array();

/**
 * Resolve a "A/B/C" category path to a category id, optionally creating it.
 *
 * @param  DoliDB $db            Database handler
 * @param  User   $user          Acting user
 * @param  string $path          Slash separated category path
 * @param  bool   $createMissing Create levels that do not exist
 * @param  bool   $doApply       False to simulate creation only
 * @return int                   Category id, 0 when unresolved
 */
function resolveCategoryPath($db, $user, $path, $createMissing, $doApply)
{
	global $categoryCache, $wouldCreate;

	$path = trim($path, " /\t\n");
	if ($path === '') {
		return 0;
	}
	if (isset($categoryCache[$path])) {
		return $categoryCache[$path];
	}

	$parts = array_map('trim', explode('/', $path));
	$parent = 0;
	$walked = array();

	foreach ($parts as $part) {
		$walked[] = $part;
		$sofar = implode('/', $walked);

		if (isset($categoryCache[$sofar])) {
			$parent = $categoryCache[$sofar];
			continue;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie"
			." WHERE type = 0"
			." AND entity IN (".getEntity('category').")"
			." AND fk_parent = ".((int) $parent)
			." AND label = '".$db->escape($part)."'";
		$resql = $db->query($sql);
		$row = $resql ? $db->fetch_object($resql) : null;

		if ($row) {
			$parent = (int) $row->rowid;
			$categoryCache[$sofar] = $parent;
			continue;
		}

		if (!$createMissing) {
			return 0;
		}

		if (!$doApply) {
			// Simulated id so the rest of the dry run can proceed.
			if (!in_array($sofar, $wouldCreate, true)) {
				$wouldCreate[] = $sofar;
			}
			$parent = -1;
			$categoryCache[$sofar] = -1;
			continue;
		}

		$cat = new Categorie($db);
		$cat->label = $part;
		$cat->type = 0;
		$cat->fk_parent = ($parent > 0 ? $parent : 0);
		$cat->visible = 1;   // core leaves this 0; set it so the row is not odd
		$newid = $cat->create($user);
		if ($newid <= 0) {
			print "  ! could not create category '".$sofar."': ".$cat->error."\n";
			return 0;
		}
		print "  + created category ".$sofar." (id ".$newid.")\n";
		$parent = (int) $newid;
		$categoryCache[$sofar] = $parent;
	}

	$categoryCache[$path] = $parent;

	return $parent;
}


// ----------------------------------------------------------- product scan

$sql = "SELECT p.rowid, p.ref, p.label, p.fk_product_type"
	." FROM ".MAIN_DB_PREFIX."product p"
	." WHERE p.entity IN (".getEntity('product').")";

if ($typeFilter !== null && $typeFilter !== '') {
	$sql .= " AND p.fk_product_type = ".((int) $typeFilter);
}
if ($onlyUntagged) {
	$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product cp WHERE cp.fk_product = p.rowid)";
}
$sql .= " ORDER BY p.ref";
if ($limit > 0) {
	$sql .= " LIMIT ".$limit;
}

$resql = $db->query($sql);
if (!$resql) {
	exit("Product query failed: ".$db->lasterror()."\n");
}

$products = array();
while ($o = $db->fetch_object($resql)) {
	$products[] = $o;
}
$db->free($resql);

print "Scanning ".count($products)." product(s).\n\n";


// ------------------------------------------------------------ rule matcher

/**
 * Whether a rule matches a product row.
 *
 * @param  array<string,mixed> $rule    Rule definition
 * @param  stdClass            $p       Product row
 * @param  DoliDB              $db      Database handler
 * @return bool                         True on match
 */
function ruleMatches($rule, $p, $db)
{
	$match = isset($rule['match']) ? $rule['match'] : '';
	$value = isset($rule['value']) ? $rule['value'] : '';

	switch ($match) {
		case 'all':
			return true;

		case 'ref':
			return strcasecmp((string) $p->ref, (string) $value) === 0;

		case 'prefix':
			return $value !== '' && stripos((string) $p->ref, (string) $value) === 0;

		case 'suffix':
			return $value !== '' && substr_compare((string) $p->ref, (string) $value, -strlen((string) $value), strlen((string) $value), true) === 0;

		case 'regex':
			$re = '/'.str_replace('/', '\/', (string) $value).'/i';
			return @preg_match($re, (string) $p->ref) === 1;

		case 'label':
			return $value !== '' && stripos((string) $p->label, (string) $value) !== false;

		case 'type':
			return (int) $p->fk_product_type === (int) $value;

		case 'supplier':
			$sql = "SELECT 1 FROM ".MAIN_DB_PREFIX."product_fournisseur_price"
				." WHERE fk_product = ".((int) $p->rowid)
				." AND fk_soc = ".((int) $value)." LIMIT 1";
			$r = $db->query($sql);
			return (bool) ($r && $db->num_rows($r) > 0);
	}

	return false;
}


// ------------------------------------------------------------------ run

$planned = 0;
$applied = 0;
$skipped = 0;
$failed  = 0;
$unresolved = array();

if ($doApply) {
	$db->begin();
}

foreach ($products as $p) {
	foreach ($rules as $rule) {
		if (!ruleMatches($rule, $p, $db)) {
			continue;
		}

		$path = isset($rule['category']) ? $rule['category'] : '';
		$catid = resolveCategoryPath($db, $user, $path, $createMissing, $doApply);

		if ($catid === 0) {
			if (!in_array($path, $unresolved, true)) {
				$unresolved[] = $path;
			}
			continue;
		}

		// Simulated category during a dry run with --create-missing.
		if ($catid === -1) {
			$planned++;
			printf("  would tag %-18s -> %s (new category)\n", $p->ref, $path);
			continue;
		}

		$cat = new Categorie($db);
		if ($cat->fetch($catid) <= 0) {
			$failed++;
			continue;
		}

		// Additive and idempotent: never re-add an existing membership.
		if ($cat->containsObject('product', (int) $p->rowid)) {
			$skipped++;
			continue;
		}

		$planned++;

		if (!$doApply) {
			printf("  would tag %-18s -> %s\n", $p->ref, $path);
			continue;
		}

		$product = new Product($db);
		if ($product->fetch((int) $p->rowid) <= 0) {
			$failed++;
			continue;
		}

		$res = $cat->add_type($product, 'product');
		if ($res >= 0) {
			$applied++;
			printf("  tagged    %-18s -> %s\n", $p->ref, $path);
		} else {
			$failed++;
			printf("  ! failed  %-18s -> %s : %s\n", $p->ref, $path, $cat->error);
		}
	}
}

if ($doApply) {
	if ($failed > 0) {
		$db->rollback();
		print "\nROLLED BACK: ".$failed." failure(s); no changes were kept.\n";
		exit(1);
	}
	$db->commit();
}

print "\n".str_repeat('-', 72)."\n";
printf("planned: %d   applied: %d   already tagged (skipped): %d   failed: %d\n", $planned, $applied, $skipped, $failed);

if (!empty($wouldCreate)) {
	print "\nCategories that would be created (--create-missing, dry run):\n";
	foreach ($wouldCreate as $w) {
		print "  ".$w."\n";
	}
}

if (!empty($unresolved)) {
	print "\nUnresolved category paths (add --create-missing to create them):\n";
	foreach ($unresolved as $u) {
		print "  ".$u."\n";
	}
}

if (!$doApply) {
	print "\nDRY RUN — nothing was written. Re-run with --apply to commit.\n";
}

print "\n";
reportCoverage($db, $entity);
