# Historical percentage coupon scope source check — 2026-10-01

Historical snapshot only. It records that the capability was absent at
`983c054`; public commit `f03979d` now implements and verifies a first slice.
The ledger status is `partial` until the remaining lifecycle and integration
proof listed there passes.

The public OPF feature branch was verified at
`983c0542128a8ef27ac09db6486d34a8c759b50e` using the explicit GitHub remote:

```sh
git ls-remote https://github.com/networkersoss/open-product-fields-for-woocommerce.git refs/heads/feat/opf-archive-import
```

At that commit, read-only searches of `includes/`, `tests/`, and `bin/` found no
OPF percentage-coupon exclusion setting, WooCommerce coupon discount bridge,
helper, coupon regression test, or disposable coupon E2E artifact. The public
tree therefore does not substantiate the previous partial-status ledger
description; that row is corrected to `gap` until an implementation is
reviewed and its evidence is integrated.

The comparison contract was read from installed WAPF Extended 3.1.5 source at
`includes/controllers/class-integrations-controller.php:35-76`. Its opt-in is
per coupon, stored as `wapf_excl_addons`, applies to percentage coupons, and is
absent by default. The checkbox is presented only for percentage coupons.
WAPF's calculation excludes option prices for eligible applied quantities;
fixed-product and fixed-cart coupons are not changed by this setting.

At the historical snapshot, the isolated implementation was still in progress.
See the current [coupon scope evidence](coupon-scope-proof.md) and capability
ledger for the reviewed public implementation and remaining gaps.
