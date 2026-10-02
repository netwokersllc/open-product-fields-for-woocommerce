# WAPF sumQty Tools parser round-trip evidence — 2026-10-02

## Scope and runtime

This records source/parser evidence for the `sumQty(field ID)` Tools import
mapping in the isolated worktree at `/tmp/opf-sumqty-mapping-20261002`, HEAD
`6d0578e9653ff40d2c839693b767ce9d650c5b89`. The emitted payload was built by
the current `WapfMapper::map()` and `WapfExporter::build_payload()` and used an
image quantity field `prints` (`image-swatch-qty`, oak min `0`, max `8`,
default `0`) plus an `fx` choice formula `sumQty(prints)*[qty]`.

The parser harness ran on the disposable clone `/tmp/opf-sumqty-woo-wordpress`:
WordPress 7.1.2, WooCommerce 11.1.0, WAPF Free 1.7.1, Extended 3.1.5. The
two installed Extended source files used by the proof were:

| File | SHA-256 |
| --- | --- |
| `includes/classes/class-field-groups.php` | `a93eec77639cf7ba4bc23908927421dacf26490de141fda7734ed7560720a9f8` |
| `includes/controllers/class-admin-controller.php` | `c3006f34b63ff306a7d49c330e64f8f7fe56f998b90398327ccec6ab4f75ebac` |

## Executed parser path and result

The temporary payload and WP-CLI harness were removed after execution. The
command used was:

```sh
wp --path=/tmp/opf-sumqty-woo-wordpress \
  eval-file /tmp/opf-sumqty-wapf-parser-roundtrip.php
```

The harness called this exact WAPF path without writing to the database:

1. `Field_Groups::raw_json_to_field_group($raw)` parsed the four-section
   mapper/exporter payload. The field remained `prints` / `image-swatch-qty`;
   its oak choice retained `min=0`, `max=8`, `default=0`; the fee choice
   retained `pricing_type=fx` and the exact expression bytes
   `sumQty(prints)*[qty]`.
2. `serialize($field_group->to_array())` produced the same serialization
   shape used by `Field_Groups::save()` (`class-field-groups.php:736-768`).
3. `Field_Groups::process_data($serialized)` reloaded that serialized group
   (`:778-808`).
4. `Field_Groups::field_group_to_raw_fields_json($reloaded)` projected the
   fields into WAPF Tools export shape (`:19-80`). The harness reassembled the
   projected fields with the original top-level sections.
5. `raw_json_to_field_group($projected_raw)` parsed the projected payload
   again. The image type, bounds/default, `fx` type, and exact formula string
   remained unchanged.

Serialization observations:

| Stage | Bytes | SHA-256 |
| --- | ---: | --- |
| First parsed group serialized | 1473 | `18e0a23baae927061dcc45510e0ceaccbd90cdaef942b8006cc2b6b8d5a65980` |
| Projected export reparsed and serialized | 1509 | `125f2e893d3474d8e1f7e107ed46e7aefde3a3a204e16ee8a05ebccde3430ba8` |

The only observed round-trip delta was `options.meta: ""` on the image field.
Before projection, its `options` keys were `["choices"]`; after projection and
reparse they were `["choices", "meta"]`. WAPF's projection serializes the
runtime `Field::$meta` property as a top-level `meta` value (lines 67-76),
while the Extended parser's reserved-key list (lines 161-169) does not include
`meta`; the generic extra-attribute handling therefore retains it under
`options`. This is WAPF's parser/projection delta. The formula and image
quantity settings were byte/value-preserved. OPF does not currently normalize
this unrelated WAPF `options.meta` projection artifact. No calculator or
storefront JavaScript behavior was part of this lane.

For formula acceptance, the Extended parser sanitizes `pricing_amount` as
textfield for `pricing_type === 'fx'` (`:217-223`), preserving this expression.
Field-ID mapping and quantity option serialization are covered by the
worktree's focused mapper/exporter tests; this parser run independently
confirms WAPF 3.1.5 accepts the emitted shape and preserves the relevant values
through its parser, serializer, projection, and second parse.

## Tools UI limit

Actual admin UI was opened on the same clone at
`http://127.0.0.1:8199/wp-admin/post.php?post=22&action=edit`. Extended 3.1.5
checks `Licensing::get_license_info()` and returns before rendering editable
fields when no license is active (`class-admin-controller.php:518-524`). The
page showed the license activation prompt, no field rows, and an empty
`wapf-fields` input. The Tools import interaction displayed “Import done” but
created no saved field group; no `_wapf_fieldgroup` metadata existed on test
post 22. Therefore this is **not** proof of a successful licensed Tools UI
import/save/export cycle. UI persistence remains unproven; parser-level
acceptance is the proven result. Four inspected disposable clones had no WAPF
license option; no license was requested, bypassed, or borrowed.

## Cleanup

The temporary harness, payload, result, and screenshot were removed; test draft
post 22 was deleted; WAPF Free and Extended were deactivated back to their
original inactive state; the loopback server on port 8199 was stopped. No
production site/database or ledger was touched. The disposable clone's
`opf-proof` admin password was changed to a temporary test value to access the
UI; its prior value was unknown and could not be restored. The clone remains
disposable and should not be treated as a clean credential baseline.
