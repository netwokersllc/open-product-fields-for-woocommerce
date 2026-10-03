# Free Content Text storefront proof

At 2026-10-03 03:00 UTC, **5 PHP checks and 22 real Chromium checks passed**
against public WAPF Free 1.7.1 and OPF 0.1.0. This advances the runtime evidence
for `WAPF-FIELD-CONTENT-TEXT`; it does not promote that ledger row or establish
complete lifecycle parity. No product implementation or central ledger changed.

## Bound reference and runtime

- Source checkout: `/tmp/opf-content-text-parity-20261003`, branch
  `parity/content-text-runtime-20261003`, implementation baseline
  `e5225707e351ba85ab5ec8d29d205cda614c0998`.
- Independent clone: `/tmp/opf-content-text-wp-20261003`, copied from
  `/tmp/opf-toggle-woo-20261002`. Its SQLite database resolves inside the new
  clone at `wp-content/database/.ht.sqlite`; the fixture refuses any database
  outside its own `/tmp` WordPress root. Shared source clones were only read.
- Served URL: `http://127.0.0.1:8243`. PHP 8.5.11, WordPress **7.1.2** (the
  actual runtime-reported version), WooCommerce 11.1.0, Twenty Twenty-Five 1.5,
  Chromium 147.0.7727.15 through Playwright. WAPF Free and OPF were active
  together; Extended was inactive and the fixture rejects its loaded class.
- Free source: `/tmp/wapf-free-1.7.1-source/advanced-product-fields-for-woocommerce`.
  Archive `/tmp/wapf-free-1.7.1-source.zip` SHA-256:
  `c51ad76eb88f0095704bcf6b0a7a9b12d3d18263028121d276bff96ea70f9171`.
  The archive was already present; this run does not claim a fresh download.

| Executed or browser-served file | SHA-256 |
| --- | --- |
| Free `includes/classes/class-field-groups.php` | `e6a4b7b31c375d7a700cf55ba991521958957d7dea6469154d2ccdc50ee8e1a9` |
| Free `includes/classes/class-html.php` | `88a0950f56bb73fa28ba110895e054812a2fab322c570da5d57dedbcec18aa38` |
| OPF `includes/Service/Renderer.php` | `141bf2dbf98e9b76a7798cf40d5ec47f0c61622a5c280eef889dc940f6d07c00` |
| OPF `includes/Engine/WapfMapper.php` | `12d54cc9163c8809c6d9ca65757be671f198ed7bc88d1cf9e0a5c03932954a15` |
| Free `assets/js/frontend.min.js?ver=1.7.1` | `7d05d8620168862b21315f2e9183189bc9285a782f0b007f00c8e3833e766306` |
| OPF `assets/js/opf-frontend.js?ver=0.1.0` | `0ed89fc96d3eb6fcab43e25b7969bca240db40c3add6919b1371a944daecc66a` |

The two JavaScript hashes were calculated from **response bodies received by
Chromium**, and compared with the source file bytes. Both comparisons passed.

## Source and observed behavior

Free `class-fields.php:349-367` retains legacy `paragraph` and registers
`content`, both using `p_content`. `class-field-groups.php:118-119` sanitizes
admin input with `sanitize_textarea_field()`. `class-html.php:184-189` selects
the `content.php` template for legacy `paragraph`; `class-html.php:288-291`
escapes stored `p_content` with `esc_html()`. `views/frontend/fields/content.php`
prints that escaped value in its wrapper.

The fixture invokes the real `raw_json_to_field_group()` save conversion for
both types, persists its `to_array()` output in product `_wapf_fieldgroup`,
imports that stored group with OPF's actual mapper, and saves an OPF group
targeting the same product. A `[product_page]` host page exercises both plugins'
classic storefront hooks through a real WordPress HTTP request.

