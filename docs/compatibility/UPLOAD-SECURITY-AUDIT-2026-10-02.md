# Upload security audit — 2026-10-02

Isolated worktree `/tmp/opf-upload-security-review-20261002`, HEAD base
`d8a356e`. Runtime proof on disposable clone `/tmp/opf-upload-security-wp`
(WP 7.1.2, WooCommerce 11.1.0, SQLite, `OPF_UPLOAD_PRIVATE_DIR` =
`/tmp/opf-upload-security-private`). No production resource was touched.

## Scope verdicts

| Area | Result |
| --- | --- |
| Upload capture (`receive`, `native`, Store API `capture_store_api`) | Sound: `is_uploaded_file` on the REST path, same-origin + 64-hex session nonce, field must resolve to a live publishable product/group/field (`includes/Service/Uploads.php:189-200, 248-297`). |
| Ownership/session binding | Sound: owner = `hash_hmac(sha256, WC session customer id, wp_salt)`; tokens validated against product, group, field, owner, TTL (`Uploads.php:161-164, 321-331`). |
| Classic + Store API cart/checkout validation | One defect found and fixed (below). `woocommerce_check_cart_items` fires on both checkouts (`WC_Checkout::check_cart_items`, `CartController::validate_cart` → `Checkout.php:421/577`); `woocommerce_checkout_create_order_line_item` and `woocommerce_new_order_item` fire on both paths. |
| Download permissions | Sound: session owner, bound-order customer, or `manage_woocommerce`; private/no-store + `nosniff`; streamed via `rest_pre_serve_request` (`Uploads.php:401-411`). |
| Deletion/retention | Sound with residual risk noted below. `remove` refuses cart-bound files and records for extant orders; `cleanup` retains files for extant orders (including retryable drafts) and reaps expired stale-order references; `delete_order_uploads` is nonce + `manage_woocommerce` gated (`Uploads.php:105-126, 393-399, 438-460`). |
| Path traversal | Sound: tokens are strict `[a-f0-9]{64}`, paths are derived not user-controlled, symlinks rejected at file and root level, realpath equality enforced (`Uploads.php:202-229`). |
| File type / size / DoS | Sound: extension allowlist + `wp_check_filetype_and_ext` + real `finfo` content sniffing (never browser MIME), per-field size cap vs `wp_max_upload_size`, per-field/session file counts, site-wide byte/file caps, exclusive lock over quota + move (`Uploads.php:231-246, 263-291`). Active-content extensions (php, html, svg, js…) are unconditionally refused. |
| WAPF Extended 3.1.5 comparison | OPF is stricter: WAPF stores under public `uploads/wapf/<field>/<md5(customer_id)>/` protected only by `.htaccess`/`index.php`, trusts the submitted MIME string, and has no order binding — so it has no retry defect but offers far weaker isolation. |

## Proven defect and fix

**Order-claim lifecycle defect.** Pre-fix, any nonzero `order_id` permanently
consumed a token. `bind_order`/`persist` bind the token the moment a
`checkout-draft` line item is saved, and Store API checkout flips drafts to
`pending` before payment (`Checkout.php` `process_order`). Any failure after
that point — declined payment, stock reservation, additional-field validation —
left the token bound to a still-retryable order, so the *same* shopper's retry
was rejected by `woocommerce_check_cart_items` → `check_cart` →
`validate_tokens`. `persist` also skipped bound records without re-emitting the
hidden `_opf_uploads` item meta, silently losing the admin download/deletion
link on draft resync.

Reproduced on the clone: `bin/e2e-upload-order-rebind.php` check
`checkout retry accepts token bound to retryable order` failed before the fix;
all 16 checks pass after.

**Fix (`Uploads.php:308-318`).** New `claimed()` predicate: an order claims a
token only while it exists and is neither `checkout-draft` nor
`needs_payment()` (pending/failed). Applied to `validate_tokens`, `mark_cart`,
`persist`, and `bind_order`; `persist` now re-emits `_opf_uploads` references
for retryable bindings and rebinds stale ones to the current order. Owner,
field scope, TTL, and file-content checks are unchanged; live-order claims are
still absolute, so the "one upload per completed purchase" invariant holds.

## Exact commands and results

```
# Reproduction (pre-fix): check 9 failed — checkout retry blocked
wp --path=/tmp/opf-upload-security-wp eval-file bin/e2e-upload-order-rebind.php

# Post-fix: 19/19 checks pass (retry accepted, draft resync keeps meta,
# rebind to replacement draft, processing-order token stays claimed,
# live-order token not rebound, stale-order cleanup/deletion, unrelated session 404,
# order customer 200)
wp --path=/tmp/opf-upload-security-wp eval-file bin/e2e-upload-order-rebind.php

# Regression suite (existing e2e, guard adapted for this clone's private dir):
# 21/21 checks pass, including the updated 'live order-bound token replay rejected'
sed 's|/tmp/opf-upload-foundation-private|/tmp/opf-upload-security-private|' \
  bin/e2e-upload-security.php > /tmp/e2e-upload-security-clone.php
wp --path=/tmp/opf-upload-security-wp eval-file /tmp/e2e-upload-security-clone.php

# Unit tests: new regression file 5 tests / 15 assertions OK; full suite 299 tests OK
vendor/bin/phpunit tests/Unit/UploadOrderClaimTest.php   # OK (5 tests, 15 assertions)
vendor/bin/phpunit                                      # OK, 299 tests / 1162 assertions

php -l includes/Service/Uploads.php   # No syntax errors
php -l tests/Unit/UploadOrderClaimTest.php
php -l bin/e2e-upload-order-rebind.php
```

## Residual risks (documented, unchanged or accepted)

1. **Stale-order cleanup depends on scheduled cleanup.** A deleted/GC'd order
   no longer blocks owner deletion or rebinding. Its file remains until the
   upload TTL expires and scheduled cleanup runs; if that job is disabled or
   repeatedly fails, the orphan remains on disk.
2. **Fail-closed edges.** A bound order that exists but is neither
   `checkout-draft` nor `needs_payment()` — e.g. `$0` pending, cancelled, or a
   custom status — keeps its claim; the shopper must re-upload. Conservative
   and intended.
3. **`remove` stays strict.** Customer DELETE returns 409 for any cart- or
   order-bound token, including draft-bound ones, so files referenced by an
   in-flight purchase cannot be destroyed from under it.
4. **Order-duplication plugins.** Third-party tools that copy `_opf_uploads`
   item meta onto a new order can rebind a token *only* while the previous
   order is still retryable or deleted; live-order bindings never move.
5. **Shared-session ownership.** Any process holding the same WooCommerce
   session id shares the upload owner — consistent with cart ownership.
