# Native true/false toggle lifecycle evidence

Current-source browser proofs completed 2026-10-02 at 06:51:46, 06:54:18
and 06:54:38 UTC; served asset bytes were verified at 06:58:00 UTC. Exact public base:
`336dcac3e24746f96f2d387bc8df52be37ae45f6` plus this change.
Source checkout: `/tmp/opf-toggle-lifecycle-20261002`, branch
`work/opf-toggle-lifecycle-20261002`. OPF runtime:
`/tmp/opf-toggle-woo-20261002`, `http://127.0.0.1:8193`.
Installed WAPF Free reference runtime:
`/tmp/opf-toggle-wapf-reference-20261002`, `http://127.0.0.1:8194`.
Both are disposable SQLite clones with WordPress 7.1.2, WooCommerce 11.1.0,
PHP 8.5.11, Twenty Twenty-Five, and real Chromium/Playwright.
OPF and Free run separately; WooCommerce and SQLite integration are active.
Mail is intercepted with `pre_wp_mail`, including generated order emails.
Production, protected tabs, ledger and roadmap were untouched.

## Installed Free 1.7.1 contract

The reference is the actual installed package, not an inferred documentation
contract. Source paths below are relative to that plugin:

| Primary source | Observed behavior |
| --- | --- |
| `includes/classes/class-fields.php:44`, `:160` | Free `true-false` registry; checkbox message, checked/unchecked default, field pricing. |
| `views/frontend/fields/true-false.php:7` | Hidden `0` precedes native checkbox `1`; message escaped beside checkbox with a native label. |
| `includes/classes/class-html.php:272` | Checked default adds native `checked`. |
| `includes/classes/class-fields.php:463`, `:474`, `:509` | Native `1` displays `true`, other raw scalars display `false`; unchecked `0` skips field pricing. |
| `includes/classes/class-fields.php:582` | Required validation rejects empty supplied values; null is treated as a hidden dependency. |
| `includes/classes/class-field-groups.php:56`, `:116`, `:125` | Actual Tools converter accepts flattened default/message data and creates WAPF field models. |

Source SHA256:

```text
class-fields.php       92e2096f35e01e1c7036a780b4363a6285279b0d76e4cfcacca4224b7e05128b
class-html.php         88a0950f56bb73fa28ba110895e054812a2fab322c570da5d57dedbcec18aa38
class-field-groups.php e6a4b7b31c375d7a700cf55ba991521958957d7dea6469154d2ccdc50ee8e1a9
true-false.php         fe9e6eca3c79a1fe70af2782f4f527075bcc7b57c818b299976a598452aa9f17
```

## Toggle corrections

OPF now saves, reloads, imports, exports and renders checkbox message and
checked/unchecked defaults. The Tools roundtrip is executed through Free's
actual model converter. The editor's Not checked condition uses canonical `0`;
Free `!check` imports as `is_not 1`, which has the same Boolean result.

Real browser execution exposed hidden required toggle inputs still participating
in native form validation. Toggle controls and their hidden false inputs are
now disabled when hidden and enabled when visible. Nonrepeated hidden toggles
are removed at cart attachment, including forged submissions, so they do not
persist into orders or order-again data. Repeated fields are excluded from this
new attachment filter.

Real commerce execution exposed unchecked toggles being charged field pricing
because the server treated `0` as nonempty text. Toggle pricing now returns
zero unless the canonical value is `1`; the same guard applies to the browser
preview. The real purchase matrix verifies the $10 unchecked and $12 checked
prices at quantity one.

## Executed proof

- **7 WAPF reference browser checks:** native default/required/optional state,
  escaped message and accessible label, hidden `0`/checked `1` transport order,
  Space keyboard interaction, browser rejection of required unchecked state,
  and no uncaught page errors.
- **32 OPF browser checks:** authenticated real admin REST save/reload of type,
  label, required, message, checked default and false condition; native labels
  and accessibility snapshot; checked/unchecked defaults; Space interaction;
  conditional visibility; hidden-input transport; native required validation;
  forged classic/Store API HTTP rejection with empty cart; mobile fit; both
  false and true actual classic checkout; both false and true actual Store API
  add-item and Checkout Block checkout; confirmation display and no page errors.
