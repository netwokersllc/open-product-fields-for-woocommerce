=== Open Product Fields for WooCommerce ===
Contributors: ssthormess
Tags: woocommerce, product fields, product addons, custom fields, conditional logic
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build custom product fields and add-ons for WooCommerce — free, open source, with conditional logic and pricing.

== Description ==

Open Product Fields lets you add custom fields and add-ons to your WooCommerce product pages: text inputs, choices, files, dates, and more, with conditional logic and per-option pricing.

= Why "Open"? =

This plugin is 100% free and open source (GPLv2 or later). No license keys, no nags, no crippled Lite version, no upsell walls. Every feature is in every copy.

**Features planned for the 1.0 release:**

* Field builder with a live product page preview
* Field types: text, textarea, number, select, radio, checkbox, file upload, date, color, heading, separator
* Conditional logic (show/hide fields based on other values)
* Pricing: flat fee, percentage, per-character, quantity multipliers, and tiered pricing
* Cart and checkout display of chosen options, with edit links
* Order meta persistence and export
* Translation-ready and RTL-friendly
* Developer API: `opf_field_types` filter and documented template overrides

== Installation ==

1. Install and activate WooCommerce.
2. Upload the `open-product-fields-for-woocommerce` folder to `/wp-content/plugins/`, or install from the Plugins screen.
3. Activate "Open Product Fields for WooCommerce" and open the field builder from a product's edit screen.

== Frequently Asked Questions ==

= Is it really free? =

Yes. GPLv2 or later — use it on any number of sites, for any purpose, at no cost.

= Does it work with my theme? =

It renders fields through standard WooCommerce hooks, so any theme that follows WooCommerce template standards works out of the box.

= Where are my customers' choices stored? =

Choices are attached to the cart item, the order, and (optionally) displayed in order emails and the admin order screen.

== Changelog ==

= 0.1.0 =
* Initial development scaffold.
