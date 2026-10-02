# Paragraph content HTML + shortcode storefront evidence

Real runtime proof completed 2026-10-02 ~22:53 UTC against installed WAPF
Extended 3.1.5. Source checkout: `/tmp/opf-content-html-storefront-20261002`,
branch `proof/content-html-storefront-20261002`. Disposable runtime:
`/tmp/opf-formula-roundtrip-wp`, `http://127.0.0.1:8216`, SQLite, WooCommerce
11.1.0, PHP 8.5.11, Twenty Twenty-Five, real Chromium/Playwright. WAPF
Extended 3.1.5 and this OPF checkout were active simultaneously; the OPF
plugin symlink was temporarily repointed to this worktree and restored to
`/tmp/opf-formula-import-proof-audit` by EXIT trap. Production and protected
tab markup untouched.

## Runtime finding that produced a code change

The first guarded run exposed a real divergence: Extended's `p` view
(`views/frontend/fields/content.php`, reached via
`class-html.php:367-369`) merges `img => src,target,class,alt,style,id` into
`Html::$minimal_allowed_html_element`, while OPF's paragraph HTML allowlist
permitted only `src,class,style,id`. On the served page WAPF rendered
`<img ... alt="OPFCH_ALT" target="_blank">` and OPF stripped both attributes.
`Renderer.php` now permits `alt` and `target` on `img` inside paragraph HTML
content, matching Extended's `p` allowlist exactly. The PHPUnit regression
pins the attribute set; it failed before the fix and passes after. Note the
legacy `paragraph.php` takeover view uses the smaller set (`src,class,style,
id`); it serves the Free `paragraph` type, which OPF maps to plain escaped
text, so no OPF render path needs it.

## Executed proof

Fixture: one product-local `_wapf_fieldgroup` meta (stored `to_array()` shape,
raw unsanitized `options.p_content` so render-time `wp_kses` is exercised) plus
one OPF group (`content_format=html`, `process_shortcodes=true`, identical
payload) targeting the same product, plus a second OPF paragraph with
`process_shortcodes=false`. A fixture mu-plugin registered the unique
shortcode `[opfch_probe_20261002]` returning `<mark data-opfch="probe">
OPFCH_SC_OK_20261002</mark>` — `mark` is outside both allowlists, so its
survival proves `do_shortcode` runs after `wp_kses` in each engine.

Payload exercised allowlisted markup (strong/em/a+href+target+class+id+style/
ul/li/h3/table/thead/tbody/tr/th/td/img+src+target+class+alt+style+id/span/
div/hr/br), disallowed markup (`<script>`, `<u>`, `<iframe>`, `<object>`,
`onclick`, `javascript:` href), entity escaping, and the fixture shortcode.

- **11 PHP render-path checks:** `Html::field()` vs `Renderer::render_group()`
  on the stored fixture — allowlist kept, blocklist stripped, `<mark>` emitted
  by both (sanitize-then-shortcode order in both), img alt/target kept by both
  post-fix, opt-out paragraph keeps literal `[opfch_probe_20261002]`, and the
  normalized fragments are equal. Log: `content-html-storefront-20261002/
  render-verify.json`.
- **13 Chromium checks on the served page:** same assertions against the
  browser DOM plus normalized-fragment equality and zero uncaught errors.
  Artifacts: `content-html-storefront-20261002/browser-results.json`,
  `wapf-fragment.html`, `opf-fragment.html`, `opf-nosc-fragment.html`.
- **PHPUnit:** focused paragraph allowlist regression red before the fix,
  green after; full suite 351 tests / 1465 assertions (one existing PHPUnit
  metadata deprecation). PHP lint and Node syntax checks pass.

## Render-surface note

Under Twenty Twenty-Five's block Single Product template neither plugin emits
anything on the product URL: `woocommerce_before_add_to_cart_button` does not
run there (verified by page fetch — no `wapf-field` or `opf-field` markup for
either plugin). The proof therefore renders the classic add-to-cart template
through a `[product_page]` host page, where both hooks fire and both plugins
emit their field markup. Block-template field output is out of scope for both
plugins equally and is not claimed here.

## Cleanup and guard verification

`OPF_CONTENT_HTML_E2E_PHASE=cleanup` removed the product, OPF group, host page,
state option, state JSON, and fixture mu-plugin (plus the created mu-plugins
directory). Independent re-read afterward reported all fixture records absent
and the shortcode unregistered. The OPF plugin symlink was restored to
`/tmp/opf-formula-import-proof-audit` via EXIT trap and re-read.

## Reproduce

On the disposable clone (WAPF Extended 3.1.5 + WooCommerce + this checkout as
`open-product-fields-for-woocommerce`), serve `php -S 127.0.0.1:8216` and run:

```sh
OPF_CONTENT_HTML_E2E_ALLOW=1 OPF_CONTENT_HTML_E2E_PHASE=setup   wp eval-file bin/e2e-content-html-storefront.php
OPF_CONTENT_HTML_E2E_ALLOW=1 OPF_CONTENT_HTML_E2E_PHASE=verify  wp eval-file bin/e2e-content-html-storefront.php
OPF_CONTENT_HTML_BASE_URL=http://127.0.0.1:8216 node bin/e2e-content-html-storefront.mjs   # cwd /tmp for playwright
OPF_CONTENT_HTML_E2E_ALLOW=1 OPF_CONTENT_HTML_E2E_PHASE=cleanup wp eval-file bin/e2e-content-html-storefront.php
vendor/bin/phpunit
```

## Acceptance boundary

Proven: Extended `p`/`options.p_content` HTML allowlist parity, disallowed
markup stripping, sanitize-then-`do_shortcode` order, entity escaping, and
opt-in/opt-out shortcode behavior on the rendered storefront fragment for the
payload above. Not covered: block-template rendering (absent for both),
conditional visibility of `p` fields at runtime, builder editing UI for HTML
content, and cart/order surfaces (static fields carry no submitted value).
