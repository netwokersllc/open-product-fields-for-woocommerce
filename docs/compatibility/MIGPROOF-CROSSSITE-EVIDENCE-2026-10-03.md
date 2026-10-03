# migproof — 5 MIGRATION rows cross-site portability proof (2026-10-03)

Lane: `migproof` (worktree `/tmp/opf-lane-migproof`, base `405a5f6`).
Rows: `WAPF-MIGRATION-IMPORT-LOCAL`, `WAPF-MIGRATION-IMPORT-GLOBAL`,
`WAPF-MIGRATION-EXPORT`, `WAPF-MIGRATION-MEDIA-PORTABILITY`,
`WAPF-MIGRATION-PRODUCT-ID-PORTABILITY`.

## Environment

| | Source A | Destination B |
| --- | --- | --- |
| URL | http://127.0.0.1:8321 | http://127.0.0.1:8330 |
| Path | `/tmp/opf-image-currproof-wp` | `/tmp/opf-image-migproof2-wp` |
| OPF plugin | symlink → `/tmp/opf-lane-migproof` | symlink → `/tmp/opf-lane-migproof` |
| WAPF Extended 3.1.5 | inactive | **active** (reference importer) |

PHP 8.5.11 · WordPress 7.1.2 · WooCommerce 11.1.0 · OPF 0.1.0 · SQLite.

## What was proven

The source site builds one WAPF-shaped OPF group covering every site-local
reference class the MIGRATION rows name:

| field / rule | reference carried | source value |
| --- | --- | --- |
| `products` (checkbox, manual) | product IDs | 15939, 15940 |
| `products` (card, category) | `product_query.query_id` (product_cat term) | 22 |
| `swatch` choices | attachment IDs | 15944, 15945 |
| `content_image` | attachment ID **+ URL** | 15945 + `…/migproof-blue.png` |
| placement `product` | product ID | 15939 |
| placement `product_cat` | term ID | 22 |
| placement `product_var` | variation ID | 15943 |
| placement `var_att` | `attr|slug` pair | `migsize|s` |

The same group is exported from A (OPF archive, WAPF Tools JSON, WXR) and fed
to destination B, which has the **same natural keys at different IDs**:
product SKUs `MIGPROOF-A/B/C` at 15981–15983, variation at 15970, product_cat
slug `migproof-cat` at term 38, attachments at 15986/15987. A decoy product was
deliberately created at the exact source product ID 15939 to model an ID
collision.

### Reference resolution matrix (`crosssite-comparison.json`)

| reference | source ID | destination ID by natural key | auto-remap? | OPF behavior | WAPF 3.1.5 behavior |
| --- | --- | --- | --- | --- | --- |
| product choice / `product` placement | 15939 | 15981 (SKU `MIGPROOF-A`) | **no** | archive warns + import held as **draft**, literal 15939 kept | literal 15939 kept, **published, no warning** |
| product choice | 15940 | 15982 (SKU `MIGPROOF-B`) | **no** | flagged + draft | literal, silent |
| product choice | 15941 | 15983 (SKU `MIGPROOF-C`) | **no** | flagged + draft | literal, silent |
| `product_var` placement | 15943 | 15970 | **no** | flagged + draft | literal, silent |
| `product_cat` placement / category query | 22 | 38 (slug `migproof-cat`) | **no** | flagged + draft | literal, silent |
| `var_att` placement | `migsize\|s` | `migsize\|s` | **portable** (no ID) | carried verbatim | carried verbatim |
| swatch attachment | 15944 | 15986 (`migproof-red.png`) | **no** | `media_files_not_included` + draft; literal ID kept | literal, silent |
| content image | 15945 + URL | 15987 + same URL | **no** (ID), URL portable | warning + draft; **URL preserved** | literal ID + URL kept, silent |

Concrete failure evidence: on B the preserved product ID 15939 resolves to the
decoy **`DECOY unrelated product`** (`post_type=product`, wrong SKU), not the
intended `Dest alpha`; and after import the group does **not** apply to the
product that actually carries `MIGPROOF-A`
(`placement_behavior.imported_group_reaches_intended = false`).

### What remaps automatically vs what warns vs what fails

- **Remaps automatically: nothing.** Neither OPF nor WAPF remaps site-local
  identifiers. The export carries only literal IDs (`choices[].id`,
  `product_query.query_id`, `rule_groups[].terms`, `attachment`), and neither
  importer resolves them against the destination catalog. The only portable
  datum that survives is the `content_image` **URL** and `var_att`
  `attribute|slug` pairs; both are carried verbatim by both engines.
- **Warns:** OPF only. Export adds `product_target_ids_may_not_match` and
  `media_files_not_included`; import turns them into `_opf_needs_review` notes
  and forces the group to **draft**. WAPF's Tools importer emits **no warning**
  and publishes.
- **Fails:** preserved IDs point at missing or wrong entities on B. OPF's draft
  gate keeps the breakage out of the storefront until a human remaps; WAPF
  publishes broken references silently. Image files are not copied by either
  engine (`media_files_not_included`).

This is why the residual cannot be closed by an automatic remap from the
current archive: the payload has no portable product/attachment natural key.
The safe, honest contract is flag-and-draft, which OPF does and WAPF does not.

## Fixes landed in the worktree

