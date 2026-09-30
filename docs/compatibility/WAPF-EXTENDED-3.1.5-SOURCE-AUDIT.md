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
| WooCommerce and theme/plugin adapters | `includes/controllers/class-integrations-controller.php`; 12 files under `includes/classes/integrations/` | The controller registers eight plugin adapters and three theme adapters. A WooCommerce Bookings adapter class also exists, but is absent from the controller's registry and no instantiation call exists in the package search; its effective runtime status is unresolved. Exact source scope recorded below. |
| Localization and translation integration | `languages/sw-wapf.pot`; `languages/sw-wapf-*.mo`; `wpml-config.xml`; `includes/controllers/class-admin-controller.php`; `class-wapf.php` | Strings use the `sw-wapf` text domain with a POT and bundled locale catalogs. WAPF registers its global group post type for Polylang; WPML config marks selected admin text options, while the WPML guide describes translation of group CPTs and product/variation fields. |
| Vendor settings, licensing, and update boundary | `includes/classes/class-licensing.php`; `includes/controllers/class-admin-controller.php`; `includes/controllers/class-extended-controller.php`; `includes/classes/class-config.php` | The admin exposes global labels, upload/date behavior and date format, summary/design settings, product price display and plugin license/update UI. Extended controllers add fields/date/formula/weight/linked-product options on top of the shared Pro settings framework. Licensed distribution/update behavior is separate from OPF's source behavior and does not enter the FOSS compatibility license decision. |
| Developer extension API | PHP `apply_filters()` / `do_action()` calls under `includes/`, `extend/`, and `class-wapf.php`; `includes/api/api-helpers.php` | Static source inventory found 92 unique literal `wapf/...` filter names, 8 unique literal `wapf/...` action names, and 12 global helper functions in installed 3.1.5. Hook names cover field registration/rendering, validation, formula/pricing, cart/order, upload, linked products, admin screens, and integrations. Helper API is explicitly labeled beta in source and spans settings, custom formula functions, field-group display/lookups, cart/order reads, and field-group serialization. Counts do not define a stable documented API. Current-package signatures/arguments and OPF compatibility remain open. |

This source map covers the installed package's subsystem boundaries. It does
not assert that OPF matches each behavior: capability equivalence, migration
semantics, runtime integrations, and current 3.2.1/3.2.2 release differences
remain tracked as separate acceptance work.

The 3.1.5 literal-hook inventory seeds `WAPF-DEVELOPER-HOOKS` in the ledger;
it does not prove every hook is public, supported, or unchanged in 3.2.1. OPF's
separately namespaced filters need an explicit compatibility contract and
documentation before this row can count as parity.

The beta helper API is a separate capability from action/filter extension
points. OPF's current global functions are lifecycle bootstrap/activation
entrypoints only; static search found no corresponding public field-group,
cart/order, settings, serialization, or custom-formula-function helpers.
The ledger records this as a separate gap. WAPF signatures and behavior need
rechecking against current Extended source before treating the 3.1.5 API as a
fixed compatibility target.

### Installed 3.1.5 beta PHP helper signatures

The declarations and behavior below come from
`includes/api/api-helpers.php`. The file labels this API “BETA - PLEASE USE AT
OWN RISK AS API CAN CHANGE IN FUTURE UPDATES.” These are historical 3.1.5
facts, not a promise that Extended 3.2.1 preserves them.

| Function | Installed signature | Observed return/side effect |
| --- | --- | --- |
| `wapf_has_setting` | `wapf_has_setting( $name = '' )` | Forwards to `wapf_pro()->has_setting( $name )`. |
| `wapf_get_setting` | `wapf_get_setting( $name, $value = null )` | Reads the setting; uses `$value` only when the stored result is `null`; then applies `wapf/setting/{$name}` to the result. |
| `wapf_add_formula_function` | `wapf_add_formula_function( $func, $callback )` | Registers the callback through `Helper::add_formula_function`; no explicit return. |
| `wapf_display_field_groups_for_product` | `wapf_display_field_groups_for_product( $product )` | Returns `''` when no groups are found; otherwise returns rendered markup from `Html::display_field_groups`. |
| `wapf_product_has_options` | `wapf_product_has_options( $product )` | Forwards to `Field_Groups::product_has_field_group`. |
| `wapf_get_field_groups_of_product` | `wapf_get_field_groups_of_product( $product )` | Forwards to `Field_Groups::get_field_groups_of_product`. |
| `wapf_get_field_groups_by_ids` | `wapf_get_field_groups_by_ids( $ids = [] )` | Forwards to `Field_Groups::get_by_ids`. |
| `wapf_get_field_group_by_id` | `wapf_get_field_group_by_id( $id )` | Forwards to `Field_Groups::get_by_id`. |
| `wapf_get_options_from_order` | `wapf_get_options_from_order($order): array` | Accepts an order object or passes the argument to `wc_get_order`; returns line-item records (`product_id`, `item_id`, `quantity`, and option records with field ID, label, value, optional type). It skips linked-product fields when those are separate cart lines. No failed-order guard appears before `get_items()`. |
| `wapf_get_custom_fields_in_cart` | `wapf_get_custom_fields_in_cart()` | Returns `[]` when WooCommerce/cart is unavailable; otherwise cart-item records with cart key, product ID, and matched field ID/label/value records. |
| `wapf_fieldgroup_to_array` | `wapf_fieldgroup_to_array( FieldGroup $fg )` | Returns `$fg->to_array()`. |
| `wapf_array_to_fieldgroup` | `wapf_array_to_fieldgroup( array $a )` | Creates a `FieldGroup`, calls `from_array( $a )`, and returns it. |

