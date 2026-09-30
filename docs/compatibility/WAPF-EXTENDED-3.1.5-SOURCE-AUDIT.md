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
| Global/local authoring and migration | `includes/controllers/class-admin-controller.php`; `includes/classes/class-field-groups.php`; `includes/classes/class-wapf-list-table.php`; `views/admin/field.php`; `views/admin/field-list.php`; `views/admin/layout.php`; `views/admin/conditions.php`; `views/admin/variable-builder.php`; `views/admin/tools.php`; `views/admin/modal.php`; `views/admin/cpt-list-table.php` | Product-local field groups and the global Field Groups post type have separate persistence/assignment paths. Admin code handles product tabs, group lists and scheduling, builder tabs, conditions, formula variables, duplication and ID remapping, settings/design screens, import/export tools, and list actions. Product-targeting and field IDs are persisted in WAPF's group/product data structures. |
| Storefront markup, design, and browser behavior | `includes/classes/class-html.php`; `includes/classes/class-design-helper.php`; `includes/controllers/class-product-controller.php`; `views/frontend/field-group.php`; `views/frontend/fields/*.php`; `assets/js/frontend.min.js`; `assets/js/datepicker.min.js`; `assets/css/frontend-*.min.css`; `assets/css/datepicker.min.css` | The renderer emits type-specific controls, labels, descriptions, price hints, conditional state, group layout, design classes, variation data, and pricing summary. Frontend JS handles choice state, condition updates, pricing previews, image switching, repeater controls, uploader interactions, and product quantity changes. Date picker behavior is in its dedicated script. |
| Cart validation, price calculation, order, and restore | `includes/classes/class-cart.php`; `includes/controllers/class-product-controller.php`; `includes/controllers/class-linked-products-controller.php`; `includes/controllers/class-public-controller.php` | The add-to-cart path reads raw field values, applies field/group/conditional and type-specific validation, recalculates price on the server, adds structured WAPF data to Woo cart lines, and renders configured values on cart/checkout/order surfaces. Source hooks also cover Store API data, cart edits, order metadata, order-again restoration, checkout validation, stock, and linked-child line handling. |
| File upload lifecycle | `includes/classes/class-file-upload.php`; `includes/controllers/class-public-controller.php`; `views/frontend/fields/file.php`; `assets/js/dropzone.min.js`; `assets/js/frontend.min.js` | Uploads have a dedicated Ajax and native-input path, allowed-type handling, size/count checks, storage-path protection files, cart-token validation, removal, order download/cleanup, and optional zip creation. The installed 3.1.5 implementation is the baseline; later security hardening/default changes are listed in the current-release delta above and remain to verify in the current package. |
| WooCommerce and theme/plugin adapters | `includes/controllers/class-integrations-controller.php`; `includes/classes/integrations/class-*.php` | Conditional adapters are registered for WooCommerce Subscriptions, WOOCS/FOX, Aelia, Woo Discount Rules, YITH Request a Quote, Tiered Pricing Table, WooCommerce Bookings, product tables, quick view, and named themes (Astra, Flatsome, Woodmart). Each adapter changes specific hooks for product type, price/currency, coupon, cart display, gallery, or quantity behavior; these are not one generic compatibility guarantee. |
| Localization and translation integration | `languages/sw-wapf.pot`; `languages/sw-wapf-*.mo`; `wpml-config.xml`; `includes/controllers/class-admin-controller.php`; `class-wapf.php` | Strings use the `sw-wapf` text domain with a POT and bundled locale catalogs. WAPF registers its global group post type for Polylang; WPML config marks selected admin text options, while the WPML guide describes translation of group CPTs and product/variation fields. |
| Vendor settings, licensing, and update boundary | `includes/classes/class-licensing.php`; `includes/controllers/class-admin-controller.php`; `includes/controllers/class-extended-controller.php`; `includes/classes/class-config.php` | The admin exposes global labels, upload/date behavior and date format, summary/design settings, product price display and plugin license/update UI. Extended controllers add fields/date/formula/weight/linked-product options on top of the shared Pro settings framework. Licensed distribution/update behavior is separate from OPF's source behavior and does not enter the FOSS compatibility license decision. |

This source map covers the installed package's subsystem boundaries. It does
not assert that OPF matches each behavior: capability equivalence, migration
semantics, runtime integrations, and current 3.2.1/3.2.2 release differences
remain tracked as separate acceptance work.

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
| 3.1.7 | Upload and order-admin deletion security hardening; text-swatch corner-radius persistence fix; informational calculation result-format fix; modern uploader enabled by default | Inspect upload and order-admin authorization, corner-radius serialization, calculation result formatting, and uploader defaults |
| 3.2 | True/false and checkbox switch presentation; checkbox columns; field-group title search; number step and whole/decimal validation; formula weight; styled-control accessibility; negative options-total formatting; active product-type condition filtering; upload validation fix | Inspect setting keys/defaults, server validation, formula-weight parsing, keyboard/screen-reader behavior, and the admin query/filter contract |
| Extended 3.2.1 | Image zoom for image+quantity and linked-product image fields; date-picker accessibility; disabled-days save fix; option-discount tax fix | Inspect zoom controls/data, date control semantics and persistence, and option-tax calculations |
| Pro 3.2.2 | Fixed a blank Product Fields admin page | Extended includes Pro features, but the versioned Extended package source must establish whether this Pro patch is bundled |

Sources: [official Extended changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/),
[official Pro changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/changelog/),
and [official edition comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/).
The edition comparison confirms Extended includes Pro; the audit gate therefore
covers both Pro-core and Extended-only behavior. Pro's latest changelog is
3.2.2 while Extended's is 3.2.1, so exact package inclusion is an open
source-audit question. Six separately sold add-ons remain out of this scope.
This is a changelog-based release-delta review, not source-level verification
of the current 3.2.1/3.2.2 packages.
