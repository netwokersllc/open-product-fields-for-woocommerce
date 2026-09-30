# WAPF Extended 3.1.5 source audit

Audit snapshot: 2026-09-30. The installed WAPF Extended package reports
version 3.1.5 in both its plugin header and the live WordPress plugin
registry; it was inactive during this read-only audit. Its package bundles
the Pro core plus Extended and linked-product controllers. The installed
directory contains 156 files, including 120 PHP files. No activation or
production behavior is inferred from static source.

Studio Wombat describes Extended as Pro plus Cards, linked Products,
Calculation, image swatches with quantities, extra formula functions, date
restrictions, weight changes, and enlarged image swatches. “Extended +
Addons” is a separate bundle that adds current and future add-ons; six
separate add-ons are tracked separately in the [full capability
ledger](WAPF-CAPABILITY-LEDGER.md).

Official edition boundary: [version comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/)
and [Extended changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/).

## Extended capability inventory

| Capability IDs in ledger | Installed 3.1.5 source | Audited source behavior |
| --- | --- | --- |
| `WAPF-DATE-DYNAMIC-BOUNDS`, `WAPF-DATE-WEEKDAYS`, `WAPF-DATE-DISABLED-DATES`, `WAPF-DATE-CUTOFF` | `includes/controllers/class-extended-controller.php`, `extend/date.php`, `includes/classes/class-helper.php`, `includes/classes/class-cart.php`, `assets/js/extended.min.js`, `views/frontend/fields/date.php` | Options are `min_date`, `max_date`, `disabled_days`, `disabled_dates`, and `disable_today_after`. Bounds accept y/m/d offsets and references to another date field. Blackouts accept exact dates, recurring MM-DD dates, and inclusive ranges. The frontend receives corresponding data attributes; the server validation filter enforces blackouts, weekdays, bounds, and same-day cutoff. Display format is the global `wapf_date_format` option, default `mm-dd-yyyy`. |
| `WAPF-DATE-WEEK-START` | `assets/js/datepicker.min.js`; date configuration in `includes/controllers/class-extended-controller.php` | The installed Extended PHP settings do not expose a WordPress `start_of_week` option. OPF's configured-week-start support is an additional behavior, not a WAPF setting. |
| `WAPF-FIELD-CARDS`, `WAPF-FIELD-CARDS-MAIN-IMAGE` | `includes/classes/class-config.php`, `includes/classes/class-html.php`, `includes/controllers/class-product-controller.php`, `assets/js/frontend.min.js` | `card` is a selectable, multi-choice field with choice pricing and appearance settings. Changelog v3.1 adds card-driven main-image switching. |
| `WAPF-FIELD-CHILD-PRODUCTS`, `WAPF-FIELD-CHILD-PRODUCTS-CATEGORY-PRICE-TYPE` | `includes/controllers/class-linked-products-controller.php`, `includes/classes/class-fields.php`, `includes/classes/class-cart.php`, `includes/classes/class-html.php`, `includes/classes/class-woocommerce-service.php`, `includes/controllers/class-product-controller.php` | The Products field stores `product_selection`, `product_query`, presentation subtype, selected products, quantity settings, selection bounds, and optional display slots. Category query `pricing_type` accepts `fixed` (default) or `none`; `none` sets the child line price to zero. Quantity selectors price from child quantity. Selected children become Woo cart/order lines; source hooks validate stock, synchronize quantity/removal, update stock, extend Store API data, persist parent-child order metadata, and restore order-again links. Changelog v3.0.8 adds category price type and caps category results at 50. |
| `WAPF-FIELD-CALCULATION` | `includes/controllers/class-extended-controller.php`, `includes/classes/class-config.php`, `includes/classes/class-fields.php`, `includes/classes/class-html.php`, `includes/controllers/class-public-controller.php`, `views/frontend/fields/calc.php`, `extend/formulas.php` | Field type `calc` stores `calc_type` (`default` informational or `cost` pricing), `formula`, `result_format`, and `result_text`. Cost calculations enter normal formula pricing. Changelog v3.1 allows calculations in other fields' conditionals. |
| `WAPF-FIELD-IMAGE-QUANTITIES`, `WAPF-FIELD-IMAGE-QUANTITY-ZOOM`, `WAPF-FIELD-SWATCH-IMAGE-ZOOM`, `WAPF-FIELD-CHILD-PRODUCTS-IMAGE-ZOOM` | `includes/classes/class-config.php`, `includes/classes/class-html.php`, `includes/classes/class-fields.php`, `includes/controllers/class-linked-products-controller.php`, `assets/js/frontend.min.js` | `image-swatch-qty` is a single-choice image field with quantity input and quantity-based pricing. `large_image` enables an enlarged choice image; markup exposes `data-zoom-url` for frontend behavior. Linked-product cards have independent image and display settings. |
| `WAPF-PRICE-FORMULA-WEIGHT`, `WAPF-COMMERCE-WEIGHT` | `includes/controllers/class-extended-controller.php`, `includes/classes/class-fields.php`, `includes/classes/class-cart.php`, `includes/controllers/class-linked-products-controller.php` | Field and choice settings store `weight`; quantity fields can store weight per quantity. Before Woo totals, selected values are resolved against the source field group and weight expressions accept `[qty]` and `[x]`. Values use WooCommerce's configured weight unit; linked products retain their own product weight. |
| `WAPF-PRICE-FORMULA-ADVANCED`, `WAPF-PRICE-FORMULA-TEXT-COMPARE`, `WAPF-PRICE-FORMULA-CHECKED`, `WAPF-PRICE-FORMULA-FIELD-STATE`, `WAPF-PRICE-FORMULA-SUM-QTY`, `WAPF-PRICE-FORMULA-TRIG`, `WAPF-PRICE-FORMULA-DATE`, `WAPF-PRICE-FORMULA-DOW`, `WAPF-PRICE-FORMULA-MONTH`, `WAPF-PRICE-FORMULA-CUSTOM-VARIABLE` | `includes/controllers/class-public-controller.php`, `includes/classes/class-config.php`, `includes/classes/class-helper.php`, `includes/classes/class-fields.php`, `includes/classes/class-field-groups.php`, `extend/formulas.php`, `extend/date.php`, `views/admin/variable-builder.php` | Core supplies `min`, `max`, `len`, and `lookuptable`; Extended registers `round`, `abs`, `floor`, `ceil`, `sqrt`, `cos`, `sin`, `tan`, `pow`, `sumQty`, `checked`, `files`, `if`, `or`, `and`, plus `today`, `datediff`, `dow`, and `month`. Formula arguments are semicolon-delimited; formula text comparisons are unquoted; `checked`, `files`, and `sumQty` take field IDs. Saved groups carry formula definitions and custom variables. PHP runtime and public formula-definition metadata are both in scope. |

