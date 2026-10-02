# WAPF/OPF date-format JS precedence — 2026-10-02

## Scope

`Assets::enqueue_frontend()` prints `window.OPF_DATE_FORMAT` for the frontend
module's date-formula parser (`assets/js/opf-frontend.js`, `resolveFormulaDate`
reads it before the `wapf_config` fallback). Before this change the global was
read straight from `wapf_date_format`, so a store whose importer had already
migrated the setting into `opf_date_format` — or whose admin set the OPF option
directly — got JavaScript date parsing under the legacy site's format while
the PHP surfaces (`Renderer`, `CartIntegration`, `Calculator`,
`Admin\Settings`) already resolved `opf_date_format` first.

## Change

`Assets::frontend_date_format()` resolves the emitted value tier by tier, each
gated on `DateFormat::is_valid()`:

1. an explicit `opf_date_format` when it stores a valid value;
2. `wapf_date_format` when it stores a valid value;
3. `DateFormat::DEFAULT_FORMAT` (`mm-dd-yyyy`).

The winner passes through `DateFormat::normalize()`, so a stored `DD/MM/YY`
emits as `dd/mm/yy`. An invalid stored OPF value no longer masks a valid WAPF
fallback, and absent or invalid values can never leak into the printed script.
Emission still happens only when the registry is non-empty, matching the
existing gate in `enqueue_frontend()`.

## Verification

- `php -l includes/Service/Assets.php` and
  `php -l tests/Unit/AssetsDateFormatTest.php`: no syntax errors.
- `/tmp/opf-archive-import/vendor/bin/phpunit -c phpunit.xml.dist --filter AssetsDateFormatTest`:
  4 tests, 7 assertions, all pass — OPF-over-WAPF precedence, WAPF fallback
  when the OPF option is absent or invalid (string and non-string values),
  `mm-dd-yyyy` when both are absent or invalid, and lowercase normalization of
  the emitted value. The tests capture the real printed inline script via a
  stubbed `wp_print_inline_script_tag` and a fake option store.
- Full suite `/tmp/opf-archive-import/vendor/bin/phpunit -c phpunit.xml.dist`:
  290 tests, 1128 assertions, all pass. PHPUnit reports one unrelated
  pre-existing suite discovery deprecation in `CapabilityFixtureRegistryTest`
  doc-comment metadata.
- `node --test tests/js/*.test.cjs` (run per file): 12 tests pass —
  opf-formula-date 8, opf-formula-math-import 2, opf-conditional 1,
  opf-builder-auth 1.
- `git diff --check`: clean.

No production deployment or live WordPress lifecycle was run; the proof is the
focused unit test plus the full PHP and JS suites in this worktree.

## Remaining gaps

- Server-side emission now follows the migrated setting, but end-to-end proof —
  an entered date parsed under the resolved format through real cart/order
  surfaces in a browser — remains unproven in this worktree.
- `Renderer`, `CartIntegration`, `Calculator`, and `Admin\Settings` still use
  their own nested `get_option( 'opf_date_format', get_option( 'wapf_date_format', ... ) )`
  chains without the per-tier validity gate; there an invalid stored OPF value
  normalizes to the default instead of falling through to a valid WAPF value.
  Aligning them is a separate slice.
- The `WAPF-DATE-FORMAT` ledger row remains partial: OPF's native date input
  still follows browser formatting and the commerce lifecycle is unverified.
