# Bulk choice import evidence

Audited capability: `WAPF-CHOICE-BULK-IMPORT` (WAPF Pro changelog v2.5).

## Browser verification

The isolated WordPress/WooCommerce browser run tested implementation commit
`78721c2` (based on public branch `f82a5c2`). Served `opf-builder.js` bytes
matched the tested worktree. Real authenticated admin actions exercised all
five choice field types, newline parsing, whitespace/blank lines, repeated and
duplicate labels, Unicode, commas, hostile-looking slugs, long-label slug
truncation, color parsing/defaults, live quantity controls, choice deletion,
REST save, reload, and authoritative database equality.

Result: **68/68 checks passed**, zero uncaught browser errors. Responsive
viewports checked: 320, 768, 1024, and 1440 CSS pixels. The test removed its
private field-group fixture during cleanup. Screenshots and machine-readable
results were inspected at run time under `/tmp/opf-bulk-artifacts/`.

```sh
OPF_WP_PATH=/tmp/opf-bulk-wp-e2e-20261001 \
OPF_BASE_URL=http://127.0.0.1:8133 \
OPF_E2E_LOGIN_URL='http://127.0.0.1:8133/?opf_bulk_login=1' \
PLAYWRIGHT_BROWSERS_PATH=/tmp/fy-playwright-browsers \
node bin/e2e-bulk-choice-wordpress-browser-test.mjs

OPF_BASE_URL=http://127.0.0.1:8133 \
OPF_E2E_LOGIN_URL='http://127.0.0.1:8133/?opf_bulk_login=1' \
PLAYWRIGHT_BROWSERS_PATH=/tmp/fy-playwright-browsers \
node bin/e2e-bulk-choice-wapf-reference.mjs
```

The second command passed against the installed WAPF 3.1.5 model and registered
bulk-options handler. Source comparison covered trimming, blank-line skipping,
duplicate retention, append behavior, fresh option IDs, and color parsing. The
export/parser roundtrip initially exposed loss of WAPF `checkboxes`; the OPF
mapper fix is committed at `8f256ce`, and actual WAPF model save/reload/export
to OPF import for select and checkboxes is recorded in
[`DISABLED-COMMERCE-ROUNDTRIP-EVIDENCE.md`](DISABLED-COMMERCE-ROUNDTRIP-EVIDENCE.md).

## Remaining scope

This proves the builder bulk-entry interaction and source/model behavior, not
the licensed WAPF Tools UI import/save/export lifecycle. OPF treats an invalid
color value as white, trims color components, and skips empty color labels;
those safer differences need explicit review against the exact WAPF handler
contract before this ledger row can be accepted. Product pricing/cart behavior
is covered by the separate choice-field rows, not by this authoring utility
proof.
