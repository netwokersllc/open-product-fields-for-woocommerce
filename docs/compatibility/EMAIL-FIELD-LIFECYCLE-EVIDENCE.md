# Native email field lifecycle evidence

Executed 2026-10-02, 00:25 UTC, based on public
`d82d21e38a849199b3dae4817d099926e8bc5da8` plus this change.
Isolated source: `/tmp/opf-email-lifecycle-20261002`; disposable SQLite WordPress:
`/tmp/opf-email-woo-20261002`, served only at `http://127.0.0.1:8181`.
WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, Twenty Twenty-Five,
and actual Chromium/Playwright. Mail was intercepted with `pre_wp_mail`;
HTML/plain email bodies were generated without delivery.

## Source contract and fixes

Installed WAPF Free 1.7.1 source:

- `includes/classes/class-fields.php:272` registers email default, placeholder,
  and pricing options. `:464` sanitizes with `sanitize_email(trim($value))`;
  `:581` explicitly validates only required fields, without email format checks.
- `views/frontend/fields/email.php:1` renders a native single-address `type=email`.
- `includes/classes/class-field-groups.php:56` converts Tools JSON to WAPF models;
  `:115` imports the flattened placeholder into field options.
- [HTML email input contract](https://html.spec.whatwg.org/multipage/input.html#email-state-(type=email))
  supplies the native single-address grammar.

Source SHA256: `class-fields.php`
`92e2096f35e01e1c7036a780b4363a6285279b0d76e4cfcacca4224b7e05128b`;
`email.php`
`136864f841da3229b4424183822effe8e1717b13df1da59b4dfaa1ae37e45079`.

The old PHP `FILTER_VALIDATE_EMAIL` rejected native-valid single-label domains
and dotted local parts, and accepted quoted local parts rejected by Chromium.
OPF now uses the HTML grammar for the browser's canonical ASCII wire value.
Chromium transforms `a@bücher.test` to `a@xn--bcher-kva.test` before submission;
that value passes both commerce paths. Raw noncanonical Unicode domain payloads
remain invalid server input. This adds actual server format checking beyond
WAPF's browser-only checks.

Email transport retains invalid characters instead of stripping markup into a
valid-looking address. Required and optional forged arrays/objects fail format
validation. NUL bytes, including trailing NUL, remain invalid rather than being
trimmed away. Optional empty values remain allowed. The WAPF mapper now retains
email fields; previously it dropped the type as unsupported.

## Executed proof

- **38 browser checks:** authenticated actual admin REST save/reload of email
  type, label and required state; native validity matrix; accessible naming,
  keyboard focus, mobile fit; invalid native submission blocked; forged classic
  and Store API HTTP requests rejected with an empty cart; exact valid value and
  unchanged $10 cart price; actual classic shortcode checkout HTTP and actual
  Checkout Block Store API checkout; escaped confirmation DOM; no page errors.
- **63 real Woo checks:** persisted admin data; import/export type, required and
  placeholder roundtrip through the installed WAPF Free Tools converter;
  durable classic/block orders; exact structured/public item metadata; HTML and
  plain email content; restored order-again data and actual cart revalidation;
  seven native-valid formats through classic and Store API; required/optional
  scalar, markup, NUL and forged-shape rejection on both paths.
- **6 browser order-again checks:** both completed orders' actual Woo Order again
  links followed through account pages; real restored carts keep exact email
  and unchanged base price.
- Complete PHPUnit: **278 tests / 1095 assertions**, one existing metadata
  deprecation. Focused FieldValue/WapfMapper: **42 tests / 208 assertions**.
  Changed engine files lint on PHP 7.1. PHP 8.5/Node syntax and
  `git diff --check` pass.

Woo's shipped plain email template renders its formatted ampersand metadata as
`a&amp;b@example.test`; HTML renders the same address with proper escaping.
The structured and public stored values remain exactly `a&b@example.test`.
This proof records the actual core plain-text representation explicitly.
Admin, mobile and order screenshots are in `/tmp/opf-email-artifacts` and the
mobile screenshot was visually inspected.

## Reproduce

Activate this checkout on a dedicated loopback-only `/tmp` site. The disposable
login helper reads `opf_email_e2e_state` and signs in the fixture administrator;
it is not shipped. Intercept mail before browser checkout.

```sh
OPF_EMAIL_E2E_ALLOW=1 OPF_EMAIL_E2E_PHASE=setup wp --path=/tmp/opf-email-woo-20261002 eval-file bin/e2e-email-lifecycle.php
OPF_BASE_URL=http://127.0.0.1:8181 node bin/e2e-email-browser-test.mjs
OPF_EMAIL_E2E_ALLOW=1 OPF_EMAIL_E2E_PHASE=commerce wp --path=/tmp/opf-email-woo-20261002 eval-file bin/e2e-email-lifecycle.php
vendor/bin/phpunit
```

For actual order-again browser proof, set only the two fixture orders to completed
and assign their customer IDs to the fixture administrator, then run
`OPF_BASE_URL=http://127.0.0.1:8181 node bin/e2e-email-order-again.mjs`.
The committed raw JSON/text evidence contains current-source results and runtime
HTTP hashes for validator, mapper, cart integration and renderer.

## Acceptance boundary

This covers `WAPF-FIELD-EMAIL`'s native field, invalid-input errors and relevant
commerce lifecycle. Default-value editing/rendering is still absent and belongs
to the separate default-value row. Placeholder browser editing, rich pricing,
repeaters, licensed edition model/UI comparisons, custom email templates,
additional themes and the full supported-version matrix are not accepted by
this proof. Import/export evidence covers the tested field attributes and actual
Free Tools model converter, not the full global import/export capability.
No ledger or roadmap status is changed here. Production was untouched.
