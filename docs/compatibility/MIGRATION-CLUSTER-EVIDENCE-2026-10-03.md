# Migration/import-export cluster evidence — 2026-10-03

Lane: `migration/import-export` (5 rows: `WAPF-MIGRATION-IMPORT-LOCAL`,
`WAPF-MIGRATION-IMPORT-GLOBAL`, `WAPF-MIGRATION-EXPORT`,
`WAPF-MIGRATION-MEDIA-PORTABILITY`, `WAPF-MIGRATION-PRODUCT-ID-PORTABILITY`).

Worktree: `/tmp/opf-lane-migration` (branch `lane/migration`).
Clone: `/tmp/opf-image-migration-wp`, site `http://127.0.0.1:8310`.
Reference: WAPF Extended 3.1.5 (`advanced-product-fields-for-woocommerce-extended`).

## Scope of this wave

- Extend `WapfMapper` to map WAPF `products` fields (manual/category sourcing,
  presentation subtype, qty limits/defaults, display slots) instead of dropping
  them as unsupported.
- Map WAPF `file` fields to the OPF `upload` type (multiple, maxsize, accepted
  types) and drop non-representable upload pricing/repeat with review.
- Verify the `variables` + `lookuptable` export path added last wave.
- Prove round-trip fidelity: OPF → WAPF Tools JSON → native WAPF store → OPF
  mapper (fixed point), plus WXR and OPF-archive transfer.
- Surface linked-product ID portability in archive export/import warnings.

## Coverage inventory vs WAPF 3.1.5's own format

WAPF's raw Tools shape is produced by
`Field_Groups::field_group_to_raw_fields_json()` (flattens each field's
`options` to top-level keys) and consumed by
`Field_Groups::raw_json_to_field_group()` (routes some keys into `options`, with
`Linked_Products_Controller::sanitize_field_data` handling `product_selection`,
`product_query`, `slot_*`, `incl_*`, `img_fit`, qty limits). The OPF exporters
emit that flat shape and the mapper consumes the stored nested shape
(`Field::to_array()` → `options` + top-level `subtype`).

| WAPF key (raw Tools / stored options) | OPF normalized key | Import | Export |
| --- | --- | --- | --- |
| `type=products`, `subtype` (`checkbox/radio/dropdown/image/card/vcard/card-qty/vcard-qty`) | `type=products`, `subtype` | ✅ mapped | ✅ |
| `product_selection` (`manual`/`category`) | `product_selection` | ✅ | ✅ |
| `choices[].id` (product ID), `slug`, `label`, `selected`, `disabled`, `pricing_type` (`fixed`/`none`) | `choices[].product_id/slug/label/selected/disabled/pricing_type` | ✅ | ✅ |
| `choices[].options.{default,min,max}` (qty subtypes) | `choices[].quantity.{default,min,max}` | ✅ | ✅ |
| `product_query.{query_id,query_label,limit,sort,pricing_type}` | `product_query` | ✅ | ✅ |
| `qty_method` (`one`/`parent`) | `qty_method` | ✅ | ✅ |
| `display` (`default`/`plus_min`) | `display` | ✅ | ✅ |
| `min_choices`/`max_choices` (qty subtypes) | `min_choices`/`max_choices` | ✅ | ✅ |
| `slot_1/2/3`, `incl_img`, `incl_desc` | same | ✅ | ✅ |
| `img_fit` (`cover`/`contain`) | `img_fit` | ✅ | ✅ |
| `label_pos`, `item_width` (image subtype) | `label_pos`, `item_width` | ✅ | ✅ (WAPF default 68 made explicit) |
| `large_image` | `image_zoom` | ✅ | ✅ |
| `multiple`, `maxsize`, `accept` (`file`) | `multiple`, `max_size`, `accepted_types` | ✅ | ✅ |
| `variables[].{name,default,rules[]}` incl. `lookuptable(...)` | `variables` | ✅ | ✅ |
| `hide_cart`/`hide_checkout`/`hide_order` (any field) | same | ✅ | ✅ |

Known representational gaps (flagged, never silently dropped):

- Non-qty products `min_choices`/`max_choices` (checkbox/radio/card/vcard/image)
  have no OPF enforcement → import keeps the field and adds a review note.
- WAPF products field-level pricing is dropped with review (linked products price
  themselves).
- Upload field pricing is dropped with review (OPF uploads do not charge); upload
  fields inside repeated sections are dropped with review (`FieldGroup` rejects
  that placement, as does WAPF rendering).
- WAPF products legacy Pro presentation toggles `incl_link`/`incl_price`/
  `incld_stock` are not modelled; OPF expresses the same surface through
  `slot_1/2/3` + `incl_img`/`incl_desc`.
