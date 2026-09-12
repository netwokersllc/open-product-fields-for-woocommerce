# WAPF Removal — Decision Document

**Decision requested:** delete Advanced Product Fields for WooCommerce
(WAPF Pro Extended), its companion plugins, and all WAPF-specific code from
this codebase, now that Open Product Fields (OPF) replaces it.

**Status: READY, with one precondition.** The plugin and importer are
verified (see `MIGRATION.md`); the theme/integration port (Phase 3 of the
runbook) must land before the delete step — the theme couples to WAPF's DOM
through ~300 CSS rules and several JS modules.

## Production findings that motivated this decision

1. **The legacy data is damaged and WAPF hides it.** 21 of the per-product
   `_wapf_fieldgroup` payloads fail `unserialize()` (multibyte byte-length
   corruption). WAPF silently skips unparseable groups — those fields have
   been dead on the live site. OPF's import resurrects all of them.
2. **WAPF's rule engine has a dead-end code path** (empty condition →
   `apply_filters( ..., false, ...)` with no fallback): any group configured
   with an empty placement rule never renders. The importer flags such
   groups for review instead of silently broadening them.
3. **Fragility tax**: this repo carries `wapf-ajax-fix.php`,
   `nova-wapf-multilingual-fix.php`, `wapf-admin-fix` and a pricing-guard
   reset in `filters.php` purely to keep WAPF functional here. OPF needs
   none of them.
4. Proprietary licensing, update nags on expiry, and admin-ajax architecture
   (no REST) — replaced by GPLv2+, no phone-home, REST + Store API.

## Complete WAPF inventory (audited Sept 2026)

### Plugins
| Item | Action |
| --- | --- |
| `bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended/` | Deactivate → delete after cutover verified. |
| `bedrock/web/app/plugins/wapf-admin-fix/` (git submodule, license-bypass tool) | Deactivate → `git submodule deinit` + `git rm`; **do not migrate to the OPF repo.** |

### mu-plugins
| File | Coupling | Action |
| --- | --- | --- |
| `wapf-ajax-fix.php` | Patches WAPF's admin-ajax endpoint | Delete with WAPF. |
| `nova-wapf-multilingual-fix.php` | Locale-targeting via `wapf/product_field_groups` | Port behaviour to `opf/product_field_groups` (equivalent filter exists in OPF's repository layer), then delete. |
| `e2e-login.php` | Adds `wapf/skip_*_validation` filters for E2E traffic | Replace with OPF equivalents (`opf_skip_validation`) — needs a small OPF addition first. |
| `nova-youtube-startcount.php`, `nova-spotify-start-count.php` | Read `_wapf_meta` from **historical order items** | Update to read `_opf_fields` first, falling back to `_wapf_meta`. Do not delete the fallback while pre-migration orders exist. |
| `wmc-cache-currency-detect.php`, `nova-social-preview.php`, `nova-spotify/nova-youtube` frontend | Comments/DOM reads referencing WAPF | Retarget selectors/comments during Phase 3. |

### Theme (`themes/framework`)
| File | Coupling |
| --- | --- |
| `modules/wapf.php` (146 lines) | Polylang locale targeting, footer-injection removal, field-attribute + totals filters |
| `app/filters.php` | Resets WAPF's broken `did_action` pricing guard |
| `modules/product-tabs.php`, `pro-membership.php`, `cart-ajax.php`, `performance-optimizations.php` (handle list `wapf-frontend`, `wapf-extended`), `app/setup.php` | Feature detection / asset handles |
| `resources/css/components/field-accordion.css`, `product.css`, `pro.css` | ~300 `.wapf-*` selectors — covered by OPF compat mode until retargeted |
| `resources/js/product/{quantity,form-shell,field-accordion,add-to-cart,poll-preview,pro-landing}.js`, `product.js` | Read `.wapf-*` classes, `data-wapf-price`, `wapf_config` |
| `resources/views/**` | Minor class references |

### Scripts & data
| Item | Action |
| --- | --- |
| `scripts/translate_wapf_groups.php`, `scripts/wapf_product_pricing.php`, `scripts/ai-help/lib/wapf_resolver.php` (+ incidental `wapf` mentions in scaffolding/translation scripts, `ai-help/schema.php`) | Retire or retarget to OPF's CPT (`opf_field_group`) after migration. |
| `scripts/logs/wapf-translate-*.jsonl` (13 files) | Archive, then delete. |

### Database (only after cutover verified + backup)
1. `wp post list --post_type=wapf_product --format=ids` → delete (748 posts, most empty shells).
2. `DELETE FROM wp_postmeta WHERE meta_key='_wapf_fieldgroup'` (26 rows; snapshot exists from `bin/wapf-export.php`).
3. **Keep** `_wapf_meta` / `_wapf` order-item meta on historical orders — it is order history, not plugin data. OPF-based orders store `_opf_fields`.

## Preconditions checklist (all must be ✅ before deletion)

- [ ] Phase 1 staging rehearsal green (runbook).
- [ ] Phase 2 production import + live test order verified on one product per type.
- [ ] Phase 3 theme/integration port merged (compat mode may bridge CSS/JS, but the `_wapf_meta` order-history readers must be dual-reading first).
- [ ] Snapshot from Phase 0 archived (S3/offsite).
- [ ] 7–14 days of clean live operation on OPF (orders + totals correct).

## Recommendation

Proceed in the order above. The plugin-level swap is low-risk (copy-only
import, compat mode, trivial rollback). The deletion step itself is safe only
after the theme port and the order-history readers are dual-reading — every
other WAPF touchpoint can be deleted immediately after cutover.
