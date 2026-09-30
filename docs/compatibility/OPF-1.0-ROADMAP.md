# OPF 1.0 parity roadmap

**Target:** match or exceed the current WAPF Extended edition, which includes
WAPF Pro core plus Extended features. The official Extended changelog currently
lists 3.2.1; the separate Pro changelog lists 3.2.2. Audit the latest Extended
archive together with the latest bundled/core fixes, including whether Pro's
3.2.2 admin fix is present in Extended 3.2.1.
Separately sold add-ons are outside this 1.0 target. Production has WAPF
Extended 3.1.5 installed but inactive; that installed source is evidence for
the production baseline, not the current parity target.

**Source of truth:**
[capability ledger](WAPF-CAPABILITY-LEDGER.md) has one stable row per
capability and records current OPF state, evidence, and remaining gap.
[3.1.5 installed-source audit](WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md) records
the local source baseline. [Official tier comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/)
and [official changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/)
establish that Extended includes Pro and identify the current changelog
version skew. Local WP-CLI reports the installed Extended plugin as inactive
3.1.5 with no update currently exposed in its update registry; this is not
evidence that 3.2.1 source has been reviewed.

## Progress now

The 132 Free/Pro/Extended ledger rows are the 1.0 edition scope, including three
“All versions” rows. Current recorded status:

| Status | Rows | Meaning for the gate |
| --- | ---: | --- |
| Baseline supported | 8 | Promising baseline only; not accepted as proof |
| Supported | 18 | Evidence recorded; still subject to current-source reconciliation |
| Supported with documented difference | 11 | Needs explicit non-regression review and acceptance |
| Partial | 80 | Material parity or proof remains |
| Gap | 11 | Known absent in the current OPF tree |
| Needs audit | 4 | Gift Card details, card-quantity conditionals, date-picker accessibility, and whether WAPF registers its bundled Bookings adapter need source review |
| **Total** | **132** | **G1 and G2 remain open** |

Extended-only rows: 28 total; 2 supported, 4 supported with a documented
difference, 20 partial, 2 needs audit. All 132 edition rows have a ledger status; this closes
inventory and known-gap classification, not source freeze. Several statuses
remain provisional against installed 3.1.5 source and current public docs; the
current licensed package has not been source-audited. These are row counts, not a
feature-weighted percent. The 3.1.5 source audit does not satisfy G1 because
the official current Extended target is 3.2.1, the Pro changelog separately
lists 3.2.2, and bundled Pro behavior is also in scope. No implementation
slice starts until G1 passes.

The changelog crosswalk found two missing capability rows: conditional settings
for card quantity inputs and date-picker accessibility. Both now have explicit
`needs audit` rows; totals above include them. Their exact behavior still needs
current-package source review. See the release-by-release crosswalk in
`WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md`.

## G1 audit tracker

This is the active stop line. Status advances only on recorded evidence in the
source audit and ledger; changelog coverage alone does not close source review.

| Step | Audit task | State | Acceptance evidence |
| --- | --- | --- | --- |
| A | Freeze edition boundary and versions: Extended 3.2.1 target, Pro 3.2.2 delta, six separate add-ons excluded | Done | Official tier comparison and current changelog versions recorded |
| B | Audit production-installed Extended 3.1.5 package as historical baseline | Done | Version and file inventory plus source behavior map in `WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md` |
| C | Audit public Free 1.7.1 source and its edition boundary | Done | Archive hash, source file inventory, Free/Pro field and pricing boundary recorded |
| D | Map official changes from Extended 3.1.6–3.2.1 and Pro 3.2.2 to ledger rows | Done for changelog mapping; source confirmation open | Versioned changes and affected rows listed; no changelog claim treated as source proof |
| E | Acquire the licensed current Extended 3.2.1 archive and verify its version/hash | **Blocked: archive unavailable on server** | Exact archive retained locally; plugin header/version and package inventory recorded |
| F | Inspect current package source, including bundled Pro code; reconcile 3.2.2 admin fix | Not started; depends on E | Changed files and behavior mapped against 3.1.5; exact defaults, stored keys, hook signatures, validation and lifecycle paths recorded |
| G | Reconcile all 132 edition rows to current source/docs and resolve bounded unknowns | Not started; depends on F | Every row names evidence, behavior/data semantics, relevant lifecycle, OPF gap and proof needed; no `needs audit` row |
| H | Fresh review of audit and ledger, then freeze source baseline | Not started; depends on G | Reviewer confirms scope/version and all row citations; G1 marked passed with dated snapshot |

Until E–H pass, do not start or continue parity implementation. Existing dirty
implementation files are preserved, but they do not count as audited or
accepted progress. The next action is to place the licensed 3.2.1 ZIP in this
plugin's audit inputs (or supply an authorized source directory); do not send a
license key or fetch a paid package through an account without authorization.

## FOSS and package audit (G4, partial)

Current source evidence:

