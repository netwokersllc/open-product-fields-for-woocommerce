# Upload checkout proof blocked by absent implementation

Observed 2026-10-01 20:28 UTC against requested source base
`f82a5c2961bb62a8060ebb6d4fdc45c6a71ad82a` in isolated worktree
`/tmp/opf-upload-checkout-proof`, branch `proof/upload-checkout-20261001`.

The current ledger's `WAPF-FIELD-UPLOAD` and `WAPF-UPLOAD-AJAX-UI` rows
describe an upload implementation that is absent from this source revision.
Store API upload checkout cannot be proved against this revision.

## Exact responsible paths

- `includes/Engine/FieldGroup.php:25`: `FIELD_TYPES` excludes `upload`.
- `includes/Engine/FieldGroup.php:133`: `normalize_field()` changes unsupported
  types to `text` at lines 135–136. An input field with type `upload` therefore
  becomes a text field before it reaches the renderer or cart integration.
- `includes/Engine/UploadService.php` is absent from the source tree.
- `open-product-fields-for-woocommerce.php` has no upload service registration.
- `includes/Service/CartIntegration.php` has no upload handling.

## Disposable runtime evidence

A new `/tmp/opf-upload-checkout-wp` install uses fresh SQLite data, copied
WordPress/WooCommerce binaries, and this worktree as its only OPF plugin.
No shared database was copied. Only WooCommerce and OPF are active. No
production or shared clone was mutated.

Command:

```sh
wp --path=/tmp/opf-upload-checkout-wp eval-file /tmp/opf-upload-checkout-proof/bin/probe-upload-availability.php
```

Exit status: **1**, the expected prerequisite failure. Observed output:

```json
{
    "wordpress": "7.1.2",
    "woocommerce": "11.1.0",
    "active_plugins": [
        "opf-upload-proof/open-product-fields-for-woocommerce.php",
        "woocommerce/woocommerce.php"
    ],
    "upload_service_exists": false,
    "upload_type_supported": false,
    "normalized_upload_type": "text",
    "upload_routes": []
}
```

A real localhost HTTP request confirms route absence independently:

```sh
curl --silent --show-error --include --request POST \
  --form product_id=1 --form group_id=1 --form field_id=art \
  'http://127.0.0.1:8157/?rest_route=/opf/v1/uploads'
```

Response at 2026-10-01 20:28:40 UTC: **HTTP 404**, JSON code
`rest_no_route`, status 404. This is a route-availability probe, not upload
or checkout lifecycle proof.

`git ls-tree -r HEAD --name-only includes/Engine/UploadService.php
includes/Service/UploadService.php` returned no paths.

The runtime probe is pinned to source base `f82a5c2`. Subsequent reviewed
changes on the current feature branch add disabled-choice/order-again handling,
WAPF checkbox import mapping, and bulk choice builder UX; none adds upload
service files or routes. Re-check the current branch before relying on this
finding after any later upload implementation.

## Remaining acceptance paths

Upload implementation must first be integrated into the authoritative source.
Ajax upload, disabled modern-uploader native fallback, Store API cart
attachment and checkout, persisted order file references, authorized
download ownership, absence of publicly served bytes, cleanup, and order-again
remain unproved on this revision. No edition row can be promoted from this
finding. The previously installed upload snapshot is a separate source and
was inspected read-only only to diagnose the mismatch.