Only the last two declarations type their inputs, and only the order helper
declares a return type. The order/cart helpers expose WAPF's `_wapf_meta` and
`wapf` storage conventions, so matching function names alone would not provide
API compatibility. `WAPF-DEVELOPER-PHP-API` remains a known OPF gap; current
3.2.1 signatures and migration guarantees await the licensed package.

### Installed 3.1.5 integration adapter inventory

The controller conditionally registers eight plugin adapters and three theme
adapters. A separate Woo Bookings adapter class exists in the same directory,
but this audit found no package code that registers or instantiates it. Its
source behavior is therefore recorded as a candidate capability, not a
confirmed active WAPF feature. This inventory was compared with existing feature and
compatibility rows. It covers the installed 3.1.5 package only; adapter labels
or behavior may differ in Extended 3.2.1.

| Source adapter | Detected behavior | Ledger mapping |
| --- | --- | --- |
| `class-aelia.php` | Converts product/variation bases, formula bases, linked-product choice prices, pricing hints, option totals, and browser totals to the active Aelia currency. | `WAPF-CURRENCY-AELIA` |
| `class-astra.php` | Reinitializes WAPF frontend behavior in Astra quick-view modal. | `WAPF-COMPAT-ASTRA` |
| `class-flatsome.php` | Reinitializes fields and pricing in Flatsome quick view. | `WAPF-COMPAT-FLATSOME` |
| `class-product-table.php` | Initializes fields for Barn2 product-table rows and synthesizes variation data for individually listed variations. | `WAPF-COMPAT-PRODUCT-TABLE` |
| `class-quickview.php` | Initializes Barn2 Quick View Pro fields, serializes field inputs during Ajax add-to-cart, and hides duplicate totals in modal. | `WAPF-COMPAT-QUICK-VIEW-PRO` |
| `class-tiered-pricing-table.php` | Uses active tier-rule prices in browser and cart; honors variation and summarized-quantity rules; adds option prices to tiered cart display. | `WAPF-COMPAT-QUANTITY-RULES` (vendor/name mapping requires current docs review) |
| `class-woo-discount-rules.php` | Uses discount-plugin prices for product, variation, and cart bases; includes WAPF option prices in discount calculations; suppresses WAPF validation for generated free cart items. | `WAPF-COMPAT-WC-DISCOUNTS` |
| `class-woocommerce-bookings.php` | Class implementation adds booking product groups; per-field repeat-by-person-type settings; frontend clone/removal by person count; person-cost-multiplier pricing; and restrictions on pricing/repeater options. But `Integrations_Controller::$available_integrations` does not list this class and package search found no other instantiation. Effective runtime support is unverified. | `WAPF-PRODUCT-BOOKINGS` (new `needs audit` row) |
| `class-woocommerce-subscriptions.php` | Supports subscription product types and prices; updates variable-subscription browser base; skips field validation during early and regular renewal cart setup. | `WAPF-PRODUCT-SUBSCRIPTION` |
| `class-woocs.php` | Converts simple/variable bases, formulas, linked products, hints, and cart totals through WOOCS rates/back-conversion; updates browser currency formatting. | `WAPF-CURRENCY-WOOCS`, `WAPF-CURRENCY-FOX` (FOX equivalence still unverified) |
| `class-woodmart.php` | Reinitializes fields in Woodmart quick view; adjusts edit-cart redirect and product-gallery image classes/events. | `WAPF-COMPAT-WOODMART` |
| `class-yith-raq.php` | Carries validated fields/files/pricing into YITH quote requests, quote display/email/orders, quote-to-cart restoration, quantity behavior, and acceptance flow. | `WAPF-COMPAT-YITH-QUOTE` |

