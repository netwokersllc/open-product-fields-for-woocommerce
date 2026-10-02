# WAPF quantity-flat fixed fee lifecycle evidence — 2026-10-02

## Scope and runtime

This closes the `WAPF-PRICE-QUANTITY-FLAT` lifecycle gap: a WAPF Pro `qt`
fixed fee, which is added per product unit and therefore scales with product
quantity. The native WAPF Extended 3.1.5 runtime was compared with OPF's
native fixed choice using a disposable WooCommerce clone. No production site,
ledger row, or runtime product code was changed.

- Source under test: `9b2bcf8aae125086c52ce591e065ad2b95cb03de`.
- Runtime: WooCommerce 11.1.0, WordPress clone backed by SQLite, PHP 8.5.11,
  WAPF Extended 3.1.5, OPF loaded from this worktree.
- Clone: `/tmp/opf-quantity-fee-woo-20261002`.
- Fixtures: `bin/e2e-price-quantity-flat.php` and the authenticated browser
  fixture scripts in commits `ccd63b7` and `6d39b24`.
- Run: `OPF_QFL_PROOF_ALLOW=1 bin/run-e2e-price-quantity-flat-proof.sh`.
- Products: taxable, virtual products at $10.00; selected fee $0.335; requested
  quantities 1 and 3. Tax-exclusive prices, standard temporary 8.25% rate.
- Request paths: classic `WC_Cart::add_to_cart()` and the Woo Store API
  `POST /wc/store/v1/cart/add-item` route dispatched through the live
  WordPress REST server. No browser tabs were opened.

## Cart comparison

All eight runtime cases passed. Amounts below are the exact raw cart line
values returned by WooCommerce before the final currency display formatting.

| Request path | Engine | Qty | Unit price | Line subtotal | Line subtotal tax | Line total | Line tax |
|---|---|---:|---:|---:|---:|---:|---:|
| Classic | WAPF `qt` | 1 | $10.335 | $10.335 | $0.85 | $10.335 | $0.85 |
| Classic | OPF fixed per-unit | 1 | $10.335 | $10.335 | $0.85 | $10.335 | $0.85 |
| Store API | WAPF `qt` | 1 | $10.335 | $10.335 | $0.85 | $10.335 | $0.85 |
| Store API | OPF fixed per-unit | 1 | $10.335 | $10.335 | $0.85 | $10.335 | $0.85 |
| Classic | WAPF `qt` | 3 | $10.335 | $31.005 | $2.56 | $31.005 | $2.56 |
| Classic | OPF fixed per-unit | 3 | $10.335 | $31.005 | $2.56 | $31.005 | $2.56 |
| Store API | WAPF `qt` | 3 | $10.335 | $31.005 | $2.56 | $31.005 | $2.56 |
| Store API | OPF fixed per-unit | 3 | $10.335 | $31.005 | $2.56 | $31.005 | $2.56 |

WAPF cart metadata reports `price_type=qt`, `price=0.335`, and
`calc_price=0.335`. OPF cart data retains the selected `qtyflat` choice. Both
engines set the adjusted unit price to $10.335, so WooCommerce multiplies the
fee with the product quantity. The line subtotal remains at WooCommerce's
working precision: $10.335 at q=1 and $31.005 at q=3.

At 8.25%, Woo rounds tax on the full line: $10.335 × 8.25% = $0.8526375 →
$0.85; $31.005 × 8.25% = $2.5579125 → $2.56. Thus q=3 line tax is one cent
higher than tripling the separately rounded q=1 tax ($2.55). WAPF and OPF
matched at both quantities and on both cart paths.

## Checkout orders and authenticated order-again

The fixture created q=3 checkout orders for both engines. Both saved order
lines have quantity 3, line total $31.005, and line tax $2.56. Each Woo order
total is $33.57 after rounding the $31.005 line total plus $2.56 tax to
currency precision. WAPF persisted its `_wapf_meta` field selection and
`qt` pricing data; OPF persisted `_opf_fields` with the selected `qtyflat`
choice.

The authenticated browser follow-up used a separate phase fixture and actual
Chromium. It created a disposable customer and q=3 checkout orders for both
products, attached each completed order to that customer, signed in through a
loopback-only helper, opened each real My Account order page, and clicked its
visible **Order again** link with WooCommerce's nonce. The browser landed on
the cart and verified the restored “Quantity flat fee” selection. A
loopback-only cart probe read the server cart after WooCommerce had processed
the link:

| Engine | Restored choice | Qty | Line subtotal | Line subtotal tax | Line total | Line tax |
|---|---|---:|---:|---:|---:|---:|
| Native WAPF | `qtyflat` / `qt` | 3 | $31.005 | $2.56 | $31.005 | $2.56 |
| OPF | `qtyflat` | 3 | $31.005 | $2.56 | $31.005 | $2.56 |

The test uses `pre_wp_mail` in a clone-only MU plugin to intercept all
outgoing WordPress mail before completing source orders. Four mail calls were
short-circuited during order setup; the successful run artifact records
browser-phase interceptions as well, and records zero messages sent. Fixture
state is saved only after both checkout orders are created. Test addresses use the reserved
`.invalid` domain. Browser output: [`qfl-order-again-browser-results.json`](qfl-order-again-browser-results.json), with **23/23 checks passing**, an explicit completion marker and run ID, and no uncaught page errors. The browser result records the choice parsed from the actual cart item data.
Screenshots were saved outside the repository at
`/tmp/opf-qfl-order-again-wapf-cart.png` and
`/tmp/opf-qfl-order-again-opf-cart.png`.

The runner installs and removes the clone-only MU-plugin symlink, starts and
stops the loopback server, runs both fixtures, verifies the browser artifact,
and cleans up in its exit trap. Reproduction command, from this worktree:

```sh
OPF_QFL_PROOF_ALLOW=1 bin/run-e2e-price-quantity-flat-proof.sh
```

After the browser and verifier passed, cleanup removed the temporary products,
group, customer, completed orders, tax rate, cart, and saved test state, then
restored all tax options. The runner stopped its isolated server process group
and verified port 8207 closed; the fixture removed its exact-target MU-plugin
symlink. Post-cleanup queries found no test state, tax rate, QFL
products/groups, fixture orders, or test customer.

## Cleanup and result

The fixture's `finally` cleanup removed the temporary tax rate, both fixture
products, the OPF field group, both checkout orders, and cart data, then
restored the tax options. Successful run artifacts record the actual order IDs
and confirm that `wc_get_order()` returns no fixture orders, no temporary tax
rows/products/groups/customer or fixture state remain, the cart is empty, and
all original tax options (including shop/cart tax display) were restored. See
[`qfl-main-e2e-results.json`](qfl-main-e2e-results.json) and
[`qfl-order-again-run-results.json`](qfl-order-again-run-results.json); they
retain exact cart/order totals, metadata, plugin versions, suppressed mail
count, and cleanup outcomes.

Result: **supported with documented difference** for native WAPF Pro `qt`
fixed fee pricing through classic cart, Store API cart, checkout order
persistence, the authenticated My Account order-again button flow, line tax,
and the exercised quantity rounding case. OPF represents `qt` as a fixed
per-unit choice; WooCommerce line quantity supplies the equivalent scaling.
This proof covers WAPF Extended 3.1.5 and WooCommerce 11.1.0 only.
