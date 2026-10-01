# OPF 1.0 parity roadmap

**Target:** match or exceed the current WAPF Extended edition, which includes
WAPF Pro core plus Extended features. The official Extended changelog currently
lists 3.2.1; the separate Pro changelog lists 3.2.2. The available-source audit
and published changelog crosswalk are complete. The licensed current archive
is unavailable on this server, so behavior not disclosed by public sources is
identified on its affected ledger row and is not a project-wide implementation
hold.
Separately sold add-ons are outside this 1.0 target. Production has WAPF
Extended 3.1.5 installed but inactive; that installed source is evidence for
the production baseline, not the current parity target.

**Source of truth:**
[capability ledger](WAPF-CAPABILITY-LEDGER.md) has one stable row per
capability and records current OPF state, evidence, and remaining gap.
[3.1.5 installed-source audit](WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md) records
the local source baseline. [Official tier comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/),
[product marketing page](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/),
and the separate [Extended](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/)
and [Pro](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/changelog/)
changelogs establish the edition boundary, advertised capability inventory,
and version skew. Marketing claims are mapped to ledger rows; they do not
replace source or lifecycle proof. Local WP-CLI reports the installed
Extended plugin as inactive 3.1.5 with no update currently exposed in its
update registry. The available-source audit is complete; this live-site check
does not change the audited version boundary.

## Progress now — 2026-10-01

The 132 Free/Pro/Extended ledger rows are the 1.0 edition scope, including three
“All versions” rows. Current recorded status:

| Status | Rows | Meaning for the gate |
| --- | ---: | --- |
| Baseline supported | 8 | Promising baseline only; not accepted as proof |
| Supported | 18 | Evidence recorded and accepted as complete under the row-count rule |
| Supported with documented difference | 11 | Needs explicit non-regression review and acceptance |
| Partial | 88 | Material parity or proof remains |
| Gap | 7 | Known absent in the current OPF tree |
| Needs audit | 0 | Available source, marketing claims, and every published release delta are mapped; exact release details remain scoped to partial rows |
| **Total** | **132** | **G1 complete for available evidence; G2 remains open** |

Extended-only rows: 28 total; 2 supported, 4 supported with a documented
difference, and 22 partial. All 132 edition rows have a ledger status. The
available-source audit covers installed Extended 3.1.5, public Free 1.7.1,
current tier/marketing claims, and every published Extended 3.1.6–3.2.1 and
Pro 3.2.2 changelog change. Exact current-package details not specified in
public sources stay on the affected partial rows and do not block unrelated
implementation. These are row counts, not a feature-weighted percent.

The changelog crosswalk found two missing capability rows: conditional settings
for card quantity inputs and date-picker accessibility. Both are now explicit
partial rows with the known behavior and undisclosed exact release details
recorded. See the release-by-release crosswalk in
`WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md`.

## G1 audit tracker

The available-source and public-claim audit is complete. Changelog coverage
defines scope; it does not count as implementation or commerce proof.

| Step | Audit task | State | Acceptance evidence |
| --- | --- | --- | --- |
| A | Freeze edition boundary and versions: Extended 3.2.1 target, Pro 3.2.2 delta, six separate add-ons excluded | Done | Official tier comparison and current changelog versions recorded |
| B | Audit production-installed Extended 3.1.5 package as historical baseline | Done | Version and file inventory plus source behavior map in `WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md` |
| C | Audit public Free 1.7.1 source and its edition boundary | Done | Archive hash, source file inventory, Free/Pro field and pricing boundary recorded |
| D | Map official changes from Extended 3.1.6–3.2.1 and Pro 3.2.2 to ledger rows | Done | Every published change is mapped in the dated crosswalk; changelog claims remain separate from runtime proof |
| E | Look for the licensed current Extended 3.2.1 archive and verify its version/hash | Done for this audit | Server and source searches found installed Extended 3.1.5 only; package absence is recorded and is not a stop condition |
| F | Audit available core source, Free source, tier/marketing claims, and release deltas | Done | Installed 3.1.5 + public Free 1.7.1 source maps and official 3.1.6–3.2.2 changelog crosswalk; undisclosed current-package details are noted on affected rows |
| G | Reconcile all 132 edition rows to available source/docs and bound unknowns | Done | Every row has evidence, known behavior or uncertainty, OPF status, and proof gap; no unbounded source-audit item remains |
| H | Fresh review of audit and ledger, then freeze available-source baseline | Done | Audit and ledger reviewed on 2026-09-30; later source may refine individual rows |

G1 is complete for the available-source baseline. Continue using installed
3.1.5 source, public Free 1.7.1 source, official changelogs, and current
documentation. Keep version-specific evidence attached to each row; do not
present a 3.1.5-only behavior as verified for 3.2.1. Implement from the
published contract where sufficient and keep undisclosed settings or runtime
behavior explicitly unverified on the affected row. No global archive hold
remains.

## FOSS and package audit (G4, partial)

Current source evidence:

- `LICENSE`, the committed plugin header, and committed `readme.txt` declare
  GPL-2.0-or-later.
- `composer.json` has no runtime library dependencies; PHPUnit and its
  transitive packages are development-only and declare MIT/BSD licenses.
- No separate third-party JS/CSS payloads were found under `assets/`.
- `bin/build.sh` at public commit `c2153f8` was run from a clean archive. It
  produced 31 files / 245,569 bytes, including the GPL license, PHP runtime,
  CSS, and JS; `bin/`, `docs/`, `vendor/`, `tests/`, and Composer manifests
  were excluded. This inspected directory is baseline 0.1.0, not a 1.0
  candidate; final install/runtime behavior and marketplace archive remain
  unverified.
