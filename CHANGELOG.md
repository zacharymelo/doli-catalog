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

## [1.8.3] - 2026-08-24

### Documentation

- **Settings tables were four short and one wrong.** Archived category, Maximum
  tag filters, Attribute roots and the product list filter were undocumented,
  and Root categories was still described as "comma-separated category ids"
  after it became a picker.
- **Upgrade guidance was out of date**, still warning that disabling the module
  discards its settings. That stopped being true in 1.7.2; the note now says so
  and flags the one-time loss for anyone coming from an earlier build.
- Added sections on **archived products** and on **working alongside other
  modules**, the latter covering why card-page hooks do not see lines added by
  the picker and how the Fixed Price integration handles it.
- `docs/ARCHITECTURE.md` refreshed: the file tree omitted the catalogue page,
  the list strip and three of the four scripts, and it still described hooks as
  the only entry point. Added the shared filter builder, facets, archived
  products, thumbnail selection and card-page interoperation, plus two gotchas
  worth not rediscovering — the `$mc` MultiCompany global, and the
  `deleteonunactive` flag.

## [1.8.2] - 2026-08-24

### Fixed

- **Catalogue cards showed the smallest thumbnail.** Dolibarr generates two
  thumbnails, `_mini` and `_small`, and the module took whichever sorted first
  by filename — always `_mini`. Measured on a 1200x800 photo that is 108x72,
  stretched to fill a 220x132 card, which is where the pixelation came from.

  Thumbnails are now chosen by the size they will be displayed at. The catalogue
  page uses `_small` (405x270 on the same photo); the picker keeps `_mini`,
  which is correct behind its 38px row thumbnails and avoids pulling larger
  files for no benefit.

- **Cards now load progressively.** The card paints `_mini` immediately and
  swaps in `_small` once it has decoded, softened slightly until then so the
  stand-in reads as loading rather than as a poor image. The grid fills at once
  instead of staying empty while the larger files arrive.

  On a display with no generated thumbnails the original is used for both, and
  where only one size exists it is used for both, so nothing renders blank.

## [1.8.1] - 2026-08-24

### Fixed

- **A pinned currency price left the line internally inconsistent.** 1.8.0 set
  the foreign-currency price from the pin but left the base-currency price as
  the product's own. The two then disagreed: a line pinned at 1999 USD on a
  document at rate 1.25 stored 1450 EUR beside it, implying a rate of 1.3786.
  Every base-currency figure downstream — line total, margin, accounting —
  described a sale that was not the one being made, drifting by 149.20 EUR per
  unit in that example.

  The pinned amount is the truth and the base-currency price is now derived back
  from it at the document rate, which is what the Fixed Price module's own
  back-calculation was preserving. The pinned figure is still passed explicitly
  as well, so rounding the derived base price cannot move the agreed amount.

  Applied to proposals, orders and invoices alike. 1.8.0 should not be used
  where fixed prices are configured.

## [1.8.0] - 2026-08-24

### Fixed

- **Fixed multicurrency prices were ignored for anything added from the
  catalogue.** The Fixed Price module pins a selling price per currency by
  injecting into `$_POST` from a `doActions` hook on the proposal, order and
  invoice card pages. The picker adds lines from its own endpoint and never
  loads a card page, so that hook never fired and pinned prices simply did not
  apply — lines silently fell back to the exchange-rate conversion.

  The line adder now looks the pinned price up itself and passes it as the
  line's foreign-currency unit price. Verified across proposals, orders and
  invoices.

  The lookup runs only when that module is enabled and the document is in a
  foreign currency, and reads its table directly, so nothing here depends on it
  being installed. With it disabled, lines convert at the document rate exactly
  as before.

  Orders get the pinned price directly rather than the back-calculated base
  price that module derives for them. That back-calculation exists because the
  order *card page* has no foreign-price branch; calling `addline()` directly
  has no such limit, so the pinned figure is used as-is.

