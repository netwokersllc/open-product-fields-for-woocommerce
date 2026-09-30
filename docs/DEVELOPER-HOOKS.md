# Developer hooks

OPF is pre-1.0. Public hook contracts are documented here; release notes will
call out any contract changes before the stable 1.0 API is declared.

## `opf/admin/after_product_duplication`

Runs after WooCommerce saves a duplicated product and OPF has saved a copied
product-local field group and its provenance metadata. It runs once per copied
local group. Ordinary global groups are shared and do not trigger this action.
An idempotent retry that finds an existing copy does not trigger it again.

Register for all five arguments:

```php
add_action(
	'opf/admin/after_product_duplication',
	'networkers_handle_duplicated_product_fields',
	10,
	5
);
```

Arguments, in order:

1. `WC_Product $duplicate` — saved duplicate product.
2. `WC_Product $source` — original product.
3. `OPF\Engine\FieldGroup $group` — in-memory snapshot of the copied group.
4. `array<string,string> $field_id_map` — old field ID to copied field ID.
5. `int $field_group_post_id` — saved `opf_field_group` post ID.

The group object is a snapshot. Mutating it inside the callback does not update
the saved post; persist edits through the field-group repository after checking
the documented storage contract. The callback return value is ignored.
