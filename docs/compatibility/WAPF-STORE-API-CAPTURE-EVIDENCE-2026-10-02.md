# WAPF 3.1.5 Store API add-to-cart capture evidence

## Result

Installed WAPF Extended 3.1.5 does not capture field values submitted with a
WooCommerce Store API product add. Its validation and cart capture read
`$_REQUEST['wapf_field_groups']` and `$_REQUEST['wapf']`; JSON Store API
parameters are available on the `WP_REST_Request`, not those globals. The
plugin registers no `woocommerce_store_api_add_to_cart_data` handler. On the
tested Store API add-item route, WAPF rejects an otherwise valid product add
with `Error adding product to cart.` and inserts no cart line.

OPF at base `d8a356e` captures its `opf_fields` request parameter through that
Store API filter. The matching OPF add returned HTTP-style status 201 and
stored the submitted value on the cart line. This is direct Store API route
dispatch evidence, not a browser test of the product-page block UI.

## Source evidence

The installed source was read from
`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`.
Its plugin header reports version 3.1.5. The digest of sorted per-file SHA-256
records for that source tree is
`b46705d67d1d6cb294f04c3bac9636767a8ed79cfcf8cff75f499172e19ef762`.
Key file hashes:

| File | SHA-256 |
| --- | --- |
| `includes/controllers/class-product-controller.php` | `87d4a25191ced322408e49b6ec5663a49a9ee82e7338e02d299c3d532460ba07` |
| `includes/controllers/class-linked-products-controller.php` | `38293fc4c39eb58002945488454a0233a88e65c1abe5cfa09c010db9a3de2e2d` |

Relevant WAPF source locations:

- `class-product-controller.php:29,33` registers classic Woo add validation
  and cart-item-data hooks.
- `class-product-controller.php:65,176-215` registers Store API cart-item
  response data (`apf.editLink`) after `woocommerce_blocks_loaded`; it does
  not register an add-request capture callback.
- `class-product-controller.php:341-383` validates field groups using
  `$_REQUEST['wapf_field_groups']`.
- `class-product-controller.php:408-445` builds cart data from
  `$_REQUEST['wapf_field_groups']` and `$_REQUEST['wapf']`.
- A recursive PHP source scan for `woocommerce_store_api_add_to_cart_data`
  found no WAPF occurrence. WAPF's `wapf/store_api/cart/*_callback` hooks are
  response schema/data filters, not request capture hooks.

WooCommerce 11.1.0's installed `src/StoreApi/Routes/V1/CartAddItem.php:116-127`
applies `woocommerce_store_api_add_to_cart_data` with the `WP_REST_Request`
then passes the result into `CartController::add_to_cart`. At OPF base
`d8a356e`, `includes/Service/CartIntegration.php:50,60-83` reads `opf_fields`
from that request and carries it as cart-item data. Its file SHA-256 is
`2a0900f9fa7bf27e9f8206f9760178fa7156dd19fade7c7c3f8849adb79a026f`.

## Disposable runtime proof

Both runs used cloned SQLite WordPress environments under `/tmp`, WordPress
7.1.2 and WooCommerce 11.1.0. The WAPF clone activated Free 1.7.1 plus
Extended 3.1.5; the OPF clone activated the OPF tree at `d8a356e` and disabled
WAPF. No production site or database was used.

Commands:

```sh
wp --path=/tmp/opf-store-api-wapf-20261002 eval-file /tmp/opf-store-api-wapf-probe.php
wp --path=/tmp/opf-store-api-opf-20261002 eval-file /tmp/opf-store-api-opf-probe.php
```

Each probe created a disposable virtual product and one required text/boolean
field, then dispatched `POST /wc/store/v1/cart/add-item` with a valid Store API
nonce and JSON-style request parameters. WAPF received `wapf_field_groups` and
`wapf[field_accept]`; the active WP request globals remained unset, as in a JSON
Store API request. OPF received `opf_fields`.

Observed results:

| Implementation | Route result | Cart result |
| --- | --- | --- |
| WAPF Extended 3.1.5 | Status 400, code `woocommerce_rest_add_to_cart_error`, message `Error adding product to cart.` | Empty cart; no `wapf` cart data |
| OPF at `d8a356e` | Status 201 | One cart line; `opf_fields[group_id].message` equals `captured by OPF`; Store API `item_data` displays the value |

## Scope limits

This proves request capture behavior on WooCommerce's Store API add-item route
using direct `WP_REST_Request` dispatch. It does not test a rendered product
block in a browser, WAPF's classic form path, or later WAPF releases. The
static scan supports the source-level absence finding; the 400/empty-cart
result is the separate runtime finding for this exact installed version and
clone configuration.
