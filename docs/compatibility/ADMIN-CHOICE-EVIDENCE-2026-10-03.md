# adminmisc lane — OPF↔WAPF Extended 3.1.5 parity evidence

Date: 2026-10-13. Lane: `lane/adminmisc` worktree `/tmp/opf-lane-adminmisc`;
disposable clone `/tmp/opf-image-adminmisc-wp` at `http://127.0.0.1:8316`
(WooCommerce 11.1.0, WAPF Extended 3.1.5 licensed/active, OPF 0.1.0 active,
SQLite integration).

All browser proofs are real authenticated wp-admin runs (user `laneadmin`)
via Playwright Chromium, executed from `/tmp/opf-url-native-parity` for its
playwright install. Fixture posts are deleted after each run; a title search
for `LANE` in both `wapf_product` and `opf_field_group` returns zero rows.

## Result files

- `wapf-licensed-admin-results.json` — 19/19 checks, reference side.
- `opf-admin-lane-results.json` — 19/19 checks, OPF side.
- `wapf-capacity-results.json` — 2/2 checks, reference side.
- `opf-developer-api-e2e.log` — developer API + variation parent lookup.
- `wapf-hooks-315.txt` (101 names) / `opf-hooks.txt` (16 names) — hook manifests.
- `wapf-tools-export-A.json` — real WAPF Tools export payload.
- `opf-archive-export.json` — real OPF archive export.
- `wapf-field-groups-list.png`, `wapf-group-editor.png`, `wapf-list-scheduled.png`.

## Per-row outcomes

### WAPF-ADMIN-DUPLICATE-FIELD — parity confirmed, one cosmetic difference

- **Reference (licensed)**: `.wapf-action-dupe` deep-copies the field,
  generates a fresh random id, appends ` (Copy)` to the label; duplicated
  select preserves `choices` incl. `disabled`; persists across real
  post save+reload. (4 checks pass.)
- **OPF**: `Duplicate field` button (`assets/js/opf-builder.js:692`) inserts a
  deep copy after the source with collision-free id `slug-copy(-n)`;
  choices/disabled flag preserved; saved through the real REST route and
  persisted on reload (`field` → `field-copy`). (3 checks pass.)
- **Difference to document**: WAPF renames the label `… (Copy)`; OPF keeps the
  label identical and encodes the copy in the id only. Cosmetic only.
- Verdict: proven both sides. No code change needed.

### WAPF-ADMIN-DUPLICATE-GROUP — parity confirmed, two residual differences

- **Reference (licensed)**: list shows `Duplicate` only for published groups
  (`class-wapf-list-table.php:102`, publish-only confirmed live — a draft row
  has no link). `?wapf_duplicate=<id>` creates a published ` - Copy` post with
  remapped field ids. WAPF source additionally remaps variable/gallery refs
  and hooks `woocommerce_product_duplicate` for product-level copies.
- **OPF**: `post_row_actions` adds nonce-protected `opf_duplicate_field_group`
  on published groups only (`includes/Service/FieldGroups.php:45`); the real
  list action returned `Field group duplicated.`, created a published
  `… (Copy)` group, and remapped field ids + condition refs +
  `[field.*]`/`[price.*]` formula references (verified in stored JSON).
  (4 checks pass.)
- **Residual gaps (documented, not fixed)**:
  1. WAPF's `woocommerce_product_duplicate` product-level copy hook has no OPF
     equivalent — OPF has no per-product groups, so global-group duplication
     parity is complete, but product-duplication parity is N/A by design.
  2. WAPF remaps `variables` and gallery references on group copy; OPF group
     `variables` are currently lost at save for another reason (see BUG-1
     below), so reference remap coverage there is moot until BUG-1 lands.
- Verdict: proven both sides.

### WAPF-ADMIN-IMPORT-EXPORT — proven both sides (licensed UI + OPF archive)

- **Reference (licensed)**: real Tools export emits the 4-part payload
  (`fields`/`conditions`/`layout`/`variables`); licensed UI import `replace`
  shows "Import done", populates the model, persists after save+reload on the
  target group, and remaps field ids; `append` mode appends (2→4) and persists.
  This resolves the previously open "licensed UI round-trip" item — the earlier
  failure was the license-gated editor being disabled, now unblocked by the
  seeded license + `_ga_atob_enq_` localStorage flag. (6 checks pass.)
- **OPF**: `wp opf export --group=<id>` writes the `opf-field-groups` archive;
  `wp opf import_archive` dry-run reports `Would import 1 group(s)` without
  writing; `--commit` creates a new published group preserving the field
  structure and ids verbatim (round-trip format — unlike WAPF's cross-site
  remapping); re-import reports `already-imported` (checksum dedupe, no
  duplicate posts). (5 checks pass.)
