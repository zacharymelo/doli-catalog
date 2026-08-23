# Doli Catalog

A visual, tree-based product and service picker for Dolibarr.

Dolibarr's native line entry asks you to know what you want before you start typing — you get a single autocomplete box and no way to look around. Doli Catalog adds a **Browse catalog** button that opens the product tree, lets you search and filter it, tick several items with quantities, and drop them all into the document in one action.

It reads native tables only. No core file is modified.

---

## Contents

- [What it does](#what-it-does)
- [Where it appears](#where-it-appears)
- [How items are priced](#how-items-are-priced)
- [Requirements](#requirements)
- [Install](#install)
- [Configuration](#configuration)
- [Permissions](#permissions)
- [Troubleshooting](#troubleshooting)
- [Local development](#local-development)
- [For maintainers](#for-maintainers)

---

## What it does

**Browse.** Unlimited-depth category trees taken straight from native Dolibarr product categories. Folders show their configured colour and a count of everything beneath them, subcategories included. A breadcrumb tracks where you are and jumps back to any level.

**Search.** One box covering reference, label, description, barcode **and category name**. Typing a category name returns what is inside it to any depth, so somebody who knows the catalog by shape rather than by product name can still find things — searching "docking" finds the docks even though neither product is called that. Naming a parent reaches its whole branch. A matching category is offered as a folder alongside the hits, and every result shows its category path (`Hardware / Accessories / Docking stations`) so a hit is never a bare row with no context. Search from the root and it covers the whole catalog; search from inside a category and it stays within that branch and its subcategories, so a common term does not drown you in unrelated hits.

**Filter.** Products vs services. Warehouse — which also switches the stock column from the all-warehouse total to that warehouse's quantity. On purchase documents, an optional *This supplier only* toggle.

**Pick.** Tick as many items as you like, set a quantity on each, review them as chips in the footer, then add them all in one action. Typing a quantity selects the item, so the common case is a single keystroke.

**Remember.** Star items into per-user favourites. Recently picked items are tracked per user and surfaced in their own tab.

---

## Category filter on the product list

Optional, off by default. Enable **Category filter on product list** in setup to
add a category strip above the native product list.

Dolibarr's built-in category filter matches the chosen category exactly, so
filtering by a parent shows only what is linked directly to it — often nothing.
The strip submits the category *and its whole subtree*, so filtering by a parent
includes everything beneath it.

It drives the native filter rather than replacing it, so sorting, columns,
pagination, mass actions and export keep working, and the selection stays
visible in the list's own Categories control.

## The catalogue page

**Products | Services → Products → Catalog** is a standalone browser for the range: click
through category folders, or search across product text and category names.
Results show each item's full category path, and references link to the product
card. Use it to explore the catalogue without starting a document — useful when
onboarding staff who know the categories but not the product codes.

Searching from this page always searches the whole catalogue, even while inside
a folder.

## Where it appears

| Document | Hook context | Catalog mode |
|---|---|---|
| Customer proposal | `propalcard` | sell |
| Sales order | `ordercard` | sell |
| Customer invoice | `invoicecard` | sell |
| Supplier proposal | `supplier_proposalcard` | buy |
| Purchase order | `ordersuppliercard` | buy |
| Supplier invoice | `invoicesuppliercard` | buy |
| Bill of materials | `bomcard` | bom |

The button appears **only on draft documents**. That is not a policy choice — Dolibarr only renders the add-line form (and the hooks attached to it) while lines are still editable, and the classes themselves refuse `addline()` on a validated document.

The three catalog modes differ in what they show and what price they display:

| Mode | Items shown | Price column |
|---|---|---|
| `sell` | flagged *for sale* | customer price (see below) |
| `buy` | flagged *for purchase* | best supplier price, else cost price |
| `bom` | **no flag filter** | cost price |

`bom` deliberately applies no flag filter. A manufactured sub-assembly is frequently neither sold nor purchased, and filtering on those flags would hide exactly the components a bill of materials is made of.

---

## How items are priced

Lines are priced the way the native card pages price them, not with a flat `product.price`.

**Customer documents** resolve in this order, first match wins:

1. Per-customer price (`llx_product_customer_price`), honouring its validity dates
2. Price level / multiprice, when the customer is assigned a level
3. Price-by-quantity tier matching the quantity entered
4. The product's base price

VAT then resolves through `get_default_tva()` and `get_default_npr()`, plus local taxes 1 and 2 via `get_localtax()`.

**Supplier documents** use `ProductFournisseur::find_min_price_product_fournisseur()` for the supplier's unit price, discount and reference, falling back to the product's cost price when the supplier has no price recorded. Purchase VAT resolves for the buyer/seller pair, which is the reverse of a sale.

**Bills of materials** carry no price at all. `BOM::addLine()` takes a component and a quantity; the cost shown in the picker is informational.

---

## Requirements

- Dolibarr **19.0+** (developed and verified against 23.0)
- PHP **7.3+**
- Native **Products** (or Services) and **Categories** modules enabled
- For BOM support, the **BOM** module enabled
- MySQL / MariaDB / PostgreSQL

---

## Install

Build the installable zip:

```bash
./build.sh
```

Then in Dolibarr: **Home → Setup → Modules → Deploy external module**, upload `dolicatalog-<version>.zip`, enable **Doli Catalog**, and open its setup page.

> The zip must have `dolicatalog/` at its top level, not `module/`. `build.sh` handles this; Dolibarr rejects the package otherwise.

### Upgrading

Default settings are written when the module is **activated**. If a new version adds a setting, disable and re-enable the module once so the new defaults are stored. Note that disabling removes the module's stored settings — this is standard Dolibarr behaviour for any module that declares defaults, so record any customised values first.

---

## Configuration

**Home → Setup → Modules → Doli Catalog → Setup**

### Display

| Setting | Default | Effect |
|---|---|---|
| Show thumbnails | on | First product photo per row. Turn off on very large catalogs to avoid a directory scan per row. |
| Show stock | on | Stock column plus the warehouse filter |
| Show price including tax | off | Second price column. Ignored in `buy` and `bom` modes, where no meaningful tax-inclusive price exists. |
| Show service duration | on | Duration column for items of type Service |
| Hide empty categories | off | Suppress folders whose count is zero |
| Trigger button icon | `fa-th-large` | Font Awesome class on the button |

### Behaviour

| Setting | Default | Effect |
|---|---|---|
| Enable favourites | on | Per-user starring |
| Enable recently used | on | Per-user pick history |
| Maximum rows | 50 | Rows per query before the list is truncated (1–500) |
| Recent items kept | 12 | History depth per user |
| Default quantity | 1 | Pre-filled quantity on selection |
| Root categories | empty | Comma-separated category ids to show at the top level. Empty means every top-level category. |
| Debug mode | off | Exposes `ajax/debug.php` |

Numeric settings are clamped on save, and the setup page writes only its own allowlisted constants.

---

## Permissions

Doli Catalog never widens what a user can already do. Before rendering the button it checks all three of:

1. `dolicatalog → picker → use` — the module's own switch, granted by default
2. `produit → lire` (or `service → lire`) — can this user see the catalog at all
3. The **target document's own create right** — e.g. `commande → creer`, `bom → write`

The add-lines endpoint re-checks the same rights server-side, so a crafted request cannot bypass the UI. It is POST-only, CSRF-checked, refuses non-draft documents, and caps a batch at 200 items.

To take the picker away from a user or group without touching anything else, remove permission 1.

---

## Troubleshooting

Enable **Debug mode**, then as an admin open:

```
/custom/dolicatalog/ajax/debug.php?mode=all
```

| Mode | Reports |
|---|---|
| `overview` | Module status, hook registration per context, table health, asset presence |
| `catalog` | The category tree with product counts, and total category/product link counts |
| `doctypes` | The seven supported types and the caller's rights on each |
| `settings` | Every stored `DOLICATALOG_*` constant |
| `classes` | Class loading and method availability |
| `sql` | Read-only diagnostic queries: `?mode=sql&q=SELECT...` |
| `all` | Everything except `sql` |

### The button does not appear

Work down this list:

1. Is the document a **draft**? The button never renders on a validated document.
2. Does `?mode=overview` show the relevant context as `OK` under **HOOK REGISTRATION**? If it says `NOT REGISTERED`, disable and re-enable the module — hook contexts are written to `MAIN_MODULE_DOLICATALOG_HOOKS` at activation.
3. Does `?mode=doctypes` show `CAN CREATE = YES` for that document type?
4. Are Products and Categories both enabled?

### The catalog is empty

Check `?mode=catalog`. If there are no root categories, either no *product* categories exist, they are all marked not visible, or **Root categories** is set to ids that do not exist.

### An item is missing from the list

In `sell` mode it must be flagged for sale; in `buy` mode, for purchase. On a purchase document, also check the *This supplier only* toggle — unticking it shows every purchasable item, including those with no price recorded for this supplier.

---

## Local development

```bash
docker compose up -d
```

Dolibarr comes up on <http://localhost:8091> (admin / admin) with `./module` mounted at `custom/dolicatalog`, so edits are live without a rebuild.

```bash
docker compose down -v     # tear down, including the database
```

---

## For maintainers

See **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** for how the module attaches to Dolibarr, why each hook was chosen, and a step-by-step guide to adding an eighth document type.

## License

GPL-3.0-or-later.
