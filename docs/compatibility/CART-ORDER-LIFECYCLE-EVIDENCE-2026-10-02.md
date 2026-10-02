# Classic and Blocks cart-to-order lifecycle evidence

`WAPF-LIFECYCLE-CART-ORDER` passes its stated contract, “Classic and blocks survive to order.” No product code change was required. This evidence does not change the ledger or roadmap.

Executed on 2026-10-02, with browser proof completing at **06:59:50 UTC** and durable order proof at **07:00:20 UTC**, against source base `da5632fe0325c3d92459f445285dc41441e21e50` in `/tmp/opf-cart-order-lifecycle-20261002`. The dedicated disposable WordPress clone is `/tmp/opf-cart-order-woo-20261002`, served only at `http://127.0.0.1:8195`. Versions: WordPress **7.1.2**, WooCommerce **11.1.0**, PHP **8.5.11**, Twenty Twenty-Five, actual Chromium through Playwright. Mail is intercepted by `pre_wp_mail`; only generated bodies were inspected.

## Source audit

Installed WAPF Free **1.7.1**, `advanced-product-fields-for-woocommerce/includes/controllers/class-product-controller.php`, registers Classic cart capture at line 38, cart/checkout display at line 41, and order-line persistence at line 44. `add_fields_to_cart_item()` at line 293 stores submitted field values. `display_fields_on_cart_and_checkout()` at line 374 supplies Woo item data. `create_order_line_item()` at line 184 writes public label/value metadata and hidden `_wapf_meta`. Inspected file SHA-256: `e5f6d95de4374543c98f4fc02818cc6311168f43ab0078b122a4da44b0caea15`.

OPF uses `CartIntegration::attach()` and `capture_store_api()` to capture both transports, `display_item_data()` for the shared `woocommerce_get_item_data` filter, and `persist_order_item()` for `woocommerce_checkout_create_order_line_item`. WooCommerce's installed `src/StoreApi/Schemas/V1/CartItemSchema.php:170` consumes that same display filter. `src/StoreApi/Utilities/OrderController.php:839` calls Woo's `create_order_line_items()`, which fires the persistence hook in `includes/class-wc-checkout.php:598`.

The committed earlier [email lifecycle evidence](EMAIL-FIELD-LIFECYCLE-EVIDENCE.md) already exercised actual Classic and Block checkout, plus durable order reloads; [text lifecycle evidence](TEXT-FIELD-LIFECYCLE-EVIDENCE.md) covered Block cart/checkout display and Store API checkout. Its cart source differs from this base only in equivalent repeater arrow-function versus PHP 7.1 closure syntax. The fresh run below proves this base directly. [HTTP source provenance](cart-order-http-source.json) reports the actual served source path and hashes; [durable results](cart-order-durable-results.json) reports the same current source hashes.

Current SHA-256 values:

| File | SHA-256 |
|---|---|
| `includes/Service/CartIntegration.php` | `a8f6af2b585eec20f9788c6b662f5ace7410cc47fd959197c659bcaf7c4c8922` |
| `includes/Service/Renderer.php` | `0e5914cdc8472f863f37475ca6b18c9153c1a094488371ea7aa8134d3d759b35` |
| `includes/Engine/FieldValue.php` | `cb2cd1bb007de47db3022cff33fd1a3b634547c82703eca025e3171fd205030a` |
| `includes/Engine/FieldGroup.php` | `8e0275a55f18bf22a02b8fa28760ed319a677bb2f5fcd645366bef0ad54fbcfd` |

## Fresh real lifecycle proof

[Browser results](cart-order-browser-results.json): **23/23 checks pass**, zero uncaught page errors. Each path uses a separate browser context, actual WordPress/WooCommerce routes, native product controls for Classic add, actual cart and checkout pages, and real order confirmation. No request mocks or synthetic HTML were used.

| Add transport | Checkout | Reloaded order |
|---|---|---|
| Classic native product form | Classic shortcode checkout, actual `wc-ajax=checkout` | `9248237` |
| Actual Store API `cart/add-item` | Actual Checkout Block | `9248238` |
| Classic native product form | Actual Checkout Block | `9248239` |

Every path verifies both the actual Classic shortcode cart and actual Cart Block DOM. Each preserves eight fields: text, textarea, email, URL, toggle, select, radio, and multiple checkbox choices. Cart and checkout display keep their labels and selected choice labels; confirmation keeps all labels and values. Quantity is **2**. Base price is **$10**, with a **$2 fixed fee once per line**, so the unit price is **$11** and the line/order total is **$22**.

[Durable Woo results](cart-order-durable-results.json): **46/46 checks pass**. A separate `wp eval-file` process reloads each actual checkout order with `wc_get_order()` and verifies exactly one native Woo product line, all eight exact `_opf_fields` values, all eight exact public label/value metadata entries, quantity, price, stored field snapshot, and generated HTML/plain email content. Ampersands remain exact in stored metadata and escape correctly in generated HTML email. Native Classic textarea submission stores CRLF; Store API JSON stores LF; both persist their exact transport values.

The only failures during harness preparation were incorrect expected fixed-fee quantity semantics, Chromium response-body retrieval after Classic navigation, and an LF expectation for native Classic CRLF. Expectations were corrected to the authoritative source and native wire format; checkout evidence uses successful HTTP response, real order-received navigation, and independent durable order reload. No product failure was suppressed.

## Reproduction and artifacts

The disposable audit scripts and full cart payloads/screenshots are retained in `/tmp/opf-cart-order-woo-20261002`:

```sh
wp --path=/tmp/opf-cart-order-woo-20261002 server --host=127.0.0.1 --port=8195
node /tmp/opf-cart-order-woo-20261002/cart-order-browser.mjs
wp --path=/tmp/opf-cart-order-woo-20261002 eval-file /tmp/opf-cart-order-woo-20261002/cart-order-verify.php
```

`cart-order-setup.php` creates the disposable fixture. `state.json` identifies product `9248232`, group `9248233`, and Classic cart page `9248234`. Each `*-cart.json` records the actual Store API cart. Each `*-classic-cart.png`, `*-blocks-cart.png`, and `*-order.png` records actual browser output. The Store API-to-Blocks Cart Block screenshot was visually inspected. The fixture and three final orders remain in the disposable clone for independent inspection. Earlier harness checkout attempts can also remain in that clone.

## Acceptance boundary

No missing path was found within this row's stated cart/checkout display and order-save contract. This evidence accepts the common integration across Classic and Blocks. Field-specific validation/import parity, every advanced field/edition, native block product-form controls, cart editing, visibility settings, repeaters, stock/refunds, order-again, and the platform/theme matrix retain their separate acceptance rows. No production files/data, unrelated browser sessions, protected tabs, ledger, or roadmap were changed.
