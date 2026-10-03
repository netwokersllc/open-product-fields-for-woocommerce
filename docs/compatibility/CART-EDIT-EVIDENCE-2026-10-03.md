# Edit-in-cart lane evidence — WAPF-INTERACTION-CART-EDIT (2026-10-03)

Lane `lane/cartedit`, worktree `/tmp/opf-lane-cartedit`, clone
`/tmp/opf-image-cartedit-wp` (`http://127.0.0.1:8318`). Reference: WAPF Extended
3.1.5 (inactive at final state, per baseline).

## Outcome

Flipped the ledger row's `ZERO edit-cart code` gap to a working, browser-proven
implementation. OPF now mirrors WAPF 3.1.5's edit-in-cart flow:

- Opt-in setting `opf_edit_cart` (default `no`), WC → Settings → Product fields.
- Gate `CartEdit::can_edit_cart_item()` = setting on AND line carries
  `opf_fields` (the `wapf`/`_wapf_children` analogue).
- Cart-line `(edit)` link (`a.opf-edit-cartitem`) on classic
  (`woocommerce_after_cart_item_name`) and block (Store API `opf_edit.editLink`
  + `registerCheckoutFilters('opf-editlink')`) surfaces, with
  `disable_cart_edit_when_invisible` / edit-text filters.
- Edit permalink `?opf_edit=<cart_key>`; product form prefills stored values
  (text/select/radio/checkbox/toggle/date/image-quantity/products/repeats and
  section repeats), quantity, button text (`Update cart`), hidden `_opf_edit`.
- Submit = WAPF remove-then-add (`woocommerce_add_cart_item_data` priority 20):
  the named line is removed before `generate_cart_id`, so an identical submit
  re-merges onto the same key and a changed submit gets a new one. Redirect to
  cart + `Cart updated.` notice.
- Uploads retain their session-owned private tokens through hidden inputs;
  `Uploads::validate_tokens` re-checks owner/product/group/field on resubmit
  (fail closed). No re-minting, no URL rehydration (documented divergence from
  WAPF's extract-URL-from-HTML approach).

## Hardening beyond WAPF (fail closed)

- Forged/stale `_opf_edit` keys that name a non-OPF line are ignored, never
  destructive (WAPF removes whatever key `_wapf_edit` names).
- Linked child lines (`_opf_child`) and non-OPF lines never get a link.

## WAPF 3.1.5 source extraction (clone plugin)

- `Util::can_edit_cart_item()` / `can_edit_in_cart()` — `class-util.php:57-73`.
- Setting registration — `class-admin-controller.php:765`.
- Links + Store API `apf` extension + block JS — `class-product-controller.php:176-269`.
- Quantity prefill / button text / redirect / message — `class-product-controller.php:130-174`.
- Hidden `_wapf_edit` + prefill — `class-html.php:229-267`, `views/frontend/field-group.php`.
- Remove-then-add — `class-product-controller.php:501-504`.
- Section/repeat edit clones (`data-edit-cart`) — `views/frontend/repeater-button.php`,
  `includes/classes/class-helper.php:904`.
- Upload edit = `Helper::extract_upload_urls_from_html` + `addFromUrl` —
  `views/frontend/fields/file.php:30,117-147`.

## Verification summary

| Surface | Artifact | Result |
| --- | --- | --- |
| OPF unit (CartEdit + Renderer prefill) | `vendor/bin/phpunit` | 556 tests, 2289 assertions, 0 failures |
| OPF server lifecycle | `opf-server-results.json` | 20 checks, 0 failed |
| OPF real Chromium (classic + block + upload retain + section-repeat clone prefill + order-untouched) | `browser-results.json` + `ce-*.png` | 25 checks, 0 failed, 0 page errors |
| WAPF reference server | `wapf-reference-results.json` | 13 checks, 0 failed |
| WAPF reference Chromium | `wapf-browser-results.json` | 6 checks, 0 failed, 0 page errors |
| Cleanup to baseline | `opf-cleanup-results.json` | 8 checks, 0 failed |

### Browser-proven lifecycle (OPF, `browser-results.json`)

- Classic cart exposes `a.opf-edit-cartitem` with `opf_edit=<key>`.
- Clicking it prefills note/select/checkbox/radio/quantity/button-repeat rows,
  a repeating-section clone (row 2 rebuilt by JS from `data-opf-edit-rows`),
  and the retained upload file row; `_opf_edit` hidden input present; button
  says `Update cart`.
- Modifying and submitting replaces the original line (exactly one line; new
  values; upload retained), redirects to the cart.
- An order placed before the edit keeps `note=ORIG, finish=matte` afterwards.
- Block cart exposes the same edit link through `extensions.opf_edit.editLink`,
  prefills, and updates in place.

### Notes / not proven

- The classic surface uses a fixture `[woocommerce_cart]` page; the clone's
  real Cart page is the Woo Cart Block (used for the block proof).
- WAPF Extended's admin/settings screen is license-gated; the OPF settings
  checkbox is wired through the standard WC field API but is not browser-proofed.
- `products`-field prefill and section-repeat clone prefill are unit-proven
  (Renderer tests) but not separately browser-proven.
