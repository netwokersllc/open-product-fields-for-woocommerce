# OPF 1.0 Burn-down Tasks

## Current checkpoint — 2026-10-01

- [x] Dispatch isolated implementation/proof workstreams from current HEAD `2753549`.
- [ ] Verify every worktree diff and focused evidence; reject unsupported claims.
- [ ] Integrate and push each coherent verified slice to `feat/opf-archive-import`.
- [ ] Recompute roadmap counts after each accepted ledger row; keep all 132 rows.
- [ ] Continue until every row and G2–G4 pass.

## Parallel workstream ledger

| Task | Acceptance criteria | Owner / worktree | Verified | Blocked | Waivers |
| --- | --- | --- | --- | --- | --- |
| Date-option import parity | Map source-confirmed date constraints; mapper tests prove supported values and review unsafe ones | `/root/date_import`, `gpt-6-luna`, `/tmp/opf-date-import`, 0 strikes | Implemented and cherry-picked as `fdfffa4`; focused 2 tests / 15 assertions reported passed, PHP lint and diff check passed | No | Unsupported settings retained for manual review |
| `sumQty(field ID)` parity | Match Extended source and actual OPF quantity model; server/browser and lifecycle evidence | `/root/sumqty`, `gpt-6-luna`, `/tmp/opf-sumqty`, 0 strikes | In progress; native image-choice quantity model underway | No | Import remains outside this slice |
| `[price.ID]` browser parity | Real Chromium verifies prior-group price, hidden source, totals, and no browser errors | `/root/priceid_browser`, `gpt-6-luna`, `/tmp/opf-priceid-browser`, 0 strikes | Implemented and cherry-picked as `264c72b`; 5 Chromium checks passed against actual OPF frontend JS in an isolated page | No | Live WordPress-rendered product page remains unverified |
| WOOCS currency integration | Implement the source-confirmed adapter and focused coverage for the known OPF gap | `/root/opf_woocs`, isolated worktree from `fdfffa4`, 0 strikes | Dispatched; source and integration-point audit in progress | No | Full third-party plugin lifecycle proof may remain separate |
