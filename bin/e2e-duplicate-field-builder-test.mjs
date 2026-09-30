// Real Chromium DOM harness for field duplication; WordPress REST is mocked.
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
page.on('console', (message) => { if ( 'error' === message.type() ) errors.push(message.text()); });
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if ( ! ok ) failures++;
};

const initial = {
	fields: [
		{
			id: 'finish', label: 'Finish', description: 'Choose a finish', type: 'select', required: true, width: 70,
			choices: [ { slug: 'matte', label: 'Matte', selected: true, disabled: false, pricing: { type: 'fixed', amount: 4, formula: '' }, extra: { swatch: '#555' } } ],
			conditionals: [ { type: 'show', rules: [ { field_id: 'material', operator: 'equals', value: 'metal' } ] } ],
			pricing: { type: 'formula', amount: 0, formula: '[price] + 3' },
			custom: { nested: [ 1, { keep: true } ] },
		},
		{ id: 'finish-copy', label: 'Existing ID collision', type: 'text', required: false, choices: [], conditionals: [], pricing: { type: 'none', amount: 0, formula: '' } },
	],
	rule_groups: [],
};

await page.setContent('<!doctype html><html><body><input id="title" value="Fixture"><select id="opf-placement-cats" multiple></select><select id="opf-placement-tags" multiple></select><div id="opf-builder-app"></div></body></html>');
await page.evaluate((model) => {
	const mount = document.getElementById('opf-builder-app');
	mount.dataset.postId = '31';
	mount.dataset.nonce = 'fixture';
	mount.dataset.rest = '/wp-json/opf/v1/groups';
	mount.dataset.previewRest = '/wp-json/opf/v1/preview';
	mount.dataset.model = JSON.stringify(model);
	window.__opfSavedPayloads = [];
	window.fetch = async (_url, options) => {
		window.__opfSavedPayloads.push(JSON.parse(options.body));
		return { json: async () => ({ id: 31 }) };
	};
}, initial);

await page.addScriptTag({ content: source });
const cards = page.locator('.opf-b-field');
check('each field offers a duplicate action', await cards.count() === 2 && await page.locator('.opf-b-duplicate').count() === 2);
await page.locator('.opf-b-duplicate').first().click();
check('duplicate is inserted immediately after its source with WAPF copy label', await page.locator('.opf-b-field').count() === 3 && await page.locator('.opf-b-field').nth(1).locator('.opf-b-label').inputValue() === 'Finish (Copy)');

await page.locator('.opf-b-toolbar button').nth(1).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 1);
const saved = await page.evaluate(() => window.__opfSavedPayloads[0].data.fields);
const sourceField = saved[0];
const copy = saved[1];
check('copy preserves every field setting while receiving a collision-free ID', copy.id === 'finish-copy-2' && copy.label === 'Finish (Copy)' && JSON.stringify({ ...copy, id: sourceField.id, label: sourceField.label }) === JSON.stringify(sourceField));
await page.locator('.opf-b-field').nth(1).locator('.opf-b-label').fill('Custom finish');
check('source label remains unchanged after copy edit', await page.locator('.opf-b-field').first().locator('.opf-b-label').inputValue() === 'Finish');
check('no uncaught builder errors', errors.length === 0);
if ( errors.length ) console.log(errors.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
