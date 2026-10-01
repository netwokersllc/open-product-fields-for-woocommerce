# Supported capabilities

This document describes the capability set implemented in OPF 0.1.0. The
canonical source is `OPF\Engine\FieldGroup::FIELD_TYPES` and
`OPF\Engine\FieldGroup::PRICING_TYPES`; documentation must not promise a
capability that is absent from those registries and their renderer, cart, and
validation paths.

## Available now

| Area | Implemented behavior |
| --- | --- |
| Field types | `text`, `textarea`, `email`, `url`, `number`, `date`, `toggle`, `select`, `radio`, `checkbox`, and text-only `swatch` |
| Email | Browser email input plus server-side rejection of malformed non-empty values |
| Toggle | Boolean input stored as `1` when checked and `0` when unchecked; a required toggle must be checked |
| Choice behavior | Defaults, disabled choices, single-select controls, and multiple checkbox selections |
| Conditions | Show or hide a field using `is`, `is_not`, `contains`, `not_contains`, `greater`, `less`, `empty`, and `not_empty` rules; condition blocks support all/any logic |
| Product placement | Product and product-term inclusion/exclusion rules |
| Visitor targeting | Field groups can target logged-in or logged-out visitors; result caching varies by login state. |
| Pricing | No price, fixed amount, percentage of product price, or safe arithmetic formula. The server calculates the cart price. Fixed prices are flat per line unless `per_unit` is enabled; percentage and formula prices are per unit. |
| Commerce flow | Classic product-form add to cart plus Store API add to cart; cart, block cart/checkout display, order-item storage, and order-again restoration |
| WooCommerce features | The plugin declares compatibility with HPOS and cart/checkout blocks |
| Administration | Field-group builder, authenticated `opf/v1` REST endpoints, WAPF import command, WP-CLI OPF archive export/import, and limited WAPF Tools JSON export |
| WAPF import | Maps supported field types and pricing, including `true-false` toggles, WAPF group `auth` / `!auth` visitor rules, and field-condition operators `==`, `!=`, `==contains`, `!=contains`, `gt`, `lt`, `empty`, and `!empty`. Unmapped conditions are marked for review; unsupported types, repeaters, and pricing remain review-required. |
| OPF archive migration | `wp opf import-archive <file>` validates a versioned export with a 5 MiB and 500-group limit, defaults to dry-run, rejects data the installed schema would drop, and imports repeated-safe groups. Portability warnings force affected groups to draft. Isolated WordPress round-trip proof remains open. |
| WAPF Tools JSON export | `wp opf export --group=<id> --format=wapf-json` exports one group in WAPF's `fields`, `conditions`, `layout`, and `variables` shape. Unsupported or lossy settings stop export; site-local placement IDs still need destination review. |

## Not implemented in 0.1.0

OPF does not currently provide file uploads, time fields, image or colour
swatches, repeatable fields, child/linked products, image quantities,
content/layout fields, visual previews, lookup tables, or third-party
integration adapters. These are roadmap work, not supported features.

There is no general compatibility guarantee for a theme, page builder,
currency plugin, translation plugin, subscription plugin, or another product
extension until OPF ships and documents an adapter and its tests.

## Formula syntax

Formula pricing accepts numbers, `+`, `-`, `*`, `/`, parentheses, and the
variables `[price]`, `[qty]`, `[addons]` (or `[options_total]`), and `[val]`.
Invalid formulas, division by zero, and non-finite results evaluate to zero.
OPF does not evaluate PHP or JavaScript expressions.

## Release claims

New field types and integrations remain roadmap work until their registry,
rendering, validation, server-side pricing where applicable, cart/order flow,
and automated coverage have shipped. Historical migration reports are not a
claim of support for capabilities outside this document.
