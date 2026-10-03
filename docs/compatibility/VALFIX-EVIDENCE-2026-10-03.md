# Lane valfix — evidence summary

Worktree `/tmp/opf-lane-valfix` (branch `lane/valfix`). Clone
`/tmp/opf-image-valfix-wp` @ http://127.0.0.1:8325. No commits/pushes/ledger edits.

## Implemented

1. **Checkbox min/max choices (WAPF-FIELD-CHECKBOX)**
   - `FieldGroup::normalize_field` stores `min_choices`/`max_choices` (int 1..10000, min<=max).
   - Builder controls; `Renderer::render_choices` emits `data-min-choices`/`data-max-choices`.
   - `opf-frontend.js` max guard extended to checkbox (keeps at most max selected).
   - `CartIntegration::validate_values` enforces max on any submitted value, min only once a
     value exists (empty optional below min accepted, empty required rejected) — mirrors WAPF
     `validate_multiple_choice_field` (class-cart.php:332-341).
2. **Text length/regex (WAPF-FIELD-TEXT-VALIDATION)**
   - `FieldGroup::normalize_field` stores `minlength`/`maxlength` (text+textarea) and `pattern`
     (text). Builder controls added. `Renderer::text_validation_attrs` emits them. No server
     enforcement (WAPF parity).
3. **Hidden/forged value drop (cross-cutting defect)**
   - `CartIntegration::drop_hidden_values` + `field_visibility` drop values for conditionally
     hidden fields and children of hidden sections before validation/pricing/persist, including
     per-row repeat filtering. Replaces the earlier toggle-only attach filter.
   - `opf-frontend.js` now disables every hidden control (author-disabled choices preserved),
     matching WAPF's conditional handler. Section ancestors hide/disable children.
4. **`nrq`/`charq` pricing import**
   - `WapfMapper::map_value_pricing` maps `nr`/`nrq` → `[x] * amount` and `char`/`charq` →
     `len([x]) * amount`; `nrq`/`charq` are per-unit, `nr`/`char` flat per line. `normalize_formula`
     probe now accepts `len(...)`.
5. **Constraint import keys**
   - `WapfMapper::map_checkbox_limits` (min/max_choices) and `map_text_validation`
     (minlength/maxlength/pattern) read both the parsed `options` shape and the raw Tools
     top-level shape.

## WAPF Extended 3.1.5 reference (empirical)

- Checkbox: `3 of max 2` → rejected; `0 of min 1` optional → accepted; `2` → accepted.
- Number: below min / above max → rejected (OPF out of scope this lane).
- Text: pattern breach and too-short → accepted (no server enforcement).
- Hidden forged values: WAPF **validates** field constraints and **persists/prices** them
  (`wapf-hidden-probe.json`). This lane deliberately drops them instead, extending the project's
  prior toggle-lifecycle hardening (see `TOGGLE-FIELD-LIFECYCLE-EVIDENCE.md`); documented divergence.
  WAPF does skip the *required* check for hidden fields, which OPF now also does via section
  propagation.

## Artifacts

| Artifact | Result |
|---|---|
| `phpunit-full.txt` | 555 tests / 2275 assertions, 0 failures (baseline was 537) |
| `opf-fieldb-report.json` | 95 checks / 0 failures (OPF lifecycle, WAPF inactive) |
| `wapf-ref-contract-run.txt` / `wapf-ref-contract.json` | WAPF checkbox/text/validation contract |
| `wapf-hidden-probe.json` | WAPF hidden forged behavior |
| `wapf-reimport-result.json` | OPF export → WAPF parser: 0 failures |
| `opf-browser-run.txt` | Chromium OPF: 10/10 |
| `wapf-browser-run.txt` | Chromium WAPF: 4/4 |
| `opf-rendered-group.html`, `opf-import-map.json`, `opf-exported-wapf-payload.json` | raw captures |

## Notes / boundaries

- Tools export of the new text-validation keys (`minlength`/`maxlength`/`pattern`) and checkbox
  limits fails closed in `WapfExporter` (not owned this lane); import-only scope.
- `tests/js/opf-builder-auth.test.cjs` fails identically on baseline HEAD (mock lacks
  `querySelector`); pre-existing, unrelated.
- Existing `bootstrap.php` unit suite does not run the bin/ PHP E2E; those were run via wp-cli.