- `lookuptable(...)` formula/variable references are preserved but review-flagged
  because they depend on runtime tables.

## Verification (verbatim)

### Unit tests

```
$ composer install   # vendor already present in this worktree
$ vendor/bin/phpunit
Tests: 564, Assertions: 2410, PHPUnit Deprecations: 1.
OK, but there were issues!   # the 1 deprecation is pre-existing
```

Added focused mapper/exporter tests: products image subtype (`label_pos` + default
`item_width` 68), category selection without a query id, unsupported subtype
fallback, invalid upload `maxsize`/`accept` token review, and hide
checkout/order flags.

### Round-trip e2e (native WAPF on the clone)

```
$ OPF_WAPF_PRODUCTS_E2E_ALLOW=1 \
  OPF_WAPF_PRODUCTS_E2E_ARTIFACTS=/tmp/opf-lane-migration-evidence \
  wp --path=/tmp/opf-image-migration-wp eval-file bin/e2e-wapf-products-roundtrip.php
{"passed":44,"total":44,"needs_review":true,"notes":[
  "field \"Fee\" formula contains references that require runtime review (lookuptable(cutting;width;2)); pricing needs review.",
  "variable \"feevar\" formula contains references that require runtime review (lookuptable(cutting;width;5)); pricing needs review."]}
ok WAPF products/upload/variables round-trip: 44/44 checks
```

44/44 checks cover: products manual (subtype/selection/qty/choice IDs/pricing/
slots/columns/includes/hide), products category (selection/query/no choices/
img_fit/subtype), products card-qty (subtype/display/limits/choice quantity),
upload (type/flags/required/hide/pricing), variables, lookuptable formula, field
conditional (`greater`), group placement rule, review notes limited to runtime
formulas, fixed-point stability (`map1.group === map2.group`), and WXR native
parse. `post-cleanup.json` reports `equal: true`.

### CLI round-trip matrix

```
$ wp opf export --group=<id> --format=wapf-json --output=.../cli/wapf-json.json
$ wp opf export --group=<id> --format=wapf-wxr  --output=.../cli/wapf-wxr.xml
$ wp opf export --group=<id>                     --output=.../cli/opf-archive.json
```

Native WAPF parse + `WapfMapper` re-import of the CLI artifacts
(`cli/cli-results.json`):

```
wapf_json:   products_manual ✅ products_category ✅ products_qty ✅ upload ✅ variables ✅
wapf_wxr:    products_manual ✅ products_category ✅ products_qty ✅ upload ✅ variables ✅
opf_archive: warning product_target_ids_may_not_match ✅ ; dry-run imported=1 skipped=0 status=draft
```

### Regression

```
$ OPF_ARCHIVE_E2E_ALLOW=1 wp --path=/tmp/opf-image-migration-wp \
  eval-file bin/e2e-opf-archive-portability.php
ok archive portability warnings, dry-run, preserved IDs and media refs, review draft, and idempotent repeat
```

## Environment / cleanup

- `php 8.5.11`, WordPress `7.1.2`, WooCommerce `11.1.0`, OPF `0.1.0`,
  WAPF Extended `3.1.5`.
- Baseline: products=5, wapf_product=749, opf_field_group=7, attachment=1,
  posts=1030, options=421, users=1, WAPF inactive.
- After the full run: `post-cleanup.json` `equal: true`; CLI fixture and option
  removed; WAPF reference plugin returned to inactive.
- Pre-existing clone content (749 `wapf_product`, 250 orders, etc.) was left
  untouched; only fixture posts created by this proof were deleted.

## Artifacts

- `baseline.json`, `post-cleanup.json` — before/after state.
- `source-opf-group.json`, `wapf-tools-payload.json`,
  `wapf-tools-payload-2.json`, `wapf-native-model.json`,
  `wapf-native-raw-export.json`.
- `mapped-opf-group-1.json`, `mapped-opf-group-2.json` — fixed-point pair.
- `wxr.xml`, `wxr-native-model.json`.
- `opf-archive.json`, `archive-import.json`.
- `php-results.json` — the 44 assertions.
- `cli/{wapf-json.json,wapf-wxr.xml,opf-archive.json,cli-results.json}` — CLI
  matrix.

## Suggested ledger deltas (integrator-owned)

All five rows remain `partial`; this wave closes the specific products/upload/
variables gaps the rows name and adds durable round-trip proof. Recommended
evidence pointer: this file. Remaining documented gaps: OPF archive transfer does
not reassign destination media/product IDs (rows stay partial), and WAPF Pro
legacy product toggles are superseded by slots.
