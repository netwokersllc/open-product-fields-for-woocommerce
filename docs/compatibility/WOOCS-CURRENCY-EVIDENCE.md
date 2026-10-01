# WOOCS currency implementation and remaining parity

Historical audit 2026-10-01 (final recorded check: 14:48:30 UTC), against OPF `6126bbe` and installed
WAPF Extended 3.1.5. `WAPF-CURRENCY-WOOCS` is **partial**. The prior ledger
claim that OPF has no adapter was stale; implemented and executed paths exist,
but it found a preview gate mismatch and unconnected paths. The preview fix and
its later proof are recorded below; unconnected paths remain open.

## Source and connected paths

OPF's display/pricing work is present in this HEAD through commits `db86587`
and `8b3acf4` (equivalent changes to the original `ce9f02e` / `ab8e9ab`).
The audited runtime files were not changed by this documentation update.

| Installed WAPF `class-woocs.php` contract | OPF implementation and evidence |
| --- | --- |
| `convert_back()` uses foreign currency, `woocs_is_multiple_allowed=1`, current rate, and 8-digit `back_convert()` | `WoocsIntegration::back_convert()` preserves those gates/arguments. `cart_base_price()` reads a fresh product before conversion; `CartIntegration::apply_prices()` invokes the registered base filter before adding calculated options. Unit and real Woo fake-API tests pass. |
| `convert_base_back()` and `get_original_product_price()` use original edit-context prices with shop tax display for formulas | `opf_formula_base_price` is registered and called by `Calculator::evaluate_formula()` when a product ID exists. Original tax-price helper and fixed-price/formula separation pass unit tests. The Woo fake-API formula `[price]` contributes the shop base of 10. |
| `set_variant_price()` supplies a browser base; footer script converts totals using current rate | `woocommerce_available_variation` supplies separate percentage and formula bases. Frontend jQuery variation/reset listeners use them, with a single final totals conversion. Unit tests and the isolated Chromium event harness pass. Real WOOCS variation lifecycle remains unverified. |
| `change_currency_info_on_frontend()` reads position, symbol, decimals, cents, separators | Frontend config filter and footer output normalize these settings. `Assets::enqueue_frontend()` emits the config when the API is present, and boot registers the adapter. Actual served product totals/config pass foreign/default/rate-change checks. |
| `set_product_base_price()` normalizes simple/subscription foreign preview bases independently of the multiple-currency cart gate, unless fixed pricing is active | OPF preview config uses `cart_base_price()`, whose normalization depends on that cart gate. A mismatch when multiple currency is disabled is reproduced below. |
| `change_product_choice_price()` rewrites linked-product choices | OPF has an original-price helper used by its formula bridge, but source search found no linked-product choice caller. A unit test of the helper does not establish linked-product integration. |
| `convert_pricing_hint()` is attached to WAPF's pricing-hint filter | OPF's `pricing_hint()` helper has direct unit coverage, but `init()` does not register it and source search found no renderer caller. Product/cart hint parity remains open. |

WAPF source:
`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended/includes/classes/integrations/class-woocs.php`.
Its SHA-256 is `08cf25aa8a6f2423a9425dbe4f0e330917b1ced76e9109f20b3ab3a49994503a`.
The installed integration registry's `WOOCS` → `Woocs` routing and official API
references are recorded in [FOX shared contract evidence](FOX-CURRENCY-CONTRACT.md).

Audited OPF bytes:

```text
includes/Service/WoocsIntegration.php 881f3621e6fefe515ce1bd8594407ec3dee5f77d9c7361a9ab20f1b47a8aadd6
assets/js/opf-frontend.js             720b46771e020f363c73bed66eb99d9322814fb2b5c5292ffa9ec876ead089f8
```

## Executed evidence

PHP 8.5.11 / PHPUnit 11.5.56: WOOCS focused tests pass **11 tests / 37
assertions**. Adding the FOX shared contract tests gives **15 / 46**. Suite
discovery reports one existing doc-comment metadata deprecation in the unrelated
`CapabilityFixtureRegistryTest`; there are no test failures.

```sh
/tmp/opf-archive-import/vendor/bin/phpunit -c phpunit.xml.dist --filter 'WoocsIntegrationTest|WoocsRuntimeTest'
WP_TEST_PATH=/tmp/opf-woocs-wp-e2e node bin/e2e-woocs-browser-test.mjs
```

The existing Chromium harness runs actual frontend JS and WordPress jQuery on
synthetic markup/config. It passes initial totals, variation/reset events,
fixed-vs-formula bases, right-space and decimal formatting, and empty separators
with no JS errors. It does not establish served WooCommerce variation behavior.

A fresh SQLite clone at `/tmp/opf-woocs-currency-audit-20261001` runs WordPress
7.1.2 / WooCommerce 11.1.0 and this checkout through the `opf-currency-audit`
plugin link. Active plugins are WooCommerce and OPF; WAPF stays inactive and
`class_exists('WOOCS')` is false. Cron and mail are disabled in the clone.
WOOCS is an explicit API/price-filter test double, not a real plugin.

`wp eval-file bin/e2e-woocs-cart-test.php --path=/tmp/opf-woocs-currency-audit-20261001`
passes its real Woo cart/order contract: base 10 plus fixed 3, percentage 1,
and formula 10 gives shop total 24; rate 2 gives 48; a second calculation stays
48; rate 3 gives 72; session restore retains base 10; an order line is 48;
default-currency switching returns 24. The order is created through Woo APIs,
not a checkout flow. Created cart-test fixtures are cleaned by the harness.

