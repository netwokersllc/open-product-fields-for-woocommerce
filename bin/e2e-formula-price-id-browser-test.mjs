import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const frontendScript = fs.readFileSync(path.join(here, '../assets/js/opf-frontend.js'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage();
const pageErrors = [];
const consoleErrors = [];
page.on('pageerror', (error) => pageErrors.push(error.message));
page.on('console', (message) => {
	if (message.type() === 'error') consoleErrors.push(message.text());
});

let failures = 0;
const check = (name, ok, details = '') => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}${ok || !details ? '' : `: ${details}`}`);
	if (!ok) failures++;
};

try {
	await page.setContent(`<!doctype html><html><body>
		<form class="cart">
			<input class="qty" name="quantity" type="number" value="1">
			<div class="opf-product-totals" data-product-price="100">
				<span class="opf-product-total"></span>
				<span class="opf-options-total"></span>
				<span class="opf-grand-total"></span>
			</div>
			<div data-opf-fields>
				<div data-opf-group="early">
					<div data-opf-field="source">
						<label><input type="radio" name="source" value="chosen" checked> Chosen</label>
					</div>
				</div>
				<div data-opf-group="later">
					<div data-opf-field="derived">
						<label for="derived-value">Multiplier</label>
						<input id="derived-value" type="text" value="2">
					</div>
				</div>
			</div>
		</form>
	</body></html>`);
	await page.addScriptTag({ content: `window.OPF_FIELDS = ${JSON.stringify({
		early: {
			source: {
				type: 'radio',
				choices: [{ slug: 'chosen', disabled: false, pricing: { type: 'fixed', amount: 7 } }],
				pricing: { type: 'none', amount: 0, formula: '' },
			},
		},
		later: {
			derived: {
				type: 'text',
				choices: [],
				pricing: { type: 'formula', amount: 0, formula: '[price.source] * 2' },
			},
		},
	})};` });
	await page.addScriptTag({ content: frontendScript });

	const total = async (selector) => (await page.locator(selector).innerText()).trim();
	check('group totals include earlier choice pricing and later formula pricing', await total('.opf-options-total') === '$21.00');
	check('later formula resolves [price.ID] from earlier group', await total('.opf-grand-total') === '$121.00');

	await page.locator('[data-opf-field="source"]').evaluate((node) => { node.hidden = true; });
	await page.locator('#derived-value').dispatchEvent('input');
	await page.waitForFunction(() => document.querySelector('.opf-grand-total')?.textContent === '$100.00', { timeout: 2000 });
	check('hidden earlier source is excluded from totals and [price.ID]', await total('.opf-options-total') === '$0.00' && await total('.opf-grand-total') === '$100.00');

	await page.locator('[data-opf-field="source"]').evaluate((node) => { node.hidden = false; });
	await page.locator('#derived-value').dispatchEvent('input');
	await page.waitForFunction(() => document.querySelector('.opf-grand-total')?.textContent === '$121.00', { timeout: 2000 });
	check('showing the source restores the cross-group formula total', await total('.opf-grand-total') === '$121.00');
	check('Chromium page has no JavaScript errors', pageErrors.length === 0 && consoleErrors.length === 0, JSON.stringify({ pageErrors, consoleErrors }));
} catch (error) {
	failures++;
	console.error(`FAIL browser run: ${error.stack || error}`);
} finally {
	await browser.close();
}

if (failures) process.exit(1);
