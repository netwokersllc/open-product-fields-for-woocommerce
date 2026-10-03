# WAPF developer hooks — 101-hook crosswalk + `wapf/` alias bridge

Lane: `lane/devhooks` worktree `/tmp/opf-lane-devhooks`. Reference: Advanced
Product Fields for WooCommerce Extended **3.1.5** (clone `/tmp/opf-image-devhooks-wp`,
WooCommerce 11.1.0). Evidence: `/tmp/opf-lane-devhooks-evidence/`.

## Summary

- WAPF-owned hook names scanned: **102** (101 canonical per the adminmisc manifest + 1 additional dynamic `wapf/setting/{$name}`).
- Status: **5 implemented**, **52 mappable**, **45 genuinely absent**.
- WAPF aliases now firing through the bridge: **27**.
- Bridge file: `includes/Compat/WapfHooks.php` (loaded from the plugin bootstrap).
- Unit proof: `tests/Unit/WapfHooksBridgeTest.php` (3 tests, 70 assertions, separate-process hook harness).
- Clone proof: temporary mu-plugin `opf-wapf-hook-probe.php` + `clone-wapf-bridge-proof.php`; log `wapf-bridge-clone-run.log`.

## Bridged hooks

| wapf_hook | kind | opf_equivalent | category | rationale |
|---|---|---|---|---|
| wapf/cart/item_data | apply_filters | woocommerce_get_item_data | cart | Bridged in CartIntegration::display_item_data; WAPF arg shape ($item_data,$cart_item) preserved. |
| wapf/features/linked_products | apply_filters | opf_features_linked_products | linked-products | OPF already exposes opf_features_linked_products; alias registered. |
| wapf/html/field_container_classes | apply_filters | None | field-render | Bridged in Renderer::render_field; WAPF arg shape ($classes,$field) preserved. |
| wapf/html/field_description | apply_filters | None | field-render | Bridged in Renderer::render_field; WAPF arg shape ($html,$field) preserved. |
| wapf/html/field_label | apply_filters | None | field-render | Bridged in Renderer::render_field; WAPF arg shape ($label_content,$field,$product) preserved. |
| wapf/html/image_swatch_size | apply_filters | None | field-render | Bridged in Renderer::render_choices; WAPF arg shape ($size,$field,$product,$choice) preserved. |
| wapf/html/option_wrapper_classes | apply_filters | None | field-render | Bridged in Renderer::render_choices; WAPF arg shape ($classes,$field,$product,$option) preserved. |
| wapf/html/section_container_classes | apply_filters | None | field-render | Bridged in Renderer::render_section; WAPF arg shape ($classes,$field) preserved. |
| wapf/linked_products/cart_choice | apply_filters | None | linked-products | Bridged in LinkedProducts::add_children; WAPF arg shape ($choice,$field,$main_product_id) preserved. |
| wapf/linked_products/choice | apply_filters | opf/linked_products/choice | linked-products | OPF already exposes opf/linked_products/choice with the identical WAPF signature; alias registered. |
| wapf/lookup_tables | apply_filters | opf_lookup_tables | linked-products | Calculator already falls back to wapf/lookup_tables; alias on opf_lookup_tables keeps a single application path. |
| wapf/order/order_item_field | apply_filters | None | order-meta | Bridged in CartIntegration::persist_order_item; WAPF arg shape ($meta_field,$cart_item,$field) preserved. |
| wapf/order_again/before_cart_item_field | do_action | None | order-meta | Action bridged in CartIntegration::restore_order_again; WAPF arg shape ($order_item,$field,$clone_idx,$raw_values) approximated with clone_idx=0. |
| wapf/order_item/meta_display_value | apply_filters | None | order-meta | Bridged in CartIntegration::persist_order_item; WAPF arg shape ($display_value,$field) preserved. |
| wapf/pricing/base | apply_filters | opf_cart_item_base_price | pricing | Bridged at CartIntegration::apply_prices; WAPF arg shape ($price,$product,$quantity) preserved. |
| wapf/pricing/cart_item_base | apply_filters | opf_cart_item_base_price | pricing | Bridged at CartIntegration::apply_prices; WAPF arg shape ($price,$product,$quantity,$cart_item) preserved. |
| wapf/pricing/cart_item_options | apply_filters | opf_addon_price | pricing | Bridged at CartIntegration::apply_prices; WAPF arg shape ($options_total,$product,$quantity,$cart_item) preserved. |
| wapf/pricing/product | apply_filters | None | pricing | Bridged in Renderer::render as the base price used for display; WAPF arg shape ($price,$product) preserved. |
| wapf/pricing_summary | apply_filters | opf_show_totals | pricing | Alias registered on opf_show_totals. WAPF returns a summary mode string; OPF keeps its boolean display gate, so the alias receives the boolean. |
| wapf/product_field_groups | apply_filters | opf_groups_for_product | field-render | OPF already exposes opf_groups_for_product($groups,$product); alias registered for the WAPF name. |
| wapf/products/query | apply_filters | opf/linked_products/query_args | linked-products | OPF already exposes opf/linked_products/query_args($args,$query,$main_product); alias registered. |
| wapf/skip_cart_validation | apply_filters | opf_skip_validation | cart | Alias registered on opf_skip_validation (boolean passthrough). |
| wapf/skip_fieldgroup_validation | apply_filters | opf_skip_validation | cart | Alias registered on opf_skip_validation (boolean passthrough). |
| wapf/validate | apply_filters | None | cart | Bridged in CartIntegration::validate_values for visible non-repeat fields; returns OPF error strings via the WAPF {error,message} contract. cart_item_data is passed as null (unavailable at that stage). |
| wapf_after_product_totals | do_action | None | field-render | Legacy action bridged in Renderer::render_totals (WAPF arg: $product). |
| wapf_before_product_totals | do_action | None | field-render | Legacy action bridged in Renderer::render_totals (WAPF arg: $product). |
| wapf_before_wrapper | do_action | None | field-render | Legacy action bridged in Renderer::render immediately before the wrapper (WAPF arg: $product). |

