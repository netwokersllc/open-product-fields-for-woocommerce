# OPF 1.0 Parity Burn-down

## Objective

Match or exceed every Free, Pro, and Extended WAPF capability in the 132-row
ledger while keeping OPF FOSS. Separately sold add-ons remain outside scope,
as defined in `docs/compatibility/OPF-1.0-ROADMAP.md`.

## Acceptance

- Every ledger row has implementation evidence and required import, browser,
  server, cart, checkout, and order proof for that capability.
- No capability remains partial, baseline-only, or a gap. Documented
  differences have explicit user acceptance.
- Release gates G2–G4 pass; full suite and release artifact checks pass.
- Every verified slice is committed and pushed to the public GitHub branch.
- Production remains unchanged during parity work.

## Current evidence

As recorded in the roadmap on 2026-10-01: 18 supported, 7 supported with a
documented difference, 8 baseline-supported, 94 partial, and 5 gaps (132 total).
Strict row-count completion is 18/132 = 13.6%; this is not a feature-weighted
percentage. The ledger remains the detailed source of truth.

## Today’s execution

Parallelize independent vertical slices in separate worktrees. Each slice must
include source-grounded implementation or stronger proof, focused tests, and
an atomic commit. The main agent integrates, runs interaction/full gates, and
pushes verified commits. Reassign only after checking live agent and worktree
state.

## Dependencies and gates

1. Import mapping changes may depend on shared WAPF mapper APIs; serialize those
   writes or keep helper/test paths disjoint.
2. Formula runtime work must follow the actual value model; do not approximate
   missing image/file quantities with unrelated values.
3. Storefront proof must exercise the runtime totals writer; server pricing
   proof must reach Woo cart/order surfaces.
4. Integrate slices in small batches; after each batch run focused checks, then
   the complete suite and relevant disposable Woo/browser E2Es.

## Release gate

100% means all 132 rows are accepted and G2, G3, and G4 are verified. Passing
unit tests or completing only today’s parallel wave is not release completion.
