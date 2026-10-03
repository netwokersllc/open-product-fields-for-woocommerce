# Lane calcfield — WAPF-FIELD-CALCULATION evidence

Worktree: `/tmp/opf-lane-calcfield` (head `405a5f6` + working-tree changes, **not committed**)
Site: `http://127.0.0.1:8310` (clone `/tmp/opf-image-migration-wp`, Woo 11.1, WAPF Extended 3.1.5 inactive, OPF symlinked to this worktree during the runs).
All artifacts in this directory.

Implementation reuses OPF's single formula engine: `OPF\Engine\Evaluator`/
`Calculator::evaluate_formula` server-side and `evalFormula` in
`assets/js/opf-frontend.js` (no second engine).

## 1. Code changes

| File | Change |
| --- | --- |
| `includes/Engine/FieldGroup.php` | `calc` in `FIELD_TYPES`; normalize `calc_type`/`formula`/`result_format`/`result_text`; `cost` derives signed `formula` pricing; `[options_total]`→`[addons]`; repeat dropped. |
| `includes/Engine/Calculator.php` | `calc_value()` helper (shared evaluator); cost calcs price via the existing field-formula path. |
| `includes/Service/Renderer.php` | `calc` compat type, registry metadata (`calc_type`/`formula`/`result_format`/`result_text`), calc markup (`opf-calc`, `opf-calc-text`, `opf-calc-raw`), cart-edit prefill. |
| `includes/Service/CartIntegration.php` | calc value sanitisation (numeric, fail-closed), `display_calc()` (`result_text`/`result_format`, currency for cost), drops/prices per condition visibility. |
| `includes/Engine/WapfMapper.php` | `calc`→`calc`; `map_calc_settings()` + field-ID remap via the shared formula remapper; review only for unportable refs / bad formats / repeat placement. |
| `assets/js/opf-frontend.js` | Live calc sync: dependency graph, topological order either field order, cycle fail-closed, hidden clears raw, `result_format`/`result_text`/currency formatting; reads `.opf-calc-raw` as the field value; deferred `init` (module TDZ fix). |
| `assets/js/opf-builder.js` | Registers `calc` with calc-type / formula / result-format / result-text controls. |
| `includes/Service/WapfExporter.php` | Exports `calc` (options + remapped formula, `[addons]`→`[options_total]`). |
| `includes/Service/WapfWxrExporter.php` | Serializes calc options into the stored `options` bucket. |
| `docs/CAPABILITIES.md` | Capability matrix lists `calc`. |

Tests added: `tests/Unit/CalcFieldTest.php` (10), one `RendererDisplaySettingsTest` case, `tests/js/opf-calc-field.test.cjs` (8), `tests/js/opf-calc-builder.test.cjs` (1).

## 2. Test suites

```
cd /tmp/opf-lane-calcfield
vendor/bin/phpunit            # OK — 688 tests, 2840 assertions (1 pre-existing PHPUnit deprecation)
for f in tests/js/*.test.cjs; do node "$f"; done   # 16/16 files pass
```

## 3. Real Woo lifecycle (OPF)

`evidence/e2e-calcfield-lifecycle.php` → `lifecycle-opf.json`. All checks passed
on the live clone (Woo cart → checkout order → order-again):

- **A** product 100, `info` default calc `[field.rate]*2`, `cost` cost calc `[field.rate]*0.5`, `gate` toggle conditioned on `info > 10`; add qty 2 with `rate=6`:
  - unit price **101.5**, line subtotal **203** (info prices nothing; cost adds 3 per line, differs from a per-unit `* qty` formula on purpose);
  - order line total **203**; meta `Info => "Info 12.00"`, `Cost => "$3.00"`, `Gate => "Yes"` (calc subject satisfied the condition);
  - order-again restores values, recomputes unit **101.5** / line **203**.