## Implemented (pre-existing OPF hook, WAPF alias added)

| wapf_hook | opf_equivalent |
|---|---|
| wapf/features/linked_products | opf_features_linked_products |
| wapf/linked_products/choice | opf/linked_products/choice |
| wapf/lookup_tables | opf_lookup_tables |
| wapf/product_field_groups | opf_groups_for_product |
| wapf/products/query | opf/linked_products/query_args |

## Mappable, not auto-bridged

These have a plausible OPF surface but the alias would need a signature that
OPF cannot supply exactly, or would touch core logic the lane must not edit.
They are documented here so a future lane can decide.

| wapf_hook | opf_equivalent | rationale |
|---|---|---|
| wapf/ajax_file_upload_config | opf_frontend_config | OPF publishes a frontend config via opf_frontend_config; the WAPF Dropzone config shape is upload-service specific and not emitted. |
| wapf/cart/item_values_label | None | OPF derives cart/order labels from field definitions in CartIntegration::visible_selections. No per-label filter; WAPF form needs cartitem_field context OPF does not materialize. |
| wapf/field_group/is_condition_valid | None | OPF evaluates conditionals in Engine/Evaluator (core logic, off-limits). No extension filter is exposed for a single condition result. |
| wapf/file/ajax_upload_success_file_result | None | OPF Uploads REST route returns its own result shape; no per-file success filter. |
| wapf/file/check_nonce | None | OPF performs nonce/origin checks in Uploads::same_origin; no filter toggle. |
| wapf/file/test_file_type | None | OPF validates types through accepted_types + wp_check_filetype; no filter toggle. |
| wapf/file/upload_result | None | OPF private-upload pipeline returns tokens, not a wp_handle_upload result; no equivalent filter. |
| wapf/function_definitions | OPF\API::add_formula_function | OPF registers formula functions via the public API::add_formula_function; there is no bulk function-definition filter at boot. |
| wapf/fx/functions | OPF\API::add_formula_function | OPF exposes registered function names only through the Calculator internals; no filter listing them. |
| wapf/fx/solve | None | OPF evaluates formulas in Calculator::evaluate_formula (core); no per-call solve filter. |
| wapf/html/field_attributes | None | OPF emits input attributes as preformatted strings in Renderer::render_input/render_choices; no array-attribute filter point. |
| wapf/html/field_classes | None | OPF builds input class strings inline rather than an array. wapf/html/field_container_classes is bridged for the container; the inner input class filter is not. |
| wapf/html/field_container_attributes | None | OPF container attributes are fixed markup; no array filter point on the container. |
| wapf/html/option_attributes | None | OPF option attributes are a preformatted string; WAPF passes/returns an array. Bridging would change the type contract, so left unimplemented. |
| wapf/html/pricing_hint | None | OPF builds pricing hints in Renderer::pricing_hint_html but has no filter there; the WAPF form also carries field/option context unavailable at that call. |
| wapf/html/pricing_hint/amount | None | OPF computes hint amounts from the normalized pricing block in Renderer::pricing_hint_html; no amount filter exposed. |
| wapf/html/pricing_hint/format | None | OPF hint format is fixed to " +/- wc_price()"; no format filter exposed. |
| wapf/html/product_totals | None | OPF render_totals() echoes its block directly; no filtered totals-HTML return. before/after actions are bridged instead. |
| wapf/html/product_totals/data | None | OPF totals data is computed inline in render_totals() as data-* attributes rather than a mutable array. |
| wapf/pricing/addon | None | OPF computes per-field addons inside Calculator::field_addon (core engine, off-limits). No per-addon extension filter is exposed; use opf_addon_price for per-line totals. |
| wapf/pricing/cart_item_base_for_formulas | opf_formula_base_price | OPF exposes opf_formula_base_price ($price,$product_id) at formula evaluation; WAPF form takes quantity+cart_item which are unavailable there. Left reader-compatible, not auto-bridged. |
| wapf/replace_in_formula | None | OPF performs token replacement inside Calculator (core); no replacement-step filter. |
| wapf/sanitize_value | None | OPF sanitizes per type in CartIntegration::sanitize_value; adding a filter would touch every return branch in the cart flow. Left unimplemented to avoid behavior churn. |
| wapf/section_classes/ | None | Dynamic per-section class filter; OPF render_section builds fixed section classes and only the generic section_container_classes alias is bridged. |
| wapf/settings | opf/setting/{name} + opf_frontend_config | OPF reads/writes settings through OPF\API::get_setting/has_setting and opf/setting/{name}; the WAPF whole-array admin filter has no counterpart. |
| wapf/skip_add_to_cart | None | WAPF skips rendering fields/add-to-cart for a product. OPF controls this through placement rules and Renderer::visible_to_viewer(); no per-request filter. |
| wapf/store_api/cart/data_callback | capture_store_api | OPF captures Store API payloads in CartIntegration::capture_store_api but exposes no data callback filter; WAPF form is cart-item shaped. |
| wapf/validate/file | None | OPF validates upload tokens in Uploads::validate_tokens; no extension filter. |
| wapf_upload_ajax | opf_upload_ajax option | OPF reads the migrated wapf_upload_ajax/opf_upload_ajax option in Uploads::modern but exposes no filter. |
| wapf/setting/{$name} | opf/setting/{name} | Additional dynamic filter found by source scan (WAPF\api\api-helpers.php:23). Mirrors OPF\API::get_setting() opf/setting/{name}; not auto-bridged because WordPress filters cannot wildcard a dynamic suffix. |

## Genuinely absent

See `WAPF-HOOKS-ABSENT.md` for the full per-hook rationale.

## Files changed

- `includes/Compat/WapfHooks.php` — new bridge class.
- `open-product-fields-for-woocommerce.php` — `WapfHooks::init()` wiring.
- `includes/Service/CartIntegration.php` — wrapped alias dispatches in pricing, cart display, validation, order meta, order-again, plus `field` on selection records.
- `includes/Service/Renderer.php` — wrapped alias dispatches for field/section/option rendering + legacy totals/wrapper actions + `wapf/pricing/product`.
- `includes/Service/LinkedProducts.php` — wrapped `wapf/linked_products/cart_choice` dispatch.
- `tests/Unit/WapfHooksBridgeTest.php` — new.
