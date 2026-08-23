# Changelog

All notable changes to Doli Catalog are documented here.

This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `tools/bulk-tag.php` — a standalone CLI maintenance script that bulk-assigns
  product categories from prefix/regex/label/supplier/type rules or a CSV
  mapping. Dry run by default, additive (never replaces a product's existing
  categories), idempotent, and transactional. Not shipped in the installable
  zip. See `tools/README.md`.

## [1.3.1] - 2026-08-23

### Fixed

- **Product descriptions showed raw markup.** Descriptions authored in
  Dolibarr's rich text editor appeared literally as
  `<strong>Pressure:</strong> 20psi<br />` in both the picker and the catalogue
  page, because both render text safely rather than as HTML. Descriptions are
  now reduced to plain text before display, with block boundaries becoming
  spaces so consecutive paragraphs do not run together. Stripping happens before
  truncation, since truncating raw HTML can cut mid-tag.

- **Stylesheet changes did not reach anyone until they hard-reloaded.** The
  stylesheet was registered through `module_parts`, which emits a plain `<link>`
  with no query string, so browsers cached it indefinitely and a deployed CSS
  change appeared to have done nothing. It is now emitted with a token derived
  from the file's modification time, so deploying is sufficient on its own.

- **Orphaned stylesheet registration on upgrade.** `delete_module_parts()` only
  removes constants for keys still present in the descriptor, so removing `css`
  from `module_parts` left `MAIN_MODULE_DOLICATALOG_CSS` behind permanently and
  Dolibarr kept emitting a second, unversioned link. `init()` now deletes that
  constant explicitly when the module is enabled.

## [1.3.0] - 2026-08-23

### Added

- **A standalone catalogue page.** The picker only exists inside a document
  being edited, so there was nowhere to simply look at the range. **Products |
  Services → Catalog** opens a full-page browser: click through category
  folders, or search across product text and category names.

  It shares `ajax/catalog.php` with the picker, so navigation, search and
  favourites behave identically in both. The presentation differs on purpose —
  the picker is a dense table for selecting quickly, this is a card grid with
  thumbnails, prices and stock for looking around. References link to the
  product card.

  Paging replaces the picker's "more results exist" notice, using one row beyond
  the page to detect a next page rather than a second COUNT over the same
  filters.

### Fixed

- **A scoped search could advertise folders it then showed nothing for.** When
  searching inside a category, products were filtered to that branch but the
  matching category folders were not, so a search could offer "Docking stations,
  2 items" above an empty product list. Matched categories now respect the same
  scope.

- **Asset cache-busting on the browse page** keyed the query string to
  `MAIN_MODULE_DOLICATALOG_VERSION`, a constant Dolibarr never writes, so the URL
  was pinned to the fallback value permanently and browsers kept serving stale
  JavaScript after a deploy. Both script tags now use the asset's modification
  time, which changes exactly when the file does.

### Changed

- On the catalogue page, typing in the search box leaves the current folder and
  searches everything. The page exists for people who do not know where a thing
  lives; a search that silently excluded everything outside the folder they
  happened to be standing in would defeat the purpose. The in-document picker
  keeps its scoped search, where narrowing is usually deliberate.

## [1.2.0] - 2026-08-23

### Added

- **Link to the product card from a picker row.** An external-link icon sits to
  the right of each reference and opens that product's card. It opens in a new
  tab deliberately: the picker sits inside a half-written quote or order, and
  navigating away in the current tab would discard whatever the user had already
  entered.

  Clicking the icon does not touch the row's selection, so checking an item and
  then inspecting it are independent actions.

## [1.1.0] - 2026-08-23

### Added

- **Search matches category names.** Typing a category name now returns the
  products inside it, and inside everything beneath it. Previously the search
  looked only at a product's own reference, label, description and barcode, so
  someone who knew the catalogue by its shape rather than by product names had
  no way in — searching "docking" found nothing when the products were called
  "USB-C Dock 180W" and "Thunderbolt Dock".

  Matching a parent reaches the whole branch: "Hardware" returns everything
  under Hardware regardless of depth.

