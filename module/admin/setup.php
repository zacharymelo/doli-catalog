<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup dolicatalog
 * \brief   Administration page for the Doli Catalog module.
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
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
dol_include_once('/dolicatalog/lib/dolicatalog.lib.php');

$langs->loadLangs(array('admin', 'products', 'dolicatalog@dolicatalog'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Non-boolean settings post here; the on/off toggles save themselves over AJAX.
if ($action === 'update') {
	$constname = GETPOST('constname', 'alpha');
	$constvalue = GETPOST('constvalue', 'alphanohtml');

	$allowed = array(
		'DOLICATALOG_MAX_RESULTS',
		'DOLICATALOG_RECENT_COUNT',
		'DOLICATALOG_DEFAULT_QTY',
		'DOLICATALOG_BUTTON_ICON',
		'DOLICATALOG_ROOT_CATEGORIES',
		'DOLICATALOG_ATTRIBUTE_ROOTS',
	);

	if (!in_array($constname, $allowed, true)) {
		setEventMessages($langs->trans('ErrorBadParameters'), null, 'errors');
	} else {
		// Clamp the numeric settings so a typo cannot make the picker unusable.
		if ($constname === 'DOLICATALOG_MAX_RESULTS') {
			$constvalue = (string) max(1, min(500, (int) $constvalue));
		} elseif ($constname === 'DOLICATALOG_RECENT_COUNT') {
			$constvalue = (string) max(1, min(100, (int) $constvalue));
		} elseif ($constname === 'DOLICATALOG_DEFAULT_QTY') {
			$constvalue = (string) max(1, min(9999, (int) $constvalue));
		} elseif ($constname === 'DOLICATALOG_ROOT_CATEGORIES' || $constname === 'DOLICATALOG_ATTRIBUTE_ROOTS') {
			// Keep digits and commas only.
			$parts = array();
			foreach (explode(',', $constvalue) as $chunk) {
				$chunk = (int) trim($chunk);
				if ($chunk > 0) {
					$parts[] = $chunk;
				}
			}
			$constvalue = implode(',', $parts);
		} elseif ($constname === 'DOLICATALOG_BUTTON_ICON') {
			$constvalue = preg_replace('/[^a-z0-9\- ]/i', '', $constvalue);
		}

		if (dolibarr_set_const($db, $constname, $constvalue, 'chaine', 0, '', $conf->entity) > 0) {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		} else {
			setEventMessages($db->lasterror(), null, 'errors');
		}
	}

	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans('DoliCatalogSetup'), '', '', 0, 0, '', '', '', 'mod-dolicatalog page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DoliCatalogSetup'), $linkback, 'title_setup');

$head = dolicatalogAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('Module500410Name'), -1, 'product');

print '<span class="opacitymedium">'.$langs->trans('DoliCatalogSetupIntro').'</span><br><br>';

// Warn when a hard dependency is off — the picker silently shows nothing otherwise.
if (!isModEnabled('categorie')) {
	print info_admin($langs->trans('WarningModuleNotActive', $langs->transnoentities('Categories')), 0, 0, 'warning');
}
if (!isModEnabled('product') && !isModEnabled('service')) {
	print info_admin($langs->trans('WarningModuleNotActive', $langs->transnoentities('Products')), 0, 0, 'warning');
}

// ---------------------------------------------------------------- Display
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('DoliCatalogDisplay').'</td>';
print '<td class="center" width="120">'.$langs->trans('Value').'</td>';
print '<td>'.$langs->trans('Description').'</td>';
print '</tr>';

dolicatalogPrintToggleRow('DOLICATALOG_SHOW_IMAGES', 'ShowImages', 'ShowImagesDesc');
dolicatalogPrintToggleRow('DOLICATALOG_SHOW_STOCK', 'ShowStock', 'ShowStockDesc');
dolicatalogPrintToggleRow('DOLICATALOG_SHOW_TTC', 'ShowTtc', 'ShowTtcDesc');
dolicatalogPrintToggleRow('DOLICATALOG_SHOW_DURATION', 'ShowDuration', 'ShowDurationDesc');
dolicatalogPrintToggleRow('DOLICATALOG_HIDE_EMPTY_CATEGORIES', 'HideEmptyCategories', 'HideEmptyCategoriesDesc');
dolicatalogPrintInputRow('DOLICATALOG_BUTTON_ICON', 'ButtonIcon', 'ButtonIconDesc', 'text', 'fa-th-large', 'size="16"');

print '</table>';
print '</div>';

print '<br>';

// -------------------------------------------------------------- Behaviour
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('DoliCatalogBehaviour').'</td>';
print '<td class="center" width="120">'.$langs->trans('Value').'</td>';
print '<td>'.$langs->trans('Description').'</td>';
print '</tr>';

dolicatalogPrintToggleRow('DOLICATALOG_ENABLE_FAVORITES', 'EnableFavorites', 'EnableFavoritesDesc');
dolicatalogPrintToggleRow('DOLICATALOG_ENABLE_RECENT', 'EnableRecent', 'EnableRecentDesc');
dolicatalogPrintInputRow('DOLICATALOG_MAX_RESULTS', 'MaxResults', 'MaxResultsDesc', 'number', '50', 'min="1" max="500" style="width:70px;"');
dolicatalogPrintInputRow('DOLICATALOG_RECENT_COUNT', 'RecentCount', 'RecentCountDesc', 'number', '12', 'min="1" max="100" style="width:70px;"');
dolicatalogPrintInputRow('DOLICATALOG_DEFAULT_QTY', 'DefaultQty', 'DefaultQtyDesc', 'number', '1', 'min="1" max="9999" style="width:70px;"');
dolicatalogPrintInputRow('DOLICATALOG_ROOT_CATEGORIES', 'RootCategories', 'RootCategoriesDesc', 'text', '', 'size="24" placeholder="e.g. 3,7,12"');
dolicatalogPrintInputRow('DOLICATALOG_ATTRIBUTE_ROOTS', 'AttributeRoots', 'AttributeRootsDesc', 'text', '', 'size="24" placeholder="e.g. 15"');

// Debug toggle stays last.
dolicatalogPrintToggleRow('DOLICATALOG_LIST_TREE', 'ListTree', 'ListTreeDesc');
dolicatalogPrintToggleRow('DOLICATALOG_DEBUG_MODE', 'DebugMode', 'DebugModeDesc');

print '</table>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
