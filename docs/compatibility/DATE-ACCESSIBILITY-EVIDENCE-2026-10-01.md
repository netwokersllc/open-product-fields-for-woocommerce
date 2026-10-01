# Date-picker accessibility evidence

OPF's date picker is a native date input enhanced with a custom calendar.
Installed WAPF Extended 3.1.5 source uses non-focusable day spans, removes the
month/year controls from tab order, and has no keyboard day-navigation handler
or calendar roles. The published Extended 3.2.1 changelog says only that
date-picker accessibility was improved; exact new behavior is not documented
and the 3.2.1 package is not available locally.

## Executed browser checks

On a real Chromium page with the actual plugin frontend JS and CSS, **26/26
checks passed**. Coverage includes the calendar dialog, grid row/cell and day
button semantics, selected state, live month/year announcement, WordPress
configured Monday week start, display formatting, canonical ISO submission,
selection close/focus behavior, arrow movement over disabled days, Page Up/Down,
Shift+Page Up/Down year movement, Home/End by configured week, Escape focus
return, typed invalid-date custom validity, and clearing validity for an
allowed date. No browser page errors occurred.

```sh
node bin/e2e-date-blackout-browser-test.mjs
```

The test uses direct fixture markup and loads the actual `assets/js/opf-frontend.js`
and `assets/css/opf-frontend.css`; it does not claim a complete WordPress/Woo
product checkout flow. `RendererDateTest` verifies the configured week start
reaches rendered markup. Main-branch verification also passed `composer test`
(241 tests/954 assertions before the formula-import lane), PHP/JS syntax checks,
and `git diff --check`.

## Remaining gap

Without the exact Extended 3.2.1 package or a detailed official acceptance
contract, this proves OPF's accessible interaction and improves on the
installed 3.1.5 baseline, but cannot establish exact 3.2.1 parity. The ledger
row therefore remains `partial`.
