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
		{
			id: 'finish-copy', label: 'Existing ID collision', type: 'text', required: false, choices: [],
			conditionals: [
				{ action: 'show', logic: 'all', rules: [ { field: 'finish', operator: 'is', value: 'matte' }, { field: 'other', operator: 'is', value: 'yes' } ] },
				{ action: 'hide', logic: 'any', rules: [ { field: 'finish', operator: 'is', value: 'glossy' } ] },
			],
			pricing: { type: 'none', amount: 0, formula: '' },
		},
	],
	rule_groups: [],
};

await page.setContent('<!doctype html><html><body><input id="title" value="Fixture"><select id="opf-placement-cats" multiple></select><select id="opf-placement-tags" multiple></select><select id="opf-placement-auth"><option value="all">All visitors</option><option value="logged_in" selected>Logged in</option><option value="logged_out">Logged out</option></select><select id="opf-placement-role"><option value="">Any role</option><option value="editor" selected>Editor</option></select><select id="opf-placement-role-operator"><option value="in">Has role</option><option value="not_in" selected>Does not have role</option></select><select id="opf-placement-language"><option value="">All languages</option><option value="en_US" selected>English</option></select><select id="opf-placement-language-operator"><option value="in">Is</option><option value="not_in" selected>Is not</option></select><div id="opf-builder-app"></div></body></html>');
await page.evaluate((model) => {
	const mount = document.getElementById('opf-builder-app');
	mount.dataset.postId = '31';
	mount.dataset.nonce = 'fixture';
	mount.dataset.rest = '/wp-json/opf/v1/groups';
	mount.dataset.previewRest = '/wp-json/opf/v1/preview';
	mount.dataset.model = JSON.stringify(model);
	window.__opfSavedPayloads = [];
	window.wp = { media: (options) => {
		const handlers = {};
		return {
			on: (name, callback) => { handlers[name] = callback; },
			state: () => ({ get: () => ({ first: () => ({ toJSON: () => ({ id: 77, url: 'https://cdn.example.test/chosen.png' }) }) }) }),
			open: () => handlers.select(),
			options,
		};
	} };
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
const savedRules = await page.evaluate(() => window.__opfSavedPayloads[0].data.rule_groups[0].rules);
check('placement save retains authentication, role, and language conditions with product filters', savedRules.some((rule) => rule.subject === 'auth' && rule.operator === 'in') && savedRules.some((rule) => rule.subject === 'user_role' && rule.operator === 'not_in' && rule.terms[0] === 'editor') && savedRules.some((rule) => rule.subject === 'language' && rule.operator === 'not_in' && rule.terms[0] === 'en_US'));
await page.locator('.opf-b-field').nth(1).locator('.opf-b-label').fill('Custom finish');
check('source label remains unchanged after copy edit', await page.locator('.opf-b-field').first().locator('.opf-b-label').inputValue() === 'Finish');
await page.locator('.opf-b-field').first().locator('.button-link-delete').click();
check('deleting a field removes it and leaves the other fields', await page.locator('.opf-b-field').count() === 2);
await page.locator('.opf-b-toolbar button').nth(1).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 2);
const afterDelete = await page.evaluate(() => window.__opfSavedPayloads[1].data.fields);
check('delete removes dangling rules and drops condition groups left empty', afterDelete.length === 2 && afterDelete[1].conditionals.length === 1 && afterDelete[1].conditionals[0].rules.length === 1 && afterDelete[1].conditionals[0].rules[0].field === 'other');

await page.locator('.opf-b-toolbar button').first().click();
const paragraph = page.locator('.opf-b-field').last();
await paragraph.locator('select.opf-b-input').selectOption('paragraph');
check('paragraph type exposes plain-text content editor and disables required', await paragraph.locator('.opf-b-paragraph-content').count() === 1 && await paragraph.locator('input[type="checkbox"]').first().isDisabled() && ! await paragraph.locator('input[type="checkbox"]').first().isChecked());
await paragraph.locator('.opf-b-paragraph-content').fill('Read this first');
await page.locator('.opf-b-toolbar button').nth(1).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 3);
const withParagraph = await page.evaluate(() => window.__opfSavedPayloads[2].data.fields);
const savedParagraph = withParagraph[withParagraph.length - 1];
check('paragraph save persists content as a non-required, non-priced field', savedParagraph.type === 'paragraph' && savedParagraph.content === 'Read this first' && savedParagraph.required === false && savedParagraph.pricing.type === 'none' && savedParagraph.choices.length === 0);

await page.locator('.opf-b-toolbar button').first().click();
const image = page.locator('.opf-b-field').last();
await image.locator('select.opf-b-input').selectOption('image');
check('image type offers URL and WordPress media picker', await image.locator('.opf-b-image-url').count() === 1 && await image.locator('.opf-b-image-select').count() === 1);
await image.locator('.opf-b-image-select').click();
check('media selection stores attachment identity and fallback URL', await image.locator('.opf-b-image-url').inputValue() === 'https://cdn.example.test/chosen.png' && await image.locator('.opf-b-image-preview').count() === 1);
await image.locator('.opf-b-image-alt').fill('Chosen sample');
await page.locator('.opf-b-toolbar button').nth(1).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 4);
const withImage = await page.evaluate(() => window.__opfSavedPayloads[3].data.fields);
const savedImage = withImage[withImage.length - 1];
check('image save persists attachment, safe fallback URL, and alt text', savedImage.type === 'image' && savedImage.attachment_id === 77 && savedImage.image_url === 'https://cdn.example.test/chosen.png' && savedImage.alt_text === 'Chosen sample' && savedImage.required === false && savedImage.pricing.type === 'none');
check('no uncaught builder errors', errors.length === 0);
if ( errors.length ) console.log(errors.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