- **`$mc` was shadowed in the catalog endpoint.** `ajax/catalog.php` runs at
  global scope and used `$mc` as a loop variable, which is the name Dolibarr
  keeps the MultiCompany instance under. `getEntity()` checks `is_object($mc)`,
  so on a MultiCompany install the loop silently pushed it onto the
  non-MultiCompany path and returned the wrong entity list.

## [1.7.3] - 2026-08-24

### Fixed

- **Setup panel help text.** Several descriptions no longer matched the module.
  Root categories and Attribute roots still asked for "comma separated category
  ids" after both became pickers. Maximum tag filters still said a low value
  "silently hides" tags, which stopped being true once the filter began
  reporting how many it cut. Archived category did not mention that the category
  itself is hidden, not only its products.

  The rest were inconsistent rather than wrong: bounded numeric settings stated
  their range in some rows and not others, and only one description carried a
  "When enabled," preamble. Every description now follows the same shape, and
  each bounded setting states its range.

- **Half-translated French.** Newer settings were left in English in the French
  file, and its Root categories text carried the same stale "comma separated
  ids" claim. All setup strings are now translated.

## [1.7.2] - 2026-08-24

### Fixed

- **Settings no longer vanish when the module is disabled.** Every constant was
  declared with Dolibarr's `deleteonunactive` flag set, so `delete_const()`
  dropped the lot whenever the module was deactivated. Because several changes
  here need a disable/enable cycle to take effect — menus and hook contexts are
  only written at activation — a routine upgrade wiped the configuration.

  All constants are now flagged to persist. A constant that is genuinely absent
  is still seeded with its declared default on activation, and an existing value
  is never overwritten by that seeding.

## [1.7.1] - 2026-08-24

### Fixed

- **The archived category itself still appeared in the catalogue**, even though
  its products were hidden. Opening it showed an empty folder, which reads as a
  bug rather than as a policy.

  The archived category and everything beneath it are now excluded from the root
  folders, from subfolder listings and from search results, on the same
  condition as the products. **Show archived** brings the folder back along with
  its contents.

  Two places deliberately keep listing it: the setup picker, or the setting
  could never be configured in the first place, and the product list filter
  strip, because that strip filters Dolibarr's own list, which shows archived
  products like anything else — hiding the folder there would leave the strip
  disagreeing with the list underneath it.

## [1.7.0] - 2026-08-24

### Added

- **Archived category setting.** Pick a category in setup and any product in it,
  or in any category beneath it, is treated as withdrawn: hidden from the
  catalogue page, the picker, search, favourites and recents. Folder counts
  exclude them too, so a folder never advertises items the catalogue then
  refuses to show.

  A **Show archived** checkbox on the catalogue page brings them back when
  needed. It only appears once a category has actually been configured.

  The exclusion lives in the shared product filter rather than at each call
  site, so it applies everywhere by construction instead of wherever someone
  remembered to add it.

### Changed

- **All three tag-driven settings now use category pickers** instead of asking
  for ids. Nobody knows their category ids, and a mistyped one fails silently by
  matching nothing. Root categories and attribute roots are multi-selects;
  archived is a single select.

### Fixed

- **Rarely used tags were missing from the tag filter.** The filter returned the
  top 40 tags by product count, so on a catalogue with more than 40 distinct
  tags in scope the tail was dropped — and because the cut is by rank, whatever
  count happened to sit at the boundary looked like a minimum threshold. Two
  tags with identical counts could even land on opposite sides of it.

  The cap is now configurable (**Maximum tag filters**, default 200, was 40),
  and when it does bite the filter says so with a "+N more not shown" note
  rather than quietly omitting options.

## [1.6.0] - 2026-08-24

### Added

