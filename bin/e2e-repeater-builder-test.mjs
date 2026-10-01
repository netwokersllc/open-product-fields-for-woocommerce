// Real Chromium coverage for editing and saving field repeater settings.
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

await page.setContent('<!doctype html><html><body><input id="title" value="Repeater fixture"><div id="opf-builder-app"></div></body></html>');
await page.addStyleTag({ content: css });
await page.evaluate(() => {
	const mount = document.getElementById('opf-builder-app');
	mount.dataset.postId = '43';
	mount.dataset.nonce = 'fixture';
	mount.dataset.rest = '/wp-json/opf/v1/groups';
	mount.dataset.previewRest = '/wp-json/opf/v1/preview';
	mount.dataset.model = JSON.stringify({ fields: [
		{ id: 'guest_name', label: 'Guest name', type: 'text', choices: [], conditionals: [] },
		{ id: 'attendee_note', label: 'Attendee note', type: 'textarea', repeat: { enabled: true, mode: 'button', max: 9 }, choices: [], conditionals: [] },
		{ id: 'ticket_code', label: 'Ticket code', type: 'text', repeat: { enabled: true, mode: 'quantity' }, choices: [], conditionals: [] },
	], rule_groups: [] });
	window.__opfSavedPayloads = [];
	window.fetch = async (_url, options) => {
		window.__opfSavedPayloads.push(JSON.parse(options.body));
		return { json: async () => ({ id: 43 }) };
	};
});
await page.addScriptTag({ content: source });

const enabled = page.locator('[data-opf-repeat-enabled="guest_name"]');
await enabled.check();
const defaultMax = await page.locator('[data-opf-repeat-max="guest_name"]').inputValue();
await page.locator('[data-opf-repeat-max="guest_name"]').fill('2.5');
await page.getByRole('button', { name: 'Save' }).click();
const invalidBlocked = await page.evaluate(() => window.__opfSavedPayloads.length === 0);
await page.locator('[data-opf-repeat-max="guest_name"]').fill('4');
await page.locator('[data-opf-repeat-label="guest_name:add"]').fill('Add person');
await page.locator('[data-opf-repeat-label="guest_name:del"]').fill('Remove person');
await page.locator('[data-opf-repeat-label="guest_name:label"]').fill('Person {n}');
await page.getByRole('button', { name: 'Save' }).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 1);
const result = await page.evaluate(() => window.__opfSavedPayloads[0].data.fields);
const mode = await page.locator('[data-opf-repeat-mode="ticket_code"]').inputValue();
await page.locator('[data-opf-repeat-label="ticket_code:label"]').fill('Ticket {n}');
await page.getByRole('button', { name: 'Save' }).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 2);
const quantityRepeat = await page.evaluate(() => window.__opfSavedPayloads[1].data.fields[2].repeat);
const quantityWarning = await page.locator('.opf-b-repeat-notice').count();
const ok = defaultMax === '10000'
	&& invalidBlocked
	&& JSON.stringify(result[0].repeat) === JSON.stringify({ enabled: true, mode: 'button', max: 4, add: 'Add person', del: 'Remove person', label: 'Person {n}' })
	&& JSON.stringify(result[1].repeat) === JSON.stringify({ enabled: true, mode: 'button', max: 9 })
	&& JSON.stringify(result[2].repeat) === JSON.stringify({ enabled: true, mode: 'quantity' })
	&& JSON.stringify(quantityRepeat) === JSON.stringify({ enabled: true, mode: 'quantity', label: 'Ticket {n}' })
	&& mode === 'quantity'
	&& quantityWarning === 0
	&& errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} builder saves repeater mode, limits and labels, and preserves imported repeat settings`);
if (!ok) console.log(JSON.stringify({ defaultMax, invalidBlocked, result, mode, quantityWarning, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
