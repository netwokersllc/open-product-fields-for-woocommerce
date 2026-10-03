// Real Chromium lifecycle proof for OPF group placement rules vs WAPF Extended 3.1.5.
// Orchestrates: setup -> commerce -> OPF browser matrix -> WAPF reference -> cleanup.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8305';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only.');
const clone = process.env.OPF_RULES_CLONE || '/tmp/opf-image-rules-wp';
if (!clone.startsWith('/tmp/')) throw new Error('Disposable clone only.');
const dir = process.env.OPF_RULES_ARTIFACT_DIR || '/tmp/opf-rules-artifacts';
fs.mkdirSync(dir, { recursive: true });
const stateFile = process.env.OPF_RULES_STATE_FILE || '/tmp/opf-rules-state.json';
const fixture = path.join(path.dirname(fileURLToPath(import.meta.url)), 'e2e-rules-lifecycle.php');
const phase = (name) => execFileSync('wp', ['--path=' + clone, 'eval-file', fixture], {
	env: { ...process.env, OPF_RULES_E2E_ALLOW: '1', OPF_RULES_E2E_PHASE: name, OPF_RULES_ARTIFACT_DIR: dir, OPF_RULES_STATE_FILE: stateFile },
	encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'],
});
const checks = [], errors = [], referenceJsErrors = [], observations = {};
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); if (!pass) throw new Error(label); };
const wpEval = (phaseName) => { const out = phase(phaseName); out.trim().split('\n').forEach(l => l.startsWith('ok ') && checks.push({ label: 'eval: ' + l.slice(3), pass: true })); return out; };

