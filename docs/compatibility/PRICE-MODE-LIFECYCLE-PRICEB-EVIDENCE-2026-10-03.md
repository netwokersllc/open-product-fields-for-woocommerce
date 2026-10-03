# Price-mode lifecycle evidence (PriceB lane) — 2026-10-03

Disposable-clone proof of the 12 price-mode rows against the installed WAPF
Extended 3.1.5 reference runtime. Worktree: `/tmp/opf-lane-priceb` (branch
`lane/priceb`). Clone: `/tmp/opf-image-priceb-wp` at `http://127.0.0.1:8317`.
PHP 8.5.11, WooCommerce 11.1.0, WAPF Extended 3.1.5.

Every run builds one native WAPF product and one OPF product (never both
engines on one product), drives both through the same paths, compares the
persisted/served numbers, then removes every fixture and asserts the clone
counters returned to baseline.

## Row outcomes

| Row | Outcome | Proof |
|-----|---------|-------|
| WAPF-PRICE-FLAT | proven | `flat` ($5) and `flat_frac` ($0.335) at q=1/q=3, Store API q=3, checkout order item totals + tax, partial refund, order-again |
| WAPF-PRICE-PERCENT | proven | `p` (20% line-flat) and `p_sale` (20% of sale base $8), full lifecycle |
| WAPF-PRICE-QUANTITY-PERCENT | proven | `percent` (20% per unit) and `percent_sale` (33.33% of sale base), full lifecycle |
| WAPF-PRICE-VALUE | proven | `nr` ($2×value) incl. decimal (2.5), negative (-3), non-numeric (`abc`→0); full lifecycle + mapper import |
| WAPF-PRICE-QUANTITY-VALUE | proven | `nrq` ($2×value×qty); full lifecycle + mapper import |
| WAPF-PRICE-CHARACTERS | proven | `char` ($0.5×mb_strlen) incl. `héllo€`, combining-mark NFD (`e\u0301x\u0308你好`); full lifecycle + mapper import |
| WAPF-PRICE-QUANTITY-CHARACTERS | proven | `charq` ($0.5×mb_strlen×qty); full lifecycle + mapper import |
| WAPF-PRICE-FORMULA | proven | fx flat (`7`), `[qty]`-scaled (`2*[qty]`), mapped `formula_raw` (`5*[qty]`), signed discount (`-4`); plus real `WapfMapper::map()` import of choice fx + field formulas pricing identically |
| WAPF-PRICE-MATRIX | proven | `lookuptable()` via the real `wapf/lookup_tables` filter through `WapfMapper::map()`: exact hit, nearest round-up, below-first clamp, qty scaling — all match native WAPF |
| WAPF-PRICE-FORMULA-WEIGHT | bug-documented | Live confirmation that OPF drops `weight` metadata at normalization and has no cart weight engine; WAPF reference raises item weight 1→3 |
| WAPF-PRICE-OPTIONS-TOTAL-NEGATIVE-FORMAT | proven (reference divergence documented) | Negative fx options total renders `-$15.00` matching `wc_price()`; WAPF 3.1.5 renders `$-15.00` (sign after symbol) |
| WAPF-PRICE-TOTAL-DISPLAY | proven | Product/options/grand totals match WAPF at q=1 and q=3, incl. per-character charq scaling and negative options |

## Detailed evidence

### Lifecycle matrix — `bin/e2e-priceb-lifecycle.php`
18 cases × 5 legs = **90 comparisons**, all identical (`mismatches: []`,
`order_match: true` for all 18). Each case:
- classic cart at q=1 and q=3 (unit price, subtotal, subtotal tax, line total, line tax);
- Store API `POST /wc/store/v1/cart/add-item` at q=3;
- checkout into a real order per engine (item quantity/total/total_tax + persisted engine meta `_wapf_meta`/`_opf_fields`);
- partial refund of one unit (refund amount + refunded total + remaining);
- real order-again via `WC_Cart_Session::populate_cart_from_order()` (repopulated cart equals the original cart).

Tax: temporary 8.25% exclusive base-location rate, removed in cleanup.
Reference-side `unserialize()` warnings from WAPF `class-field-groups.php:784`
are attributed to the WAPF reference, not OPF.

### Import parity — `bin/e2e-priceb-import-parity.php`
5 scenarios through the **real** `WapfMapper::map()` importer (choice
fixed/qt/p/percent/fx + field charq/nrq/char/nr + `[qty]` field fx), saved as
an OPF group, priced against the native WAPF product. All 5 identical
(`mismatches: []`). This closes the "legacy formula import" residual on
WAPF-PRICE-FORMULA and the import gate for VALUE/CHARACTERS.

### Matrix — `bin/e2e-priceb-matrix.php`
`lookuptable(cutting;widthf;heightf)` with a real
`add_filter('wapf/lookup_tables', …)` migration snippet. 4 matched scenarios
(exact, round-up, below-first clamp, qty scaling) — `mismatches: []`.
Documented divergence (not asserted): an axis beyond the last key makes
WAPF 3.1.5 fatal inside `find_nearest` (PHP 8 mid-chain `TypeError`) while OPF
fails closed to `0`; recorded under `observed_beyond`.

### Totals display — `bin/e2e-priceb-totals.php` + `bin/e2e-priceb-totals.mjs`
Real Chromium at 1280×900, one native WAPF product and one imported OPF
product, base $10. 7 scenarios, all numeric totals identical
(`mismatches: []`):

| scenario | qty | WAPF options | OPF options | grand |
|----------|-----|--------------|-------------|-------|
| default | 1 | 0 | 0 | 10 |
| plus (fixed +5) | 1 | 5 | 5 | 15 |
| minus (fx −15) | 1 | −15 | −15 | 0 (clamped) |
| plus | 3 | 5 | 5 | 35 |
| minus | 3 | −15 | −15 | 15 |
| charq "abcd" | 3 | 6 | 6 | 36 |
| plus + charq | 3 | 11 | 11 | 41 |