This pass found a distinct WooCommerce Bookings implementation absent from the
edition ledger, then found that installed 3.1.5 does not register it. OPF has
no Bookings-specific builder or person-type repeat and price bridge. Ledger
keeps this as `needs audit` until the supported WAPF runtime behavior is
established. Other adapter behaviors map to existing currency/product/
integration rows or the separate compatibility matrix; exact current-package
reconciliation remains part of G1.

### Installed 3.1.5 field-definition registry

`SW_WAPF_Config::get_field_definitions()` in
`includes/classes/class-config.php` contains 31 unique definition keys across
its frontend/admin definitions. Repeated keys are shared by those modes, not
additional field types. This is the exact source-level mapping to the current
ledger families:

| Registry keys | Ledger family |
| --- | --- |
| `text`, `textarea`, `number`, `email`, `url`, `select`, `checkboxes`, `radio`, `true-false` | `WAPF-FIELD-TEXT`, `WAPF-FIELD-TEXTAREA`, `WAPF-FIELD-NUMBER`, `WAPF-FIELD-EMAIL`, `WAPF-FIELD-URL`, `WAPF-FIELD-SELECT`, `WAPF-FIELD-CHECKBOX`, `WAPF-FIELD-RADIO`, `WAPF-FIELD-TOGGLE` |
| `image-swatch`, `multi-image-swatch`, `image-swatch-qty`, `color-swatch`, `multi-color-swatch`, `text-swatch`, `multi-text-swatch` | `WAPF-FIELD-SWATCH-IMAGE`, `WAPF-FIELD-SWATCH-MULTI`, `WAPF-FIELD-IMAGE-QUANTITIES`, `WAPF-FIELD-SWATCH-COLOUR` |
| `card`, `vcard` | `WAPF-FIELD-CARDS` |
| `products-checkbox`, `products-radio`, `products-dropdown`, `products-image`, `products-card`, `products-vcard`, `products-vcard-qty`, `products-card-qty` | `WAPF-FIELD-CHILD-PRODUCTS` and its category-pricing and image-zoom rows |
| `file` | `WAPF-FIELD-UPLOAD`, `WAPF-UPLOAD-AJAX-UI` |
| `p`, `img`, `section`, `sectionend` | `WAPF-FIELD-CONTENT-TEXT`, `WAPF-FIELD-CONTENT-IMAGE`, `WAPF-FIELD-SECTION` |

Two related registrations are conditional or owned by other controllers and
must not be omitted from the full inventory: date is added only when the
`wapf_datepicker` setting is enabled; Extended registers `calc` through
`wapf/field_types` in `class-extended-controller.php`.

HTML and shortcode support are not separate WAPF field types. Free 1.7.1
registers `content` (with legacy `paragraph`) and stores plain text in
`p_content`; `class-field-groups.php` sanitizes the value as text and the
renderer escapes it. The Free field-options description explicitly presents
HTML and shortcodes as Pro upgrade behavior. Pro/Extended registers the `p`
“Text & HTML” field using the same `p_content` option, applies a minimal HTML
allowlist, then calls `do_shortcode()`. The `paragraph.php` view explicitly
exists to take over the Free paragraph view. The official field-types guide
also describes content fields and shortcodes as capabilities, not distinct
field types. These are three separately trackable edition behaviors—Free plain
text, Pro limited HTML, and Pro shortcode execution—but all map to the same
content-field data path. They are represented by `WAPF-FIELD-CONTENT-TEXT`,
`WAPF-FIELD-CONTENT-HTML`, and `WAPF-FIELD-SHORTCODE`; none should be
described or imported as a standalone WAPF `html` or `shortcode` field type.

OPF's ledger previously mislabeled the content rows as separate WAPF admin
field types and assumed a WAPF `shortcode` type during import. The row mapping
is corrected in the ledger: Free `content`/legacy `paragraph` is plain
text, while Pro HTML and shortcode behaviors use the `p` content type and
`p_content` option. Migration parity remains open until that payload is mapped
and verified.

`SW_WAPF_Config::get_pricing_options()` separately registers the general
`fixed`, `qt`, `p`, `percent`, and `fx` price types; text-like fields add
`char`/`charq`, while number and image-quantity fields add `nr`/`nrq`. These
map to the flat, quantity-flat, percentage, quantity-percentage, formula,
character-count and numeric-value pricing rows in the ledger. This closes the
installed 3.1.5 registry inventory only; equivalence and current 3.2.1 changes
remain release- and behavior-level audit work.

