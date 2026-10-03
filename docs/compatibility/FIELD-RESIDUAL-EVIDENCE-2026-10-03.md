# fieldb lane — FIELD-type residual proofs

Worktree: `/tmp/opf-lane-fieldb` @ `1e74aa0` (rebased main). Clone: `/tmp/opf-image-fieldb-wp`
(`http://127.0.0.1:8312`, SQLite + WooCommerce 11.1.0, WAPF Extended 3.1.5 installed for reference runs only).

Relaunch note: this is the second agent on the lane. The prior run's report was generated pre-rebase;
this run cleaned up both interrupted fixture sets (`opf_rules_e2e_state`, `opf_fieldb_state`), re-ran the
entire lifecycle fresh at `1e74aa0`, and added the remaining reference gates. Upstream changes absorbed:
signed `Calculator::field_addon`, group `variables` preserved through `normalize()`, WAPF `[qty]`/fx
per-unit semantics (removed `entered_qty × fixed_choice` multiplication for `image_quantity`).

## Totals

| Artifact | Checks | Fail |
|---|---|---|
| `opf-fieldb-report.json` (OPF lifecycle, fresh at 1e74aa0) | 91 | 0 |
| `wapf-reimport-result.json` (OPF export → WAPF 3.1.5 parser + serialization audit) | 18 | 0 |
| `bin/e2e-image-swatch-renderer-test.php` (read-only WP runtime) | 5 | 0 |
| `bin/e2e-image-swatch-browser-test.mjs` (static CSS: 4/2/1 cols, hover+focus zoom, reduced motion) | 1 | 0 |
| `wapf-ref-contract.json` (WAPF-side render/validation/design capture, Extended 3.1.5) | contract data | — |

## Per-row outcomes

### WAPF-FIELD-SWATCH-COLOUR — proven (commerce lifecycle now executed)
Render: `data-color-layout=circle`, per-choice `--opf-swatch-color:#FF0000`/`--opf-swatch-size:34px`,
`data-color-label-position="tooltip"`, priced choice `data-opf-price`. Commerce: classic validation +
cart capture; priced choice +3.00 → line 16.50; Store API accepts unpriced choice (base kept); forged
slug silently dropped, never persisted. Order: `_opf_fields` stores `red`, public meta shows `Pick a
colour`, HTML + plain emails render it, order-again restores exact values/price. Export → `color-swatch`
with `layout/size/label_pos/choices[].color`; reimport through WAPF's own parser preserves every option
(`wapf-reimport-result.json`). Residual: non-hex palettes stay review-required.

### WAPF-FIELD-SWATCH-IMAGE — proven
Render: `opf-image-swatch-wrapper` + flexible grid CSS vars (3/2/1), `data-label-position="out"`,
real attachment `<img>`, `opf-swatch-zoom-preview`. Store API persists slug; order-again restores.
Export → `image-swatch` with `grid_layout/label_pos/large_image/item_width/items_per_row*`; reimport
survives. Standalone proofs: `e2e-image-swatch-renderer-test.php` 5/5 (media-library image used,
zoom uses full size, alt text, input kept), `e2e-image-swatch-browser-test.mjs` responsive+zoom pass.
Residual: imported attachment IDs may need remapping (noted by importer; needs_review).

### WAPF-FIELD-SWATCH-MULTI — proven
Render: checkbox inputs `…[multitext][]` + `data-min-choices=1`/`data-max-choices=2`. Commerce:
2-of-2 accepted (+1.50 priced choice), 3-of-max2 rejected server-side (400), 0-of-min1 rejected.
Order meta + email + order-again. Export → `multi-text-swatch` with `min_choices/max_choices`;
reimport preserves both bounds.

### WAPF-FIELD-IMAGE-QUANTITIES — proven incl. NEW WAPF-parity pricing contract
Render: per-choice `type=number` inputs with `min/max/step/default` (blu 1..2 verified).
Commerce: in-bounds accepted; aggregate max (6>5) rejected; per-choice max (5>3) rejected;
non-numeric rejected; all-zero rejected 400 (blu `min=1` forces one). Order persists structured
`quantities` map; email + order-again verified.

**New at 1e74aa0 — pricing parity verified against installed `class-fields.php` `do_pricing()`:**
WAPF `fixed`/`default` rows return `$amount` or `$amount/$qty` — the entered image count does **not**
multiply a fixed charge; `nr`/`nrq`/`char`/`charq`/`fx` consume the count via `$v`/`[x]`. OPF now
matches: `fixed` red ×2 = **$2 flat** (was $4 pre-rebase — stale expectation corrected, three commerce
checks updated), and Calculator-level checks prove `[x]`/`[val]` formulas consume the count
(2×$2=$4) and both types divide to per-unit contributions at line qty=2 (fx $4→$2; fixed $2→$1),
exactly WAPF's `$x/$qty` and `$amount/$qty` rows.
Import: `image-swatch-qty` → `image_quantity` preserving bounds/defaults; **`nrq` pricing →
"not supported; imported without pricing" + needs_review** (fail-closed, no silent repricing).
Export: `image-swatch-qty` with `min_choices/max_choices` + per-choice `options.{min,max,default}`;
reimport through WAPF parser preserves all of it.

