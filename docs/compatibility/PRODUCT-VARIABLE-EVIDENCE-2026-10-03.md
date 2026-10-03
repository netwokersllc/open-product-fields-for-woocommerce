# WAPF-PRODUCT-VARIABLE — evidence

Clone `http://127.0.0.1:8323` (`/tmp/opf-image-uploadui-wp`); OPF pointed at
`/tmp/opf-lane-prodtypes`. Real WooCommerce 11.1.0 lifecycle + real Chromium.

## What the row note said was missing

`docs/compatibility/WAPF-CAPABILITY-LEDGER.md` line 282 ends with:

> Gap: no `product_var` rule subject — all rules resolve against parent id
> (variations lane implementing).

That gap is stale: `product_var`/`var_att` placement subjects and the
per-field `action=var` gates landed in `99a5602` (present at this base).
This lane re-proves the behaviour end-to-end and adds the two paths the row
did not cover: **variation switching price recalculation** and
**order-again**.

## 1. Variation-specific visibility + placement + cart + order meta

`bin/e2e-variation-rules.php` (fixture: variable product, Red/Blue, groups
scoped by `product_var` in/not_in and `var_att` in/wildcard; WAPF 3.1.5
reference uses the same rule model).

| Phase | Log | Checks |
| --- | --- | --- |
| setup | `vrules-setup.log` | 10 ok |
| assert | `vrules-assert.log` | **37 ok** |
| order | `vrules-order.log` | 6 ok |

Server assert proves, per product object:

- parent matches all variation-scoped groups and each carries exactly one
  generated `var` gate with no baked context;
- Red variation matches `vOnlyRed`/`vAttrRed`/`vAttrAny`, Blue does **not**
  match `vOnlyRed`;
- strict per-variation gate hides `vAttrRed` on Blue while `vAttrAny` stays
  visible;
- a simple product matches only the control group;
- posted `variation_id` drives the gate for the parent (`red` passes, `blue`
  fails, none hidden).

Order phase proves the Red variation add-to-cart keeps both scoped values on
the cart line and in the order-item `_opf_fields` meta (`order.json`).

Browser (`bin/e2e-variation-rules-browser.mjs`): **18 checks, 0 page errors**
(`browser-results.json`, `storefront-*.png`, `cart-red.png`) across
Red→Blue→Red, strict attribute hiding, add-to-cart POST payload and cart.

## 2. Variation switching price recalculation + order-again

`bin/e2e-variable-residual.php` + `bin/e2e-variable-residual-browser.mjs`
(fixture: Red base 10 / Blue base 20, one select field priced +5).

Server (`residual-server-results.json`, 11 checks):

- Red base 10, Blue base 20;
- cart line recalculates Red → **15.00**, Blue → **25.00**, variation ids kept;
- order item persists `_opf_fields`;
- `woocommerce_order_again_cart_item_data` restores the selection **and** the
  current variation base (`opf_base_price = 10`).

Browser (`residual-browser-results.json`, 5 checks, 0 errors; totals made
visible via `opf_show_totals=yes` for the run only):

| Selection | product total | options | grand |
| --- | --- | --- | --- |
| Red | `$10.00` | `$5.00` | `$15.00` |
| Blue | `$20.00` | `$5.00` | `$25.00` |
| reset | `$10.00` | `$5.00` | `$15.00` |

`found_variation` swaps `product_base_price`/`formula_base_price` to the
variation value and `reset_data` restores the parent base
(`assets/js/opf-frontend.js:2247-2259`).

## Cleanup

`vrules-cleanup` restored all dirty baselines (products, variations, groups,
wapf_posts, orders, users, attributes, plugins); `residual-cleanup.json`
restored products/variations/groups/orders/attributes and the
`opf_show_totals`/`opf_price_summary_mode` options.

## Still unverified (row caveats, not the named gap)

- Active-WAPF interop: the installed 3.1.5 parser fatals on this clone's
  pre-existing `wapf_product` data, so the OPF run deactivates it.
- Store API / block checkout add flow (this lane exercised the classic
  `woocommerce_add_to_cart_validation` + `WC_Cart::add_to_cart` path).
- Tax-inclusive/exclusive preview parity on variable products.

These remain open; the specific `product_var` gap is closed.
