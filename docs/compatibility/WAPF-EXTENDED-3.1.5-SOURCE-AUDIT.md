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
separately for each capability. Pro details, the six separate add-ons, and
compatibility entries remain in the full OPF 1.0 objective.

Official formula inventory: [formula function reference](https://www.studiowombat.com/knowledge-base/formula-functions-reference/).
