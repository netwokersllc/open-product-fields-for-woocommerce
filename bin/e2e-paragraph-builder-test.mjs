// Real Chromium coverage for editing and saving a static paragraph field.
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
const page = await browser.newPage({ viewport: { width: 720, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

await page.setContent('<!doctype html><html><body><input id="title" value="Paragraph fixture"><div id="opf-builder-app"></div></body></html>');
await page.addStyleTag({ content: css });
await page.evaluate(() => {
	const mount = document.getElementById('opf-builder-app');
	mount.dataset.postId = '43';
	mount.dataset.nonce = 'fixture';
	mount.dataset.rest = '/wp-json/opf/v1/groups';
	mount.dataset.previewRest = '/wp-json/opf/v1/preview';
	mount.dataset.model = JSON.stringify({ fields: [
		{ id: 'wash-note', label: '', type: 'paragraph', content: 'Old text', required: true, width: 100, choices: [ { slug: 'loss', label: 'loss' } ], pricing: { type: 'fixed', amount: 12, formula: '' }, conditionals: [] },
	], rule_groups: [] });
	window.__opfSavedPayloads = [];
	window.fetch = async (_url, options) => {
		window.__opfSavedPayloads.push(JSON.parse(options.body));
		return { json: async () => ({ id: 43 }) };
	};
});
await page.addScriptTag({ content: source });
const body = page.getByRole('textbox', { name: 'Paragraph content' });
const normalized = await page.locator('.opf-b-field').first().locator('input[title="Required"]').evaluate((input) => input.disabled && !input.checked);
await body.fill('Wipe with a soft cloth.\nAvoid bleach.');
await page.getByRole('button', { name: 'Save' }).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 1);
const saved = await page.evaluate(() => window.__opfSavedPayloads[0].data.fields[0]);
const ok = normalized
	&& saved.type === 'paragraph'
	&& saved.content === 'Wipe with a soft cloth.\nAvoid bleach.'
	&& saved.required === false
	&& saved.choices.length === 0
	&& saved.pricing.type === 'none'
	&& errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} paragraph editor saves plain static content without required state or pricing`);
if (!ok) console.log(JSON.stringify({ saved, normalized, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
