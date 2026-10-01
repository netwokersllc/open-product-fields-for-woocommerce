import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '../assets/js/opf-builder.js'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

await page.setContent('<!doctype html><html><body><input id="title" value="Quantity limits"><div id="opf-builder-app"></div></body></html>');
await page.evaluate(() => {
	const mount = document.getElementById('opf-builder-app');
	mount.dataset.postId = '51';
	mount.dataset.nonce = 'fixture';
	mount.dataset.rest = '/wp-json/opf/v1/groups';
	mount.dataset.previewRest = '/wp-json/opf/v1/preview';
	mount.dataset.model = JSON.stringify({ fields: [ {
		id: 'prints', label: 'Prints', type: 'image_quantity', min_choices: 3, max_choices: 8,
		choices: [ { slug: 'oak', label: 'Oak', quantity: { default: 4, min: 0, max: 12 }, pricing: { type: 'none', amount: 0, formula: '' } } ],
		conditionals: [],
	} ], rule_groups: [] });
	window.__opfSavedPayloads = [];
	window.fetch = async (_url, options) => {
		window.__opfSavedPayloads.push(JSON.parse(options.body));
		return { json: async () => ({ id: 51 }) };
	};
});
await page.addScriptTag({ content: source });

const minimum = page.getByLabel('Minimum total quantity');
const maximum = page.getByLabel('Maximum total quantity');
if (await minimum.count() !== 1 || await maximum.count() !== 1) {
	console.log('FAIL image quantity editor does not expose aggregate limits');
	await browser.close();
	process.exit(1);
}
await minimum.fill('2');
await maximum.fill('9');
await page.getByRole('button', { name: 'Save' }).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 1);
const field = await page.evaluate(() => window.__opfSavedPayloads[0].data.fields[0]);
const ok = field.min_choices === 2 && field.max_choices === 9 && field.choices[0].quantity.max === 12 && errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} image quantity editor saves aggregate limits without changing choice limits`);
if (!ok) console.log(JSON.stringify({ field, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
