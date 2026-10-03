# Lane taxproof — WAPF-COMMERCE-TAX real lifecycle re-proof

Proof lane only. No production source was edited, no commit/push/ledger change
was made. Everything below was executed against the real clone at
`http://127.0.0.1:8320`.

## Result at a glance

| Surface | Outcome | Checks |
| --- | --- | --- |
| 1a `opf:pricing` theme consumers | **NOT CLOSED — real gap** | 4/8 (4 hard fails) |
| 1b customer exemption + location | **Cart/order CLOSED; preview display NOT CLOSED** | OPF 21/21, WAPF ref 14/14, preview 2/4 |
| 1c active-WAPF interop | **CLOSED** | 12/12 |
| Task 2 signed `field_addon` tax regression | **CLOSED** | included in 1b OPF run |

Machine-readable: `results/tpf-results.json` (aggregate), plus per-surface JSON.

## Environment

```
WP 7.1.2 · WooCommerce 11.1.0 · PHP 8.5.11 · SQLite drop-in
Clone      /tmp/opf-image-taxproof-wp
OPF        0.1.0  (branch lane/taxproof, symlinked at wp-content/plugins/open-product-fields-for-woocommerce)
WAPF       Advanced Product Fields Extended 3.1.5 (inactive at baseline)
Active     [open-product-fields-for-woocommerce, sqlite-database-integration, woocommerce]
```

Baseline snapshot (full options / products / groups / users / rates / orders):
`baseline.json` + `baseline-snapshot.php`. This is a shared clone that already
contained prior-lane fixtures (746 `wapf_product` posts, 250 orders, 12 OPF
groups, 5 products, 1 user). Those were **not touched**; only `tpf`-tagged
fixtures were created and removed.

---

## Surface 1a — `opf:pricing` event consumers: NOT CLOSED

### What a real consumer needs

The production theme is present on this machine at
`/home/followersya-5hqi7/followersya.com/bedrock/web/app/themes/framework`. Both
product consumers require OPF to dispatch a **native DOM `CustomEvent` named
`opf:pricing`**:

- `resources/js/product/quantity.js:131`
  ```js
  document.addEventListener('opf:pricing', (event) => {
    applyPricing(event.detail?.displayed?.final ?? event.detail?.final);
  });
  ```
- `resources/js/product/field-accordion.js:427`
  ```js
  document.addEventListener('opf:pricing', () => { /* resync .acc-value */ });
  ```

(Copies for citation: `theme-consumers/quantity.js`, `theme-consumers/field-accordion.js`.)

The consumer therefore needs `event.detail.displayed.final` = the Woo-displayed
grand total and `event.detail.final` = the raw grand total.

### What was tested

A faithful minimal port of both consumers (and a raw recorder for **every**
dispatched `EventTarget.dispatchEvent` type) was installed through a temporary
mu-plugin (`fixtures/zz-tpf-opf-pricing-consumer.php`) and a Playwright run
(`browser/tpf-pricing-event.mjs`) drove a real product page (base +$5 option +
$3 toggle, qty 2, 10% tax) on the clone's shipped OPF 0.1.0.

```
$ node browser/tpf-pricing-event.mjs
ok   product page returns 200 200
ok   server injected Woo context {"productId":15915,"basePrice":100,"displayShop":"excl","taxMultiplier":1.1,...}
ok   initial totals rendered {"grand":"$100.00","events":0,"consumerCalls":0,"accordionCalls":0}
FAIL OPF dispatches a pricing event to document consumers {"opfEvents":[],"dispatchedPricingTypes":[]}
FAIL quantity.js-shaped consumer received a final total {"consumerCalls":[],"marker":""}
FAIL field-accordion.js-shaped consumer received the event {"accordionCalls":0}
FAIL event payload carries Woo-displayed totals distinct from raw []
ok   no uncaught JavaScript errors []
```

Direct DOM write still works (`product $200.00 / options $16.00 / grand $216.00`)
but **no pricing event of any name is dispatched**, so both theme consumers are
dead on OPF pages. Screenshot: `shots/tpf-event-consumer.png`.

### Why it cannot be closed on this branch

`assets/js/opf-frontend.js` (0.1.0) has no `dispatchEvent`/`CustomEvent` at all;
`writeTotals()` (lines 1423–1686) writes the three `.opf-*-total` spans directly.
The ledger note's claim that OPF "emits both existing raw `opf:pricing` values and
separate Woo-displayed totals" is therefore **not reproducible on the tested
artifact**.

