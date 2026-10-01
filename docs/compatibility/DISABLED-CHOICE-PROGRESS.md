# Disabled-choice implementation progress

OPF now stores a disabled choice as unavailable in the builder, prevents it
from being a default selection, renders native disabled controls, rejects a
forged selected value in server validation (including repeated field rows),
omits disabled choices from pricing, and preserves the flag in the WAPF Tools
export/import payload.

Installed WAPF Extended 3.1.5 source checked read-only on 2026-10-01:

- `includes/classes/class-field-groups.php` normalizes `raw_choice.disabled`
  (SHA-256 `a93eec77639cf7ba4bc23908927421dacf26490de141fda7734ed7560720a9f8`).
- `includes/classes/class-html.php` adds native `disabled` attributes to
  unavailable choices (SHA-256
  `725cc1d252bc4efdbd3617097fc82d605d47baff6840d7d63d14615274b11fda`).
- Installed package version: Extended 3.1.5 (bundled Pro).

Focused and disposable-commerce proof at this commit:

- `vendor/bin/phpunit --filter 'disabled_choice|disabled_choices'` — 3 tests,
  8 assertions pass.
- `composer test` — 235 tests, 939 assertions pass (one existing PHPUnit
  metadata deprecation).
- `node --test tests/js/*.test.cjs` — 10/10 pass.
- PHP syntax checks for all five touched PHP files and `node --check
  assets/js/opf-builder.js` pass.
- On a disposable WordPress/WooCommerce clone at `127.0.0.1:8142`,
  `OPF_BASE_URL=http://127.0.0.1:8142 OPF_PRODUCT_STATE_FILE=/tmp/opf-product-state.json node bin/e2e-disabled-choice-browser.mjs`
  passes 13/13 checks with no browser errors. Chromium changes both a select
  option and checkbox choice in the authenticated builder, saves through the
  real REST route, reloads their disabled state, and observes disabled native
  storefront controls. A forged classic request is rejected with the OPF
  unavailable-choice notice and leaves the cart empty. A Store API request
  with its real nonce is rejected with the same OPF validation message and
  leaves cart empty. Available classic choices still reach WooCommerce cart.
  JSON results: [disabled-choice browser run](disabled-choice-browser-results.json).
- `OPF_DISABLED_CHOICE_E2E_ALLOW=1 OPF_DISABLED_CHOICE_E2E_PHASE=verify wp --path=/tmp/opf-product-woo eval-file bin/e2e-disabled-choice.php`
  passes renderer checks for disabled select/checkbox markup, forged select
  and checkbox validation, and acceptance of available choices.
- Disposable test setup: `OPF_PRODUCT_E2E_ALLOW=1 OPF_PRODUCT_E2E_PHASE=setup
  OPF_PRODUCT_STATE_FILE=/tmp/opf-product-state.json wp
  --path=/tmp/opf-product-woo eval-file bin/e2e-product-targeting.php`, then
  `OPF_DISABLED_CHOICE_E2E_ALLOW=1 OPF_DISABLED_CHOICE_E2E_PHASE=setup wp
  --path=/tmp/opf-product-woo eval-file bin/e2e-disabled-choice.php`.

The capability remains `partial`: successful checkout/order persistence,
WAPF import/export runtime comparison, and broader accessibility review remain
open before the ledger can accept parity. No progress count changes until all
row acceptance conditions are proven.