- `LICENSE`, the committed plugin header, and committed `readme.txt` declare
  GPL-2.0-or-later.
- `composer.json` has no runtime library dependencies; PHPUnit and its
  transitive packages are development-only and declare MIT/BSD licenses.
- No separate third-party JS/CSS payloads were found under `assets/`.
- `bin/build.sh` excludes `vendor/`, tests, and development files. This is
  consistent with the zero-runtime-dependency manifest, but the final archive
  still needs a contents/runtime inspection.
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
| 0 | Freeze current source and edition scope | **Blocked at audit step E** | Obtain authoritative Extended 3.2.1 source. Reconcile Pro 3.2.2's fix, changelogs 3.1.6–3.2.2, official docs, public Free 1.7.1 source, and installed 3.1.5 source. Review all 132 edition rows, including duplicate-field/group operations, import/export, Gift Card and WooCommerce Bookings integrations, PHP helpers, user/role/language group targeting, platform support floors, bundled Pro behavior, and developer hook/API surface. Record source path/version, serialized keys/defaults, hook signatures, admin/frontend behavior, and applicable validation/price/cart/order lifecycle. Keep the six add-ons excluded. | **G1:** 132/132 rows reconciled to source/docs; version-specific claims cited; unknowns bounded; audit reviewed; no 3.1.5-only claim treated as current 3.2.1/core proof. |
| 1 | Migration and data fidelity | **On hold until G1** | Close every row whose gap includes WAPF import/export, legacy IDs, conditions, field/choice settings, global variables, formulas, or review-required mappings. Use real anonymized exports where available plus source-derived fixtures for absent field types. Unknown semantics must remain visibly review-required, never silently dropped. | Every in-scope importable row round-trips or maps equivalently; unsupported legacy data is reported for review; migration proof is attached to its ledger row. |
| 2 | Shared field/value and rule engines | **On hold until G1 and WP1** | Close core field input, defaults, required/constraints, conditional logic, repeaters, dates, calculations, formula functions/variables, and price/weight evaluation. Implement shared semantics once where possible, and keep browser previews aligned with server-authoritative validation and totals. | Each affected ledger row has passing focused coverage for normalization, valid/invalid submitted values, conditional visibility, and pricing/weight where relevant; no client-only behavior is counted as parity. |
| 3 | Extended field experiences | **On hold until G1 and WP2** | Close cards and main-image switching, child/linked products (specific and category sources, fixed/none category price type), image choices with quantity limits/zoom, date policies/cutoffs, calculation display and price modes, and formula-driven weight. | Admin save/reload and keyboard-accessible product-page behavior match the audited source contract; each field's stored/imported state and invalid-input behavior are covered. |
| 4 | WooCommerce lifecycle and integrations | **On hold until G1 and WP2–3** | Close pricing/tax/coupons/currency, classic and Store API carts, cart editing, stock and parent-child quantity/removal, checkout/order metadata, order-again, refunds/restocks, and each claimed theme/plugin integration. | Every applicable row has end-to-end evidence through the relevant storefront, server validation, cart, checkout/order, and restore/refund paths. No integration is claimed from static markup alone. |
| 5 | Admin, display, and accessibility parity | **On hold until G1** | Close global/product settings, builder usability, field-group listing/search/scheduling, visual design, price summaries/hints, translations, screen-reader/keyboard behavior, and responsive layouts against current docs/source. | Every UI row has admin save/reload and browser evidence; accessibility and responsive acceptance criteria are recorded in the ledger. |
| 6 | Ledger closure and release candidate | **Not started; depends on WP1–5** | Review all 132 edition rows with a fresh reviewer; resolve or explicitly document every difference; security/privacy, supported WordPress/WooCommerce/PHP versions, upgrade/uninstall, packaging, docs, changelog, rollback, and marketplace requirements. Add a release-candidate manifest and reproducible verification record. | **G2:** no baseline-supported/partial/gap/needs-audit rows and no unaccepted difference. **G3:** applicable commerce proofs pass. **G4:** release checklist passes before 1.0 tag/publication. |

Packages 1–5 are worked in the listed order after G1. A package can split into
small public commits, but its ledger row and proof must close before moving to
the next package. Cross-cutting fixes can be included with the active package
when needed, and must update every affected row. Avoid publishing any customer-
visible or production-only diagnostic UI as part of this work.

## Progress update rule

After each completed package or coherent commit, update the affected ledger
rows and this table's status. Count only `supported` rows as complete;
`supported with documented difference` stays separate until that difference
is reviewed against current WAPF and accepted. `baseline supported` remains
unproven. Report counts by status and completed gate, never an unweighted
percentage. Do not estimate a delivery date until G1 is closed and the current
source has established the actual remaining scope.

## Non-negotiable release goalposts

1. **G1 source freeze:** current Extended 3.2.1 behavior, bundled Pro 3.2.2
   fixes, and all 132 edition rows understood before further parity
   implementation.
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
