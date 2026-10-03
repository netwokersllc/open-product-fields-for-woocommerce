# Display-lane parity evidence — OPF ↔ WAPF Extended 3.1.5

Worktree `/tmp/opf-lane-display` (branch `lane/display`), disposable clone
`http://127.0.0.1:8307` (`/tmp/opf-image-display-wp`). Fixture:
`bin/e2e-display-lifecycle.php` (PHP lifecycle) + `bin/e2e-display-storefront.mjs`
(Playwright). No commits; disposable clone restored to baseline afterwards.

## Browser proof results (Playwright 1.61.1, viewports 1280 + 390)

| Run | Checks | Result | Artifacts |
|---|---|---|---|
| OPF `three` + surfaces | 82/82 | PASS | opf-three.json, opf-storefront-three-{1280,390}.png, opf-{cart,checkout,order}.png |
| OPF `grand` | 66/66 | PASS | opf-grand.json, opf-storefront-grand-{1280,390}.png |
| OPF `hidden` | 64/64 | PASS | opf-hidden.json, opf-storefront-hidden-{1280,390}.png |
| WAPF `lines` + surfaces | 36/36 | PASS | wapf-lines.json, wapf-storefront-lines-{1280,390}.png, wapf-{cart,checkout,order}.png |
| WAPF `grand` | 20/20 | PASS | wapf-grand.json, wapf-storefront-grand-{1280,390}.png |
| WAPF `hide` | 18/18 | PASS | wapf-hide.json, wapf-storefront-hide-{1280,390}.png |

Proven in-browser for OPF: 3-row/grand/hidden totals; tooltip trigger inside
`.opf-field-label` opening on click+focus, closing on Escape+Enter, aria
wiring; scalar defaults (text/email/number/textarea/url/toggle/radio/select/
checkbox); placeholders; `label[for]`↔input id; `role="radiogroup"` +
`aria-labelledby`; `abbr.required`; `.label-below` geometric check
(label y > input y); price hints on field label, select option, choice label;
Store-API cart/checkout + order-received hide_cart/hide_checkout/hide_order.

Proven in-browser for WAPF (reference): `lines`/`grand`/`hide` summary modes;
`label-above` group class; `abbr.required`; `style="width:50%"` container;
`(+$N.NN)` hint format on fields + select options; cart item metadata with
`wapf-pricing-hint` spans; hide_* suppression on cart/checkout/order-received
(order view reached via WC's real email-verification gate).

## PHP lifecycle results (wp eval-file, per phase)

- setup: `ok no pre-existing fixture`, `ok fixture administrator created`,
  `ok fixtures created product=15084 group=15085 below=15086`
- rest: 20 ok incl. description_presentation=tooltip, scalar defaults,
  labels_position=below, width clamp ≥25, choice selected+pricing,
  manage_woocommerce gate, settings REST save/reload for summary modes +
  price-hint flag.
- commerce (OPF): 22 ok incl. classic + Store API add-to-cart, item_data
  labels/values, hide_cart/hide_checkout/hide_order suppression, mini-cart,
  order formatted meta + `_opf_fields` structured meta + order-again.
- wapf_setup/wapf_layout: WAPF product-level group `p_<pid>` + layout switches.
- wapf_commerce: 16 ok incl. `wapf` cart fields, hide flags stored per field,
  `wapf-pricing-hint` in cart display, `wapf_settings_show_in_cart=no` global
  suppression, order raw meta + formatted-meta hide_order + `_wapf_meta`.

## PHPUnit

`vendor/bin/phpunit` → `Tests: 373, Assertions: 1619, PHPUnit Deprecations: 1.`
`OK, but there were issues!` (deprecation notice only, all tests pass).
Focused: `CartIntegrationHideValuesTest` 18 tests / 60 assertions.

## Fixture environment notes

- mu-plugin `wp-content/mu-plugins/probe-hooks.php` (leftover debug probe from
  a previous run) fataled when OPF was inactive — patched to guard its
  `OPF\Service\FieldGroups::all()` call with `class_exists()`. Disposable
  clone only; not a repo change.
- `wp server` router 404s `?page_id=` — browser walks `/cart/` + `/checkout/`
  permalinks.
- Block add-to-cart form posts via Store API and drops `wapf[]`/`opf[]`
  inputs; the proof submits the classic form POST (hidden `add-to-cart`
  injected, identical payload to a button click).
- WC 11 gates guest order views behind email verification after a 10-minute
  grace period — the proof submits the billing email like a shopper rather
  than disabling the gate.
- WAPF unserialize warnings on the clone's pre-existing (malformed/
  double-serialized) `_wapf_fieldgroup` rows — WAPF skips them; not an OPF
  failure.
- Baseline `wapf_posts` counted 0 only because WAPF was inactive at capture
  (unregistered CPT); the 746 `wapf_product` posts are pre-existing clone
  data dated 2026-09-12, not fixture output.

## Baseline restoration (verified post-cleanup)

products=5, opf_groups=7, users=1, wc_orders=250, attachments=1,
active_plugins=[opf, sqlite, woocommerce] (WAPF deactivated),
`opf_price_summary_mode=three` (pre-existing), all other probed options
ABSENT, fixture product/groups/user/orders deleted, state file removed,
`?p=15084` → 404.
