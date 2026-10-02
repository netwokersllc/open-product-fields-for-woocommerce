# WAPF/OPF date-format fallback evidence — 2026-10-02

## Scope and implementation

Runtime fix: `1d72e4859f42a35fc65d295aa0e3d804b639e49a`.
Settings sanitizer fix: `9c9f22108821628a740e92e25146d3cb1c1dff78`.
Both were verified in the isolated worktree
`/tmp/opf-date-format-invalid-fallback-20261002`, based on public feature
commit `9b2bcf8aae125086c52ce591e065ad2b95cb03de`.

`DateFormat::configured()` resolves a valid `opf_date_format`, then a valid
`wapf_date_format`, then `mm-dd-yyyy`, normalizing the winning format.
Every settings/runtime consumer uses that shared resolver: assets, rendered
date-field attributes, cart/order display labels, formula parsing, and the
admin settings sanitizer's invalid-submission fallback. A valid posted
settings format still wins. The importer retains its separate migration
ownership rule: do not overwrite an explicit OPF option.

Before the fix, `opf_date_format=garbage` with `wapf_date_format=dd/mm/yy`
gave frontend assets `dd/mm/yy`, renderer format `mm-dd-yyyy`, cart label
`10-15-2026`, and a formula parser that rejected `15/10/26`. The new cross-surface regression
reproduced four failures before the runtime fix. The admin regression then
reproduced one failure: an invalid posted format discarded the valid WAPF
fallback. Both mechanisms now use the same resolver.

