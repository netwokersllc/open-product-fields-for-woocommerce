# Aelia Currency Switcher — real licensed plugin parity (2026-10-03)

Resolves the candidate divergence flagged by the faithful-stub run
(`WAPF-CURRENCY-AELIA`, currproof lane). Real licensed Aelia Currency Switcher
for WooCommerce (Freemius premium build, user-supplied) + free Aelia Foundation
Classes, activated on a disposable clone (`/tmp/opf-image-aelia-wp`, :8331).
Woo 11.1, USD base, EUR rate 2 (manual), selected via `wc_aelia_cs_selected_currency`.

Fixture (identical to stub run): product USD 10; fields flat +3, percent 10%,
formula `[price]`. Expected shop-currency line 24.

## Real-plugin results

| Probe | WAPF Extended 3.1.5 | OPF (pre-fix) | OPF (post-fix) |
|---|---|---|---|
| product view price | 20 | 20 | 20 |
| cart view price | 50 | 48 | **50** |
| cart edit price | 50 | 48 | **50** |
| cart subtotal | 50 | 48 | **50** |
| order line total | 50 | 48 | **50** |
| `wapf_item_pricing` | base 20, options_total 30 | — | — |

## What the real plugin proved

WAPF's cart base is the **already-converted** view price (`base 20`), percent
addons compute on that converted base (`10% × 20 = 2`), flat/formula amounts
stay in shop currency (`3` / `[price]` = 10), and the resulting options total
(`15`) is converted **again** (`→ 30`) — a double-conversion of the percent
component that real WAPF emits as line 50.

OPF previously computed everything on the shop base and converted the whole
line once (`24 × 2 = 48`) — a real €2 behavioral difference, not a stub
artifact.

## Fix (AeliaIntegration)

- `cart_base_price` returns the converted view price (was: divided by rate) so
  percent addons compute on the converted base exactly like WAPF.
- `convert_cart_prices` emits `conv_base + convert(price − conv_base)` —
  converted base plus the converted options total — mirroring WAPF's
  `base + options_total` shape including the percent double-conversion.
- Formula `[price]` still resolves on the original shop price via
  `opf_formula_base_price`/`original_product_price` (real Aelia does not
  convert `get_price('edit')`, verified: formula contributed 10, not 20).
- Manual per-currency product prices flow through unchanged (fresh
  `get_price()` honors them, matching WAPF's base semantics).

Harnesses: `opf-aelia-real.php`, `wapf-aelia-real.php` (in repo `bin/` or
lane evidence dir). 7 AeliaIntegration unit tests updated to the verified
pipeline; full suite 677 tests / 2785 assertions green.
