# tools

Standalone maintenance scripts. **Not part of the installable module** — `build.sh` packages `module/` only, so nothing here ships in the deployment zip.

## bulk-tag.php

Bulk-assigns product categories from rules, so a catalogue that was never organised can be brought under the category tree without clicking through products one at a time.

Run it on the Dolibarr host (CLI only — it refuses to run over HTTP):

```bash
php tools/bulk-tag.php --report
```

If the script is not sitting under `custom/dolicatalog/tools/`, point it at your install:

```bash
DOLIBARR_ROOT=/var/www/html php tools/bulk-tag.php --report
```

### Safety

- **Dry run is the default.** Nothing is written unless you pass `--apply`.
- **Tagging is additive.** It uses `Categorie::add_type()`, never `Product::setCategories()` — the latter *replaces* a product's entire category set and would erase existing tagging.
- **Idempotent.** A membership that already exists is skipped, so re-running is safe.
- **Transactional.** Under `--apply`, any failure rolls the whole run back.

### Options

| Option | Effect |
|---|---|
| `--report` | Show coverage (tagged / untagged) and exit. Never writes. |
| `--rules=FILE` | JSON rule file. |
| `--csv=FILE` | CSV of `ref,category path` lines. |
| `--apply` | Actually write. Without it, the run only reports. |
| `--create-missing` | Create category paths that don't exist yet. |
| `--only-untagged` | Consider only products currently in zero categories. |
| `--type=0\|1` | Restrict to products (0) or services (1). |
| `--limit=N` | Process at most N products. |
| `--entity=N` | Entity to operate on (default 1). |

### Rule file

```json
{
  "rules": [
    {"match": "prefix",   "value": "LAP-",    "category": "Hardware/Laptops"},
    {"match": "regex",    "value": "^DOCK-",  "category": "Hardware/Accessories/Docking stations"},
    {"match": "label",    "value": "licence", "category": "Software/Licences"},
    {"match": "supplier", "value": 12,        "category": "Suppliers/Acme"},
    {"match": "type",     "value": 1,         "category": "Services"},
    {"match": "all",      "value": "",        "category": "Needs review"}
  ]
}
```

Match types: `all`, `ref` (exact), `prefix`, `suffix`, `regex` (against ref), `label` (substring), `type`, `supplier` (has a purchase price for that thirdparty id).

**Every matching rule is applied**, because a product can legitimately sit in several categories. Paths are `/`-separated from the root of the product category tree.

### Suggested workflow

```bash
# 1. See how bad it is.
php tools/bulk-tag.php --report

# 2. Draft rules, then preview. Nothing is written.
php tools/bulk-tag.php --rules=rules.json --only-untagged --create-missing

# 3. Happy with the plan? Commit it.
php tools/bulk-tag.php --rules=rules.json --only-untagged --create-missing --apply

# 4. Confirm.
php tools/bulk-tag.php --report
```

Start with `--only-untagged` so you cannot disturb products someone has already organised. Take a database backup before the first `--apply` regardless — this edits live catalogue data.

### A catch-all for the remainder

Rules rarely cover everything. A final `"match": "all"` rule sweeps whatever is left into a review bucket, which makes the stragglers browsable in the picker instead of search-only:

```json
{"match": "all", "value": "", "category": "Needs review"}
```

Combined with `--only-untagged`, that tags exactly the products no other rule claimed.