Payload covers leading/trailing whitespace, `<strong>` markup, an executable
script probe, literal and named/numeric entities, quotes, a raw ampersand,
newline/tab, literal `[opfct_unregistered]`, and `%41`.

- Five PHP checks pass: both types save exactly as `sanitize_textarea_field()`;
  markup/script body disappear; newline/tab survive and percent octet disappears;
  raw stored HTML imported through OPF is stripped and `needs_review=true`.
- For each saved type, Chromium observes equivalent escaped HTML in WAPF and
  OPF after trimming template indentation, identical decoded text, preserved
  newline/tab and literal shortcode, no child markup/input, and initial hiding
  under the mapped `gate == show` condition.
- Typing `show` in each plugin's own text gate reveals both paragraphs; changing
  each gate to `hide` hides both again. This proves the mapped condition executes
  in each served frontend, rather than merely existing in stored data.
- A separate raw stored `content` probe bypasses WAPF's save sanitizer. WAPF
  displays literal `<b>` and `<script>` text safely through `esc_html()`. OPF
  imports it with markup and script body removed and requests review. The
  browser confirms this intentional loss; **raw markup import is not lossless
  parity** and this proof does not claim it is.
- Both script probes remain unexecuted. Final HTTP response is 200, browser
  console/page errors are `[]`, failed resource responses are `[]`.

The normalized saved HTML fragment observed in both engines is:

```html
Alpha bold &amp; &lt;tag&gt; © "quotes" 'single' &amp; raw
Second	line [opfct_unregistered]
Tail
```

Here `\t` denotes the actual preserved tab. Browser CSS collapses whitespace
visually; preservation is proved by `textContent`, not by visible line breaks.
The `visible.png` screenshot was captured and inspected after both gates opened.

## Cleanup evidence

The final browser run finished at `2026-10-03T03:00:41.910Z`; cleanup re-read at
`2026-10-03T03:00:48+00:00` found all three fixture posts absent and the fixture
option absent. Baseline and after inventory match exactly:

| Inventory | Before | After |
| --- | --- | --- |
| Posts | 330 | 330 |
| Post metadata | 8620 | 8620 |
| Fixture product, OPF group, host page | absent | absent |
| Fixture state option | absent | absent |
| Home/site URL | `http://127.0.0.1:8243` | same |

Active plugins before and after were Free 1.7.1, the `opf-content-text-lane`
symlink to this checkout, SQLite integration and WooCommerce. The fixture does
not change plugins, site settings, existing content, users, orders, or MU files.
Its baseline is the independently prepared clone, not the source clone's plugin
configuration. The inherited toggle MU fixture was moved out of the new clone
during preparation. No MU fixture was added. The PHP server was stopped after
cleanup. Artifact JSON and the screenshot remain outside Git under
`/tmp/opf-content-text-artifacts-20261003` for review; they are not live fixture
state and the scripts reproduce them.

## Reproduce

Prepare a new independent `/tmp` SQLite WordPress/WooCommerce clone (do not run
this preparation on a shared clone). For this run preparation copied the toggle
clone with `cp -a`, changed only the new clone's WP_HOME/WP_SITEURL to port 8243,
set its active plugins to SQLite/Woo before installing the tested plugins,
copied the Free source above into its normal plugin directory, and linked OPF to
this worktree as `wp-content/plugins/opf-content-text-lane`. Activate Free and
OPF with `wp plugin activate opf-content-text-lane advanced-product-fields-for-woocommerce`.
Equivalent preparation commands for that same layout are below. Destination
paths must be new; refuse to overwrite any existing clone or plugin fixture.

