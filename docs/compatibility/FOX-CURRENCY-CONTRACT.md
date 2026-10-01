# FOX currency contract evidence

Checked 2026-10-01. Capability: `WAPF-CURRENCY-FOX`.

OPF's existing `OPF\Service\WoocsIntegration` accepts FOX's documented API.
There is no evidenced separate FOX API requiring a second adapter. This closes
the API-identification question, while real FOX commerce and browser acceptance
remains open. The capability is `partial`: the shared adapter has an executed
official API contract test, while real-package runtime acceptance remains open.

## Authoritative source evidence

Installed WAPF Extended reports version 3.1.5 in its plugin header at
`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php`.
Paths below are relative to that installed plugin. Inspected at
2026-10-01T14:27:21Z; this was source inspection, not plugin activation.

| Source | Observed contract |
| --- | --- |
| `includes/controllers/class-integrations-controller.php:18,82-88` | Registry maps class `WOOCS` to integration `Woocs`; `add_integrations()` instantiates it when `class_exists('WOOCS')`. There is no separate FOX entry. |
| `includes/classes/integrations/class-woocs.php:138-148,205-216,245-248` | Uses global `$WOOCS`, `current_currency`, `default_currency`, `get_currencies()`, and `back_convert($price, $rate, 8)`; back-conversion requires foreign currency and `woocs_is_multiple_allowed=1`. |
| Same adapter, `:219-235` | Fixed regular/sale pricing uses `woocs_is_fixed_enabled` and `_woocs_regular_price_{currency}` / `_woocs_sale_price_{currency}`. |
| Same adapter, `:36-105,107-118,155-183,251-280` | Reads currency formatting from its table; formula/linked-product bases read fresh edit-context product prices with shop tax display. Browser totals/hints multiply by the current rate. |

SHA-256 of inspected WAPF files:

```text
class-integrations-controller.php 288687c0fe4c8f553648f3103d7516ed03d5f5561b7f9a670896a11786584ee5
class-woocs.php                  08cf25aa8a6f2423a9425dbe4f0e330917b1ced76e9109f20b3ab3a49994503a
```

The author's [WordPress plugin listing](https://wordpress.org/plugins/woocommerce-currency-switcher/)
identifies FOX as the renamed WOOCS product. FOX's own
[APF compatibility instructions](https://currency-switcher.com/advanced-product-fields-for-woocommerce)
explicitly target WAPF's `class-woocs.php`. Its
[current currency](https://currency-switcher.com/function/woocs-current_currency)
and [default currency](https://currency-switcher.com/function/woocs-default_currency)
references expose those properties on `$WOOCS`. Its
[currency table reference](https://currency-switcher.com/function/woocs-get_currencies)
specifies `get_currencies($suppress_filters=false)` and the keyed rate/symbol/position
table. Its [back conversion reference](https://currency-switcher.com/function/woocs-back_convert)
specifies amount, rate, and decimal precision on `$WOOCS->back_convert()`.
These current official references support API equivalence for the surface the
WAPF adapter consumes; they do not prove every FOX feature or historical version.

WAPF's [Extended changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/)
records improved FOX formula compatibility in version 3.0 (2025-03-05).

## Executable proof and limits

`tests/Unit/FoxCurrencyContractTest.php` supplies a FOX-shaped fake through the
documented `$WOOCS` global. It exercises the existing adapter without a FOX-specific
class or bootstrap. Its currency rows match FOX's documented structure, including
entity symbols and omitted optional decimals/separators. It checks the active
rate/format, default filtering mode, back-conversion arguments and precision,
default-currency handling, and the multiple-currency gate. The fake's arithmetic
is a test double, not a captured FOX runtime result.

Reproduce from this checkout using the available PHPUnit installation:

```sh
/tmp/opf-archive-import/vendor/bin/phpunit -c phpunit.xml.dist --filter 'FoxCurrencyContractTest|WoocsIntegrationTest|WoocsRuntimeTest'
```

Checked with PHP 8.5.11 / PHPUnit 11.5.56: the new FOX test file passes
4 tests / 9 assertions; the combined FOX/WOOCS filter passes 15 tests /
46 assertions. The combined suite reports one pre-existing PHPUnit metadata
deprecation in `CapabilityFixtureRegistryTest`, discovered during suite loading.
The FOX-only run has no warnings or deprecations, and its PHP syntax check passes.

Remaining acceptance requires an isolated WooCommerce installation with a named
real FOX package/version: simple and variable products; fixed, percentage, and
formula add-ons; default/foreign currency switching; multiple-currency and fixed
regular/sale price gates; linked-product prices; tax display modes; repeated cart
recalculation/session restore; checkout/order totals; and browser totals/hints and
formatting. Record package version/hash, setup, requests, and actual results.

FOX's APF compatibility page warns about converting percentage amounts twice and
suggests percentage exceptions in WAPF's adapter/browser code. Installed WAPF
3.1.5 does not contain those suggested percentage hint exceptions. OPF's shop-base
normalization differs from that historical patch; API equivalence alone therefore
does not accept percentage preview/cart parity. Real FOX tests must verify exactly
one conversion, including fixed foreign prices and formula bases.
