// Real Chromium coverage for duplicating a field without losing its settings.
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

await page.setContent('<!doctype html><html><body><input id="title" value="Duplicate fixture"><div id="opf-builder-app"></div></body></html>');
await page.evaluate(() => {
	const mount = document.getElementById('opf-builder-app');
	mount.dataset.postId = '42';
	mount.dataset.nonce = 'fixture';
	mount.dataset.rest = '/wp-json/opf/v1/groups';
	mount.dataset.previewRest = '/wp-json/opf/v1/preview';
	mount.dataset.model = JSON.stringify({
		fields: [
			{
				id: 'size', label: 'Size', description: 'Choose a size', type: 'select', required: true, width: 75,
				choices: [ { slug: 'large', label: 'Large', selected: true, disabled: false, pricing: { type: 'fixed', amount: 3.5, formula: '' } } ],
				pricing: { type: 'none', amount: 0, formula: '' },
				conditionals: [ { action: 'show', logic: 'all', rules: [ { field: 'color', operator: 'is', value: 'blue' } ] } ],
			},
			{ id: 'size-copy', label: 'Existing ID collision', type: 'text', required: false, width: 100, choices: [], pricing: { type: 'none', amount: 0, formula: '' }, conditionals: [] },
		],
		rule_groups: [],
	});
	window.__opfSavedPayloads = [];
	window.fetch = async (_url, options) => {
		window.__opfSavedPayloads.push(JSON.parse(options.body));
		return { json: async () => ({ id: 42 }) };
	};
});
await page.addScriptTag({ content: source });
const duplicate = page.locator('.opf-b-duplicate-field');
if ( ! await duplicate.count() ) {
	console.log('FAIL builder exposes a duplicate-field action');
	await browser.close();
	process.exit(1);
}
await duplicate.first().click();
await page.locator('.opf-b-toolbar button').nth(1).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 1);
const fields = await page.evaluate(() => window.__opfSavedPayloads[0].data.fields);
const expectedCopy = {
	id: 'size-copy-2', label: 'Size', description: 'Choose a size', type: 'select', required: true, width: 75,
	choices: [ { slug: 'large', label: 'Large', selected: true, disabled: false, pricing: { type: 'fixed', amount: 3.5, formula: '' } } ],
	pricing: { type: 'none', amount: 0, formula: '' },
	conditionals: [ { action: 'show', logic: 'all', rules: [ { field: 'color', operator: 'is', value: 'blue' } ] } ],
};
const check = (name, ok) => console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
check('duplicate is inserted after its source and receives a collision-free ID', fields.length === 3 && fields[1].id === 'size-copy-2');
check('duplicate preserves nested choices, pricing, and condition references', JSON.stringify(fields[1]) === JSON.stringify(expectedCopy));
check('source and existing fields remain unchanged', fields[0].id === 'size' && fields[0].label === 'Size' && fields[2].id === 'size-copy');
check('no uncaught builder errors', errors.length === 0);
if ( errors.length ) console.log(errors.join('\n'));
await browser.close();
process.exit(fields.length === 3 && JSON.stringify(fields[1]) === JSON.stringify(expectedCopy) && errors.length === 0 ? 0 : 1);
