# Private upload foundation: scoped implementation and evidence

Integration base: `01a29299598d3917ef4e2f5dc9a8a073559a7548`, verified
with `git rev-parse HEAD^` after rebasing this lane. The public
`feat/opf-archive-import` branch was independently checked during the rebased
validation and matched that SHA:

```sh
git ls-remote https://github.com/networkersoss/open-product-fields-for-woocommerce.git refs/heads/feat/opf-archive-import
```

Worktree: `/tmp/opf-upload-foundation`, branch
`feat/upload-foundation-20261001`. This change requires review/integration
against the later public branch; it has not been pushed or deployed.

## Acceptance sources

[WAPF file upload field](https://www.studiowombat.com/knowledge-base/file-upload-field/)
and [WAPF upload tutorial](https://www.studiowombat.com/blog/how-to-add-ajax-file-upload-to-woocommerce-product-pages/)
describe native single/multiple file inputs, configurable file restrictions,
and modern Ajax uploads with drag/drop, progress, and file removal. They also
describe access to uploaded files through order administration.

Installed WAPF Extended 3.1.5 was inspected read-only at
`/tmp/opf-product-woo/wp-content/plugins/advanced-product-fields-for-woocommerce-extended/includes/classes/class-config.php:1477` and
`includes/classes/class-file-upload.php:195`. Its upload-specific options are
`multiple`, `accept`, and `maxsize`; multiple uploads use PHP's
`max_file_uploads` limit. OPF's schema accepts single/multiple uploads,
extension lists (including WAPF-style pipe-separated extension groups), and
nonnegative numeric size limits in MiB. Empty restrictions use the WordPress
allowlist; zero size uses the PHP/WordPress limit. No narrower default per-field
count cap was added. WAPF mapping is deliberately still open in this slice.

## Implemented behavior

- Native product forms use multipart files and same-origin + native form
  nonce checks. With `opf_upload_ajax=yes` (falling back to `wapf_upload_ajax`),
  the input is enhanced with Ajax progress, drag/drop, removable filenames,
  and HTML validity/submission blocking during active uploads. Failed uploads
  create no accepted token. Native behavior works when modern upload is off.
- `POST /opf/v1/uploads/session` creates a WooCommerce-session-bound CSRF
  token. Upload/remove requests require `X-OPF-Upload-Nonce`; cross-origin
  browser requests are rejected. Tokens contain 32 random bytes represented
  as hex and are bound to the session, product, group, and field.
- Bytes are private files (`0700` root, `0600` files), never media-library
  attachments or public upload URLs. Configure a dedicated absolute
  `OPF_UPLOAD_PRIVATE_DIR` outside every web root; a per-install default is
  attempted above the WordPress directory. Unwritable/public/symlink roots
  fail closed. `finfo`, WordPress's allowed MIME types, actual bytes, extension,
  file size, and upload transport are checked; active formats remain denied.
- A site-wide filesystem lock covers quota inspection, the move, and durable
  metadata insertion. Actual stored byte files, including orphan bytes, count
  toward hard site limits: 1 GiB and 10,000 files by default, configurable with
  `OPF_UPLOAD_MAX_BYTES` / `OPF_UPLOAD_MAX_FILES`. Per-session staging is bounded
  by PHP's batch count and a 100 MiB budget (at least one WordPress maximum
  upload; configurable with `opf_upload_session_budget`).
- Classic and Store API cart attachment validate ownership and current field
  constraints. Checkout revalidates availability. Cart display uses filenames;
  hidden order metadata keeps opaque file references. Classic checkout binds
  files after line-item saving assigns the order ID. Store API binding was also
  verified against an actual submitted order.
- Protected download responses require the original session, authenticated
  order customer, or a shop manager for an order-bound file. They serve exact
  bytes as attachment/octet-stream with no-store and nosniff headers.
- Temporary uploads expire after 24 hours and are cleaned hourly when WordPress
  cron runs; active cart staging also expires and is rejected before cron runs.
  Interrupted moves without
  metadata are cleaned after the same TTL. Orders retain files until a shop
  manager uses the explicit signed GET admin action; capabilities, order ID,
  record binding, and nonce are checked. Plugin deactivation unschedules cleanup.

## Disposable verification

Fresh WordPress 7.1.2 / WooCommerce 11.1.0, PHP 8.5.11, with a new SQLite
database at `/tmp/opf-upload-runtime-20261001`. Only WooCommerce, OPF, and the
SQLite integration are active. Test email is blocked with `pre_wp_mail`.
The listener is `127.0.0.1:8169`; private fixtures are under
`/tmp/opf-upload-foundation-private`. No production database or plugin was
modified. `bin/e2e-upload-fixture.php` generates disposable MU controls for
test quota headers; the product plugin never consumes those headers.

Commands:

```sh
vendor/bin/phpunit --configuration phpunit.xml.dist
vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'UploadFieldTest|FieldGroupSchemaTest|CartIntegrationImageQuantityTest|RepeaterFieldTest'
wp --path=/tmp/opf-upload-runtime-20261001 eval-file /tmp/opf-upload-foundation/bin/e2e-upload-security.php
# Run from the workspace that already has Playwright installed:
node /tmp/opf-upload-foundation/bin/e2e-upload-browser-test.mjs
php -l includes/Service/Uploads.php
php -l includes/Service/CartIntegration.php
php -l includes/Service/Renderer.php
php -l includes/Engine/FieldGroup.php
php -l bin/e2e-upload-fixture.php
php -l bin/e2e-upload-security.php
php -l tests/Unit/UploadFieldTest.php
node --check assets/js/opf-uploads.js
node --check assets/js/opf-frontend.js
node --check bin/e2e-upload-browser-test.mjs
git diff --check
```

Unit evidence after rebase: **275 tests / 1069 assertions passed** in the complete suite;
the focused upload/schema/repeater/image-quantity/coupon/order-again suite
passes **63 tests / 147 assertions**. PHPUnit reports one pre-existing doc-comment metadata
deprecation in `CapabilityFixtureRegistryTest` under PHP 8.5.11. All **119
non-vendor PHP files** pass syntax checks; the changed JavaScript and proof
script pass `node --check`; `git diff --check` passes.

Browser evidence: **36 checks passed** in
`/tmp/opf-upload-foundation-proof/browser-results.json`. It verifies multiple Ajax files,
progress + submission validity, drag/drop, content spoof rejection, removal,
both hard quota denials (HTTP 503), nonce/origin denials (403), foreign access
(404), optional traversal token rejection (Store API 400), protected exact-byte
download, claimed-file deletion denial (409), actual Store API and classic
checkout/order binding, public URL absence (404), authenticated customer and
manager download, customer REST nonce failures (403/404), the real order-admin
download link, private filesystem permissions, unchanged bytes after quota
denials, invalid admin deletion (403), valid admin deletion, and
zero browser runtime errors. The classic checkout test captures and forwards
the real browser request/response using Playwright routing to avoid response
body loss during the immediate order-received navigation.

Runtime evidence: **21 checks passed** in
`/tmp/opf-upload-foundation-proof/security-results.json`, including replay boundaries,
expired/ordered references, duplicate normalization, malformed file arrays,
filename sanitation, MIME and size rejection, symlink/root attacks, temporary
and interrupted-move cleanup, cart expiry before cleanup for validation and
download, order retention, and exclusion of a competing
process by the actual site lock. This proves process lock exclusion; it does
not claim a sustained concurrent HTTP upload load test.

Screenshots were captured and the mobile image inspected:
`/tmp/opf-upload-foundation-proof/modern-desktop.png`, `modern-mobile.png`,
`block-checkout.png`, and `native-after-cart.png`.

## Explicit remaining scope

This is a foundation, not full WAPF upload parity. Keep compatibility ledger
rows unpromoted until the remaining contracts are implemented/proved:

- WAPF `file` import mapping and upload export round-trip (`multiple`, `accept`,
  `maxsize`); mapper edits are held for the independent formula lane.
- Upload builder controls; definitions are currently created through REST/JSON.
- Upload price and formula parity. Priced upload definitions are rejected to
  prevent differing browser/cart totals; unknown or malformed pricing types
  also fail before normalization can silently change them to free. Direct
  repeat and inherited repeated
  section uploads are explicitly rejected.
- Order-again and payment/checkout retry ownership, guest downloads after loss
  of the original WooCommerce session, variation products, multi-site routing,
  and external storage backends. Existing order-bound tokens cannot be reused
  as new cart uploads. No order-again success is claimed.
- Comprehensive document/archive MIME equivalence. Current validation requires
  exact content MIME; safe WAPF/WordPress MIME aliases may need a reviewed
  normalization contract. Thumbnail/live-preview addons are not implemented.
- Automatic order-file retention/deletion policy beyond the explicit manager
  action. Order files are otherwise retained. Cleanup depends on WordPress cron;
  database and private-file backups must travel together during migration.

The lane was rebased onto the verified public `01a2929` before repeating
the full unit suite, browser, security, and syntax evidence. Re-run the focused
evidence after integration; the production cache was not changed.

## Disposable cleanup

After the independent rerun on 2026-10-01, the guarded command
`wp --path=/tmp/opf-upload-runtime-20261001 eval-file /tmp/opf-upload-foundation/bin/e2e-upload-cleanup.php`
removed fixture product **10**, group **11**, orders **13, 14, 17, 18, 20,
22, 24, 26, 28, 30, 32**, and **18** upload records with their private files.
`/tmp/opf-upload-foundation-proof/cleanup-results.json` verifies the product
and group absent, zero remaining orders, zero remaining private byte files,
and unchanged active plugins. This disposable installation has no WAPF plugin
installed; its prior state was therefore preserved without toggling WAPF.
The earlier source rerun then recreated product **34** / group **35**, passed
the same **36 browser / 21 security checks**, and removed them with orders
**36, 38** and the remaining **1** native upload record. The final cleanup
report is `/tmp/opf-upload-foundation-proof/cleanup-final-results.json` and
again verifies zero remaining orders/private files and unchanged plugin state.
The latest rebased rerun on 2026-10-02 used product **58** / group **59**, passed **36
browser / 21 security checks**, and removed those fixtures with orders
**60, 62** and the remaining **1** native upload record. Its final report is
`/tmp/opf-upload-foundation-proof/cleanup-rebased-results.json`; zero orders
or private byte files remain and active plugins are unchanged.
The read-only source installation was never mutated. Test infrastructure,
blocked email, screenshots, and proof JSON remain available; no production
installation or database was used. Recreate the fixture with
`bin/e2e-upload-fixture.php` before rerunning, and pass its emitted product and
group IDs through `OPF_PRODUCT_ID` / `OPF_GROUP_ID` to the browser script.

## PHP 7.1 audit after rebase

The two typed static properties in `Uploads.php` were replaced by ordinary
properties with PHPDoc types. The audited image `php:7.1-cli` runs **PHP
7.1.33**, not PHP 7.1.2 (the browser installation uses **WordPress 7.1.2**).
The image digest is the one recorded in
`docs/compatibility/WAPF-MINIMUM-PLATFORM-EVIDENCE.md`.

```sh
sudo -n docker run --rm --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges \
  --mount type=bind,src=/tmp/opf-upload-foundation,dst=/audit,readonly \
  -w /audit php:7.1-cli php -l includes/Service/Uploads.php
sudo -n docker run --rm --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges --tmpfs /tmp:rw,noexec,nosuid,size=16m \
  --mount type=bind,src=/tmp/opf-upload-foundation,dst=/audit,readonly \
  -w /audit php:7.1-cli php bin/probe-upload-php71.php
sudo -n docker run --rm --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges \
  --mount type=bind,src=/tmp/opf-upload-foundation,dst=/audit,readonly \
  --mount type=bind,src=/tmp/opf-php71-lint-20261001.php,dst=/probe.php,readonly \
  -w /audit php:7.1-cli php /probe.php
```

The upload class and its new probe pass PHP 7.1 syntax. Isolated runtime
checks pass **9/9**, including class loading, token handling, PHP count
limits, actual private-root permissions, `finfo` PNG/spoof checks, and actual
size checks. The probe explicitly supplies minimal WordPress adapters; it
is not a full WordPress/WooCommerce PHP 7.1 lifecycle proof.

The complete shipped PHP 7.1 parser gate remains **36 files / 6 failures**
(exit 1). An independent archive of base `01a2929`, mounted at `/audit` in
the same command, produces **35 files / the same 6 failures**: `API.php`,
`Calculator.php`, `FieldGroup.php`, `CartIntegration.php`, `Rest.php`, and
`WapfExporter.php`. Those existing arrow functions and typed properties
remain a platform parity gap; this slice introduces no extra parser failure.
Full-stack PHP 7.1 compatibility is not claimed.

Artifacts under `/tmp/opf-upload-foundation-proof/`:
`php71-upload-lint.txt`, `php71-upload-runtime.json`,
`php71-shipped-lint.txt`, and `php71-base-lint.txt`.

## Coupon and order-again merge review

The final rebase onto `01a2929` preserved the six-argument
`woocommerce_add_to_cart_validation` registration, three-argument
`woocommerce_order_again_cart_item_data` registration, and both coupon
registrations (`woocommerce_coupon_get_apply_quantity` and
`woocommerce_coupon_get_discount_amount`) exactly as in the public base.
The two conflicts were resolved by preserving restored/structured order-again
values in `attach()` and the structured `sanitize_value()` argument, then
adding upload validation/token handling alongside them. The diff against the
base contains only upload additions in `CartIntegration.php`. Native upload
initialization now skips no-file requests before product/cache access, so
the existing fresh classic/Store API order-again regression tests still run
without entering upload transport. Upload order-again success remains open.
