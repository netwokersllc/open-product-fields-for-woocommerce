# WAPF formula quantity semantics — line-flat vs per-unit — 2026-10-02

## Scope

This lane fixes the verified WAPF Extended 3.1.5 `fx` quantity-semantic gap:
WAPF `fx` is **line-flat** for normal fields, while OPF priced formulas
per-unit on the server and the browser evaluated raw results without
quantity normalization. One `{type, per_unit}` model now drives the server,
the browser totals writer, and the theme-space `data-opf-*` price attributes.
Verified on a disposable WooCommerce clone against the installed WAPF
Extended 3.1.5 oracle. No production, release, ledger, or roadmap state was
touched.

## Root cause

WAPF 3.1.5 `Fields::do_pricing()`
(`includes/classes/class-fields.php:280-315`, installed tree
`/tmp/opf-fxqty-woo-20261002/wp-content/plugins/advanced-product-fields-for-woocommerce-extended`)
returns a **per-unit** addon that is added to the product price before
WooCommerce multiplies by line quantity
(`includes/controllers/class-product-controller.php:630`
`$item_price = $base + $options_total`). The per-type truth table:

| type      | normal field        | qty_based field (`clone_type=qty` / `qty_based` option) |
|-----------|---------------------|--------------------------------------------------------|
| `percent` | `result`            | `result * qty`                                          |
| `p`       | `result / qty`      | `result`                                                |
| `qt`      | `amount`            | `amount * qty`                                          |
| `fx`      | `x / qty`           | `x`                                                     |
| `fixed`/`default` | `amount / qty` | `amount`                                                |

`$qty_based` comes from `class-cart.php:56`
(`clone_type === 'qty' || !empty(qty_based)`).

OPF diverged two ways:

- `Calculator::choice_addon` / `field_pricing_addon` returned formula and
  percent results unconditionally per-unit — a formula choice
  `[price] * 0.2` charged `20/unit` where WAPF `fx` charges `20/line`.
- `opf-frontend.js` summed raw results without the `result/qty`
  normalization, so browser previews disagreed with the server, and the
  registry omitted `per_unit` for `qt`-mapped fixed choices.
- Quantity-repeat browser rows were summed per row instead of deduplicated
  into merged clone lines the way
  `CartIntegration::split_quantity_repeat_cart_item` splits the cart.

## The pricing model

Every normalized pricing block is `{type, amount, formula, formula_raw,
per_unit}`. `result` = `amount` (fixed), `base * amount/100` (percent), or
the evaluated expression (formula). Then:

- normal field   → per-unit addon `per_unit ? result : result/qty`
- quantity-repeat → per-unit addon `per_unit ? result*qty : result`
  (WAPF `clone_type=qty`; OPF `repeat.mode=quantity` splits these into cart
  lines of `qty = identical-unit count`)

`per_unit` explicit is always honored. Absent-flag defaults (WAPF-faithful):
`fixed` → flat, `formula` → flat (`fx` parity — the gap fix),
`percent`/`none` → per-unit.

## Compatibility surfaces

- **Migration**: `FieldGroup::SCHEMA` 1 → 2. Records that *declare*
  `schema: 1` get `per_unit=true` injected on unflagged percent/formula
  pricing, preserving the old forced-per-unit behavior at read time
  (`FieldGroup::migrate_schema_1_pricing`). Schema-absent and schema-2
  payloads get the new defaults. Builder seed now emits
  `FieldGroup::SCHEMA`.
- **Import** (`WapfMapper`): `fixed` → fixed flat; `qt` → fixed per-unit;
  `percent` → percent per-unit; `p` → percent flat; `fx` → formula with
  `formula_raw` verbatim, `formula` = outermost `*[qty]`-stripped canonical,
  `per_unit` = whether the raw expression carried that factor.
- **Export** (`WapfExporter`): fixed flat → `fixed`, fixed per-unit → `qt`,
  percent per-unit → `percent`, percent flat → `p`, formula flat → `fx`
  verbatim, formula per-unit → `fx` wrapped in `(expr) * [qty]`.
