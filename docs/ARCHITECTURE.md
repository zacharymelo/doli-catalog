# Architecture

How Doli Catalog attaches to Dolibarr, why each attachment point was chosen, and how to extend it.

Read this before changing how the picker injects itself or adding a document type. Several of the choices below look arbitrary until you know what the alternative broke.

---

## Shape of the module

Doli Catalog has no business object of its own. There is no card page, no list page, no numbering module, no trigger. It is three things:

1. **A hook class** that injects a button and a modal shell into pages Dolibarr already renders.
2. **Three AJAX endpoints** that feed the modal and write the result back.
3. **Two small tables** holding per-user favourites and pick history.

Everything the picker displays is read from native tables (`llx_categorie`, `llx_categorie_product`, `llx_product`, `llx_product_stock`, `llx_product_fournisseur_price`). Nothing in core is modified, and no core table is written to — lines are always created by calling the document class's own `addline()`.

```
module/
  core/modules/modDoliCatalog.class.php   module descriptor: hooks, rights, settings, tables
  class/
    actions_dolicatalog.class.php         hook handlers — the only entry point from Dolibarr
    dolicatalogbrowser.class.php          read-only catalog queries (tree, search, favourites)
    dolicataloglineadder.class.php        price resolution + per-type addline() adapters
  ajax/
    catalog.php                           GET  — tree, search, favourites, recent
    addlines.php                          POST — append picked items as document lines
    favorite.php                          POST — toggle a favourite
    debug.php                             admin diagnostics, gated by DOLICATALOG_DEBUG_MODE
  js/dolicatalog.js                       the modal, built with plain DOM APIs
  css/dolicatalog.css                     scoped under .dolicatalog-*
  lib/dolicatalog.lib.php                 admin page helpers + version lookup
```

---

## How the button gets onto the page

This is the part with the sharp edges. **Two different hooks are used, because Dolibarr renders line entry two different ways.**

### Commercial documents — `formCreateProductOptions`

Proposals, orders, invoices and their supplier equivalents all render their add-line form from the shared template `core/tpl/objectline_create.tpl.php`. That template fires two hooks near the product selector:

- `formCreateProductOptions` — customer-facing documents
- `formCreateProductSupplierOptions` — supplier-facing documents (the template picks by `$senderissupplier`)

Both are "output" hooks. The template prints `$hookmanager->resPrint` itself, so the handler returns markup in `$this->resprints` and returns `0`.

This is the right anchor because the template only renders that form while lines are editable — which is precisely when the picker should be available. No separate draft check is needed for these types.

### Bills of materials — `formAddObjectLine`

BOM does **not** use the shared template. It ships its own set under `bom/tpl/`, and its `objectline_create.tpl.php` fires **no hooks at all**. The only usable anchor is in `bom/bom_card.php`:

```php
$reshook = $hookmanager->executeHooks('formAddObjectLine', $parameters, $object, $action);
if ($reshook < 0) { setEventMessages(...); }
if (empty($reshook)) {
    $object->formAddObjectLine(1, $mysoc, null, '/bom/tpl');
}
```

Two consequences, both easy to get wrong:

**1. The return value must be `0`.** The native add-line form runs only when `empty($reshook)`. Returning `1` — the usual "I handled it" value — silently deletes BOM's own line entry form. `ActionsDoliCatalog::formAddObjectLine()` always returns `0`.

**2. Output must be printed, not returned.** Unlike the commercial card pages, `bom_card.php` never prints `$hookmanager->resPrint` for this hook. Markup left in `$this->resprints` vanishes without an error. The handler therefore `print`s directly.

**3. The markup must be a table row.** The hook fires inside `<table id="tablelines">`, so loose `<div>`/`<button>` markup is invalid there. `renderPicker()` takes a `$wrap` argument; BOM passes `'tablerow'`, which wraps the output in `<tr><td colspan="12">`.

### Why not a card-level hook?

`formObjectOptions` and `addMoreActionsButtons` are available on every card and would have been one code path instead of two. They were rejected because they render in the object's detail table or the action bar — far from the lines the user is editing, and visible on validated documents where the picker cannot work. Anchoring to the add-line form ties the button's lifetime to the exact condition that makes it usable.

---

## Catalog modes

`DoliCatalogBrowser::normaliseMode()` collapses input to one of three modes. The mode decides both which items are visible and which price is shown.

| Mode | Flag filter | Price shown |
|---|---|---|
| `sell` | `tosell = 1` | customer price (per-customer → level → by-qty → base) |
| `buy` | `tobuy = 1` | best supplier unit price, else `cost_price` |
| `bom` | **none** | `cost_price` |

`bom` applying no flag filter is deliberate. A manufactured sub-assembly is routinely flagged neither for sale nor for purchase; filtering on those flags would hide the very components a bill of materials consists of.

In `buy` mode the supplier price comes from a single grouped subquery joined onto the product table, not a per-row lookup:

```sql
LEFT JOIN (SELECT fk_product, MIN(unitprice) AS buyprice
           FROM llx_product_fournisseur_price
           WHERE entity IN (...) [AND fk_soc = :supplier]
           GROUP BY fk_product) AS pfpmin ON pfpmin.fk_product = p.rowid
```

The join becomes an `INNER JOIN` when the *This supplier only* filter is on. That filter is a user choice rather than an automatic restriction: applying it silently hid purchasable products that simply had no price recorded for the current supplier.

