# WAPF sumQty import diagnostics — 2026-10-02

## Result

`sumQty(ID)` no longer receives the false “runtime behavior is not implemented” warning when the referenced source field maps to OPF `image_quantity` and both target and formula consumer are outside cloned/repeated scopes. The importer still marks the group draft for its independent image-media review. Choice formula diagnostics now identify the choice; scalar formula diagnostics identify the field.

`files(ID)`, non-quantity targets, cloned/repeated contexts and forward/self price references retain runtime review. Missing/ambiguous source IDs still drop pricing and require review. The revised warning says “references that require runtime review” without claiming these functions are absent.

Base: `7f319a616ea8f8a18242831b19457291a79fdfc8`, isolated worktree `/tmp/opf-sumqty-import-review`, branch `fix/sumqty-import-review`. Runtime change is confined to `includes/Engine/WapfMapper.php`.

## Native source and environment

Disposable WordPress `/tmp/opf-sumqty-import-wp` copied from the prior empty formula fixture clone; its own SQLite database is `/tmp/opf-sumqty-import-wp/wp-content/database/.ht.sqlite`, prefix `wpt_`. OPF plugin symlink resolves to this worktree. WordPress 7.1.2, WooCommerce 11.1.0, WAPF Extended 3.1.5, PHP 8.5.11, PHPUnit 11.5.56. No production database or service was changed; no server was started.

Native source root: `/tmp/opf-sumqty-import-wp/wp-content/plugins/advanced-product-fields-for-woocommerce-extended`.

- `extend/formulas.php:130`: installed `sumQty` callback sums integer-converted cart-value labels for the matching field ID. SHA-256: `cc8edbe3b429ffb923c3ff23240a6043c0857542a45f0ae9845e4742960e3ccc`.
- `includes/classes/class-fields.php:55`: native request conversion; image quantity choice inputs are read by slug.
- `includes/classes/class-fields.php:155`: native raw values become quantity-bearing cart labels.
- `includes/classes/class-fields.php:280`: native formula pricing and line quantity conversion.
- `includes/classes/class-field-groups.php:82`: native raw payload converter used to create the persisted product-local source.
- `includes/classes/class-cart.php:21`: native pricing context is built from the actual cart, including product base, cart quantity and converted selected fields.

## Persisted import and cart proof

`bin/e2e-wapf-sumqty-import.php` uses the actual native converter, saves its model in product `_wapf_fieldgroup` meta, then executes `Importer::run(true)`. The persisted imported field is `image_quantity`; choice formula `sumQty(PrintID)*[qty]` becomes `sumQty(prints)` with preserved raw formula `sumQty(prints)*[qty]` and `per_unit=true`. Import report has exactly one image-media review note and draft status. No sumQty review note remains.

The fixture first adds the native product through WooCommerce classic cart hooks. It then removes native field meta, explicitly publishes only this disposable imported group after accepting its empty-media fixture review, and submits equivalent inputs through OPF classic hooks. Contexts in this proof come from the actual cart integrations. Both native and OPF selected field structures are retained in the JSON artifact. Each scenario resets WAPF's totals action counter to emulate an independent request because the installed controller otherwise skips subsequent repricing in one CLI process.

| Oak | Ash | Cart quantity | Native/OPF unit | Native/OPF line |
| --- | --- | --- | --- | --- |
| 2 | 3 | 1 | 15 | 15 |
| 2 | 3 | 3 | 15 | 45 |
| 3 | 0 | 2 | 13 | 26 |
| 0 | 0 | 1 | 10 | 10 |
| 0 | 4 | 2 | 14 | 28 |

Base product price is 10; image choices have no separate price. These assertions exercise the choice formula and valid nonnegative integer quantities, including zero. They do not establish parity for native invalid quantity coercion.

## Commands and exact results

Run from `/tmp/opf-sumqty-import-review`:

```sh
vendor/bin/phpunit tests/Unit/WapfFormulaReferenceReviewTest.php tests/Unit/WapfMapperTest.php tests/Unit/WapfExporterTest.php
vendor/bin/phpunit --display-phpunit-deprecations
node --test tests/js/opf-sumqty.test.cjs
OPF_SUMQTY_IMPORT_ALLOW=1 wp --path=/tmp/opf-sumqty-import-wp eval-file bin/e2e-wapf-sumqty-import.php > /tmp/opf-sumqty-import-proof.json
python3 bin/e2e-wapf-sumqty-cleanup.py /tmp/opf-sumqty-import-proof.json > /tmp/opf-sumqty-import-cleanup.json
php -l includes/Engine/WapfMapper.php
php -l tests/Unit/WapfFormulaReferenceReviewTest.php
php -l bin/e2e-wapf-sumqty-import.php
git diff --check
```

- Focused PHP: **71 tests, 337 assertions, passed**.
- Full PHP: **347 tests, 1357 assertions, exit 0**. One existing PHPUnit deprecation: doc-comment metadata on `CapabilityFixtureRegistryTest::test_validator_rejects_a_fixture_missing_a_required_contract_member`; no failed assertions.
- JS: **2 tests, 2 passed, 0 failed**. These are existing PHP/JS evaluator comparisons; this lane adds no browser claim.
- Native/imported carts: **5 scenarios passed**, exit 0.
- PHP lint: **no syntax errors**; diff check exit 0.
- Cleanup: the fixture removes owned product/group IDs in `finally`, records ownership on each insertion even during partial importer failure, restores request bags/admin-only option and empties the cart. A separate Python process opens SQLite read-only: **0 fixture post types, 0 owned postmeta rows, 0 fixture ownership options**. No admin credentials are embedded in the fixture.

Artifacts: `evidence/WAPF-SUMQTY-IMPORT-PROOF-2026-10-02.json` and `evidence/WAPF-SUMQTY-IMPORT-CLEANUP-2026-10-02.json`.

## Remaining scope

- `WAPF-ADMIN-IMPORT-EXPORT` stays **partial**. Licensed native Tools import/export and its ID allocation behavior remain unproven; this proof uses the native PHP converter and persisted model, not that UI.
- Full formula/admin rows are not closed. Browser admin import/error flows, archive export round-trip and Store API/order lifecycle for this imported fixture were not exercised here.
- Native multi-quantity types other than mapped image quantities retain review. Repeated consumers and targets retain review; directly cloned image quantities already throw `InvalidArgumentException: Image quantity fields cannot repeat.` in existing schema normalization. The unit test records that existing rejection; this patch does not repair it.
- Independent media/layout reviews remain. Files formulas and forward/self price-reference semantics still require separate audit.
- Protected tabs, Calculator, frontend JS and cart integration were not edited. No ledger/status update, push, deployment or tag.
