<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/addlines.php
 * \ingroup dolicatalog
 * \brief   Adds the picked catalog items to a commercial document as lines.
 *
 * POST only, CSRF-checked by main.inc.php. Permissions are the native ones for
 * the target document type — this endpoint never widens what a user may edit.
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

dol_include_once('/dolicatalog/class/dolicataloglineadder.class.php');
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	dolicatalog_json(array('ok' => false, 'error' => 'MethodNotAllowed'), 405);
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

$doctype = GETPOST('doctype', 'aZ09');
$docid = GETPOSTINT('docid');
$rawitems = GETPOST('items', 'restricthtml');

$def = DoliCatalogLineAdder::getTypeDef($doctype);
if (empty($def)) {
	dolicatalog_json(array('ok' => false, 'error' => 'UnsupportedDocumentType'), 400);
}
if (!DoliCatalogLineAdder::userCanCreate($doctype, $user)) {
	dolicatalog_json(array('ok' => false, 'error' => 'AccessDenied'), 403);
}

$decoded = json_decode($rawitems, true);
if (!is_array($decoded) || empty($decoded)) {
	dolicatalog_json(array('ok' => false, 'error' => 'NothingSelected'), 400);
}

// Normalise and cap the batch so one request cannot be used to hammer the box.
$items = array();
foreach ($decoded as $entry) {
	if (!is_array($entry) || empty($entry['id'])) {
		continue;
	}
	$qty = isset($entry['qty']) ? (float) $entry['qty'] : 1;
	if ($qty <= 0) {
		$qty = 1;
	}
	$items[] = array('id' => (int) $entry['id'], 'qty' => $qty);

	if (count($items) >= 200) {
		break;
	}
}

if (empty($items)) {
	dolicatalog_json(array('ok' => false, 'error' => 'NothingSelected'), 400);
}

$adder = new DoliCatalogLineAdder($db);

$object = $adder->fetchDocument($doctype, $docid);
if (!$object) {
	dolicatalog_json(array('ok' => false, 'error' => $adder->error), 404);
}

// Only draft documents accept new lines, matching the native card pages.
// BOM exposes $status while commercial documents expose $statut, so this goes
// through the shared helper rather than probing one property.
if (!DoliCatalogLineAdder::isDraft($object)) {
	dolicatalog_json(array('ok' => false, 'error' => 'DocumentNotDraft'), 409);
}

$result = $adder->addLines($doctype, $object, $items, $user);

if ($result['added'] > 0) {
	$catalog = new DoliCatalogBrowser($db);
	$catalog->recordRecent((int) $user->id, $result['productids']);
}

dolicatalog_json(array(
	'ok' => ($result['added'] > 0),
	'added' => $result['added'],
	'failed' => $result['failed'],
	'messages' => $result['messages'],
));