These findings seed the Extended entries in the [capability
ledger](WAPF-CAPABILITY-LEDGER.md). This source audit does not claim OPF
parity; implementation, import, and commerce evidence must be established
separately for each capability. Pro is included because the Extended edition
bundles it. Six separately sold add-ons and compatibility entries remain
tracked separately and are outside this edition's 1.0 parity gate.

Official formula inventory: [formula function reference](https://www.studiowombat.com/knowledge-base/formula-functions-reference/).

## Bundled Pro-core source map (installed package 3.1.5)

Extended is built on the full Pro codebase. This map records the installed
package's Pro subsystems so the Extended-only inventory above is not mistaken
for the complete edition scope. Paths are relative to
`advanced-product-fields-for-woocommerce-extended/`.

| Pro subsystem | Installed source files | Source behavior inspected |
| --- | --- | --- |
| Bootstrap, models, and public PHP API | `class-wapf.php`; `includes/api/api-helpers.php`; `includes/models/class-field.php`; `includes/models/class-fieldgroup.php`; `includes/models/class-fieldpricing.php`; `includes/models/class-conditionrule.php`; `includes/models/class-conditionrulegroup.php` | Autoloading and plugin bootstrap load the admin, product, public, integrations, and Extended controllers. Field/group/pricing/condition models normalize the internal objects consumed by the remaining controllers; public helper functions expose field/group lookups and formula helpers. |
| Field schema and option settings | `includes/classes/class-config.php`; `includes/classes/class-fields.php`; `includes/models/class-field.php`; `views/admin/settings/*.php` | The registry defines scalar and choice field types, single versus multiple selection, accessible-label metadata, per-type options, required/default/min/max behavior, choice data, and extended registration through filters. The option model carries labels, descriptions, defaults, design controls, pricing, conditional rules, repeaters, and upload settings. |
| Pricing modes and calculation inputs | `includes/classes/class-config.php::get_pricing_options()`; `includes/classes/class-fields.php::do_pricing()`; `includes/classes/class-helper.php`; `includes/models/class-fieldpricing.php`; `extend/formulas.php` | Base pricing modes are `fixed` (flat), `qt` (quantity flat), `p` (percentage), `percent` (quantity percentage), and `fx` (formula); text-like fields add `char`/`charq`, and number/image-quantity fields add `nr`/`nrq`. Formula inputs include product price, product quantity, field values/prices, Extended options total, and configured variables. Pricing results are later applied through cart calculation rather than trusting browser-submitted totals. |
| Product/group and field conditional logic | `includes/classes/class-config.php`; `includes/classes/class-conditions.php`; `includes/classes/class-fields.php`; `includes/classes/class-field-groups.php`; `includes/models/class-condition*.php`; `includes/models/class-conditional*.php`; `views/admin/conditions.php` | Group placement conditions and field visibility conditions are distinct rule systems. The source resolves product, variation, category/tag/attribute, language, user/role and field-value rules; it builds browser condition data and re-evaluates server-side rules for submitted cart values. WAPF 3.1 adds calculation fields as dependencies. |
| Global/local authoring and migration | `includes/controllers/class-admin-controller.php`; `includes/classes/class-field-groups.php`; `includes/classes/class-wapf-list-table.php`; `views/admin/field.php`; `views/admin/field-list.php`; `views/admin/layout.php`; `views/admin/conditions.php`; `views/admin/variable-builder.php`; `views/admin/tools.php`; `views/admin/modal.php`; `views/admin/cpt-list-table.php` | Product-local field groups and the global Field Groups post type have separate persistence/assignment paths. Admin code handles product tabs, group lists and scheduling, builder tabs, conditions, formula variables, field/group duplication and field-ID remapping, settings/design screens, import/export tools, and list actions. Product-targeting and field IDs are persisted in WAPF's group/product data structures. |
| Storefront markup, design, and browser behavior | `includes/classes/class-html.php`; `includes/classes/class-design-helper.php`; `includes/controllers/class-product-controller.php`; `views/frontend/field-group.php`; `views/frontend/fields/*.php`; `assets/js/frontend.min.js`; `assets/js/datepicker.min.js`; `assets/css/frontend-*.min.css`; `assets/css/datepicker.min.css` | The renderer emits type-specific controls, labels, descriptions, price hints, conditional state, group layout, design classes, variation data, and pricing summary. Frontend JS handles choice state, condition updates, pricing previews, image switching, repeater controls, uploader interactions, and product quantity changes. Date picker behavior is in its dedicated script. |
| Cart validation, price calculation, order, and restore | `includes/classes/class-cart.php`; `includes/controllers/class-product-controller.php`; `includes/controllers/class-linked-products-controller.php`; `includes/controllers/class-public-controller.php` | The add-to-cart path reads raw field values, applies field/group/conditional and type-specific validation, recalculates price on the server, adds structured WAPF data to Woo cart lines, and renders configured values on cart/checkout/order surfaces. Source hooks also cover Store API data, cart edits, order metadata, order-again restoration, checkout validation, stock, and linked-child line handling. |
| File upload lifecycle | `includes/classes/class-file-upload.php`; `includes/controllers/class-public-controller.php`; `views/frontend/fields/file.php`; `assets/js/dropzone.min.js`; `assets/js/frontend.min.js` | Uploads have a dedicated Ajax and native-input path, allowed-type handling, size/count checks, storage-path protection files, cart-token validation, removal, order download/cleanup, and optional zip creation. The installed 3.1.5 implementation is the baseline; later security hardening/default changes are listed in the current-release delta above and remain to verify in the current package. |
| WooCommerce and theme/plugin adapters | `includes/controllers/class-integrations-controller.php`; `includes/classes/integrations/class-*.php` | Conditional adapters are registered for WooCommerce Subscriptions, WOOCS/FOX, Aelia, Woo Discount Rules, YITH Request a Quote, Tiered Pricing Table, WooCommerce Bookings, product tables, quick view, and named themes (Astra, Flatsome, Woodmart). Each adapter changes specific hooks for product type, price/currency, coupon, cart display, gallery, or quantity behavior; these are not one generic compatibility guarantee. |
| Localization and translation integration | `languages/sw-wapf.pot`; `languages/sw-wapf-*.mo`; `wpml-config.xml`; `includes/controllers/class-admin-controller.php`; `class-wapf.php` | Strings use the `sw-wapf` text domain with a POT and bundled locale catalogs. WAPF registers its global group post type for Polylang; WPML config marks selected admin text options, while the WPML guide describes translation of group CPTs and product/variation fields. |
| Vendor settings, licensing, and update boundary | `includes/classes/class-licensing.php`; `includes/controllers/class-admin-controller.php`; `includes/controllers/class-extended-controller.php`; `includes/classes/class-config.php` | The admin exposes global labels, upload/date behavior and date format, summary/design settings, product price display and plugin license/update UI. Extended controllers add fields/date/formula/weight/linked-product options on top of the shared Pro settings framework. Licensed distribution/update behavior is separate from OPF's source behavior and does not enter the FOSS compatibility license decision. |
| Developer extension API | PHP `apply_filters()` / `do_action()` calls under `includes/`, `extend/`, and `class-wapf.php` | Static source inventory found 92 unique literal `wapf/...` filter names and 8 unique literal `wapf/...` action names in the installed 3.1.5 package. Names cover field registration/rendering, validation, formula/pricing, cart/order, upload, linked products, admin screens, and integrations. Some names may be deprecated or internal call sites; counts do not define a stable documented API. Current-package signatures/arguments and OPF compatibility remain open. Official changelogs document individual developer hooks over time, including child-product query and cart-pricing filters. |

This source map covers the installed package's subsystem boundaries. It does
not assert that OPF matches each behavior: capability equivalence, migration
semantics, runtime integrations, and current 3.2.1/3.2.2 release differences
remain tracked as separate acceptance work.

The 3.1.5 literal-hook inventory seeds `WAPF-DEVELOPER-HOOKS` in the ledger;
it does not prove every hook is public, supported, or unchanged in 3.2.1. OPF's
separately namespaced filters need an explicit compatibility contract and
documentation before this row can count as parity.

## WAPF Free 1.7.1 source and edition boundary

The current WordPress.org source page lists Free 1.7.1; its plugin header and
readme agree. The inspected WordPress.org archive contains 79 files (53 PHP,
20 translation catalogs, 2 JS, and 2 CSS files). This is a public, GPL-licensed
source baseline separate from the production server, where the Extended
package is installed and the standalone Free plugin is absent. Archive
SHA-256: `b1852652a2c966a2419f7f2d7059ad97b296e1e136785f252260bafc2513e283`.

| Free subsystem | 1.7.1 source | Audited behavior and edition boundary |
| --- | --- | --- |
| Field registry | `includes/classes/class-fields.php::get_field_types()`; `views/frontend/fields/*.php` | Ten types are marked Free in the registry: text, textarea, number, email, URL, select, true/false, checkboxes, radio, and paragraph/content. The same registry marks file, date, image/color/text swatches, cards, image quantities, calculation, child products, HTML/shortcodes, image, and section as Pro. Extended fields register through the paid package's `wapf/field_types` filter. |
| Free pricing boundary | `includes/classes/class-fields.php::get_pricing_options()`; `pricing_value()`; `do_pricing()` | The Free package exposes only `fixed` flat-fee pricing. The same registry marks quantity-flat, formula, percentage, numeric-value, and character-count pricing as Pro-only. Choice pricing is resolved by submitted choice slug; the server computes its add-on from validated values. |
| Core field validation and conditions | `includes/classes/class-fields.php`; `includes/classes/class-conditions.php`; `includes/classes/class-field-groups.php` | Values are type-sanitized (textarea, number, email, true/false and choices have dedicated paths); required state depends on field conditions; selection values are matched to configured choice slugs. Free conditions include the documented value/empty/checked tests; Pro-only rule operators and product/user targets are marked separately in source configuration. |
| Product groups and persistence | `includes/classes/class-field-groups.php`; `includes/controllers/class-admin-controller.php`; `includes/controllers/class-product-controller.php` | Global field-group CPT records and product-local `_wapf_fieldgroup` data have separate load/save paths. Product and variation rendering, product assignment, conditions, and field values are resolved before storefront output. Free supports simple/variable products and Ajax variation selection; targeting exact variations is Pro-only. |
| Cart/order lifecycle | `includes/controllers/class-product-controller.php`; `includes/classes/class-fields.php`; `includes/classes/class-woocommerce-service.php` | The controller renders on product pages, validates required fields at add-to-cart, stores structured `_wapf_meta` data, applies server-side add-on prices before totals, displays values on cart/checkout, persists order-item metadata, and restores saved values for order-again. The Free package intentionally disables WooCommerce Ajax add-to-cart when fields are present; the premium package adds integrations for supported Ajax product-page implementations. |
| Admin, options, and localization | `includes/controllers/class-admin-controller.php`; `includes/classes/class-wapf-list-table.php`; `views/admin/field.php`; `assets/js/admin.min.js`; `includes/classes/class-l10n.php`; `languages/`; `wpml-config.xml` | Admin supports product-local and global group editing, assignment rules, global add-to-cart text settings, per-field duplication, and nonce/capability-checked global-group duplication with new field IDs and conditional-reference remapping. The package ships its own POT/locale catalogs and WPML configuration. The WordPress.org feature page describes Free UI translations and marks subscriptions/multicurrency, variation-specific fields, advanced pricing, and richer targeting as paid boundaries. |

Official Free source and boundary references: [WordPress.org plugin page and
changelog](https://wordpress.org/plugins/advanced-product-fields-for-woocommerce/)
and [official Pro/Extended tier comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/).
The Free readme's 1.6.22 changelog says Gift Card plugin integration was
improved, but neither plugin names nor the integration contract are specified;
the public 1.7.1 PHP source has no explicit gift-card adapter identifier. It
remains an unclassified integration until the target plugin/behavior is
identified from authoritative evidence; OPF compatibility must remain
unclaimed until then.
This Free source audit plus the installed Extended 3.1.5/Pro map documents the
available code baselines; it does not replace the still-open source audit of
current Extended 3.2.1 and Pro 3.2.2 packages.

## Published runtime compatibility floors

The Free 1.7.1 readme/plugin header declares WordPress 4.5+, PHP 7.0+, and
WooCommerce 6.0+. The current paid product page declares WordPress 6.0+, PHP
7.1+, and WooCommerce 7.0+. The installed Extended 3.1.5 header still says
WooCommerce 4.9+, while its current changelog says 3.1.7 raised the minimum to
7.0; the licensed 3.2.1 archive is needed to confirm its final metadata. These
declared support ranges are part of the compatibility baseline, alongside
capability parity.

## Current target version gap

This document audits the installed 3.1.5 package only. The current official
Extended changelog now lists 3.2.1, released 27 June 2026. The live site's
inactive 3.1.5 copy is a useful source baseline, but it cannot establish current
target behavior by itself. The 3.2.1 distribution source is not present in the
audited installation, so source-level review of the intervening releases is
still open. Do not use the 3.1.5 audit as the source-audit exit gate.

The official changelog delta that must be reconciled into the ledger is:

| Version | Published capability or behavior changes | Audit implications |
| --- | --- | --- |
| 3.1.6 | Cards with quantity inputs gained additional conditional-logic options; select tax handling was fixed | Inspect card quantity condition controls and saved settings, then compare selected-option tax behavior |
| 3.1.7 | Upload and order-admin deletion security hardening; output hardening; text-swatch corner-radius persistence fix; informational calculation result-format fix; modern uploader enabled by default; minimum WooCommerce version raised to 7.0 | `WAPF-FIELD-UPLOAD`: inspect upload and order-admin authorization/output paths. `WAPF-FIELD-CARDS`/style rows: inspect saved corner radius. Calculation row: check informational result format. Uploader row: reconcile default. G4: confirm WC 7.0 floor and security behavior. |
| 3.2 | True/false and checkbox switch presentation; checkbox columns; field-group title search; number step and whole/decimal validation; formula weight; styled-control accessibility; negative options-total formatting; active product-type filtering; skip validation for unsupported product types; iOS upload validation scroll fix | Reconcile setting keys/defaults and server validation for switch/columns/number rows; title-search row; formula-weight row; styled-control keyboard/screen-reader behavior; negative-total row; product-type query behavior; upload error focus on iOS. |
| Extended 3.2.1 | Image zoom for image+quantity and linked-product image fields; date-picker accessibility; auto-update fix; disabled-days save fix; option-discount tax fix; WordPress 7.0 admin CSS fixes | Inspect zoom data/settings; date control semantics, accessibility and disabled-day persistence; updater/package behavior; admin CSS; option-discount tax calculations. Map to image-quantity/child-image zoom, date, commerce tax, and release-readiness rows. |
| Pro 3.2.2 | Fixed a blank Product Fields admin page | Extended includes Pro features, but the versioned Extended package source must establish whether this Pro patch is bundled. Verify admin page load independently in the exact archive. |

Sources: [official Extended changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/),
[official Pro changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/changelog/),
and [official edition comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/).
The edition comparison confirms Extended includes Pro; the audit gate therefore
covers both Pro-core and Extended-only behavior. Pro's latest changelog is
3.2.2 while Extended's is 3.2.1, so exact package inclusion is an open
source-audit question. Six separately sold add-ons remain out of this scope.
The current official changelog pages were reread on 2026-09-30: Extended lists
3.2.1, Pro lists 3.2.2. This is a changelog-based release-delta review, not
source-level verification of either package. The Pro 3.2.2 blank-admin fix is
also a separate package-inclusion question even though Extended bundles Pro
features.