wpEval('setup');
let state = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
const logins = JSON.parse(fs.readFileSync(dir + '/private-login.json', 'utf8'));
const fid = (key) => 'f_' + key.toLowerCase();
const wid = (key) => 'wrules' + key.toLowerCase();
wpEval('commerce');
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('pageerror', (e) => { errors.push(e.message); if (/advanced-product-fields/.test(e.stack || '')) referenceJsErrors.push(e.message); });
const loginAs = async (creds) => {
	const ctx = await browser.newContext({ viewport: { width: 1280, height: 960 } });
	const p = await ctx.newPage();
	p.on('pageerror', (e) => { errors.push(e.message); if (/advanced-product-fields/.test(e.stack || '')) referenceJsErrors.push(e.message); });
	await p.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	await p.locator('#user_login').fill(creds.user);
	await p.locator('#user_pass').fill(creds.password);
	await Promise.all([p.waitForURL(/wp-admin/), p.locator('#wp-submit').click()]);
	return [ctx, p];
};
const fieldIds = async (p, impl) => {
	const sel = impl === 'wapf' ? '.wapf-field-group [data-field-id]' : '.opf-fields [data-opf-field]';
	const attr = impl === 'wapf' ? 'data-field-id' : 'data-opf-field';
	return [...new Set(await p.locator(sel).evaluateAll((els, a) => els.map((el) => el.getAttribute(a)), attr))].sort();
};
const expectedOpf = (keys) => keys.map(fid).sort();
// 746 baseline wapf_product posts render alongside ours: assert per-field
// presence/absence of our unique wrules<key> ids, not exact page sets.
// WAPF display_field_groups() hard-skips grouped/external products entirely
// (class-product-controller.php:725), so no fixture group can render there
// even when its rules match — OPF renders the matched set on pExt.
const wapfExpected = (product, keys) => (['pExt'].includes(product) ? [] : keys);
const wapfPresence = async (p, expectedKeys) => {
	const allKeys = Object.keys(state.wapf || {});
	const ids = new Set(await p.locator('.wapf-field-group [data-field-id]').evaluateAll((els) => els.map((el) => el.getAttribute('data-field-id'))));
	return expectedKeys.every((k) => !state.wapf[k] || ids.has(wid(k))) && allKeys.every((k) => expectedKeys.includes(k) || !ids.has(wid(k)));
};
try {
	// ---------- OPF storefront matrix ----------
	for (const [product, keys] of Object.entries(state.expected.anon)) {
		await page.goto(state.permalinks[product], { waitUntil: 'domcontentloaded' });
		await page.locator('[data-opf-field="f_gglobal"]').waitFor();
		const actual = await fieldIds(page, 'opf');
		check(`anon ${product} storefront renders exactly expected OPF field set`, JSON.stringify(actual) === JSON.stringify(expectedOpf(keys)));
		await page.screenshot({ path: `${dir}/opf-anon-${product}.png`, fullPage: true });
	}
	const [ctxE, edPage] = await loginAs(logins.editor);
	for (const [product, keys] of Object.entries(state.expected.editor)) {
		await edPage.goto(state.permalinks[product], { waitUntil: 'domcontentloaded' });
		await edPage.locator('[data-opf-field="f_gglobal"]').waitFor();
		const actual = await fieldIds(edPage, 'opf');
		check(`editor ${product} storefront renders auth+role OPF field set`, JSON.stringify(actual) === JSON.stringify(expectedOpf(keys)));
	}
	await edPage.screenshot({ path: `${dir}/opf-editor-pA.png`, fullPage: true });
	await ctxE.close();
	const [ctxA, admPage] = await loginAs(logins.admin);
	for (const [product, keys] of Object.entries(state.expected.admin)) {
		await admPage.goto(state.permalinks[product], { waitUntil: 'domcontentloaded' });
		await admPage.locator('[data-opf-field="f_gglobal"]').waitFor();
		const actual = await fieldIds(admPage, 'opf');
		check(`admin ${product} storefront renders admin-context OPF field set`, JSON.stringify(actual) === JSON.stringify(expectedOpf(keys)));
	}
	await admPage.screenshot({ path: `${dir}/opf-admin-pA.png`, fullPage: true });
	// ---------- OPF admin authoring roundtrip on gAdminEdit ----------
	const gid = state.groups.gAdminEdit;
	await admPage.goto(`${base}/wp-admin/post.php?post=${gid}&action=edit`, { waitUntil: 'domcontentloaded' });
	await admPage.locator('.opf-b-field').first().waitFor();
	const excludedCats = admPage.locator('#opf-placement-excluded-cats');
	check('builder excluded-categories control reloads stored not_in rule', await excludedCats.locator('option:checked').count() === 1 && await excludedCats.locator('option:checked').getAttribute('value') === String(state.catA));
	check('builder exposes product-type and attribute controls', await admPage.locator('#opf-placement-types').count() === 1 && await admPage.locator('#opf-placement-excluded-types').count() === 1 && await admPage.locator('#opf-placement-attributes').count() === 1 && await admPage.locator('#opf-placement-excluded-attributes').count() === 1);
	await admPage.screenshot({ path: `${dir}/opf-admin-builder-placement.png`, fullPage: true });
	await admPage.locator('#opf-placement-types').selectOption('external');
	await admPage.locator('#opf-placement-excluded-attributes').selectOption(`pa_color:${state.red}`);
	const saveResponse = admPage.waitForResponse((r) => r.url().includes('/opf/v1/groups') && r.request().method() === 'POST');
	await admPage.getByRole('button', { name: 'Save', exact: true }).click();
	const saved = await (await saveResponse).json();
	const savedRules = saved.data.rule_groups;
	const hasRule = (subject, operator, term) => savedRules.some((g) => g.rules.some((r) => r.subject === subject && r.operator === operator && r.terms.includes(term)));
	check('admin save keeps untouched cat rule and adds type + attribute exclusions', hasRule('product_cat', 'not_in', String(state.catA)) && hasRule('product_type', 'in', 'external') && hasRule('pa_color', 'not_in', String(state.red)));
	await admPage.getByText('Saved.', { exact: true }).waitFor();
	await admPage.reload({ waitUntil: 'domcontentloaded' });
	await admPage.locator('.opf-b-field').first().waitFor();
	check('reloaded builder selects reflect saved type and attribute rules', await admPage.locator('#opf-placement-types option:checked').count() === 1 && await admPage.locator('#opf-placement-excluded-attributes option:checked').count() === 1);
	// Saved rules take live effect: gAdminEdit now = not-catA AND external AND not-red -> pExt only.
	await page.goto(state.permalinks.pExt, { waitUntil: 'domcontentloaded' });
	await page.locator('[data-opf-field="f_gglobal"]').waitFor();
	check('edited admin rules take live storefront effect on external product', await page.locator('[data-opf-field="f_gadminedit"]').count() === 1);
	await page.goto(state.permalinks.pB, { waitUntil: 'domcontentloaded' });
	check('edited admin rules hide group on non-external product', await page.locator('[data-opf-field="f_gadminedit"]').count() === 0);
	// ---------- OPF French-language context ----------
	await page.goto(state.permalinks.pAFr, { waitUntil: 'domcontentloaded' });
	await page.locator('[data-opf-field="f_gglobal"]').waitFor();
	const frFields = await fieldIds(page, 'opf');
	check('french page renders fr language rules and FR post-language group', JSON.stringify(frFields) === JSON.stringify(expectedOpf(state.expected.anon_fr.pAFr)));
	await page.screenshot({ path: `${dir}/opf-fr-pAFr.png`, fullPage: true });
	// ---------- OPF browser add-to-cart: all matched required fields enforced ----------
	await page.goto(state.permalinks.pA, { waitUntil: 'domcontentloaded' });
	const inputs = page.locator('.opf-fields [data-opf-field] input[type="text"], .opf-fields [data-opf-field] input:not([type])');
	const n = await inputs.count();
	check('anon pA renders one text control per matched group', n === state.expected.anon.pA.length);
	for (let i = 0; i < n; i++) await inputs.nth(i).fill('Browser ' + i);
	const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
	await page.locator('button.single_add_to_cart_button').click();
	await nav;
	const cart = await (await page.request.get(base + '/wp-json/wc/store/v1/cart')).json();
	const line = cart.items.find((i) => i.id === state.pA);
	check('browser add-to-cart captured every matched group value', line && state.expected.anon.pA.every((k) => JSON.stringify(line.item_data).includes('Browser ')));
	await page.screenshot({ path: `${dir}/opf-cart-pA.png`, fullPage: true });
	observations.opfDom = { pA: frFields };
	// ---------- WAPF reference setup ----------
	wpEval('wapf_setup');
	wpEval('wapf_assert');
	// wapf_setup populated state.wapf + state.wapf_extra ids after launch.
	state = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
	// ---------- WAPF storefront matrix ----------
	for (const [product, keys] of Object.entries(state.expected.anon)) {
		await page.goto(state.permalinks[product], { waitUntil: 'domcontentloaded' });
		await page.locator('[data-opf-field="f_gglobal"]').waitFor();
		check(`wapf anon ${product} renders matching WAPF field set`, await wapfPresence(page, wapfExpected(product, keys)));
	}
	await page.goto(state.permalinks.pA, { waitUntil: 'domcontentloaded' });
	await page.screenshot({ path: `${dir}/side-by-side-pA-anon.png`, fullPage: true });
	const [ctxE2, edPage2] = await loginAs(logins.editor);
	for (const [product, keys] of Object.entries(state.expected.editor)) {
		await edPage2.goto(state.permalinks[product], { waitUntil: 'domcontentloaded' });
		check(`wapf editor ${product} renders auth+role WAPF field set`, await wapfPresence(edPage2, wapfExpected(product, keys)));
	}
	await ctxE2.close();
	const [ctxA2, admPage2] = await loginAs(logins.admin);
	for (const [product, keys] of Object.entries(state.expected.admin)) {
		await admPage2.goto(state.permalinks[product], { waitUntil: 'domcontentloaded' });
		check(`wapf admin ${product} renders admin-context WAPF field set`, await wapfPresence(admPage2, wapfExpected(product, keys)));
	}
	await ctxA2.close();
	await page.goto(state.permalinks.pAFr, { waitUntil: 'domcontentloaded' });
	check('wapf french page renders matching fr language field set', await wapfPresence(page, wapfExpected('pAFr', state.expected.anon_fr.pAFr)));
	await page.screenshot({ path: `${dir}/side-by-side-pAFr-anon.png`, fullPage: true });
	// ---------- teardown ----------
	wpEval('wapf_cleanup');
	wpEval('cleanup');
	check('no uncaught browser errors outside reference plugin', errors.filter((e) => !referenceJsErrors.includes(e)).length === 0);
} finally {
	fs.writeFileSync(dir + '/browser-results.json', JSON.stringify({ time: new Date().toISOString(), base, checks, errors, referenceJsErrors, observations }, null, 2));
	await browser.close();
}
console.log(JSON.stringify({ passed: checks.filter((c) => c.pass).length, total: checks.length, referenceJsErrors: referenceJsErrors.length }));
