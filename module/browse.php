<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    browse.php
 * \ingroup dolicatalog
 * \brief   Standalone catalogue browser.
 *
 * The picker only exists inside a document being edited. This page gives the
 * same category navigation somewhere anyone can simply look: useful for staff
 * learning the range, and for answering "what do we sell in X" without starting
 * a quote.
 *
 * Read-only. It shares ajax/catalog.php with the picker, so browsing, search
 * and favourites behave identically in both places.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

dol_include_once('/dolicatalog/class/dolicatalogbrowser.class.php');
// Defines dolicatalogAssetVersion(), used for the script tag below.
dol_include_once('/dolicatalog/lib/dolicatalog.lib.php');

$langs->loadLangs(array('dolicatalog@dolicatalog', 'products', 'categories', 'stocks'));

if (!isModEnabled('dolicatalog')) {
	accessforbidden();
}
if (!$user->hasRight('dolicatalog', 'picker', 'use')) {
	accessforbidden();
}
if (!$user->hasRight('produit', 'lire') && !$user->hasRight('service', 'lire')) {
	accessforbidden();
}

/*
 * View
 */

llxHeader('', $langs->trans('DoliCatalogBrowseTitle'), '', '', 0, 0, '', '', '', 'mod-dolicatalog page-browse');

print dolicatalogStylesheetTag();

print load_fiche_titre($langs->trans('DoliCatalogBrowseTitle'), '', 'product');

print '<span class="opacitymedium">'.$langs->trans('DoliCatalogBrowseIntro').'</span><br><br>';

// Toolbar. Mirrors the picker's controls so the two feel like one feature.
print '<div class="dolicatalog-browse-toolbar">';
print '<input type="search" id="dcb-search" class="dolicatalog-search" placeholder="'.dol_escape_htmltag($langs->trans('DoliCatalogSearchPlaceholder')).'" autocomplete="off">';

print '<select id="dcb-type" class="dolicatalog-filter">';
print '<option value="-1">'.dol_escape_htmltag($langs->trans('DoliCatalogAllTypes')).'</option>';
print '<option value="0">'.dol_escape_htmltag($langs->trans('Products')).'</option>';
print '<option value="1">'.dol_escape_htmltag($langs->trans('Services')).'</option>';
print '</select>';

if (isModEnabled('stock') && getDolGlobalInt('DOLICATALOG_SHOW_STOCK', 1)) {
	require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
	$formproduct = new FormProduct($db);
	print '<span class="dolicatalog-warehouse">';
	// forcecombo = 1: a plain select, consistent with the picker's fix.
	print $formproduct->selectWarehouses(-1, 'dcb_warehouse', '', 1, 0, 0, $langs->trans('DoliCatalogAllWarehouses'), 0, 1);
	print '</span>';
}
print '</div>';

print '<div class="dolicatalog-browse-crumbs" id="dcb-breadcrumb"></div>';
print '<div class="dolicatalog-browse-body" id="dcb-results"></div>';
print '<div class="dolicatalog-browse-pager" id="dcb-pager"></div>';

$config = array(
	'mode' => 'sell',
	'urlCatalog' => dol_buildpath('/dolicatalog/ajax/catalog.php', 1),
	'urlFavorite' => dol_buildpath('/dolicatalog/ajax/favorite.php', 1),
	'urlProduct' => dol_buildpath('/product/card.php', 1),
	'token' => newToken(),
	'pageSize' => max(1, getDolGlobalInt('DOLICATALOG_MAX_RESULTS', 50)),
	'showStock' => getDolGlobalInt('DOLICATALOG_SHOW_STOCK', 1),
	'showTtc' => getDolGlobalInt('DOLICATALOG_SHOW_TTC', 0),
	'showImages' => getDolGlobalInt('DOLICATALOG_SHOW_IMAGES', 1),
	'showDuration' => getDolGlobalInt('DOLICATALOG_SHOW_DURATION', 1),
	'enableFavorites' => getDolGlobalInt('DOLICATALOG_ENABLE_FAVORITES', 1),
	'enableRecent' => getDolGlobalInt('DOLICATALOG_ENABLE_RECENT', 1),
	'currency' => $conf->currency,
	'labels' => array(),
);

$labelKeys = array(
	'Ref', 'Label', 'Stock', 'Duration', 'Products', 'Services',
	'DoliCatalogRoot', 'DoliCatalogCategories', 'DoliCatalogMatchingCategories',
	'DoliCatalogItems', 'DoliCatalogLoading', 'DoliCatalogNoResults',
	'DoliCatalogEmptyCategory', 'DoliCatalogError', 'DoliCatalogFavorites',
	'DoliCatalogRecent', 'DoliCatalogOpenProduct', 'DoliCatalogAddFavorite',
	'DoliCatalogRemoveFavorite', 'DoliCatalogPrevious', 'DoliCatalogNext',
	'DoliCatalogBrowseEmpty', 'DoliCatalogRefineBy', 'DoliCatalogClearTags',
);
foreach ($labelKeys as $k) {
	$config['labels'][$k] = $langs->transnoentities($k);
}

print '<script type="application/json" id="dolicatalog-browse-config">'.json_encode($config).'</script>';
print '<script src="'.dol_buildpath('/dolicatalog/js/dolicatalog-browse.js', 1).'?v='.urlencode(dolicatalogAssetVersion('/dolicatalog/js/dolicatalog-browse.js')).'"></script>';

llxFooter();
$db->close();