The fixture temporarily drafts pre-existing all-product OPF group `15057`
(empty `rule_groups`) because OPF's frontend writer targets
`.wapf-product-totals` and otherwise corrupts the WAPF reference reading;
cleanup restores it to `publish`.

**Negative sign placement (documented reference divergence):**
- OPF options total: `-$15.00` — matches WooCommerce `wc_price(-15)`.
- WAPF 3.1.5 options total: `$-15.00` — WAPF's `formatMoney` sign-rewrite line
  (`e<0 && t.format.replace("%2$s","-%2$s")`) is a no-op (result discarded),
  while `formatNumber` re-injects the minus inside the number. OPF therefore
  differs from WAPF 3.1.5 but follows the documented WAPF Pro intent ("position
  the minus sign") and WooCommerce's canonical output.

### Formula weight gap — `bin/probe-priceb-weight-gap.php`
- `FieldGroup::normalize()` output has **no** `weight` key at field or choice
  level (whitelist schema; the incoming `options.weight`/`weight` never reach
  the normalized field/choice).
- No `Calculator::weight()` function exists.
- Live: WAPF choice `options.weight = 2` on a 1.0 kg product → cart item weight
  **3.0**; the `WapfMapper::map()` imported OPF equivalent → cart item weight
  **1.0** (base). `WapfMapper` now flags every such field/choice for review.

Per the lane brief this is a documentation-only outcome (do not implement).
Small importer fix kept: `WapfMapper` flags WAPF field- and choice-level weight
formulas — including non-numeric formulas like `[x]*2` that `floatval()` to 0 —
instead of silently dropping them.

## Worktree changes kept from the prior agent (reviewed, verified, extended)

- `assets/js/opf-frontend.js` — `lookuptable()` resolves submitted short field
  ids before WAPF's `<6`-char literal heuristic (imported OPF ids can be short).
- `includes/Engine/Calculator.php` — same short-field-id resolution server-side.
- `includes/Engine/WapfMapper.php` — map WAPF `nr`/`nrq`/`char`/`charq` pricing
  to `[x]`/`len([x])` formulas with correct flat/per-unit intent; flag WAPF
  field/choice `weight` options for review.
- `includes/Service/Assets.php` — emit `window.OPF_LOOKUP_TABLES` from the
  `opf_lookup_tables` filter (falling back to `wapf/lookup_tables`) so migrated
  lookup snippets keep working in the browser preview.
- Unit/JS tests for all of the above.

## Verification commands

```
vendor/bin/phpunit                      # 540 tests, 2256 assertions, OK
node tests/js/*.test.cjs                # 9/10 pass
# (opf-builder-auth.test.cjs fails on a pre-existing, unrelated mock gap:
#  assets/js/opf-builder.js calls mount.querySelector(), the test's fake DOM
#  node has no querySelector; opf-builder.js is unchanged from HEAD.)

OPF_PRICEB_E2E_ALLOW=1 OPF_PRICEB_OUT=/tmp/opf-lane-priceb-evidence \
  wp eval-file bin/e2e-priceb-lifecycle.php --path=/tmp/opf-image-priceb-wp
OPF_PRICEB_E2E_ALLOW=1 OPF_PRICEB_OUT=/tmp/opf-lane-priceb-evidence \
  wp eval-file bin/e2e-priceb-matrix.php --path=/tmp/opf-image-priceb-wp
OPF_PRICEB_E2E_ALLOW=1 OPF_PRICEB_OUT=/tmp/opf-lane-priceb-evidence \
  wp eval-file bin/e2e-priceb-import-parity.php --path=/tmp/opf-image-priceb-wp
OPF_PRICEB_E2E_ALLOW=1 OPF_PRICEB_OUT=/tmp/opf-lane-priceb-evidence \
  wp eval-file bin/probe-priceb-weight-gap.php --path=/tmp/opf-image-priceb-wp
cd /tmp/opf-url-native-parity && OPF_PRICEB_OUT=/tmp/opf-lane-priceb-evidence \
  node /tmp/opf-lane-priceb/bin/e2e-priceb-totals.mjs
```

## Artifacts (all under `/tmp/opf-lane-priceb-evidence/`)

- `priceb-lifecycle-matrix.json` — 18 cases / 90 legs
- `priceb-import-parity.json` — 5 import scenarios
- `priceb-matrix-parity.json` — 4 matched + 1 observed divergence
- `priceb-totals-display.json` — 7 totals scenarios + sign placement
- `priceb-totals-{wapf,opf}-{minus_q1,plus_charq_q3}.png` — rendered totals
- `priceb-weight-gap.json` — normalization + live weight gap
- `baseline.json` — pre/post clone counters

## Cleanup

All fixtures (products, OPF groups, drafted/restored group status, orders,
refunds, tax rate, cart, options) removed. Post-run counters equal baseline:
products 9, OPF groups 11 published / 5 draft, users 1, orders 250,
attachments 1, tax rates 0.

## Residual / not in scope

- `WAPF-PRICE-FORMULA-WEIGHT` / `WAPF-COMMERCE-WEIGHT`: weight engine is not
  implemented (out of scope, documented).
- Tax-inclusive/exempt customer-location paths for these preview totals remain
  covered by the display lane's separate 8091 evidence; this lane used tax-off
  previews to isolate the display math.
- WAPF 3.1.5 negative-sign and beyond-last lookup behavior are reference-side
  quirks; OPF intentionally follows `wc_price()` / fails closed.
