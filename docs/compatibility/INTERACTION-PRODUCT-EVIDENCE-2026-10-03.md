# Interact lane evidence — interaction + product-type rows

Clone: `http://127.0.0.1:8315` (`/tmp/opf-image-interact-wp`, WooCommerce 11.1.0, SQLite driver)
Worktree: `/tmp/opf-lane-interact` @ `lane/interact` (HEAD `b174200`, feat/opf-archive-import lineage)
Reference: WAPF Extended 3.1.5 (inactive at baseline; activated only for `verify-wapf`, then deactivated + `wapf_db_version` removed)
Fixture: guarded WP-CLI lifecycle `bin/e2e-interact-lifecycle.php` (phases setup/verify/verify-wapf/cleanup) + real-Chromium proof `bin/e2e-interact-browser.mjs` (Playwright)

## Machine-readable results

- `server-results.json` — 39 server-side checks, **0 failed** (OPF active, WAPF inactive)
- `server-results-wapf.json` — 6 WAPF-reference checks, **0 failed** (WAPF active, `wapf_edit_cart=yes` pre-set for the memoized gate)
- `browser-results.json` — 23 real-browser checks, **0 failed, 0 page errors**
- `browser-order.json` — real classic-checkout order id placed by the browser run (bacs)
- Screenshots: `repeat-form.png`, `repeat-cart.png`, `repeat-order-received.png`, `image-swap.png`, `variable-cart.png`
- PHPUnit: full suite **440 tests / 1854 assertions, 0 failures** (1 pre-existing PHPUnit deprecation)

## WAPF-INTERACTION-REPEAT — proven

Server (39/39): button-mode payload validates; cart line values keep per-row arrays (`attendee:[Ada,Grace]`, `seat:[front,back]`, `meal_note`, `note_fee`); per-unit price = base 20 + attendee 2×2 + seats 5+0 + clone-scoped `len([field.meal_note])` 3+1 + ticket 1 = **34.00**; visible selections carry numbered labels (`Guest 2`, `Extra 2 - Seat`); real order meta persists `_opf_fields` + display labels + `_opf_fields_snapshot`; order-again `restore_order_again` restores both split lines; server-side max-clone and required-section-child-per-clone enforced.

Browser (23/23): "Add guest" produces indexed `opf[gid][attendee][1]` with rewritten label "Guest 2"; "Add extra" clones the whole section (`seat[1]` radios renumbered); add-then-remove cycle works (3 → 2 instances); classic cart/Store API shows the numbered clone labels; real checkout produced order 15910 with two unit lines at 34.00 and full clone meta.

## WAPF-INTERACTION-QUANTITY-REPEAT — proven

Server: `split_quantity_repeat_cart_item` produced **2 unit lines** from qty=2 (`ticket:["Ada"]` / `ticket:["Grace"]`), row-count mismatch rejected (`ticket` 3 rows vs qty 2).
Browser: qty=2 auto-cloned a second `ticket` row; Store API cart = 2 items qty 1, each priced 34.00; order meta keeps per-line ticket holder (Ada on line 1, Grace on line 2).

## WAPF-INTERACTION-IMAGE-CHANGE — proven for OPF's surface, parity note documented

OPF implements image switching only via the linked-products field (`data-opf-swap-image`). Browser: selecting child A swaps `.wp-post-image` src to `opf-ix-child-a.png`; with A+B checked the **first checked in DOM order wins** (A stays); unchecking A falls through to B; unchecking all restores the original `opf-ix-main.png` exactly (src/srcset/sizes/data-large_image/link href restored, `woocommerce_gallery_init_zoom` re-triggered).

Gap vs WAPF: WAPF's per-field "gallery images" engine (`enable_gallery_images`, `gallery_images`, `swap_type` `last`/`rules`) lets arbitrary choice fields drive gallery swaps with per-value image mapping. OPF exposes this only through product-linked fields. Behavioural parity holds for the surface OPF ships; the generic rules/last engine is absent — documented, not claimed.

## WAPF-INTERACTION-CART-EDIT — gap (OPF has no feature)

- OPF source: zero `edit_cart`/`cart_edit`/`_opf_edit`/`_edit` handling in `includes/`; no `woocommerce_cart_item_permalink` or cart-item edit link hooks; `includes/Service/Admin/Settings.php` has no such setting. Browser: rendered cart exposes no per-item edit links.
- WAPF reference (6/6 checks): `Util::can_edit_cart_item` returns true only with `wapf_edit_cart=yes` **and** `wapf`/`_wapf_children` cart data (static memoization documented in the check).
- Prior summary text claimed a partial OPF edit-cart proof ("signed item permalink, replaces cart row") — **that code does not exist in this tree**; verified by source search. Treat the row as an open gap.

## WAPF-PRODUCT-VARIABLE — lifecycle proven for parent-level groups; variation targeting is a gap

