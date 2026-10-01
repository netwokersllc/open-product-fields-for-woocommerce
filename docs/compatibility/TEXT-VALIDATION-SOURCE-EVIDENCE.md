# WAPF text validation source evidence

Audit baseline: WAPF Extended 3.1.5 installed source and the OPF public branch
at `ccaefcfa4c740b07fff93aeda9c8e666581fe8b5` (2026-10-01).

## WAPF behavior and serialized keys

WAPF's WordPress.org 1.7.1 readme places the min/max text length and HTML
validation regex options in its **Premium Features** list, so this is paid Pro
capability (inherited by Extended), not a Free-tier feature.

The installed WAPF field configuration in
`advanced-product-fields-for-woocommerce-extended/includes/classes/class-config.php`
defines the Text field's length settings with the exact IDs `minlength` and
`maxlength`, and its advanced HTML5 regex control with ID `pattern`. The same
configuration describes the length values in characters and says either limit
can be left blank. The Textarea definition also has `minlength` and
`maxlength`, but no regex control.

WAPF's `views/admin/settings/text.php` binds each control to
`field.<id>`; its `includes/classes/class-field-groups.php` import path stores
the raw `minlength` and `maxlength` values in `field->options`, sanitizing them
as integers. `pattern` is not explicitly listed in this method, but is retained
by its generic extra-option path (`sanitize(..., 'textarea')`). The matching
export method serializes each field object and flattens every `options` member
onto the field object. Thus WAPF Tools payloads use top-level field keys
`minlength`, `maxlength`, and `pattern`; a mapper must read and emit these exact
keys. A nested `options` object would not match the observed Tools payload.

At render time, `includes/classes/class-html.php` adds HTML input attributes
from these option keys: non-empty `minlength`/`maxlength` become integer
attributes, and non-empty `pattern` is emitted verbatim. The Text template
renders `<input type="text" ...>` with those attributes. The official Pro
changelog records these as new Text field options for HTML5 pattern and
minimum/maximum character length; the official knowledge-base article explains
the pattern setting through an HTML5 regex example.

The inspected PHP cart path is narrower than the browser attributes. In
`includes/classes/class-cart.php::validate_cart_data`, the built-in validator
checks required values, multi-select choices, dates, numbers, and quantity
selectors, then invokes `wapf/validate` extension filters. It does not inspect
`minlength`, `maxlength`, or `pattern`. Therefore this source proves browser
constraint hints/validation and WAPF Tools key fidelity; it does **not** prove
server-side enforcement of these three Text constraints. OPF's strict forged
request rejection is a useful stronger behavior, but is not WAPF parity evidence
by itself.

## OPF baseline discrepancy

At the audit SHA, `WapfMapper::map_group()` maps text placeholder/default but
does not map the three WAPF validation keys. `WapfExporter::map_field()` neither
accepts OPF constraint properties nor emits the three WAPF keys. More broadly,
this audited SHA's `FieldGroup::normalize_field()`, `Renderer`, and
`FieldValue::validate()` do not contain text validation controls, attributes,
or server checks. The prior ledger description that those paths already
support the feature is not supported by the current public source snapshot; the
row must remain `partial` until implementation and the complete verification
matrix below are integrated.

## Acceptance / evidence matrix

| Path | Required proof | Status at audit SHA |
| --- | --- | --- |
| WAPF authoring/source | Exact text controls and values; text area applicability separated | Proven in installed WAPF 3.1.5 `class-config.php` |
| WAPF rendering semantics | Exact `minlength`/`maxlength`/`pattern` attributes and Text input type | Proven in installed WAPF 3.1.5 `class-html.php` and `views/frontend/fields/text.php` |
| WAPF Tools serialization | Export flattens option keys; importer restores integer limits and generic pattern option | Proven in installed WAPF 3.1.5 `class-field-groups.php` |
| OPF native schema/admin save and reload | Author each constraint, persist, reload, and preserve omitted vs zero/empty semantics | Not implemented/proven at this SHA |
| OPF frontend/browser validation | Render the same native constraints; test valid/invalid input and keyboard/form submission | Not implemented/proven at this SHA |
| OPF server/cart validation | Reject forged min, max, and regex violations through classic and Store API; valid submissions survive | Not implemented/proven at this SHA |
| OPF import/export round trip | Import top-level `minlength`, `maxlength`, `pattern`; export identical keys and values; verify OPF normalization retains them | Not implemented/proven at this SHA |
| Commerce lifecycle | Verify accepted values persist through checkout/order metadata and display paths, with no behavior regression | Not proven at this SHA |

## Primary source references

- Installed WAPF 3.1.5 source: `includes/classes/class-config.php` (Text and
  Textarea option definitions), `includes/classes/class-html.php` (frontend
  input attributes), `views/frontend/fields/text.php` (input template),
  `includes/classes/class-field-groups.php` (Tools import/export projection),
  and `includes/classes/class-cart.php` (`validate_cart_data`).
- [WAPF Pro changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/changelog/)
- [WAPF Free 1.7.1 readme / premium feature list](https://wordpress.org/plugins/advanced-product-fields-for-woocommerce/)
- [WAPF text-field validation regex guide](https://www.studiowombat.com/knowledge-base/text-field-validation-regex/)
- [WAPF field types guide](https://www.studiowombat.com/knowledge-base/all-field-types/)
