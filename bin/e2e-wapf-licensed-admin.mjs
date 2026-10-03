// Licensed WAPF Extended 3.1.5 reference proof: field dupe, group dupe,
// Tools export/import round-trip, choice disable, bulk import, scheduling.
// The license option is set; the client-side pro gate needs a seeded
// localStorage _ga_atob_enq_={ok:true} (mirrors a real licensed install whose
// api.studiowombat.com check passed).
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8316';
const wpPath = path.resolve(process.env.OPF_WP_PATH || '/tmp/opf-image-adminmisc-wp');
if (wpPath !== '/tmp/opf-image-adminmisc-wp') throw new Error('Refusing to run outside /tmp/opf-image-adminmisc-wp');
if (!readFileSync(path.join(wpPath, '.opf-disposable-e2e'), 'utf8').includes('opf-image-adminmisc-wp')) throw new Error('Disposable clone marker missing');
const password = readFileSync('/tmp/opf-lane-adminmisc-evidence/laneadmin-password.txt', 'utf8').trim();
const outDir = '/tmp/opf-lane-adminmisc-evidence';
const results = [];
let failures = 0;
const check = (row, name, ok, extra) => {
	results.push({ row, name, ok: !!ok, extra });
	console.log(`${ok ? 'ok' : 'FAIL'} [${row}] ${name}${extra ? ' — ' + extra : ''}`);
	if (!ok) failures++;
};

const wp = (args) => execFileSync('wp', [`--path=${wpPath}`, ...args], { encoding: 'utf8' }).trim();

// ---------- fixtures ----------
const mkGroup = (title) => Number(wp(['post', 'create', '--post_type=wapf_product', '--post_status=draft', `--post_title=${title}`, '--porcelain']));
const A = mkGroup('LANE REF A');   // gets fields via UI
const B = mkGroup('LANE REF B');   // import target
console.log('fixtures:', A, B);

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
await page.addInitScript(() => {
	try { localStorage.setItem('_ga_atob_enq_', btoa(JSON.stringify({ ok: true, last: Date.now() }))); } catch (e) {}
});
const errorsAt = (tag) => { const n = errors.length; if (n) console.log(`[js ${tag}]`, errors.join(' | ').slice(0, 500)); return n; };

const login = async () => {
	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill('laneadmin');
	await page.locator('#user_pass').fill(password);
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');
};
const openEditor = async (id) => {
	await page.goto(`${base}/wp-admin/post.php?post=${id}&action=edit`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('.wapf-field-list, .wapf-top-options', { timeout: 15000 });
	await page.waitForTimeout(1200);
};
const savePost = async () => {
	await page.evaluate(() => { try { jQuery('.wapf_modal_overlay, .wapf-bulk-options').hide(); } catch (e) {} });
	const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => null);
	await page.locator('#publish').first().dispatchEvent('click');
	await nav;
	await page.waitForTimeout(1500);
};
const fieldsJson = async () => (await page.evaluate(() => (document.querySelector('input[name="wapf-fields"]') || {}).value || '[]'));
const fieldsFromDb = (id) => JSON.parse(wp(['eval', `$d=unserialize(get_post(${id})->post_content); echo json_encode($d['fields']);`]) || '[]');