- **Theme attrs** (`Renderer::pricing_attrs`, consumed verbatim by the
  production theme `quantity.js`): percent per-unit → `percent`; percent
  flat → `fx` `([price]*a/100)/[qty]`; fixed flat → `fixed`; fixed per-unit
  → `qt`; formula per-unit → `fx` `(expr)`; formula flat → `fx`
  `(expr)/[qty]`. The theme's `fxTokenRe` accepts `[price] [qty] [val]
  [addons]/[options_total]`, so the canonical `[addons]` token survives.
- **Registry** (`window.OPF_FIELDS`): choices and field pricing now carry
  `per_unit` + `formula_raw`.
- **Browser** (`opf-frontend.js`): `choiceUnitAddon` applies the truth
  table; `[addons]`/`[price.*]` contexts accumulate in per-unit space;
  quantity-repeat units dedupe by whole-unit signature (all quantity-scope
  fields' values at that index) into merged lines of `qty = count`, matching
  the server's cart split.

## Runtime proof — disposable clone

Clone `/tmp/opf-fxqty-woo-20261002` (WordPress, WooCommerce 11.1.0, SQLite,
PHP 8.5.11; `php -S 127.0.0.1:8207`). Products at `$100`:

- `wapf-fxqty-oracle` — WAPF `_wapf_fieldgroup` select with six choices:
  `flatfx` (`fx [price]*0.2`), `scaledfx` (`fx ([price]*0.2)*[qty]`),
  `flatfee` (`fixed 5`), `qtfee` (`qt 5`), `pct` (`percent 10`),
  `flatpct` (`p 10`).
- `opf-fxqty-native` — same six choices authored in OPF vocabulary
  (schema 2 defaults).
- `opf-fxqty-imported` — group produced by `WapfMapper::map()` on the WAPF
  source, then `FieldGroups::save` — exercises the real import path.
- `opf-fxqty-legacy` — raw `schema:1` post_content written directly (no
  `per_unit` flags) to prove read-time migration preserves old semantics.

Line totals (`fxqty-verify.php` via `wp eval-file`; full JSON in
`fxqty-commerce-results.json`):

| choice   | qty | WAPF | OPF native | OPF imported | Store API OPF | Store API WAPF |
|----------|-----|------|------------|--------------|---------------|----------------|
| flatfx   | 1   | 120  | 120        | 120          | 120           | 120            |
| flatfx   | 4   | 420  | 420        | 420          | 420           | 420            |
| scaledfx | 4   | 480  | 480        | 480          | 480           | 480            |
| flatfee  | 4   | 405  | 405        | 405          | —             | —              |
| qtfee    | 4   | 420  | 420        | 420          | 420           | —              |
| pct      | 4   | 440  | 440        | 440          | —             | —              |
| flatpct  | 4   | 410  | 410        | 410          | —             | —              |

- Classic cart (`$_POST['opf']` / `$_REQUEST['wapf']` + `add_to_cart`):
  identical unit prices across WAPF, native, and imported OPF for all six
  pricing shapes at qty 1 and 4.
- Store API (`/wc/store/v1/cart/add-item` with `opf_fields` /
  `wapf`+`wapf_field_groups` params): identical results.
- Orders: `WC()->checkout()->create_order` → OPF order line total 420 with
  `_opf_fields` meta persisted; WAPF order also 420.
- Legacy `schema:1` read: formula migrated to `per_unit=true` → qty 4 line
  480 (old forced-per-unit preserved); fixed stayed flat → 405.
- Browser (headless Chromium on the clone): `.opf-options-total` /
  `.opf-grand-total` equal the server line totals for all six choices at qty
  1 and 4 (`fxqty-browser-results.json`); zero console errors.
- Theme attrs on rendered radio inputs
  (`browser-check.mjs` → `attrs`): `fx "([price] * 0.2) / [qty]"`,
  `fx "([price] * 0.2)"`, `fixed "5"`, `qt "5"`, `percent "10"`,
  `fx "([price] * 0.1) / [qty]"`.

Note on methodology: WAPF's product controller returns early once
`woocommerce_before_calculate_totals` has fired (`did_action > 1`), so the
verify script resets the action counter between scenarios to emulate
separate requests — a clone-harness artifact, not a plugin behavior change.

## Test coverage added

- `tests/Unit/FormulaLinePricingTest.php` — 14 tests pinning both rows of
  the truth table (flat/scaled formula, `p`, `qt`, qty-based variants) plus
  schema-1 migration and new-payload defaults.
- `tests/Unit/WapfMapperTest.php` — fx `per_unit` derivation, `p` → flat
  percent, `qt` → per-unit fixed, field-level `formula_raw` retention.
- `tests/js/opf-pricing-per-unit.test.cjs` — `choiceUnitAddon` truth table
  and `writeTotals` line math at qty 4.

## Residual gaps

- WAPF `nr`/`nrq`/`char`/`charq` pricing types still import as `none` with a
  `needs_review` note (unchanged, pre-existing).
- WAPF's `qty_based` field *option* (non-clone toggle) is not yet mapped to
  `repeat.mode=quantity` — quantity-based pricing requires an actual
  quantity-repeat field (unchanged).
- `formula_raw` is retained verbatim only for formulas whose qty-compensation
  factor is the outermost term; formulas embedding `[qty]` mid-expression
  keep the token live (verified) but import stays `per_unit=false`.
- Theme `quantity.js` encoding relies on the theme's `fx` evaluator stripping
  outermost `*[qty]`; verified against the production theme source, not
  executed in a themed clone here.
