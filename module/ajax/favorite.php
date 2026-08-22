<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/favorite.php
 * \ingroup dolicatalog
 * \brief   Toggles a product in the current user's Doli Catalog favourites.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	print json_encode(array('ok' => false, 'error' => 'MethodNotAllowed'));
	exit;
}
if (!isModEnabled('dolicatalog') || !getDolGlobalInt('DOLICATALOG_ENABLE_FAVORITES', 1)) {
	http_response_code(403);
	print json_encode(array('ok' => false, 'error' => 'FeatureDisabled'));
	exit;
}
if (empty($user->id) || !$user->hasRight('dolicatalog', 'picker', 'use')) {
	http_response_code(403);
	print json_encode(array('ok' => false, 'error' => 'AccessDenied'));
	exit;
}

$productId = GETPOSTINT('productid');
if ($productId <= 0) {
	http_response_code(400);
	print json_encode(array('ok' => false, 'error' => 'BadParameters'));
	exit;
}

$catalog = new DoliCatalogBrowser($db);
$state = $catalog->toggleFavorite((int) $user->id, $productId);

if ($state < 0) {
	http_response_code(500);
	print json_encode(array('ok' => false, 'error' => $catalog->error));
	exit;
}

print json_encode(array('ok' => true, 'favorite' => $state));
