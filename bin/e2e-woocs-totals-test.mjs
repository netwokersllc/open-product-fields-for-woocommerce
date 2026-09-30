// Real Chromium check for base-currency calculation and selected-currency output.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '../assets/js/opf-frontend.js'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
await page.setContent(`<!doctype html><html><body>
<form class="cart variations_form"><input name="quantity" value="2"></form>
<div data-opf-fields><div data-opf-group="1">
<div data-opf-field="addon"><span data-opf-choice-hint="five"></span><span data-opf-choice-hint="ten"></span><input type="radio" name="addon" value="five" checked></div>
<div data-opf-field="formulaAddon"><input type="text" name="formulaAddon" value="v"><span data-opf-field-hint></span></div>
</div></div>
<div class="opf-product-totals" data-product-price="100" data-opf-currency-rate="1.25">
<span class="opf-product-total"></span><span class="opf-options-total"></span><span class="opf-grand-total"></span>
</div></body></html>`);
await page.evaluate(() => {
	window.OPF_FIELDS = { '1': {
		addon: { type: 'radio', choices: [ { slug: 'five', pricing: { type: 'fixed', amount: 5 } }, { slug: 'ten', pricing: { type: 'percent', amount: 10 } } ] },
		formulaAddon: { type: 'text', pricing: { type: 'formula', formula: '[price]*0.1' } }
	} };
	window.opf_config = { display_options: { symbol: '€', decimals: 2, thousand: ' ', decimal: ',', trim_zeroes: true, format: '{price} {symbol}' } };
	window.OPF_PRICE_HINTS = { show: true, format: '(+{x})' };
	window.__opfCurrencyHandlers = {};
	window.jQuery = () => {
		const chain = { on: (name, callback) => { window.__opfCurrencyHandlers[name] = callback; return chain; } };
		return chain;
	};
});
await page.addScriptTag({ content: source });
const checks = [];
const check = (name, ok) => { console.log(`${ok ? 'ok' : 'FAIL'} ${name}`); if (!ok) checks.push(name); };
const totals = async () => page.evaluate(() => [ '.opf-product-total', '.opf-options-total', '.opf-grand-total' ].map((selector) => document.querySelector(selector).textContent));
check('base-currency formula displays converted totals in selected currency format', JSON.stringify(await totals()) === JSON.stringify([ '250,00 €', '18,75 €', '268,75 €' ]));
const hints = async () => page.evaluate(() => [ '[data-opf-choice-hint="five"]', '[data-opf-choice-hint="ten"]', '[data-opf-field-hint]' ].map((selector) => document.querySelector(selector).textContent));
check('fixed, percent, and formula hints use converted currency without changing percentages', JSON.stringify(await hints()) === JSON.stringify([ '(+6,25 €)', '(+10%)', '(+12,5 €)' ]));
await page.evaluate(() => window.__opfCurrencyHandlers['found_variation.opfCurrency'](null, { opf_currency_base: 200, opf_currency_rate: 1.5 }));
check('variation currency payload updates totals and their hints', JSON.stringify(await totals()) === JSON.stringify([ '600,00 €', '37,50 €', '637,50 €' ]) && JSON.stringify(await hints()) === JSON.stringify([ '(+7,5 €)', '(+10%)', '(+30 €)' ]));
await page.evaluate(() => window.__opfCurrencyHandlers['reset_data.opfCurrency']());
check('reset restores parent currency context', JSON.stringify(await totals()) === JSON.stringify([ '250,00 €', '18,75 €', '268,75 €' ]) && JSON.stringify(await hints()) === JSON.stringify([ '(+6,25 €)', '(+10%)', '(+12,5 €)' ]));
check('no uncaught frontend errors', errors.length === 0);
if ( errors.length ) console.log(errors.join('\n'));
await browser.close();
process.exit(checks.length ? 1 : 0);
