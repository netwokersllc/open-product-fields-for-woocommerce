# OPF Test Report — September 12, 2026

Full-suite stability run: **3 consecutive iterations, every suite green, zero
flakes.** Runner: `bin/run-all-tests.sh` (single command, exits non-zero on
any failure — CI-ready).

## Suites and results (×3 iterations each)

| # | Suite | What it proves | Checks | Result |
| --- | --- | --- | --- | --- |
| 1 | Lint (PHP `php -l` all files + `node --check` both JS bundles) | No syntax regressions | all files | ✅ ×3 |
| 2 | PHPUnit unit (`tests/Unit`) | Pricing math + formula safety, conditionals, placement rules, WAPF parser repair, mapper fidelity, model normalization | 32 tests / 93 assertions | ✅ ×3 |
| 3 | CLI E2E (`bin/e2e-test.php`) | Full lifecycle: fixtures → group save → placement matching → classic add-to-cart (capture, hidden-field stripping, required validation) → pricing (percent/fixed/formula/multi-checkbox) → display → Store API add-item → Store API **checkout → real order** (structured meta, display meta, server-priced totals) → order-again restore → theme-compat markup | 43 | ✅ ×3 |
| 4 | Real HTTP (`curl` against live `wp server`) | Product page renders fields + compat classes + `data-wapf-price` + registry + `wapf_config` + module tag; real form POST add-to-cart; Store API cart returns **price 10500** ($105 = 100 + 5 boost) with `Delivery speed ⇒ Boost` item data | page + cart | ✅ ×3 |
| 5 | jsdom behavior (`bin/e2e-jsdom-test.mjs`) | The real frontend module executed against the real served page HTML: conditional field hidden on load → revealed on Boost → hidden again on switch-back; `wapf-checked` moves between swatches; `data-wapf-price` present | 10 | ✅ ×3 |
| 6 | Import verification (`bin/e2e-import-test.php`) | Clean-state import of the **real production WAPF corpus** (747 groups): all parsed, 21 charset-corrupted payloads resurrected, pricing/placement fidelity, formula qty-compensation stripped, percent choice computes on known base, idempotent re-import | 9 | ✅ ×3 |
| 7 | Activation cycle + uninstall (`bin/e2e-uninstall-test.php`) | Deactivate/reactivate keeps data and re-registers services; uninstall removes options + group posts; **historical order item meta survives** | 9 | ✅ ×3 |
| 8 | Production dry-run (read-only, live DB) | `imported=657 skipped=0 repaired=21 unparseable=0 needs_review=0` | — | ✅ |

## Bugs this test campaign caught and fixed

1. `plugins_loaded` boot order — plugin sorted before WooCommerce, cart layer
   never registered on web requests (CLI worked, web didn't).
2. `wp_add_inline_script` is a no-op for script modules — the field registry
   never reached the browser.
3. Theme-compat rewrite dropped `data-opf-field` — rendered markup was inert.
4. Store API static payload leak — a stale submission could attach to a later
   cart item in long-lived PHP processes (CLI caught it; FPM shielded it).
5. Importer read product meta via raw SQL — breaks under storage
   transformations; switched to the metadata API.
6. Importer assumed `get_post_meta` returns strings — clean payloads arrive
   pre-unserialized as arrays.

## Explicitly NOT covered by automation (requires staging rehearsal)

- Visual appearance in a real browser (no browser backend in the dev
  environment; jsdom verifies behavior, not rendering).
- The production theme's JS (`quantity.js`, `form-shell.js`,
  `field-accordion.js`) executing against OPF's compat markup — the
  attributes/classes they read are verified present and correct.
- React block-checkout UI rendering (the Store API payloads it consumes are
  verified end-to-end).
- PHP runtimes other than 8.5 (code targets 7.4+), page caching layers,
  multisite.

These are exactly runbook Phase 1: import on staging, click through one
product per field type, place a test order — the final human gate before
production rollout (`docs/MIGRATION.md`) and the WAPF deletion decision
(`docs/WAPF-DELETION.md`).
