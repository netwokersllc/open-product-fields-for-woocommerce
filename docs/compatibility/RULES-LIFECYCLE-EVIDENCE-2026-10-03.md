# Rules lane — lifecycle proof report (draft for integrator)

**Lane:** OPF↔WAPF rules/conditions parity proof
**Worktree:** `/tmp/opf-lane-rules` — branch `lane/rules` (uncommitted; no commits/pushes made)
**Clone:** `/tmp/opf-image-rules-wp` @ `http://127.0.0.1:8305` (WooCommerce, Polylang en+fr, sqlite integration)
**Reference:** WAPF Extended 3.1.5, activated only inside `wapf_setup`/`wapf_cleanup` phases
**Date:** 2026-10-03

## Outcome per row

| Row | Outcome | Notes |
|---|---|---|
| WAPF-RULE-CONDITIONAL | proven | AND-within-group / OR-across-groups verified OPF vs WAPF, engine + storefront |
| WAPF-RULE-GLOBAL | proven | empty `rule_groups` renders globally on both; WAPF extra explicit-empty-group also global |
| WAPF-RULE-CATEGORY | proven | include, multi-term, cat+tag exclusion AND-composition, identical sets |
| WAPF-RULE-TYPE | fixed+proven | added `product_type` subject end-to-end; WAPF quirks documented below |
| WAPF-RULE-ATTRIBUTE | fixed+proven | added `pa_*` subjects end-to-end; `patts` + `color|*` wildcard verified vs WAPF |
| WAPF-RULE-EXCLUSION | proven | `not_in` rules honored in engine, storefront, cart capture, order line; forged payload dropped |
| WAPF-RULE-AUTH | proven | logged-in/out matrices identical across 3 browser session variants |
| WAPF-RULE-ROLE | proven | editor-only and not-administrator groups across anon/editor/admin sessions |
| WAPF-RULE-LANGUAGE | proven | `fr_FR` include/exclude + OPF post-language assignment vs WAPF `lang`/`!lang` |

## Verbatim results

- `vendor/bin/phpunit`: `Tests: 356, Assertions: 1564, PHPUnit Deprecations: 1` — `OK`
- `bin/e2e-rules-lifecycle.mjs` (full run): `{"passed":144,"total":144,"referenceJsErrors":0}`
  - eval `commerce` phase alone: `SUCCESS rules commerce` — 27 checks
  - eval `wapf_assert` phase alone: `SUCCESS wapf assert` — 24 checks
  - cleanup teardown checks all `ok` (baseline products/variations/users/groups/wapf posts/orders/terms/attributes/active plugins restored; fixture state option removed)

## Files changed in worktree (all uncommitted)

| File | Change |
|---|---|
| `includes/Engine/Evaluator.php` | accept `product_type` and `pa_*` placement subjects; docblock updated. **Shared file — other lanes (cards/variations) also own Evaluator; integrator must reconcile.** |
| `includes/Service/FieldGroups.php` | `for_product()` builds `product_type` (parent-aware for variations) + every registered `pa_*` taxonomy term context |
| `includes/Service/Admin/Builder.php` | render + reload include/exclude controls for categories, tags, product types, attribute terms (value = `taxonomy:term_id`) |
| `assets/js/opf-builder.js` | save/load change-tracking + rule emission for excluded cats/tags, type in/exclude, pa_* in/exclude; preserve unrelated OR groups |
| `tests/Unit/EvaluatorTest.php` | +1 test covering product_type and pa_* in/not_in |
| `bin/e2e-rules-lifecycle.php` | new — guarded `/tmp/`-only fixture: setup, commerce, wapf_setup/assert/cleanup, cleanup-to-baseline |
| `bin/e2e-rules-lifecycle.mjs` | new — Playwright orchestrator: session-variant DOM matrix, admin builder roundtrip, live edit effect, Store API cart, WAPF side-by-side |
| `opencode.json` | pre-existing untracked leftover from the failed opencode run — not mine, left untouched |

Shared docs (`WAPF-CAPABILITY-LEDGER.md`, `OPF-1.0-ROADMAP.md`): **not edited**, per lane rails.

## WAPF reference findings (not OPF failures)

1. **grouped/external display gate** — `includes/controllers/class-product-controller.php:725`:
   `display_field_groups()` returns early for `['grouped','external']`. No WAPF group ever
   renders on those product pages even when `is_field_group_valid_for_product` returns it
   (verified: engine asserts pass on pExt, DOM shows nothing). OPF renders the matched set
   on external products. The fixture encodes this reference expectation.
2. **product_type admin options** — `class-config.php:383-410` select2 lists only
   simple/variable/grouped. `external` is engine-valid (`product_is_type` accepts any
   `get_type()`) but not selectable in WAPF admin UI.
3. **select2 value contract** — real WAPF posts store rule values as `[{id,text}]` objects.
   `Conditions::get_frontend_conditions()` (`class-conditions.php:25`) does `$value['id']`
   unconditionally for `var_att`/`product_variation` subjects — scalar values cause a
   **TypeError → critical error page**. Fixture stores the real object shape.
4. **lang value** is the Polylang *locale* (`fr_FR`) via `Helper::get_current_language()`,
   matching OPF's `user_language` terms.
5. **clone data noise** — 746 pre-existing `wapf_product` posts, several with malformed
   serialized `post_content` (`unserialize` warnings at `class-field-groups.php:784` on
   every `Field_Groups::get_all()` call). Reference-side data issue; DOM assertions are
   per-field-id to stay sound. Pre-existing published OPF groups are drafted during the
   run and republished on cleanup.

## Evidence artifacts

`/tmp/opf-lane-rules-evidence/`:
- `browser-results.json` — all 144 check labels + pass flags
- `rows.json` — per-row outcome mapping (this report's table, machine-readable)
- Screenshots: `opf-anon-{pA,pA2,pB,pExt,pBlue,pVar}.png`, `opf-editor-pA.png`,
  `opf-fr-pAFr.png`, `opf-admin-pA.png`, `opf-admin-builder-placement.png`,
  `opf-cart-pA.png`, `side-by-side-{pA,pAFr}-anon.png` (OPF + WAPF co-rendered)
- `REPORT.md` — this file

## Commands run (verbatim)

```
vendor/bin/phpunit
  => Tests: 356, Assertions: 1564, PHPUnit Deprecations: 1. OK

OPF_RULES_E2E_ALLOW=1 OPF_RULES_E2E_PHASE={setup|commerce|wapf_setup|wapf_assert|wapf_cleanup|cleanup} \
  wp --path=/tmp/opf-image-rules-wp eval-file /tmp/opf-lane-rules/bin/e2e-rules-lifecycle.php
  => each phase prints "ok ..." lines + "SUCCESS <phase>"

node /tmp/opf-lane-rules/bin/e2e-rules-lifecycle.mjs   (run from /tmp/opf-url-native-parity for playwright module)
  => 21 OPF browser checks ok, 13 WAPF browser checks ok,
     {"passed":144,"total":144,"referenceJsErrors":0}
```

## Cleanup verification

Post-run clone state (queried after teardown): products=5, product_variations=0,
opf_field_group=7 (all republished), wapf_product=746, active_plugins=baseline
(polylang, open-product-fields-for-woocommerce, sqlite, woocommerce — WAPF inactive),
`opf_rules_e2e_state` option deleted, 0 `opf_rules_*` users, 0 leftover orders/terms/
attributes/uploads vs recorded baseline.
