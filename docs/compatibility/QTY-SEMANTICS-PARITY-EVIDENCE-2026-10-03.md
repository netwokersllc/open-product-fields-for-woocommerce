# Quantity-semantics parity evidence (lane/qtysem)

Proof that OPF pricing now matches WAPF Extended 3.1.5 quantity semantics
for every mapped pricing type, at cart quantities 1 and 3, through cart unit
prices, cart line totals, order totals and persisted order line values.

## Environment

- Disposable clone: `/tmp/opf-image-qtysem-wp` (http://127.0.0.1:8311)
- WAPF Extended 3.1.5 (`advanced-product-fields-for-woocommerce-extended`)
- WooCommerce 11.1.0, PHP 8.5.11, OPF worktree `lane/qtysem` @ `acbbfe9` base
- OPF plugin symlinked into the clone (live worktree code)
- Fixture: `bin/e2e-qtysem-parity.php` (guarded: `OPF_QTYSEM_E2E_ALLOW=1`
  + `ABSPATH == /tmp/opf-image-qtysem-wp` + WAPF 3.1.5 version check)
- Machine-readable results: `qtysem-parity-matrix.json` (same directory)

## WAPF 3.1.5 quantity semantics (extracted)

`Fields::do_pricing()` (`includes/classes/class-fields.php:280-314`) returns a
per-unit `calc_price`; `Cart::calculate_cart_item_prices()`
(`class-cart.php:21-83`) sums them into `options_total` and the product
controller sets the cart unit price to `base + options_total`. WooCommerce
multiplies by the line quantity afterwards — so OPF must NOT multiply addon
contributions by the line quantity again.

| WAPF type | normal field | qty_based (clone_type=qty) |
|---|---|---|
| fixed (default) | amount / qty | amount |
| qt | amount | amount * qty |
| p | (base * amount/100) / qty | base * amount/100 |
| percent | base * amount/100 | (base * amount/100) * qty |
| fx | eval(formula) / qty | eval(formula) |
| nr | (val * amount) / qty | val * amount |
| nrq | val * amount | val * amount |
| char | (len(val) * amount) / qty | len(val) * amount |
| charq | len(val) * amount | len(val) * amount |

`$val` is the cart-field value label: the submitted text for text/number
fields, the choice label for choice fields, and the entered per-choice count
for `image-swatch-qty` (`class-fields.php:139`, `class-cart.php:57`).

OPF normalized equivalent: `per_unit=false` means "flat per line"
(result/qty on normal fields); `per_unit=true` means "per unit". On
qty_based (OPF `repeat.mode=quantity`) the rows swap: false → result,
true → result * qty.

## What changed

1. `includes/Engine/Calculator.php`
   - `choice_addon()` gained a `$val` parameter (WAPF `$v`) plumbed into
     formula evaluation.
   - New `formula_is_verbatim_wapf_expression()`: a stored formula that still
     contains `[qty]` and has no normalized `formula_raw` (or whose
     `formula_raw` reduces to the stored formula after
     `[options_total]` → `[addons]`) is priced with the WAPF fx row —
     `result/qty` normal, `result` qty_based — so `per_unit` never
     re-multiplies an expression that already consumed `[qty]`.
   - `image_quantity` choices now pass the entered count as `$val` instead of
     multiplying the choice addon by it (WAPF `image-swatch-qty` semantics:
     the pricing type decides whether the count matters — `fixed`/`qt`
     ignore it, `nr`/`nrq`/`[x]` formulas consume it).
   - Select/radio/checkbox/swatch choices pass the choice label as `$val`.
   - `len()` resolves a bare `[x]`/`[val]` argument to the submitted value
     instead of measuring the numeric placeholder.
   - `field_pricing_addon()` gained the same verbatim-fx carve-out.
2. `includes/Service/CartIntegration.php`
   - `split_quantity_repeat_cart_item()` now returns early when the cart
     item carries no OPF values. Previously a foreign item (e.g. a WAPF
     item on a product that also has an OPF repeat group) produced a
     synthetic clone group of N identical empty rows and rewrote the item
     quantity back to the submitted count — undoing WAPF's own qty-clone
     split and inflating the cart (observed 4 units for a qty-3 add).
3. `assets/js/opf-frontend.js` (shared browser mirror)
   - `verbatimWapfFx()` mirror + the same `$val` plumbing and `len()` fix,
     keeping browser totals identical to server-side pricing.

## Live parity matrix — OPF vs WAPF on the same products

Each case ran both engines on one disposable product (base $10): WAPF via
`$_REQUEST['wapf']` + `_wapf_fieldgroup` post meta, OPF via `$_POST['opf']`
+ a published field group. Cart lines captured after each engine's own
repricing; orders built through `woocommerce_checkout_create_order_line_item`
and read back from fresh `WC_Order_Item_Product` objects. All 46 legs match.

| Case | Qty | WAPF unit | WAPF line | OPF unit | OPF line | Order (both) |
|---|---|---|---|---|---|---|
| fixed (fixed) | 1 | q1x15 | 15 | q1x15 | 15 | 15 |
| fixed (fixed) | 3 | q3x11.6667 | 35 | q3x11.6667 | 35 | 35 |
| qt (qt) | 1 | q1x15 | 15 | q1x15 | 15 | 15 |
| qt (qt) | 3 | q3x15 | 45 | q3x15 | 45 | 45 |
| p (p) | 1 | q1x12 | 12 | q1x12 | 12 | 12 |
| p (p) | 3 | q3x10.6667 | 32 | q3x10.6667 | 32 | 32 |
| percent (percent) | 1 | q1x12 | 12 | q1x12 | 12 | 12 |
| percent (percent) | 3 | q3x12 | 36 | q3x12 | 36 | 36 |
| fx_qty (fx) | 1 | q1x12 | 12 | q1x12 | 12 | 12 |
| fx_qty (fx) | 3 | q3x12 | 36 | q3x12 | 36 | 36 |
| fx_mapped (fx) | 1 | q1x15 | 15 | q1x15 | 15 | 15 |
| fx_mapped (fx) | 3 | q3x15 | 45 | q3x15 | 45 | 45 |
| fx_flat (fx) | 1 | q1x17 | 17 | q1x17 | 17 | 17 |
| fx_flat (fx) | 3 | q3x12.3333 | 37 | q3x12.3333 | 37 | 37 |
| nr (nr) | 1 | q1x18 | 18 | q1x18 | 18 | 18 |
| nr (nr) | 3 | q3x12.6667 | 38 | q3x12.6667 | 38 | 38 |
| nrq (nrq) | 1 | q1x18 | 18 | q1x18 | 18 | 18 |
| nrq (nrq) | 3 | q3x18 | 54 | q3x18 | 54 | 54 |
| char (char) | 1 | q1x12 | 12 | q1x12 | 12 | 12 |
| char (char) | 3 | q3x10.6667 | 32 | q3x10.6667 | 32 | 32 |
| charq (charq) | 1 | q1x12 | 12 | q1x12 | 12 | 12 |
| charq (charq) | 3 | q3x12 | 36 | q3x12 | 36 | 36 |
| img_fixed (fixed) | 1 | q1x16 | 16 | q1x16 | 16 | 16 |
| img_fixed (fixed) | 3 | q3x12 | 36 | q3x12 | 36 | 36 |
| img_qt (qt) | 1 | q1x16 | 16 | q1x16 | 16 | 16 |
| img_qt (qt) | 3 | q3x16 | 48 | q3x16 | 48 | 48 |
| img_nr (nr) | 1 | q1x20 | 20 | q1x20 | 20 | 20 |
| img_nr (nr) | 3 | q3x13.3333 | 40 | q3x13.3333 | 40 | 40 |
| img_nrq (nrq) | 1 | q1x20 | 20 | q1x20 | 20 | 20 |
| img_nrq (nrq) | 3 | q3x20 | 60 | q3x20 | 60 | 60 |
| qb_fixed (fixed) | 1 | q1x15 | 15 | q1x15 | 15 | 15 |
| qb_fixed (fixed) | 3 | q3x15 | 45 | q3x15 | 45 | 45 |
| qb_qt (qt) | 1 | q1x15 | 15 | q1x15 | 15 | 15 |
| qb_qt (qt) | 3 | q3x25 | 75 | q3x25 | 75 | 75 |
| qb_p (p) | 1 | q1x12 | 12 | q1x12 | 12 | 12 |
| qb_p (p) | 3 | q3x12 | 36 | q3x12 | 36 | 36 |
| qb_percent (percent) | 1 | q1x12 | 12 | q1x12 | 12 | 12 |
| qb_percent (percent) | 3 | q3x16 | 48 | q3x16 | 48 | 48 |
| qb_mixed_fixed (fixed) | 1 | q1x15 | 15 | q1x15 | 15 | 15 |
| qb_mixed_fixed (fixed) | 3 | q1x18; q2x15 | 18; 30 | q1x18; q2x15 | 18; 30 | 48 |
| qb_mixed_qt (qt) | 1 | q1x15 | 15 | q1x15 | 15 | 15 |
| qb_mixed_qt (qt) | 3 | q1x18; q2x20 | 18; 40 | q1x18; q2x20 | 18; 40 | 58 |
| qb_fx (fx) | 1 | q1x12 | 12 | q1x12 | 12 | 12 |
| qb_fx (fx) | 3 | q3x16 | 48 | q3x16 | 48 | 48 |
| img_sumqty (sumQty) | 1 | q1x15 | 15 | q1x15 | 15 | 15 |
| img_sumqty (sumQty) | 3 | q3x15 | 45 | q3x15 | 45 | 45 |

`qb_qt`/`qb_mixed_qt` reproduce WAPF's literal `amount*qty` per-unit pricing
on qty clones (the M-squared line-total quirk) — parity, not a bug to fix.
`img_*` rows prove the entered image count is the pricing value (`$v`):
`img_fixed`/`img_qt` ignore it, `img_nr`/`img_nrq` consume it.

## Order-line persistence

Every order line carried engine-native metadata: `_wapf_meta` for WAPF legs,
`_opf_fields` + visible `Label: value` meta for OPF legs (see
`qtysem-parity-matrix.json` → `order.lines[].has_wapf`/`has_opf`/`meta`).

## Verification runs

- `vendor/bin/phpunit` — 427 tests, 1809 assertions, all passing
  (1 pre-existing PHPUnit doc-comment deprecation in
  `CapabilityFixtureRegistryTest`, unrelated).
- `node --test tests/js/*.cjs` — 30/31 pass; the single failure is a
  pre-existing stale DOM mock in `opf-builder-auth.test.cjs`
  (`opf-builder.js` untouched by this lane).
- `OPF_IMAGE_QUANTITY_E2E_ALLOW=1 wp eval-file
  bin/e2e-image-quantity-sumqty-test.php` — passes on the clone:
  canonical cart unit 20.00, two-unit order 40.00, boundaries 15.00/23.00.
- `OPF_QTYSEM_E2E_ALLOW=1 OPF_QTYSEM_OUT=/tmp/opf-lane-qtysem-evidence
  wp eval-file bin/e2e-qtysem-parity.php` — 23 cases × 2 quantities, zero
  mismatches, fixtures cleaned.

## Cleanup

The parity fixture deleted all 23 disposable products, 23 OPF groups and all
orders it created; cart verified empty; `opf_admin_only` and
`woocommerce_calc_taxes` restored; WAPF deactivated back to the clone's
original state. No fixture residue (`post_title LIKE '%qtysem parity%'` = 0,
orphan `_wapf_fieldgroup` meta = 0).
