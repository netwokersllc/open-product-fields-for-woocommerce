# commtax lane evidence — COMMERCE-TAX / COMMERCE-WEIGHT / CURRENCY / COUPON-SCOPE

Disposable clone `/tmp/opf-image-commtax-wp` (loopback SQLite, WooCommerce 11.1.0, PHP 8.5.11).
Reference: installed WAPF Extended 3.1.5, activated only during the reference fixture.
All fixtures self-clean via `register_shutdown_function`; baseline re-verified after runs.

## Fixtures

| Fixture | Runs | Result |
| --- | --- | --- |
| `bin/e2e-commtax-wapf-reference.php` | live WAPF 3.1.5 (OPF deactivated) | 19/19 checks — `commtax-wapf-reference.json/.log` |
| `bin/e2e-commtax-commerce.php` | OPF cart/order/order-again | 27/27 checks — `commtax-commerce.json/.log` |
| `bin/e2e-commtax-currency.php` | OPF + fake WOOCS/FOX/Aelia APIs | 34/34 checks — `commtax-currency.json/.log` |
| `bin/e2e-coupon-scope.php` (existing) | OPF coupon lifecycle | 15/15 checks — `coupon-scope.log` |

All fixtures are guarded to the owned clone (`OPF_COMMTAX_ALLOW=1`, FQDB under the
clone, SQLite drop-in, loopback host) and refuse to run against real plugin APIs.

## WAPF-COMMERCE-TAX — proven (implementation parity), one display bug documented

WAPF 3.1.5 folds the addon total into the Woo line price
(`class-product-controller.php::add_prices_to_cart_item` sets
`base + options_total`), so Woo taxes the merged line at the product/variation
tax class — proven live: merged 110 → line tax 11 at 10 %, 0 on non-taxable.

OPF does the same (`CartIntegration` + `set_price(base + addons)`), proven:

- standard rate: merged unit 110, line subtotal 220, line tax 22, order persists
  line total tax 22 and `_opf_fields`, order-again recomputes 22
- reduced-rate class (`reduced-rate` 5 %): line tax 5.5 ✓
- non-taxable product: 0 ✓
- variation tax class: 5 % × 90 = 4.5 ✓
- inclusive mode (`woocommerce_prices_include_tax=yes`): merged gross 120 →
  10.91 tax inside ✓

**Bug documented — `includes/Service/Renderer.php::render_totals()`:**
`$data_tax` is initialised to `1` and the conditional branch also assigns `1`,
so `data-tax="1"` is emitted unconditionally. WAPF's equivalent
(`Helper::get_tax_multiplier`) returns the product's real tax multiplier (1.1 for
the 10 % fixture). The totals element therefore under-reports the tax factor for
taxable products on the JS display path. Fixture check:
`data-tax='1'` while WAPF-equivalent = 1.1. Also documented: the OPF pricing
hint renders the raw amount without `maybe_add_tax`-style display conversion
(WAPF converts hint amounts for shop incl/excl display). Not fixed — fix lives
in Renderer/pricing-display region outside this lane's allowance
(`AeliaIntegration`/`WoocsIntegration` only).

## WAPF-COMMERCE-WEIGHT — reference proven, OPF gap documented

WAPF 3.1.5 live on clone proves the reference contract:

- select choice `weight: '0.5'` + number field `weight: '[x]'` with x=4 →
  item weight 2.0 + 0.5 + 4 = 6.5, cart weight 19.5 at qty 3
- `weight: '[qty]'` choice → substitutes line quantity: 2.0 + 3 = 5.0
- virtual product: weight untouched
- negative sum floors at 0 (`max(0, …)`)
- 3.1.5 semantics are `floatval(str_replace('[qty]','[x]'))`, NOT arithmetic:
  `'[x]*0.5'` → `'4*0.5'` → `floatval` → 4.0 (expression evaluation is a 3.2
  feature)
- order lines carry field meta (`Packaging` etc.); weight itself is cart-scope

OPF gap proven (not fixed — implementation absent, owned by core field/pricing
lanes):

- `Field` normalisation drops `weight` metadata entirely (no `weight` key in
  field or choice output)
