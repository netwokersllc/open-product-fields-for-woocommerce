# OPF 1.0 Burn-down Tasks

## Current checkpoint — 2026-10-01

- [ ] Dispatch isolated implementation/proof workstreams from current HEAD `217e2d3`.
- [ ] Verify every worktree diff and focused evidence; reject unsupported claims.
- [ ] Integrate and push each coherent verified slice to `feat/opf-archive-import`.
- [ ] Recompute roadmap counts after each accepted ledger row; keep all 132 rows.
- [ ] Continue until every row and G2–G4 pass.

## Parallel workstream ledger

| Task | Acceptance criteria | Owner / worktree | Verified | Blocked | Waivers |
| --- | --- | --- | --- | --- | --- |
| Date-option import parity | Map source-confirmed date constraints; mapper tests prove supported values and review unsafe ones | `/root/date_import`, `gpt-6-luna`, `/tmp/opf-date-import`, 0 strikes | Pending | No | None |
| `sumQty(field ID)` parity | Match Extended source and actual OPF quantity model; server/browser and lifecycle evidence | `/root/sumqty`, `gpt-6-luna`, `/tmp/opf-sumqty`, 0 strikes | Pending | No | None |
| `[price.ID]` browser parity | Real Chromium verifies prior-group price, hidden source, totals, and no browser errors | `/root/priceid_browser`, `gpt-6-luna`, `/tmp/opf-priceid-browser`, 0 strikes | Pending | No | None |
