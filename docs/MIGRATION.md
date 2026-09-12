# WAPF → OPF Migration Runbook

Field groups live in the **site's own database**, not in either plugin. The
importer copies them into OPF's own storage; the originals are untouched
until the separate deletion step (see `WAPF-DELETION.md`).

## Verified status (Sept 2026)

Evidence from this repository's test phase (disposable WP 7.1 + WooCommerce
11.1 environment):

| Check | Result |
| --- | --- |
| Unit tests (pricing, conditionals, parser, mapper) | 32/32 |
| E2E lifecycle (classic + Store API + orders + compat) | 28/28 |
| Real-data import (production corpus, commit mode, disposable DB) | 747 groups, all fidelity checks green |
| Real-data import (production, read-only dry run) | 657 import-ready, 0 unparseable, 21 repaired, 0 needs-review |

"21 repaired": 21 per-product payloads on production fail PHP's
`unserialize()` because multibyte characters broke the byte-length prefixes
(charset damage). They were already **silently dead inside WAPF** — the OPF
importer's recovering decoder resurrects them.

## Phase 0 — Snapshot (read-only, any time)

```bash
cd bedrock
wp eval-file web/app/plugins/open-product-fields-for-woocommerce/bin/wapf-export.php /tmp/wapf-export.json
```

Keep this file; it is both the safety net and the input for staging
rehearsal.

## Phase 1 — Staging rehearsal

1. Copy production to staging (DB + uploads).
2. `wp plugin activate open-product-fields-for-woocommerce`
3. `wp opf import-wapf --commit`
4. Verify one product per field-type family (text-swatch, url, textarea):
   fields render, price updates with each choice, add-to-cart carries the
   chosen values into cart and order.
5. Re-run `wp opf import-wapf` — must report 0 imported (idempotent).

## Phase 2 — Production rollout

1. `wp plugin activate open-product-fields-for-woocommerce`
2. `wp opf import-wapf --commit`
3. `wp opf report` — confirm count matches the staging run.
4. Place a live test order on one product per type; verify cart line shows
   the choices and the order item stores per-field meta.

**Theme compatibility mode is ON by default** (`opf_theme_compat` option):
OPF renders the legacy `wapf-*` class skeleton, `data-wapf-price` attributes,
`wapf-checked` toggling and the `window.wapf_config` formatting global, so
the current theme integration keeps working untouched. Rollback is trivial at
this point: deactivate OPF, re-activate WAPF — no shared data was modified.

## Phase 3 — Theme & integration port (separate workstream)

De-WAPF the codebase (inventory in `WAPF-DELETION.md`):

- `themes/framework/modules/wapf.php` → port the Polylang locale-targeting
  behaviour to an `opf/product_field_groups` filter in OPF, then retire the
  WAPF-specific parts.
- Theme CSS (`field-accordion.css`, `product.css`, `pro.css`) → retarget
  `.wapf-*` selectors to `.opf-*`, or keep compat mode.
- Theme JS (`quantity.js`, `form-shell.js`, `field-accordion.js`,
  `add-to-cart.js`, `poll-preview.js`) → retarget `.wapf-*` / `wapf_config`
  reads to OPF equivalents.
- `mu-plugins/nova-youtube-startcount.php` + `nova-spotify-start-count.php`
  → read BOTH `_opf_fields` (new orders) and `_wapf_meta` (historical orders).
- `mu-plugins/wapf-ajax-fix.php`, `nova-wapf-multilingual-fix.php` → delete
  (they patch WAPF internals OPF does not have).
- `filters.php` → drop the WAPF pricing-guard reset (OPF does not have that
  bug; it prices through `woocommerce_before_calculate_totals` with an
  idempotency guard).

## Phase 4 — Compat off, then deletion

1. `wp option update opf_theme_compat off` and re-verify a product page.
2. Follow `WAPF-DELETION.md` for the removal order and per-item verification.

## Rollback

At any point before Phase 4 item 2: deactivate OPF, re-activate WAPF. WAPF's
own storage (`wapf_product` posts, `_wapf_fieldgroup` meta) is never written
by OPF — the import is copy-only.
