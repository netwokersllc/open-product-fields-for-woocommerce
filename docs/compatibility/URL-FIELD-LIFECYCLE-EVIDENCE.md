# Native URL field lifecycle evidence

Executed on 2026-10-01, final commerce proof completed at 18:30 UTC.
Base: `4d9c6e20458933df09a1aef33964c3125aae8cfa`, plus this change.
Isolated worktree: `/tmp/opf-url-parity`; disposable SQLite WordPress clone:
`/tmp/opf-url-woo`, served only at `http://127.0.0.1:8126`.
WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, Twenty Twenty-Five,
and real Chromium through Playwright were used. Active plugins were
WooCommerce, SQLite integration, and this checkout through the `opf-url` link.
WAPF Free was installed for source inspection and inactive during OPF runtime
proof. Mail was intercepted with `pre_wp_mail`; HTML/plain email bodies were
generated without delivery. Production and protected browser tabs were unused.

## Installed WAPF Free 1.7.1 contract

The installed plugin reports version 1.7.1. Its source matches the extracted
`/tmp/wapf-free-1.7.1/advanced-product-fields-for-woocommerce` files inspected:

* `includes/classes/class-fields.php:294`: URL registers `default`,
  `placeholder`, and pricing options.
* `views/frontend/fields/url.php:6`: native `type="url"` with
  `esc_attr( $model['field_value'] )` and shared required/placeholder attributes.
* `includes/classes/class-fields.php:467`: URL falls through to
  `sanitize_text_field(trim($value))` in `sanitize_value()`.
* `includes/classes/class-fields.php:581`: `is_field_value_valid()` explicitly
  checks required fields only; it supplies no URL format or protocol validation.

SHA-256:

```text
class-fields.php 92e2096f35e01e1c7036a780b4363a6285279b0d76e4cfcacca4224b7e05128b
url.php          c7de9bddbbdf7ec093aa24e11ce8feece19e64ffe3f1f0f8211ce6dc97a9730c
```

## Implementation and documented validation difference

OPF now exposes URL default/placeholder controls in its existing builder,
validates and trims scalar defaults, renders the default with attribute
escaping, and seeds conditional evaluation with it. An omitted non-repeated
URL input within a submitted payload uses its configured default; an explicitly
empty required input fails validation. Optional empty inputs remain absent
from captured selections. Legacy URL fields without a default retain their shape.

The URL transport preserves malformed nonempty scalar input for server
validation. It no longer calls `esc_url_raw()` before validation, because that
function can supply a scheme and remove characters. Arrays/objects retain an
invalid marker so optional forged arrays cannot become silently empty values.
Classic, Store API, and repeated URL validation use the same URL validator.

The validator requires PHP `FILTER_VALIDATE_URL`, a protocol from WordPress's
default safe protocol list, and no raw whitespace/control/markup delimiters.
Accepted scheme examples tested through both classic validation and Store API
include HTTP, HTTPS, FTP, FTPS, mailto, and IRC. JavaScript/data protocols,
missing schemes/hosts, markup, and array submissions are rejected. This is an
intentional stricter policy than WAPF's server checks and the browser constraint:
Chromium considers `javascript:alert(1)` valid for `type=url`, while OPF rejects
it server-side. PHP's validator follows RFC 2396 and requires ASCII URLs;
internationalized hostnames must use their ASCII representation. Additional
custom WordPress protocol filters do not expand OPF's accepted protocol list.
Sources: [HTML URL input contract](https://html.spec.whatwg.org/multipage/input.html#url-state-(type=url)),
[WordPress URL cleaning contract](https://developer.wordpress.org/reference/functions/esc_url/),
and [PHP URL validation contract](https://www.php.net/manual/en/filter.constants.php).

## Executed acceptance

[Real-browser proof](../../bin/e2e-url-browser-test.mjs): **35 passing checks**.
It saves/reloads URL labels, required, placeholder, and default through the real
authenticated WordPress editor and REST endpoint. It checks actual native URL
inputs, required and type-mismatch constraints, forged classic HTTP rejection,
and five malformed/array submissions through the actual Store API HTTP route.
Rejected requests leave the cart empty. A valid classic form submission reaches
the real block cart, block checkout, Store API place-order response, and order
confirmation; cart/order DOM serializations escape ampersands. No uncaught page
errors occurred. No fetch mocks or synthetic HTML were used. Admin/cart
screenshots were visually inspected.

[Disposable commerce proof](../../bin/e2e-url-lifecycle.php): **59 passing checks**.
It reloads the browser-created order from WooCommerce's data store, verifies
admin persistence, checks valid/invalid schemes and required/optional arrays,
omitted defaults, explicitly cleared required defaults, and valid overrides
through classic/Store API paths. Actual Store API checkout persists structured
and public item metadata with the unchanged base line total. HTML/plain on-hold
email bodies include the labels and values; HTML escapes the URL ampersand.
WooCommerce's order-again filter and a real cart add preserve exact selections.

Focused `UrlFieldTest`: **7 tests / 47 assertions** for normalization, safe and
invalid schemes, transport preservation, optional/required input, array
rejection, repeated validation, default validation, and unchanged legacy shape.
Complete PHPUnit: **232 tests / 930 assertions**, with one existing metadata
deprecation. PHP/Node syntax checks and `git diff --check` passed.

This accepts the native `WAPF-FIELD-URL` lifecycle with the documented stricter
validation policy. WAPF default import/export mapping, richer repeated-default
behavior, other themes, and platform floors are separate acceptance work; this
evidence does not accept those rows.

## Reproduction, raw evidence, and cleanup

Use a dedicated `/tmp` WordPress/WooCommerce site, this checkout active,
a fixture administrator, and loopback-only login helper reading
`opf_url_e2e_state`. The helper is disposable and not shipped.

```sh
OPF_URL_E2E_ALLOW=1 OPF_URL_E2E_PHASE=setup wp --path=/tmp/opf-url-woo eval-file bin/e2e-url-lifecycle.php
OPF_BASE_URL=http://127.0.0.1:8126 node bin/e2e-url-browser-test.mjs
OPF_URL_E2E_ALLOW=1 OPF_URL_E2E_PHASE=commerce wp --path=/tmp/opf-url-woo eval-file bin/e2e-url-lifecycle.php
vendor/bin/phpunit
OPF_URL_E2E_ALLOW=1 OPF_URL_E2E_PHASE=cleanup wp --path=/tmp/opf-url-woo eval-file bin/e2e-url-lifecycle.php
```

Raw [browser results](url-field-browser-results.json) and
[commerce results](url-field-commerce-results.txt) are committed alongside this
document. Screenshots remain in `/tmp/opf-url-artifacts`:

```text
admin-reload.png     2adfd9aff609b96ab35b6e7923010611415e466e3ee53a1eb651a762a947fee5
checkout.png         5c40f96a3bf29ed35029a445030b2b70de1b6d7a3a32a792c8a7c4eb8c7ef842
classic-cart.png     3b7d44954ac3fb9ba272a77c2ba765d9a8a65bb72c6ecd426ec3374cfb66f131
order-received.png   e57bcbabef0e0f6fdd538a1e2f57f2abd686ea16e870f2236cbbf014934a52f2
required-invalid.png 07b19bb698549c4ffdd5fdabeea1d05002ef46c604609f1f85e27c083acff1e2
```

Cleanup removed fixture product/group/user/state and all orders containing the
fixture product. Follow-up queries reported fixture state absent, zero products
with its slug, and zero remaining order item metadata for its product ID.
