# Content image admin + conditional lifecycle proof — 2026-10-03

Real WordPress admin, Media Library, and conditional-storefront check of the
OPF informative `content_image` field against installed WAPF Extended 3.1.5.
Run on a dedicated disposable SQLite WordPress clone at
`http://127.0.0.1:8247` (WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11,
Chromium headless) using a throwaway administrator account created by the
fixture. Production was not used.

## Result

**43/43 checks pass** on OPF admin save/reload, real Media Library
selection, conditional storefront visibility, responsive rendering, and
byte-identical image delivery.

Artifacts live in
`/home/followersya-5hqi7/ops/scratchpad/20261003-opf-image-admin-evidence/`:
`browser-results.json`, `state.json`, `cleanup.json`, `persisted-*.json`
snapshots after every save phase, `reference-admin-license-gate.png`,
`opf-admin-reloaded.png`, `media-{first,second}.png` (real Media Library
dialogs), and `conditional-{visible,hidden}-{1280,390}.png`.
Fixture/proof scripts: `bin/e2e-content-image-admin.php` (guarded,
clone-path + env gated) and `bin/e2e-content-image-admin.mjs`.

## Covered

- **Reference license gate recorded, not bypassed.** Extended 3.1.5's admin
  editor shows "Please activate your license first" on the product screen;
  the run asserts the gate text exists and continues on OPF surfaces.
- **Real admin + Media Library.** Logged in over `/wp-login.php`, opened the
  OPF group editor, added a `content_image` field, selected images through
  the actual Media Library modal, and saved over the authenticated
  `/wp-json/opf/v1/groups` REST endpoint (HTTP 200 asserted).
- **Save/reload persistence.** Attachment ID + URL survive real page reloads
  across create, replace, direct-URL edit (which correctly clears stored
  attachment identity), and re-selection — snapshot JSON dumped after each
  phase (`persisted-created/edited/direct-url/final.json`).
- **Conditional visibility.** A `show when image_gate is "on"` rule hides
  both OPF and native WAPF `img` fields on load, reveals both on `"on"`,
  hides both again on `"off"`, and resets to hidden after reload — at 1280px
  and 390px.
- **Rendering parity.** OPF and WAPF select the same `src`/`srcset`, the
  image decodes with `naturalWidth > 0`, stays inside the viewport, and
  creates no submitted form controls (informative only). Every browser-
  selected byte matches the owned Media Library file's sha256 at both
  widths.
- **Alt semantics (documented difference).** After the Media Library alt is
  updated, the native WAPF image picks up `Updated Media alt & "quoted"`
  while OPF keeps the authored field label `Conditional image & "quoted"`.
  OPF intentionally uses the field label as `alt`; WAPF sources it from the
  attachment. Recorded as a difference, not a failure.

## Reference-side defect recorded

Installed WAPF Extended 3.1.5's own `assets/js/admin.min.js` throws
`Cannot read properties of undefined (reading 'style')` when its
license-gated product admin tab is clicked. The exception is attributed by
stack (`advanced-product-fields-for-woocommerce-extended`) and recorded in
`observations.referenceJsErrors`; the OPF-served surfaces threw zero
uncaught exceptions.

## Cleanup

`cleanup.json` verifies the clone returned to its exact baseline: 1027
posts, 1 user, original active-plugin list, fixture option removed, no
owned files left on disk. The real admin session leaves a wp-admin
`Auto Draft` + revision owned by the fixture user on this WordPress
version; the cleanup phase now also removes any posts authored by the
fixture user before the baseline comparison (see `bin/e2e-content-image-admin.php`).
