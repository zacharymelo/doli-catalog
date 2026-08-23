<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/dolicatalog.lib.php
 * \ingroup dolicatalog
 * \brief   Shared helpers for the Doli Catalog module.
 */

/**
 * Tabs shown on the module administration pages.
 *
 * @return array<int,array<int,string>> Head array for dol_get_fiche_head()
 */
function dolicatalogAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('dolicatalog@dolicatalog');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/dolicatalog/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolicatalog@dolicatalog', 'remove');

	return $head;
}

/**
 * Render one on/off setting row using Dolibarr's standard AJAX toggle.
 *
 * @param  string $constant    Constant name
 * @param  string $labelKey    Translation key for the label
 * @param  string $helpKey     Translation key for the help text
 * @return void
 */
function dolicatalogPrintToggleRow($constant, $labelKey, $helpKey)
{
	global $langs;

	print '<tr class="oddeven">';
	print '<td>'.$langs->trans($labelKey).'</td>';
	print '<td class="center">'.ajax_constantonoff($constant).'</td>';
	print '<td class="opacitymedium">'.$langs->trans($helpKey).'</td>';
	print '</tr>';
}

/**
 * Render one free-text or numeric setting row with its own inline form.
 *
 * @param  string $constant Constant name
 * @param  string $labelKey Translation key for the label
 * @param  string $helpKey  Translation key for the help text
 * @param  string $type     HTML input type ('number' or 'text')
 * @param  string $default  Value used when the constant is unset
 * @param  string $extra    Extra HTML attributes for the input
 * @return void
 */
function dolicatalogPrintInputRow($constant, $labelKey, $helpKey, $type = 'text', $default = '', $extra = '')
{
	global $langs;

	print '<tr class="oddeven">';
	print '<td>'.$langs->trans($labelKey).'</td>';
	print '<td class="center">';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="constname" value="'.dol_escape_htmltag($constant).'">';
	print '<input type="'.dol_escape_htmltag($type).'" name="constvalue" '.$extra;
	print ' value="'.dol_escape_htmltag(getDolGlobalString($constant, $default)).'">';
	print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
	print '</form>';
	print '</td>';
	print '<td class="opacitymedium">'.$langs->trans($helpKey).'</td>';
	print '</tr>';
}

/**
 * Version declared by the module descriptor.
 *
 * Dolibarr does not store a MAIN_MODULE_DOLICATALOG_VERSION constant, so the
 * descriptor itself is the only source of truth. Used for asset cache busting
 * and for the diagnostics header.
 *
 * @return string Semantic version, or 'unknown' when the descriptor is missing
 */
function dolicatalogGetVersion()
{
	static $version = null;

	if ($version !== null) {
		return $version;
	}

	$version = 'unknown';
	$file = dol_buildpath('/dolicatalog/core/modules/modDoliCatalog.class.php');
	if (file_exists($file)) {
		require_once $file;
		if (class_exists('modDoliCatalog')) {
			global $db;
			$descriptor = new modDoliCatalog($db);
			if (!empty($descriptor->version)) {
				$version = $descriptor->version;
			}
		}
	}

	return $version;
}

/**
 * Cache-busting token for a module asset, derived from the file itself.
 *
 * Keying an asset URL to the module version only busts caches when the version
 * is bumped, and keying it to MAIN_MODULE_DOLICATALOG_VERSION does not work at
 * all - Dolibarr never writes that constant, so the URL pinned to the fallback
 * forever. A file's modification time changes exactly when the file does, which
 * is the property actually wanted.
 *
 * @param  string $relPath Module-relative asset path, e.g. '/dolicatalog/js/x.js'
 * @return string          Version token for a query string
 */
function dolicatalogAssetVersion($relPath)
{
	$full = dol_buildpath($relPath, 0);
	$mtime = (is_string($full) && $full !== '' && file_exists($full)) ? filemtime($full) : false;

	if ($mtime === false) {
		// Never fail an asset over a missing timestamp; fall back to the
		// declared module version, which at least changes each release.
		return dolicatalogGetVersion();
	}

	return (string) $mtime;
}

/**
 * The module stylesheet as a versioned <link> tag.
 *
 * Registering the stylesheet through module_parts produced an unversioned URL,
 * so a deployed CSS change did not reach anyone until they hard-reloaded.
 * Keying the query string to the file's modification time means a deploy is
 * enough on its own.
 *
 * @return string A <link> element
 */
function dolicatalogStylesheetTag()
{
	$rel = '/dolicatalog/css/dolicatalog.css';

	return '<link rel="stylesheet" type="text/css" href="'
		.dol_buildpath($rel, 1).'?v='.urlencode(dolicatalogAssetVersion($rel)).'">';
}
