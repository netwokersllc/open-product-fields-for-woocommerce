# Formula import/export evidence — 2026-10-02

Observed **2026-10-02 11:01:35–11:01:39 UTC**, with cleanup completing
after the browser closed. Base: public feature commit
`9b2bcf8aae125086c52ce591e065ad2b95cb03de`, isolated worktree
`/tmp/opf-formula-import-roundtrip`. No runtime implementation, ledger,
deployment, release, or production data changed.

## Result and limitation

Twelve expressions at quantities 1 and 4 produce the same **per-unit add-on**
in installed WAPF Extended 3.1.5, OPF PHP choice pricing, OPF PHP scalar field
pricing, and OPF JavaScript executed in real Chromium. Each native result
also matches native choice and scalar pricing after the OPF WAPF export
passes through WAPF's raw converter, save, reload, and raw export methods.

The installed native Tools UI is **unlicensed**. Chromium sees the invalid
license notice and exports `fields: []` despite 16 saved native model fields.
Importing the full model payload shows “Import done!” but immediate Tools
reexport still has zero fields. This run therefore proves the native **PHP
model path**, not a licensed Tools UI round-trip or native Tools regenerated
IDs. Both empty UI payloads and a screenshot are retained in
[the artifacts](formula-import-roundtrip-20261002/).

## Exact environment and native source

Fresh WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, Chromium
147.0.7727.15. Independent SQLite database:
`/tmp/opf-formula-roundtrip-wp/wp-content/database/.ht.sqlite`.
OPF resolves to this worktree. HTTP server: `127.0.0.1:8216`.
The PHP fixture refuses any other ABSPATH/database location.

Native package root:
`/tmp/opf-formula-roundtrip-wp/wp-content/plugins/advanced-product-fields-for-woocommerce-extended/`.

| Native source relative to that root | Role |
| --- | --- |
| `advanced-product-fields-for-woocommerce-extended.php:7` | Version 3.1.5 |
| `includes/classes/class-field-groups.php:19` | Actual raw field export |
| `includes/classes/class-field-groups.php:82` | Actual raw payload import converter |
| `includes/classes/class-field-groups.php:736` | Native serialized post persistence |
| `includes/classes/class-fields.php:280` | `do_pricing()` oracle, including `fx / qty` |
| `includes/classes/class-helper.php:627` | Quantity/base/options/field/price substitution |
| `includes/classes/class-helper.php:708` | Installed function parser |
| `includes/controllers/class-public-controller.php:28` | `min`, `max`, `len`, `lookuptable` registration |
| `extend/formulas.php:83` | Remaining numeric, count, and conditional registrations |
| `extend/date.php:225` | Date registrations |
| `views/admin/tools.php` and `assets/js/admin.min.js` | Actual Tools modal and handlers used in Chromium |

Native `admin.min.js` SHA-256:
`e77a542ff02edc9a5af2872fe6b6d824a368097d79c277727b306bbb96864aab`.
Native `extend/formulas.php` SHA-256:
`cc8edbe3b429ffb923c3ff23240a6043c0857542a45f0ae9845e4742960e3ccc`.
The full registered function list is captured from
`Helper::get_all_formula_functions()` in `php-results.json`; source inspection
of `extend/formulas.php` alone omits functions from other files.

## Cases and numerical results

Context: catalog base 100, sibling number 3, sibling flat fee 8 per line,
two selected checkboxes. `[options_total]` and `[price.FeeID]` are both 8/qty.
Every row below matches for both choice and scalar pricing, before and
after native model round-trip, and in Chromium.

| Case | Quantity 1 add-on/unit | Quantity 4 add-on/unit |
| --- | ---: | ---: |
| Constant `7` | 7 | 1.75 |
| `2 * [qty] + 5` | 7 | 3.25 |
| `(2 + 5) * [qty]` | 7 | 7 |
| `[qty] * (2 + 5)` | 7 | 7 |
| `[price] * 0.1 + [options_total]` | 18 | 3 |
| `[field.CountID] + [price.FeeID]` | 11 | 1.25 |
| `checked(FlagsID) + 3` | 5 | 1.25 |
| `min(5; 1; 3) + max(5; 8; 3)` | 9 | 2.25 |
| `round(pow([field.CountID]; 2) / 7; 2)` | 1.29 | 0.3225 |
| `abs(-4) + floor(2.9) + ceil(2.1)` | 9 | 2.25 |
| `sqrt(9) + sin(0) + cos(0) + tan(0)` | 4 | 1 |
| Combined eleven-function expression `* [qty]` | 12 | 12 |

