<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/catalog.php
 * \ingroup dolicatalog
 * \brief   Read-only JSON feed for the catalog browser: tree, search, favourites.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

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

dol_include_once('/dolicatalog/class/dolicatalogbrowser.class.php');

header('Content-Type: application/json; charset=utf-8');

/**
 * Emit a JSON payload and stop.
 *
 * @param  array<string,mixed> $payload Response body
 * @param  int                 $status  HTTP status code
 * @return never
 */
function dolicatalog_json($payload, $status = 200)
{
	http_response_code($status);
	print json_encode($payload);
	exit;
}

if (!isModEnabled('dolicatalog')) {
	dolicatalog_json(array('ok' => false, 'error' => 'ModuleDisabled'), 403);
}
if (empty($user->id)) {
	dolicatalog_json(array('ok' => false, 'error' => 'NotAuthenticated'), 401);
}
if (!$user->hasRight('dolicatalog', 'picker', 'use')) {
	dolicatalog_json(array('ok' => false, 'error' => 'AccessDenied'), 403);
}
if (!$user->hasRight('produit', 'lire') && !$user->hasRight('service', 'lire')) {
	dolicatalog_json(array('ok' => false, 'error' => 'AccessDenied'), 403);
}

$action = GETPOST('action', 'aZ09');
$category = GETPOSTINT('category');
$search = GETPOST('q', 'alphanohtml');
$mode = DoliCatalogBrowser::normaliseMode(GETPOST('mode', 'aZ09'));
$type = GETPOSTISSET('type') ? GETPOSTINT('type') : -1;
$warehouse = GETPOSTINT('warehouse');
$supplier = GETPOSTINT('supplier');

$catalog = new DoliCatalogBrowser($db);

$offset = GETPOSTINT('offset');

$filters = array(
	'mode' => $mode,
	'offset' => $offset,
	'type' => $type,
	'warehouse' => $warehouse,
	'supplier' => $supplier,
	'userid' => (int) $user->id,
);

switch ($action) {
	case 'browse':
		$categories = $category > 0
			? $catalog->getChildCategories($category)
			: $catalog->getRootCategories();

		// Annotate each folder with how many products sit beneath it.
		$hideEmpty = getDolGlobalInt('DOLICATALOG_HIDE_EMPTY_CATEGORIES', 0);
		$decorated = array();
		foreach ($categories as $cat) {
			$cat['count'] = $catalog->countProductsInCategory($cat['id'], $filters);
			if ($hideEmpty && $cat['count'] === 0) {
				continue;
			}
			$decorated[] = $cat;
		}

		$products = array('rows' => array(), 'truncated' => false);
		if ($category > 0) {
			$products = $catalog->listProducts($filters + array('category' => $category, 'deep' => 0));
		}

		dolicatalog_json(array(
			'ok' => true,
			'breadcrumb' => $category > 0 ? $catalog->getBreadcrumb($category) : array(),
			'categories' => $decorated,
			'products' => $products['rows'],
			'truncated' => $products['truncated'],
			'offset' => $offset,
		));
		break;

	case 'search':
		if (dol_strlen($search) < 1) {
			dolicatalog_json(array('ok' => true, 'breadcrumb' => array(), 'categories' => array(), 'products' => array(), 'truncated' => false));
		}

		$searchFilters = $filters + array('search' => $search);
		if ($category > 0) {
			// Scoped search: stay inside the current branch, subcategories included.
			$searchFilters['category'] = $category;
			$searchFilters['deep'] = 1;
		}

		$products = $catalog->listProducts($searchFilters);

		// A term matching a category name is offered as a folder too, so the
		// search doubles as a shortcut into that branch.
		$matchedCategories = $catalog->searchCategories($search, $searchFilters);
		foreach ($matchedCategories as $k => $mc) {
			$matchedCategories[$k]['count'] = $catalog->countProductsInCategory($mc['id'], $filters);
		}

		dolicatalog_json(array(
			'ok' => true,
			'breadcrumb' => $category > 0 ? $catalog->getBreadcrumb($category) : array(),
			'categories' => $matchedCategories,
			'products' => $products['rows'],
			'truncated' => $products['truncated'],
			'offset' => $offset,
			'scoped' => $category > 0 ? 1 : 0,
		));
		break;

	case 'favorites':
		$ids = $catalog->getFavoriteIds((int) $user->id);
		$products = empty($ids)
			? array('rows' => array(), 'truncated' => false)
			: $catalog->listProducts($filters + array('ids' => $ids));

		dolicatalog_json(array(
			'ok' => true,
			'breadcrumb' => array(),
			'categories' => array(),
			'products' => $products['rows'],
			'truncated' => $products['truncated'],
			'offset' => $offset,
		));
		break;

	case 'recent':
		$ids = $catalog->getRecentIds((int) $user->id);
		if (empty($ids)) {
			dolicatalog_json(array('ok' => true, 'breadcrumb' => array(), 'categories' => array(), 'products' => array(), 'truncated' => false));
		}

		$products = $catalog->listProducts($filters + array('ids' => $ids));

		// Preserve most-recent-first ordering, which the SQL ORDER BY ref lost.
		$byId = array();
		foreach ($products['rows'] as $row) {
			$byId[$row['id']] = $row;
		}
		$ordered = array();
		foreach ($ids as $id) {
			if (isset($byId[$id])) {
				$ordered[] = $byId[$id];
			}
		}

		dolicatalog_json(array(
			'ok' => true,
			'breadcrumb' => array(),
			'categories' => array(),
			'products' => $ordered,
			'truncated' => false,
		));
		break;

	default:
		dolicatalog_json(array('ok' => false, 'error' => 'UnknownAction'), 400);
}
