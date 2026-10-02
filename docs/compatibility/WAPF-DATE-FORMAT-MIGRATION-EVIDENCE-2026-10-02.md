# WAPF global date-format migration — 2026-10-02

## Source and boundary

The installed Advanced Product Fields for WooCommerce Extended plugin identifies itself as version 3.1.5 in `advanced-product-fields-for-woocommerce-extended.php:7`. Its WooCommerce settings registration uses the site option `wapf_date_format`, a text control with default `mm-dd-yyyy` and documented placeholders `mm`, `m`, `dd`, `d`, `yyyy`, `yy` (`includes/controllers/class-admin-controller.php:807-815`). The frontend date field and cart validation read this option (`views/frontend/fields/date.php:10-11`; `includes/classes/class-cart.php:264-273`). It is global, rather than a field-group option.

The installed WAPF group save path constructs `fields`, `conditions`, `layout`, and `variables` in `class-admin-controller.php:1037-1054`; the Tools UI in `views/admin/tools.php` exports/imports a field-group code payload. Neither path reads or writes `wapf_date_format`. Thus a WAPF group export does not carry this global site preference. The OPF WAPF mapper operates on group payloads and cannot infer the site setting.

## Change

`Importer::run()` now reports `date_format` alongside group results. A dry run reports `would-import` for a valid explicit WAPF option. Commit copies that validated value to `opf_date_format` only if OPF has no explicit value. The existing OPF setting wins on repeat runs. Missing or invalid WAPF values do not write; the missing case uses OPF's existing `mm-dd-yyyy` default. The copy is bounded to OPF's existing `DateFormat::is_valid()` grammar and normalization.

The portable OPF field-group archive and WAPF Tools JSON remain group-only. This change does not claim to transfer global settings between sites via those files. The WAPF source does not put the setting in the group payload, and changing archive semantics would require a separate package version and importer contract.

## Verification

- `php -l includes/Service/Importer.php` and `php -l tests/Unit/ImporterDateFormatTest.php`: no syntax errors.
- `/tmp/opf-archive-import/vendor/bin/phpunit -c phpunit.xml.dist --filter ImporterDateFormatTest`: 3 tests, 9 assertions, all pass. PHPUnit reports one unrelated suite discovery deprecation in `CapabilityFixtureRegistryTest` doc-comment metadata.
- `git diff --check`: clean.

The focused tests cover dry-run no-write, copy-once/idempotence, absent source, and invalid source using a disposable fake database and option store. No production import or deployment was run.

## Remaining parity

WAPF renders a text date control with the selected format; OPF's native date input still follows browser formatting. OPF's `Assets.php` also initializes `window.OPF_DATE_FORMAT` directly from the WAPF option, so JavaScript that reads that global has a separate setting-precedence gap when the OPF option differs. End-to-end native date UI and commerce lifecycle parity remain unproven here. The `WAPF-DATE-FORMAT` ledger row therefore remains partial.
