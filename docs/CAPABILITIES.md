# Supported capabilities

This document describes the capability set implemented in OPF 0.1.0. The
canonical source is `OPF\Engine\FieldGroup::FIELD_TYPES` and
`OPF\Engine\FieldGroup::PRICING_TYPES`; documentation must not promise a
capability that is absent from those registries and their renderer, cart, and
validation paths.

## Available now

| Area | Implemented behavior |
| --- | --- |
| Field types | `text`, `textarea`, `email`, `url`, `number`, `toggle`, `select`, `radio`, `checkbox`, and text-only `swatch` |
| Email | Browser email input plus server-side rejection of malformed non-empty values |
| Toggle | Boolean input stored as `1` when checked and `0` when unchecked; a required toggle must be checked |
| Choice behavior | Defaults, disabled choices, single-select controls, and multiple checkbox selections |
| Conditions | Show or hide a field using `is`, `is_not`, `contains`, `greater`, `less`, `empty`, and `not_empty` rules; condition blocks support all/any logic |
| Product placement | Product and product-term inclusion/exclusion rules |
| Pricing | No price, fixed amount, percentage of product price, or safe arithmetic formula. The server calculates the cart price. Fixed prices are flat per line unless `per_unit` is enabled; percentage and formula prices are per unit. |
| Commerce flow | Classic product-form add to cart plus Store API add to cart; cart, block cart/checkout display, order-item storage, and order-again restoration |
| WooCommerce features | The plugin declares compatibility with HPOS and cart/checkout blocks |
| Administration | Field-group builder, authenticated `opf/v1` REST endpoints, and WAPF import command |
| WAPF import | Maps the supported field types and supported pricing. Unsupported types, repeaters, and unsupported pricing are omitted from the imported group and recorded for review. |

## Not implemented in 0.1.0

OPF does not currently provide file uploads, date or time fields, image or
colour swatches, repeatable fields, child/linked products, image quantities,
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