- **Note**: `wp opf export` self-validates via `ArchiveImporter::decode` and
  rejects post_content that would not survive `FieldGroup::normalize`
  byte-identically — hand-written minimal fixtures fail export with "contains
  fields or settings this OPF version cannot preserve". Correct strictness;
  groups saved through `FieldGroups::save`/builder REST export cleanly.
- Verdict: proven both sides.

### WAPF-CHOICE-DISABLED — proven both sides incl. licensed Tools round-trip

- **Reference (licensed)**: the `disabled` toggle persists in the stored model
  after save+reload and is preserved by field duplication. The earlier
  "license restriction/errors" blocker is resolved — the licensed editor works.
- **OPF**: builder "Unavailable" checkbox sets `disabled`, the flag persists in
  stored JSON after REST save+reload (`Beta` → `disabled:true`), and disabled
  choices cannot remain preselected (existing checked behaviour).
- Verdict: proven both sides.

### WAPF-CHOICE-CAPACITY — WAPF side now exercised; OPF side already proven

- **Reference (licensed)**: a real `post.php` save of a select field carrying
  200 choices persisted all 200 (`first=Option 1, last=Option 200`) and the
  editor reload returned the full model — no fixed cap in WAPF's schema or
  save path. (2 checks, `wapf-capacity-results.json`.)
- **OPF**: unchanged — the 512-fields/512-choices authenticated proof stands
  (`WAPF-CHOICE-CAPACITY-BROWSER-EVIDENCE-2026-10-01.md`).
- **Remaining documented deltas**: neither side advertises/proves a hard cap;
  storefront/commerce lifecycle at high counts remains untested on both.
- Verdict: proven to tested capacity both sides.

### WAPF-CHOICE-BULK-IMPORT — proven both sides on the integrated branch

- **Reference (licensed)**: bulk modal imported `Gamma\nDelta\n\n Epsilon ` →
  "3 out of 4 items imported" (blank counted in denominator, skipped; values
  trimmed); choices appended in DOM; persisted through save — WAPF reserializes
  `wapf-fields` on `wapf/admin/before_submit`, so the hidden input only lags
  until submit (verified in stored post_content: all 5 labels, `disabled`
  flag on `Beta` kept).
- **OPF**: `details.opf-b-bulk-import` → same input produced
  "3 out of 4 lines imported.", DOM rows = `Alpha|Beta|Gamma|Delta|Epsilon`,
  and all 5 persisted through REST save+reload.
- **Difference note**: WAPF reports "N out of M items imported" where M counts
  blank lines; OPF reports the same denominator semantics ("N out of M lines").
  Invalid swatch colors default to `#FFFFFF` in OPF (documented safer default).
- Verdict: proven both sides.

### WAPF-GROUP-SCHEDULING — proven both sides

- **Reference (licensed)**: the WAPF list table renders `Scheduled` for future
  groups and `<strong class="error-message">Missed schedule</strong>` for
  past-due `future` posts (live-verified; missed post needed a direct DB write
  because `wp_insert_post` normalizes future+past-date to publish, and the
  list sorts by date desc so `orderby=date&order=asc` was used to surface it).
- **OPF**: the native WP list (`edit.php?post_type=opf_field_group`) labels the
  same states identically (`Missed schedule` / `Scheduled`); `FieldGroups::all()`
  only queries `post_status=publish` so a future group does not resolve for a
  product until `publish_future_post` fires (verified: `resolved=no`).
- Verdict: proven both sides. OPF has no custom start/end window UI — same
  scope note as before.

### WAPF-UPLOAD-AJAX-UI — still partial; residual gaps documented

- `assets/js/opf-uploads.js` (85 lines) confirms: drag/drop `drop` handler,
  `<progress>` element with `aria-label`, add/remove with per-file DELETE,
  submit-blocking while busy — no `<img>`/`FileReader`/`createObjectURL`
  anywhere → **thumbnail previews are absent** (confirmed gap).
- `FieldGroup::FIELD_TYPES` includes `'upload'`, but the builder `TYPES` list
  (`opf-builder.js:29`) does **not** — upload fields cannot be authored in the
  OPF builder UI (import/programmatic only) → **builder/settings UI gap**
  confirmed for this row. (Builder JS is outside this lane's allowed impl
  scope; not fixed here.)
- Order-again retry and a11y/responsive coverage remain open as before.
- Verdict: bug-documented/partial — no regression; gaps narrowed, not closed.