- Public `HEAD` plugin version and readme stable tag both say 0.1.0. The current
  dirty `open-product-fields-for-woocommerce.php` changes its header and
  `OPF_VERSION` to 0.1.1 while `readme.txt` remains at 0.1.0. Preserve that
  work; align metadata before making a release package.

This is not a completed provenance review: no file-by-file authorship/license
audit or reproducible release archive has been accepted. G4 stays open until
source provenance, bundled notices, build output, version metadata, and
marketplace requirements are reviewed against the exact release commit.

## Ordered work packages

| Order | Work package | Current state | Scope and required output | Exit condition |
| --- | --- | --- | --- | --- |
| 0 | Freeze current source and edition scope | **G1 complete for available evidence; row-level implementation continues** | Audit installed Extended 3.1.5, bundled Pro source, public Free 1.7.1, official tier/marketing claims, and all published changelog changes through Extended 3.2.1 / Pro 3.2.2. The current paid archive is absent, so package-only internals stay explicit per row; do not block unrelated implementation. Continue closing import/export, integration, PHP helper, targeting, platform-floor, and developer API parity from available evidence. Keep the six add-ons excluded. | **G1:** available-source scope mapped, public changes crosswalked, and version-specific unknowns bounded to affected rows. |
| 1 | Migration and data fidelity | **Active; import lifecycle and review-safe reporting proven for representative WAPF Free data** | Close every row whose gap includes WAPF import/export, legacy IDs, conditions, field/choice settings, global variables, formulas, or review-required mappings. Use real anonymized exports where available plus source-derived fixtures for absent field types. Unknown semantics must remain visibly review-required, never silently dropped. | Every in-scope importable row round-trips or maps equivalently; unsupported legacy data is reported for review; migration proof is attached to its ledger row. |
| 2 | Shared field/value and rule engines | **Active for documented/source-confirmed behavior** | Close core field input, defaults, required/constraints, conditional logic, repeaters, dates, calculations, formula functions/variables, and price/weight evaluation. Implement shared semantics once where possible, and keep browser previews aligned with server-authoritative validation and totals. | Each affected ledger row has passing focused coverage for normalization, valid/invalid submitted values, conditional visibility, and pricing/weight where relevant; no client-only behavior is counted as parity. |
| 3 | Extended field experiences | **Active for documented/source-confirmed behavior** | Close cards and main-image switching, child/linked products (specific and category sources, fixed/none category price type), image choices with quantity limits/zoom, date policies/cutoffs, calculation display and price modes, and formula-driven weight. | Admin save/reload and keyboard-accessible product-page behavior match the audited source contract; each field's stored/imported state and invalid-input behavior are covered. |
| 4 | WooCommerce lifecycle and integrations | **Active** | Close pricing/tax/coupons/currency, classic and Store API carts, cart editing, stock and parent-child quantity/removal, checkout/order metadata, order-again, refunds/restocks, and each claimed theme/plugin integration. | Every applicable row has end-to-end evidence through the relevant storefront, server validation, cart, checkout/order, and restore/refund paths. No integration is claimed from static markup alone. |
| 5 | Admin, display, and accessibility parity | **Active against available source/docs; current-package-only details remain gated** | Close global/product settings, builder usability, field-group listing/search/scheduling, visual design, price summaries/hints, translations, screen-reader/keyboard behavior, and responsive layouts against current docs/source. | Every UI row has admin save/reload and browser evidence; accessibility and responsive acceptance criteria are recorded in the ledger. |
| 6 | Ledger closure and release candidate | **Not started; depends on WP1–5** | Review all 132 edition rows with a fresh reviewer; resolve or explicitly document every difference; security/privacy, supported WordPress/WooCommerce/PHP versions, upgrade/uninstall, packaging, docs, changelog, rollback, and marketplace requirements. Add a release-candidate manifest and reproducible verification record. | **G2:** no baseline-supported/partial/gap/needs-audit rows and no unaccepted difference. **G3:** applicable commerce proofs pass. **G4:** release checklist passes before 1.0 tag/publication. |

Packages 1–5 proceed in the listed order using the best available evidence.
Features whose semantics are established by available source/docs move
through implementation and proof; an undisclosed package-specific detail stays
scoped to its ledger row and does not gate unrelated work. A package can split into small
public commits, but its ledger row and proof must close before moving to the
next package. Cross-cutting fixes can be included with the active package when
needed, and must update every affected row. Avoid publishing any customer-
visible or production-only diagnostic UI as part of this work.

## Progress update rule

After each completed package or coherent commit, update the affected ledger
rows and this table's status. Count only `supported` rows as complete;
`supported with documented difference` stays separate until that difference
is reviewed against current WAPF and accepted. `baseline supported` remains
unproven. Report counts by status and completed gate, never an unweighted
percentage. Estimate a delivery date only after the remaining rows are
decomposed into sized implementation and verification tasks.

## Non-negotiable release goalposts

1. **G1 source freeze:** available installed source, public Free source, current
   marketing/tier claims, published changelogs, and all 132 edition rows are
   mapped. Unpublished current-package details remain bounded to affected rows;
   G1 is complete for the available evidence and does not block implementation.
2. **G2 capability parity:** every edition row supported or has a reviewed,
   explicitly accepted difference; zero baseline-only, partial, gap, or
   needs-audit rows.
3. **G3 commerce proof:** relevant browser, server, cart/Store API,
   checkout/order, stock, restore, tax, and pricing lifecycles verified.
4. **G4 release readiness:** security, accessibility, compatibility,
   migration/rollback, FOSS source/dependency/asset provenance, aligned version
   metadata, inspected package contents, docs, and publication gates passed.

No single goalpost can be waived by a passing aggregate test or a marketing
feature list. A change in WAPF target version or inclusion of separate add-ons
requires updating the scope baseline and ledger before implementation resumes.
