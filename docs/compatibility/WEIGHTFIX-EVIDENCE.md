# weightfix lane — evidence (WAPF-COMMERCE-WEIGHT, WAPF-PRICE-FORMULA-WEIGHT, data-tax + tax-aware hints)

Date: 2026-10-03
Worktree: `/tmp/opf-lane-weightfix` (branch `lane/weightfix`)
Clone: `/tmp/opf-image-weightfix-wp` — `http://127.0.0.1:8319`
Reference: WAPF Extended 3.1.5 at `wp-content/plugins/advanced-product-fields-for-woocommerce-extended`

## Rows / scope

| Row | Outcome |
| --- | --- |
| `WAPF-COMMERCE-WEIGHT` | **fixed + proven** — weight metadata survives schema normalization; cart/order weight mirrors WAPF 3.1.5 |
| `WAPF-PRICE-FORMULA-WEIGHT` | **fixed + proven (as 3.1.5 actually implements it)** — `[qty]`/`[x]` weight expressions evaluated at cart time; see "3.1.5 vs brief" note |
| Renderer `data-tax` | **fixed + proven in Chromium** — emits the real product tax multiplier (WAPF `Helper::get_tax_multiplier`) |
| Renderer tax-aware pricing hints | **fixed + proven in Chromium** — `fixed`/`formula` hints follow `woocommerce_tax_display_shop` (WAPF `Helper::maybe_add_tax`); percent stays percent-derived |

## WAPF 3.1.5 source audit (see `wapf-3.1.5-weight-tax-source-excerpts.txt`)

- Weight lives on field `options.weight` and choice `options.weight`; the `calc_weight`
  marker is set when either exists (`class-extended-controller.php:293-301`).
- `maybe_calculate_weight` (`class-extended-controller.php:303-380`):
  - slugged choice values use the **choice** weight, `[x]` = choice **label**;
  - slug-less scalar values use the **field** weight, `[x]` = raw submission;
  - `str_replace(['[qty]','[x]'], [line_qty, v], expr)` then `floatval()` — **not arithmetic**;
  - `qty_selector` fields multiply the evaluated weight by the entered count;
  - empty raw submissions skip;
  - the sum is added to the product weight and **floored at 0**;
  - virtual products are untouched.
- `Helper::get_tax_multiplier` (`class-helper.php:358-375`) = `1` for non-taxable / VAT-exempt,
  else `1 + array_sum(WC_Tax::calc_tax(1, WC_Tax::get_rates(tax_class)))`. Emitted as the
  product-totals `data-tax` attribute (`class-html.php:200`).
- `Helper::maybe_add_tax` (`class-helper.php:396-425`): empty/negative amounts and disabled tax
  render untouched; otherwise `wc_get_price_to_display($product, ['qty'=>1,'price'=>$amount])`
  for the shop page. `adjust_addon_price` never tax-adjusts `percent` amounts.
- Formula variable resolution (`class-helper.php:627-631`) exposes `[qty]`, `[price]`, `[x]`,
  `[options_total]`, `[field.id]`, `[price.id]`. **There is no `[weight]` token and no `weight()`
  formula function in 3.1.5**; formula functions are `min`, `max`, `len`, `lookuptable`
  (`class-public-controller.php:28-42`). The 3.1.5 "formula-driven weight" *is* the `[qty]`/`[x]`
  expression above. That is what this lane implements.

## Implementation (worktree files)

- `includes/Engine/FieldGroup.php` — `normalize_weight()` + field/choice weight whitelisting in `normalize_field()`.
- `includes/Engine/Calculator.php` — `field_weight()` (WAPF `maybe_calculate_weight` model),
  `weight_expression()` (substitute + `apply_filters('opf_field_weight')` + `floatval`).
- `includes/Engine/WapfMapper.php` — preserves field/choice `options.weight` verbatim; drops the
  stale "weight metadata not preserved" review note.
- `includes/Service/CartIntegration.php` — `opf_base_weight` anchoring on add/session restore,
  `apply_weights()` on `woocommerce_before_calculate_totals:30`; floors at 0, skips virtual,
  skips conditional-hidden fields.
- `includes/Service/Renderer.php` — `tax_multiplier()` for `data-tax`; `hint_price_with_tax()`
  for `fixed`/`formula` hints; product threaded through the hint call sites.
- `tests/Unit/CommerceWeightTest.php` (new), `tests/Unit/WapfMapperTest.php` (updated).
- `bin/e2e-weightfix-browser.php` (new fixture), `bin/e2e-weightfix-browser.mjs` (prior-agent browser proof).
- `bin/e2e-commtax-commerce.php`, `bin/e2e-commtax-wapf-reference.php` — lane-clone path override.

## Proof

### 1. PHPUnit — 556 tests, 2288 assertions, 0 failures
`vendor/bin/phpunit` → `Tests: 556, Assertions: 2288, PHPUnit Deprecations: 1` (deprecation pre-existing).

