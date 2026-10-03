# URL field cart visual proof — 2026-10-03

Real served check of whether submitted URL values visibly reach the WooCommerce
classic cart and the Store API cart block. Run on a disposable SQLite
WordPress clone at `http://127.0.0.1:8251` (WordPress 7.1.2, WooCommerce
11.1.0, PHP 8.5.11, Chromium headless) with the OPF lane checkout and the
installed WAPF Free 1.7.1 compared on the same product fixture. Production was
not used.

This run resolves the caveat left open in the
[native URL parity follow-up](URL-FIELD-NATIVE-PARITY.md): after a direct
Store API add the disposable clone's cart block had rendered empty there, so
the earlier proof asserted only the authoritative Store API response.

## Result

**OPF passes 36/36 visible-cart checks; WAPF Free passes 16/16 on its own
native submission path.**

- `opf` engine, native form submit (4 URL values): **16/16 checks** —
  [results](url-cart-visual-20261003/opf-results.json),
  [classic screenshot](url-cart-visual-20261003/opf-classic.png),
  [block screenshot](url-cart-visual-20261003/opf-block.png).
- `opf` engine, **direct Store API `add-item` with `opf_fields`** (same 4
  values): **20/20 checks** — the cart block visibly renders the one item and
  its `Profile URL` label after every direct API add.
  [results](url-cart-visual-20261003/opf-store-guest-results.json),
  [classic screenshot](url-cart-visual-20261003/opf-store-guest-classic.png),
  [block screenshot](url-cart-visual-20261003/opf-store-guest-block.png).
- `free` engine (installed WAPF Free 1.7.1), native form submit: **16/16
  checks** — identical visible classic/block cart behavior to OPF.
  [results](url-cart-visual-20261003/free-form-guest-results.json),
  [classic screenshot](url-cart-visual-20261003/free-form-guest-classic.png),
  [block screenshot](url-cart-visual-20261003/free-form-guest-block.png).

Tested values: `https://例え.テスト/こんにちは?q=✓` (Unicode IDN + query),
`https://example.invalid/profile?a=1&b=2` (escaped query), `mailto:ada@example.invalid`,
and `https:example.invalid` (scheme-relative). Each was checked for native
input validity, single real cart item creation, visible classic-cart label,
and visible block-cart row + label.

## Scope notes

- The `free` engine has no direct Store API field-add path: installed WAPF
  Free 1.7.1 contains no `woocommerce_store_api_*` integration, so its
  store-submit run fails on its own absence of that API — not an OPF
  divergence. `opf_fields` Store API capture is an OPF superset; its
  [free store-submit results](url-cart-visual-20261003/free-store-guest-results.json)
  are kept as the control record.
- Both engines emit identical cosmetic `404` requests for
  `.../undefinedwc/store/v1/cart` while the block cart hydrates — a clone
  environment quirk, not a plugin divergence (present in both runs).
- Visual coverage is the classic `[woocommerce_cart]` page plus the block
  cart page on the default theme, desktop viewport; authenticated checkout,
  order-again visual rendering, and non-tested URL/protocol cases remain in
  the row's other open items.

## Reproduction and cleanup

Guarded fixture: `bin/e2e-url-cart-visual.php` refuses to run outside
`/tmp/opf-url-cart-*` SQLite clones; `bin/e2e-url-cart-visual-mu.php` provides
loopback-only fixture login and stays inert elsewhere.

```sh
OPF_URL_CART_ALLOW=1 OPF_URL_CART_OUT=/tmp/opf-url-cart-artifacts \
  wp --path=/tmp/opf-url-cart-woo eval-file bin/e2e-url-cart-visual.php
wp --path=/tmp/opf-url-cart-woo server --host=127.0.0.1 --port=8251
OPF_URL_CART_ENGINE=opf OPF_URL_CART_SUBMIT=store \
  OPF_URL_CART_OUT=/tmp/opf-url-cart-artifacts node bin/e2e-url-cart-visual.mjs
OPF_URL_CART_ALLOW=1 OPF_URL_CART_PHASE=cleanup \
  OPF_URL_CART_OUT=/tmp/opf-url-cart-artifacts \
  wp --path=/tmp/opf-url-cart-woo eval-file bin/e2e-url-cart-visual.php
```

The fixture drafts every other published field group for isolation and
restores their statuses at cleanup; the product, pages, user, groups, and
orders are removed and `active_plugins` is restored.
[cleanup verification](url-cart-visual-20261003/cleanup.json) shows the clone
back at its 1027-post baseline with the original plugin set.
