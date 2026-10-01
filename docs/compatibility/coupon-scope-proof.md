# Percentage coupon scope proof

Status: partial, pending scope acceptance and the remaining lifecycle/integration gates.
This evidence does not update the capability ledger or establish OPF 1.0 parity.

Proof environment: disposable SQLite WordPress at `/tmp/opf-price-coupon-scope-wp`,
served only at `http://127.0.0.1:8091`. WordPress 7.1.2, WooCommerce 11.1.0,
PHP 8.5.11, OPF worktree rebased onto public commit
`c74d9600e18759cd1e9aea8eccb229011953b6e5`. Checks ran on 2026-10-01 UTC.

## Verified source contract

Installed WAPF Extended 3.1.5 source:
`wp-content/plugins/advanced-product-fields-for-woocommerce-extended/includes/controllers/class-integrations-controller.php:35-76`.
SHA-256: `288687c0fe4c8f553648f3103d7516ed03d5f5561b7f9a670896a11786584ee5`.

The native per-coupon checkbox stores `wapf_excl_addons=yes`. Its absence is
unchecked/default-off. The field is hidden for fixed coupons. Saving unchecked
deletes the metadata. Percentage calculation subtracts option unit price times
WooCommerce's eligible apply quantity before taking the coupon percentage.

WooCommerce 11.1.0 source:
`wp-content/plugins/woocommerce/includes/class-wc-discounts.php:354-421`.
SHA-256: `2c0647d04eb331ae093b756dfc9fe7c4c23c90ec3ab793aa96105269094847a6`.
WooCommerce supplies the original or sequentially reduced amount, applies the
eligible item limit, runs the discount filter in currency units, and rounds the
returned value. OPF consequently returns the unrounded base percentage rather
than prematurely flooring it. The callback uses WAPF's priority 1000.

OPF uses a distinct native form key `opf_excl_addons` and preserves WAPF's stored
metadata key, so an existing/imported WAPF coupon needs no metadata migration.
No global product-fields setting controls coupon scope.

## Verification

```sh
OPF_COUPON_E2E_ALLOW=1 wp --path=/tmp/opf-price-coupon-scope-wp eval-file bin/e2e-coupon-scope.php
node bin/e2e-coupon-admin-browser.cjs
/tmp/opf-archive-import/vendor/bin/phpunit -c phpunit.xml.dist --display-phpunit-deprecations
php -l includes/Service/Admin/CouponSettings.php
php -l includes/Service/CartIntegration.php
php -l bin/e2e-coupon-scope.php
php -l bin/e2e-coupon-admin.php
node --check bin/e2e-coupon-admin-browser.cjs
git diff --check
```

Real WooCommerce: 15/15 checks passed; see `coupon-scope-commerce-results.txt`.
This includes real WordPress WXR import/export; default unchecked and native
save/delete; classic checkout/order selection persistence; fixed-product and
fixed-cart coupons; two sequential coupons across three units; a one-item limit;
and fractional discount rounding ($3.337 becomes $3.34). Tax-inclusive Store API
add-item, apply-coupon, and checkout routes preserved gross subtotal $132, gross line/order total $121,
net discount $10, discount tax $1, and remaining tax $11.

Authenticated Chromium: 10/10 checks passed; see
`coupon-scope-browser-results.json` (2026-10-01T23:44:32.322Z).
The real coupon editor POST saved/reloaded the checked and unchecked states.
Type changes hid/showed the control and retained its selection. The checkbox
has a native accessible label and responds to keyboard Space. Desktop 1280x960
and mobile 390x844 screenshots were inspected. No uncaught page errors occurred.
Screenshots remain in `/tmp/opf-coupon-admin-artifacts/`:
`percent-checked.png`, `percent-unchecked.png`, `mobile-keyboard.png`.

Unit suite: 254 tests, 1017 assertions passed. One pre-existing PHPUnit
deprecation remains in `CapabilityFixtureRegistryTest` for docblock metadata.
All scoped PHP/JavaScript syntax checks and `git diff --check` passed.
A disposable copy with an intentionally false assertion returned exit code 1,
reported one failure, and cleaned up its fixtures, proving failure propagation.

## Remaining proof

- Order-again, refund, and stock/restock behavior with scoped coupons.
- Coupon behavior with live currency/discount integration plugins and mixed cart
  contents, including negative option prices (negative price arithmetic has unit
  coverage only).
- Supported WordPress/PHP/WooCommerce version matrix, upgrade/uninstall, and
  translations; concurrent WAPF/OPF administration is not proved.

The proof scripts require explicit opt-in, WP-CLI, loopback URL, and the SQLite
drop-in; they restore options and remove created fixtures. Browser test users
and coupons are removed. Production and protected tabs markup were untouched.
