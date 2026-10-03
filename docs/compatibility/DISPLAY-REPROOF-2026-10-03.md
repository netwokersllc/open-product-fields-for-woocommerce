# Lane dispproof — report (3 downgraded DISPLAY rows)

Environment: disposable clone `http://127.0.0.1:8322` (`/tmp/opf-image-dispproof-wp`),
admin `admin`/`admin` (verified: `wp user list` shows only `admin`; `wp_check_password('admin')` true).
OPF = `/tmp/opf-lane-dispproof` (lane/dispproof @ `1e74aa0`) symlinked as the plugin.
WAPF Extended 3.1.5 installed (activated only for reference captures). Proof lane: no source edits,
no commits/pushes/ledger edits. Evidence: `/tmp/opf-lane-dispproof-evidence/`.

Baseline: products=5, wapf_product=746, shop_orders=250, users=1, attachments=1,
active_plugins=[OPF, sqlite, woocommerce]; opf_theme_compat=yes, opf_admin_only=no,
woocommerce_calc_taxes=no, woocommerce_prices_include_tax=no, opf_price_summary_mode/opf_show_price_hints absent.
Final state verified equal to baseline (see `logs/baseline.txt` + final checks in row dirs).

---

## Row `WAPF-DISPLAY-PRODUCT-PRICE` — outcome: **DIVERGENCE / FEATURE ABSENT (row claim false)**

- WAPF Extended real product-price keys (product meta, saved through the REAL admin UI):
  `_wapf_price_display` in {`` default, `hide`, `before`, `after`, `replace`} and `_wapf_price_label` (text).
  Source: `class-admin-controller.php:196-246` (hooks `woocommerce_product_options_pricing` +
  `woocommerce_process_product_meta`); renderer `class-product-controller.php:71,83-128`
  (`woocommerce_get_price_html` prio 100, simple/subscription only).
- Saved via real UI on product 15915 and read back for all 5 modes; rendered `get_price_html()`:
  hide→empty; before→`<span class="wapf-price-before">Label</span> $10.00`;
  after→`$10.00 <span class="wapf-price-after">Label</span>`; replace→`<span class="wapf-price-replace">Label</span>`.
- OPF: **no implementation anywhere.** Repo-wide grep + `git log --all -S "_wapf_price_display"` = 0 hits.
  Runtime: OPF registers NO callback on `woocommerce_product_options_pricing`,
  `woocommerce_process_product_meta`, `woocommerce_get_price_html`; `_wapf_price_display=replace`
  on product 15915 is IGNORED (price still $10.00); OPF product editor has no price-display control.
- The ledger entry claiming OPF implements all five modes is not backed by any code/ref and is false
  at runtime. This row is a real GAP, not "partial, keys unverified".
- Evidence: `row2-product-price/` (`RESULT.md`, `wapf-price-display.mjs`, `ui-*.json`,
  `wapf-general-pricing-*.png`, `opf-general-pricing.png`, `opf-price-display-absent.mjs`).

## Row `WAPF-DISPLAY-PRICE-HINTS` — outcome: **BUG CONFIRMED (tax) + GAP CONFIRMED (order meta)**

- Tax conversion: OPF `Renderer::pricing_hint_html()` (`includes/Service/Renderer.php:99-122`) uses
  `wc_price(abs($amount))`, which never applies tax. WAPF converts via
  `Helper::maybe_add_tax()` / `adjust_addon_price()` (`class-helper.php:396-425,437-449`).
  Runtime (10% standard rate; prices excl tax; shop display incl):
  `wc_price(5)=$5.00` but `wc_get_price_to_display(addon 5)=5.5`;
  OPF hint `+ $5.00` vs WAPF hint `(+$5.50)`.
  Sibling `weightfix` fix NOT landed: branch == HEAD `1e74aa0`; worktree edits only
  Calculator/FieldGroup/WapfMapper/CartIntegration; `Renderer.php` unmodified; frontend JS has no
  hint-format path. => document the bug precisely (done).
- Order-item pricing-hint metadata: WAPF emits hints in 4 places (cart item_data `display`;
  raw order meta value `"yes (+&#36;5.00)"`; `_wapf_meta`.fields.*.values[].pricing_hint;
  formatted order meta display_value). OPF emits NONE (cart item_data `display:""`; raw order meta
  plain; `_opf_fields` plain; formatted meta plain). To match, OPF would need to compute/store a
  per-value hint (tax-correct) and append it in order context — feasible but unimplemented.
- Evidence: `row1-price-hints/` (`RESULT.md`, `wapf-meta-dump.json`, `opf-meta-dump.json`,
  `hint-tax.json`, 3 probe scripts).

## Row `WAPF-DISPLAY-HIDE-VALUES` — outcome: **SUPPRESSION MATCHES (WCPDF invoice proven)**

- Installed `woocommerce-pdf-invoices-packing-slips` 5.16.3 (free, wordpress.org) and generated REAL
  invoices (DomPDF, `%PDF-1.7`, ~213 KB) for an OPF order and a WAPF reference order, each with a
  visible priced choice `Finish=Gold` and a `hide_order` field `Internal note=secret-ref`.
- Both engines: invoice HTML/PDF contain `Finish: Gold` but NOT `Internal note` or `secret-ref`;
  raw structured meta (`_opf_fields` / `_wapf_meta`) still retains `secret-ref`. Suppression MATCHES.
- WCPDF path confirmed in source: `wpo_ips_display_item_meta()` → `get_all_formatted_meta_data()`
  (`wpo-ips-functions.php:1692-1714`).
- Residual (cross-link Row 1): WAPF annotates the visible value `Gold (+$3.00)`; OPF shows `Gold`
  only — the order-item pricing-hint gap, not a hide failure.
- Evidence: `row3-hide-values/` (`RESULT.md`, `invoice-opf.pdf/html`, `invoice-wapf.pdf/html`,
  `invoice-result-*.json`, `row3-invoice.php`).

---

## Cleanup (verified)
WCPDF uninstalled (plugin dir removed, `wpo_wcpdf*` options deleted, tmp upload dir removed);
tax options/rates restored; all fixture products/groups/orders deleted; row2 fixture product 15915
deleted. Final: products=5, wapf_product=746, shop_orders=250, users=1, attachments=1,
active_plugins=[OPF, sqlite, woocommerce]; no `*e2e*`/`wpo_wcpdf*` options; worktree `git status`
unchanged (only pre-existing untracked `LANE-BRIEF.md`, `opencode.json`; no source diff).