### WAPF-INTEGRATION-GIFT-CARDS — proven (parent-product lookup)

- `bin/e2e-variation-parent-lookup.php`: created a real variable product +
  variation, an OPF group with rule `product in [parent_id]`, then resolved
  `FieldGroups::for_product(wc_get_product($variation_id))` → matched the
  parent-targeted group, and `woocommerce_add_to_cart_validation` for the
  variation returned `false` with the required field missing — the exact
  WAPF 1.6.22 parent-lookup behavior.
- Scope note unchanged: no third-party gift-card plugin adapter exists or is
  claimed; this proves the underlying parent-product lookup only.
- Verdict: proven.

### WAPF-DEVELOPER-HOOKS — manifest evidence; no drop-in layer claimed

- WAPF Extended 3.1.5 scan: `wapf-hooks-315.txt` — 101 unique hook names
  (97 `wapf/…` + 4 legacy `wapf_…`), e.g. `wapf/admin/sanitize_field`,
  `wapf/field_template_model`, `wapf/cart/item_data`, `wapf_upload_ajax`.
- OPF scan: `opf-hooks.txt` — 16 names: `opf_addon_price`,
  `opf_cart_item_base_price`, `opf_features_linked_products`,
  `opf_formula_base_price`, `opf_frontend_config`, `opf_groups_for_product`,
  `opf/linked_products/{by_id_args,choice,query_args}`, `opf_lookup_tables`,
  `opf/setting/{name}` (dynamic), `opf_show_price_hints`, `opf_show_totals`,
  `opf_skip_validation`, `opf_theme_compat`, `opf_upload_session_budget`.
- Verdict: documented crosswalk — OPF exposes a smaller namespaced surface
  with no WAPF-named aliases; purposes don't map one-to-one (as ledgered).

### WAPF-DEVELOPER-PHP-API — proven

- `bin/e2e-developer-api.php` on the clone: "Developer API settings, groups,
  rendering, cart values, and immutable order snapshot passed." plus the
  parent-lookup fixture above.
- Verdict: proven; signatures are OPF-namespaced (not WAPF drop-in), matching
  `docs/DEVELOPER-API.md`.

## Bugs documented (not fixed — outside lane impl scope)

### BUG-1 (Engine): `FieldGroup::normalize()` silently drops `variables`

- `new FieldGroup(['…', 'variables'=>[…]])` → `data` keys lose `variables`
  (verified live: `in: schema,fields,rule_groups,mark_required,labels_position,
  variables` → `out: schema,fields,rule_groups,mark_required,labels_position`).
- End-to-end: `FieldGroups::save(0, $data_with_variables)` persists
  post_content with `vars=DROPPED`.
- Impact chain: `Engine\WapfMapper` *emits* `variables` on the mapped group
  (line ~301), `CartIntegration` *reads* `$group->data['variables']` (lines
  538, 566), `WapfExporter` *emits* a `variables` key — but every save path
  (`FieldGroups::save`, `Importer` line 285 `new FieldGroup($mapped['group'])`,
  `ArchiveImporter` line 127) re-normalizes and discards them. WAPF-imported
  groups with variables lose them silently at save; formulas referencing
  variables then cannot evaluate them.
- Also blocks full parity credit for group-duplicate variable remapping.
- Suggested owner: Engine (`FieldGroup::normalize` whitelist) + Importer;
  integrator decision required.

### BUG-2 (reference-side note): WAPF `wapf-fields` hidden input lags the model

- `addChoice` pushes into the field model without calling the list
  controller's `onChange`, so `input[name=wapf-fields]` stays stale until the
  form's `wapf/admin/before_submit` handler reserializes (verified: DOM had 5
  option rows while the input still showed 2; save persisted all 5). Not an
  OPF bug — documented so future probes don't misread the hidden input.

### Reference noise recorded as `referenceJsErrors`/warnings (not OPF)

- WAPF admin runs intermittently logged a minified-JS error
  (`this[obf…][obf…] is not a function`) on earlier runs; the final licensed
  run logged none. Per brief these are reference-side.
- `wp eval` runs emit WAPF's own `unserialize()` warnings from
  `class-field-groups.php:784` when WAPF scans its own stored posts — noise
  from WAPF's storage format, unrelated to OPF.

## Cleanup

Every fixture post (`wapf_product`/`opf_field_group` titled `LANE *`, the
duplicate copies, the archive source/import, scheduling fixtures, variable
product + variation) is deleted at end of each script; a post-run sweep
verifies zero `LANE`-titled posts remain in either post type and plugin state
is unchanged (WAPF Extended + OPF active, as at baseline).