Counter-evidence (not the clone plugin): a separate production checkout at
`.../plugins/open-product-fields-for-woocommerce/assets/js/opf-frontend.js:2070`
(version 0.1.1, uncommitted tree) *does*
`document.dispatchEvent(new CustomEvent('opf:pricing', { detail: { base, options, final, quantity, displayed: { base, options, final } } }))`.
The clone is OPF 0.1.0 and lacks it. No source edit is allowed in this lane, so
this surface stays open and is documented rather than closing it with new code.

Evidence: `results/tpf-surface-1a-event.json`, `browser/tpf-pricing-event.log`,
`shots/tpf-event-consumer.png`.

---

## Surface 1b — customer tax exemption + location variations

Product base 100, `+10` per-unit addon, qty 1 → merged unit 110. Rates: US-CA
10%, US-NY 8%, DE 19%; FR has no rate; plus a VAT-exempt customer. Run once with
OPF active and once with WAPF Extended 3.1.5 active (OPF off).

```
$ wp eval-file fixtures/tpf-lifecycle-opf.php   # OPF active
ok  location/exempt: base US:CA 10%           merged unit 110, line tax 11
ok  location/exempt: billing US:NY 8%         merged unit 110, line tax 8.8
ok  location/exempt: shipping DE 19%          merged unit 110, line tax 20.9
ok  location/exempt: shipping FR no rate      merged unit 110, line tax 0
ok  location/exempt: base US:CA VAT exempt    merged unit 110, line tax 0
ok  order persists NY billing tax 8.8
ok  order meta billing state NY
21/21 checks pass
```

WAPF reference run produced the **identical** matrix (`results/tpf-surface-1b-comparison.json`,
`all_match: true`):

| Scenario | OPF unit / line tax | WAPF unit / line tax |
| --- | --- | --- |
| base US:CA 10% | 110 / 11 | 110 / 11 |
| billing US:NY 8% | 110 / 8.8 | 110 / 8.8 |
| shipping DE 19% | 110 / 20.9 | 110 / 20.9 |
| shipping FR (no rate) | 110 / 0 | 110 / 0 |
| base US:CA + VAT-exempt | 110 / 0 | 110 / 0 |

Order persistence (OPF 8.8 / WAPF 8.8) and `billing_state=NY` match as well.

**Preview side is not closed.** Under `woocommerce_tax_display_shop=incl` the OPF
on-page totals DOM shows the raw total, not the Woo-displayed total:

```
$ node browser/tpf-preview-taxdisplay.mjs
FAIL OPF DOM grand matches Woo-displayed when shop display = incl
     {"domGrand":216,"wooDisplayedGrand":237.6,"rawGrand":216,"displayShop":"incl","taxMultiplier":1.1}
FAIL data-tax attribute equals the Woo tax multiplier {"dataTax":"1","multiplier":1.1}
ok   no uncaught JS errors
```

`data-tax` is hardcoded `1` (the documented `Renderer::render_totals()` dead
branch); the totals/hints are computed raw with no `maybe_add_tax`-style
conversion. Cart/order math is correct, only the storefront preview display
diverges. Screenshot: `shots/tpf-preview-incl.png`.

Evidence: `results/tpf-surface-1b-opf.json`, `results/tpf-surface-1b-wapf-reference.json`,
`results/tpf-surface-1b-comparison.json`, `results/tpf-surface-1b-preview.json`.

---

## Surface 1c — active-WAPF interop: CLOSED

OPF **and** WAPF Extended active together. Fixture creates one OPF-priced product
and one WAPF-priced product (both base 100, +10 addon, 10% CA), then runs
OPF-only, WAPF-only, mixed cart, mixed recalc, and mixed order.

```
$ wp eval-file fixtures/tpf-interop.php
ok  interop: OPF-only line priced 110 tax 11
ok  interop: OPF-only line carries opf data, no wapf data
ok  interop: WAPF-only line priced 110 tax 11
ok  interop: WAPF-only line carries wapf data, no opf data
ok  interop mixed: OPF line unchanged (110, tax 11)
ok  interop mixed: WAPF line unchanged (110, tax 11)
ok  interop mixed: subtotal 220 and tax 22 (no double-application)
ok  interop mixed: each line flags its own plugin data
ok  interop mixed: stable after recalc (OPF 110 / WAPF 110)
ok  interop mixed order: OPF line total 110 tax 11
ok  interop mixed order: WAPF line total 110 tax 11
12/12 checks pass
```

