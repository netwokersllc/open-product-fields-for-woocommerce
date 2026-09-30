// Real Chromium DOM harness for builder persistence; WordPress REST is mocked.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '../assets/js/opf-builder.js'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 900, height: 800 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('console', (message) => { if ( 'error' === message.type() ) errors.push(message.text()); });
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if ( ! ok ) failures++;
};

await page.setContent(`<!doctype html><html><body>
	<input id="title" value="Targeted group">
	<select id="opf-placement-cats" multiple><option value="5" selected>Tools</option></select>
	<select id="opf-placement-tags" multiple><option value="9" selected>Wholesale</option></select>
	<select id="opf-placement-auth"><option value="logged_in" selected>Logged in</option><option value="logged_out">Not logged in</option></select>
	<select id="opf-placement-roles" multiple><option value="wholesale" selected>Wholesale</option><option value="customer">Customer</option></select>
	<select id="opf-placement-excluded-roles" multiple><option value="suspended" selected>Suspended</option></select>
	<select id="opf-placement-language-operator"><option value="not_in" selected>Is not</option></select>
	<select id="opf-placement-language"><option value="fr_FR" selected>Français</option></select>
	<div id="opf-builder-app"></div>
</body></html>`);
await page.evaluate(() => {
	const mount = document.getElementById('opf-builder-app');
	mount.dataset.postId = '31';
	mount.dataset.nonce = 'fixture';
	mount.dataset.rest = '/wp-json/opf/v1/groups';
	mount.dataset.previewRest = '/wp-json/opf/v1/preview';
	mount.dataset.model = JSON.stringify({
		fields: [],
		rule_groups: [
			{ rules: [ { subject: 'product', operator: 'in', terms: [ '42' ] }, { subject: 'product_cat', operator: 'in', terms: [ '5' ] }, { subject: 'product_tag', operator: 'in', terms: [ '9' ] }, { subject: 'user_auth', operator: 'in', terms: [ 'logged_in' ] }, { subject: 'user_role', operator: 'in', terms: [ 'wholesale' ] }, { subject: 'user_role', operator: 'not_in', terms: [ 'suspended' ] }, { subject: 'user_language', operator: 'not_in', terms: [ 'fr_FR' ] } ] },
			{ rules: [ { subject: 'product_tag', operator: 'not_in', terms: [ '7' ] } ] },
		],
	});
	window.__opfSavedPayloads = [];
	window.fetch = async (url, options) => {
		window.__opfSavedPayloads.push(JSON.parse(options.body));
		return { json: async () => ({ id: 31 }) };
	};
});
await page.addScriptTag({ content: source });
await page.locator('.opf-b-toolbar button').nth(1).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 1);
const originalGroups = await page.evaluate(() => window.__opfSavedPayloads[0].data.rule_groups);
check('saving unrelated field settings preserves existing OR-group placement exactly', JSON.stringify(originalGroups) === JSON.stringify([
	{ rules: [ { subject: 'product', operator: 'in', terms: [ '42' ] }, { subject: 'product_cat', operator: 'in', terms: [ '5' ] }, { subject: 'product_tag', operator: 'in', terms: [ '9' ] }, { subject: 'user_auth', operator: 'in', terms: [ 'logged_in' ] }, { subject: 'user_role', operator: 'in', terms: [ 'wholesale' ] }, { subject: 'user_role', operator: 'not_in', terms: [ 'suspended' ] }, { subject: 'user_language', operator: 'not_in', terms: [ 'fr_FR' ] } ] },
	{ rules: [ { subject: 'product_tag', operator: 'not_in', terms: [ '7' ] } ] },
]));

await page.locator('#opf-placement-auth').selectOption('logged_out');
await page.locator('#opf-placement-roles').selectOption('customer');
await page.locator('.opf-b-toolbar button').nth(1).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 2);
const data = await page.evaluate(() => window.__opfSavedPayloads[1].data);
const groups = data.rule_groups;
const everyGroupHas = (subject, operator, term) => groups.every((group) => group.rules.some((rule) => rule.subject === subject && rule.operator === operator && rule.terms.includes(term)));

check('all OR groups retain unedited product, category, tag, and language rules', groups.length === 2 && groups[0].rules.some((rule) => rule.subject === 'product' && rule.terms[0] === '42') && groups[0].rules.some((rule) => rule.subject === 'product_cat' && rule.operator === 'in' && rule.terms[0] === '5') && groups[0].rules.some((rule) => rule.subject === 'product_tag' && rule.operator === 'in' && rule.terms[0] === '9') && groups[0].rules.some((rule) => rule.subject === 'user_language' && rule.operator === 'not_in' && rule.terms[0] === 'fr_FR') && groups[1].rules.some((rule) => rule.subject === 'product_tag' && rule.operator === 'not_in' && rule.terms[0] === '7') && !groups[1].rules.some((rule) => rule.subject === 'user_language'));
check('changed login, required role, and excluded role apply to every OR group', everyGroupHas('user_auth', 'not_in', 'logged_in') && everyGroupHas('user_role', 'in', 'customer') && everyGroupHas('user_role', 'not_in', 'suspended'));
check('no uncaught builder errors', errors.length === 0);
if ( errors.length ) console.log(errors.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