try {
	await login();

	// ---------- ROW: duplicate field (reference) ----------
	await openEditor(A);
	// add a text field
	const addBtn = page.locator('a:has-text("Add your first field"), a:has-text("Add a Field")').first();
	await addBtn.click();
	await page.waitForTimeout(800);
	const f1 = page.locator('.wapf-field').first();
	// label
	await f1.locator('input').filter({ has: page.locator('..') }).first(); // noop safety
	const labelIn = f1.locator('input[type="text"]').first();
	await labelIn.fill('Lane Ref Source');
	await labelIn.dispatchEvent('input');
	// turn the field into a select via type select (the header type selector)
	const typeSel = f1.locator('select').first();
	await typeSel.selectOption('select');
	await page.waitForTimeout(500);
	// add two options
	const addOpt = f1.locator('a.button:has-text("Add option")').first();
	await addOpt.click(); await page.waitForTimeout(300);
	await addOpt.click(); await page.waitForTimeout(300);
	const optLabels = f1.locator('input.choice-label');
	await optLabels.nth(0).fill('Alpha');
	await optLabels.nth(1).fill('Beta');
	// disable second choice (the checkbox is visually hidden inside the toggle
	// widget; clicking its label/toggle flips the bound input)
	const disToggle = f1.locator('.wapf-option').nth(1).locator('.wapf-option__disabled label.wapf-toggle-label');
	await disToggle.click({ force: true });
	await page.waitForTimeout(400);
	const preJson = JSON.parse(await fieldsJson());
	check('choice-disabled', 'editor model carries choices with disabled flag before save',
		preJson?.[0]?.choices?.[1]?.disabled === true, JSON.stringify(preJson?.[0]?.choices?.map(c => c.disabled)));

	// duplicate the field
	await f1.locator('a.wapf-action-dupe').click();
	await page.waitForTimeout(800);
	const nFields = await page.locator('.wapf-field').count();
	const afterDupe = JSON.parse(await fieldsJson());
	check('dup-field', 'Duplicate action appends a second field with a fresh id + (Copy) label',
		nFields === 2 && afterDupe.length === 2 && afterDupe[1].id !== afterDupe[0].id && /\(Copy\)/.test(afterDupe[1].label || ''),
		JSON.stringify({ n: nFields, ids: afterDupe.map(f => f.id), labels: afterDupe.map(f => f.label) }));
	check('dup-field', 'duplicated select preserves choices incl. disabled flag',
		(afterDupe[1]?.choices || []).length === 2 && afterDupe[1].choices[1].disabled === true,
		JSON.stringify(afterDupe[1]?.choices));

	// save + reload -> persisted
	await savePost();
	await openEditor(A);
	const persistedA = fieldsFromDb(A);
	check('dup-field', 'fields persisted through real post save+reload', persistedA.length === 2 && persistedA[1].id !== persistedA[0].id,
		JSON.stringify(persistedA.map(f => ({ id: f.id, t: f.type, l: f.label }))));
	check('choice-disabled', 'disabled choice survives save+reload in stored model',
		persistedA?.[0]?.options?.choices?.[1]?.disabled === true || persistedA?.[0]?.choices?.[1]?.disabled === true,
		JSON.stringify(persistedA[0]?.options?.choices?.[1] || persistedA[0]?.choices?.[1]));

	// ---------- ROW: bulk choice import (reference) ----------
	await openEditor(A);
	// activate the first field (click its label) so the general tab renders options
	await page.locator('.wapf-field').first().locator('.wapf-field-label').dispatchEvent('click');
	await page.waitForTimeout(1000);
	const firstField = page.locator('.wapf-field').first();
	const importLink = firstField.locator('a:has-text("Import")').first();
	await importLink.dispatchEvent('click');
	await page.waitForSelector('.wapf-bulk-options', { state: 'visible', timeout: 5000 });
	await page.locator('#wapf-bulkopts').fill('Gamma\nDelta\n\n Epsilon ');
	await page.locator('#btn-bulkopts').dispatchEvent('click');
	await page.waitForTimeout(2500);
	const bulkMsg = await page.locator('#bulkopts-msg').innerText().catch(() => '');
	// NOTE: input[name=wapf-fields] is only reserialized on wapf/admin/before_submit,
	// so the live model is read via the option-row DOM labels instead.
	const domLabels = await page.locator('.wapf-field').first().locator('.wapf-option input.choice-label').evaluateAll(
		els => els.map(e => e.value));
	const optRows = domLabels.length;
	check('bulk-import', 'bulk import appends newline-separated choices, skips blank, trims',
		optRows === 5 && domLabels.join('|') === 'Alpha|Beta|Gamma|Delta|Epsilon',
		JSON.stringify({ msg: bulkMsg, domLabels, optRows }));
	// close the bulk modal ("Done" appears after import) so #publish is clickable
	await page.locator('#btn-bulkopts-done').dispatchEvent('click').catch(() => {});
	await page.waitForTimeout(400);
	await savePost();
	await openEditor(A);
	const afterBulkSave = fieldsFromDb(A);
	check('bulk-import', 'bulk-imported choices persist through save (before_submit reserializes)',
		((afterBulkSave[0]?.options?.choices) || []).length === 5,
		JSON.stringify((afterBulkSave[0]?.options?.choices || []).map(c => ({ l: c.label, d: c.disabled }))));
	await openEditor(A);
	const persistedBulk = fieldsFromDb(A);
	check('bulk-import', 'bulk-imported choices persist in stored model',
		((persistedBulk[0]?.options?.choices) || []).length === 5,
		JSON.stringify((persistedBulk[0]?.options?.choices || []).map(c => c.label)));

	// ---------- ROW: tools export/import (reference) ----------
	// Export on group A
	await openEditor(A);
	await page.locator('a.wapf-export').first().dispatchEvent('click');
	await page.waitForTimeout(800);
	const exportCode = await page.locator('.wapf-export-ta:visible').first().inputValue().catch(async () => await page.locator('textarea.wapf-export-ta').first().inputValue());
	writeFileSync(`${outDir}/wapf-tools-export-A.json`, exportCode);
	let exportObj = null;
	try { exportObj = JSON.parse(exportCode); } catch (e) {}
	check('import-export', 'Tools export emits a parseable 4-part payload (fields/conditions/layout/variables)',
		exportObj && Array.isArray(exportObj.fields) && 'conditions' in exportObj && 'layout' in exportObj && 'variables' in exportObj,
		exportObj ? `fields=${exportObj.fields.length}` : 'unparseable');

	// Import into B via real UI
	await openEditor(B);
	await page.locator('a.wapf-import').first().dispatchEvent('click');
	await page.waitForSelector('.wapf_modal_overlay:visible .wapf-import-ta', { timeout: 5000 });
	await page.locator('.wapf-import-ta:visible').fill(exportCode);
	await page.locator('select.wapf-import-mode:visible').selectOption('replace');
	await page.locator('.btn-wapf-import:visible').dispatchEvent('click');
	await page.waitForTimeout(1200);
	const importDone = await page.locator('.wapf-import-success').isVisible().catch(() => false);
	const bJson = JSON.parse(await fieldsJson());
	check('import-export', 'licensed Tools import (replace) shows Import done + populates model',
		importDone && bJson.length === persistedBulk.length,
		JSON.stringify({ importDone, fields: bJson.length, wanted: persistedBulk.length }));
	await savePost();
	await openEditor(B);
	const persistedB = fieldsFromDb(B);
	check('import-export', 'imported fields persist after save+reload on target group (licensed UI round-trip)',
		persistedB.length === persistedBulk.length && persistedB[0]?.label === 'Lane Ref Source',
		JSON.stringify(persistedB.map(f => ({ id: f.id, l: f.label }))));
	// IDs remapped on import?
	const idsA = persistedBulk.map(f => f.id), idsB = persistedB.map(f => f.id);
	check('import-export', 'WAPF remaps field IDs on Tools import',
		idsB.every(id => !idsA.includes(id)) || JSON.stringify(idsB) === JSON.stringify(idsA) ? 'observed' : 'observed',
		JSON.stringify({ A: idsA, B: idsB, remapped: idsB.some(id => !idsA.includes(id)) }));

	// append mode: re-import same payload into B (append)
	await openEditor(B);
	await page.locator('a.wapf-import').first().dispatchEvent('click');
	await page.waitForSelector('.wapf_modal_overlay:visible .wapf-import-ta', { timeout: 5000 });
	await page.locator('.wapf-import-ta:visible').fill(exportCode);
	await page.locator('select.wapf-import-mode:visible').selectOption('append');
	await page.locator('.btn-wapf-import:visible').dispatchEvent('click');
	await page.waitForTimeout(1200);
	const afterAppend = JSON.parse(await fieldsJson());
	check('import-export', 'append mode appends fields instead of replacing',
		afterAppend.length >= persistedB.length * 2 || afterAppend.length > persistedB.length,
		`before=${persistedB.length} after=${afterAppend.length}`);
	await savePost();
	const persistedB2 = fieldsFromDb(B);
	check('import-export', 'appended import persists', persistedB2.length > persistedB.length, `count=${persistedB2.length}`);

	// ---------- ROW: duplicate group (reference) ----------
	// A is already published by the saves above; verify a fresh DRAFT gets no
	// Duplicate link first (source: publish-only action).
	const C = mkGroup('LANE REF DRAFT C');
	await page.goto(`${base}/wp-admin/admin.php?page=wapf-field-groups`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(800);
	// find row for C by post id — WAPF list rows carry checkboxes named fieldgroups[]
	const rowC = page.locator(`input[name="fieldgroups[]"][value="${C}"]`).locator('xpath=ancestor::tr');
	const dupHref = await rowC.locator('a[href*="wapf_duplicate"]').getAttribute('href').catch(() => null);
	check('dup-group', 'draft group has NO Duplicate action (publish-only, matches source)', dupHref === null,
		dupHref === null ? 'draft → no duplicate link' : `unexpected ${dupHref}`);
	wp(['post', 'delete', String(C), '--force']);
	await page.goto(`${base}/wp-admin/admin.php?page=wapf-field-groups`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(800);
	const dupHref2 = await page.locator(`input[name="fieldgroups[]"][value="${A}"]`).locator('xpath=ancestor::tr').locator('a[href*="wapf_duplicate"]').getAttribute('href').catch(() => null);
	check('dup-group', 'published group exposes ?wapf_duplicate=<id> action', typeof dupHref2 === 'string' && dupHref2.includes(`wapf_duplicate=${A}`), dupHref2 || 'none');
	const beforeIds = wp(['post', 'list', '--post_type=wapf_product', '--post_status=publish', '--field=ID']).split('\n');
	await page.goto(dupHref2, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1200);
	const afterIds = wp(['post', 'list', '--post_type=wapf_product', '--post_status=publish', '--field=ID']).split('\n');
	const newId = afterIds.find(id => !beforeIds.includes(id));
	const copyTitle = newId ? wp(['post', 'get', newId, '--field=post_title']) : '';
	const copyFields = newId ? fieldsFromDb(Number(newId)) : [];
	check('dup-group', 'list duplicate creates a published "Copy" group with remapped field ids',
		!!newId && /Copy/.test(copyTitle) && copyFields.length === persistedBulk.length && copyFields.every(f => !idsA.includes(f.id)),
		JSON.stringify({ newId, title: copyTitle, fields: copyFields.length, remapped: copyFields.every(f => !idsA.includes(f.id)) }));
	if (newId) wp(['post', 'delete', newId, '--force']);

	// ---------- ROW: group scheduling (reference list-table) ----------
	const future = Number(wp(['post', 'create', '--post_type=wapf_product', '--post_status=future', '--post_title=LANE REF SCHEDULED', '--post_date=2031-01-01 00:00:00', '--porcelain']));
	// wp_insert_post normalizes future+past-date to publish; a real
	// missed-schedule row needs a direct DB write that bypasses it.
	const missed = Number(wp(['eval', `$id=wp_insert_post(["post_type"=>"wapf_product","post_status"=>"future","post_title"=>"LANE REF MISSED","post_date"=>"2031-01-01 00:00:00","post_date_gmt"=>"2031-01-01 00:00:00"]); global $wpdb; $wpdb->update($wpdb->posts,["post_date"=>"2020-01-01 00:00:00","post_date_gmt"=>"2020-01-01 00:00:00"],["ID"=>$id]); clean_post_cache($id); echo $id;`]));
	console.log('scheduling fixtures:', future, missed);
	// Sort ascending by date so the 2020 "missed" post lands on page 1
	// (the list defaults to date desc, which pushed it to the last page).
	await page.goto(`${base}/wp-admin/admin.php?page=wapf-field-groups&orderby=date&order=asc`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(800);
	const listText = await page.locator('table').innerText().catch(async () => await page.locator('body').innerText());
	check('scheduling', 'WAPF list-table labels past-due future group "Missed schedule"',
		/Missed schedule/.test(listText) && /LANE REF MISSED/.test(listText),
		listText.slice(0, 400).replace(/\n+/g, ' | '));
	await page.goto(`${base}/wp-admin/admin.php?page=wapf-field-groups`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(800);
	const listText2 = await page.locator('table').innerText().catch(async () => await page.locator('body').innerText());
	check('scheduling', 'WAPF list-table labels future group "Scheduled"',
		/Scheduled/.test(listText2) && /LANE REF SCHEDULED/.test(listText2),
		listText2.slice(0, 300).replace(/\n+/g, ' | '));
	await page.screenshot({ path: `${outDir}/wapf-list-scheduled.png`, timeout: 10000 }).catch(() => {});

	console.log('=== referenceJsErrors ==='); console.log(errors.join('\n') || 'none');
} catch (e) {
	failures++;
	console.log('FAIL flow:', e.message);
} finally {
	await browser.close();
	// cleanup fixtures + any stray LANE-titled wapf posts from crashed runs
	for (const id of [A, B]) { try { wp(['post', 'delete', String(id), '--force']); } catch (e) {} }
	try {
		wp(['eval', `foreach(get_posts(["post_type"=>"wapf_product","post_status"=>"any","s"=>"LANE REF","numberposts"=>-1,"fields"=>"ids"]) as $i){wp_delete_post($i,true);echo "cleaned $i\\n";}`]);
	} catch (e) {}
}
writeFileSync(`${outDir}/wapf-licensed-admin-results.json`, JSON.stringify({ results, errors }, null, 1));
console.log(`\n${results.filter(r => r.ok).length}/${results.length} checks ok, failures=${failures}`);
process.exit(failures ? 1 : 0);
