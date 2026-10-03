# WAPF-PRICE-FORMULA-CUSTOM-VARIABLE — residuals closed (lane varz)

Worktree: `/tmp/opf-lane-varz` (base `405a5f6`). Site: http://127.0.0.1:8317
(clone `/tmp/opf-image-priceb-wp`). No commits or ledger edits were made.

Engine parity (`Calculator` `[var_*]`, `lookuptable`, `files`, rule port,
`WapfMapper`) was already proven. This lane closes the three residuals below.

## Residual 2 — cart-time wiring (`variables` + `lookup_tables`)

**Verified, not rebuilt.** At `405a5f6` the ledger note ("CartIntegration does
not yet pass variables/lookup_tables") was stale:

- `CartIntegration::addons_per_unit()` already threads per-group `variables`
  and `fields` into `Calculator::field_addon()` (both the repeat-row and scalar
  paths). Confirmed by `tests/Unit/FormulaVariableCartWiringTest.php`
  (`test_group_variables_reach_cart_addons_per_unit`).
- `lookup_tables` is deliberately **not** passed by CartIntegration. WAPF has no
  table store — tables are supplied through the developer filter
  `wapf/lookup_tables`. `Calculator`'s `lookuptable` callback falls back to
  `opf_lookup_tables` then `wapf/lookup_tables`, so cart-time resolution works
  through the same extension point. Proven by
  `test_lookup_tables_reach_cart_addons_per_unit_via_filter` and
  `test_variable_body_may_use_lookuptable` (variable body invoking
  `lookuptable(...)`), including WAPF's below-first / round-up / beyond-last
  semantics.

Browser side was the real gap: `Renderer::registry()` did not expose group
variables and `writeTotals()` never passed them to the evaluator, so on-page
previews of variable-driven formulas rendered 0. Fixed:

- `Renderer::render_group()` now emits the group's real
  `data-variables="[...]"` (WAPF `data-variables` parity; was hardcoded `[]`).
- `Renderer::registry()` ships `__opf_variables` and `__opf_formula_fields`
  per group.
- `assets/js/opf-frontend.js`: new `groupFormulaOptions()` (registry primary,
  `data-variables` fallback) is threaded through `choiceOrFieldAddon` →
  `evalFormula` in `writeTotals`.

Coverage: `tests/Unit/RendererVariablesTest.php`,
`tests/js/opf-variable-storefront-wiring.test.cjs`, and the browser proof.

## Residual 1 — builder custom-variable editor

`includes/Engine/FieldGroup.php` previously stored `variables` verbatim. It now
normalizes them to WAPF's canonical `{name, default, rules[]}` shape with
`{type, field, condition, value, variable}` rules (`Field_Groups` sanitize
parity), dropping malformed entries and omitting the key when empty so
variable-less groups stay canonical. This keeps authored and imported data
round-tripping through the WAPF Tools exporter.

`assets/js/opf-builder.js` gains a `Custom variables` editor matching WAPF
Extended 3.1.5's `views/admin/variable-builder.php`:

- Add / duplicate / delete variable; `var_`-prefixed, `[A-Za-z0-9_]`-only name.
- Standard value (number or formula).
- Rules with a `field value changes` / `product quantity changes` selector;
  field rules pick the subject field, a condition (`==`, `!=`, `is empty`,
  `is not empty`, `contains`, `does not contain`, `greater`, `less`) and a
  value control matched to the source type (choice list, toggle, number,
  text); qty rules pick `==`/`!=`/`gt`/`lt` plus a numeric value; every rule
  has its own result value/formula body.

Coverage: `tests/js/opf-builder-variables.test.cjs` (render, add, duplicate,
save-shape), `tests/Unit/FieldGroupSchemaTest.php`
(`test_variables_normalize_to_wapf_canonical_shape`,
`test_variables_omit_key_when_absent_and_drop_malformed_entries`), and the
authenticated builder browser proof.

## Residual 3 — real cart → order → refund → order-again lifecycle

`bin/e2e-varz-lifecycle.php` builds two products per case (native WAPF
`_wapf_fieldgroup` with `variables` vs. an OPF group with the same `variables`)
and drives the real WooCommerce paths: classic cart at q=1/q=3, Store API
add-item at q=3, `WC()->checkout()->create_order()`, a partial refund of one
unit, and
`WC_Cart_Session::populate_cart_from_order()` order-again repopulation.

Result — **6/6 parity cases identical, 0 mismatches** (unit price, line
subtotal/tax/total, order totals, refund math, order-again lines, persisted
engine meta):

| case | bookkeeping |
| --- | --- |
| `var_default` | `[var_rate]` default 2 |
| `var_field_rule` | rule `sizes == lg → 5` |
| `var_field_default` | rule not matched → default 2 |
| `var_qty_rule` | rule `qty > 2 → 7` |
| `var_nested` | `[var_base]*2` |
| `var_mixed` | `[var_rate] + [price]` |

Example (`var_field_rule`, q=3): WAPF and OPF both unit 11.6667 / line 35,
order total 35, refund 11.67, remaining 23.33, order-again identical.

### Documented divergence (WAPF limitation, not a regression)

`var_lookuptable` (variable default `lookuptable(cutting;widthf;heightf)`)
diverges: WAPF 3.1.5's `Helper::evaluate_variables` calls `parse_math_string`
with cart-item field records whose `values[0]['label']` is unset for text
fields, so WAPF resolves the axis to `''` and returns 0; OPF resolves the
submitted field value and returns the table cell (300). OPF mirrors WAPF's
`lookuptable` semantics exactly when the function is evaluated directly
(`CalculatorTest`, `FormulaRuntimeParityTest`), so this is WAPF's
variable-evaluation limitation. Recorded as `parity: false` with a `divergence`
note in the matrix; the parity gate skips this case.

## Browser proof

`bin/e2e-varz-browser-test.mjs` (authenticated Chromium, guarded disposable
clone) — **15/15 checks**: builder editor renders the stored variable + rule,
Add-variable / Add-rule append, Save persists, authored variables and defaults
survive reload/normalize; the storefront group emits real `data-variables`, the
client registry carries `__opf_variables`, and the live options total
recomputes from the variable formula (Small `$2.00` default → Large `$5.00`
rule) with no page errors.

## Suite status

- PHPUnit 11.5.56: **684 tests, 2801 assertions, OK** (`phpunit-suite.txt`).
  Baseline was 677; +7 in `FormulaVariableCartWiringTest` (3),
  `RendererVariablesTest` (2), `FieldGroupSchemaTest` (2).
- JS `node:test`: **16 files pass** (`js-suite.txt`); +2 new
  (`opf-builder-variables`, `opf-variable-storefront-wiring`).
- The single `PHPUnit Deprecations: 1` is pre-existing at base `405a5f6`.

## Clone baseline restoration

- Plugin symlink repointed to `/tmp/opf-lane-varz` for the run, then restored to
  `/tmp/opf-lane-priceb`; the 8317 server was restarted so the baseline plugin
  is served again.
- Disposable marker `.opf-disposable-e2e` removed; fixture admin password file
  removed and the `admin` password rotated. Fixtures (products, groups, orders,
  users) are deleted by the scripts' `finally` blocks; `option` state
  (`opf_admin_only`, `opf_show_totals`, taxes) captured/restored.

## Files

Modified: `includes/Engine/FieldGroup.php`, `includes/Service/Renderer.php`,
`assets/js/opf-builder.js`, `assets/js/opf-frontend.js`,
`assets/css/opf-builder.css`, `tests/Unit/FieldGroupSchemaTest.php`.

Added: `tests/Unit/FormulaVariableCartWiringTest.php`,
`tests/Unit/RendererVariablesTest.php`,
`tests/js/opf-builder-variables.test.cjs`,
`tests/js/opf-variable-storefront-wiring.test.cjs`,
`bin/e2e-varz-lifecycle.php`, `bin/e2e-varz-browser-fixture.php`,
`bin/e2e-varz-browser-test.mjs`.