Expressions use the native semicolon argument delimiter. The exact combined
expression and every preserved/canonical formula are in the JSON artifacts.

## Persistence, IDs, and archives

The fixture creates native data through `raw_json_to_field_group()` and
`Field_Groups::save()`, reads the actual serialized database payload through
`Importer::run()`, and asserts:

- Two global sources and one `_wapf_fieldgroup` local source import successfully;
  dry-run writes no OPF groups, repeated commit skips all three.
- Source IDs `CountID`, `FeeID`, `FlagsID`, and `ChoiceID` become label-derived
  OPF IDs `count`, `fee`, `flags`, and `formula-choice`. Sibling references and
  `checked()` are rebound, and canonical/raw formulas preserve quantity intent.
- `WapfExporter::build_payload()` is consumed by the real native converter,
  persisted, reloaded, exported, and imported by the legacy importer. The full
  OPF group data is exactly equal before and after this model round-trip.
- Export of `[FIELD.COUNT] + [PRICE.FEE]` becomes
  `[field.count] + [price.fee]` so IDs match the exported fields literally.
  Incorrect casing in **native input IDs** (`COUNTID` vs `CountID`) is held for
  review and loses formula pricing, matching the source's exact-ID boundary.
- `Exporter::build_package()` → JSON encode → `ArchiveImporter::decode()` →
  dry-run/commit creates an exact-data OPF copy; repeated import is idempotent.

The browser fetches the actual served OPF frontend JavaScript, asserts it is
byte-for-byte equal to the worktree source, and executes its pricing function
with the native oracle contexts in Chromium. Served SHA-256:
`0ed89fc96d3eb6fcab43e25b7969bca240db40c3add6919b1371a944daecc66a`.
The formula execution page has zero page errors. The native admin pages emit
two `Failed to fetch` errors while external requests are blocked by the
isolated runner; these are recorded, not counted as clean admin pages.

## Commands and results

Prerequisite: a fresh dedicated clone at the guarded path with the three
plugins active, the fixture administrator, the exact worktree plugin symlink,
and `php -S 127.0.0.1:8216 -t /tmp/opf-formula-roundtrip-wp`.

```sh
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
PLAYWRIGHT_CHROMIUM_EXECUTABLE=/tmp/fy-playwright-browsers/chromium_headless_shell-1217/chrome-headless-shell-linux64/chrome-headless-shell \
node bin/e2e-formula-import-roundtrip-browser.cjs
php -l bin/e2e-formula-import-roundtrip.php
node --check bin/e2e-formula-import-roundtrip-browser.cjs
```

Exit 0. PHP: 24 cases for both choice and field before/after native model
round-trip; global/local import, archive equality, repeat skips, casing export,
and nine explicit review boundary checks pass. Chromium: the same 24 cases
for both choice and field pass. PHP lint and Node syntax checks pass.
Full output: [run.txt](formula-import-roundtrip-20261002/run.txt).

Cleanup deletes every fixture product/global/local/imported/archive post and
the fixture option. An independent database query returns zero products,
`wapf_product`, and `opf_field_group` posts; the fixture option is absent.
The dedicated HTTP server was stopped after verification. The isolated clone
and evidence artifacts remain for reproduction.

## Remaining gaps

- Licensed native Tools export/import and its automatic destination ID
  regeneration remain unproven because the real UI discards fields without
  a valid license. No license state or paid-plugin source was modified.
- Native conditional, string, lookup, and date grammar is broader than the
  current mapper. This run demonstrates review/drop behavior for `if`, `len`,
  `lookuptable`, `today`, and `datediff`; it does not extend grammar or prove
  formula-pricing parity for those functions. `and`, `or`, `dow`, and `month`
  are registered but not exercised by these fixtures.
- `sumQty()` and `files()` retain formulas but still set `needs_review`;
  these inputs do not prove valid image-quantity or upload lifecycle parity.
- `[price.ID]` coverage is a preceding sibling. Forward/self references,
  cloned/option-qualified references, variables, custom extension functions,
  and native `[x]` are outside the demonstrated cases.
- This lane compares the installed parser/pricing methods and persisted import
  paths. It does not claim new cart, checkout, order, theme, storefront totals,
  or production coverage. No OPF runtime bug was found in the exercised cases.
