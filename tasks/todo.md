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
| Source-key import parity | Map only source-confirmed date constraints; mapper tests prove supported values and review unsafe ones | Pending | No | No | None |
| Formula quantity parity | `sumQty(field ID)` matches WAPF source and actual OPF quantity model; server/browser tests | Pending | No | No | None |
| Price-reference browser parity | Browser totals writer uses ordered group prices, hidden source resolves safely; cart/order cross-group proof remains green | Pending | No | No | None |
