# WAPF capability ledger

This is OPF's acceptance ledger for replacing WAPF Free, Pro, Extended, and
the six current official add-ons. Each ID is a stable fixture placeholder.
`baseline supported` is evidence from the 2026-09-19 OPF replacement plan,
not a public parity claim; a fixture must promote it to `supported`,
`supported with documented difference`, or `not yet supported`. `gap` is
known absent in that baseline; `needs audit` has no established result.

## Official source index

| Key | Official source | Evidence |
| --- | --- | --- |
| `PRODUCT` | [WAPF product page](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/) | Pro fields, pricing, placement, products, cart edit, localization, currency, repeaters. |
| `TIERS` | [WAPF tier comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/) | Extended-only fields, functions, date restrictions, weight, swatch zoom. |
| `ADDONS` | [WAPF official add-ons](https://www.studiowombat.com/advanced-product-fields-addons/) | The six add-ons and user-visible behavior. |
| `INTEGRATIONS` | [WAPF compatibility matrix](https://www.studiowombat.com/knowledge-base/which-plugins-and-themes-are-compatible-with-advanced-product-fields-for-woocommerce/) | Named integrations and WAPF's labels. |
| `FREE` | [WAPF Free directory page](https://wordpress.org/plugins/advanced-product-fields-for-woocommerce/) | Free inputs, tax, targeting, Ajax variations, translations, limitations. |

## Fields, rules, and interaction

| ID | WAPF capability | Tier | Source | OPF module | Status | Fixture | Required behavior |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `WAPF-FIELD-TEXT` | Single-line text | Free | `FREE` | Field registry | baseline supported | `capability/WAPF-FIELD-TEXT` | Required/default validation; cart, order, email, order-again. |
| `WAPF-FIELD-TEXTAREA` | Multi-line text | Free | `FREE` | Field registry | baseline supported | `capability/WAPF-FIELD-TEXTAREA` | Safe newline preservation. |
| `WAPF-FIELD-EMAIL` | Email with invalid-input error | Free | `FREE`, `PRODUCT` | Field registry | supported | `capability/WAPF-FIELD-EMAIL` | Server rejects malformed input. |
| `WAPF-FIELD-URL` | URL with invalid-input error | Free | `FREE`, `PRODUCT` | Field registry | baseline supported | `capability/WAPF-FIELD-URL` | Validation and safe output. |
| `WAPF-FIELD-NUMBER` | Number with min/max and decimal/whole restrictions | Free | `FREE`, `PRODUCT` | Field registry | baseline supported | `capability/WAPF-FIELD-NUMBER` | Server bounds and precision. |
| `WAPF-FIELD-TOGGLE` | True/false toggle | Free | `FREE`, `PRODUCT` | Field registry | supported | `capability/WAPF-FIELD-TOGGLE` | Checked is stored as `1`, unchecked as `0`; a required toggle must be checked. |
| `WAPF-FIELD-SELECT` | Dropdown/select | Free | `FREE`, `PRODUCT` | Field registry | baseline supported | `capability/WAPF-FIELD-SELECT` | Published-option validation. |
| `WAPF-FIELD-CHECKBOX` | Checkboxes with selection limits | Free | `FREE`, `PRODUCT` | Field registry | baseline supported | `capability/WAPF-FIELD-CHECKBOX` | Server min/max selection limits. |
| `WAPF-FIELD-RADIO` | Radio group | Free | `FREE`, `PRODUCT` | Field registry | baseline supported | `capability/WAPF-FIELD-RADIO` | Keyboard-operable exclusive choice. |
| `WAPF-FIELD-PARAGRAPH` | Static paragraph | Free | `FREE` | Content/layout | gap | `capability/WAPF-FIELD-PARAGRAPH` | Sanitized, non-submitted content. |
| `WAPF-FIELD-UPLOAD` | Single/multiple file upload | Pro | `PRODUCT` | Upload service | gap | `capability/WAPF-FIELD-UPLOAD` | Count/type/size/access controls. |
| `WAPF-FIELD-DATE` | Date picker and date format | Pro | `PRODUCT` | Date field | partial | `capability/WAPF-FIELD-DATE` | OPF exposes a native date input with a WAPF-style configurable display format, keeps submitted dates canonical ISO, formats calendar and cart/checkout/order presentation, supports past/future toggles and static or relative min/max boundaries, and rejects impossible, disallowed, out-of-range, or non-ISO dates server-side. Remaining: WAPF option import and `[field.id]` bound mapping, and full live cart/order proof. |
| `WAPF-DATE-WEEKDAYS` | Disable selected weekdays | Extended | `DATE` | Date field and date picker | partial | `capability/WAPF-DATE-WEEKDAYS` | Builder stores weekday numbers, calendar disables matching dates, and server validation rejects submitted disabled weekdays. Runtime cart/order E2E remains required. |
| `WAPF-DATE-DISABLED-DATES` | Disable explicit dates and date ranges | Extended | `DATE` | Date field and date picker | partial | `capability/WAPF-DATE-DISABLED-DATES` | Exact ISO dates, recurring MM-DD dates, ISO inclusive ranges, and recurring ranges (including year wrapping) are disabled in the calendar and rejected server-side. WAPF import mapping and runtime cart/order E2E remain required. |
| `WAPF-DATE-CUTOFF` | Disable same-day selection after configured time | Extended | `DATE` | Date field, date picker, server validation | partial | `capability/WAPF-DATE-CUTOFF` | Builder accepts a site-local 24-hour cutoff; the calendar disables today after that time and server validation rejects forged same-day values. WAPF import mapping and cart/order E2E remain required. |
| `WAPF-DATE-CUTOFF` | Disable same-day selection after configured time | Extended | `DATE` | Date field, date picker, server validation | partial | `capability/WAPF-DATE-CUTOFF` | Builder accepts a local 24-hour cutoff; the calendar disables today after that time and server validation rejects forged same-day values. Site timezone is explicit. WAPF import mapping and cart/order E2E remain required. |
| `WAPF-FIELD-SWATCH-TEXT` | Text swatches | Pro | `PRODUCT` | Choice presentation | baseline supported | `capability/WAPF-FIELD-SWATCH-TEXT` | Accessible choices and validation. |
| `WAPF-FIELD-SWATCH-COLOUR` | Colour swatches | Pro | `PRODUCT` | Choice presentation | gap | `capability/WAPF-FIELD-SWATCH-COLOUR` | Accessible name and selected state. |
| `WAPF-FIELD-SWATCH-IMAGE` | Image swatches | Pro | `PRODUCT` | Choice presentation | gap | `capability/WAPF-FIELD-SWATCH-IMAGE` | Alternative text and validation. |
| `WAPF-FIELD-CONTENT-TEXT` | Informative text | Pro | `PRODUCT` | Content/layout | gap | `capability/WAPF-FIELD-CONTENT-TEXT` | Sanitized non-submitted content. |
| `WAPF-FIELD-CONTENT-IMAGE` | Informative image | Pro | `PRODUCT` | Content/layout | gap | `capability/WAPF-FIELD-CONTENT-IMAGE` | Responsive image and alt policy. |
| `WAPF-FIELD-CONTENT-HTML` | Admin HTML | Pro | `PRODUCT` | Content/layout | gap | `capability/WAPF-FIELD-CONTENT-HTML` | Capability-gated sanitized output. |
| `WAPF-FIELD-SHORTCODE` | Admin shortcode | Pro | `PRODUCT` | Content/layout | gap | `capability/WAPF-FIELD-SHORTCODE` | Explicit allow-list/context. |
| `WAPF-FIELD-CARDS` | Selectable cards/layouts | Extended | `TIERS`, `PRODUCT` | Choice presentation | gap | `capability/WAPF-FIELD-CARDS` | Semantic keyboard selection. |
| `WAPF-FIELD-CHILD-PRODUCTS` | Linked child products with option stock | Extended | `TIERS`, `PRODUCT` | Product composition | gap | `capability/WAPF-FIELD-CHILD-PRODUCTS` | Child stock, price, tax, order lines. |
| `WAPF-FIELD-CALCULATION` | Informational calculation | Extended | `TIERS`, `PRODUCT` | Pricing/calculation | gap | `capability/WAPF-FIELD-CALCULATION` | Safe recalculation from valid values. |
| `WAPF-FIELD-IMAGE-QUANTITIES` | Image choices with quantities | Extended | `TIERS`, `PRODUCT` | Product composition | gap | `capability/WAPF-FIELD-IMAGE-QUANTITIES` | Per-image quantity validation. |
| `WAPF-FIELD-SWATCH-IMAGE-ZOOM` | Zoom image swatch | Extended | `TIERS` | Choice presentation | gap | `capability/WAPF-FIELD-SWATCH-IMAGE-ZOOM` | Pointer/keyboard enlarged image. |
| `WAPF-RULE-CONDITIONAL` | Show/hide by other field value | Free | `FREE`, `PRODUCT` | Rules engine | baseline supported | `capability/WAPF-RULE-CONDITIONAL` | Progressive UI; server ignores unavailable data. |
| `WAPF-RULE-GLOBAL` | Reuse group across products | Free | `PRODUCT` | Group assignment | needs audit | `capability/WAPF-RULE-GLOBAL` | Consistent target resolution. |
| `WAPF-RULE-PRODUCT` | Product targeting | Free | `FREE`, `PRODUCT` | Group assignment | baseline supported | `capability/WAPF-RULE-PRODUCT` | Selected products only. |
| `WAPF-RULE-VARIATION` | Variation targeting | Pro | `PRODUCT`, `FREE` | Group assignment | needs audit | `capability/WAPF-RULE-VARIATION` | Variation context validation. |
| `WAPF-RULE-CATEGORY` | Category targeting | Free | `PRODUCT` | Group assignment | needs audit | `capability/WAPF-RULE-CATEGORY` | Correct membership resolution. |
| `WAPF-RULE-TAG` | Tag targeting | Free | `PRODUCT` | Group assignment | needs audit | `capability/WAPF-RULE-TAG` | Correct membership resolution. |
| `WAPF-RULE-TYPE` | Product-type targeting | Free | `PRODUCT` | Group assignment | needs audit | `capability/WAPF-RULE-TYPE` | Correct Woo type resolution. |
| `WAPF-RULE-EXCLUSION` | Target exclusions | Free | `PRODUCT` | Group assignment | needs audit | `capability/WAPF-RULE-EXCLUSION` | Deterministic exclusion precedence. |
| `WAPF-INTERACTION-REPEAT` | Customer-added repeatable fields | Pro | `PRODUCT` | Repeater engine | gap | `capability/WAPF-INTERACTION-REPEAT` | Add/remove, validate, preserve order data. |
| `WAPF-INTERACTION-QUANTITY-REPEAT` | Repeat by desired quantity | Pro | `PRODUCT` | Repeater engine | gap | `capability/WAPF-INTERACTION-QUANTITY-REPEAT` | Quantity changes preserve valid data. |
| `WAPF-INTERACTION-IMAGE-CHANGE` | Change main product image | Pro | `PRODUCT` | Gallery bridge | gap | `capability/WAPF-INTERACTION-IMAGE-CHANGE` | Restore image after selection change. |
| `WAPF-INTERACTION-CART-EDIT` | Edit product data in cart | Pro | `PRODUCT` | Cart lifecycle | needs audit | `capability/WAPF-INTERACTION-CART-EDIT` | Recalculate without identity loss. |

## Pricing, lifecycle, and locale

| ID | WAPF capability | Tier | Source | OPF module | Status | Fixture | Required behavior |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `WAPF-PRICE-FLAT` | Signed fixed fee | Free | `FREE`, `PRODUCT` | Pricing engine | baseline supported | `capability/WAPF-PRICE-FLAT` | Server-authoritative breakdown. |
| `WAPF-PRICE-PERCENT` | Percentage price change | Pro | `PRODUCT` | Pricing engine | baseline supported | `capability/WAPF-PRICE-PERCENT` | Correct base, rounding, tax, sales. |
| `WAPF-PRICE-QUANTITY` | Quantity fee | Pro | `PRODUCT` | Pricing engine | gap | `capability/WAPF-PRICE-QUANTITY` | Correct multiplication. |
| `WAPF-PRICE-CHARACTERS` | Amount × character count | Pro | `PRODUCT` | Pricing engine | gap | `capability/WAPF-PRICE-CHARACTERS` | Defined Unicode count. |
| `WAPF-PRICE-VALUE` | Amount × number value | Pro | `PRODUCT` | Pricing engine | gap | `capability/WAPF-PRICE-VALUE` | Bounded deterministic total. |
| `WAPF-PRICE-FORMULA` | Formula pricing | Pro | `PRODUCT` | Pricing engine | baseline supported | `capability/WAPF-PRICE-FORMULA` | Sandboxed client/server-equivalent result. |
| `WAPF-PRICE-MATRIX` | Lookup-table/matrix pricing | Pro | `PRODUCT` | Pricing engine | gap | `capability/WAPF-PRICE-MATRIX` | Match/fallback and reportable miss. |
| `WAPF-PRICE-FORMULA-ADVANCED` | Math and conditional formula functions | Extended | `TIERS` | Pricing engine | partial | `capability/WAPF-PRICE-FORMULA-ADVANCED` | OPF mirrors WAPF `dow()` (Sunday=0) and `month()` (1–12) on server and browser. Accepts strict ISO dates, configured WAPF date formats, `[val]`, validated sibling date-field references, and `today()` from the WordPress site date; invalid dates fail closed. PHPUnit and Node tests plus `bin/e2e-date-formula-reference-test.php` cart/order proof pass. The rest of Extended formula functions remain gaps. |
| `WAPF-PRICE-TOTAL-DISPLAY` | Pre-cart configured total | Pro | `PRODUCT` | Storefront pricing | needs audit | `capability/WAPF-PRICE-TOTAL-DISPLAY` | Matches server total. |
| `WAPF-COMMERCE-TAX` | WooCommerce tax settings | Free | `FREE`, `PRODUCT` | Pricing engine | needs audit | `capability/WAPF-COMMERCE-TAX` | Tax class and display totals match Woo. |
| `WAPF-COMMERCE-WEIGHT` | Final product weight change | Extended | `TIERS` | Shipping bridge | gap | `capability/WAPF-COMMERCE-WEIGHT` | Delta reaches shipping rates. |
| `WAPF-LIFECYCLE-CART-ORDER` | Cart/checkout display and order save | Free | `FREE` | Cart/order integration | baseline supported | `capability/WAPF-LIFECYCLE-CART-ORDER` | Classic and blocks survive to order. |
| `WAPF-PRODUCT-SIMPLE` | Simple products | Free | `PRODUCT` | Woo product bridge | needs audit | `capability/WAPF-PRODUCT-SIMPLE` | Full lifecycle. |
| `WAPF-PRODUCT-VARIABLE` | Variable products and Ajax variations | Free | `PRODUCT`, `FREE` | Woo product bridge | needs audit | `capability/WAPF-PRODUCT-VARIABLE` | Reinitialize fields/totals after Ajax change. |
| `WAPF-PRODUCT-SUBSCRIPTION` | Subscription/variable subscription | Pro | `PRODUCT` | Subscription adapter | needs audit | `capability/WAPF-PRODUCT-SUBSCRIPTION` | Initial/renewal semantics tested. |
| `WAPF-LOCALE-TRANSLATION` | Translation-ready UI | Free | `FREE`, `PRODUCT` | Localization | needs audit | `capability/WAPF-LOCALE-TRANSLATION` | OPF text-domain coverage. |
| `WAPF-LOCALE-WPML` | WPML integration | Pro | `PRODUCT` | WPML adapter | needs audit | `capability/WAPF-LOCALE-WPML` | Locale-specific group/choice/rule. |
| `WAPF-LOCALE-POLYLANG` | Polylang integration | Pro | `PRODUCT` | Polylang adapter | needs audit | `capability/WAPF-LOCALE-POLYLANG` | Locale-specific group/choice/rule. |
| `WAPF-CURRENCY-WOOCS` | WOOCS currency | Pro | `PRODUCT` | Currency adapter | needs audit | `capability/WAPF-CURRENCY-WOOCS` | Active currency rounding. |
| `WAPF-CURRENCY-AELIA` | Aelia currency | Pro | `INTEGRATIONS` | Currency adapter | needs audit | `capability/WAPF-CURRENCY-AELIA` | Active currency calculation. |
| `WAPF-CURRENCY-FOX` | FOX currency | Pro | `INTEGRATIONS` | Currency adapter | needs audit | `capability/WAPF-CURRENCY-FOX` | Active currency calculation. |

## Official add-ons

| ID | WAPF capability | Tier | Source | OPF module | Status | Fixture | Required behavior |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `WAPF-ADDON-ACF` | ACF formula variables | Extended + Addons: ACF | `ADDONS` | ACF adapter | gap | `capability/WAPF-ADDON-ACF` | Authorized typed ACF values only. |
| `WAPF-ADDON-IMAGE-UPLOAD` | Upload-image edit/process | Extended + Addons: Image upload | `ADDONS` | Upload service | gap | `capability/WAPF-ADDON-IMAGE-UPLOAD` | Bounded access-controlled derivative. |
| `WAPF-ADDON-URL-PREFILL` | URL parameter prefill | Extended + Addons: URL parameters | `ADDONS` | URL configuration | gap | `capability/WAPF-ADDON-URL-PREFILL` | Allow-list cannot bypass validation. |
| `WAPF-ADDON-LIVE-PREVIEW` | Live text/upload image preview | Extended + Addons: Live preview | `ADDONS` | Product preview | gap | `capability/WAPF-ADDON-LIVE-PREVIEW` | Preview is never order authority. |
| `WAPF-ADDON-LAYERED-IMAGE` | Layered product image | Extended + Addons: Layered Image | `ADDONS` | Product preview | gap | `capability/WAPF-ADDON-LAYERED-IMAGE` | Deterministic order/fallback. |
| `WAPF-ADDON-LOOKUP-IMPORT` | Spreadsheet lookup-table import | Extended + Addons: Lookup powerup | `ADDONS` | Lookup import | gap | `capability/WAPF-ADDON-LOOKUP-IMPORT` | Versioned CSV and error report. |

## Compatibility-matrix ledger

The following compact entries reserve every WAPF matrix entry. WAPF labels:
`I` integrated, `A` author integrated, `T` tested, `C` code, `P` partly
integrated. Every OPF status is `needs audit`; OPF must not inherit WAPF's
label. All sources are `INTEGRATIONS` and every fixture is
`compatibility/<ID>`.

| ID | Entry | WAPF | OPF owner |
| --- | --- | --- | --- |
| `WAPF-COMPAT-BEAVER` | Beaver Builder | T | Builder adapter |
| `WAPF-COMPAT-DIVI-BUILDER` | Divi Builder | T | Builder adapter |
| `WAPF-COMPAT-ELEMENTOR` | Elementor | T | Builder adapter |
| `WAPF-COMPAT-OXYGEN` | Oxygen Builder | T | Builder adapter |
| `WAPF-COMPAT-BREAKDANCE` | Breakdance | T | Builder adapter |
| `WAPF-COMPAT-VISUAL-COMPOSER` | Visual Composer | T | Builder adapter |
| `WAPF-COMPAT-WPBAKERY` | WPBakery | T | Builder adapter |
| `WAPF-COMPAT-ORDER-EXPORT` | Advanced Order Export | T | Export adapter |
| `WAPF-COMPAT-WC-DISCOUNTS` | WooCommerce Discounts | I | Discounts adapter |
| `WAPF-COMPAT-INVOICE-DELIVERY` | Print Invoice & Delivery Notes | C | Invoice adapter |
| `WAPF-COMPAT-RESERVED-STOCK` | Reserved Stock Pro | T | Stock adapter |
| `WAPF-COMPAT-TI-WISHLIST` | TI Wishlist | A | Wishlist adapter |
| `WAPF-COMPAT-YITH-ELEMENTOR` | Ultimate Addons for Elementor | C | Builder adapter |
| `WAPF-COMPAT-VARIATION-SWATCHES` | Variation Swatches | T | Variation adapter |
| `WAPF-COMPAT-WEIGHT-SHIPPING` | Weight Based Shipping | T | Shipping adapter |
| `WAPF-COMPAT-PDF-INVOICES` | PDF Invoices & Packing Slips | T | Invoice adapter |
| `WAPF-COMPAT-DEPOSITS` | Deposits & Partial Payments | A | Payments adapter |
| `WAPF-COMPAT-PRODUCT-TABLE` | WooCommerce Product Table | I | Product-table adapter |
| `WAPF-COMPAT-QUANTITY-RULES` | Quantity Discounts, Rules & Swatches | I | Quantity adapter |
| `WAPF-COMPAT-QUICK-VIEW-PRO` | WooCommerce Quick View Pro | I | Quick-view adapter |
| `WAPF-COMPAT-RESTAURANT` | WooCommerce Restaurant Ordering | C | Ordering adapter |
| `WAPF-COMPAT-SUBSCRIPTIONS` | WooCommerce Subscriptions | I | Subscription adapter |
| `WAPF-COMPAT-WP-ALL-EXPORT` | WP All Export | T | Export adapter |
| `WAPF-COMPAT-YITH-BOOKING` | YITH Booking & Appointment | C | Booking adapter |
| `WAPF-COMPAT-YITH-QUOTE` | YITH Request a Quote | I | Quote adapter |
| `WAPF-COMPAT-YITH-QUICK-VIEW` | YITH WooCommerce Quick View | C | Quick-view adapter |
| `WAPF-COMPAT-ADVANCED-SHIPPING` | Advanced Shipping Rates | A | Shipping adapter |
| `WAPF-COMPAT-FEATURED-VIDEOS` | Featured Videos | T | Media adapter |
| `WAPF-COMPAT-CADDY` | Caddy Smart Side Cart | T | Cart adapter |
| `WAPF-COMPAT-PAYPAL` | Payment Plugin for PayPal | A | Payments adapter |
| `WAPF-COMPAT-SHOPTIMIZER` | Shoptimizer | T | Theme adapter |
| `WAPF-COMPAT-STOREFRONT` | Storefront | T | Theme adapter |
| `WAPF-COMPAT-FLATSOME` | Flatsome | I | Theme adapter |
| `WAPF-COMPAT-WOODMART` | Woodmart | I | Theme adapter |
| `WAPF-COMPAT-ASTRA` | Astra | I | Theme adapter |
| `WAPF-COMPAT-DIVI-THEME` | Divi theme | T | Theme adapter |
| `WAPF-COMPAT-SHOPKEEPER` | Shopkeeper | T | Theme adapter |
| `WAPF-COMPAT-GOYA` | Goya | T | Theme adapter |
| `WAPF-COMPAT-PORTO` | Porto | P | Theme adapter |
| `WAPF-COMPAT-AVADA` | Avada | I | Theme adapter |
| `WAPF-COMPAT-OCEANWP` | OceanWP | I | Theme adapter |
| `WAPF-COMPAT-XSTORE` | X-Store | I | Theme adapter |

## Promotion rule

A fixture must cover applicable storefront rendering, submission,
server-calculated pricing, classic cart, block cart/checkout, order storage,
order email/meta, and order-again. Migration fixtures must also prove that
unsupported source data blocks publication with an actionable report, never
silent data loss. Only a passing fixture may promote a status.
