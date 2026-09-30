# Public PHP API

OPF exposes helpers with the `opf_` prefix. These are OPF APIs; WAPF's beta
global functions are not defined or overridden.

## Formula functions

Register a numeric function during the current PHP request:

```php
opf_add_formula_function(
	'area_cost',
	static function ( array $arguments, array $context ): float {
		$width  = $arguments[0] ?? 0.0;
		$height = $arguments[1] ?? 0.0;
		return $width * $height * 0.02;
	}
);
```

Use it in a field's formula as `area_cost(12, 8)`. Arguments are arithmetic
expressions evaluated by OPF's formula parser. The callback receives float
arguments and this context: `price`, `quantity`, `addons`, `value`, `today`,
and validated `field_values`.

Function names are ASCII identifiers up to 32 characters. `today`, `dow`,
`month`, and formula variable names `p`, `q`, `a`, and `v` are reserved. A
callback must return a finite number; thrown errors,
non-numeric results, non-finite results, malformed arguments, and division by
zero make the formula return zero. Callbacks run during server-side pricing,
so they should be deterministic and avoid side effects.

## Field groups

- `opf_get_field_group_by_id( $id )` returns a readable published group (or a
  draft the current user can read) as an `OPF\Engine\FieldGroup`, or `null`.
- `opf_get_field_groups_by_ids( $ids )` returns found groups in requested
  order as `FieldGroup` objects.
- `opf_get_field_groups_of_product( $product )` accepts a product object or
  ID and returns applicable groups after product, user, and language rules.
- `opf_product_has_options( $product )` reports whether applicable groups
  exist.
- `opf_display_field_groups_for_product( $product )` returns the same markup
  as the WooCommerce product hook; it may enqueue the required frontend assets.
- `opf_fieldgroup_to_array( $group )` returns canonical normalized data.
  `opf_array_to_fieldgroup( $data )` validates and normalizes it into a
  `FieldGroup` object.

These helpers use OPF post IDs and data shapes. They do not accept WAPF's
product-local `p_<product-id>` IDs or WAPF model objects.

## Settings

`opf_has_setting( $name )` recognizes OPF runtime metadata (`name`, `version`,
`slug`, `basename`, `path`, `url`, `capability`, and `cpts`) and the public
options `opf_date_format`, `opf_theme_compat`, `opf_admin_only`,
`opf_show_totals`, and `opf_compat_i18n`. `opf_get_setting( $name,
$fallback = null )` returns runtime metadata or reads the option using its OPF
default, then applies the `opf/setting/{name}` filter. Unknown names return the
supplied fallback before the filter runs.

## Cart and order values

`opf_get_custom_fields_in_cart()` returns visible OPF selections with cart key,
product ID, group/field IDs, labels, raw values, and field types. It returns an
empty array when WooCommerce or the active cart is unavailable.

`opf_get_options_from_order( $order )` accepts a `WC_Order` or order ID and
returns line-item product/item IDs, quantity, and field selections. OPF now
saves a checkout-time `_opf_fields_snapshot` alongside its existing `_opf_fields`
reorder data, so labels and types remain available after field-group edits or
deletion. Older order items fall back to `_opf_fields`; labels/types are
resolved from the group if it still exists. The caller must authorize access
to the order before passing it to this PHP helper.

This is OPF's stable numeric callback contract. WAPF's beta
`wapf_add_formula_function()` callback receives raw string arguments and a
different context; this helper does not claim source-compatible callback
signatures. WAPF's current paid package still needs review before OPF 1.0.
