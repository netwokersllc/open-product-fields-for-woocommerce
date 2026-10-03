# Dates lane evidence — OPF ↔ WAPF Extended 3.1.5 parity

Captured 2026-10-03 on the disposable clone `http://127.0.0.1:8306`
(`/tmp/opf-image-dates-wp`, site timezone UTC, `start_of_week=1` = Monday).

Run order per side: guarded fixture → server-side verify → real Chromium →
cleanup. All checks passed; pageerror count 0 on both sides.

## Fixture parity

| Rule | WAPF storage (reference) | OPF storage |
|---|---|---|
| Disabled weekday | `disabled_days` = `"6"` (CSV scalar; `class-field-groups.php:336` keeps bare `'0'`) | `disabled_weekdays = [6]` |
| Disabled dates | `disabled_dates` = `"02-10-2027 02-12-2027, 12-25"` (**mm-dd-yyyy / mm-dd**, per WAPF admin note at `class-extended-controller.php`) | `disabled_dates = ["2027-02-10 2027-02-12","12-25"]` (ISO / MM-DD) |
| Same-day cutoff | `disable_today_after` = `"00:00"` | `cutoff_time = "00:00"` |
| Display format | `wapf_date_format` option (default `mm-dd-yyyy`) | `opf_date_format` option → `yyyy.mm.dd` for the run |
| Second field | `co` (cutoff only, optional) | `loose` (unrestricted) |

## Results

### OPF (`open-product-fields-for-woocommerce`, worktree `/tmp/opf-lane-dates`)

- `lifecycle-verify.log` — 17 checks: classic `woocommerce_add_to_cart_validation`
  and Store API `POST /wc/store/v1/cart/add-item` both reject a Saturday
  (weekday), `2027-02-11` (range middle), `2028-12-25` (recurring MM-DD on a
  **Monday** — isolated from the weekday rule), and today-after-cutoff; a valid
  Monday reaches the cart (classic 201/store), cart label displays
  `2027.02.15`, `_opf_fields` keeps ISO `2027-02-15`, order display meta shows
  `2027.02.15`. Deterministic cutoff boundary: `FieldValue::validate` with an
  injected Monday "today" allows 11:59 and rejects 12:01 against a 12:00 cutoff.
- `opf-browser-results.json` / `lifecycle-browser.log` — 21 checks: input
  carries `data-opf-disabled-weekdays/-dates/-cutoff/-week-start`; dialog opens
  with accessible name + `aria-live` month header; **week starts on the
  configured Monday**; Sat 2027-02-06/13 disabled; range 2027-02-10..12
  disabled; recurring `2028-12-25` disabled (Monday — isolated); today blocked
  by cutoff; ArrowRight skips disabled; PageUp/Down navigate months; Escape
  closes; forged Saturday and forged today submissions are rejected with
  notices; cart renders `2027.02.15`.
- `import-equivalence.log` — 13 checks: WAPF serialized shapes map correctly:
  array `disabled_days`, scalar CSV `"0,6"`, bare `'0'`, documented
  `mm-dd-yyyy`/`mm-dd` `disabled_dates` → ISO rules, `disable_today_after` →
  `cutoff_time`; malformed values still flag `needs_review`.
- `blackout-browser.log` — 26 checks (pre-existing standalone test, OPF
  frontend JS in isolation): keyboard, ARIA grid, cutoff boundary, recurring
  ranges.
- `format-fallback.log` — 3 scenarios (invalid OPF → WAPF value, garbage →
  `mm-dd-yyyy` default, `yyyy.mm.dd`): rendered picker toggle, Store API cart
  label, cart page, checkout page all show the configured format; native input
  stays ISO.

### WAPF Extended 3.1.5 reference

- `wapf-reference-verify.log` + `wapf-reference-server-results.json` —
  16 checks over both formats. Saturday → `"has an invalid date."`; range →
  `"contains a disallowed date."`; recurring `12-25` rejected for the current
  year; **recurring `12-25` in 2028 is ACCEPTED server-side** (reference gap —
  `Helper::string_to_date` expands `MM-DD` to the current year only, while the
  picker blocks every year; OPF rejects it, so OPF is stricter/consistent);
  isolated cutoff field rejects today with `"Today&#039;s date can't be
  selected ... because it is after 12:00 am."`; later dates pass; valid values
  add to cart and store the submitted **formatted** string verbatim
  (`raw`/`values[0].label` = `02-15-2027` / `2027.02.15` — WAPF does not keep a
  canonical ISO value; OPF's `_opf_fields` ISO storage + formatted display meta
  is a documented improvement).
- `wapf-reference-results.json` / `wapf-reference-browser.log` — 15 checks:
  input renders `data-disabled-days/-dates/-disable-after` + `data-format`;
  picker opens on focus; **week header is `['M','T','W','T','F','S','S']` —
  WAPF does honor `start_of_week`** (`views/frontend/fields/date.php` passes
  `weekStart: get_option('start_of_week')` to the `dp` widget); Saturdays,
  range, and recurring `2028-12-25` render disabled `li` cells; picking fills
  `2027.02.15`; forged Saturday + forged today are rejected with WC error
  notices; cart page shows `2027.02.15`.
- Reference a11y baseline: `.wapf-dp-c` has **no** `role`/`aria-label`, day
  cells are non-focusable `<li>` with no roles/tabindex — OPF's dialog/grid
  ARIA + keyboard model exceeds the 3.1.5 reference.

## Per-row outcome

| Row | Outcome |
|---|---|
| WAPF-DATE-WEEKDAYS | **proven** — UI disabled + classic/Store API reject; WAPF scalar `disabled_days` import fixed + proven |
| WAPF-DATE-DISABLED-DATES | **proven** — exact/range/recurring enforced UI+server; documented difference: WAPF server only expands MM-DD to current year, OPF enforces every year |
| WAPF-DATE-CUTOFF | **proven** — isolated cutoff field rejects today server-side both plugins; deterministic boundary checks added |
| WAPF-DATE-ACCESSIBILITY | **proven (OPF exceeds reference)** — grid roles/keyboard/aria-live vs WAPF's non-focusable `li` list, no dialog semantics |
| WAPF-DATE-FORMAT | **proven** — configured format drives picker label + cart/order display; OPF keeps ISO in `_opf_fields`; fallback chain verified |
| WAPF-DATE-WEEK-START | **proven** — both honor `start_of_week` (WAPF via `weekStart` dp option — ledger's "no corresponding setting" note is stale) |

## Environment note

mu-plugin `probe-hooks.php` in the clone called `FieldGroups::all()` unguarded
and fatal'd every product page whenever OPF was inactive — this broke the
previous run's WAPF reference phase. Patched with a `class_exists` guard
(clone-local scaffolding fix; the worktree itself is unaffected).

## Cleanup / baseline

Fixture products/orders/users/options removed; `opf_date_format` restored to
baseline `d/m/yy`; `wapf_datepicker`/`wapf_date_format` deleted (were unset);
failed-run leftover product `15843` deleted; active plugins =
`open-product-fields-for-woocommerce`, `sqlite-database-integration`,
`woocommerce` (WAPF inactive per lane contract). Final counts:
products {10,11,14374,14397,14961}, users 1, orders 250 — matches baseline.json.
