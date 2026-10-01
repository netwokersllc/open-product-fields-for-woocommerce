# OPF 1.0 Burn-down Tasks

## Current checkpoint — 2026-10-01

- [x] Dispatch isolated implementation/proof workstreams from current HEAD `2753549`.
- [ ] Verify every worktree diff and focused evidence; reject unsupported claims.
- [ ] Integrate and push each coherent verified slice to `feat/opf-archive-import`.
- [ ] Recompute roadmap counts after each accepted ledger row; keep all 132 rows.
- [ ] Continue until every row and G2–G4 pass.

## Parallel workstream ledger

| Task | Acceptance criteria | Owner / worktree / model (effort) | Verified | Blocked | Waivers |
| --- | --- | --- | --- | --- | --- |
| Date-option import parity | Map source-confirmed date constraints; mapper tests prove supported values and review unsafe ones | `/root/date_import`, `gpt-6-luna` (medium), `/tmp/opf-date-import`, 0 strikes | Implemented and cherry-picked as `fdfffa4`; focused 2 tests / 15 assertions reported passed, PHP lint and diff check passed | No | Unsupported settings retained for manual review |
| `sumQty(field ID)` parity | Match Extended source and actual OPF quantity model; server/browser and lifecycle evidence | `/root/sumqty`, `gpt-6-luna` (medium), `/tmp/opf-sumqty`, 0 strikes | Native model cherry-picked as `4ef9bd5`; image-swatch-qty import/bounds as `255f10a`; full suite 176 / 633 and JS 6/6 pass | No | Woo cart/order lifecycle proof remains open; row stays partial |
| `[price.ID]` browser parity | Chromium verifies prior-group price, hidden source, totals, and no browser errors | `/root/priceid_browser`, `gpt-6-luna` (medium), `/tmp/opf-priceid-browser`, 0 strikes | `264c72b`: 5 isolated Chromium checks against actual OPF frontend JS; live WP proof now running on cloned SQLite site | No | Exact WAPF 3.2.1 source comparison and duplicate-ID behavior remain |
| WOOCS currency integration | Implement the source-confirmed adapter and focused coverage for the known OPF gap | `/root/opf_woocs`, `gpt-6-luna` (medium), isolated worktree from `264c72b`, 0 strikes | Adapter cherry-picked as `db86587`; 3 tests / 7 assertions and full suite 169 / 605 pass; lint/diff check pass | No | Adapter not wired; cart/back-conversion, linked-product/formula/hint/browser behavior remain unverified; row stays partial |
| Duplicate-group admin proof | Authenticated browser verifies list action, dispatch, and reference remapping in a disposable WP clone | `/root/opf_group_dup_browser`, `gpt-6-luna` (medium), isolated worktree from `799a411`, 0 strikes | Cherry-picked as `8b24765`; main rerun passed 8 Chromium assertions and cleanup; full suite 172 / 615 passes | No | Current Extended comparison, variable/gallery refs, and product-duplication hook remain separate; parity row stays partial |
| Image-quantity import + bounds parity | Map source-confirmed `image-swatch-qty` settings; represent WAPF's 999999 bound natively | `/root/opf_qty_import`, `gpt-6-luna` (medium), isolated worktree from `4ef9bd5`, 0 strikes | Cherry-picked as `255f10a`; source-mapped defaults/min/max/max_choices, review notes for unsupported settings; main full suite 176 / 633 and JS 6/6 pass | No | No disposable Woo import/cart/order E2E yet; affected row stays partial |
| WOOCS runtime parity | Wire currency adapter and prove base/option/formula/cart/browser conversion paths against installed source | `/root/opf_woocs_runtime`, `gpt-6.1-sol` (high), fresh worktree from current branch, 0 strikes | Dispatched; worktree and source contract audit underway | No | Any path without an actual WOOCS runtime test remains partial |
