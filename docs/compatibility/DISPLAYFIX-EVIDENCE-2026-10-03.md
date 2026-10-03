# Lane displayfix — report (DISPLAY-PRODUCT-PRICE impl + `opf:pricing` event + preview display)

Worktree `/tmp/opf-lane-displayfix` (base main `4f5e6e8`, branch `lane/displayfix`).
Clone `/tmp/opf-image-displayfix-wp` at `http://127.0.0.1:8326` (Woo 11.1.0,
WAPF Extended 3.1.5 installed/inactive, OPF symlinked). admin/password.
Proof lane: no commits, pushes or ledger edits.

## Outcome at a glance

| Task | Outcome | Evidence |
| --- | --- | --- |
| 1 `WAPF-DISPLAY-PRODUCT-PRICE` | **CLOSED** — 5 modes, WAPF meta keys, byte-identical to WAPF | `results/dspf-price-display-opf.json` vs `-wapf.json`, `dspf-price-display.json`, `dspf-price-archive.json`, `shots/dspf-price-*.png` |
| 2 `opf:pricing` CustomEvent | **CLOSED** — native event with theme payload | `results/dspf-pricing-*.json`, `theme-consumers/quantity.js:131` |
| 3 preview display (exemption/location/tax-incl) | **CLOSED** — preview matches `wc_get_price_to_display()` | `results/dspf-pricing-incl-*.json`, `dspf-pricing-excl-*.json` |
| Unit tests | 547 PHPUnit pass; new JS test passes | `logs/phpunit.txt`, `logs/js-pricing-event.txt` |

## Source changes (ownership respected)

- **new** `includes/Service/ProductPriceDisplay.php` — admin controls on
  `woocommerce_product_options_pricing`, save on
  `woocommerce_admin_process_product_object`, filter on
  `woocommerce_get_price_html` (prio 101).
- **new** `tests/Unit/ProductPriceDisplayTest.php`, `tests/js/opf-pricing-event.test.cjs`
  (initial `composer install` produced `vendor/`, which is gitignored).
- `includes/Service/Renderer.php` — preview region only: `render_totals()`
  attribute block + new `tax_multiplier()` / `tax_display_factor()` helpers.
- `assets/js/opf-frontend.js` — `writeTotals()` tail: display factor + event dispatch.
- `open-product-fields-for-woocommerce.php` — wire `ProductPriceDisplay::init()`.

Not touched: `Calculator.php`, `Evaluator.php`, `CartIntegration.php`,
`Uploads*`, `LinkedProducts.php`, `Settings.php`, `Rest.php`.

---

## Task 1 — WAPF-DISPLAY-PRODUCT-PRICE

**Implementation** (`ProductPriceDisplay`): WAPF's exact meta keys
`_wapf_price_display` (`` / `hide` / `before` / `after` / `replace`) and
`_wapf_price_label`, so products migrated from WAPF render unchanged without a
data migration. Verified against WAPF source
(`class-admin-controller.php:196-246`, `class-product-controller.php:71,83-128`).

**Side-by-side vs live WAPF Extended 3.1.5** (same fixture products, OPF active
vs WAPF active): the normalized `get_price_html()` payloads are **byte-identical**
for all five modes and for the variable product:

```
$ diff <(grep -v '"id"' .../dspf-price-display-opf.json) \
       <(grep -v '"id"' .../dspf-price-display-wapf.json)   => no differences
```

Rendered output:

| Mode | `get_price_html()` |
| --- | --- |
| default | `<span class="woocommerce-Price-amount amount">…$10.00…</span>` |
| hide | *(empty)* |
| before | `<span class="wapf-price-before">Label-before</span> <amount>` |
| after | `<amount> <span class="wapf-price-after">Label-after</span>` |
| replace | `<span class="wapf-price-replace">Label-replace</span>` |

**Contexts.** Real Chromium: single-product page for each mode
(`browser/dspf-price.mjs`), and the WooCommerce products loop via `[products]`
(`browser/dspf-archive.mjs`). All five modes render in both product and
archive/loop contexts.

**Variable products.** The filter only touches `simple`/`subscription`; a
variable parent carrying `_wapf_price_display=hide` keeps its min–max range
(`shots/dspf-price-variable.png`, `results/dspf-price-display.json` →
`variable`). No price corruption.

**Active-WAPF safety.** OPF's filter runs at priority 101 (after WAPF's 100) and
bails when the incoming HTML already carries a `wapf-price-*` wrapper, so with
both plugins active WAPF keeps ownership and the label is never applied twice.

---

## Task 2 — `opf:pricing` CustomEvent

The production theme reads the event in
`resources/js/product/quantity.js:131`:

```js
document.addEventListener('opf:pricing', (event) => {
  applyPricing(event.detail?.displayed?.final ?? event.detail?.final);
});
```

and `resources/js/product/field-accordion.js:427` only needs the event itself
(copies in `theme-consumers/`). `writeTotals()` now dispatches a native
`CustomEvent` at the recompute point:

```js
document.dispatchEvent(new CustomEvent('opf:pricing', { detail: {
  base, options, final, quantity,
  displayed: { base, options, final },   // WooCommerce-displayed totals
}}));
```

The dispatch is guarded (`typeof document.dispatchEvent === 'function' &&
typeof CustomEvent === 'function'`) and wrapped in `try/catch` so a consumer-side
failure can never break the totals render (and the pure-PHP/VM JS suites keep
their synchronous behaviour).

**Consumer proof** (`browser/dspf-pricing.mjs` + temporary mu-plugin faithful
port of both theme listeners): the consumer received the event, resolved
`displayed.final`, and the marker matched the Woo-displayed grand in every
scenario (`results/dspf-pricing-*.json` → `lastEvent`, `marker`).

---

## Task 3 — preview display (tax exemption / location / incl)

**Root cause.** The frontend preview rendered its raw option totals
(`fmtMoney(amount * currency_rate)`) and ignored tax display entirely; the
totals element also hardcoded `data-tax="1"` (`render_totals()`'s dead
`if` branch set `1` in both paths). So while cart/order applied Woo's tax
pipeline, the on-page preview showed the untaxed amount under
`woocommerce_tax_display_shop=incl` and under tax-exempt/foreign-location
customers — the "preview diverges from cart/order" symptom.

**Fix.** `Renderer::render_totals()` now emits the real values and the frontend
applies them:

- `data-tax` = `Renderer::tax_multiplier()`:
  `wc_get_price_including_tax(product, ['price' => base]) / base` — the gross
  multiplier, `1` for exempt/non-taxable. Matches the tax re-proofer's expected
  `incl/base` and WAPF `Helper::get_tax_multiplier`.
- `data-opf-tax-factor` = `Renderer::tax_display_factor()`:
  `wc_get_price_to_display(product, ['qty' => 1, 'price' => 1, 'display_context' => 'shop'])`
  — the shop-context multiplier. Because these are WC functions, they already
  honor the customer's tax location and VAT-exempt session; `writeTotals()`
  multiplies all three preview totals by it.

**Real Chromium matrix** (`browser/dspf-pricing.mjs`, base 100 + $5 + $3, qty 2):

| Scenario | display | `data-tax` | `data-opf-tax-factor` | DOM grand | Woo-displayed grand | event `displayed.final` |
| --- | --- | --- | --- | --- | --- | --- |
| US:CA base 10% | excl | 1.1 | 1 | 216 | 216 | 216 |
| US:NY 8% | excl | 1.08 | 1 | 216 | 216 | 216 |
| DE 19% | excl | 1.19 | 1 | 216 | 216 | 216 |
| VAT exempt | excl | 1 | 1 | 216 | 216 | 216 |
| US:CA base 10% | incl | 1.1 | 1.1 | 237.60 | 237.60 | 237.60 |
| US:NY 8% | incl | 1.08 | 1.08 | 233.28 | 233.28 | 233.28 |
| VAT exempt | incl | 1 | 1 | 216 | 216 | 216 |

All checks pass in `results/dspf-pricing-*.json`. Cart/order code was not
touched.

---

## Unit / regression tests

- `./vendor/bin/phpunit` → **547 tests / 2311 assertions pass**
  (1 pre-existing PHPUnit deprecation). New `ProductPriceDisplayTest` covers the
  WAPF keys, all five modes, escaping and the non-product early return.
- `node --test tests/js/opf-pricing-event.test.cjs` → 2/2 — asserts the event
  payload (raw + displayed) and that the tax factor scales the preview.
- Existing JS suites: 32/32 relevant tests pass. The only failure in
  `tests/js/*.test.cjs` is the pre-existing, unrelated
  `opf-builder-auth.test.cjs` (documented in the taxproof lane and reproducible
  on the unmodified repo).

## Cleanup (verified)

Fixtures removed (products/group/tax rates/page, `dspf_fixture_state`,
`dspf_archive_state`), temporary mu-plugin deleted, WAPF deactivated and its
`wapf_db_version` option removed, tax options restored (calc_taxes=no,
display_shop=excl, prices_include_tax=no, tax_based_on=shipping,
default_country=US:CA; tax_rates=0). Final vs baseline identical: products=5,
groups=13, orders=250, users=1, wapf_product=746, active plugins
`[open-product-fields-for-woocommerce, sqlite-database-integration, woocommerce]`
(`logs/baseline.txt`, `logs/final.txt`). Worktree `git status` shows only the
four intended source files plus the two new tests.

## Caveats

- The `opf:pricing` consumer proof uses a faithful mu-plugin port of the two
  production listeners (the production theme is not installed on the clone),
  matching the taxproof lane's method. The listener call sites are cited.
- The preview factor is evaluated at page render from the current customer
  session. Address changes that alter tax without a page reload are not
  re-fetched; that matches how the server-rendered totals element works today.
- Currency-plugin (WOOCS/FOX/Aelia) interaction was not re-run here; the tax
  factor is multiplicative and independent of the existing `currency_rate`, so
  the two compose.