### Installed 3.1.5 field-group targeting conditions

`SW_WAPF_Config::get_fieldgroup_visibility_conditions()` registers these
placement families in `includes/classes/class-config.php`; the server
evaluator is `SW_WAPF_PRO\Includes\Classes\Conditions::check()` in
`includes/classes/class-conditions.php`:

| Source keys and behavior | Source implementation | Ledger row |
| --- | --- | --- |
| `auth` / `!auth`: logged in / logged out | `is_user_logged_in()` | `WAPF-RULE-AUTH` (new row) |
| `role` / `!role`: user has / does not have a selected role | `get_editable_roles()` populates the builder; `wp_get_current_user()->roles` is checked server-side | `WAPF-RULE-ROLE` (new row) |
| `product` / `!product`: selected products | Product lookup condition and Woo product IDs | `WAPF-RULE-PRODUCT` |
| `product_var` / `!product_var`: selected variations | Variation lookup and product relationship | `WAPF-RULE-VARIATION` |
| `product_cats` / `!product_cats`: product categories | Product/category membership; legacy `product_cat` values are normalized | `WAPF-RULE-CATEGORY` |
| `patts` / `!patts`: attribute terms, optionally any term via `*` | Product attribute resolver | `WAPF-RULE-ATTRIBUTE` |
| `p_tags` / `!p_tags`: product tags | Product-tag resolver | `WAPF-RULE-TAG` |
| `product_type` / `!product_type`: simple, variable, or grouped | Product-type resolver; 3.2 later filters admin choices to active/allowed types | `WAPF-RULE-TYPE` |
| `lang` / `!lang`: current language | `Helper::get_available_languages()` and `get_current_language()` support Polylang and WPML; `Conditions::current_language_is()` compares the selected language | `WAPF-RULE-LANGUAGE` (new row) |

The Free and installed OPF group-assignment rows already cover product,
variation, category, attribute, tag, type, and exclusion targeting. The Pro
source adds the three user/context conditions above. A repository-wide search
of OPF's `includes` and `assets/js` finds Polylang post-language assignment,
but no login-state, role, or group-language condition in its builder/evaluator;
the ledger therefore records those three as gaps. The exact latest-package
source is still required to confirm that these 3.1.5 keys and semantics remain
unchanged in Extended 3.2.1.

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

### Current package recovery check — 2026-09-30

Read-only filename search across the server found no Extended 3.2.1 archive
(excluding virtual `/proc`, `/sys`, `/dev`, `/run`, and Docker storage).
The three inspected Ploi site backups
dated 2026-04-12, 2026-08-18, and 2026-08-31 each contain the Extended plugin
header for 3.1.5. The three ZIPs in the WordPress uploads root contain no
WAPF plugin paths. A fresh
WP-CLI read at 2026-09-30 12:18 UTC reports installed version 3.1.5, inactive,
and no cached update entry. The official paid-plugin install guide directs
license holders to sign into their Studio Wombat account and download the
latest version available to their license; no unauthenticated package source
was found. Thus the 3.2.1 source remains unavailable here. This check does
not authorize or attempt account access, license changes, or activation.

### Current changelog-to-ledger crosswalk

This crosswalk is limited to published changelog evidence. It maps every
listed 3.1.6–3.2.2 change to its current acceptance row or release gate; it
does not establish the 3.2.1 package's implementation, stored keys, defaults,
or bundled Pro contents.

