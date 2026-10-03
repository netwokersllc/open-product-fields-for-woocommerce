# Upload field order-again behavior

Base: public `feat/opf-archive-import` commit `ad95c3cecf1f02776fd66cab987654de33f2385c`.

## Result

The uploaded files pass OPF's normal upload, add-to-cart, and order-save path. WooCommerce's real authenticated **View order → Order again** action does not restore the cart line when that line contains an OPF upload from the completed order. WooCommerce's add-to-cart validation rejects the prior private token, and the cart is empty after the action. The rejection is caused by both ownership boundaries: the fresh authenticated session does not own the upload token, and the token remains bound to its completed order.

The clone-only validation probe confirmed all of the following during the real action: OPF received upload tokens; the original token record still exists; the current session does not own it; it is bound to a completed order; OPF rejects the add; and the error is “An uploaded file is unavailable. Please upload it again.” The original order metadata and private bytes remain bound to the original order. This preserves upload privacy, but leaves a functional difference: Woo's default order-again route does not return the shopper to the product form to upload again.

No product code change is included. Supporting this flow safely would need an explicit copy/reissue contract for private bytes, gated by ownership of the source order and current upload-field rules, with bounded storage, expiry, and cleanup for abandoned reorder attempts. Reusing the completed order's token would cross both session and order boundaries.

## WAPF Extended 3.1.5 source comparison

Read-only inspection of the installed 3.1.5 source at `/tmp/opf-image-wp/wp-content/plugins/advanced-product-fields-for-woocommerce-extended` found:

- `includes/classes/class-file-upload.php` stores accepted files below the public WordPress uploads tree, scoped by field and an MD5 of the WooCommerce customer/session ID. It adds each upload's URL/path to the field value.
- `includes/controllers/class-product-controller.php` serializes file field values into `_wapf_meta`; its `order_again_cart_item_data()` reconstructs the WAPF cart field from those stored values.
- That order-again path does not use OPF's private session-owner or completed-order token claim checks.

WAPF was installed but inactive in the disposable clone, matching its initial state. This is a source comparison; this lane did not run a WAPF file-upload browser checkout/order-again transaction. No runtime WAPF parity claim is made.

## OPF runtime proof

The lane used a SQLite backup of `/tmp/opf-image-wp` at `/tmp/opf-upload-order-again-wp`; no source clone, production database, or deployed plugin was modified. Runtime was WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11. OPF was the only product-fields plugin active. A separate private upload directory was used at `/tmp/opf-upload-order-again-private`.

The existing `bin/e2e-upload-browser-test.mjs` ran in order-again mode against the real local WordPress server at `http://127.0.0.1:8265` and passed **37/37** checks, including:

- accepted two real PNG uploads; rejected a `.png` containing PHP bytes and a forged malformed Store API token;
- created an actual Store API order and verified its `_opf_fields` and `_opf_uploads` metadata;
- fetched exact uploaded PNG bytes as the authorized customer; denied a foreign session and invalid/nonced customer requests; and verified the public uploads URL did not serve the private PNG bytes;
- completed the authenticated WooCommerce order-again action and verified OPF rejected the existing token before WooCommerce restored the cart line;
- verified manager download/deletion controls, removed the order's upload references/bytes, and observed zero browser page errors.

The public URL probe encountered WordPress's canonical trailing-slash redirect (301) and then a themed HTML soft-404 (200); it did not return the uploaded PNG bytes. The test compares served bytes and MIME, rather than treating that WordPress soft-404 status as proof of exposure.

The exact sanitized browser results are in [upload-order-again-results-2026-10-03.json](upload-order-again-results-2026-10-03.json). Test-only validation instrumentation is in `bin/e2e-upload-order-again-mu.php`; install it only in a disposable clone. The browser harness change also uses a DOM click for the test-only remove/submit actions because the Twenty Twenty-Five product image overlays those controls in pointer hit-testing.

## Cleanup and verification

After the browser proof, scoped cleanup verified the fixture product/group/order, test users, probe option, test-only MU plugins, and private upload root were absent; order count returned to the clone baseline of 250, and active plugins matched the clone baseline. The test's checkout page updates left clone-only revision/autodraft rows, so I removed the entire disposable clone rather than leave its modified database behind. The separate source clone `/tmp/opf-image-wp` was not modified. The local server on port 8265 was stopped, and the private upload directory was absent.

Reproduction after creating the existing upload fixture in a fresh isolated clone:

```sh
cp bin/e2e-upload-order-again-mu.php "$OPF_WP_PATH/wp-content/mu-plugins/opf-upload-order-again-probe.php"
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
OPF_UPLOAD_ORDER_AGAIN_ONLY=1 OPF_BASE_URL=http://127.0.0.1:8265 \
OPF_WP_PATH="$OPF_WP_PATH" OPF_UPLOAD_PROOF_DIR=/tmp/opf-upload-order-again-proof \
OPF_PRODUCT_ID="$OPF_PRODUCT_ID" OPF_GROUP_ID="$OPF_GROUP_ID" \
node bin/e2e-upload-browser-test.mjs
```

`node --check bin/e2e-upload-browser-test.mjs`, `php -l bin/e2e-upload-order-again-mu.php`, and `git diff --check` pass. No PHPUnit run was needed because product code did not change.
