# Lane currproof — WOOCS/FOX and Aelia real-runtime parity re-proof

Date: 2026-10-03 (UTC). Lane: `currproof`. Proof lane only: **no production-source edits,
no commits, no ledger edits.** All artifacts are under `/tmp/opf-lane-currproof-evidence/`.

## 1. Verdict summary

| Row | Prior status | Verdict after this lane | Basis |
| --- | --- | --- | --- |
| `WAPF-COMMERCE-FOX` | partial | **supported — real runtime 1:1 amount parity** | Real FOX/WOOCS 1.5.4 installed and active; OPF and WAPF Extended 3.1.5 produce identical preview/cart/order amounts on a real Woo cart and order. |
| `WAPF-COMMERCE-WOOCS` | partial | **supported — real runtime 1:1 amount parity** | FOX is the renamed WOOCS package (same class `WOOCS`, same adapter contract). Same evidence as FOX. |
| `WAPF-COMMERCE-AELIA` | partial | **blocked / partial — real plugin unavailable** | Aelia Currency Switcher is commercial-only (sold direct at aelia.co, not on wordpress.org, no free tier). A faithful hook-surface stub is provided; under it OPF=48 and WAPF=50, a candidate discrepancy that cannot be certified without the licensed plugin. |

## 2. Environment and currency-plugin inventory

- Clone: `/tmp/opf-image-currproof-wp`, served at `http://127.0.0.1:8321` (php built-in server).
- PHP 8.5.11, WordPress 7.1.2, WooCommerce 11.1.0, SQLite drop-in, no taxes, shop tax display `excl`.
- Baseline plugins: WooCommerce, OPF (active), WAPF Extended 3.1.5 (inactive), akismet, plugin-check, sqlite-database-integration.
- **No currency plugin was present** (`woocs`, `aelia*`, `woocommerce-currency-switcher` all absent).

Downloads attempted:

- **FOX — Currency Switcher Professional for WooCommerce 1.5.4** — available free on wordpress.org.
  - `curl -L https://downloads.wordpress.org/plugin/woocommerce-currency-switcher.zip`
  - zip sha256 `2d8ddca92b2af5b34fdffab2f866518661f6b2afa96efb543fa4f299e0da25b5`
  - installed `index.php` sha256 `95ed71ef39b081222242eeb0bb5b737a350b12aa1cc746ad42a5fea89bca5a76`
  - installed `classes/woocs.php` sha256 `73d45f061d09515283d66beef96fd08029ca5fa42299e5facc5ba927764f040e`
  - Real class is `WOOCS`; exposes `current_currency`, `default_currency`, `get_currencies()`, `back_convert()` — exactly the surface WAPF's `class-woocs.php` consumes. Free tier is capped at 2 currencies, which is sufficient here.
- **Aelia Currency Switcher for WooCommerce** — not on wordpress.org (`woocommerce-aelia-currencyswitcher`, `aelia-currency-switcher`, `aelia-foundation-classes` all 404). Aelia's own 2026 comparison states it is *"Sold direct, not on WordPress.org | No, 14-day free trial"*. **Blocking fact: no real package is obtainable without a purchased licence.**

## 3. Real-FOX runtime parity (FOX and WOOCS)

Harnesses: `scripts/opf-fox-real.php`, `scripts/wapf-fox-real.php`. Both run the real
`WOOCS` plugin with a real `WC_Cart` and a real order created through Woo APIs. No fake
API and no injected price filter.

Configured currency table: `USD` etalon rate 1, `EUR` rate 2. Product shop base 10 USD.
Fields: flat `+3`, percent `10%`, formula `[price]` (=10). One unit.

Expected in shop currency: 10 + 3 + 1 + 10 = **24**; at rate 2 = **48**; at rate 3 = **72**.

| Probe | OPF | WAPF 3.1.5 | Match |
| --- | --- | --- | --- |
| preview product base (shop) | 10 | 10 | ✅ |
| cart view price (EUR rate 2) | 48 | 48 | ✅ |
| cart edit price (shop target) | 24 | 24 | ✅ |
| cart subtotal (EUR) | 48 | 48 | ✅ |
| repeat totals stable | 48 | 48 | ✅ |
| rate 3 cart view | 72 | 72 | ✅ |
| order line total (EUR) | 48 | 48 | ✅ |
| default-currency cart price (USD) | 24 | — (not asserted) | ✅ |
| all internal checks | pass | pass | |

Raw results: `scripts/opf-fox-real-result.json`, `scripts/wapf-fox-real-result.json`.
This is a real order amount match, satisfying the "converts correctly needs the amount
to match on a real order" bar. Both engines agree because both normalise the line to the
shop-currency target and let FOX perform the final display conversion.

## 4. Exact conversion-path differences

Full registered-callback dumps: `scripts/hook-surface-opf.json`, `scripts/hook-surface-wapf.json`.

On identical real-FOX runtime:

| Stage | OPF | WAPF 3.1.5 |
| --- | --- | --- |
| recalc WAPF/OPF option prices | — | `Woocs::recalculate_pricing` @ `woocommerce_before_calculate_totals` **9** |
| set addon-adjusted cart price | `CartIntegration::apply_prices` @ same hook **20** | `Product_Controller::add_prices_to_cart_item` @ same hook **2000** (first pass only, `did_action` guard) |
| cart base back-conversion | `opf_cart_item_base_price` @ **20** → `WoocsIntegration::cart_base_price` | `wapf/pricing/cart_item_base` @ **20** → `Woocs::convert_back` |
| formula base back-conversion | `opf_formula_base_price` @ **10** → `formula_base_price` | `wapf/pricing/cart_item_base_for_formulas` @ **10** → `Woocs::convert_base_back` |
| preview/product base | `opf_frontend_config` @ **10** → `merge_frontend_config` | `wapf/pricing/product` @ **10** → `Woocs::set_product_base_price` + footer JS |
| final display conversion | `WOOCS::raw_woocommerce_price` @ **9999** on `woocommerce_product_get_price` | identical |

