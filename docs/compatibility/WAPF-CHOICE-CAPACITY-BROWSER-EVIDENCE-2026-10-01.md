# WAPF choice capacity: authenticated builder evidence

**Result (2026-10-01 20:21 UTC):** OPF's real authenticated admin builder saved
and reloaded a field group containing **512 fields and 512 choices**. The run
edited the last field label in the browser, received HTTP 200 from the builder's
authenticated REST save, then verified all counts and boundary IDs after a new
admin page load and a direct read of the stored WordPress post content. All
17 browser/runtime checks passed; no uncaught JavaScript exceptions occurred.

This closes the missing reproducible 512-item save/reload proof for
`WAPF-CHOICE-CAPACITY`. It proves this tested capacity. It does not prove an
infinite capacity, compare the builder against an installed WAPF Pro package,
or cover WAPF import/export, storefront rendering, or commerce lifecycle paths.
The disposable clone had WAPF Free 1.7.1 active; no WAPF Pro package was
available there. Keep those distinctions when accepting or updating the
capability row.

## Source and implementation inspection

WAPF's official product page describes “unlimited date fields” and says “Add
as many choices as you like” ([WAPF Pro product page](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/)).
The claim is marketing-level; the page does not provide a numeric acceptance
threshold or a serialized Pro data contract.

On the tested OPF source at base commit
`4d8a5d8dac8239871f2bea70727bd73086740fb0`:

- `includes/Engine/FieldGroup.php:89-124` normalizes the field list by iterating
  the supplied items without a total field-count check.
- `includes/Engine/FieldGroup.php:139-152` normalizes each field's choices by
  iterating them without a total choice-count check.
- `includes/Service/Rest.php:30-50,91-112` registers the authenticated group
  save route, creates a `FieldGroup`, persists it, and returns normalized data.
- `includes/Service/FieldGroups.php:273-304` serializes the normalized group
  into the WordPress post content and calls `wp_insert_post`.

The 512/512 admin/browser and database round trip is runtime evidence for those
paths. The finite run is not a proof that all possible counts fit within every
hosting configuration's PHP, REST-body, database, or browser memory limits.

## Disposable runtime

- Source branch: `proof/wapf-choice-capacity`, isolated worktree
  `/tmp/opf-choice-capacity`; source base `4d8a5d8`.
- Configured `origin` is the local path
  `/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce`,
  not a hosted Git remote. `git ls-remote origin refs/heads/feat/opf-archive-import`
  returned `ed17517d3c8c50a6293c4b65e24ee012fbc154c8`; this is the configured
  local origin's current ref, not independent public-host verification.
- WordPress 7.1.2, WooCommerce 11.1.0, WAPF Free 1.7.1, OPF 0.1.0,
  PHP 8.5.11, Node v23.11.1, Chromium 147.0.7727.15.
- Disposable SQLite clone: `/tmp/opf-choice-capacity-wp`, served at
  `http://127.0.0.1:8149`. Its OPF plugin path was linked to the isolated
  worktree. The generated administrator password was held in a mode-600 file
  outside the repository and is not included here.

The first browser startup attempt exposed copied `WP_HOME`/`WP_SITEURL`
constants still pointing at port 8093. Only the disposable clone's config was
corrected to port 8149; the subsequent run used the clone and passed.

## Reproduce the run

From the FollowersYa workspace root (which provides the installed Playwright
package), with the disposable clone and local server above running:

```sh
OPF_BASE_URL=http://127.0.0.1:8149 \
OPF_WP_PATH=/tmp/opf-choice-capacity-wp \
OPF_E2E_PASSWORD_FILE=/tmp/opf-choice-capacity-password \
OPF_CHOICE_CAPACITY_E2E_ALLOW=1 \
node /tmp/opf-choice-capacity/bin/e2e-choice-capacity-browser-test.mjs
```

The runner creates one token-tagged group through the fixture, logs in as the
disposable administrator, loads and edits the actual Product Data builder, saves
through OPF's REST route, reloads the admin page, reads the persisted JSON, and
removes only its tagged group in `finally`. Field/choice counts can be varied
with `OPF_CHOICE_CAPACITY_FIELDS` and `OPF_CHOICE_CAPACITY_CHOICES` (fixture
guardrails: 2–2048 fields, 2–4096 choices). Explicit opt-in and a marked
`/tmp` WordPress root are required.

Fixture and runner:

- `bin/e2e-choice-capacity-fixture.php`
- `bin/e2e-choice-capacity-browser-test.mjs`

## Exact run result

The command above returned exit status 0:

```text
ok authenticated as the disposable administrator
ok admin builder loads the complete field count (512 fields in 1330ms)
ok admin builder loads the complete choice count (512 choices)
ok first and last field identifiers survive initial admin render
ok first and last choice identifiers survive initial admin render
ok builder save uses authenticated REST successfully (HTTP 200)
ok REST save response retains all fields and choices (512 fields / 512 choices)
ok REST response retains boundary labels and slugs
ok fresh admin reload retains all fields and choices (512 fields / 512 choices)
ok fresh admin reload retains first and last field identifiers
ok fresh admin reload retains the actual builder edit
ok fresh admin reload retains first and last choice identifiers
ok no uncaught JavaScript exceptions in admin browser (0)
ok database reload retains all fields and choices (512 fields / 512 choices)
ok raw database post content decodes with all fields and choices (512 fields / 512 choices)
ok database post content is valid UTF-8 JSON (227934 bytes)
ok tagged field-group fixture is removed (1 group(s))
{"fields":512,"choices":512,"checks":17,"passed":17,"failed":0}
```
