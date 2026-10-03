# Hidden first duplicate price reference

Base: `fa462976c3190716dbdfd5cacdf111bea3b1b0bc`. Narrow fix only; this document does not close the capability ledger row.

The browser skipped hidden fields before recording their price reference. A later visible duplicate then supplied `[price.source]`. Preserve the first DOM source's slot as zero while still charging the later duplicate's own price. The original PHP zero reservation was removed after independent review and native WAPF server proof: server references scan actual submitted cart fields, rather than every configured field. Missing first fields/groups must not reserve a synthetic zero. PHP product code is identical to the base SHA.

Source inspected: installed WAPF Extended 3.1.5, `includes/classes/class-helper.php:656` uses the first cart field with a matching ID and clone index. Its unchanged `assets/js/frontend.min.js` exposes `WAPF.Util.replaceFx`, which selects `.input-ID` with `.first()` regardless of visibility. `getFieldPrice` reads its price data. The reference browser test establishes the lookup behavior for a first source whose price data is zero; it does not establish how every WAPF visibility transition changes price data.

Verification on 2026-10-03:

- Baseline Chromium: initial DOM includes first zero-priced radio, later duplicate priced $11, and formula `[price.source] * 2`. Visible first passes. Hidden first fails: options $33 instead of $11. Restoring first passes. No JavaScript errors.
- Initial PHP test incorrectly expected zero for an excluded first source. That expectation was withdrawn after native WAPF runtime proof; it does not establish a baseline PHP bug.
- Patched Chromium: all eight checks pass, including prior cross-group source show/hide/restore checks.
- Unchanged PHP: five checks pass: visible first yields 32, zero first yields 11, excluded hidden first yields 33, missing field yields 33, missing group yields 33.
- Native WAPF Extended 3.1.5 controller plus cart calculation on disposable SQLite WordPress/WooCommerce 11.1.0: both submitted duplicate groups create two entries and reference `0 * 2`; first group absent creates one entry and reference `11 * 2`; no submitted source creates no cart entries; helper with only later entry resolves `11`. Fixture product is deleted in `finally`. Duplicate IDs share the same request key, so submitting the second independently while retaining both groups is not representable by the native request format.
- Installed WAPF helper in real Chromium: four checks pass, including hidden first zero price and visible first nonzero price. Script bytes are loaded unchanged.
- Existing focused Node tests: 14/14 pass. Existing quantity-repeat Chromium regression passes. PHP syntax and `git diff --check` pass.

Commands (from this worktree, Playwright supplied by the workspace):

```sh
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules node bin/e2e-formula-price-id-browser-test.mjs
php bin/test-formula-price-id-duplicates.php
wp --path=/tmp/opf-priceid-wapf-server-20261003 eval-file bin/e2e-formula-price-id-wapf-server.php
node --test tests/js/opf-pricing-per-unit.test.cjs tests/js/opf-formula-date.test.cjs
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules node tests/js/opf-qty-repeat-pricing-browser-test.mjs
WAPF_FRONTEND_SCRIPT=/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended/assets/js/frontend.min.js WAPF_JQUERY_SCRIPT=/home/followersya-5hqi7/followersya.com/bedrock/web/wp/wp-includes/js/jquery/jquery.min.js NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules node bin/e2e-formula-price-id-wapf-reference.mjs
php -l includes/Service/CartIntegration.php
git diff --check
```

Browser baseline was verified from an isolated detached worktree at the base SHA using `OPF_FRONTEND_SCRIPT`. The native server clone copied a disposable SQLite site and took a read-only SQLite backup into its own database; plugin changes were restricted to that clone. The first server fixture attempt omitted WAPF field metadata and failed; supplying native Config field metadata produced the recorded passing results. One initial quantity-repeat invocation used the wrong working directory and exited with ENOENT; rerunning from the worktree passed.

Remaining proof: full OPF/WAPF WordPress storefront comparison, admin/import fidelity, sanitized actual cart/checkout/order lifecycle, duplicate clone rows and section repeats, backwards references/cycles, tax and supported-version behavior. Native WAPF controller/cart functions, PHP lookup stubs and the installed browser helper are focused regression evidence, not a complete commerce comparison. The browser/server difference for absent versus DOM-present hidden sources remains explicit. No production writes were performed.
