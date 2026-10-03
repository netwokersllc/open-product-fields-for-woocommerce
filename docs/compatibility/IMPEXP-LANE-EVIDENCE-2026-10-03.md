# impexp lane evidence — ADMIN-IMPORT-EXPORT + FIELD-UPLOAD + CONTENT-IMAGE

Worktree: `/tmp/opf-lane-impexp` @ `405a5f6` (uncommitted changes only).
Clone: `/tmp/opf-image-valfix-wp`, site `http://127.0.0.1:8325`.
Reference: WAPF Extended **3.1.5** (`advanced-product-fields-for-woocommerce-extended`
activated only for the round-trip run, then returned to inactive).
OPF symlink was pointed at the worktree for the run and restored to
`/tmp/opf-lane-valfix` afterwards.

## Deliverables

| Artifact | Meaning |
| --- | --- |
| `wapf-tools-export-fixture.json` | Real WAPF Tools export produced by WAPF's own `Field_Groups::field_group_to_raw_fields_json()` from a native model built by `raw_json_to_field_group()`. |
| `opf-imported-group-1.json` | OPF import (`WapfMapper::map`) of that fixture. |
| `opf-exported-wapf-payload.json` | OPF re-export (`WapfExporter::build_payload`). |
| `opf-imported-group-2.json` | WAPF native reimport (`raw_json_to_field_group`) → OPF map. Fixed point vs group 1. |
| `impexp-wxr.xml`, `wxr-native-model.json` | WXR path through WAPF `process_data()`. |
| `impexp-results.json` | 25/25 assertion records + environment. |
| `impexp-run.log` | Verbatim run output. |

Run:

```bash
OPF_IMPEXP_E2E_ALLOW=1 OPF_IMPEXP_E2E_WP=/tmp/opf-image-valfix-wp/ \
OPF_IMPEXP_E2E_ARTIFACTS=/tmp/opf-lane-impexp-evidence \
wp --path=/tmp/opf-image-valfix-wp eval-file bin/e2e-impexp-tools-roundtrip.php
# {"passed":25,"total":25,"needs_review":true,
#  "notes":["field \"Fabric guide\" uses a site-local image attachment ID; verify or remap the attachment on the destination site."]}
# ok impexp WAPF Tools round-trip: 25/25 checks
```

## Code changes

`includes/Engine/WapfMapper.php`
- `map_placement()` now maps WAPF group conditions `product_var` → `product_var`,
  `patts` → `var_att`, `product_type` → `product_type` (the subjects the RULE
  lane added to `Evaluator`). `!`-negation maps to `not_in`.
- `map_conditionals()` maps WAPF variation-scoped field conditionals
  (`product_var`/`!product_var`, `patts`/`!patts`) onto OPF `product_var`/
  `var_att` subjects instead of dropping them; empty-term rules are flagged.

`includes/Service/WapfExporter.php`
- `map_placement_rule()` exports OPF `product_var`/`var_att`/`product_type`
  placement as WAPF `product_var`/`patts`/`product_type` conditions (WAPF's own
  export projection keeps those condition names intact).
- `map_conditionals()` fails closed with an explicit error for
  variation-scoped field conditionals, which WAPF Tools cannot represent.

`tests/Unit/WapfMapperTest.php`, `tests/Unit/WapfExporterTest.php`
- 4 new tests covering import of variation/attribute/type placement (both
  polarities), import of variation-scoped field conditionals, export +
  round-trip of the same, and export refusal for variation field conditionals.

`bin/e2e-impexp-tools-roundtrip.php` (new)
- Generates the real WAPF Tools fixture, imports, exports, reimports through
  WAPF's own parser, checks the fixed point, and repeats through WXR.

## Row outcomes

### WAPF-ADMIN-IMPORT-EXPORT — fixed + re-proven
Now mapped and proven through WAPF's native parser and WXR:
`product_var`/`!product_var`, `patts`/`!patts` → OPF `product_var`/`var_att`,
and `product_type`/`!product_type` (positive and negated polarities).
Field-level variation conditionals import onto the OPF variation subjects. The
round-trip reaches a fixed point (`opf-imported-group-1` ==
`opf-imported-group-2`, asserted). Negation is checked explicitly
(`placement_negated_var_att`, `export_negated_patts`).

