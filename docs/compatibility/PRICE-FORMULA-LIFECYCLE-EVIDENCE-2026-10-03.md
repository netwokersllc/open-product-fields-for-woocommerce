# PriceA lane — price-formula lifecycle evidence (OPF ↔ WAPF Extended 3.1.5)

Runtime: `/tmp/opf-image-pricea-wp` (SQLite clone, `http://127.0.0.1:8304`), WP 7.1.2,
WooCommerce 11.1.0, PHP 8.5.11, WAPF Extended 3.1.5, OPF 0.1.0.
Fixture: `bin/e2e-pricea-fixture.php` (guarded by `OPF_PRICEA_ALLOW=1`); products
15216–15236, filestate product 15261, OPF groups 15217–15237/15262, account orders
15260 (OPF) and 15265 (WAPF) owned by customer `pricea-proof` (id 86).
Browser proof: `bin/e2e-pricea-browser.mjs` — real Chromium (headless shell 1228),
per-provider runs of identical disposable products.

## Gate coverage per row

Each row below passed: admin field config → storefront render → submitted value →
preview → cart line price → classic checkout (`?wc-ajax=checkout`, COD) → durable
order total → field metadata persisted → partial refund (`wc_create_refund`, $2).
Order-again additionally passed on OPF order 15260 and WAPF order 15265 via the
real my-account "Order again" link (login as pricea-proof).

| Row | Formula | OPF unit / order | WAPF unit / order | Outcome |
|---|---|---|---|---|
| WAPF-PRICE-FORMULA-MIN-MAX | `max(3;min([field.x];7))*[qty]`, x=8 | 17 / 17 | 17 / 17 | proven |
| WAPF-PRICE-FORMULA-TEXT-COMPARE | `if([field.size]=Extra Large;20;0)*[qty]`, size=xl | 30 / 30 | 30 / 30 | proven |
| WAPF-PRICE-FORMULA-ADVANCED | `round(sqrt(pow([field.x];2)))*[qty]`, x=2 | 12 / 12 | 12 / 12 | proven |
| WAPF-PRICE-FORMULA-DATE | `datediff(today();[field.d])*[qty]`, d=01-15-2026 | 271 / 271 | 271 / 271 | proven |
| WAPF-PRICE-FORMULA-DOW | `dow([field.d])*7*[qty]`, d=10-03-2026 | 52 / 52 | 52 / 52 | proven |
| WAPF-PRICE-FORMULA-MONTH | `month([field.d])*3*[qty]`, d=03-15-2026 | 19 / 19 | 19 / 19 | proven |
| WAPF-PRICE-FORMULA-CHECKED | `checked(opts)*4*[qty]`, a+b | 18 / 18 | 18 / 18 | proven |
| WAPF-PRICE-FORMULA-SUM-QTY | `sumQty(prints)*[qty]`, oak=2+ash=3 | 15 / 15 | 15 / 15 | proven |
| WAPF-PRICE-FORMULA-TRIG | `round(cos([field.x])*100)*[qty]`, x=0 | 110 / 110 | 110 / 110 | proven |
| WAPF-PRICE-FORMULA-PRICE-ID | `[price.plan]*2*[qty]`, plan=premium($5) | 25 / 25 | 25 / 25 | proven |
| WAPF-PRICE-FORMULA-LEN | `len([field.t];true)*[qty]`, t="  a b " | 12 / 12 | 12 / 12 | proven |
| WAPF-PRICE-FORMULA-FIELD-STATE | `files(docs)*3*[qty]`, 2 files | **10 / 10** | **16 / 16** | **bug-documented** |

PHP oracle parity (`php-oracle.json`): all 11 literal probes identical
(minmax 7, textcmp 20, advanced 2, date 261, dow 42, month 9, checked 8,
sumqty 5, trig 100, len 2, price-id via `[price.plan]` and `[field.size]`
resolution). `fieldstate-oracle.json`: `files(docs)*3` = WAPF 6 vs OPF 0
(two uploads), 0 vs 0 (no uploads), `(files(docs)*3)*[qty]` = 6 vs 0.

## Documented gaps (not fixed here — owned by other lanes)

1. **FIELD-STATE: OPF lacks `files()` formula support.**
   WAPF `extend/formulas.php` registers `files(<field>)` which counts
   comma-separated uploaded-file labels; two uploaded files → `files(docs)*3` = 6.
   - OPF PHP `includes/Engine/Calculator.php` — no `files` function registered;
     the expression fails closed to 0 (cart addon $0 → unit 10 vs WAPF 16).
   - OPF JS `assets/js/opf-frontend.js` — `files` absent from the registered
     function map; live preview shows "Options total $0.00" while WAPF shows $6.
   - OPF upload plumbing itself is complete and more secure than WAPF's:
     private storage outside webroot (`OPF_UPLOAD_PRIVATE_DIR`), 64-hex tokens,
     `opf_upload_*` option records, owner/claimed/TTL revalidation at
     `Uploads::validate_tokens`, `_opf_uploads` order-item meta. The gap is only
     the missing function binding that would expose `count(tokens)` to formulas.
   - Evidence: `fieldstate-oracle.json`, `browser-lifecycle-results.json`
     (wapf filestate 16.00 vs opf filestate 10.00), `opf-filestate.png` vs
     `wapf-filestate.png`, `upload-probe.json`.

