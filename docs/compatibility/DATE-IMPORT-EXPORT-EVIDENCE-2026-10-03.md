# WAPF-FIELD-DATE residual — evidence

Clone `http://127.0.0.1:8323` (`/tmp/opf-image-uploadui-wp`), OPF pointed at
`/tmp/opf-lane-prodtypes`. Reference: installed WAPF Extended **3.1.5**.

## What the row note said was missing

Ledger line 175:

> WAPF legacy option serialization/import mapping remains unverified and is
> not claimed.

The import direction was already implemented in `WapfMapper::map_date_settings()`
(and covered by the dates-lane evidence). The **export** direction was the
actual hole: `WapfExporter::map_field()` hard-threw
`"WAPF Tools export cannot preserve OPF date-field settings."` and
`WapfWxrExporter`'s flattened `options` key list omitted every date key, so a
date group could be imported but never round-tripped back.

## Source audit

`wapf-3.1.5-date-key-audit.md` (line-numbered) covers the exact keys in
`extend/date.php`, `class-field-groups.php`, `class-extended-controller.php`,
`class-config.php`, `class-cart.php`, `class-html.php`, and
`views/frontend/fields/date.php`:

| WAPF key | Source | OPF mapping |
| --- | --- | --- |
| `disable_past` / `disable_future` | `class-config.php:740-741`, `class-cart.php:269-282` | invert → `allow_past` / `allow_future` |
| `disable_today` | `class-config.php:742`, `class-cart.php:271` | **unsupported** (no OPF equivalent, flagged) |
| `min_date` / `max_date` | `extend/date.php:95-99`, `class-cart.php` | `min_date` / `max_date` (ISO + relative periods) |
| `disabled_days` | `class-field-groups.php:336-339` (CSV scalar; bare `'0'` special) | `disabled_weekdays` |
| `disabled_dates` | `extend/date.php:88-102`, `class-cart.php:163-184` | `disabled_dates` (ISO / MM-DD / inclusive range) |
| `disable_today_after` | `class-extended-controller.php:194`, `extend/date.php:200-216` | `cutoff_time` |
| `wapf_date_format` (global) | `class-html.php:709`, `extend/date.php` | migrated to `opf_date_format` (`Importer::migrate_date_format`) |

Note: `class-html.php`'s only date option is the global display format
(`data-df`); `field_data()` supplies localized month/day names. The
field-level constraints all live in `extend/date.php` /
`class-extended-controller.php`.

## Implementation

- `WapfExporter::map_date_settings()` serializes OPF date settings back to the
  WAPF keys; `date_to_wapf()` converts ISO `YYYY-MM-DD` tokens to
  `mm-dd-yyyy` and passes relative periods / `MM-DD` through. `disabled_days`
  is emitted as the CSV scalar WAPF stores, preserving the bare `'0'` case.
- `WapfWxrExporter` flattens `disable_past`, `disable_future`, `min_date`,
  `max_date`, `disabled_dates`, `disable_today_after` into the stored
  `options` bucket (`disabled_days` was already listed).
- `disable_today` is intentionally not emitted: OPF has no equivalent and the
  importer already surfaces it as `needs_review`.

## Round-trip proof

`bin/e2e-date-roundtrip.php` on the real WordPress runtime
(`date-roundtrip-results.json`, **31 checks, 0 failures**):

1. WAPF-serialized `disabled_days='0,6'`, `'0'`, `disabled_dates` CSV with a
   range, `min_date='02-10-2027'`, `max_date='1y'`, `disable_today_after='12:00'`,
   `disable_past=true`/`disable_future=false` import cleanly to canonical OPF.
2. `WapfExporter::build_payload()` emits the WAPF keys again
   (`'02-10-2027'`, `'1y'`, `'0,6'`, `'02-10-2027 02-12-2027,12-25'`,
   `'12:00'`, bare `'0'`).
3. Re-importing the exported payload reproduces every OPF date key.
4. The WXR document is valid XML and its serialized field `options` bucket
   contains the date keys.

Unit-level: `WapfExporterTest` date round-trip + bare-zero/relative test;
`WapfWxrExporterTest` date options test (PHPUnit 682 total, 0 failures).

## Bounds

- WAPF's own Tools admin parser (`raw_json_to_field_group`) was **not**
  executed: the installed 3.1.5 clone fatals while parsing this clone's
  pre-existing 746 `wapf_product` rows. The round-trip is proven through OPF's
  mapper/exporter against the exact stored option shapes WAPF produces
  (including the CSV/bare-zero serialization at
  `class-field-groups.php:336-339`).
- `disable_today` remains unsupported by design and is documented as such.
