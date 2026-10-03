# Native URL field parity follow-up

Run on 2026-10-03 against the OPF URL lane based on public branch commit
`fa462976c3190716dbdfd5cacdf111bea3b1b0bc`. The served comparison ran on a
disposable SQLite WordPress 7.1.2 / WooCommerce 11.1.0 clone at
`http://127.0.0.1:8137`, with WAPF Free 1.7.1, WAPF Extended 3.1.5, OPF,
Chromium 149.0.7827.55, and PHP 8.5.11. Production was not used.

This follow-up resolves the parser mismatches documented in the earlier
[compatibility audit](URL-FIELD-COMPATIBILITY-EVIDENCE.md) for the tested URL
cases. OPF uses the WHATWG URL parser with IDNA support (Rowbot URL 3.1.7,
IDNA 0.1.5, Punycode 1.0.4, Brick Math 0.9.3, Symfony polyfills 1.31.0),
vendored under a private namespace and loaded only when a URL is validated.
Accepted protocols come from `wp_allowed_protocols()`. JavaScript, data, and
vbscript remain rejected even if another plugin adds them to WordPress's safe
protocol list. URL selection/default values are preserved; validation does not
canonicalize the stored value.

## Evidence

The browser matrix compares OPF's served URL input with the installed WAPF Free
and Extended URL templates. For each of 19 values, it checks browser-native
validity, OPF admin REST save/reload for accepted defaults, storefront defaults,
classic form submission, and Store API submission. Accepted values also pass
through Store API cart item data, checkout, and order confirmation. Results:
**185/185 checks across 19 values, 11 accepted checkout cases, zero browser
errors.** The cases cover Unicode IDN/path/query, punycode, spaces, a filtered
WordPress-safe protocol, `mailto`, `tel`, `urn`, shortened HTTP syntax, unsafe
schemes, invalid IP/host/port forms, markup, and missing schemes. All three
served inputs agree on native validity. The server intentionally rejects
unsafe protocols and markup even where Chromium's generic `type=url` control
reports the value as syntactically valid.

Eleven completed fixture orders were then reopened through their real
authenticated WooCommerce **Order again** links. All **35/35 replay checks**
passed, including exact URL values in the Store API cart payload. The clone's
visible cart block did not render the item after a direct Store API request, so
the cart proof asserts the authoritative Store API response and follows through
to checkout rather than claiming visual cart-block output.

Focused `UrlFieldTest`: **10 tests / 129 assertions**. Full PHPUnit:
**354 tests / 1547 assertions**, one existing PHPUnit deprecation. PHP syntax
checks passed for all 118 vendored parser files. Admin REST defaults and WAPF
URL default import/export round trips preserve each tested value. The
public `WAPF-FIELD-URL` ledger row still needs the main audit owner's review of
this evidence and of separate URL behaviors outside this measured matrix.

## Reproduction and cleanup

The disposable setup uses `bin/e2e-url-lifecycle.php` and the isolated clone;
the helper refuses to run outside `/tmp`.

```sh
OPF_URL_E2E_ALLOW=1 OPF_URL_E2E_PHASE=setup wp --path=/tmp/opf-url-native-woo eval-file bin/e2e-url-lifecycle.php
wp --path=/tmp/opf-url-native-woo server --host=127.0.0.1 --port=8137
OPF_BASE_URL=http://127.0.0.1:8137 node bin/e2e-url-native-browser.mjs
OPF_BASE_URL=http://127.0.0.1:8137 OPF_URL_NATIVE_PHASE=again node bin/e2e-url-native-browser.mjs
OPF_URL_E2E_ALLOW=1 OPF_URL_E2E_PHASE=cleanup wp --path=/tmp/opf-url-native-woo eval-file bin/e2e-url-lifecycle.php
```

Raw [commerce observations](url-field-native-commerce-results.json) and
[order-again observations](url-field-native-order-again-results.json) are
committed next to this report. Cleanup returned the cloned database's
`wpt_posts` count to 1027, matching an untouched sibling clone. The fixture
product, state option, user, and all fixture orders are absent.
