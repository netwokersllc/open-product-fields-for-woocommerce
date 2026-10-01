# OPF PHP developer API

`OPF\API` is the supported PHP entry point for third-party extensions. It
replaces the capabilities exposed by WAPF Extended 3.1.5's beta
`api-helpers.php` with an OPF-owned contract. Load it after the plugin has
loaded; the class is available through OPF's autoloader.

```php
use OPF\API;

$groups = API::get_field_groups_of_product( $product );
$html   = API::display_field_groups_for_product( $product );
```

## Methods

| Method | Contract |
| --- | --- |
| `has_setting( string $name = '' ): bool` | Checks an OPF option. Names may be passed with or without the `opf_` prefix. An empty name returns `false`. |
| `get_setting( string $name, $default = null )` | Reads the OPF option, uses the default only when the stored value is `null`, then applies `opf/setting/{sanitized-name}`. |
| `add_formula_function( string $name, callable $callback ): void` | Registers a trusted server-side formula function. Names use letters, digits, and `_`, begin with a letter, and cannot replace OPF's `today`, `dow`, or `month` functions. |
| `display_field_groups_for_product( $product ): string` | Returns OPF's normal product fields and totals markup for a `WC_Product` or product ID; returns an empty string when no group matches or fields are gated. It enqueues the same frontend assets as the normal renderer. |
| `product_has_options( $product ): bool` | Checks whether any published group matches the product's placement rules. |
| `get_field_groups_of_product( $product ): array` | Returns matching entries shaped as `id`, `title`, `lang`, and `group` (`OPF\Engine\FieldGroup`). Accepts a product or product ID. |
| `get_field_groups_by_ids( array $ids = [] ): array` | Returns published groups in requested ID order; unknown, unpublished, and non-OPF IDs are skipped. |
| `get_field_group_by_id( $id ): ?array` | Returns one published group entry or `null`. |
| `field_snapshot_for_product( WC_Product $product, array $values ): array` | Builds field ID, group ID, label, type, and raw value records for immutable order persistence. |
| `get_options_from_order( $order ): array` | Reads line items from a `WC_Order` or order ID. Records contain product/item IDs, quantity, and field records with `field_id`, `label`, raw canonical `value`, and field `type`. New orders use a saved snapshot so labels and values survive later group edits or deletion. |
| `get_custom_fields_in_cart(): array` | Returns cart-item keys, product IDs, and matched field records with raw canonical values. Choice values are slugs, not display labels. |
| `field_group_to_array( FieldGroup $group ): array` | Returns the normalized OPF group data. |
| `array_to_field_group( array $data ): FieldGroup` | Normalizes input through the current schema and returns an OPF field-group value object. |

Formula callbacks receive `(array $arguments, array $context)`. Arguments are
raw strings split on WAPF's semicolon separator; nested OPF formula calls are
expanded first. Context contains `price`, `qty`, `addons`, `value`,
`field_values`, and nullable `product_id`. Return a finite numeric value.
Exceptions and non-numeric results fail closed to zero. The parser does not
execute PHP or arbitrary expression text supplied by a formula.

```php
API::add_formula_function(
    'area_price',
    static function ( array $args, array $context ): float {
        return (float) $args[0] * (float) $args[1] * 0.25;
    }
);
```

This API intentionally uses OPF value objects and option storage rather than
WAPF classes or internal storage keys. Existing WAPF integrations should
migrate calls to these documented methods; they must not assume the beta
WAPF object shape or its `_wapf_meta`/`wapf` cart storage.
