# OPF 1.0 Burn-down Tasks

## Current checkpoint — 2026-10-01, integration HEAD `9ea1fae`

- [x] Refresh orchestration roster: root is responsible for source review, integration, verification, ledger acceptance, and public pushes; three isolated writers are active.
- [ ] Verify every worktree diff and focused evidence; reject unsupported claims.
- [ ] Integrate and push each coherent verified slice to `feat/opf-archive-import`.
- [ ] Recompute roadmap counts after each accepted ledger row; keep all 132 rows.
- [ ] Continue until every row and G2–G4 pass.

The live GitHub branch is `9ea1faea0171c9002e4a9bb7322a01aff4646379` as of
this checkpoint (`git ls-remote`, 2026-10-01). Local `origin` ahead/behind
decoration is not authoritative for the public branch.

## Parallel workstream ledger

| Task | Acceptance criteria | Owner / worktree / model (effort) | Verified | Blocked | Waivers |
| --- | --- | --- | --- | --- | --- |
| Date-option import parity | Map source-confirmed date constraints; mapper tests prove supported values and review unsafe ones | `/root/date_import`, `gpt-6-luna` (medium), `/tmp/opf-date-import`, 0 strikes | Implemented as `fdfffa4`; main `WapfMapperTest` passed 31 / 137 assertions; full suite passes | No | Unsupported settings retained for manual review |
| `sumQty(field ID)` parity | Match Extended source and actual OPF quantity model; server/browser and lifecycle evidence | `/root/sumqty`, `gpt-6-luna` (medium), `/tmp/opf-sumqty`, 0 strikes | Native model `4ef9bd5`, import/bounds `255f10a`, Woo lifecycle E2E `46ec85a`; main rerun passed; full suite 184 / 663, JS 7/7 | No | Browser interaction and import round-trip remain separate; parity row remains partial |
| `[price.ID]` browser parity | Chromium verifies prior-group price, hidden source, totals, and no browser errors | `/root/priceid_browser`, `gpt-6-luna` (medium), `/tmp/opf-priceid-browser`, 0 strikes | `264c72b` isolated proof and `ad202cc` WordPress-page proof; root reran 7 functional checks plus fixture/options restoration | No | Exact WAPF 3.2.1 source comparison and duplicate-ID behavior remain |
| WOOCS currency integration | Implement the source-confirmed adapter and focused coverage for the known OPF gap | `/root/opf_woocs`, `gpt-6-luna` (medium), isolated worktree from `264c72b`, 0 strikes | Adapter `db86587` plus runtime `8b3acf4`; main 184 / 663 PHP, JS 7/7, fake-WOOCS + real-Woo cart/order, jQuery/Chromium currency/variation/reset proof pass | No | Actual WOOCS hook ordering/fixed-price preview, linked-product and pricing-hint end-to-end paths remain open; row stays partial |
| Duplicate-group admin proof | Authenticated browser verifies list action, dispatch, and reference remapping in a disposable WP clone | `/root/opf_group_dup_browser`, `gpt-6-luna` (medium), isolated worktree from `799a411`, 0 strikes | Cherry-picked as `8b24765`; main rerun passed 8 Chromium assertions and cleanup; full suite 172 / 615 passes | No | Current Extended comparison, variable/gallery refs, and product-duplication hook remain separate; parity row stays partial |
| Image-quantity import + bounds parity | Map source-confirmed `image-swatch-qty` settings; represent WAPF's 999999 bound and aggregate max_choices natively | `/root/opf_qty_import`, `gpt-6-luna` (medium), isolated worktree from `4ef9bd5`, 0 strikes | `255f10a` mapped per-choice bounds; fresh WAPF source check found max_choices is an aggregate sum cap, so current mapping is incomplete and a failing acceptance case is assigned below | No | Must prove aggregate cap in server/client/import lifecycle; affected row stays partial |
| `sumQty` commerce lifecycle | Disposable Woo cart/order E2E proves quantity input sanitation, formula result, price, and order persistence | `/root/opf_sumqty_woo_e2e`, `gpt-6.1-sol` (high), fresh isolated clone/worktree, 0 strikes | Cherry-picked as `46ec85a`; main independently reran 9 invalid Store API/classic cases, cart 28.00, two-unit order 56.00, boundary prices 13.00/41.00, and fixture cleanup; full suite 176 / 633 passes | No | Browser input interaction/import round-trip remain separate; parity row remains partial |
| WOOCS runtime parity | Wire currency adapter and prove base/option/formula/cart/browser conversion paths against installed source | `/root/opf_woocs_runtime`, `gpt-6.1-sol` (high), fresh worktree from current branch, 0 strikes | Cherry-picked as `8b3acf4`; main 184 / 663, JS 7/7; fake-WOOCS cart/order and browser tests independently rerun on disposable clones | No | Third-party WOOCS itself unavailable; fixed-price differences, linked products, and price hints still unverified |
| Image-quantity import lifecycle + aggregate cap | Implement WAPF aggregate `array_sum(quantities) <= max_choices`, preserve per-choice limits/import, prove cart/order and sumQty | `/root/opf_sumqty_woo_e2e`, `gpt-6.1-sol` (high), isolated worktree + private clone, 0 strikes | Source audit found WAPF 3.1.5 `class-cart.php:300–309` enforces aggregate max; implementation and failing→passing acceptance proof underway | No | Browser/admin interaction remains separate |
| WPML localization integration | Close the known WPML gap for translated local/global fields and variations against WPML contract | `/root/opf_wpml`, `gpt-6-astra` (high), isolated worktree from `043a2b1`, 0 strikes | Safe runtime seam identified: translate only `FieldGroups::for_product()` results, with language-aware cache key; implementation underway | No | WPML plugin runtime may be unavailable; no parity claim until tested |
| Aelia currency integration | Close the known Aelia gap across currency bases, option/formula pricing, cart, and browser totals | `/root/opf_aelia`, `gpt-6.1-sol` (high), isolated worktree from `043a2b1`, 0 strikes | Source contract located in WAPF 3.1.5; adapter uses documented Aelia conversion API and OPF currency hooks; mock contract proof underway | No | Live Aelia plugin unavailable so far; mock contract alone cannot close the row |

### Orchestration control

The current wave has three writers plus the root integrator (the four-agent
concurrency limit). Currency adapters and quantity validation have distinct
file ownership; WPML is restricted to a `for_product()`-scoped filter and its
own integration class. Bootstrap ownership is serialized to Aelia. The next
slot will be assigned only after a current lane commits or is explicitly
blocked. Candidate independent chunks, in priority order, are: FOX currency
adapter (separate integration class and tests); current-state audit and closure
of a bounded capability family with source + browser/cart proof; and G4
platform/version plus reproducible package audit. Each is assigned only after
checking path overlap and current roster. The 94 partial + 5 gap + 8
baseline-only + 7 difference rows mean a three-lane wave cannot honestly be
reported as 1.0 completion; only accepted ledger rows and all G2–G4 gates count.
