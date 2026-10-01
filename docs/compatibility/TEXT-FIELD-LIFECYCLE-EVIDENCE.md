# Native single-line text lifecycle evidence

Executed on 2026-10-01, with the final commerce run ending at 18:09:39 UTC.
Source baseline: `aa6f8b44b21c8dfd8387ca82f30761cff0f7736b`, plus this change.
The isolated checkout is `/tmp/opf-text-field-lifecycle`; the disposable SQLite
WordPress site is `/tmp/opf-text-woo`, served at `http://127.0.0.1:8124`.
WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, real Chromium via Playwright,
and Twenty Twenty-Five were used. Active plugins were WooCommerce, SQLite
integration, and this checkout through the `opf-text` link. WAPF was inactive.
Outgoing mail was intercepted with `pre_wp_mail`; email bodies were generated
and inspected without delivery. Production files and data were not used.
Cleanup at 18:12 UTC removed the fixture product/group/user/state and all
orders containing the fixture product; Woo's data store reported zero remaining
order items for that product. The screenshots and raw proof output were kept.

WAPF Free 1.7.1's installed `class-fields.php` registers `default` and
`placeholder` for text, `class-html.php` supplies its default field value, and
`views/frontend/fields/text.php` renders a single-line input. Inspected hashes:

```text
includes/classes/class-fields.php 92e2096f35e01e1c7036a780b4363a6285279b0d76e4cfcacca4224b7e05128b
views/frontend/fields/text.php    c0e3b01ee0d2d8f1e96d4e5f68abdca2fdb7ee3578de3f95071c86365996c502
```

## Changes and executed acceptance

Native text now stores a scalar single-line `default`, renders it escaped,
seeds conditional evaluation with it, and offers default/placeholder controls
in the builder. Non-repeated text uses the configured default when that input
is omitted from the submitted group; an explicitly empty input does not regain
its default. Existing fields without defaults retain their stored shape.
The admin Save button explicitly uses `type="button"`, avoiding a simultaneous
WordPress outer-form submission that was observed in the real admin page.

The guarded [commerce proof](../../bin/e2e-text-lifecycle.php) passed 23 checks.
It reloads the actual browser checkout order from Woo's data store, verifies
the saved admin label/required/placeholder/default settings, rejects classic
empty and array submissions and an explicitly cleared required default, and
captures a sanitized single-line value with an omitted default and no optional
value. Store API add-item rejects empty required text with the cart still
empty, accepts valid text and an explicit default override, and includes both
in its returned cart display data. Actual Store API checkout creates an order
with structured `_opf_fields`, public display metadata, and the unchanged
base line total. HTML and plain on-hold email bodies contain the labels and
values. Woo's order-again filter and a real cart add preserve those selections.

The [browser proof](../../bin/e2e-text-browser-test.mjs) passed 17 checks with
no uncaught page errors. It uses the actual authenticated WordPress admin page
and REST save, reloads persisted settings, verifies required/default/optional
storefront inputs, observes browser constraint blocking, sends a forged empty
classic HTTP submission and verifies server rejection with an empty cart,
then submits valid text through the classic product form. The real block cart,
block checkout, Store API place-order response, and order confirmation display
the text and default. It uses no fetch mocks or synthetic HTML.

PHPUnit passed 217 tests / 807 assertions; the suite reported one existing
PHPUnit metadata deprecation. The focused default tests passed 3 tests /
4 assertions for Unicode/single-line sanitation, numeric zero, legacy shape,
and complex-default rejection. PHP/Node syntax and `git diff --check` passed.

This accepts the native `WAPF-FIELD-TEXT` baseline lifecycle. Text length/regex,
WAPF import/export default mapping, repeated text, and other themes/platform
floors remain governed by their own ledger rows; those are not accepted by
this evidence.

## Reproduction and artifacts

Use a dedicated disposable `/tmp` WordPress/WooCommerce site with OPF active,
no unrelated published field groups matching the fixture product, a loopback
server, and mail interception. The browser needs an authenticated fixture
administrator. This run used a clone-only loopback login helper that reads
`opf_text_e2e_state` and sets that user's WordPress cookie; it is not shipped.

```sh
OPF_TEXT_E2E_ALLOW=1 OPF_TEXT_E2E_PHASE=setup wp --path=/tmp/opf-text-woo eval-file bin/e2e-text-lifecycle.php
OPF_BASE_URL=http://127.0.0.1:8124 node bin/e2e-text-browser-test.mjs
OPF_TEXT_E2E_ALLOW=1 OPF_TEXT_E2E_PHASE=commerce wp --path=/tmp/opf-text-woo eval-file bin/e2e-text-lifecycle.php
vendor/bin/phpunit
OPF_TEXT_E2E_ALLOW=1 OPF_TEXT_E2E_PHASE=cleanup wp --path=/tmp/opf-text-woo eval-file bin/e2e-text-lifecycle.php
```

Raw browser checks and commerce output are retained alongside this document
in `text-field-browser-results.json` and `text-field-commerce-results.txt`.
Full screenshots remain in `/tmp/opf-text-artifacts`; admin reload and cart
screenshots were visually inspected. Their SHA-256 values are:

```text
admin-reload.png     ef42b46f00d1e4ecc563cfc1964af3b3b1eeeccce8e4d6ddfa81c1f7edb99df4
checkout.png         d42e252e829ed3b6de1ed639694eebdf357a1bd32e1d5547d90df223bc13ce1a
classic-cart.png     673b47494ea1de3bf58804923ea60165f6309ce382f00b5633dd983a7e82bfe0
order-received.png   b4f5f6cded5d8058ccc78838e5a4b956d13a9bee27e93c72c61307e82f8f0f26
required-invalid.png 011246230e4b1629f1da6b03be960e21c430bbe4ad7dff0e387355dd42cd34f7
```
