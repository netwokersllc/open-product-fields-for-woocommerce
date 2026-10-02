# WAPF-GROUP-ADMIN-TITLE-SEARCH browser proof — 2026-10-02

## Scope

The 2026-10-01 supported-row audit flagged this ledger row because the cited
authenticated browser evidence was absent. OPF registers field groups as the
`opf_field_group` CPT (`includes/Service/FieldGroups.php::register_cpt`,
`show_ui => true`, `supports => [ 'title' ]`, searchable under WooCommerce →
Field Groups), so WordPress core admin list title search is the entire
implementation. This lane supplies the missing reproducible authenticated
browser proof only; no production site was touched.

## Environment

- Disposable SQLite clone `/tmp/opf-group-duplicate-browser-clone`
  (marker `.opf-disposable-e2e`), WordPress 7.1.2, WooCommerce 11.1.0,
  WAPF Free 1.7.1 active, PHP 8.5.11, served by `wp server` on loopback
  `http://127.0.0.1:8093` (`WP_HOME`/`WP_SITEURL` in `wp-config.php`).
- The clone's `wp-content/plugins/open-product-fields-for-woocommerce` is a
  real directory; for the run it was moved to a unique `/tmp` backup and
  replaced by a symlink to this worktree so the audited code served requests.
  `wp eval 'echo OPF_DIR'` resolved to `/tmp/opf-admin-title-search-20261002/`.
  `opf-dev-dup` was deactivated during the run because a second OPF copy would
  redeclare `opf_boot()`. Both changes were reverted by a trap on exit.
- Browser: Chromium 147.0.7727.15 via Playwright 1.59.1
  (`/tmp/node_modules`), viewport 1920x1080, authenticated through
  `wp-login.php` as `admin` with the clone password file.
- Fixture: one published `opf_field_group` titled
  `OPF Admin Title Search <token>` (post ID 9248110 this run) created via
  `wp eval-file bin/e2e-admin-search-fixture.php create <token>` and tagged
  `_opf_admin_search_fixture` for scoped cleanup. The clone held exactly 14
  pre-existing groups before the run.

## Assertions (all passed, 8/8)

Authenticated `GET /wp-admin/edit.php?post_type=opf_field_group`:

1. `h1.wp-heading-inline` contains `Field Groups` and `#post-search-input` /
   `#search-submit` render with `value="Search Field Groups"` (the CPT's
   `search_items` label).
2. Unfiltered `#the-list` renders `tr#post-<id>` whose `.row-title` text
   equals the exact fixture title.
3. Submitting the exact title navigates to
   `edit.php?post_type=opf_field_group&s=OPF+Admin+Title+Search+<token>`
   (asserted via `URL.searchParams`).
4. That result set is exactly one row: `tr#post-<id>` with the fixture's
   `.row-title`.
5. Searching the distinct term `WXR E2E` (matches pre-existing clone groups)
   returns rows but never `tr#post-<id>` and never the fixture title.
6. Searching `zz-nomatch-<token>` returns `tr.no-items` ("No … found") and
   zero fixture rows.
7. Zero uncaught `pageerror` events across login, list, and all searches.
8. Cleanup deleted exactly the one tagged fixture; `post list --format=count`
   returned to the 14-group baseline.

## Run output

```text
$ bash /tmp/run-admin-title-search-proof.sh
== before: active plugins / group count ==
["advanced-product-fields-for-woocommerce\/advanced-product-fields-for-woocommerce.php","opf-dev-dup\/open-product-fields-for-woocommerce.php","sqlite-database-integration\/load.php","woocommerce\/woocommerce.php","wordpress-importer\/wordpress-importer.php"]
14
Plugin 'opf-dev-dup' deactivated.
Plugin 'open-product-fields-for-woocommerce' activated.
served OPF_DIR: /tmp/opf-admin-title-search-20261002/
browser: Chromium 147.0.7727.15, viewport 1920x1080
baseline opf_field_group count 14; fixture post-9248110
ok authenticated group list heading and CPT-labelled search box render
ok unfiltered list renders the fixture row with its exact title
ok title search submits to the CPT list with the term in the URL
ok exact-title search returns exactly one row, the fixture itself
ok a distinct matching term lists other groups while the fixture disappears
ok a non-matching term shows the list-table empty state and no fixture row
ok no uncaught browser errors during the whole flow
ok fixture cleanup removed 1 tagged group(s); group count 14 -> 14
== after: group count before restore ==
14
Plugin 'open-product-fields-for-woocommerce' deactivated.
Plugin 'opf-dev-dup' activated.
```

Post-run verification: `open-product-fields-for-woocommerce` is again a real
directory (backup moved back, symlink removed), `active_plugins` is byte-for-byte
the pre-run list, and the clone still holds exactly 14 groups with no
`_opf_admin_search_fixture` rows left.

## Reproduce

```sh
# plugin swap is trap-restored by the wrapper; the test itself is:
cd /tmp   # playwright resolves from /tmp/node_modules
wp --path=/tmp/opf-group-duplicate-browser-clone server --host=127.0.0.1 --port=8093 &
OPF_WP_PATH=/tmp/opf-group-duplicate-browser-clone \
OPF_E2E_PASSWORD_FILE=/tmp/opf-group-duplicate-browser-password \
  node /tmp/opf-admin-title-search-20261002/bin/e2e-admin-title-search-browser-test.mjs
```

Scripts: `bin/e2e-admin-search-fixture.php` (WP-CLI fixture create/cleanup),
`bin/e2e-admin-title-search-browser-test.mjs` (guarded Chromium proof).

## Remaining limits

The proof covers single-term and multi-word title search on the core CPT list
for an administrator. Quick-edit/bulk-row behavior, search by content/excerpt
(core `s` semantics already cover those), non-administrator capability
combinations, and WAPF's own list UI on the same screen are unchanged core
paths and out of scope for this row.