Honest residuals that stay:
- **Source group IDs/titles** are not part of WAPF's four-value Tools payload
  (`.fields/.conditions/.layout/.variables`); WAPF's own export does not carry
  them, so there is nothing to remap. OPF import titles global groups from the
  source post; product-local groups from the product title.
- **Linked-product references** are site-local by definition. WAPF's own Tools
  import remapping loop is empty and OPF import/archive keep the IDs with
  review/warning rather than guessing. Documented portability caveat.
- **Append does not preserve nonempty conditions/layout** is WAPF's own append
  semantics (conditions always replace, layout always rebuilt — source audit,
  `WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md` §Installed 3.1.5 Tools
  import/export behavior). OPF cannot change the reference plugin; kept as a
  documented WAPF behavior.
- **Generated field-condition rules**: WAPF's `field_group_to_raw_fields_json()`
  strips `generated` and `product_var`/`patts` rules from *field* conditionals
  on its own export. These are runtime-merged gates, not stored data; the
  round-trip test proves no data is silently lost from stored groups.
- **`pa_*` attribute-term placement** has no WAPF Tools equivalent (WAPF uses
  `attr|value` pairs); OPF export fails closed for it rather than emitting a
  lossy rule. `var_att`/`patts` is the 1:1 path.
- Licensed WAPF Tools *UI* persistence is still only reachable via the
  underlying `raw_json_to_field_group`/`field_group_to_raw_fields_json`
  helpers (the 3.1.5 minified Tools controller is not shipped executable in the
  installed package); current 3.2.1 behavior remains unaudited.

### WAPF-FIELD-UPLOAD — residual note was stale; re-proven
The mapper (`map_upload_settings`) **and** the exporter upload block already
existed at `699e2e9`; the ledger's "builder controls and WAPF import/export
mapping/data round-trip are absent" was stale. Builder controls live in
`assets/js/opf-builder.js::uploadEditor()`. This run proves the data path:
- WAPF `file` options `multiple=true`, `maxsize=4.5`, `accept=jpg|jpeg|jpe,pdf`
  (alternate-extension group) → OPF `multiple=true`, `max_size=4.5`,
  `accepted_types=["jpg","jpeg","jpe","pdf"]` → export `file` with
  `accept=jpg,jpeg,jpe,pdf` → native WAPF reimport → same values.
- Same through WXR (`process_data()`).
Checks: `upload_type`, `upload_multiple`, `upload_max_size`,
`upload_accept_alt_group`, `upload_hide_order`, `export_upload_file`,
`wxr_upload`, `tools_roundtrip_fixed_point`.

Honest residual that stays: guest session-loss recovery, broader
platform/storage compatibility, and upload-specific repeaters remain out of
scope for this lane.

### WAPF-FIELD-CONTENT-IMAGE — export edge closed
OPF `content_image` now proven end-to-end through WAPF's own formats:
- OPF field (`image_url=https://example.test/fabric.jpg`, `image_id=<attachment>`)
  → WAPF `img` with `image` + `attachment` → native `raw_json_to_field_group`
  → back to OPF `content_image` with both values intact (`content_image_type`,
  `content_image_url`, `content_image_attachment`, `export_content_image`).
- Same through WXR (`wxr_content_image`).

Honest residuals that stay (documented differences, not fixable here): the
alt-text source semantic differs (OPF authored label vs WAPF attachment alt),
and neither Tools nor WXR bundles media, so destination attachment IDs require
remapping/review — the mapper emits exactly that review note (visible as the
single `needs_review` note in the run).

## Regression

```
vendor/bin/phpunit --testsuite Unit
# Tests: 681, Assertions: 2798, PHPUnit Deprecations: 1 (pre-existing) — OK

node --test tests/js/*.test.cjs
# tests 40, pass 40, fail 0
```

`git status` after the run: only the 4 modified source/test files plus the new
`bin/e2e-impexp-tools-roundtrip.php` (no commits made, no ledger edits).