1. `includes/Engine/WapfMapper.php` — import group-level WAPF
   `product_var` (`subject=product_variation`) and `patts`
   (`subject=var_att`) placement rules into OPF `product_var` / `var_att`.
   Previously dropped as "no OPF equivalent".
2. `includes/Service/WapfExporter.php` — export OPF `product_var` /
   `var_att` placement rules to WAPF `product_variation/product_var` and
   `var_att/patts`. Previously **failed closed**, blocking any Tools/WXR export
   of a group with variation placement. `map_placement_rule()` now matches
   WAPF's own config vocabulary.
3. `includes/Service/Exporter.php` + `includes/Service/ArchiveImporter.php` —
   `product_var` (variation IDs) is now detected as a site-local target, so
   archive export warns and destination import drafts. Import note clarified to
   include variation targets.

Tests added: `tests/Unit/WapfMapperTest.php` (variation/attribute placement),
`tests/Unit/WapfExporterTest.php` (export + fixed-point round trip),
`tests/Unit/ExporterTest.php` (`product_var` site-local, `var_att` portable).

## Verification (verbatim)

Unit suite:

```
$ vendor/bin/phpunit
Tests: 680, Assertions: 2798, PHPUnit Deprecations: 1.
OK, but there were issues!   # the 1 deprecation is pre-existing
```

Existing archive portability regression:

```
$ OPF_ARCHIVE_E2E_ALLOW=1 wp --path=/tmp/opf-image-currproof-wp eval-file bin/e2e-opf-archive-portability.php
ok archive portability warnings, dry-run, preserved IDs and media refs, review draft, and idempotent repeat
```

Two-site lifecycle:

```
$ OPF_XSITE_ALLOW=1 OPF_XSITE_ARTIFACTS=/tmp/opf-lane-migproof-evidence \
  wp --path=/tmp/opf-image-currproof-wp eval-file bin/e2e-crosssite-migproof-source.php
{"ok":true,"group_id":15946,...,"archive_warnings":["media_files_not_included","product_target_ids_may_not_match"],"wapf_export_ok":true,"wxr_ok":true}

$ OPF_XSITE_ALLOW=1 OPF_XSITE_ARTIFACTS=/tmp/opf-lane-migproof-evidence \
  OPF_XSITE_MANIFEST=/tmp/opf-lane-migproof-evidence/source-manifest.json \
  wp --path=/tmp/opf-image-migproof2-wp eval-file bin/e2e-crosssite-migproof-destination.php
OPF archive  : status=draft, data_preserved_verbatim=true, notes=[media_files_not_included,
               product_target_ids_may_not_match, "Site-local product, category, or tag targets
               may need remapping (variation targets included)", "Media files are not included"]
WAPF native  : publish (silent), product_choice_ids=[15939,15940],
               attachment_ids=[15944,15945], conditions preserved incl. product_var/patts
WXR native   : parsed by WAPF Field_Groups::process_data(), same literal IDs
```

User-facing CLI import on B (`wp opf import_archive` — note the real
registered name uses an underscore; the docblock example `import-archive` is not
registered):

```
$ wp --path=/tmp/opf-image-migproof2-wp opf import_archive <archive> --commit --format=json
{"mode":"commit","imported":1,"skipped":0,
 "groups":[{"source_id":15946,"result":"imported","opf_id":15975,"status":"draft","needs_review":true}]}
```

Baseline/cleanup: both sites returned to the exact pre-run snapshot
(`source-post-cleanup.json` equal true; `crosssite-destination-post-cleanup.json`
equal true; source A and dest B both 1032 posts, 12 OPF groups, 746
`wapf_product`, 5 products, 1 attachment).

## Artifacts (`/tmp/opf-lane-migproof-evidence/`)

- `crosssite-comparison.json` — reference resolution matrix + OPF/WAPF behavior.
- `crosssite-destination-results.json` — full destination run result.
- `crosssite-destination-cli-import.json` — user-facing CLI import report.
- `crosssite-source-opf-archive.json`, `crosssite-source-wapf-tools.json`,
  `crosssite-source-wapf-wxr.xml` — the three exports.
- `source-manifest.json` — source IDs + natural keys.
- `source-baseline.json` / `source-post-cleanup.json`,
  `crosssite-destination-post-cleanup.json`, `crosssite-environment.json`.

Scripts: `bin/e2e-crosssite-migproof-source.php`,
`bin/e2e-crosssite-migproof-destination.php`.

## Suggested ledger deltas (integrator-owned)

- All five rows: add this evidence file; the cross-site remap residual is now
  **proven**. Honest wording: site-local product/variation/category/tag/attachment
  IDs are **not auto-remapped** on either OPF or WAPF; OPF warns on export and
  holds the imported group as a review draft, WAPF preserves the IDs silently
  and publishes. `content_image` URLs and `var_att` slugs are portable.
- `WAPF-MIGRATION-EXPORT` gains: variation/attribute placement rules now export
  to WAPF Tools/WXR (new `WapfExporter` support).
- `WAPF-MIGRATION-PRODUCT-ID-PORTABILITY` gains: `product_var` variation IDs are
  now included in the site-local warning/draft gate.
- Minor: the CLI docblock examples say `wp opf import-archive` but the
  registered subcommand is `wp opf import_archive`.

No commits, tags, or shared-doc edits were made.
