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
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

await page.setContent('<!doctype html><html><body><input id="title" value="Image swatch fixture"><div id="opf-builder-app"></div></body></html>');
await page.addStyleTag({ content: css });
await page.evaluate(() => {
	const mount = document.getElementById('opf-builder-app');
	mount.dataset.postId = '43';
	mount.dataset.nonce = 'fixture';
	mount.dataset.rest = '/wp-json/opf/v1/groups';
	mount.dataset.previewRest = '/wp-json/opf/v1/preview';
	mount.dataset.model = JSON.stringify({ fields: [
		{ id: 'finish', label: 'Finish', type: 'swatch', choices: [ { slug: 'oak', label: 'Oak', selected: false, disabled: false, pricing: { type: 'none', amount: 0, formula: '' } } ], conditionals: [] },
	], rule_groups: [] });
	window.__opfSavedPayloads = [];
	window.fetch = async (_url, options) => {
		window.__opfSavedPayloads.push(JSON.parse(options.body));
		return { json: async () => ({ id: 43 }) };
	};
	window.wp = {
		media: (settings) => {
			const handlers = {};
			window.__opfMediaSettings = settings;
			return {
				on: (event, callback) => { handlers[event] = callback; },
				state: () => ({ get: () => ({ first: () => ({ toJSON: () => ({ id: 481, url: 'https://example.test/oak.jpg' }) }) }) }),
				open: () => handlers.select(),
			};
		},
	};
});
await page.addScriptTag({ content: source });
await page.getByRole('button', { name: 'Choose image' }).click();
await page.locator('[data-opf-image-setting="image_zoom"]').check();
await page.locator('[data-opf-image-setting="label_pos"]').selectOption('tooltip');
await page.locator('[data-opf-image-setting="grid_layout"]').selectOption('flexible');
await page.locator('[data-opf-image-setting="items_per_row"]').fill('4');
await page.locator('[data-opf-image-setting="items_per_row_tablet"]').fill('2');
await page.locator('[data-opf-image-setting="items_per_row_mobile"]').fill('1');
await page.getByRole('button', { name: 'Save' }).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 1);
const result = await page.evaluate(() => ({
	choice: window.__opfSavedPayloads[0].data.fields[0].choices[0],
	field: window.__opfSavedPayloads[0].data.fields[0],
	media: window.__opfMediaSettings,
}));
const ok = result.choice.image_id === 481
	&& result.choice.image === 'https://example.test/oak.jpg'
	&& result.field.swatch_style === 'image'
	&& result.field.image_zoom === true
	&& result.field.label_pos === 'tooltip'
	&& result.field.grid_layout === 'flexible'
	&& result.field.items_per_row === 4
	&& result.field.items_per_row_tablet === 2
	&& result.field.items_per_row_mobile === 1
	&& result.media.library.type === 'image'
	&& result.media.multiple === false
	&& errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} image swatch editor saves selected Media Library attachment and URL`);
if (!ok) console.log(JSON.stringify({ result, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