- **B** `rate=4` → `info=8`, `gate` meta absent (condition false).
- **C** conditionally-hidden `info` + `cost`, forged submitted values (`999`): hidden cost contributes **0** price (unit 100), no `Info`/`Cost` meta, downstream `gate` absent. Hidden calc cannot submit a price nor satisfy a condition.
- **D** base 1 + signed cost `-5` → cart clamps unit price to **0** (post-1e74aa0 semantics).

## 4. Direct WAPF Extended 3.1.5 comparison

`evidence/e2e-calcfield-wapf-reference.php` (WAPF active, OPF off) captured
`Fields::do_pricing('fx', …)` — the exact path `Extended_Controller` uses for
`calc_type=cost` — for the same inputs (`rate=6`, `qty=2`, `price=100`).
`evidence/e2e-calcfield-import-parity.php` (OPF active) then matched OPF:

| formula | WAPF per-unit | OPF per-unit |
| --- | --- | --- |
| `[field.rate] * 0.5` | 1.5 | 1.5 |
| `([price] + [options_total]) * 0.1 * [qty]` | 10 | 10 |
| `[field.rate] * [qty]` | 6 | 6 |
| `-[field.rate] + 1` | -2.5 | -2.5 |

The captured native WAPF group imports with **no review**, `default`→calc/none,
`cost`→calc/formula, and an imported-group cart line matches the WAPF line
(203) at qty 2. `wapf-calc-reference.json` holds the raw capture.

## 5. Browser live recalculation (Chromium/Playwright)

`evidence/e2e-calcfield-browser-test.mjs` → `browser.json`. All checks passed:

- `rate=6`: info display `Info 12.00`, raw `12`, cost raw `3`, calc subject shows the dependent toggle.
- `rate=4`: display updates to `Info 8.00`, raw `8`, cost raw `2`, dependent toggle hides + controls disabled (no stale submit).
- Options-total preview shows `$2.00` for the cost calc.

## 6. Import / export round-trip

- `WapfMapper`: options + formula-ID remap, cycles/dangling refs fail closed with review (unit tests + real capture).
- `WapfExporter` / `WapfWxrExporter`: calc options serialized into WAPF's `options` bucket, `[addons]`→`[options_total]`, round-trips back through `WapfMapper` without review (`CalcFieldTest::test_wxr_export_...`).

## 7. Reproduce

```
# OPF phase (symlink open-product-fields-for-woocommerce -> /tmp/opf-lane-calcfield)
OPF_CALC_ALLOW=1 OPF_CALC_RESULTS=$PWD/lifecycle-opf.json \
  wp eval-file e2e-calcfield-lifecycle.php --path=/tmp/opf-image-migration-wp

# WAPF reference phase (activate WAPF Extended, deactivate OPF)
OPF_CALC_ALLOW=1 OPF_CALC_RESULTS=$PWD/wapf-calc-reference.json \
  wp eval-file e2e-calcfield-wapf-reference.php --path=/tmp/opf-image-migration-wp

# OPF parity/import phase
OPF_CALC_ALLOW=1 OPF_CALC_REF=$PWD/wapf-calc-reference.json OPF_CALC_RESULTS=$PWD/import-parity.json \
  wp eval-file e2e-calcfield-import-parity.php --path=/tmp/opf-image-migration-wp

# Browser
PLAYWRIGHT_BROWSERS_PATH=/home/followersya-5hqi7/.cache/ms-playwright node e2e-calcfield-browser-test.mjs
```

## 8. Notes / scope

- `calc` is computed once per field; WAPF clone/repeat execution is not ported.
  A repeated/cloned calc is dropped (outside a section) or dropped-with-review
  (inside a repeated section) rather than rendering a broken line.
- Informational calc display uses Woo price decimals for `result_format=number`;
  `result_format=none` is verbatim. Cost calcs display via `wc_price`.
- Hidden calc values are cleared client-side and the field is skipped
  server-side for pricing/meta; a submitted value for a conditionally-hidden
  calc still cannot price or persist.
