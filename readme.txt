=== Open Product Fields for WooCommerce ===
Contributors: ssthormess
Tags: woocommerce, product fields, product addons, custom fields, conditional logic
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 9.0
WC tested up to: 11.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build custom product fields and add-ons for WooCommerce — free, open source, with conditional logic and pricing.

== Description ==

Open Product Fields lets you add custom fields and add-ons to your WooCommerce product pages: text inputs, choices, files, dates, and more, with conditional logic and per-option pricing.

= Why "Open"? =

This plugin is 100% free and open source (GPLv2 or later). No license keys, no nags, no crippled Lite version, no upsell walls. Every feature is in every copy.

**Features:**

* Field types: text, textarea, URL, number, select, radio, checkbox, text swatch
* Conditional logic (show/hide fields based on other values)
* Pricing per choice: fixed, percentage of product price, and math formulas — always computed server-side
* Works on classic product pages AND block-based cart/checkout (Store API)
* Per-field order item meta: queryable, exportable, HPOS-friendly
* Migration tool: one-command import from Advanced Product Fields (WAPF), including recovery of corrupted legacy payloads (`wp opf import-wapf`)
* Theme compatibility mode for themes built around legacy field plugins
* REST API (`opf/v1`) and dependency-free JavaScript builder
* Zero asset weight on pages without fields; no jQuery

== Installation ==

1. Install and activate WooCommerce.
2. Upload the `open-product-fields-for-woocommerce` folder to `/wp-content/plugins/`, or install from the Plugins screen.
3. Activate "Open Product Fields for WooCommerce" and open the field builder from a product's edit screen (Field Groups, under the WooCommerce menu).

== Frequently Asked Questions ==

= Is it really free? =

Yes. GPLv2 or later — use it on any number of sites, for any purpose, at no cost.

= Does it work with my theme? =

It renders fields through standard WooCommerce hooks, so any theme that follows WooCommerce template standards works out of the box. A theme compatibility mode (on by default) helps themes built around legacy field plugins.

= Migrating from Advanced Product Fields (WAPF)? =

Run `wp opf import-wapf` (dry run first, then `--commit`). Placement rules, choices and pricing are mapped automatically; anything needing a human decision is flagged. See docs/MIGRATION.md.

== Changelog ==

= 0.1.0 =
* Field groups with conditional logic and server-side pricing.
* Classic and block cart/checkout support, order persistence, order-again restore.
* WAPF import tool with corruption repair and WP-CLI command.
