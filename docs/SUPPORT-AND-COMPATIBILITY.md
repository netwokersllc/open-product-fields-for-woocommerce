# OPF support and compatibility policy

This policy defines what Open Product Fields for WooCommerce (OPF) supports, what a compatibility declaration means, and how to report issues. It is a release contract, not a claim that every WooCommerce extension or theme will work without testing.

## Version and platform policy

The plugin header is the source of truth for a released build. For the source tree containing this document, it declares:

| Dependency | Minimum | Tested through |
| --- | ---: | ---: |
| WordPress | 6.5 | 7.1 |
| PHP | 7.4 | — |
| WooCommerce | 9.0 | 11.1 |

OPF requires WooCommerce to be installed and active. "Tested through" means that the named release was exercised by OPF maintainers; it is not a guarantee for newer versions. A release must update the plugin header and this table when its compatibility position changes.

OPF follows semantic versioning once it reaches a stable 1.0 release. Before then, `0.x` releases may change a public contract in a minor release. Release notes must identify any migration, compatibility, or API impact.

## Support tiers

### Core

Core is the code shipped by OPF and maintained as part of the plugin. A core feature is supported only when it has documented behavior and automated coverage for its applicable lifecycle: rendering, validation, server-side calculation, cart persistence, order-item persistence, and display.

At this stage, the core contract covers the field types and pricing modes in the release documentation, product-page rendering through WooCommerce hooks, classic add-to-cart, Store API add-to-cart, cart and checkout display, order item meta, and order-again restoration. The exact list of supported field types is the release README and capability ledger; this policy does not expand that list.

### Adapters

An adapter connects OPF to a theme, plugin, builder, currency provider, translation provider, or other external system. Adapters are separate from the core contract because their upstream integrations can change independently.

An adapter may be described as **supported** only when OPF publishes all of:

1. The integration name and tested version range.
2. The supported user journey and known exclusions.
3. Automated regression coverage or reproducible verification steps.
4. An owner and a removal or update path when the upstream contract changes.

Without those four items, the integration is experimental or unverified. Theme compatibility mode is an interoperability aid, not a declaration that every theme or legacy-field-plugin customization is supported.

## WooCommerce feature scope

OPF declares compatibility with WooCommerce High-Performance Order Storage (HPOS, `custom_order_tables`) and Cart and Checkout Blocks (`cart_checkout_blocks`) through WooCommerce's feature declaration API. The core lifecycle covered by that declaration is:

| Surface | OPF core scope |
| --- | --- |
| Classic product form and add to cart | Capture, validate, price, and persist supported field values. |
| Store API add to cart | Capture, validate, price, and persist supported field values. |
| Classic cart and checkout | Display supported selections and use the server-calculated line price. |
| Block cart and checkout | Expose selections through the Store API for WooCommerce Blocks display and checkout. |
| Orders and HPOS | Store supported selections as order-item meta through WooCommerce APIs. |
| Order again | Restore supported selections from OPF order-item meta. |

The declaration does not cover third-party block extensions, payment gateways, subscriptions, bundles, composites, product add-on plugins, quick-view implementations, page builders, currencies, translations, exports, invoices, or custom themes unless a named adapter meets the policy above.

## Compatibility claims

OPF uses these terms consistently:

| Term | Meaning |
| --- | --- |
| Supported | Within the core contract or a named adapter contract, with current verification. |
| Tested | Exercised against a named version and scenario; narrower than a broad support promise. |
| Compatible | A specific scenario has passed documented verification; it does not imply every configuration works. |
| Experimental | Available for evaluation; not yet a stable compatibility commitment. |
| Unverified | No claim is made. |

Documentation, release notes, issue responses, and marketing must name the feature, version, and user journey behind a claim. They must not say "works with all themes," "all plugins," "all blocks," or "WAPF replacement" without stating the supported feature set and any exclusions.

## Reporting, security, and maintenance

For a bug report, include the OPF, WordPress, WooCommerce, PHP, theme, and relevant adapter versions; the product and field configuration after removing customer data; exact reproduction steps; expected and actual results; and any relevant PHP or browser-console errors. Do not post credentials, payment data, or customer data in public reports.

Report suspected security vulnerabilities privately to the project maintainer through the repository's security-advisory channel when one is available. If that channel is unavailable, open a minimal issue asking for a private contact channel and omit exploit details. Maintainers should acknowledge a valid report, assess impact, coordinate a fix, publish a release, and document affected versions and mitigation after users have a safe upgrade path.

OPF supports the current released version. A security fix may be backported to an earlier release when maintainers explicitly announce it; users should not assume a backport exists. The changelog and release notes are authoritative for support windows, security fixes, and compatibility changes.

## Deprecation policy

Public PHP hooks, REST endpoints, stored data formats, CLI commands, JavaScript events, and adapter contracts must be versioned and documented before they are called stable. A stable public contract is deprecated by:

1. Publishing the replacement and migration guidance in release notes and docs.
2. Keeping the deprecated contract working for at least one minor release, except where a security, data-integrity, or upstream-breakage fix requires earlier removal.
3. Emitting an actionable developer-facing notice where practical.
4. Removing it only in a major release and retaining migration guidance.

Before 1.0, public contracts may change more quickly, but every breaking change still requires a release note and migration guidance. OPF-owned persisted data must be migrated forward rather than silently discarded. Importers must flag unsupported source data for review instead of claiming a complete migration.

## Maintainer release gate

Before declaring a new platform or integration supported, maintainers must verify the applicable user journey on the named versions, add or update regression coverage, document exclusions, and update the compatibility matrix. If evidence expires because an upstream release changes behavior, the claim must be downgraded to tested, experimental, or unverified until it is renewed.