- `Calculator` exposes 24 formula functions; no weight function
- OPF cart item weight stays at product base (2.0 where WAPF yields 2.5);
  `get_cart_contents_weight()` = 6.0 for qty 3

**Reference-side notes for future fixtures:** WAPF prices/weights only on the
first `woocommerce_before_calculate_totals` firing per request
(`did_action() > 1` early-return in `class-product-controller.php:617` and
`$weight_was_calculated` in `class-extended-controller.php:378`), and
`WC_Cart::add_to_cart()` itself fires that action once. Multi-scenario fixtures
must reset both guards before each add and must not recalc after (weight is
additive to the mutated product object).

## WAPF-CURRENCY-WOOCS — adapter contract proven on real Woo lifecycle

15 checks with a WOOCS-shaped fake (`$GLOBALS['WOOCS']` + `woocs_is_multiple_allowed`):

- preview/formula bases stay shop-priced under multiple=0 and multiple=1;
  cart base gate 20 vs 10 matches WAPF's multiple-currency gate
- shop-currency cart target 24 (base 10 + addons 14); foreign view 48 at rate 2;
  line subtotal carries converted addons
- repeat totals stable; rate change 2→3 recomputes 72; session base stays 10
- order line persists converted 48; switching back to default restores 24

## WAPF-CURRENCY-FOX — same shared adapter, FOX-shaped API proven

7 checks with a FOX-shaped `$WOOCS` (`get_currencies(bool $suppress_filters)`,
`back_convert(amount, rate, decimals)`):

- currency table feeds frontend config (rate 0.89, `&euro;`, left_space format)
- `get_currencies` invoked with documented default flag
- shop base 24 → foreign view 21.36 (×0.89), `back_convert` restores 24
- order line keeps converted total

## WAPF-CURRENCY-AELIA — adapter contract proven on real Woo lifecycle

11 checks with `WC_Aelia_CurrencySwitcher` + `wc_aelia_cs_convert` fakes:

- foreign cart target 48 (24 × rate 2) incl. addons; repeat totals stable;
  rate change recomputes 72; session base 10; order line 48; default currency
  restores 24
- fixed foreign price path: `(50/2 base + 3 fixed + 2.5 percent + 10 formula)
  ×2 = 81` — manual foreign prices respected while addons convert
- variation bases 15/15; order-again re-derives base from catalog price

Commercial plugins unavailable — fakes prove adapter contracts only.

## WAPF-PRICE-COUPON-SCOPE — proven

- `CartIntegration::base_only_percent_discount` ≡ WAPF
  `recalculate_coupon_discount` on the reachable 72-case matrix
  (`base ≥ 0, addon ≥ 0, discounting = adjusted × apply_qty`); OPF additionally
  clamps into `[0, discounting_amount]` for degenerate input (defensive only —
  unreachable in the real flow)
- live cart: unscoped percent discounts base+addon (12), scoped (`wapf_excl_addons=yes`)
  discounts base only (10); order persists scoped discount (line 110, discount 10)
- existing `bin/e2e-coupon-scope.php`: 15/15 — WXR import/export preserves the
  WAPF meta key, classic checkout + taxed Store API order lifecycle, sequential
  coupons, usage limits, fractional rounding, fixed-type coupons unchanged

## Cleanup verification

- 0 commtax posts / tax rates / coupons / order items; 0 `opf-` coupons
- tax options restored (`calc_taxes=no`, `prices_include_tax=no`,
  `tax_display_shop=excl`, currency USD); `woocs_*` options removed
- `wordpress-importer` (installed for the WXR coupon proof) uninstalled+deleted
- active plugins identical to baseline: OPF active, WAPF inactive
- PHPUnit: 440 tests / 1854 assertions — all pass (1 PHPUnit deprecation)

## Remaining gaps (integrator notes)

- `Renderer::render_totals()` dead `data-tax` branch — real tax multiplier not
  emitted (display-side only; cart/order math correct)
- pricing hint has no tax-display conversion vs WAPF `maybe_add_tax`
- weight feature absent in OPF entirely (field schema + runtime + formula fn)
- real WOOCS/FOX/Aelia packages and browser-rendered currency/tax totals
  remain unverified