2. **`[qty]` inside a choice formula has different semantics.**
   On a quantity-3 order of the minmax/len products:
   - OPF multiplies the per-unit addon by the line quantity: unit 31 / 16
     (order 15260 totals 93 / 48).
   - WAPF bakes a fixed per-unit `calc_price` at add-time (qty treated as 1):
     unit 17 / 12 (order 15265 totals 51 / 36).
   At qty 1 the engines are identical; at qty >1 the same formula prices
   differently. Both engines self-consistently restore their own prices on
   order-again (browser proof below). Candidate ledger row for the `[qty]`
   contract; document-only.

## Order-again (LEN residual + cross-formula restore)

`pricea-proof` login → `/my-account/view-order/<id>/` → real "Order again" link
→ cart restored via Store API:

| Provider | Order | Restored lines | Expected | ok |
|---|---|---|---|---|
| wapf | 15265 | 15216×3 @ 1700, 15236×3 @ 1200 | 17/12 | true |
| opf | 15260 | 15216×3 @ 3100, 15236×3 @ 1600 | 31/16 | true |

OPF restores `_opf_fields` into `woocommerce_order_again_cart_item_data`
(CartIntegration) and reprices with its own `[qty]` semantics; WAPF restores
`_wapf_meta` via `class-product-controller.php` hooks
(`woocommerce_order_again_cart_item_data`, `woocommerce_add_order_again_cart_item`).

## Fixture/environment findings worth noting

- **`wapf_datepicker` option gates the `date` field type registration**
  (class-config.php: `get_field_definitions` registers 'date' only when the
  option is 'yes'). With it off (default), date fields render but
  `is_category('field')` is false → submitted values silently dropped and
  `[field.d]` formulas evaluate 0; also `datepicker.min.js` is not enqueued,
  producing `referenceJsErrors` `$this.dp is not a function` /
  `dpFormatDate is not defined`. The fixture sets `wapf_datepicker=yes`
  (baseline recorded in state['options']; cleanup deletes it since it did not
  exist before).
- **WAPF date literal format is `mm-dd-yyyy`** (`wapf_date_format`); ISO input
  makes `createFromFormat` return false → `setTime() on false` fatal in
  `extend/date.php:239` (reference-side fragility; OPF accepts ISO and fails
  closed instead). Harness fills `mm-dd-yyyy` for WAPF, ISO for OPF.
- **OPF private upload default path is unwritable in this clone**:
  `dirname(dirname(ABSPATH))` resolves to `/`, so the derived
  `/opf-private-uploads-<hash>` root cannot be created; uploads fail closed with
  "An uploaded file is unavailable." Set `OPF_UPLOAD_PRIVATE_DIR` in
  `wp-config.php` → native multipart upload then works end-to-end
  (token creation → `validate_tokens` → `_opf_uploads` meta). Cleanup removes
  the dir via the same constant.
- WAPF field-type naming differs from OPF: `checkbox` → `checkboxes`,
  `image_quantity` → `image-swatch-qty`; WAPF raw importer lifts only
  top-level field keys (`choices`, `multiple` must not be nested in `options`).
- WAPF checkbox fields emit a hidden `wapf[field_opts][]=0` companion input;
  `checked(opts)` correctly counts only real checks (2 → addon 8).
- `mu-stash/` holds two stale debug mu-plugins (probe-hooks, dbg-render) that
  were moved out of the clone's `mu-plugins/` during WAPF-only runs.

## Artifacts

- `browser-lifecycle-results.json` — 24/24 lifecycle legs
  (`cart_unit_ok`, `order_total_ok`, `has_field_meta`, refund id) + 2/2
  order-again legs (`restored_ok`).
- `php-oracle.json` — 11/11 formula probes identical.
- `fieldstate-oracle.json` — files() gap probes.
- `server-cart-order-refund.json` — server-side cart/order/refund coverage
  (earlier leg, all cases matching).
- `admin-save-reload.json` — formula persistence in group admin for all cases.
- `state.json` — full fixture state incl. status/page/option baselines.
- Screenshots: `opf|wapf-{minmax,sumqty,filestate,len}.png`,
  `opf|wapf-order-again-cart.png`.
- `upload-probe.json` — OPF native upload diagnostic (pre-fix `invalid` tokens).
- `cleanup.json` — baseline restoration proof (written by cleanup mode).

## Verification commands

- `vendor/bin/phpunit` → 355 tests / 1559 assertions, OK (1 PHPUnit deprecation).
- `OPF_PRICEA_ALLOW=1 OPF_PRICEA_OUT=/tmp/opf-lane-pricea-evidence node bin/e2e-pricea-browser.mjs`
  → `Recorded 24 browser lifecycle cases + 2 order-again runs.` with all
  `OBSERVED <provider> <case> unit= <expected> order= <expected> meta= true`
  lines and `ORDER-AGAIN … restored=…` matching per-engine expectations.
