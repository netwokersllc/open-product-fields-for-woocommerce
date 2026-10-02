# Required select and radio lifecycle proof — 2026-10-02

## Scope and source

This proof covers required select/radio choices: empty initial selection when no default exists, default selection when configured, optional select clearing, accessible names, keyboard operation, server validation, flat-fee pricing, WAPF Free round trips, classic and Store API checkout/order persistence, and order-again.

- Public feature branch base verified during the final run: `d82d21e`.
- Disabled-default fix commit: `523c0c47d230bb3891c638f9ac00bf0ce5113a35`.
- Runtime: isolated copy of the local WordPress test site at `/tmp/opf-select-radio-runtime-20261001`, loopback only (`127.0.0.1:8174`); no production site was used.
- Runtime versions: WordPress 7.1.2, PHP 8.5.11, WooCommerce 11.1.0, WAPF Free 1.6.21.
- WAPF package: `/tmp/wapf-free-1.6.21.zip`, SHA-256 `9741270796d61583df9a66ab2b154434b7f2d6237569372489e3efd00e197db6`.
- The runtime plugin tree matched the lane worktree across all 1,984 files. The served `assets/js/opf-builder.js` SHA-256 matched the worktree at `5065579c74f51c13aa1684ed219fea75b79f649a821688d82992859ec64f6fdb`.

## Changes

- Required selects now render a native `required` control and an empty “Choose an option” prompt when there is no enabled default. A configured enabled default remains selected. Optional selects keep an empty option so they can be cleared.
- Disabled choices cannot render as selected defaults or checked radio choices, including malformed/stale in-memory field data that bypasses normal schema normalization.
- Radio choices expose a named `radiogroup` with `aria-required` for required fields.
- Added focused renderer tests for empty/default/disabled select and radio defaults, plus radio naming/native required inputs.

## Results

- `composer test`: **281 tests, 1,087 assertions passed**; one pre-existing PHPUnit deprecation.
- `php -l includes/Service/Renderer.php`: passed. `git diff --check`: passed.
- Chromium `native` phase: **41/41 checks passed**. Authenticated OPF admin REST save/reload retained required flags, Unicode labels, defaults, and fee values. Browser checks covered empty required select, empty required radio, optional clearing, accessible names, select and radio arrow keys, and 320/768/1280 px field bounds. Screenshots are in `/tmp/opf-select-radio-proof-current/`.
- Chromium disabled-default probe: **13/13 checks passed**. A local-only MU probe injected stale `selected=true` after schema normalization for disabled select and radio choices. The rendered select value stayed empty, the disabled choice had no selected attribute, the radio was unchecked, and both required controls made the form invalid. Posted disabled choice slugs were rejected through classic product POST and Store API for both fields; each cart remained empty. Results and screenshot are in `/tmp/opf-select-radio-proof-current/disabled-default-browser-results.json` and `disabled-default-browser.png`.
- Baseline server checks also posted empty required values and unpublished choice slugs for both fields through classic product POST and Store API. All were rejected and left the cart empty.
- Real WAPF Free admin POST/reload phase: **7/7 browser checks passed**. The actual WAPF 1.6.21 field-group model save/reload and WAPF-to-OPF import-back checks preserved select/radio types, required state, Unicode labels, selected defaults, slugs, and flat fees. The model/admin artifacts are in `/tmp/opf-select-radio-proof-current/`.
- Classic and Store API carts each preserved quantity 2, both selected labels, and the expected `$25.00` line total from a `$10.00` base plus `$5.00` per-unit choices. Both checkout paths created real WooCommerce orders; reloaded order items preserved raw slugs, display labels, snapshots, prices, and generated email content.
- Actual account-page order-again links for the classic and Store API orders both restored the selections and current `$25.00` price, and both replayed carts completed checkout. The `again` browser phase passed **9/9 checks**; durable order checks passed in the `verify-again` phase.

## Commands and artifacts

The browser commands were run from the lane worktree against the disposable loopback site:

```sh
composer test
php -l includes/Service/Renderer.php
git diff --check
OPF_CHOICE_BASE_URL=http://127.0.0.1:8174 OPF_CHOICE_ARTIFACT_DIR=/tmp/opf-select-radio-proof-current OPF_CHOICE_BROWSER_PHASE=native node /tmp/opf-select-radio-runtime-20261001/e2e-select-radio-browser-test.mjs
wp --path=/tmp/opf-select-radio-runtime-20261001 eval-file /tmp/opf-select-radio-runtime-20261001/set-disabled-choice-fixture.php
node /tmp/opf-select-radio-runtime-20261001/e2e-disabled-choice-browser.mjs
OPF_CHOICE_E2E_ALLOW=1 OPF_CHOICE_ARTIFACT_DIR=/tmp/opf-select-radio-proof-current OPF_CHOICE_E2E_PHASE=comparator wp --path=/tmp/opf-select-radio-runtime-20261001 eval-file /tmp/opf-select-radio-runtime-20261001/e2e-select-radio-lifecycle.php
OPF_CHOICE_BASE_URL=http://127.0.0.1:8174 OPF_CHOICE_ARTIFACT_DIR=/tmp/opf-select-radio-proof-current OPF_CHOICE_BROWSER_PHASE=wapf node /tmp/opf-select-radio-runtime-20261001/e2e-select-radio-browser-test.mjs
OPF_CHOICE_E2E_ALLOW=1 OPF_CHOICE_ARTIFACT_DIR=/tmp/opf-select-radio-proof-current OPF_CHOICE_E2E_PHASE=verify-comparator wp --path=/tmp/opf-select-radio-runtime-20261001 eval-file /tmp/opf-select-radio-runtime-20261001/e2e-select-radio-lifecycle.php
OPF_CHOICE_E2E_ALLOW=1 OPF_CHOICE_ARTIFACT_DIR=/tmp/opf-select-radio-proof-current OPF_CHOICE_E2E_PHASE=prepare-again wp --path=/tmp/opf-select-radio-runtime-20261001 eval-file /tmp/opf-select-radio-runtime-20261001/e2e-select-radio-lifecycle.php
OPF_CHOICE_BASE_URL=http://127.0.0.1:8174 OPF_CHOICE_ARTIFACT_DIR=/tmp/opf-select-radio-proof-current OPF_CHOICE_BROWSER_PHASE=again node /tmp/opf-select-radio-runtime-20261001/e2e-select-radio-browser-test.mjs
OPF_CHOICE_E2E_ALLOW=1 OPF_CHOICE_ARTIFACT_DIR=/tmp/opf-select-radio-proof-current OPF_CHOICE_E2E_PHASE=verify-again wp --path=/tmp/opf-select-radio-runtime-20261001 eval-file /tmp/opf-select-radio-runtime-20261001/e2e-select-radio-lifecycle.php
```

Result JSON: `native-browser-results.json`, `wapf-browser-results.json`, and `again-browser-results.json`; screenshots and cart/order payload snapshots are in `/tmp/opf-select-radio-proof-current/`. The failed initial order-again harness attempt is retained separately at `/tmp/opf-select-radio-proof-current/harness-initial-failure-provenance.json`.

The initial replay failure was a harness setup issue: its WAPF comparator remained published during an OPF-only order-again test. The corrected run kept the OPF field group published and drafted only the WAPF comparator before replay. Both classic and Store API replay then passed; no failed check was suppressed.

## Remaining scope

The required select/radio lifecycle described above is verified. This lane does not claim full select/radio feature parity outside that lifecycle, a full assistive-technology audit, or completion of other WAPF edition rows. Those remain part of the broader parity ledger and release gates.
