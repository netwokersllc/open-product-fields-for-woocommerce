# Product targeting lifecycle evidence

Executed 2026-10-01, final acceptance run 18:30–18:31 UTC; cleanup completed
18:32:19 UTC. Baseline: `4d9c6e20458933df09a1aef33964c3125aae8cfa`, plus
this change. Isolated worktree `/tmp/opf-product-targeting`, disposable SQLite
WordPress `/tmp/opf-product-woo`, loopback `http://127.0.0.1:8142`.
WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, Twenty Twenty-Five, and
isolated Chromium through Playwright. Active plugins: this worktree through
`opf-product`, WooCommerce, SQLite integration. WAPF remained inactive.
Mail was intercepted with `pre_wp_mail`. No production data, protected tabs,
or production configuration was changed.

## Installed source contract

Free 1.7.1 and Extended 3.1.5 expose `product`, `products`, `!product`, and
`!products`: scalar/list positive membership and its negative. The guarded
[source probe](../../bin/e2e-product-targeting-source.php) invokes each installed
source's private static predicate through reflection, with real WooCommerce
products. Both passed 24 checks covering selected/unselected simple products,
selected/unselected variable parents, and their variations. This is predicate
evidence; it does not claim a full WAPF storefront/checkout run.

The source files were copied from the installed disposable WordPress plugins:

```text
Free includes/classes/class-conditions.php
cd0d4554e8df7c7e1d33f6dbc9a3fc984212b9102179ba8ef8978de79e578f87
Extended includes/classes/class-conditions.php
166b358451c270bf7b0c8ca218c3e575b5c843791ec11a66045b1c6187578754
```

Free's predicate compares the supplied object's ID; Extended normalizes a
variation to its parent ID. OPF follows Extended's parent-product contract for
`product` placement in rendering, validation, capture, and order metadata.
Selecting a variation ID through `product` is therefore not exact variation
targeting; `WAPF-RULE-VARIATION` owns that separate capability. The Free direct
variation-object difference is documented rather than counted as exact parity.

## Native authoring and commerce

The native placement editor now offers searchable Include products and Exclude
products through WooCommerce's authenticated `wc-product-search` selector and
real `woocommerce_json_search_products` AJAX endpoint. Product names reload
into selected chips, and individual chips can be removed. Optional direct ID
inputs stay synchronized with those selectors. Comma-separated positive integer
IDs are validated before Save, duplicate
IDs are removed, and saved IDs reload into their controls. `in` means any listed
product; `not_in` excludes every listed product. Empty controls remove those
conditions. An unrelated save preserves existing product rules and OR groups;
editing product conditions replaces product predicates within each existing
OR group while preserving other subjects. Branches emptied by clearing a
condition are removed; nonempty OR branches retain their restrictions. Placement
becomes global only when every branch is empty. Existing category/customer controls
retain their established behavior.

The [real browser proof](../../bin/e2e-product-targeting-browser.mjs) passed
37 checks with zero uncaught page errors. Groups start without placement rules;
the real authenticated admin creates both positive and negative rules via the
native controls and actual REST Save, reloads them, and saves them unchanged.
It uses actual Woo AJAX product-name searches for both simple and parent products
in both include and exclude pickers, selects named results, removes the selected
product by name, and verifies persisted product names on reload.
It rejects malformed IDs without submission, proves include and exclude together,
clears inclusion while retaining exclusion, clears both to global placement,
and restores inclusion. It verifies that each of four storefronts renders only
the appropriate group. A forged wrong-group-only classic HTTP submission fails
the selected required field and leaves an empty cart. Actual classic browser
forms add selected and unselected products; the real block cart, block checkout,
Store API checkout response, and order confirmation contain the proper values.
Admin and cart screenshots were visually inspected.

The guarded [WooCommerce proof](../../bin/e2e-product-targeting.php) passed
50 checks. It reloads the real browser order through Woo's data store, checks
exact persisted targeting and metadata, and verifies group lookup for six real
products (two simple, two parents, two variations). Classic validation rejects
wrong-group-only submissions; successful submissions capture only the matched
group despite a forged unmatched group. Store API add-item does the same for
both simple products and both variations, and its cart display excludes forged
values. One actual Store API checkout of all four lines succeeds; reloaded
structured `_opf_fields` and public order metadata contain only the correct
group on each line. Final commerce stderr was empty.

The focused [real OR clearing regression](../../bin/e2e-product-or-clearing.mjs)
passed eight checks: a product-only OR branch plus a separate category branch;
clear product IDs, actual REST Save, and reload preserving the exact category
branch; then clear the category and reload global placement. The existing
builder OR-group regression passed four checks. Targeted
`EvaluatorTest`, `FieldGroupsAuthTest`, and `FieldGroupSchemaTest` passed
35 tests / 96 assertions; PHPUnit reported one existing metadata deprecation.
PHP and Node syntax and `git diff --check` passed.

