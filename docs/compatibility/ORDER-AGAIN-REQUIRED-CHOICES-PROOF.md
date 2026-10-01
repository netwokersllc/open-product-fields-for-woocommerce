# Required select/radio order-again regression

Verified 2026-10-01; focused/full tests and runtime source equality were
rechecked after rebasing onto `a18ccfeba7e4fd9181098561a20bce107223db24`.
This proof covers required select/radio choices, current field definitions,
current option prices, simple products, and variations. It does not close
the wider edition, compatibility, or integration gates.

## Source and defect

Current integration base: `a18ccfeba7e4fd9181098561a20bce107223db24`.
Original defect reproduction source: `983c0542128a8ef27ac09db6486d34a8c759b50e`.
WooCommerce 11.1.0's `includes/class-wc-cart-session.php:589` restores cart
data with `woocommerce_order_again_cart_item_data`; line 617 passes that
data as validation argument six. Line 645 directly builds the restored
line. `includes/class-wc-cart.php:1294` passes product and variation IDs
to `woocommerce_add_cart_item_data`. Store API
`src/StoreApi/Utilities/CartController.php:335` also passes six validation
arguments. These installed WooCommerce sources are the hook contracts.

OPF previously registered only three validation arguments, so a required
select/radio reorder was validated against an empty submission. Its
attachment hook also used the parent product and did not recheck restored
values or refresh their base price.

The fix accepts six validation arguments and the variation ID for cart
attachment. Restored values are sanitized against current groups/fields
before validation and restoration. The structured image-quantity format
is explicitly converted back to quantities for that sanitization. Raw
Store API payloads retain precedence and are consumed as before.

## Actual runtime and source equality

Independent disposable clone: `/tmp/opf-order-again-required-wp`.
Loopback: `http://127.0.0.1:8173`.
WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11.
The SQLite `FQDB` runtime constant resolved to the independent database
`/tmp/opf-order-again-required-wp/wp-content/database/.ht.sqlite`.
Runtime reflection returned:
`/tmp/opf-order-again-required-20261001/includes/Service/CartIntegration.php`.
The runtime file and tested worktree file both had SHA256:
`3608e380cfd029df98639fa74f3a7b801c97ea5d5af0415bb211468f8cf38d9f`.
The incoming base includes coupon code in CartIntegration. A loopback
HTTP request to `/?opf_order_again_source=1` confirmed the loaded class
path and current source hash after the rebase; its timestamp and environment
are recorded in `order-again-required/http-runtime-provenance.json`.

The original SHA was separately extracted with `git archive` to
`/tmp/opf-order-again-required-baseline`; its CartIntegration SHA256 was
`b07e6287365d11e591a051ad26836fcc8bf0ae9e924000217b367c841352ebb9`.
Activating that snapshot in this clone reproduced an empty cart after
the real authenticated account order-again link. The baseline browser
command exited 1 on the required-selection assertion. The fixed plugin
was then reactivated. No production files or database were used.

## Results

- Focused PHPUnit: 14 tests, 37 assertions passed.
- Full PHPUnit: 264 tests, 1047 assertions passed. One existing runner
  deprecation concerns doc-comment metadata in CapabilityFixtureRegistryTest.
- Chromium fresh classic/Store API product additions and checkouts:
  31 checks passed, including invalid select/radio rejection on both
  simple and variation products.
- Chromium actual authenticated WooCommerce order-again links followed
  by classic/Store API checkout: 17 checks passed across four routes.
- Disabled current choice blocks real order-again on all four routes:
  9 checks passed.
- WooCommerce data-store reload of eight original/restored orders:
  40 checks passed for values, public labels, quantity, totals, and
  product/variation identity.
- Actual `WC_Cart::add_to_cart()` with restored cart data and no POST:
  8 checks passed for sanitization, current base/fees, and unrelated data.
- PHP syntax, JavaScript syntax, and `git diff --check` passed.

Quantity was two. Original simple/variation totals were 25/65. Before
ordering again, catalog bases changed from 10/30 to 12/35, the select
per-unit fee changed from 1.5 to 2, and the radio flat fee changed from
2 to 3. Restored orders correctly totaled 31/77.

## Reproduction commands

Run from `/tmp/opf-order-again-required-20261001`. Fixtures and the login
helper require the exact independent clone path and explicit fixture
guard. The helper only accepts loopback and suppresses outgoing mail.
The clone needs WordPress, SQLite integration, WooCommerce and this
worktree activated; the provided MU helper belongs in its `mu-plugins`.

```sh
vendor/bin/phpunit --filter 'OrderAgain(Validation|BasePrice)Test'
vendor/bin/phpunit --no-progress
OPF_ORDER_AGAIN_E2E_ALLOW=1 wp --path=/tmp/opf-order-again-required-wp eval-file bin/e2e-order-again-required.php
wp --path=/tmp/opf-order-again-required-wp server --host=127.0.0.1 --port=8173
curl --fail --silent 'http://127.0.0.1:8173/?opf_order_again_source=1'
OPF_ORDER_AGAIN_E2E_ALLOW=1 node bin/e2e-order-again-required-browser.mjs
OPF_ORDER_AGAIN_E2E_ALLOW=1 OPF_ORDER_AGAIN_PHASE=prepare wp --path=/tmp/opf-order-again-required-wp eval-file bin/e2e-order-again-required.php
OPF_ORDER_AGAIN_E2E_ALLOW=1 OPF_ORDER_AGAIN_PHASE=again node bin/e2e-order-again-required-browser.mjs
OPF_ORDER_AGAIN_E2E_ALLOW=1 OPF_ORDER_AGAIN_PHASE=verify wp --path=/tmp/opf-order-again-required-wp eval-file bin/e2e-order-again-required.php
OPF_ORDER_AGAIN_E2E_ALLOW=1 OPF_ORDER_AGAIN_PHASE=attach wp --path=/tmp/opf-order-again-required-wp eval-file bin/e2e-order-again-required.php
OPF_ORDER_AGAIN_E2E_ALLOW=1 OPF_ORDER_AGAIN_PHASE=retire wp --path=/tmp/opf-order-again-required-wp eval-file bin/e2e-order-again-required.php
OPF_ORDER_AGAIN_E2E_ALLOW=1 OPF_ORDER_AGAIN_PHASE=retired node bin/e2e-order-again-required-browser.mjs
```

The live captured artifacts are under
`/tmp/opf-order-again-required-artifacts`: browser-result JSONs, original
and restored order IDs, cart JSONs, cart screenshots, durable-order and
attachment result logs, unit results, and runtime provenance.

The compact browser results and server logs accompany this document.
Focused/full test logs and CLI/HTTP runtime provenance were refreshed
after the final rebase. Actual authenticated order-again/checkout,
disabled-choice rejection, durable order reload, and restored cart
attachment proofs were rerun against the current combined source.
The original fresh-entry and defect-baseline browser evidence remains
from the first isolated proof run; its original results are preserved.
Image-quantity preservation/current-limit rejection is unit-proven;
real image-quantity/repeater order-again, refunds/restock, older supported
WooCommerce versions and third-party integrations remain outside this
focused proof. This change is an isolated commit for integration review.