The [official WAPF date-field documentation](https://www.studiowombat.com/knowledge-base/date-picker-calendar-field/)
specifies a global display format with default `mm-dd-yyyy`; it does not
define precedence between WAPF and OPF settings.

## Regression and suite commands

Run from the isolated plugin worktree with its own Composer dependencies:

```bash
composer install --no-interaction --prefer-dist --no-progress
vendor/bin/phpunit tests/Unit/DateFormatFallbackTest.php
vendor/bin/phpunit tests/Unit/AdminSettingsDateFormatTest.php
vendor/bin/phpunit --filter 'DateFormat|RendererDateTest|CalculatorTest'
composer test
node --test tests/js/opf-formula-date.test.cjs
node --test tests/js/*.test.cjs
git diff --check
```

| Command/check | Result |
| --- | --- |
| Cross-surface fallback regression | 7 tests, 49 assertions passed; invalid/array/empty/absent OPF, valid WAPF, invalid/absent both, valid OPF precedence, ISO parsing and malformed-date rejection |
| Admin settings regression | 4 tests, 4 assertions passed; invalid post plus invalid OPF/valid WAPF, invalid both, valid posted value, and valid existing OPF precedence |
| Focused date/admin/calculator suite | 43 tests, 152 assertions passed |
| Full `composer test` after settings fix | 340 tests, 1315 assertions passed |
| Focused date JavaScript suite | 8 tests passed |
| Full JavaScript suite | 21 tests passed |
| PHP 8.5 and PHP 7.4 lint | All modified runtime PHP files passed |
| `git diff --check` | Clean |

The full PHP suite reports one existing PHPUnit deprecation for doc-comment
metadata in `CapabilityFixtureRegistryTest`; there are no test failures.
Before the additional settings regression, full PHP verification was
336 tests/1311 assertions, with the same deprecation; an independent final
review reran `composer test` and the full JavaScript command with those
results before the settings follow-up.

An additional focused standalone fallback probe produced identical PHP 7.4
and PHP 8.5 JSONL output for invalid OPF/valid WAPF, array OPF/valid WAPF,
invalid both, and valid OPF precedence. These local artifacts record the
commands and exact output:

```bash
php -d error_reporting=22527 /tmp/opf-date-format-fallback-php-floor-20261002.php > /tmp/opf-date-format-fallback-floor85-20261002.jsonl
/tmp/opf-php74/root/usr/bin/php7.4 -d extension=/tmp/opf-php74/root/usr/lib/php/20190902/ctype.so -d extension=/tmp/opf-php74/root/usr/lib/php/20190902/json.so /tmp/opf-date-format-fallback-php-floor-20261002.php > /tmp/opf-date-format-fallback-floor74-20261002.jsonl
cmp /tmp/opf-date-format-fallback-floor85-20261002.jsonl /tmp/opf-date-format-fallback-floor74-20261002.jsonl
```

`cmp` exited 0. This is a focused fallback check, not a full PHP 7.4 suite.
The broader standalone `bin/probe-calculator-php71.php` matrix has an
inherited failing `sumQty` oracle (`formula:0:17`) on both the base and fixed
source. It is not counted as passing, and its oracle was not changed.

## Disposable browser and commerce proof

The WordPress clone was copied from `/tmp/opf-date-format-runtime-20261002`
to `/tmp/opf-date-format-fallback-runtime-20261002`. Its SQLite database is
inside the new clone, its active OPF symlink points at the isolated worktree,
and its site/home URLs use `http://127.0.0.1:8184`. No production deployment
or production database mutation was performed.

Serve the clone in a separate terminal, then run the committed harness:

```bash
wp --path=/tmp/opf-date-format-fallback-runtime-20261002 server --host=127.0.0.1 --port=8184
```

```bash
OPF_DATE_PROOF_WP_PATH=/tmp/opf-date-format-fallback-runtime-20261002 \
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
node bin/e2e-date-format-fallback-browser.cjs
```

[Exact browser/WooCommerce JSONL output](evidence/WAPF-DATE-FORMAT-FALLBACK-BROWSER-2026-10-02.jsonl)
records all three cases against the runtime-fix commit:

| Stored OPF | Stored WAPF | Resolved format | Display for ISO `2026-10-15` |
| --- | --- | --- | --- |
| `garbage` | `dd/mm/yy` | `dd/mm/yy` | `15/10/26` |
| `garbage` | `not-a-format` | `mm-dd-yyyy` | `10-15-2026` |
| `yyyy.mm.dd` | `dd/mm/yy` | `yyyy.mm.dd` | `2026.10.15` |

Independent final review reran the same browser command and confirmed all
three cases, 14.00 prices, canonical ISO storage, formatted labels/metadata,
and zero page errors. The linked JSONL preserves the original run's exact
output; fixture IDs differ on repeat runs.

Each case verifies actual served `window.OPF_DATE_FORMAT`, renderer data
attributes, picker button text, and native input value. A real browser form
submission reaches WooCommerce; Store API cart labels, the rendered block
cart, and rendered classic checkout all show the resolved display value.
The formatted text date feeds `dow([val])`: base price 10.00 plus Thursday's
4.00 yields cart/order price 14.00 in every case. A separate direct
WooCommerce order save/reload verifies formatted display metadata and
canonical ISO `2026-10-15` in `_opf_fields`. No page errors were recorded.
Block-cart label checks wait for the rendered label, because `networkidle`
alone can precede React finishing its loading state.

The fixture restores the clone's prior OPF/WAPF settings and deletes its
products, groups, and direct proof orders. Final checks found no fixture
posts/orders, `opf_date_format_fallback_proof` absent, and restored options
`dd/mm/yy` / `DD/MM/YY`. The disposable server was stopped; the following
command showed no listener:

```bash
ss -ltnp '( sport = :8184 )'
```

## Remaining gaps and status

`WAPF-DATE-FORMAT` remains **partial**. This closes the shared fallback
inconsistency, including settings sanitization, without advancing the
capability status or roadmap counts.

- The native date input still uses the browser's locale display. The
  screenshot showed `10/15/2026` in that input beside picker text `15/10/26`.
- Browser checkout submission, payment, and the resulting thank-you flow
  were not exercised; saved order metadata was verified through the direct
  WooCommerce API fixture.
- Rendered block checkout was not exercised; the clone's checkout uses
  `[woocommerce_checkout]`. Block cart and Store API cart labels did pass.
- Customer order-page rendering and the remaining end-to-end migration
  proof are still open. Existing importer evidence does not prove this
  complete lifecycle.