| Release change | Existing ledger row(s) or release gate | Audit disposition |
| --- | --- | --- |
| Extended 3.1.6: card quantity fields gain conditional options | `WAPF-FIELD-CARDS-QUANTITY-CONDITIONALS`, `WAPF-RULE-CONDITIONAL` | Dedicated ledger row exists and remains `needs audit`; current package source must establish the exact controls, stored keys, and evaluation behavior. |
| Extended 3.1.6: select tax calculation fix | `WAPF-COMMERCE-TAX` | Covered as a tax behavior; exact current-package path and regression remain unverified. |
| Extended 3.1.7: upload/order-admin deletion and output hardening | `WAPF-FIELD-UPLOAD`; G4 security | Audit authorization, file ownership, path handling, and escaped output in current source; verify independently before release. |
| Extended 3.1.7: text-swatch corner-radius persistence | `WAPF-FIELD-SWATCH-TEXT` | Covered; compare setting round-trip against the current package. |
| Extended 3.1.7: informational calculation result format | `WAPF-FIELD-CALCULATION` | Covered; preserve the distinction between informational formatting and price calculation. |
| Extended 3.1.7: modern uploader enabled by default | `WAPF-UPLOAD-AJAX-UI` | Covered; current package default and explicit native fallback remain unverified. |
| Extended 3.1.7: minimum WooCommerce 7.0 | `WAPF-COMPAT-MINIMUM-PLATFORM` | Covered as a release compatibility floor; exact current header still needs package inspection. |
| Extended 3.2: switch style | `WAPF-FIELD-TRUE-FALSE-SWITCH` | Covered; current package setting/default and rendering remain to compare. |
| Extended 3.2: checkbox columns | `WAPF-FIELD-CHECKBOX-COLUMNS` | Covered; current package setting, accepted range, responsive behavior and accessibility remain to compare. |
| Extended 3.2: field-group title search | `WAPF-GROUP-ADMIN-TITLE-SEARCH` | Covered; compare list query behavior and search controls. |
| Extended 3.2: number step and whole/decimal validation | `WAPF-FIELD-NUMBER-STEP-VALIDATION` | Covered; compare stored mode/default, browser constraints and server validation. |
| Extended 3.2: formula-based weight | `WAPF-PRICE-FORMULA-WEIGHT`, `WAPF-COMMERCE-WEIGHT` | Covered; formula grammar, references, units and cart/shipping lifecycle remain source-audit items. |
| Extended 3.2: active product-type filtering | `WAPF-RULE-TYPE` | Covered as product-type targeting; compare which registered/active types the current admin permits. |
| Extended 3.2: styled-control accessibility | `WAPF-FIELD-STYLED-CHECKBOX-RADIO` | Covered; current markup, keyboard behavior and accessibility semantics remain to compare. |
| Extended 3.2: negative options-total sign formatting | `WAPF-PRICE-OPTIONS-TOTAL-NEGATIVE-FORMAT` | Covered as display behavior; verify negative-value formatting and currency placement. |
| Extended 3.2: skip validation for unsupported product types | G3 server validation and product-type lifecycle | Performance/validation behavior, not a standalone customer capability row; verify unsupported types fail closed without imposing WAPF's skipped validation path on supported types. |
| Extended 3.2: iOS upload validation scroll | `WAPF-UPLOAD-AJAX-UI`; G3 browser proof | Covered as an upload error-focus behavior; verify on a real iOS browser before claiming parity. |
| Extended 3.2.1: zoom for image+quantity and linked-product images | `WAPF-FIELD-IMAGE-QUANTITY-ZOOM`, `WAPF-FIELD-CHILD-PRODUCTS-IMAGE-ZOOM` | Both rows exist; compare current setting keys, defaults, hover and keyboard-focus behavior. |
| Extended 3.2.1: date-picker accessibility improvement | `WAPF-DATE-ACCESSIBILITY` | Dedicated ledger row exists and remains `needs audit`; compare names, focus order, keyboard controls, and announcements against the exact package. |
| Extended 3.2.1: auto-update fix | G4 packaging/update reliability | Release reliability rather than a storefront feature row; inspect updater code and verify package update behavior without exposing license data. |
| Extended 3.2.1: disabled-days persistence fix | `WAPF-DATE-WEEKDAYS` | Covered; compare saved defaults and reload behavior in the current package. |
| Extended 3.2.1: option-discount tax fix | `WAPF-COMMERCE-TAX` | Covered; current tax/coupon interaction needs source and commerce-path proof. |
| Extended 3.2.1: WordPress 7.0 admin CSS fixes | G4 supported-platform/admin compatibility | Release compatibility behavior; verify current admin screens at the claimed WordPress floor and WordPress 7.0. |
| Pro 3.2.2: blank Product Fields admin-page fix | G1 package-inclusion check; G4 admin reliability | No separate customer capability row. Inspect the exact Extended archive to determine whether it includes the fix, then verify the Product Fields screen loads. |

This review found two release-delta rows missing from the prior edition
inventory: card quantity conditional settings and date-picker accessibility.
Both are explicit `needs audit` rows. The installed-source audit also found the
WooCommerce Bookings adapter, now represented as a separate Pro capability
row. Together with three separately verified Pro group-target conditions
(login state, user role, and current language), plus the unregistered Bookings
class requiring runtime classification and the beta PHP helper API, the edition
ledger now has 132 rows. The official descriptions do not disclose the release rows'
exact setting
keys or complete behavior, so only the licensed current package can close
those source questions.
