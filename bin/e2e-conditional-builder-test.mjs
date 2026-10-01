// Real Chromium coverage for editing field visibility conditions.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '../assets/js/opf-builder.js'), 'utf8');
const css = fs.readFileSync(path.join(here, '../assets/css/opf-builder.css'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 320, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

await page.setContent('<!doctype html><html><body><input id="title" value="Condition fixture"><div id="opf-builder-app"></div></body></html>');
await page.addStyleTag({ content: css });
await page.evaluate(() => {
	const mount = document.getElementById('opf-builder-app');
	mount.dataset.postId = '43';
	mount.dataset.nonce = 'fixture';
	mount.dataset.rest = '/wp-json/opf/v1/groups';
	mount.dataset.previewRest = '/wp-json/opf/v1/preview';
	mount.dataset.model = JSON.stringify({
		fields: [
			{ id: 'source-text', label: 'Source text', type: 'text', required: false, width: 100, choices: [], pricing: { type: 'none', amount: 0, formula: '' }, conditionals: [] },
			{ id: 'source-toggle', label: 'Source toggle', type: 'toggle', required: false, width: 100, choices: [], pricing: { type: 'none', amount: 0, formula: '' }, conditionals: [] },
			{ id: 'target', label: 'Target field', type: 'text', required: false, width: 100, choices: [], pricing: { type: 'none', amount: 0, formula: '' }, conditionals: [] },
		],
		rule_groups: [],
	});
	window.__opfSavedPayloads = [];
	window.fetch = async (_url, options) => {
		window.__opfSavedPayloads.push(JSON.parse(options.body));
		return { json: async () => ({ id: 43 }) };
	};
});
await page.addScriptTag({ content: source });
await page.locator('.opf-b-field').nth(0).getByRole('button', { name: 'Duplicate field' }).click();
const target = page.locator('.opf-b-field').nth(3);
await target.getByRole('button', { name: '+ Add condition' }).click();
let groups = target.locator('.opf-b-conditional-group');
await groups.nth(0).locator('[aria-label="Visibility action"]').selectOption('hide');
await groups.nth(0).locator('[aria-label="How to combine rules"]').selectOption('any');
await groups.nth(0).getByRole('button', { name: '+ Add rule' }).click();
await groups.nth(0).locator('.opf-b-conditional-rule').nth(0).locator('[aria-label="Condition field"]').selectOption('source-text-copy');
await groups.nth(0).locator('.opf-b-conditional-rule').nth(0).locator('[aria-label="Condition operator"]').selectOption('contains');
await groups.nth(0).locator('.opf-b-conditional-rule').nth(0).locator('[aria-label="Condition value"]').fill('blocked');
await groups.nth(0).locator('.opf-b-conditional-rule').nth(1).locator('[aria-label="Condition field"]').selectOption('source-toggle');
await groups.nth(0).locator('.opf-b-conditional-rule').nth(1).locator('[aria-label="Condition value"]').selectOption('1');
await target.getByRole('button', { name: '+ Add condition' }).click();

const conditionLayoutFits = await page.evaluate(() => {
	const rule = document.querySelector('.opf-b-conditional-rule');
	return rule.scrollWidth <= rule.clientWidth;
});
const accessibleControls = await target.locator('[aria-label]').count() >= 5
	&& await target.locator('.opf-b-conditional-editor button').first().evaluate((button) => button.tabIndex >= 0);

await page.locator('.opf-b-toolbar button').nth(1).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 1);
const data = await page.evaluate(() => window.__opfSavedPayloads[0].data);
const conditionals = data.fields[3].conditionals;
const ok = data.fields.length === 4
	&& data.fields[1].id === 'source-text-copy'
	&& conditionals.length === 2
	&& conditionals[0].action === 'hide'
	&& conditionals[0].logic === 'any'
	&& conditionals[0].rules[0].field === 'source-text-copy'
	&& conditionals[0].rules[0].operator === 'contains'
	&& conditionals[0].rules[0].value === 'blocked'
	&& conditionals[0].rules[1].field === 'source-toggle'
	&& conditionals[0].rules[1].value === '1'
	&& conditionals[1].action === 'show'
	&& conditionals[1].rules[0].field === 'source-text'
	&& data.fields[0].conditionals.length === 0
	&& conditionLayoutFits
	&& accessibleControls
	&& errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} condition groups and rules save accessibly at 320px`);
if ( ! ok ) console.log(JSON.stringify({ conditionals, conditionLayoutFits, accessibleControls, fields: data.fields.map((field) => field.id), errors }));
if ( errors.length ) console.log(errors.join('\n'));
await browser.close();
process.exit(ok ? 0 : 1);
