# Changelog

All notable changes to Doli Catalog are documented here.

This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.0.0
