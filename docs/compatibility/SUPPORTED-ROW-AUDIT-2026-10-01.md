# Fresh review of previously supported rows — 2026-10-01

This review checked the ledger rows marked `supported` against the current
source tree and their cited, reproducible evidence. It first inspected OPF
commit `a45fcfcaaffede599f8a2226aa86f5122c8dc8ef`, then confirmed the findings
still applied at integrated commit `a7a4569eb7324511ab6bbe8168853974d5e546af`.
The product-targeting change did not alter the remaining rows in this audit.
Only tests/artifacts present in the repository were credited; uncited recollection
of earlier browser runs was not treated as proof.

## Supported claims retained

These five rows had evidence matching their bounded acceptance criteria:

- `WAPF-FIELD-TEXT`: [native text lifecycle evidence](TEXT-FIELD-LIFECYCLE-EVIDENCE.md).
- `WAPF-FIELD-TEXTAREA`: [textarea newline evidence](TEXTAREA-NEWLINE-EVIDENCE.md).
- `WAPF-FIELD-PARAGRAPH`: current mapper/exporter tests and guarded WAPF 1.7.1 WXR import in `bin/e2e-wapf-wxr.php`.
- `WAPF-RULE-TAG`: source mapping plus real product term lookup in `bin/e2e-test.php`.
- `WAPF-PRODUCT-SIMPLE`: disposable WooCommerce and Chromium lifecycle proof linked from the ledger row.

## Claims moved back to partial

| Row | Evidence finding |
| --- | --- |
| `WAPF-FIELD-EMAIL` | FieldValue unit validation exists; real classic and Store API forged-input rejection was not demonstrated. |
| `WAPF-FIELD-TOGGLE` | Schema/mapper coverage exists; full browser and commerce lifecycle proof was missing. |
| `WAPF-FIELD-CHECKBOX` | Claimed selection limits were absent in code, and Free does not include the paid multi-choice limit capability. |
| `WAPF-FIELD-CARDS-MAIN-IMAGE` | The cited E2E scripts are absent. Installed Extended implements group-level gallery image rules; current OPF has no card field or such rule engine. |
| `WAPF-CHOICE-DISABLED` | Imported flag existed, but the builder, standard choice controls, server rejection, and exporter did not preserve the complete behavior. The current implementation adds these paths; runtime proof remains open. |
| `WAPF-CHOICE-CAPACITY` | The cited 512-field/choice tests and results are absent. |
| `WAPF-CHOICE-BULK-IMPORT` | Current builder and repository lack the claimed bulk-import UI and cited browser artifacts. |
| `WAPF-RULE-GLOBAL` | OPF empty placement is global; installed WAPF 3.1.5 empty conditions do not match. Prior exact-title E2E assertion was not present as cited. |
| `WAPF-RULE-CATEGORY` | No category-only lifecycle fixture proves source membership, authoring, storefront, or cart behavior. |
| `WAPF-RULE-TYPE` | Evaluator and builder do not implement product-type targeting. |
| `WAPF-RULE-ATTRIBUTE` | Evaluator and builder do not implement attribute targeting; cited invalidation E2E artifacts are absent. |
| `WAPF-RULE-EXCLUSION` | Product/customer exclusion exists in limited paths; category/tag exclusion authoring and precedence are unproved. |
| `WAPF-GROUP-ADMIN-TITLE-SEARCH` | Core CPT search is plausible, but the cited authenticated browser evidence is absent. |
| `WAPF-DATE-WEEK-START` | Installed Extended 3.1.5 has no matching setting, and cited browser/PHP artifacts are absent; treat as an OPF enhancement pending proof of WAPF scope. |
| `WAPF-MIGRATION-REPORT-REPEATABILITY` | This is OPF migration safety, not a WAPF capability. Its proof belongs in release readiness, outside the parity denominator. |

The migration row is temporarily left `partial` in the ledger so its existing
proof and scope discrepancy remain visible. A later scope reconciliation must
move it to release-readiness criteria and replace it with any omitted WAPF
capability before adjusting the denominator.

## Reconciled counts

The 132 edition rows now record 5 supported, 8 supported with documented
differences, 114 partial, 4 baseline-supported, and 1 gap. Strict progress is
5/132 accepted supported rows. The six add-on rows remain separately tracked.

The five accepted rows are not a weighted measure of product completeness;
each other row must still clear its own implementation and proof criteria.
