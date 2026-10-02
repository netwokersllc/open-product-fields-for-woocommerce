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
- Fixture: [`bin/e2e-price-quantity-flat.php`](../../bin/e2e-price-quantity-flat.php).
- Run: `OPF_QFL_E2E_ALLOW=1 wp --path=/tmp/opf-quantity-fee-woo-20261002 eval-file bin/e2e-price-quantity-flat.php`.
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

## Checkout orders and order-again

The fixture created q=3 checkout orders for both engines. Both saved order
lines have quantity 3, line total $31.005, and line tax $2.56. Each Woo order
total is $33.57 after rounding the $31.005 line total plus $2.56 tax to
currency precision. WAPF persisted its `_wapf_meta` field selection and
`qt` pricing data; OPF persisted `_opf_fields` with the selected `qtyflat`
choice.

Order-again was exercised through WooCommerce's `populate_cart_from_order()`
population method, then recalculated from the restored cart data. Both lines
returned at q=3, $31.005 subtotal, and $2.56 tax. The disposable pending order
was temporarily associated with the clone's seeded administrator account
and the valid order-again statuses filter admitted its transient `pending`
status. This avoids a status transition and customer email during the test;
the web account flow/button was not browser-tested.

## Cleanup and result

The fixture's `finally` cleanup removed the temporary tax rate, both fixture
products, the OPF field group, both checkout orders, and cart data, then
restored the tax options. A post-run query observed zero temporary tax rows,
zero QFL products/groups, no orders 58/59, zero cart items, and restored
options (`woocommerce_calc_taxes=no`, `woocommerce_prices_include_tax=no`,
`woocommerce_tax_display_shop=excl`, `woocommerce_tax_based_on=shipping`).

Result: **supported** for native WAPF Pro `qt` fixed fee pricing through
classic cart, Store API cart, checkout order persistence, Woo order-again cart
population, line tax, and the exercised quantity rounding case. This proof
covers WAPF Extended 3.1.5 and WooCommerce 11.1.0 only.