Whenever the supplier filter is applied to the listing it is **also** applied to `countProductsInCategory()`. Otherwise a folder advertises "12 items", opens, and shows four.

---

## Tree traversal

Categories are walked iteratively, never with a recursive CTE, so the module works on MySQL, MariaDB and PostgreSQL alike:

- `getDescendantIds()` does a breadth-first descent, one query per level, capped at 64 levels.
- `getBreadcrumb()` walks `fk_parent` upward with a `seen` set, so a cycle introduced by bad data terminates instead of hanging.

Both guards exist because `llx_categorie` has no constraint preventing a cycle.

---

## Pricing

`DoliCatalogLineAdder::resolveCustomerPrice()` mirrors the precedence in `commande/card.php`'s `addline` handler: per-customer price (respecting validity dates), then price level, then price-by-quantity tier, then base price; VAT via `get_default_tva()` / `get_default_npr()`, local taxes via `get_localtax()`.

`resolveSupplierPrice()` uses `ProductFournisseur::find_min_price_product_fournisseur()` and resolves purchase VAT for the **reversed** thirdparty pair — the supplier is the seller.

BOM lines have no price at all; `basePricing()` is passed through only to keep the call uniform, and `BOM::addLine()` ignores it.

> If a future Dolibarr release changes this precedence, this is the code that drifts. It duplicates core logic because core exposes no reusable helper for it.

---

## The `addline()` adapters

**Every supported class has a different positional signature, and the differences are not merely in argument count.** `FactureFournisseur::addline()` takes `$qty` as its *sixth* argument, where `Commande::addline()` takes it third. `BOM::addLine()` takes no price arguments whatsoever.

For that reason `callAddline()` is an explicit `switch` with one hand-written call per type, each preceded by a comment naming the parameters in order. There is deliberately no generic argument-array dispatch: it would silently pass a quantity as a VAT rate the first time a signature shifted.

When touching this code, verify the signature against the running Dolibarr rather than from memory:

```bash
docker exec <container> sh -c "grep -n 'public function addline' /var/www/html/commande/class/commande.class.php"
```

---

## Adding a document type

1. **Confirm the anchor.** Does the card page render lines via `core/tpl/objectline_create.tpl.php`? If yes, the existing `formCreateProductOptions` / `formCreateProductSupplierOptions` handler covers it. If it ships its own templates, find which hook its card page fires, and check whether the caller prints `resPrint` and how it treats a non-zero return.

2. **Register the hook context** in `modDoliCatalog::__construct()` under `module_parts['hooks']['data']`.

3. **Add an entry to `DoliCatalogLineAdder::$TYPES`**, keyed by the class's `$element` value:

   ```php
   'mytype' => array(
       'class'   => 'MyClass',
       'path'    => '/mymodule/class/myclass.class.php',
       'mode'    => 'sell',                      // sell | buy | bom
       'perm'    => array('mymodule', 'creer', ''),  // User::hasRight() triplet
       'context' => 'mytypecard',
   ),
   ```

4. **Add a `case` to `callAddline()`**, with the signature read out of the class file and written into a comment above the call.

5. **Add the context** to `$HOOK_CONTEXTS` in `ajax/debug.php`.

6. **Disable and re-enable the module.** Hook contexts are written to `MAIN_MODULE_DOLICATALOG_HOOKS` at activation; a new context is invisible until then. This is the single most common reason a newly added type appears not to work.

7. **Verify** with `ajax/debug.php?mode=doctypes` and `?mode=overview`.

---

## Security model

The picker never widens what a user can already do.

| Layer | Check |
|---|---|
| Render | `dolicatalog→picker→use`, `produit→lire`, and the document's own create right |
| `catalog.php` | Module enabled, authenticated, same three rights |
| `addlines.php` | POST only, CSRF via `main.inc.php`, rights re-checked, draft-only, 200-item cap |
| `favorite.php` | POST only, CSRF, rights |
| `debug.php` | `$user->admin` **and** `DOLICATALOG_DEBUG_MODE`; `sql` mode is SELECT-only with a keyword blocklist |
| `admin/setup.php` | Writes only allowlisted constants; numerics clamped |

`isDraft()` checks `$status` before the legacy `$statut`, because BOM defines only the former and the commercial classes define both. It returns `false` when neither is readable — refusing rather than risking a write to a validated document.

Every catalog query is entity-scoped with `getEntity()`, so the module is multi-entity safe.

---

## Things that will bite you

- **Hook contexts are stored at activation.** Editing `module_parts` in the descriptor changes nothing until the module is disabled and re-enabled.
- **Disabling the module deletes its settings.** `_remove()` drops the constants declared in `$this->const`. Standard Dolibarr behaviour, still surprising.
- **`$conf` is stale after `activateModule()` in the same process.** Call `$conf->setValues($db)` before reading the new constants, or you will read the pre-activation state and conclude the activation failed.
- **`$conf->modules_parts['hooks']` is keyed by module name**, not by context. Its value is that module's list of contexts.
- **Core's `Product::updatePrice()` overwrites `llx_product.price`** when writing a price level. A "wrong" base price after touching multiprice is usually core, not this module.
- **A form input named `action` shadows `form.action`** in JavaScript. `document.forms[x].action` returns the input element, not the URL. Use `getAttribute('action')`.