- **Tags group under the attribute that names them.** Dolibarr categories double
  as tags, but a flat list cannot say what a value *means* — "Brass" and
  "1/4 in." look like the same kind of thing. Nesting values under a category
  that names the attribute supplies that meaning, and the refine panel now reads
  the tree to group them:

  ```
  Thread Type   [BSP] [NPT]
  Thread Size   [1/8 in.] [1/4 in.] [1/2 in.]
  Material      [Black Iron] [Brass]
  ```

  A new setting, **Attribute roots**, names the categories whose children are
  attribute names. A value's group is the ancestor whose parent is such a root —
  not its immediate parent, which differs as soon as an attribute has depth of
  its own. Categories outside those roots keep working as loose tags, and
  leaving the setting empty preserves the previous flat list.

  Display order is Dolibarr's own category Position on both the attribute and
  its values; nothing new is introduced. That matters for real values, since
  alphabetically "1/2 in." sorts before "1/4 in." before "1/8 in.".

- **An All / Any switch per attribute.** Attributes always narrow each other, but
  within one attribute both readings are useful once a product can carry two
  values: a reducer tagged `1/4 in.` and `1/2 in.` means selecting both sizes can
  sensibly return just that reducer, or everything in either size.

  The switch defaults to **All** and appears only once two of that attribute's
  values are selected, since with one value the two readings describe the same
  set. It is per attribute, so Thread Size can widen while Material keeps
  narrowing.

  Counts needed care. They normally reflect every active filter, so the number
  beside a value is what remains after adding it — but that is wrong for an
  attribute set to Any: counting its values against its own selection leaves
  every unselected one at zero, they drop out as dead ends, and a third value
  could never be added. Those are recounted with only that attribute's selection
  lifted.

- **The refine panel folds away.** Its heading is now a control. The choice is
  remembered between page loads, because filtering reloads the list and a panel
  that unfolds on every click is not folded away in any useful sense. Collapsed,
  the heading still reports how many filters are active, so a narrowed list is
  never mistaken for a small catalogue.

### Fixed

- **`build.sh` produced nothing for a suffixed version.** Its version pattern
  matched only digits and dots, and under `set -e` the non-matching `grep`
  aborted before the script's own check could report why — a silent no-op that
  looked like success.

- **Pre-release zips could not be uploaded.** Dolibarr validates the package
  filename and derives the module name by stripping the version, so the segment
  before `.zip` must be digits and dots only; a `-dev` suffix was refused
  outright. The zip is now named with the numeric part while the descriptor
  keeps the full version, and the build says when it has reduced one.

- **`MAX_TREE_DEPTH` was undefined in `DoliCatalogBrowser`.** The depth guard was
  a bare `64` repeated across three tree walks; a fourth referenced a constant
  that existed only in the sibling module. Now defined once and used in all four.

## [1.5.2] - 2026-08-24

### Changed

- **A tag is no longer offered when it is already a folder on screen.** Browsing
  a category that lists items filed in both it and one of its subcategories
  showed that subcategory twice: once as a folder to open, once as a tag to
  filter by. Filtering by it does the same thing as opening it, so the chip was
  pure clutter.

  The endpoint now tells `getFacets()` which folders it is drawing, since only
  it knows which survived its own hide-empty and search rules. Genuinely
  cross-cutting tags are unaffected.

## [1.5.1] - 2026-08-24

### Changed

- **The tag row moved below the Categories section** on the catalogue page,
  giving the order: categories, then tags, then items. It previously sat above
  the categories, which read as though the tags filtered the folders as well as
  the items — they only ever filtered the items.

  The chips are now built as part of the results flow rather than into a fixed
  container above it, so their position follows from the render order instead of
  from page markup.

## [1.5.0] - 2026-08-24

### Added