Neither plugin mutates the other's line, and neither double-applies on recalc.
Evidence: `results/tpf-surface-1c-interop.json`.

---

## Task 2 — signed `field_addon`, no tax regression: CLOSED

`Calculator::field_addon()` now returns signed values; `CartIntegration::apply_prices()`
clamps the *final* product price (`max(0, base + per_unit)`), not the field value.

```
ok  field_addon is signed: minus returns -30 (not clamped to 0)  (-30)
ok  signed plus: unit 110                                        (100 + 10)
ok  signed plus: line tax 11
ok  signed minus: unit 70 (base 100 + (-30))
ok  signed minus: line tax 7 (10% of 70)
ok  field_addon overdraw returns -200 (signed, unclamped)
ok  signed overdraw: cart clamps final price to 0
ok  signed overdraw: line tax 0
ok  signed overdraw order line total 0
```

Tax follows the clamped line: positive subtracts to 70 → 7 tax, overdraw clamps
to 0 → 0 tax, and the order persists the clamped 0. Focused JS pricing regression
suite passes 33/33 (`node --test tests/js/*.test.cjs`; the single failure is the
pre-existing, unrelated `opf-builder-auth.test.cjs` admin-search test, present
with the repo unmodified).

---

## Per-row recommendation (WAPF-COMMERCE-TAX)

The row should **stay `partial`**:

- **Closed/scoped:** customer tax exemption and location variations are now
  proven identical to a live WAPF 3.1.5 reference through cart and order;
  active-WAPF interop is proven; signed `field_addon` has no tax regression.
- **Still open:** (1) OPF 0.1.0 dispatches no `opf:pricing` event, so the two
  production-theme consumers are dead; (2) the storefront preview remains
  tax-unaware (`data-tax=1`, raw totals under incl display).

The row's existing note describes an `opf:pricing` emit and marketable
Woo-displayed preview that do not exist in this branch's code; that text should
not be treated as verified for OPF 0.1.0.

## Cleanup state (verified)

`after.json` vs `baseline.json`: **option diffs NONE**, active plugins identical,
products 5/5, OPF groups 12/12, `wapf_product` posts 746/746, orders 250/250,
users 1/1, tax rates 0/0, shipping zones unchanged.

Removed: event fixture product/group/rate, all lifecycle/interop fixture
products/groups/rates/orders, `wp-content/mu-plugins/zz-tpf-opf-pricing-consumer.php`,
and the WAPF-activation artifact option `wapf_db_version`. No tpf/tpfw products,
groups, rates, options, cron hooks, or users remain.

## Caveats

- The WAPF `fx` pricing type is a per-line amount (it divides by qty), whereas
  OPF `fixed` is per-unit; the 1b location matrix uses qty 1 so the tax
  comparison is unambiguous. This is a pricing-semantics difference, not a tax
  difference.
- WAPF Extended activation created `wapf_db_version`; removed. No commercial
  currency plugins were involved in this lane.

## Reproduction

```bash
cd /tmp/opf-image-taxproof-wp
# 1a
wp eval-file /tmp/opf-lane-taxproof-evidence/fixtures/tpf-event-setup.php
node /tmp/opf-lane-taxproof-evidence/browser/tpf-pricing-event.mjs
# 1b OPF (OPF active)
TPF_RESULTS=/tmp/opf-lane-taxproof-evidence/results/tpf-surface-1b-opf.json \
  wp eval-file /tmp/opf-lane-taxproof-evidence/fixtures/tpf-lifecycle-opf.php
# 1b WAPF reference (WAPF active, OPF inactive)
TPF_RESULTS=/tmp/opf-lane-taxproof-evidence/results/tpf-surface-1b-wapf-reference.json \
  wp eval-file /tmp/opf-lane-taxproof-evidence/fixtures/tpf-lifecycle-wapf.php
# 1c (both active)
TPF_RESULTS=/tmp/opf-lane-taxproof-evidence/results/tpf-surface-1c-interop.json \
  wp eval-file /tmp/opf-lane-taxproof-evidence/fixtures/tpf-interop.php
# teardown
wp eval-file /tmp/opf-lane-taxproof-evidence/fixtures/tpf-teardown.php
```
