# WAPF developer hooks — genuinely-absent OPF surfaces

Generated from `crosswalk.json` (WAPF Extended 3.1.5 source scan).
Each entry has no OPF equivalent surface to alias; the rationale records what
OPF does instead (or why the feature does not exist).

Total absent: **45** of 102 entries.

## admin

| wapf_hook | kind | rationale |
|---|---|---|
| wapf/admin/after_additional_field_settings | do_action | OPF field editing is a React builder; no PHP field-settings template hooks. |
| wapf/admin/after_field_settings | do_action | OPF field editing is a React builder; no PHP field-settings template hooks. |
| wapf/admin/after_product_duplication | do_action | OPF has no per-product field-group duplication hook (global groups are duplicated by FieldGroups). |
| wapf/admin/allowed_product_types | apply_filters | OPF placement supports product/category/tag/attribute/user rules; no allowed-product-types filter. |
| wapf/admin/before_additional_field_settings | do_action | OPF field editing is a React builder; no PHP field-settings template hooks. |
| wapf/admin/before_field_settings | do_action | OPF field editing is a React builder; no PHP field-settings template hooks. |
| wapf/admin/pricing_options | apply_filters | OPF pricing types are a fixed schema (fixed/percent/formula/per-unit) in FieldGroup; no admin option filter. |
| wapf/admin/product_tab_content_end | do_action | OPF does not render fields in the WooCommerce product-data tab; groups are global with placement rules. |
| wapf/admin/product_tab_content_start | do_action | OPF does not render fields in the WooCommerce product-data tab; groups are global with placement rules. |
| wapf/admin/sanitize_field | do_action_ref_array | OPF normalizes saved fields through Engine/FieldGroup schema normalization; no admin sanitize hook. |
| wapf/admin/settings_ | apply_filters | Dynamic WAPF admin settings-section filter; OPF settings screens are service-owned with no section hook. |
| wapf/admin/settings_sections | apply_filters | WAPF admin settings-section registry; OPF settings screens are service-owned with no section hook. |
| wapf/admin/tab_classes | apply_filters | WAPF product-tab CSS class filter; OPF renders no product-data tab. |
| wapf/licensing/timeout | apply_filters | OPF is GPL and has no licensing/update-check subsystem. |
| wapf/yith_raq_disable_quantity_edits | apply_filters | OPF has no YITH Request-a-Quote integration. |

## cart

| wapf_hook | kind | rationale |
|---|---|---|
| wapf/cart/cart_item_field | apply_filters | WAPF object-shaped per-field cart value. OPF stores canonical arrays keyed gid/fid (CartIntegration::ITEM_KEY) and renders from definitions; no cart_item_field object surface. |
| wapf/cart_edit_text | apply_filters | WAPF inline cart-edit affordance. OPF has no inline cart editing feature, so no equivalent surface exists. |
| wapf/disable_cart_edit_when_invisible | apply_filters | Only meaningful for WAPF inline cart editing, which OPF does not implement. |
| wapf/store_api/cart/schema_callback | apply_filters | OPF registers no Store API cart schema; block checkout consumes native WooCommerce item data. |

## field-render

| wapf_hook | kind | rationale |
|---|---|---|
| wapf/features/change_price_html | apply_filters | WAPF toggles its own price-HTML replacement. OPF does not replace catalog price HTML (theme/JS integration instead). |
| wapf/field_group/condition_options | apply_filters | WAPF admin condition-option list for placement rules. OPF placement rules ship a fixed schema. |
| wapf/field_options | apply_filters | WAPF admin field-option registry. OPF admin is a React builder backed by FieldGroup schema; no PHP option registry. |
| wapf/field_template_model | apply_filters | WAPF PHP view-model for field templates. OPF renders server-side PHP directly; no model handoff. |
| wapf/field_types | apply_filters | WAPF field-type registry. OPF types are enumerated in Engine/FieldGroup + Renderer; no runtime type filter. |
| wapf/field_visibility_conditions | apply_filters | WAPF admin condition-list registry. OPF conditionals are a fixed schema evaluated by Engine/Evaluator. |
| wapf/html/file_entry | apply_filters | WAPF Dropzone preview template. OPF ships its own private-upload markup/JS; no Dropzone template surface. |

## linked-products

| wapf_hook | kind | rationale |
|---|---|---|
| wapf/add_to_cart_redirect_when_editing | apply_filters | Only relevant to WAPF inline cart editing; OPF does not implement it. |
| wapf/add_to_cart_url | apply_filters | WAPF rewrites add-to-cart URLs for its AJAX flow. OPF relies on native WooCommerce add-to-cart. |
| wapf/shorten_text_limit | apply_filters | WAPF admin text-truncation helper. OPF has no shortened-text admin surface. |

## pricing

| wapf_hook | kind | rationale |
|---|---|---|
| wapf/pricing/display_options | apply_filters | WAPF admin/theme price-format override. OPF formats prices through WooCommerce + opf_frontend_config; no equivalent display-options filter surface. |
| wapf/pricing/price_with_tax | apply_filters | WAPF inline tax display helper. OPF defers all tax formatting to WooCommerce totals (wc_price / WC()->cart), so there is no per-price tax hook. |

## uploads

| wapf_hook | kind | rationale |
|---|---|---|
| wapf/htaccess_content | apply_filters | OPF stores uploads outside the web root and serves them through a REST route; no .htaccess protection file. |
| wapf/message/file_not_valid | apply_filters | OPF uses its own localized upload error strings; no message filters. |
| wapf/message/file_upload_error | apply_filters | OPF uses its own localized upload error strings; no message filters. |
| wapf/message/file_upload_error_general | apply_filters | OPF uses its own localized upload error strings; no message filters. |
| wapf/message/file_upload_logged_in | apply_filters | OPF private uploads do not require login; no equivalent message. |
| wapf/message/file_upload_nofield | apply_filters | OPF rejects orphan uploads at the REST route; no message filter. |
| wapf/message/upload_err_cant_write | apply_filters | OPF uses its own localized upload error strings; no message filters. |
| wapf/message/upload_err_ini_size | apply_filters | OPF uses its own localized upload error strings; no message filters. |
| wapf/message/upload_err_partial | apply_filters | OPF uses its own localized upload error strings; no message filters. |
| wapf/message/upload_err_too_big | apply_filters | OPF uses its own localized upload error strings; no message filters. |
| wapf/message/upload_err_too_many | apply_filters | OPF uses its own localized upload error strings; no message filters. |
| wapf/message/upload_err_type_unsupported | apply_filters | OPF uses its own localized upload error strings; no message filters. |
| wapf/message/upload_err_uploads_exceeded | apply_filters | OPF uses its own localized upload error strings; no message filters. |
| wapf/message/upload_error_code | apply_filters | OPF uses its own localized upload error strings; no message filters. |