A separate HTTP-only fake and simple-product fixture exercise the actual served
page at `http://127.0.0.1:18837/?post_type=product&p=16067`. Chromium fills the
native inputs and reads served config and totals. Rate 2 shows product/options/
grand totals `20,00 €` / `28,00 €` / `48,00 €`; rate 3 shows `30,00 €` /
`42,00 €` / `72,00 €`; default USD shows `$10.00` / `$14.00` / `$24.00`.
The foreign grand total also passes at 390px width. Browser errors/warnings and
HTTP responses >=400 are empty. Desktop/mobile screenshots were inspected.

Retained disposable artifacts: `browser-currency.mjs`, `setup-currency.php`,
`compare-preview.php`, `served-results.json`, `served-desktop.png`, and
`served-mobile.png` in that clone. The fake does not implement native Woo price
currency symbols, payment selection, storage, or a real switcher; only OPF totals
and the named config contract are accepted from these HTTP checks.

## Reproduced mismatch and remaining acceptance

With the same simple product (edit price 10, converted view 20, EUR rate 2),
`woocs_is_multiple_allowed=0`, and no fixed-price metadata, direct invocation
of installed WAPF's `set_product_base_price()` returns **10**. OPF's preview
base path returns **20**. The served page confirms `product_base_price=20`,
`formula_base_price=10`, and product/options/grand totals **40 / 30 / 70 EUR**.
This is a diagnostic reproduction, not a parity pass: OPF converts an already
converted preview base a second time. The temporary comparison invokes only
the WAPF adapter in the disposable process; it does not activate WAPF or prove
its complete storefront/cart lifecycle.

Required work remains: separate preview normalization from the cart conversion
gate; connect and prove linked-product choices and price hints; verify real
WOOCS/FOX package version/hash, fixed regular/sale and subscription pricing,
multiple-currency modes, real variation events, taxed carts and displays,
sessions and switching, classic/Store API checkout and order persistence.
The passing fake contracts do not accept real-plugin parity or current WAPF
3.2.1 internals. No runtime fixes are included in this audit commit.

## Preview gate fix — 2026-10-01

The implementation based on `d0b46e7` fixes non-fixed simple/subscription preview
normalization when `woocs_is_multiple_allowed` is disabled. The frontend config
now uses the original shop-tax display price for that case, matching installed
WAPF's `set_product_base_price()` rule. The cart bridge and its multiple-currency
gate are unchanged. Default currency, enabled multiple-currency mode, variable
products, and fixed regular/sale-price bases retain their existing behavior.

Four new regression tests ran against the old code first: three failed on its
converted base, while the preservation test passed. With the fix, WOOCS tests
pass **15 / 56**; the combined WOOCS/FOX/Aelia gate passes **26 / 103** under
PHP 8.5.11 / PHPUnit 11.5.56. Coverage includes the disabled-mode simple base,
shop tax display, subscription/variable scope, default currency, and fixed
regular/sale-price gates. The same existing unrelated PHPUnit metadata
deprecation is reported. PHP syntax and Git diff checks pass.

`bin/e2e-woocs-cart-test.php` now asserts preview/formula bases of 10 in both
multiple-currency modes and cart bases of 20 (disabled) / 10 (enabled) before
its existing commerce assertions. In a fresh WordPress 7.1.2 / WooCommerce 11.1.0
SQLite clone at `/tmp/opf-woocs-gate-proof-20261001`, it passes unchanged shop
total 24, foreign 48, repeated 48, rate-change 72, session base 10, order 48,
and default-currency 24 results. The clone disables cron and mail and uses an
explicit fake API; the real currency plugin is absent.

Directly comparing both adapters in that clone gives original edit price 10,
converted view 20, WAPF preview **10**, OPF preview **10**, and OPF cart **20**
when multiple currency is disabled. The comparison runs the installed WAPF
adapter only, without activating WAPF.

Served Chromium at `http://127.0.0.1:18838/?post_type=product&p=16067` verifies
the actual emitted config and frontend module for five scenarios: enabled and
disabled modes both show product/options/grand totals **20 / 28 / 48 EUR**;
disabled mode with rate 3 shows **30 / 42 / 72 EUR**; default currency in both
modes shows **10 / 14 / 24 USD**. Disabled-mode foreign totals also pass at
390px width; desktop/mobile screenshots are retained. Browser errors/warnings,
failed HTTP responses, and script MIME errors are empty. The existing synthetic
Chromium variation/reset/fixed-price-formatting harness also passes.

Disposable artifacts in the new clone: `verify-preview.mjs`, `compare-fixed.php`,
`fixed-results.json`, `fixed-desktop.png`, and `fixed-mobile.png`. During setup,
a clone-directory prefix collision with the plugin's symlink path generated
incorrect Woo asset URLs; choosing a distinct clone directory resolved those
environment errors before the clean browser run. Final recorded check:
2026-10-01T17:56:51Z.

Fixed adapter SHA-256:
`84b3e51e0debb357e66c3d11a312dfdbde564b9bdfc30ed6a60bdf8c03beadd6`.
Frontend JS bytes remain the audited `720b4677...` hash above.
The row remains partial: linked-product choices, pricing hints, real-plugin
life cycles, taxes/fixed prices under that plugin, actual variation events,
and classic/Store API checkout acceptance still require proof.
