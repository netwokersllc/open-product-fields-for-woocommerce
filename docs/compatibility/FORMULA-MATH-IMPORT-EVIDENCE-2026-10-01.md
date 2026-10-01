# Numeric formula import and square-root parity

Observed 2026-10-01 20:46–20:48 UTC in `/tmp/opf-formula-functions`, based on
`77fb5740f9a153c68560b574aef5d2c9918deefc`. The explicit public source was checked:

```sh
git ls-remote git@github.com:netwokersllc/open-product-fields-for-woocommerce.git refs/heads/feat/opf-archive-import
```

It returned that SHA. This work adds import acceptance for existing runtime
functions `min`, `max`, `round`, `abs`, `floor`, `ceil`, `sqrt`, `pow`, `sin`,
`cos`, and `tan`. It does not introduce eleven new runtime implementations.
Imported nested numeric calls retain semicolon arguments, field-ID remapping,
choice `formula_raw`, and the existing trailing `[qty]` compensation mapping.
Arbitrary function names, strings, unknown variables, and argument separators
outside function calls are rejected. Evaluation continues through the existing
sandboxed calculator and browser evaluator.

The PHP square-root callback now passes negative inputs to native `sqrt()`.
Its non-finite result fails the whole expression closed, as the browser already
does. The former clamp silently converted a domain error to zero before adding
the remaining expression: `sqrt(-1) + 7` returned PHP `7` versus browser `0`.

## Sources and limits

- [Official formula function reference](https://www.studiowombat.com/knowledge-base/formula-functions-reference/)
  defines numeric functions, semicolon arguments, nesting, and radians for
  cosine. It was read on 2026-10-01.
- Installed `advanced-product-fields-for-woocommerce-extended.php:7` declares
  version 3.1.5. Its `extend/formulas.php:83-128` registers native PHP `round`,
  `abs`, `floor`, `ceil`, `sqrt`, `cos`, `sin`, `tan`, and `pow` callbacks after
  numeric argument parsing. File SHA-256:
  `cc8edbe3b429ffb923c3ff23240a6043c0857542a45f0ae9845e4742960e3ccc`.
- The fixture is shaped from those definitions and OPF's existing WAPF schema.
  It is not an actual paid-plugin export or a live side-by-side WAPF execution.

## Failing-first and unit proof

The initial importer test returned two expected failures: `min(5; 1; 3)`
normalized to `null`, and field/choice math formulas were dropped and marked
for review. A later focused square-root regression failed with PHP `7.0`
instead of `0.0` before its callback was corrected.

Final commands, run from the worktree:

```sh
vendor/bin/phpunit tests/Unit/FormulaMathImportTest.php
vendor/bin/phpunit tests/Unit/WapfMapperTest.php
vendor/bin/phpunit tests/Unit/CalculatorTest.php
vendor/bin/phpunit tests/Unit/WapfParserTest.php
node --test tests/js/opf-formula-math-import.test.cjs tests/js/opf-formula-date.test.cjs
php -l includes/Engine/Calculator.php
php -l includes/Engine/WapfMapper.php
php -l bin/e2e-formula-math-import.php
node --check bin/e2e-formula-math-import-browser.cjs
git diff --check
```

Results: respectively **4 tests/43 assertions**, **35/164**, **21/66**,
**7/20**, and **10 Node tests**, all passing. PHP/JS syntax and diff checks
passed. The Node test obtains normalized formulas from the actual PHP mapper,
then passes those bytes to the storefront evaluator.

## Real browser, cart, and order proof

A fresh private WordPress installation at `/tmp/opf-math-import-wp` uses its own
SQLite database at `wp-content/database/.ht.sqlite`. WordPress 7.1.2 and
WooCommerce 11.1.0 are active with this worktree's OPF plugin. WordPress,
WooCommerce, SQLite integration, and theme binaries were copied; no database
was copied and no production or shared-clone state was changed.

The local PHP server serves port 8163. With the server running, the exact proof
command was:

```sh
PLAYWRIGHT_CHROMIUM_EXECUTABLE=/tmp/fy-playwright-browsers/chromium_headless_shell-1217/chrome-headless-shell-linux64/chrome-headless-shell node bin/e2e-formula-math-import-browser.cjs
```

The runner maps and persists a WAPF-shaped choice formula containing all eleven
functions and a sibling numeric field reference. Real Chromium renders the
product form and observes these totals after normal input/select actions:

| Numeric field | Product quantity | Rendered grand total |
| --- | --- | --- |
| 3 | 1 | $22.00 |
| 3 | 3 | $66.00 |
| 6 | 3 | $93.00 |

No page errors occurred. The same imported choice reached Store API add-item:
unit price **22.00**, line **22.00** at quantity 1 and **66.00** at quantity 3.
Woo's order-item creation hook persisted `_opf_fields`; a fresh order-item
object read back line **66.00**, Count `3`, and Plan `math`. This is a cart plus
Woo order-item persistence proof, not a complete Store API checkout/payment.

The runner cleaned its product/group, cart, and order. A subsequent live query
returned **0 products, 0 OPF groups, 0 orders**. The initial launch attempt found
a Playwright/browser revision mismatch; using the existing Chromium executable
resolved it, and the successful proof was repeated after the square-root edit.

## Remaining gaps

- These ledger capabilities remain partial; no ledger row or progress count
  is changed by this work.
- Scalar field-level formulas are imported and PHP-tested, but their browser
  quantity compensation remains unproved and has an existing mismatch:
  normalized `round(3)` previews a line adjustment of `3` at quantity 3 while
  the server treats it as a per-unit adjustment. The proven quantity path
  above is a selected choice retaining `formula_raw`.
- Native `files()` requires the missing upload field/token implementation on
  this base. `sumQty()` already exists in PHP/JS; its importer review flag
  remains unchanged. Native lookup tables and custom variables remain absent.
- Conditional/text/date function import grammar, full paid-export fidelity,
  source comparison against a newer WAPF package, rounding boundary cases,
  nonzero trigonometric commerce cases, taxes/currency, complete checkout,
  order-again, clone scopes, and other cart lifecycle paths are not proved here.
