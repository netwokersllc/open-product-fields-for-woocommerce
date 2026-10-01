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

Focused proof at this commit:

- `vendor/bin/phpunit --filter 'disabled_choice|disabled_choices'` — 3 tests,
  8 assertions pass.
- `composer test` — 235 tests, 939 assertions pass (one existing PHPUnit
  metadata deprecation).
- `node --test tests/js/*.test.cjs` — 10/10 pass.
- PHP syntax checks for all five touched PHP files and `node --check
  assets/js/opf-builder.js` pass.

The capability remains `partial`: authenticated browser admin save/reload,
accessible control review, real classic and Store API rejection, successful
checkout/order persistence, and WAPF import/export runtime comparison remain
to be recorded before the ledger can accept parity.