- **A matching category is offered as a folder.** When the term matches a
  category, it appears above the product hits as a folder card with its item
  count, so a search doubles as a shortcut into that branch.

- **Search results show where each item lives.** Hits now carry their category
  path — `Hardware / Accessories / Docking stations` — so a result is not a bare
  row with no context. The path is also shown on favourites and recents, which
  have the same problem. Browsing still relies on the breadcrumb, since position
  is already obvious there.

  This is aimed at people learning an unfamiliar catalogue: they search the word
  they know, see where it sits, and start browsing straight to the right branch.

### Notes

- Category paths are assembled from one flat query per request, so showing them
  costs nothing per row.
- Scoping is unchanged: searching inside a category still stays within that
  branch.

## [1.0.1] - 2026-08-22

### Fixed

- **Category browsing showed nothing.** The category queries carried an
  `AND visible = 1` filter that Dolibarr's own tree query
  (`Categorie::get_full_arbo()`) does not use. `Categorie::create()` inserts
  `visible` from an uninitialised property, so it lands as `''` and is coerced
  to `0` in a `NOT NULL tinyint` column — meaning categories created through the
  Dolibarr interface are stored with `visible = 0` and were all filtered out.
  Search was unaffected, which is why it kept working while browsing came back
  empty. The filter has been removed, matching core behaviour.

- **Warehouse dropdown opened behind the modal.** `FormProduct::selectWarehouses()`
  wraps the field in select2, which appends its dropdown panel to `<body>`, below
  the picker overlay's stacking context. The filter now renders as a plain
  `<select>` (`forcecombo = 1`), which the browser draws above page content. A
  scoped `z-index` guard covers any other enhanced control that reaches the modal.

## [1.0.0] - 2026-08-22

First release.

### Added

- Visual, tree-based picker for products and services, opened from a **Browse
  catalog** button on the line entry form of supported documents.
- Category browsing over native Dolibarr product categories, to unlimited depth,
  with folder colours, a per-folder item count and a clickable breadcrumb.
- Search across reference, label, description and barcode. Searching from inside
  a category stays scoped to that branch, subcategories included.
- Multi-select with a per-item quantity, and batch insertion of every selected
  item in a single action.
- Support for seven document types:
  customer proposal, sales order, customer invoice, supplier proposal,
  purchase order, supplier invoice and bill of materials.
- Pricing that mirrors the native card pages rather than a flat product price:
  per-customer prices, price levels (multiprice), price-by-quantity tiers, and
  VAT resolved through `get_default_tva()` / `get_default_npr()` with local taxes.
  Supplier documents resolve the supplier's own price and reference through
  `ProductFournisseur::find_min_price_product_fournisseur()`.
- Per-user favourites and a per-user recently-used list.
- Filters for item type (product / service), warehouse, and — on supplier
  documents — *this supplier only*. Choosing a warehouse switches the stock
  column to that warehouse's quantity.
- Thumbnails, prices (excl. and incl. tax), stock levels and service durations
  in the result rows.
- Administration page with per-entity settings for display columns, favourites
  and history, result limits, default quantity, trigger icon, and which
  categories act as roots.
- Admin diagnostic endpoint at `ajax/debug.php`, gated behind a debug-mode
  setting, reporting module status, hook registration per document context,
  table health, the category tree with counts, and per-type permissions.
- English and French translations.

### Notes

- Bills of materials apply no `tosell` / `tobuy` filter, because a manufactured
  sub-assembly is often flagged for neither, and filtering would hide the very
  components a BOM is made of.
- The picker enforces native permissions only. It can never let a user add lines
  to a document they could not already edit, and it appears solely on drafts.
- No Dolibarr core file is modified and no core table is written to directly;
  lines are always created through each document class's own `addline()`.

[1.3.1]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.3.1
[1.3.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.3.0
[1.2.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.2.0
[1.1.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.1.0
[1.0.1]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.0.1
[1.0.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.0.0
