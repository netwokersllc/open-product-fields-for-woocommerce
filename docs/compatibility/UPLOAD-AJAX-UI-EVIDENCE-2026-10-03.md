# WAPF-UPLOAD-AJAX-UI — residuals implementation evidence (uploadui lane)

Date: 2026-10-03 · Worktree: `/tmp/opf-lane-uploadui` (branch `lane/uploadui`) · Clone: `/tmp/opf-image-uploadui-wp` @ `http://127.0.0.1:8323`
Runtime: WP 7.1.2 · WooCommerce 11.1.0 · PHP 8.5.11 · PHPUnit 11.5.56 · sqlite-database-integration 3.0.2 · Node 23.11.1 · Playwright Chromium
Owned files: `assets/js/opf-uploads.js`, builder upload region of `assets/js/opf-builder.js`, upload region of `assets/css/opf-frontend.css`, tests.
No core upload security files (`Uploads.php`, `UploadReissue.php`), `CartIntegration.php`, `Calculator.php`, or `Renderer.php` were edited.

## Source audit — WAPF Extended 3.1.5

| Surface | WAPF 3.1.5 source | Behavior mirrored |
|---|---|---|
| Renderer | `views/frontend/fields/file.php` | Modern path is Dropzone (`dropzone.min.js`) when `wapf_upload_ajax=yes`; native `<input type=file>` otherwise. |
| Preview | `file.php:19` `previewTemplate` (`.dz-image > img[data-dz-thumbnail]`), `file.php:75-80` `addedfile` | Image files get an inline thumbnail; when `!type.startsWith('image/')` or `type === image/tiff`/`image/heic`, `.dz-image` is removed and only the name remains. |
| Remove | `file.php:19` `.dz-remove` (SVG X), `file.php:105-116` `removedfile` → `wapf_upload_remove` | Per-file remove control that deletes the stored file and re-serializes the field value. |
| Progress | `file.php:19` `.dz-progress`/`data-dz-uploadprogress` | Thin per-file progress bar. |
| Builder options | `includes/classes/class-config.php:1477-1508` (`file`) | Exactly three data options: `multiple` (true-false), `accept` (multi-select over `File_Upload::get_all_allowed_filetypes()`, i.e. `get_allowed_mime_types()` minus the executable deny-list), `maxsize` (number MB, default `1`). Pricing exists in WAPF but OPF's schema rejects upload pricing. |
| Allowed-type keys | `class-config.php:473-479` + `class-file-upload.php:12-14,213-222` | `accept` choices are the WordPress mime-group keys sorted with `ksort`; deny-list = exe,bat,cmd,php,php3-5,cgi,dll,js,jar,app,vb,vbscript,htaccess,htpasswd,msi,sh,shs,bin,asp,cer,csr,sys,jar,pif. |
| Order-again | `class-product-controller.php order_again_cart_item_data()` | Re-links the same public `uploaded_file` path (no copy, no token). OPF keeps its stronger session+order binding and mints a private reissue; this lane only needed the UI to reflect it. |

Live reference run (`bin/e2e-upload-ui-wapf-reference.mjs`, WAPF Extended 3.1.5 activated on the clone and restored afterwards):
`wapf-reference/wapf-reference-results.json` — 6/6 checks: Dropzone ajax uploader renders, image upload shows `.dz-preview .dz-image img[data-dz-thumbnail]`, non-image upload drops `.dz-image` but keeps the name, per-file `.dz-remove` present, zero runtime errors. Screenshot: `wapf-reference/wapf-image-preview.png`.

## Implementation

### 1. Thumbnail preview — `assets/js/opf-uploads.js`
- `isPreviewableImage(type)`: `image/*` except `image/tiff` and `image/heic`, matching WAPF's exclusion list.
- On a successful upload the row renders `.opf-upload__preview > img.opf-upload__thumb` using `URL.createObjectURL(file)`. The thumbnail is the locally selected bytes — **no private byte is fetched, no ACL is weakened, no public URL is created**. It is display-only; server-side `Uploads::validate_file()` remains authoritative.
- Object URLs are revoked on remove and on `pagehide` (`releasePreview`).
- Non-image uploads keep the filename only, exactly like the reference.
- Tokens remain the only submitted state (`input[type=hidden][data-opf-upload-token]`), so `opf-frontend.js`/`CartIntegration` value reading is unchanged.

### 2. Builder authoring surface — `assets/js/opf-builder.js`
- `TYPES` now includes `'upload'` (schema already supported it; the builder list did not).
- New `uploadEditor(field)` renders the real WAPF option surface only: **Allow multiple files** (checkbox → `multiple`), **Accepted file types** (native multi-select over the WordPress-WAPF group keys → `accepted_types`), **Maximum file size (MB)** (number, WAPF default `1` → `max_size`). No invented options; upload pricing is not exposed because `FieldGroup` rejects it.
- Switching a field's type to `upload` clears choices and resets pricing to `none`, seeding `max_size = 1` and an empty allow-list.
- Stored `accepted_types` are matched back to group options by their expanded extension set.

### 3. Order-again UI
No server change was needed — `UploadReissue` already mints a fresh session-owned token. The lane proves the **UI reflects it**: after the Woo `p.order-again` action the cart view shows the reordered file name, and the second order binds a fresh reissue (`reissued_from` set), not the original order-bound token.

