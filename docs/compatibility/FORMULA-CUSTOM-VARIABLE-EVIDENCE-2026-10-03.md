# WAPF-PRICE-FORMULA-CUSTOM-VARIABLE — semantics proven vs Extended 3.1.5

Sources: `includes/classes/class-helper.php` (`split_formula_variables`,
`replace_in_formula`, `find_nearest`, `parse_math_string`,
`evaluate_math_string`, `evaluate_variables`), `extend/formulas.php`
(`files`), `includes/controllers/class-public-controller.php`
(`lookuptable` registration), `views/frontend/lookup-tables.php`
(browser lookuptable), `assets/js/frontend.min.js` (`evalFx`, `parseFx`,
`evalVars`, `isValidRule`).

## files(id)
- `count(explode(',', values[0].label))` on the first submitted value label;
  any field type. Empty/missing → 0. OPF counts non-empty array tokens
  (upload token lists) or splits comma strings identically.

## lookuptable(table;dim;…)
- Semicolon args; `strlen < 6` → literal, else field id → first submitted
  label. Table name looked up verbatim (quoted names do not resolve).
- PHP `find_nearest`: exact `isset` key hit; `floatval` coercion means
  non-numeric literals collapse to 0 → clamp to first key; between keys
  rounds up; **beyond the last key reads `$keys[$i]` out of bounds → null
  → a mid-chain null axis fatals in `array_keys(null)` (TypeError)**;
  at the final dimension the reduce yields null → 0.
- Browser `findNearest` (lookup-tables.php): truthy exact-key hit,
  `parseFloat` + numeric-sorted keys; NaN input falls through to
  `keys[keys.length]` = `undefined`; traversal on a non-axis throws inside
  the function's try/catch → 0.
- Divergence (probed): `lookuptable(t;'abcde'-literal;…)` → **PHP 100**
  (floatval clamp) vs **browser 0** (NaN→undefined→catch). OPF mirrors
  each platform's own semantics.

## [var_name] custom variables
- Exact, **case-sensitive** name match; first declared variable wins.
- `default` used unless a rule passes; rules checked in order, first
  passing rule wins (`Fields::is_valid_rule` conditions: check/!check,
  ==/!=, empty/!empty, ==contains/!=contains, lt/gt, gtd/ltd dates, qty,
  product_var*/patts).
- Chosen body is recursively var-expanded, field-token replaced, then
  parse_math_string'd to a number, spliced into the outer formula.
- Unknown variables → 0. WAPF recurses forever on self-reference; OPF
  depth-caps (16) → 0 (documented fail-closed deviation).

## map() / reduce() — never registered
- Not WAPF functions; unregistered `name(…)` calls fall through to the
  residual char-clean eval.
- **PHP** residual keeps `e/E` (scientific notation): `map(1;2)` → 12,
  `reduce(1;2)` → 'ee' → 0.
- **Browser** residual strips ALL letters: `map(1;2)` → 12,
  `reduce(1;2)` → 12. OPF mirrors each platform.

## Misc probed semantics
- `[x]` aliases `[val]` (current field input).
- `split_formula_variables` is quote-blind: `len('a;b')` splits at the
  inner ';' → measures `'a` → 2.
- WAPF `files()`/`checked()`/`lookuptable()` accept unquoted bare ids;
  browser cloneFx remaps those same three + `[field.*]`/`[price.*]` args.

## Deliberate deviations (fail-closed where WAPF crashes)
1. Mid-chain beyond-last lookup axis: WAPF PHP `TypeError` fatal → OPF 0
   (probe rows `lookup-beyond-last`, `lookup-3dim`).
2. Self-referencing `[var_*]`: WAPF infinite recursion → OPF depth cap → 0.
3. Browser `undefined` leaf: WAPF returns `undefined`/`NaN` into the
   formula → OPF resolves the call to 0.