### WAPF-FIELD-URL — proven (the ledger's open protocol-matrix gate is now closed)
`type=url` rendered. Full `wp_allowed_protocols()` matrix through real `FieldValue::validate`:
all 23 allowed schemes accept (incl. `ftps`, `tel`, `urn`, `xmpp`, `webcal`); `javascript:`/`data:`/
`vbscript:`/`file:` reject, as do `javascript:alert(1)`, base64 data URL, `file:///etc/passwd`,
`notaurl`, `//example.com`; `https://example.com/ok path` accepts (documented WAPF-compatible space
tolerance). Store API rejects `javascript:`, accepts `ftps://`. Prior clone browser run
(`url-artifacts/`): admin REST save/reload, storefront, classic cart, checkout, order-received —
all checks pass.

### WAPF-FIELD-SECTION — proven + two behavioral differences documented
Render: `opf-section` + `has-conditions`, hidden at default (gate unchecked), button repeat
`data-opf-section-repeat`/`data-opf-repeat-max=3`/`opf-field-repeat__add "Add row"`, row-indexed
input names `…[sectext][0]`. Commerce: repeated rows `[Alpha,Beta]` persist as row array;
required child enforced while section visible; 4-row submit over `max=3` rejected; checkout order
persists row + toggle; order-again restores. Export: `section`/`sectionend` markers + the
section's `gate`-conditional rule survive WAPF parser reimport.
**Documented differences (observations in report):**
- Required child inside a *hidden* conditional section: OPF rejects (400). WAPF merges section
  conditions into enclosed fields and skips them — OPF is stricter.
- Forged value inside a *hidden* section: accepted 201 and **persisted** (`stored:["forged"]`).
  Candidate bug for the Renderer/FieldGroup owners — integrator should evaluate.
- Section `repeat` clone settings fail export closed:
  `WAPF Tools export cannot preserve unknown field data: repeat.` (documented export gap).

### WAPF-FIELD-CONTENT-TEXT — proven; the ledger's OPF→WAPF reimport gate is now executed
Render: plain content fully escaped (`&amp;`/`&lt;b&gt;`/`©`, no live markup); `html` format
allowlists `<strong>` and strips `<script>`. Paragraphs never submit order data.
Export: clean paragraphs → `content` (plain) / `p` (html, requires `process_shortcodes`),
`p_content` carried; HTML-in-`content` and disabled-shortcode export **fail closed**
("WAPF Free sanitizes paragraph content; HTML cannot be exported without loss.").
**New:** `opf-exported-paragraph-payload.json` reimported by `raw_json_to_field_group` —
`p` keeps `p_content` verbatim (`<b>x</b>`); Free-legacy `content` type is stored verbatim by the
parser (Extended cannot render that type — documented difference). Caveat: only Extended 3.1.5 was
available for reimport; Free 1.7.1 reparse is untested.

### WAPF-FIELD-TRUE-FALSE-SWITCH — reference serialization verified (was "unavailable")
Toggle lifecycle: `gate` value persists through composite order + order-again; section condition
on toggle verified. **New — live `Config::get_field_options()` audit on Extended 3.1.5:**
`true-false` settings = `message`, `default`(checked/unchecked), `label_true`, `label_false`,
`pricing`, `weight` — **no switch key exists in 3.1.5** (the Pro changelog switch postdates it).
OPF `switch_control` therefore remains review-required against the installed reference; Extended
extras `weight`/`hide_zero`/`selects` also unmapped (see serialization block in reimport result).

### WAPF-FIELD-STYLED-CHECKBOX-RADIO — reference contract captured
Extended 3.1.5 implements styling as a **global design layer**, not per-field:
`wapf_design_settings` flat keys (`apf-cb-display=styled`, `apf-cb-bg`, `apf-radio-border-width`,
`apf-ns-*`…) → `Design_Helper::design_settings_to_variables_css()` emits `:root` vars +
`.wapf-custom` CSS (full CSS + config IDs in `wapf-ref-contract.json`). Checkbox/radio markup
carries `<span class="wapf-custom">` for the styled skin. OPF's opt-in Woo→Design setting is a
documented difference (ledger evidence stands).

### WAPF-FIELD-CHECKBOX — gap documented; reference limits contract now precise
OPF: no `min_choices`/`max_choices` on `checkbox` (render + imported-field-keys observations);
accepts any count; forged disabled choice rejected server-side.
Extended 3.1.5: checkboxes expose **only** `options` + `min_choices`/`max_choices` (live audit).
Server validation: **max enforced** (3-of-2 → `"Limited checkboxes" requires at maximum 2 selections`),
**min NOT enforced on optional fields** (0-of-min1 → `true`) — i.e., WAPF's min binds only together
with `required`. Import: both keys dropped by OPF mapper (documented).