### 4. Accessibility
- Native `<input type=file>` is labelled by the existing field `<label for>` (Renderer) and is now `aria-describedby`-linked to the drop hint (`#opf-upload-hint-…`).
- `.opf-upload__files` is `role=list` with `role=listitem` rows.
- `.opf-upload__status` is `role=status aria-live=polite` (also set defensively in JS) and announces uploading/complete/errors/limits.
- Remove is a native `<button>` with an explicit `aria-label="Remove <filename>"`, keyboard-operable (Enter/Space).

### CSS — `assets/css/opf-frontend.css`
Only the upload region changed: `.opf-upload__file`, `.opf-upload__preview`, `.opf-upload__thumb` (max 52px, matching WAPF's `.dz-image` width), `.opf-upload__name`, `.opf-upload__remove`, `.opf-upload__hint`. Existing style/selectors preserved.

## Proof

Full-run summary: `final-verification.txt`.

| Proof | Result |
|---|---|
| `node --check` on both changed JS files | clean |
| `tests/js/opf-uploads-ui.test.cjs` (new) | **17 checks pass** — preview decision table + builder option surface + type-switch seeding |
| `vendor/bin/phpunit --filter Upload` | **32 tests / 87 assertions OK** |
| `vendor/bin/phpunit` (full suite) | **537 tests / 2231 assertions OK** (1 pre-existing `CapabilityFixtureRegistryTest` deprecation, unrelated) |
| `bin/e2e-upload-ui-browser-test.mjs` real Chromium | **26/26 checks pass**, zero browser runtime errors (`browser/browser-results.json`) |
| WAPF reference `bin/e2e-upload-ui-wapf-reference.mjs` | **6/6 checks pass** (`wapf-reference/wapf-reference-results.json`) |

### Browser lifecycle (26/26)
Builder: upload type offered + selected; `multiple`/`accepted_types`/`max_size` rendered from stored model; a real REST save adds `docx` and `2 MB`; reload retains both. Storefront: merged `accept` reflected on the native input; input labelled and described by the hint; status is a polite live region; PNG upload shows an inline `blob:` thumbnail that decoded (`naturalWidth > 0`); row labelled with filename + accessible Remove; PDF keeps the name without a thumbnail; single-file field blocks a second file with a live announcement; keyboard Enter on Remove works; tokens opaque. Commerce: classic add-to-cart shows the previewed file; Store API cart lists the names; checkout creates the order and binds both rendered tokens with private bytes; order-again page exposes the real Woo action, the cart reflects the reordered name, and the second order binds a fresh reissue (`reissued_from`). Screenshots: `browser/builder-upload.png`, `browser/storefront-desktop.png`, `browser/storefront-mobile.png`.

### Security boundary (unchanged, re-verified)
Preview uses only `URL.createObjectURL` of the local `File`. No edit to `Uploads.php`/`UploadReissue.php`; token/session/order binding, extension+MIME allowlist, size limits, out-of-webroot private dir, and `0600/0700` permissions are untouched. The reference binary cap works: the lane had to pin an explicit out-of-webroot `OPF_UPLOAD_PRIVATE_DIR` because the clone lives at the filesystem root, where OPF's default path resolution fails closed with `opf_upload_storage` (503) — the fail-closed boundary behaved exactly as designed.

## Not owned / flagged
- **Renderer template attribute (for the report only, not edited):** the OPF upload wrapper already carries `data-opf-upload-*` and the file input has `type`/`accept`. Previews need no new server attribute because the browser already has the selected `File`. No Renderer change was required; the pre-existing mismatch where the native `<input>` `id` uses a hyphen (`opf-<gid>-<fid>`) while the field label uses `opf-<gid>-<fid>` concatenated differently is harmless for uploads (both resolve to the same string) and was left untouched.
- WAPF's `.htaccess`/public-uploads model is intentionally **not** mirrored (OPF is strictly stronger).
- WAPF Pro/Extended exact `accept` preview visuals beyond the audited markup and per-file progress styling in Dropzone were not pixel-compared; preview semantics (image vs non-image) were.

## Cleanup — final state equals baseline
`bin/e2e-upload-ui-cleanup.php` deletes only lane fixtures and restores the exact option baseline captured by the fixture (missing sentinel included). Verified after the final browser run:

- products 5, `opf_field_group` 12, orders 250, pages 7, posts 1, revisions 4; users = `admin` only.
- `opf_upload_ajax`, `woocommerce_cod_settings` deleted; `opf_admin_only`/`woocommerce_coming_soon`/`woocommerce_calc_taxes`/`woocommerce_enable_guest_checkout`/`woocommerce_bacs_settings` restored to baseline (`options_restored: 7`).
- `wp_options` delta vs. a pristine sibling clone = only `opf_theme_compat` (pre-existing baseline); no `opf_upload_*` upload records remain.
- mu-plugins `dbg-render.php`, `probe-hooks.php` only; `/tmp/opf-upload-ui-private` removed; WAPF Extended inactive with the original `active_plugins` set restored.
- Fixture files: `bin/e2e-upload-ui-fixture.php`, `bin/e2e-upload-ui-cleanup.php`, `bin/e2e-upload-ui-browser-test.mjs`, `bin/e2e-upload-ui-wapf-reference.mjs`, `tests/js/opf-uploads-ui.test.cjs`.