- **Tag filtering inside a category on the catalogue page.** Dolibarr categories
  double as tags, so a product in a broad category usually also carries
  cross-cutting ones — a range, a fitting, a status. Opening a category now
  shows those tags as chips with counts, and selecting them narrows the list
  without leaving the category.

  **Tags narrow together.** Picking two returns the products carrying *both*,
  not either. Tags also AND with the search box and the type filter.

  Counts reflect the current selection, so the number beside a tag is exactly
  what you are left with after adding it — a tag that would empty the list drops
  out rather than sitting there as a trap. Selected tags always stay visible so
  they can be switched off, even if they would otherwise fall outside the list.

  Tags belonging to the category you are already in are not offered, since the
  folders are the navigation and filtering by them would do nothing. The
  selection resets when you change category, search, or switch to
  favourites/recents, because it describes a set that no longer exists.

### Changed

- `listProducts()` and the new `getFacets()` now share one filter builder. Facet
  counts are only trustworthy if they describe exactly the set the listing
  shows, and two hand-maintained copies of the same conditions would eventually
  disagree. The supplier restriction moved from a JOIN to an `EXISTS` as part of
  this, so the filtering condition can be reused without dragging the price
  column with it.

## [1.4.1] - 2026-08-23

### Changed

- **The product list category strip now sits above the table**, between the
  title/pagination bar and the filter well, instead of inside the well.
  Previously it rendered among the column filters, which read as one more filter
  control rather than as navigation over the list.

  `printFieldPreListTitle` is the only hook the list offers before the table and
  its output is rendered into that well by design; there is no hook in the gap
  between `print_barre_liste()` and it. The strip is therefore moved into place
  on load. It stays inside the search form either way — everything from the
  title bar to the table is within it — so submitting still carries the user's
  other filters.

  It is styled as its own band now that it no longer inherits the well's
  framing. The well itself is left alone when it still holds the native
  Categories control, and removed only if the strip was its only content.

## [1.4.0] - 2026-08-23

### Added

- **Category filter strip on the native product list**, behind a new setting
  (`Category filter on product list`, default **off**). It renders above the
  list's filter row: a breadcrumb, the categories at the current level with
  product counts, and a clear link.

  **This fixes a limitation in Dolibarr itself.** The stock category filter
  matches the chosen category exactly, so filtering by a parent shows only
  products linked directly to it — on a tree of any depth that is usually
  nothing at all. The strip submits the chosen category *and every category
  beneath it* using the filter's OR operator, so filtering by a parent finally
  includes its subtree.

  It drives the native filter rather than injecting SQL, so sorting, column
  choice, pagination, mass actions and export are untouched. The selection also
  appears in the list's own Categories control, so what is applied stays visible
  and editable there.

  > Verified against a three-level tree: filtering by the top category with the
  > stock filter returns nothing while six products sit beneath it; the strip
  > returns all six, including items three levels down.

### Changed

- `DoliCatalogBrowser` gained an `all` mode that applies no sale or purchase
  flag. The product list shows everything regardless of those flags, so counting
  only sellable items would have advertised numbers the list then contradicted.

## [1.3.2] - 2026-08-23

### Changed

- **The Catalog menu entry now sits inside the Products group** — alongside New
  product, List, Stocks and Statistics — instead of standing as its own section
  header next to Products and Services. It is one more way to look at products,
  not a peer of the whole product area. The prefix icon was dropped for the same
  reason: its siblings in that group do not carry one.

  > Requires disabling and re-enabling the module: menu rows are written to
  > `llx_menu` at activation, so the old placement persists until then.

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

[1.8.3]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.8.3
[1.8.2]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.8.2
[1.8.1]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.8.1
[1.8.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.8.0
[1.7.3]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.7.3
[1.7.2]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.7.2
[1.7.1]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.7.1
[1.7.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.7.0
[1.6.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.6.0
[1.5.2]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.5.2
[1.5.1]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.5.1
[1.5.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.5.0
[1.4.1]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.4.1
[1.4.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.4.0
[1.3.2]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.3.2
[1.3.1]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.3.1
[1.3.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.3.0
[1.2.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.2.0
[1.1.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.1.0
[1.0.1]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.0.1
[1.0.0]: https://github.com/zacharymelo/doli-catalog/releases/tag/v1.0.0