- **62 real Woo checks:** admin data; import/export roundtrip through Free model;
  observed Free sanitizer; required false/empty/garbage/array rejection in both
  paths; exact `0`/`1` and checked defaults in cart; hidden forged values absent;
  server fee gate; durable reload of all four browser-created orders; structured
  and public item metadata; generated HTML/plain emails; actual restored-cart
  attachment and totals. This phase also completes the four fixture orders for
  WooCommerce's Order again action.
- **13 real order-again browser checks:** actual account-page Order again action
  followed for each of the four orders; resulting carts retain both false and
  true values and prices; no page errors.
- **PHPUnit:** focused 85 tests / 360 assertions; complete 287 tests / 1131
  assertions. Both pass with one existing PHPUnit metadata deprecation.
  **Node:** eight focused Boolean-pricing/false-condition assertions pass.
  PHP lint, Node syntax and `git diff --check` pass.

Raw current-source logs, accessibility snapshots, result JSON and HTTP runtime
source hashes are committed in [toggle-field-lifecycle-20261002](toggle-field-lifecycle-20261002/).
The served builder/frontend JS SHA256 and byte counts match this checkout.
Screenshots remain in `/tmp/opf-toggle-artifacts`; the mobile screenshot was
visually inspected. The helper used for fixture administrator login is local
to the disposable runtime and is not shipped.

## Reproduce

Create dedicated loopback-only disposable WordPress clones; link this checkout
as the active OPF plugin. Install the local login helper that signs in the
administrator from `opf_toggle_e2e_state`, and intercept mail. Activate Free
separately for the reference fixture. Draft inherited global WAPF fixture groups
on that disposable reference clone to avoid unrelated malformed legacy data.

```sh
composer install --no-interaction --prefer-dist
OPF_TOGGLE_E2E_ALLOW=1 OPF_TOGGLE_E2E_PHASE=setup wp --path=/tmp/opf-toggle-woo-20261002 eval-file bin/e2e-toggle-lifecycle.php
OPF_BASE_URL=http://127.0.0.1:8193 node bin/e2e-toggle-browser-test.mjs
OPF_TOGGLE_E2E_ALLOW=1 OPF_TOGGLE_E2E_PHASE=commerce wp --path=/tmp/opf-toggle-woo-20261002 eval-file bin/e2e-toggle-lifecycle.php
OPF_BASE_URL=http://127.0.0.1:8193 OPF_TOGGLE_ORDER_AGAIN=1 node bin/e2e-toggle-browser-test.mjs
OPF_TOGGLE_E2E_ALLOW=1 wp --path=/tmp/opf-toggle-wapf-reference-20261002 eval-file bin/e2e-toggle-wapf-reference.php
OPF_WAPF_BASE_URL=http://127.0.0.1:8194 node bin/e2e-toggle-wapf-reference.mjs
vendor/bin/phpunit --filter 'ToggleFieldTest|FieldValueTest|WapfExporterTest|WapfMapperTest|CalculatorTest'
vendor/bin/phpunit
node tests/js/opf-toggle.test.cjs
OPF_TOGGLE_E2E_ALLOW=1 OPF_TOGGLE_E2E_PHASE=cleanup wp --path=/tmp/opf-toggle-woo-20261002 eval-file bin/e2e-toggle-lifecycle.php
OPF_TOGGLE_E2E_ALLOW=1 OPF_TOGGLE_E2E_PHASE=cleanup wp --path=/tmp/opf-toggle-wapf-reference-20261002 eval-file bin/e2e-toggle-wapf-reference.php
```

Cleanup was executed and independently re-read: fixture posts, user, all four
recorded orders and state are absent; the Free reference product and state are
also absent. Dedicated runtime servers were stopped after evidence collection.

## Acceptance boundary and differences

This establishes the native Boolean field lifecycle, Free message/default data
mapping, required validation, false/true conditions, the checked-only fee gate,
and exact structured commerce persistence. OPF retains its existing translated
`Yes`/`No` public metadata; Free uses translated `true`/`false`. Thus literal
public wording equality is not claimed. OPF's existing sanitizer also accepts
checked scalar aliases `true`, `on`, `yes` (and Boolean true); Free's observed
raw sanitizer recognizes native `1`. OPF's visible required-field checks are
stricter for missing and malformed input. Native browser wire values are the
same `0`/`1` in both products.

Pricing-hint presentation, complete pricing-mode/quantity/tax parity, repeats,
Pro switch styling, other themes, screen-reader audio and the complete supported
WordPress/WooCommerce/PHP version matrix remain separate acceptance work. No
global Free capability, Pro capability or ledger status is declared complete by
this field proof.