```sh
cp -a /tmp/opf-toggle-woo-20261002 /tmp/opf-content-text-wp-20261003
wp --path=/tmp/opf-content-text-wp-20261003 --skip-plugins --skip-themes config set WP_HOME http://127.0.0.1:8243
wp --path=/tmp/opf-content-text-wp-20261003 --skip-plugins --skip-themes config set WP_SITEURL http://127.0.0.1:8243
wp --path=/tmp/opf-content-text-wp-20261003 --skip-plugins --skip-themes option update active_plugins '["sqlite-database-integration/load.php","woocommerce/woocommerce.php"]' --format=json
mkdir -p /tmp/opf-content-text-artifacts-20261003
mv /tmp/opf-content-text-wp-20261003/wp-content/mu-plugins/toggle-lane.php /tmp/opf-content-text-artifacts-20261003/inherited-toggle-lane.php
mv /tmp/opf-content-text-wp-20261003/wp-content/plugins/advanced-product-fields-for-woocommerce /tmp/opf-content-text-wp-20261003/wp-content/plugins/free-original-inactive
cp -a /tmp/wapf-free-1.7.1-source/advanced-product-fields-for-woocommerce /tmp/opf-content-text-wp-20261003/wp-content/plugins/advanced-product-fields-for-woocommerce
ln -s /tmp/opf-content-text-parity-20261003 /tmp/opf-content-text-wp-20261003/wp-content/plugins/opf-content-text-lane
wp --path=/tmp/opf-content-text-wp-20261003 plugin activate opf-content-text-lane advanced-product-fields-for-woocommerce
wp --path=/tmp/opf-content-text-wp-20261003 eval 'echo FQDB . PHP_EOL;'
```

Confirm `FQDB` resolves inside the new clone, then:

```sh
mkdir -p /tmp/opf-content-text-artifacts-20261003
php -S 127.0.0.1:8243 -t /tmp/opf-content-text-wp-20261003
```

In another shell, using the actual checkout paths:

```sh
OPF_CONTENT_TEXT_ALLOW=1 OPF_CONTENT_TEXT_PHASE=setup OPF_CONTENT_TEXT_OUT=/tmp/opf-content-text-artifacts-20261003 wp --path=/tmp/opf-content-text-wp-20261003 eval-file /tmp/opf-content-text-parity-20261003/bin/e2e-content-text-storefront.php
cd /tmp # installed Playwright resolves from /tmp/node_modules
OPF_CONTENT_TEXT_OUT=/tmp/opf-content-text-artifacts-20261003 OPF_CONTENT_TEXT_FREE_SOURCE=/tmp/wapf-free-1.7.1-source/advanced-product-fields-for-woocommerce node /tmp/opf-content-text-parity-20261003/bin/e2e-content-text-storefront.mjs
OPF_CONTENT_TEXT_ALLOW=1 OPF_CONTENT_TEXT_PHASE=cleanup OPF_CONTENT_TEXT_OUT=/tmp/opf-content-text-artifacts-20261003 wp --path=/tmp/opf-content-text-wp-20261003 eval-file /tmp/opf-content-text-parity-20261003/bin/e2e-content-text-storefront.php
php -l /tmp/opf-content-text-parity-20261003/bin/e2e-content-text-storefront.php
node --check /tmp/opf-content-text-parity-20261003/bin/e2e-content-text-storefront.mjs
```

Run cleanup even if browser checks fail, then stop only this PHP server. Both
syntax checks passed. Setup writes `state.json`; the browser writes
`browser-results.json`, both saved-type fragments and `visible.png`; cleanup
writes `cleanup.json` and fails unless the baseline inventory matches.

## Residual acceptance boundary

Proven here: Free 1.7.1 current/legacy save conversion, classic storefront
escaping and entity/text behavior, safe handling of stored raw markup, and
one mapped field condition's hide/show/hide behavior in Chromium. Static fields
have no submitted input. **Not proved:** actual browser editing and admin save,
builder preview, export/reimport roundtrip, block single-product template
rendering, or absence from cart/order/email metadata after checkout. No current
Extended/Pro version claim follows from a Free 1.7.1 proof. The raw markup import
review boundary remains explicit. Ledger promotion is reserved for the owner
after reviewing these remaining surfaces.