## Reproduction and artifacts

Use a disposable `/tmp` WordPress/WooCommerce site, loopback-only server,
mail interception, and a fixture administrator. This run used a clone-only
loopback login helper reading `opf_product_e2e_state`; that helper is not shipped.
The site must have no other published OPF groups matching the fixture products.

```sh
OPF_PRODUCT_E2E_ALLOW=1 OPF_PRODUCT_E2E_PHASE=setup wp --path=/tmp/opf-product-woo eval-file bin/e2e-product-targeting.php
OPF_BASE_URL=http://127.0.0.1:8142 node bin/e2e-product-targeting-browser.mjs
OPF_PRODUCT_E2E_ALLOW=1 OPF_PRODUCT_E2E_PHASE=commerce wp --path=/tmp/opf-product-woo eval-file bin/e2e-product-targeting.php
OPF_PRODUCT_E2E_ALLOW=1 OPF_WAPF_SOURCE_PATH=/tmp/opf-product-woo/wp-content/plugins/advanced-product-fields-for-woocommerce wp --path=/tmp/opf-product-woo eval-file bin/e2e-product-targeting-source.php
OPF_PRODUCT_E2E_ALLOW=1 OPF_WAPF_EXTENDED=1 OPF_WAPF_SOURCE_PATH=/tmp/opf-product-woo/wp-content/plugins/advanced-product-fields-for-woocommerce-extended wp --path=/tmp/opf-product-woo eval-file bin/e2e-product-targeting-source.php
OPF_PRODUCT_E2E_ALLOW=1 OPF_PRODUCT_E2E_PHASE=cleanup wp --path=/tmp/opf-product-woo eval-file bin/e2e-product-targeting.php
```

Raw browser checks are retained in `product-targeting-browser-results.json`;
commerce and source output in `product-targeting-proof-results.txt`. Screenshots
and raw logs remain in `/tmp/opf-product-artifacts` for review. Cleanup removed
all fixture products, groups, administrator, state, and orders containing those
products. Woo's reloaded orders contained no surviving fixture order items;
cleanup stderr was empty. The loopback server was stopped. The disposable clone
and worktree remain available for reproduction and integration review.

```text
admin-positive.png b7c6d685fcf63936314c95fca98d7dbb8cd093ff19cd109206d025df7a3d9fe7
cart.png           ba729c1a7f957d695e2c08c411c1c193ac14ffdf068a30978f4d24d787b85b43
order-received.png 36f4bae5b8eae2503ccf4d0e26db6958c67189c5938b7921f5ee55137a21eb1a
```

## Search parity and OR clearing follow-up

Follow-up based on product commit `1e6a93c60198a46becd43fd38f4159787c0fe8fc`
(same public baseline above), executed 2026-10-01 at 18:43–18:48 UTC.
The original manual-ID-only editor was a real UX difference from WAPF and was
not accepted as search parity. It has been replaced by native searchable
include/exclude selectors with optional direct ID entry, preserving validation
and server targeting behavior. The refreshed 37 browser and 50 commerce checks
passed; the eight focused OR checks and 35-test / 96-assertion PHP regression
also passed. Latest admin and cart screenshots were visually inspected.

The [WAPF selector browser proof](../../bin/e2e-wapf-product-selector.mjs), on
the isolated `/tmp/opf-product-wapf-ux` clone at port 8143 with Free 1.7.1
active, passed five checks: actual product-name AJAX search, named selection
chip and numeric stored ID for both `products` and `!products`, and zero page
errors. Screenshots were visually inspected. Extended 3.1.5's source also
defines the searchable selector, but its actual admin UI in this clone reported
that features were disabled because it had no valid license. The Add your first
rule action produced no row. Extended browser selector parity is therefore not
claimed; the installed predicate contract remains separately proven above.
No license or source behavior was bypassed.

Raw follow-up checks are in `product-targeting-search-or-results.json` and the
refreshed browser/proof artifacts. Additional screenshots remain in
`/tmp/opf-product-or-artifacts` and `/tmp/opf-product-wapf-ux-artifacts`.
Both sites' final cleanup removed fixture products, groups, users, state, and
orders, verified no surviving fixture order items, and emitted no stderr. Both
loopback servers were stopped. The clones remain for integration review.
For the OR test, run `OPF_PRODUCT_E2E_PHASE=or_setup` between the standard setup
and browser phases, run its browser proof using the clone-only login helper's
`or_group` branch, then `OPF_PRODUCT_E2E_PHASE=or_cleanup` before commerce.

This closes the `WAPF-RULE-PRODUCT` baseline for native searchable
positive/negative product targeting, with the documented Free direct
variation-object difference. Exact variation targeting, import/export ownership,
Extended licensed admin behavior, and other themes/platform versions are not
accepted by these lifecycle checks.
