# Disabled choices: real checkout and comparator evidence

Verified 2026-10-01 against OPF base `4d8a5d8`, WordPress 7.1.2,
WooCommerce 11.1.0, PHP 8.5.11, SQLite, and WAPF Extended 3.1.5.
Worktree: `/tmp/opf-disabled-commerce-proof`; separate disposable clone:
`/tmp/opf-disabled-roundtrip-wp`; loopback: `http://127.0.0.1:8156`.
No production writes or product code changes in this evidence commit.
The clone suppresses outbound mail with `pre_wp_mail`.

## Proven commerce behavior

The Chromium script submits actual HTTP classic add-to-cart and
`?wc-ajax=checkout` requests using the rendered checkout nonce, and actual
`/wp-json/wc/store/v1/cart/add-item` and `/checkout` requests using the Store API
nonce. Both return successful checkout responses and durable on-hold orders.
Each order stores select `available-finish` and checkbox `available-extras`,
public labels, and `_opf_fields_snapshot`. Quantity two costs USD 23:
USD 20 base plus USD 2 and USD 1 flat choice fees. Unavailable choices priced
at USD 99 each contribute nothing. The Store API unit price is USD 11.50.
Chromium reports no page errors on the commerce paths.

Commands from the worktree:

```sh
OPF_DISABLED_LIFECYCLE_ALLOW=1 wp --path=/tmp/opf-disabled-roundtrip-wp eval-file bin/e2e-disabled-commerce-roundtrip.php
OPF_DISABLED_BROWSER_PHASE=commerce node bin/e2e-disabled-commerce-roundtrip.mjs
OPF_DISABLED_LIFECYCLE_ALLOW=1 OPF_DISABLED_LIFECYCLE_PHASE=commerce wp --path=/tmp/opf-disabled-roundtrip-wp eval-file bin/e2e-disabled-commerce-roundtrip.php
```

Setup requires `/tmp/opf-disabled-commerce-artifacts` and the clone MU hook
which suppresses mail and authenticates the fixture administrator only for
loopback `?disabled_proof_login=1`. That hook redirects to the fixture WAPF
edit screen; its user and group IDs come from `opf_disabled_lifecycle_state`.
The normal WooCommerce BACS gateway handles both checkouts.

## Comparator and explicit limits

`node bin/e2e-disabled-commerce-roundtrip.mjs` drives the actual installed
WAPF Tools Import button. WAPF reports import success and displays both fields,
but the admin displays: “Features are temporarily disabled” due to lack of a
valid license. The first attempts exported `fields: []` before save and sent
an empty `wapf-fields` value. A later attempt, after seeding the real model,
sent two imported fields in the actual POST, but reload and Tools export
still returned no fields and Chromium recorded three response JSON errors.
The displayed restriction and observed failures leave Tools fidelity unproven;
the exact cause of every failed response was not established.
This is **not** successful WAPF Tools import/save/export evidence. No license
settings, license logic, or installed WAPF code were modified.

Separately, the installed WAPF `raw_json_to_field_group`, `save`,
`get_by_id`, and `field_group_to_raw_fields_json` methods consume the actual
OPF Tools payload and persist/reload both select and checkboxes choices with
the exact disabled/available flags and choice fees. This runtime model
comparison supports data fidelity only, and does not replace the licensed
Tools UI acceptance condition.

```sh
wp --path=/tmp/opf-disabled-roundtrip-wp plugin activate advanced-product-fields-for-woocommerce-extended
OPF_DISABLED_LIFECYCLE_ALLOW=1 OPF_DISABLED_LIFECYCLE_PHASE=comparator-model wp --path=/tmp/opf-disabled-roundtrip-wp eval-file bin/e2e-disabled-commerce-roundtrip.php
OPF_DISABLED_LIFECYCLE_ALLOW=1 OPF_DISABLED_LIFECYCLE_PHASE=verify wp --path=/tmp/opf-disabled-roundtrip-wp eval-file bin/e2e-disabled-commerce-roundtrip.php
```

Installed comparator sources (same bytes as production source, read only):

- `includes/classes/class-field-groups.php:19` raw export; `:82` raw import;
  `:213` disabled flag normalization; `:583` reload; `:736` persistence.
  SHA-256: `a93eec77639cf7ba4bc23908927421dacf26490de141fda7734ed7560720a9f8`.
- `includes/controllers/class-admin-controller.php:1007` actual admin save;
  `:1042` form JSON decoding; `:1054` raw conversion; `:1063` model save.
  SHA-256: `c3006f34b63ff306a7d49c330e64f8f7fe56f998b90398327ccec6ab4f75ebac`.
- `views/admin/tools.php:30` export textarea; `:49` import textarea;
  `:57` actual Import button. The served `assets/js/admin.min.js` SHA-256 is
  `e77a542ff02edc9a5af2872fe6b6d824a368097d79c277727b306bbb96864aab`.

## Bugs found at the evidence baseline

Order-again restores exact selection values but loses the flat fees: USD 20
instead of USD 23 in both original checkout paths. The restored cart data
lacks `opf_base_price`; `CartIntegration::apply_prices` skips such lines.
The persisted-source importer also drops the WAPF `checkboxes` field;
the select survives with its flags and price. These failures are recorded
explicitly and require separate fixes and regression proof.

Broader accessibility, responsive comparison, refunds/restock, licensed WAPF
Tools UI fidelity, and commerce paths for the other multi-choice controls
are not proven by this lane. The capability must remain partial.
