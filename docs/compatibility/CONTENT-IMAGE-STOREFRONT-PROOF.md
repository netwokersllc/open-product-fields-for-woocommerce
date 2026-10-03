# Informative image storefront proof

Public baseline: `fa462976c3190716dbdfd5cacdf111bea3b1b0bc`. This evidence closes the missing **storefront observation** for `WAPF-FIELD-CONTENT-IMAGE`; it does not promote the whole capability to supported.

Installed WAPF Extended 3.1.5 `views/frontend/fields/img.php` selects `wp_get_attachment_image(..., 'full', false, raw_field_attributes)` when `options.attachment` exists, otherwise outputs `options.image` directly. `includes/classes/class-html.php::field_attributes()` supplies the WAPF field ID attributes. OPF's mapper imports both options and its renderer uses WordPress's attachment renderer or an escaped direct URL. Source hashes and runtime versions are in the accompanying state evidence.

Fresh owned `/tmp/opf-image-wp` copied from the nonproduction `opf-test/wordpress` installation with a read-only SQLite backup. No prior `/tmp` clone existed. Installed Extended source was copied read only; OPF was linked to the isolated proof worktree. Both plugins rendered the same real WooCommerce simple product through the classic `[product_page]` shortcode.

Final real Chromium run passed **39/39 checks** at 1280 and 390 CSS pixels: direct URL including query ampersands, a generated real 800×400 Media Library PNG with WordPress thumbnails, matching `src` and `srcset`, decoded loaded images, responsive width, lazy/async OPF rendering, actual browser-selected attachment response bytes matching disk SHA-256, escaped accessible alt names, no submitted image controls, and no uncaught browser errors. Accessibility snapshots and screenshots are retained. Screenshot inspection confirmed both plugins' visible blue image output at mobile width.

Deliberately hostile stored reference URLs are isolated fixture data: Extended interpolates raw URL strings and exposes an `onerror` attribute; OPF rejects both the `javascript:` URL and quote-bearing URL during import. The proof records this safety improvement without treating insecure reference behavior as required parity. The isolated browser may run the inert reference marker assignment; no secrets or external URLs are involved.

One semantic difference remains: OPF overrides attachment `alt` with the field label (escaped as text), whereas WAPF/WordPress preserves the Media Library alt. Direct WAPF URL output lacks alt, whereas OPF has an accessible name. This document records the difference; user acceptance or a deliberate media-alt policy still needs resolution. Real admin Media Library selection/save/reload and applicable conditional visibility proof are not established by this script. Existing schema/mapper/WXR tests remain separate evidence. Informative images are not input fields, so this browser proof does not establish unrelated cart/order workflows.

The loopback PHP server emitted an Extended `unserialize()` warning while reading copied pre-existing field groups. This run did not repair those unrelated copied records and does not establish a warning-free PHP runtime. The browser assertion covers uncaught JavaScript exceptions, not all console/network diagnostics; the deliberately malformed reference image also creates an expected failed image load.

Cleanup passed: 0 owned posts or attachment records, 0 generated full/thumbnail PNG files, removed fixture option, original post count (1027), active plugin list, and home/siteurl unchanged from fixture baseline. Extended was then deactivated and the copied installation's original plugin array restored. Production, central ledger, protected Tabs, and public branch were untouched.

Reproduce (use a new destination; never run against production):

```sh
python3 bin/clone-content-image-proof.py \
  --wordpress /home/followersya-5hqi7/opf-test/wordpress \
  --extended /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended \
  --opf "$PWD" --destination /tmp/opf-image-recheck --port 8241
wp --path=/tmp/opf-image-recheck option get active_plugins --format=json > /tmp/opf-image-original-plugins.json
wp --path=/tmp/opf-image-recheck plugin activate advanced-product-fields-for-woocommerce-extended
mkdir -p /tmp/opf-image-evidence
OPF_IMAGE_ALLOW=1 OPF_IMAGE_PHASE=setup OPF_IMAGE_OUT=/tmp/opf-image-evidence wp --path=/tmp/opf-image-recheck eval-file "$PWD/bin/e2e-content-image-storefront.php"
php -S 127.0.0.1:8241 -t /tmp/opf-image-recheck
# Separate terminal, from a directory with Playwright installed:
OPF_IMAGE_OUT=/tmp/opf-image-evidence node /path/to/opf/bin/e2e-content-image-storefront.mjs
OPF_IMAGE_ALLOW=1 OPF_IMAGE_PHASE=cleanup OPF_IMAGE_OUT=/tmp/opf-image-evidence wp --path=/tmp/opf-image-recheck eval-file /path/to/opf/bin/e2e-content-image-storefront.php
wp --path=/tmp/opf-image-recheck option update active_plugins "$(cat /tmp/opf-image-original-plugins.json)" --format=json
```

`php -l`, `node --check`, and `git diff --check` also passed. All evidence is scoped to the recorded installed versions, not all supported WooCommerce/WordPress versions.

Clone safety follow-up: the helper now uses explicit errors (also enforced with Python `-O`), permits only a fresh direct `/tmp/opf-image-NAME` destination, creates it exclusively with mode `0700`, and rejects source/Extended symlinks, including symlink ancestors and config/database/drop-in/uploads links. Only the new caller-owned OPF plugin link is added after copying. `python3 -B bin/test-clone-content-image-proof.py -v` passes 8/8 tests, including unchanged source configuration and independent SQLite content after clone writes.

Independent rerun using this hardened helper created `/tmp/opf-image-review-20261003` at port `8249`; Chromium again passed 39/39 at `2026-10-03T08:27:45.668Z`. Reproduction follows the commands above with this destination/port and `OPF_IMAGE_BASE=http://127.0.0.1:8249`. Local repeat artifacts remain at `/tmp/opf-image-review-evidence-20261003` (not part of the distributable evidence archive). Read-only SQLite inspection after cleanup verified no owned post IDs or fixture option, 1027 posts, no generated image files, and restored three-plugin array with Extended inactive. The owned PHP server was stopped. The previously described PHP warning and capability limits still apply.
