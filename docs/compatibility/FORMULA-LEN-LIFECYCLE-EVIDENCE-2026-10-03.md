# Formula `len()` lifecycle parity — 2026-10-03

Real browser/cart/checkout/order comparison of the formula `len()` function
and `[field.{id}]` value substitution between OPF and the installed WAPF
Extended 3.1.5. Run on a disposable SQLite WordPress clone at
`http://127.0.0.1:8276` (WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11,
Chromium headless). Production was not used.

## Result

**All 24 real cases match: identical preview totals, Store API cart totals,
and durable order line totals for both engines — including WAPF's own
browser/server quirks.**

| case | WAPF preview | WAPF cart/order | OPF preview | OPF cart/order |
| --- | --- | --- | --- | --- |
| ascii-plain `len([field.x])` | $27 | $27 | $27 | $27 |
| ascii-strip-q3 `len([field.x];true)×[qty]` | $72 | $72 | $72 | $72 |
| emoji `A😀B` | $4 | $13 | $4 | $13 |
| combining `AéB` | $4 | $14 | $4 | $14 |
| nbsp-strip `A B` | $2 | $13 | $2 | $13 |
| emspace-strip `A B` | $2 | $13 | $2 | $13 |
| bom-strip `A﻿B` | $2 | $13 | $2 | $13 |
| nel-strip `AB` | $3 | $13 | $3 | $13 |
| zero `0` | $1 | $10 | $1 | $10 |
| uppercase-TRUE `len(...;TRUE)` | $27 | $27 | $27 | $27 |
| choice-label-q3 `len([field.SourceID];true)` | $72 | $72 | $72 | $72 |
| literal-q3 `len(a quick brown fox;true)` | $72 | $72 | $72 | $72 |

Separately, `php-length-probes.json` compares `Helper::parse_math_string()`
(WAPF) against `Calculator::evaluate_formula()` (OPF) across 30 direct PHP
probes — ASCII, emoji, combining mark, NBSP, em-space, BOM, NEL, every ASCII
whitespace class, and `"0"`, each in plain/`;true`/`;TRUE` variants:
**30/30 identical**.

Artifacts: `/tmp/opf-formula-len-artifacts/` — `php-length-probes.json`,
`browser-commerce-results.json`, `wapf-mapped-findings.json`,
`roundtrip-findings.json`, `opf-tools-export.json`,
`wapf-native-model-export.json`, `state.json`, `cleanup.json`, and
per-provider screenshots for the emoji, NBSP, and choice-label cases.
Fixture/proof scripts: `bin/e2e-formula-len-lifecycle.php`,
`bin/e2e-formula-len-browser.mjs`, `bin/e2e-formula-len-mu.php`.

## Bugs fixed by this proof

The earlier 09:01 run exposed five real divergences (WAPF vs OPF order
totals). Each was traced to installed Extended 3.1.5 source and fixed:

- **Unicode whitespace stripping** (`$13` vs `$12` ×3 cases): OPF used
  `preg_replace('/\s/u', …)` which strips NBSP, em-space, and NEL. WAPF uses
  `preg_replace('/\s/', …)` without `/u` — ASCII whitespace only. OPF now
  uses the identical expression.
- **Case-sensitive `;true`** (`$27` vs `$24`): OPF compared
  `strtolower(trim($args[1]))` so `;TRUE` stripped anyway. WAPF requires the
  literal `'true'` after trim (`split_formula_variables` +
  `$args[1] === 'true'`). OPF now compares strict `'true'`; a second latent
  bug — `formula_numeric_value()` lowercasing the whole expression, which
  would have corrupted nested `min(len(x;TRUE);…)` — was fixed by keeping
  case and only matching the true/false literals case-insensitively.
- **Emoji count** (preview `$4` vs `$3`): OPF JS used `[...value].length`
  (code points); WAPF JS uses `.length` (UTF-16 code units, so an emoji
  counts 2). Server side WAPF uses `mb_strlen` (code points → 3) — WAPF
  itself intentionally differs between preview and order, and OPF now
  mirrors both sides exactly.
- **`len('0')`** (`$10` vs `$11`): WAPF's `empty($args[0])` treats the
  submitted `"0"` as empty → length 0. OPF cast to string first. Now
  identical (JS still counts `"0"` as 1, matching WAPF's preview).
- **Choice-label resolution** (`$72` vs `$36`): WAPF substitutes
  `[field.X]` with the first submitted value's **choice label**; OPF used the
  submitted slug. Added `Calculator::formula_field_label()` plus a
  `field_labels` slug→label map threaded through `evaluate_formula`,
  `choice_addon`, `field_pricing_addon`, `field_addon`,
  `formula_numeric_value`, `parse_formula_date`, and both
  `CartIntegration::addons_per_unit` pricing contexts (including repeated
  rows). WAPF's `field.X_slug` suffix selects one submitted value when a
  field has several; OPF field ids may contain underscores, so the full
  token is tried as a field id first — identical resolution order. The
  browser evaluator gained the same helper plus an `__opf_labels` map built
  from `window.OPF_FIELDS` choice definitions.

## Coverage notes

- Checkout used the classic `[woocommerce_checkout]` page with cash-on-
  delivery; each of the 24 cases placed a real order and read back the
  stored line total.
- Import/export: `wapf-mapped-findings.json` (WAPF model → OPF mapper),
  `opf-tools-export.json` (OPF → WAPF Tools payload), and
  `roundtrip-findings.json` (OPF archive decode/re-import byte identity)
  regenerated against the fixed evaluator.
- WAPF's formula field-ID substitution takes the first submitted value's
  label, so `[field.X]` on a multi-select is inherently first-value; the
  `X_slug` suffix is the documented way to pick another value.
- Cleanup (`cleanup.json`) deleted every owned post/order/option and
  restored the clone's plugin list and field-group statuses.