Server + browser: Blue variation resolves sale price 12 (browser shows "$15.00 → $12.00"); parent-targeted group renders on variable product and matches through the variation (`for_product` resolves parent id); `found_variation` swaps `opf_base_price`/`formula_base_price` to the variation price (WoocsIntegration supplies `opf_base_price` on `woocommerce_available_variation`, fallback `display_price`); `reset_data` restores originals; engraving + gift-wrap priced to 15.00 per unit; order meta keeps `ixcolor=Blue` + field values; required-field validation enforced on variation adds; browser "Clear" resets selection.

Gap: **no `product_var` rule subject**. Probe group with `product in [blue_variation_id]` matched nothing on blue, red, or parent — OPF resolves every rule against the parent id (`FieldGroups::for_product` line ~211). WAPF's `is_product_variation` matches a variation id directly and via its variable parent's children (`class-conditions.php:255`). Per-variation group markup fetch does not exist in this tree.

## WAPF-PRODUCT-SUBSCRIPTION — simulated parity proven; renewal-skip gap documented

Real WooCommerce Subscriptions is not installed, so the fixture installs a scoped mu-plugin (`zz-opf-ix-types.php`, removed at cleanup) defining `WC_Subscriptions`, `WC_Subscriptions_Product::get_price`, and `subscription`/`booking` product types via `woocommerce_product_class` (lazy class declaration after WooCommerce loads).

WAPF reference (with the stub present): integration class loads; `wapf/admin/allowed_product_types` gains `subscription` + `variable-subscription`; cart base resolves through `WC_Subscriptions_Product::get_price` (30); `wcs_before/after_renewal_setup_cart_subscriptions` toggles `wapf/skip_cart_validation` true→false.

OPF: `subscription` type resolves through the WC factory; `product_type=subscription` + `product` rules match; validated add attaches field data; **a fieldless renewal-style add is blocked by required-field validation** — OPF has no renewal-skip equivalent (gap documented; WAPF's skip exists only inside the renewal-setup hooks).

## WAPF-PRODUCT-BOOKINGS — proven equal to WAPF 3.1.5 runtime (both dormant)

WAPF ships `class-woocommerce-bookings.php` but its integrations controller never registers it — verified by source (`class-integrations-controller.php` omits `WooCommerce_Bookings`) and at runtime (`class_exists(..., false)` = false under WAPF-active). OPF: `booking` type resolves through the factory; `product_type=booking` rule matches; validated add attaches field data. Neither plugin has a live bookings integration — parity at the level both runtimes actually provide.

## Cleanup / baseline

- `cleanup` phase deleted all fixture posts (7 products + 2 variations + 5 groups + checkout page), 3 generated attachments + uploaded PNGs, the fixture customer (id 87), the state option, the mu-plugin, and all orders touching fixture products (incl. browser-placed 15909/15910).
- Post-verification counts: products 12→5, attachments 4→1, opf_groups 13→8, orders 252→250, `opf-ix-*` slugs 0, `opf_ix_customer` gone, `opf_ix_e2e_state` gone, `wapf_*` options 0, mu-plugins back to the 2 pre-existing debug files, 0 `opf-ix-*.png` files in uploads.
- Pre-existing residue not created by this lane (group 15057's global group, `dbg-render.php`, `probe-hooks.php`, 8 leftover opf_groups, 6 products) was left untouched.

## Fixture bugs fixed during the run (not OPF defects)

1. mu-plugin declared `WC_Product_*` subclasses eagerly — mu-plugins load before WooCommerce → site fatal; fixed with lazy declaration on `plugins_loaded` + `eval()`.
2. `imagestring()` unavailable → switched to `imagecreatetruecolor`/`imagefilledrectangle`/`imagepng`.
3. Formula `len(field.meal_note)` treated as literal (len 15) → corrected to bracketed `len([field.meal_note])` per `Calculator::evaluate_formula` token syntax.
4. Order-item meta staged via `add_meta_data` is discarded by `WC_Order::save()` re-reading items → persist after the order save, then `$line->save()`, then read back via `wc_get_order()` fresh object.
5. `$_POST['opf']` not unset before the fieldless renewal check → added `unset`.
6. `global $results` inside eval'd file scope never reached file-level writes → switched to `$GLOBALS['ix_results']`.
7. `class_exists()` autoloaded the dormant WAPF bookings class → `class_exists(..., false)`.
8. WAPF `can_edit_in_cart()` memoizes statically → enabled state pre-set via WP-CLI before the phase.
9. Browser-test expectations corrected: seat clone produces 3 inputs named `seat[1]` (hidden + 2 radios); product-swap picks first checked in DOM order.

## Uncommitted worktree changes

- `bin/e2e-interact-lifecycle.php` (new)
- `bin/e2e-interact-browser.mjs` (new)

No commits, no pushes, no shared-doc edits.