Net: the engines differ in *when* they write the cart line (OPF @20, WAPF @2000) and in
their private filter names, but they share the same normalise-to-shop-then-let-FOX-convert
path, which is why the real amounts match. FOX (not the engines) owns the final currency
conversion in both cases.

## 5. Aelia (commercial-only) — blocked, faithful stub only

Harnesses: `scripts/opf-aelia-stub.php`, `scripts/wapf-aelia-stub.php`. These define a
**faithful hook-surface stub** of Aelia's documented API:
`WC_Aelia_CurrencySwitcher::settings()->base_currency()/get_exchange_rate()`,
`::instance()->get_selected_currency()`, `$GLOBALS['woocommerce-aelia-currencyswitcher']`,
the `wc_aelia_cs_convert` filter, and view-price conversion. **This is not the real plugin.**

Same scenario (base 10, rate 2, flat 3 / percent 10% / formula [price]):

| Probe | OPF | WAPF 3.1.5 |
| --- | --- | --- |
| preview base (shop) | 10 | 10 |
| cart view price (EUR) | 48 | 50 |
| cart edit price | 48 | 50 |
| order line total (EUR) | 48 | 50 |

Under the stub WAPF leaves the cart base already converted (`base=20`), takes the percent
addon on that converted base (`2`), then multiply-converts the whole options total by the
rate (`15 → 30`): line `20+30=50`. OPF computes addons on the shop base (percent `1`,
flat `3`, formula `10` → options `14`), sets shop line `24`, then converts once (`48`).
Fixed and formula components agree; the `2.00` delta is the percent component converted
twice by WAPF under these stub semantics.

**This is a candidate discrepancy, not a verified defect:** real Aelia may suspend
`get_price()` conversion during cart recalculation, which would change the base WAPF sees.
It cannot be certified without the licensed plugin. Keep `WAPF-COMMERCE-AELIA` partial;
re-run these two harnesses against a real Aelia licence before changing the row.

## 6. Source hashes

```
OPF  includes/Service/WoocsIntegration.php   84b3e51e0debb357e66c3d11a312dfdbde564b9bdfc30ed6a60bdf8c03beadd6
OPF  includes/Service/AeliaIntegration.php   1ed07e706cfa17df2e2dfeb006e3cfdf08659a1f5fb7701f5b05007f3fb50151
WAPF includes/classes/integrations/class-woocs.php  08cf25aa8a6f2423a9425dbe4f0e330917b1ced76e9109f20b3ab3a49994503a
WAPF includes/classes/integrations/class-aelia.php  9f714d87025a0ddaf0b650d10ab2a53566dd1ed98e8f30532fbf255168c4404d
```

No OPF or WAPF production source was modified. `git status` in `/tmp/opf-lane-currproof`
shows no source changes from this lane.

## 7. Reproduction

```sh
# FOX (real) — OPF active, WAPF inactive
wp plugin activate open-product-fields-for-woocommerce --allow-root
wp plugin deactivate advanced-product-fields-for-woocommerce-extended --allow-root
wp plugin activate woocommerce-currency-switcher --allow-root
wp eval-file /tmp/opf-lane-currproof-evidence/scripts/opf-fox-real.php --path=/tmp/opf-image-currproof-wp --allow-root

# WAPF — OPF inactive, WAPF active
wp plugin deactivate open-product-fields-for-woocommerce --allow-root
wp plugin activate advanced-product-fields-for-woocommerce-extended --allow-root
wp eval-file /tmp/opf-lane-currproof-evidence/scripts/wapf-fox-real.php --path=/tmp/opf-image-currproof-wp --allow-root

# Aelia faithful stub (real Aelia unavailable) — run with FOX inactive
wp plugin deactivate woocommerce-currency-switcher --allow-root
wp eval-file /tmp/opf-lane-currproof-evidence/scripts/opf-aelia-stub.php --path=/tmp/opf-image-currproof-wp --allow-root
wp eval-file /tmp/opf-lane-currproof-evidence/scripts/wapf-aelia-stub.php --path=/tmp/opf-image-currproof-wp --allow-root
```

## 8. Scope limits

- FOX free 1.5.4 supports only 2 currencies; the scenario needs exactly 2, so the cap does not bite. Fixed per-currency prices, variable/subscription variations, tax-inclusive carts, coupon/shipping interaction, and classic/Store-API checkout flows were **not** exercised.
- The "preview" figure is the actual `opf_frontend_config` / `wapf/pricing/product` filter output during runtime, not a rendered storefront page. No browser rendering was performed in this lane.
- Aelia results are stub-only and must not be read as real-plugin parity.

## 9. Cleanup

Performed after evidence capture: deactivated WAPF and FOX, reactivated OPF (baseline),
deleted the installed FOX plugin directory, deleted the `woocs` and all `woocs_*` options
created by activation, cleared FOX transients, and removed temporary download/work dirs.
All test products, field groups, carts, and orders were deleted by each harness's `finally`
block. `/tmp/opf-image-currproof-wp` is back to its baseline plugin/option state; see the
`cleanup` block in `results.json` for the verified state.