### WAPF-FIELD-CHECKBOX-COLUMNS — reference serialization verified absent (was "unverified")
Live audit on installed 3.1.5: no `columns` setting key for `checkboxes` (only `options`,
`min_choices`, `max_choices`). The Pro changelog feature postdates 3.1.5, so there is no
importable serialized key to map; OPF's native columns evidence (ledger) stands unchallenged.

### WAPF-FIELD-NUMBER-STEP-VALIDATION — reference serialization verified (was "unverified")
Extended 3.1.5 `number` settings (live audit + `class-config.php:599-640`): `number_type`
(int/any, default int), `display`, `default`, `placeholder`, `minimum`, `maximum`, `pricing`,
`hide_zero`, `weight` — **no `step` key**. Renderer emits `step` only as the literal `step="any"`
for `number_type != int` (`class-html.php:745-746`). Server: `2.5` accepted on `int` field —
WAPF does not server-enforce int-only either (contract `number_decimal_int → true`).
OPF: renders no `min/max/step` attrs (gap markup in report); non-numeric submission accepted 201,
stored as `null`. OPF's native int/decimal+step exists per ledger; parity boundary = WAPF has
browser-only int semantics, no numeric step serialization to import.

### WAPF-FIELD-NUMBER-STEPPER — reference contract verified
Extended 3.1.5: **per-field** `display: plus_min` → `apf-plusmin` wrapper + `apf-minus`/`apf-plus`
buttons (contract `numstep.html`), plus global `apf-ns-*` design vars. Serialized `display` is a
reserved raw key (`class-field-groups.php:166`). OPF uses a **global** stepper toggle — documented
difference, ledger evidence stands.

### WAPF-FIELD-TEXT-VALIDATION — gap documented both directions
OPF: renders no `minlength/maxlength/pattern` attrs (markup observation); server accepts anything.
WAPF 3.1.5: settings exist (live audit: `minlength`, `maxlength`, `pattern`), parser stores them
(`wapf-ref-stored-model.json`), renderer emits native attrs (`minlength="3" maxlength="5"
pattern="[a-z]+"`) — **but server validation does not enforce them** (pattern breach → `true`,
too-short → `true`; `Cart::validate_cart_data` capture). Import: keys dropped by OPF mapper.
Residual: implement schema+renderer+server+mapper to promote; WAPF has browser-only enforcement.

### WAPF-FIELD-CALCULATION — import drop documented (fail-closed)
WAPF `calc` renders `data-formula` span + hidden `wapf[field_calc]`/`[field_calc_raw]` inputs
(contract). OPF mapper drops the field entirely with `unsupported field types dropped:
calc:Calculated` + `needs_review` — no silent mis-parse. OPF native calc/calculator parity is
covered by the formula lanes (ledger).

## Baseline restoration

- Removed interrupted-run leftovers: `opf_rules_e2e_state` (7 products, 19 groups, 2 users, 1 variation,
  4 terms, republished 8 baseline groups — script's own cleanup phase verified its recorded baseline),
  `opf_fieldb_state` fixture (product/group/attachment/upload/user/5 orders), mu-plugin
  `wp-content/mu-plugins/opf-fieldb-e2e-login.php` (prior-lane leftover; `dbg-render`/`probe-hooks`
  are base-image files present on all clones — untouched).
- Final clone state = baseline: `product 5, product_variation 0, opf_field_group 12, wapf_product 746,
  shop_order 254, attachment 1, users 1`; terms/attributes restored; `active_plugins` = OPF + SQLite +
  WooCommerce (WAPF Extended returned to inactive after the reimport run); no `*_state` options;
  uploads clean (`woocommerce-placeholder` + `.opf-private` only).

## Reproduce

```bash
# OPF lifecycle (WAPF inactive): setup → render → importexport → commerce → cleanup
OPF_FIELDB_ALLOW=1 OPF_FIELDB_OUT=/tmp/opf-lane-fieldb-evidence OPF_FIELDB_PHASE=<phase> \
  wp --path=/tmp/opf-image-fieldb-wp eval-file bin/e2e-fieldb-lifecycle.php

# WAPF reference (Extended active): setup → capture → cleanup; standalone: reimport
wp --path=/tmp/opf-image-fieldb-wp plugin activate advanced-product-fields-for-woocommerce-extended
OPF_FIELDB_ALLOW=1 OPF_FIELDB_OUT=/tmp/opf-lane-fieldb-evidence OPF_FIELDB_PHASE=reimport \
  wp --path=/tmp/opf-image-fieldb-wp eval-file bin/e2e-fieldb-wapf-reference.php
wp --path=/tmp/opf-image-fieldb-wp plugin deactivate advanced-product-fields-for-woocommerce-extended
```

Artifacts: `opf-fieldb-report.json`, `opf-rendered-group.html`, `opf-import-map.json`,
`opf-exported-wapf-payload.json`, `opf-exported-paragraph-payload.json`, `wapf-ref-contract.json`,
`wapf-ref-raw-input.json`, `wapf-ref-stored-model.json`, `wapf-reimport-result.json`.
