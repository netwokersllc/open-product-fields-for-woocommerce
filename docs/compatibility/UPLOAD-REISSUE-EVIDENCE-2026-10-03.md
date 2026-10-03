# WAPF-FIELD-UPLOAD — secure order-again reissue evidence (upload lane)

Date: 2026-10-03 · Worktree: `/tmp/opf-lane-upload` (branch `lane/upload`) · Clone: `/tmp/opf-image-upload-wp` @ `http://127.0.0.1:8302`
Runtime: WP 7.1.2 · WooCommerce 11.1.0 · PHP 8.5.11 · PHPUnit 11.5.56 · sqlite-database-integration 3.0.2

## Design

WooCommerce rebuilds an order-again cart line straight from order meta and never
passes through `woocommerce_add_cart_item_data`, so a completed order's
session-bound upload tokens can never validate for a new session. WAPF Extended
3.1.5 solves this by re-linking the same public-tree file (`_wapf_meta` →
`uploaded_file` URL, protected only by `.htaccess`/index.php). OPF keeps its
stronger model and instead **copies the private bytes to a freshly minted token**:

- `includes/Service/UploadReissue.php` — hooks
  `woocommerce_order_again_cart_item_data` at priority 20 (after
  `CartIntegration::restore_order_again()` @10 repopulates the stored values; no
  CartIntegration change required).
- Per token leaf: the record must exist, be bound to *this* order, and match
  product/group/field scope — a smuggled or mismatched reference returns the
  original token, which then fails `add_to_cart_validation` closed.
- Source-order authorization mirrors `Uploads::download()`: upload owner,
  bound-order customer (`$order->get_customer_id() === get_current_user_id()`),
  or `manage_woocommerce`. WC's own `order_again` capability check has already
  run inside `populate_cart_from_order()`; the re-check keeps the file safe if
  the filter ever runs elsewhere.
- Retryable sources (`checkout-draft` or `needs_payment()`) skip reissue — the
  original token still validates for its owner session.
- `Uploads::reissue_token()` mints `bin2hex(random_bytes(32))`, copies private
  bytes (`0600`), records `owner = current session`, `order_id = 0`,
  `cart = false`, `reissued_from = <source>` under the same `.upload.lock`
  flock + site/session file-and-byte caps as a fresh upload. A live
  (unclaimed, unexpired, same-session) reissue of the same source is reused —
  repeated reorder clicks cannot multiply private bytes; once bound to a live
  order a reissue is never recycled.

## Results

| Proof | Result |
|---|---|
| `vendor/bin/phpunit --filter UploadReissueTest` | **15 tests / 44 assertions — OK** |
| `vendor/bin/phpunit` (full suite) | **370 tests / 1603 assertions — OK** (1 pre-existing PHPUnit deprecation in `CapabilityFixtureRegistryTest`, unrelated) |
| `php -l` on all changed PHP files | clean |
| Guarded CLI proof `bin/e2e-upload-reissue-proof.php` | **22/22 checks passed** (`reissue-proof.json`) |
| Playwright `bin/e2e-upload-browser-test.mjs` | **48/48 JSON checks + "no browser runtime errors" = 49 console PASSes** (`browser/browser-results.json`) |

### Guarded proof checks (22/22)

Admin save/reload; session owner derivation; completed-order order-again returns
a fresh 64-hex token; reissue session-owned/unbound/`reissued_from`; identical
copied bytes; original record stays claimed; reissued token validates; source
token cannot seed a cart; reissued token attaches to a real cart line; reorder
item persists only reissued tokens; record binds to the reorder; retryable-bound
reissue reused not duplicated; claimed reissue never recycled; second copy
unbound/session-owned; repeated order-again reuses live reissue; foreign session
denied; registered order customer reissues from a new session; shop manager
reissues; cross-order token denied; mismatched-field token denied; pending order
needs payment; retryable source token validates without copy.

### Browser lifecycle (49 console PASSes)

Full `admin → storefront → upload → cart → order → order-again → reorder` chain:
modern + native render, real PNG uploads (multi, drag-drop, spoof rejection,
caps, CSRF/origin), Store API + native classic carts, both checkouts, order meta
+ private perms + no public URL, authenticated order-again via the real
`p.order-again` button → restored line with fresh session-owned reissue → second
order binds only reissued tokens → customer downloads reissued bytes, foreign
session denied, manager download/delete with nonce gating, 0 runtime errors.

## WAPF reference comparison (source-observable, plugin inactive in clone)

- `class-product-controller.php:915 order_again_cart_item_data()` reconstructs
  raw values from `_wapf_meta`; file values re-link the stored
  `uploaded_file`/`uploaded_file_path` — same file, no copy, no token.
- `class-file-upload.php:62-92` stores bytes at
  `uploads/wapf_uploads/<field_id>/<md5(customer session id)>/` behind
  `.htaccess` + `index.php` only.
- OPF is strictly stronger: out-of-webroot private bytes, unpredictable 64-hex
  tokens, session ownership + order binding, fresh token per reorder.

## Incidents during this lane

1. Probe mu-plugin declared all 6 `woocommerce_add_to_cart_validation` args as
   required; the classic simple path passes 3 → HTTP 500 on native multipart.
   Fixed with optional args (`bin/e2e-upload-order-again-mu.php:12`).
2. Proof initially read the wrong `opf_fields` shape — corrected to
   `$data[CartIntegration::ITEM_KEY][$gid][$fid]`.
3. Retryable fixture order had zero total (`needs_payment()=false`); set
   subtotal/total + `calculate_totals()`.
4. Two stray upload records/bins from inline debugging removed; proof cleanup
   switched to baseline-snapshot-driven removal.

## Cleanup — final state equals baseline

Deleted orders 15110/15111/15113/15115, users 89 (proof) + 90 (uploadbuyer),
7 `opf_upload_*` options, all `.bin` + `.upload.lock` in the private dir,
product 15081, group 15082, lane mu-plugins, 4 post-baseline revisions + 1
trashed auto-draft. Verified: products 5, groups 7, pages 5, orders 250, users
1, options/mu-plugins/plugins identical, revisions 4, checkout page content
byte-equal to `checkout-page-7-original.txt`. `OPF_UPLOAD_PRIVATE_DIR` define
left in `wp-config.php` (points at the lane's private dir; runtime artifact).