### 2. OPF real WooCommerce lifecycle — 40/40 checks pass
`OPF_COMMTAX_ALLOW=1 OPF_COMMTAX_ABSPATH=/tmp/opf-image-weightfix-wp wp eval-file bin/e2e-commtax-commerce.php`
→ `weightfix-opf-commerce-results.json`, `weightfix-opf-commerce-run.log`.

Weight checks (verbatim):
```
ok field weight metadata preserved verbatim ("1")
ok choice weights preserved verbatim
ok [qty] choice weight evaluates to line quantity
ok weight expression is floatval substitution, not arithmetic
ok cart item stores canonical base weight (2.0)
ok item weight = base 2.0 + choice 0.5 + [x]=4 => 6.5 (WAPF parity)
ok cart contents weight = 3 x 6.5 = 19.5
ok repeated totals pass keeps merged weight 6.5 (idempotent)
ok [qty] choice weight = base 2.0 + line qty 3 => 5.0
ok virtual product weight untouched
ok negative weight sum floors at 0
ok weight order line keeps _opf_fields
ok order-again recomputes merged weight (base 2.0 - 10 floored = 0)
ok totals data-tax emits the real product multiplier (1.1 = WAPF get_tax_multiplier)
ok non-taxable product emits data-tax 1
ok excl shop display: fixed hint stays 10
ok incl shop display: fixed hint converts 10 -> 11 (WAPF maybe_add_tax)
ok incl shop display: formula hint converts 10 -> 11
ok percent hint stays percent-derived (50), never tax-adjusted (not 55)
```

### 3. WAPF Extended 3.1.5 reference lifecycle — 19/19 checks pass
`OPF_COMMTAX_ALLOW=1 ... wp eval-file bin/e2e-commtax-wapf-reference.php`
→ `weightfix-wapf-reference-results.json`. Reference proves the same numbers OPF emits:
```
ok item weight = base 2.0 + choice 0.5 + [x]=4 => 6.5
ok cart contents weight scales with qty 3 => 19.5
ok [qty] choice weight = base 2.0 + line qty 3 => 5.0
ok virtual product weight untouched
ok negative weight sum floors at 0
ok reference weight expression is floatval substitution, not arithmetic (substituted="4*0.5")
ok wapf get_tax_multiplier = 1.1 for taxable product
ok wapf maybe_add_tax shop incl converts addon 10 -> 11
```

### 4. Real Chromium (OPF storefront) — 7/7 both phases
Fixture `bin/e2e-weightfix-browser.php` (product price 100, weight 2, 10% rate; `pack` select
`Light pack +$10/0.5kg`; `pct` percent-50 hint), then `node bin/e2e-weightfix-browser.mjs`:
```
PHASE=excl: data-tax=1.1, fixed hint $10.00, percent $50.00, cart merged unit 110, 0 JS errors
PHASE=incl: data-tax=1.1, fixed hint $11.00, percent $50.00, cart merged unit 121, 0 JS errors
```
Screenshots: `browser-weightfix-product-{excl,incl}.png`, `browser-weightfix-cart-{excl,incl}.png`.
Rendered page: `weightfix-product-page.html`.

## Cleanup / baseline

| Metric | Before | After |
| --- | --- | --- |
| products (publish) | 13 | 13 |
| opf_field_group (publish) | 19 | 19 |
| shop_coupon / shop_order / attachment | 0 | 0 |
| users | 4 | 4 |
| WC tax rates | 0 | 0 |
| active plugins | OPF active, WAPF inactive | OPF active, WAPF inactive |

All fixtures (commerce e2e, WAPF reference, browser fixture) removed what they created;
`opf_weightfix_browser_state` option deleted.

## Notes / residual

- **3.1.5 vs brief wording:** the brief named a `[weight]` token / `weight()` function. WAPF 3.1.5
  has neither (source excerpt + all-plugin grep). This lane implemented the weight feature that
  3.1.5 actually ships (`[qty]`/`[x]` expressions, `floatval`, qty_selector multiply). The 3.2
  changelog (7 May 2026) confirms "simple formulas" in the weight setting and a changed
  quantities+images weight calculation are **3.2** additions, not 3.1.5:
  https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/
  The richer arithmetic `[field.{id}]` weight formula belongs to a 3.2-parity effort, not this
  3.1.5-pinned lane.
- **Percent hint display:** OPF renders percent hints as a currency figure (50% of base) while
  WAPF renders a literal `50%`. This is pre-existing OPF display-lane behavior, intentionally
  left intact; only tax adjustment was in scope.
- **Upload weight:** fixed during review — WAPF applies a slug-less file field's weight **once**
  with `[x]` bound to the comma-joined names; the prior-agent draft multiplied by file count.
