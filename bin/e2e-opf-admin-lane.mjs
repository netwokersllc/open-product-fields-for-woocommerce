// OPF-side admin proof for the adminmisc lane, run against the disposable
// clone at http://127.0.0.1:8316 (WAPF Extended also active there).
// Covers: builder field duplicate, choice disabled flag, bulk choice import,
// field-group duplicate row action, scheduled/missed list labels, and
// opf archive export/import. Mirrors bin/e2e-wapf-licensed-admin.mjs.
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
const here = path.dirname(fileURLToPath(import.meta.url));
const fixtureScript = path.join(here, 'e2e-duplicate-group-fixture.php');
const token = 'lane' + Date.now().toString(36);

// ---------- fixtures ----------
const mkGroup = (title, status = 'draft') => Number(wp(['post', 'create', '--post_type=opf_field_group', `--post_status=${status}`, `--post_title=${title}`, '--porcelain']));
const A = mkGroup('LANE OPF A');
console.log('fixtures: opf group', A);

const fieldsFromDb = (id) => JSON.parse(wp(['eval', `$d=json_decode(get_post(${id})->post_content,true); echo json_encode($d['fields'] ?? []);`]) || '[]');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

const login = async () => {
	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill('laneadmin');
	await page.locator('#user_pass').fill(password);
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');
};
const openEditor = async (id) => {
	await page.goto(`${base}/wp-admin/post.php?post=${id}&action=edit`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#opf-builder-app .opf-b-field-head, #opf-builder-app button', { timeout: 15000 });
	await page.waitForTimeout(800);
};
// The builder saves through REST via its own Save button (independent of the
// WP post form). Returns the status text shown afterwards.
const builderSave = async () => {
	await page.locator('#opf-builder-app button:has-text("Save")').first().click();
	await page.waitForFunction(() => {
		const s = document.getElementById('opf-b-status');
		return s && /Saved|failed/i.test(s.textContent);
	}, { timeout: 15000 });
	return page.locator('#opf-b-status').innerText();
};
const fieldCard = (n = 0) => page.locator('#opf-builder-app .opf-b-field').nth(n);

try {
	await login();
	await openEditor(A);

	// ---------- builder: add select field + 2 choices, disable second ----------
	await page.locator('#opf-builder-app button:has-text("+ Add field")').click();
	await page.waitForTimeout(400);
	const f1 = fieldCard(0);
	await f1.locator('input').first().fill('Lane Opf Source');
	await f1.locator('select').first().selectOption('select');
	await page.waitForTimeout(400);
	// selecting a choice type seeds one choice; add a second
	const seeded = await f1.locator('.opf-b-choices .opf-b-choice-flags').count();
	if (seeded < 2) {
		await f1.locator('button:has-text("+ Add choice")').click();
		await page.waitForTimeout(300);
	}
	// per .opf-b-choice row, inputs are: slug, label, amount — label is index 1
	const choiceLabel = (row) => f1.locator('.opf-b-choice').nth(row).locator('input.opf-b-input').nth(1);
	await choiceLabel(0).fill('Alpha');
	await choiceLabel(1).fill('Beta');
	// disable second choice via its "Unavailable" checkbox
	const flagBoxes = f1.locator('.opf-b-choice .opf-b-choice-flags');
	await flagBoxes.nth(1).locator('label:has-text("Unavailable") input[type=checkbox]').check();
	await page.waitForTimeout(300);
	const disabledChecked = await flagBoxes.nth(1).locator('label:has-text("Unavailable") input').isChecked();
	check('choice-disabled', 'OPF builder marks choice Unavailable (disabled) before save', disabledChecked);

	// ---------- builder: duplicate the field ----------
	await f1.locator('button.opf-b-duplicate-field').click();
	await page.waitForTimeout(500);
	const nFields = await page.locator('#opf-builder-app .opf-b-field').count();
	const f2 = fieldCard(1);
	const f2label = await f2.locator('input').first().inputValue();
	const f2choices = await f2.locator('.opf-b-choice').count();
	const f2disabled = await f2.locator('.opf-b-choice .opf-b-choice-flags').nth(1).locator('label:has-text("Unavailable") input').isChecked().catch(() => false);
	check('dup-field', 'OPF builder Duplicate appends a copy preserving label + choices + disabled flag',
		nFields === 2 && f2label === 'Lane Opf Source' && f2choices === 2 && f2disabled === true,
		JSON.stringify({ nFields, f2label, f2choices, f2disabled }));

	let st = await builderSave();
	check('dup-field', 'OPF builder REST save reports Saved', /Saved/.test(st), st);
	await openEditor(A);
	const persisted = fieldsFromDb(A);
	check('dup-field', 'OPF duplicated field persists with collision-free id after save+reload',
		persisted.length === 2 && persisted[0].id !== persisted[1].id && /-copy/.test(persisted[1].id),
		JSON.stringify(persisted.map(f => ({ id: f.id, l: f.label, n: (f.choices || []).length }))));
	check('choice-disabled', 'OPF disabled flag persists in stored group after save+reload',
		persisted?.[0]?.choices?.[1]?.disabled === true,
		JSON.stringify((persisted[0]?.choices || []).map(c => ({ l: c.label, d: c.disabled }))));

	// ---------- builder: bulk choice import ----------
	await openEditor(A);
	const bf = fieldCard(0);
	await bf.locator('details.opf-b-bulk-import summary').click();
	await bf.locator('details.opf-b-bulk-import textarea').fill('Gamma\nDelta\n\n Epsilon ');
	await page.waitForTimeout(200);
	await bf.locator('details.opf-b-bulk-import button:has-text("Import choices")').click();
	await page.waitForTimeout(400);
	const bulkStatus = await bf.locator('details.opf-b-bulk-import [role=status]').innerText();
	const domLabels = await bf.locator('.opf-b-choice').evaluateAll(rows => rows.map(r => r.querySelectorAll('input.opf-b-input')[1]?.value));
	check('bulk-import', 'OPF bulk import appends newline-separated choices, skips blank, trims',
		domLabels.join('|') === 'Alpha|Beta|Gamma|Delta|Epsilon' && /3 out of 4/.test(bulkStatus),
		JSON.stringify({ status: bulkStatus, domLabels }));
	st = await builderSave();
	await openEditor(A);
	const persistedBulk = fieldsFromDb(A);
	check('bulk-import', 'OPF bulk-imported choices persist in stored group',
		((persistedBulk[0]?.choices) || []).map(c => c.label).join('|') === 'Alpha|Beta|Gamma|Delta|Epsilon',
		JSON.stringify((persistedBulk[0]?.choices || []).map(c => c.label)));

	// ---------- group duplicate via list row action ----------
	const sourceId = Number(wp(['eval-file', fixtureScript, 'create', token]));
	const sourceTitle = `OPF Browser Duplicate ${token}`;
	const copyTitle = `${sourceTitle} (Copy)`;
	await page.goto(`${base}/wp-admin/edit.php?post_type=opf_field_group`, { waitUntil: 'domcontentloaded' });
	const sourceRow = page.locator(`tr#post-${sourceId}`);
	await sourceRow.waitFor({ state: 'visible' });
	const dupLink = sourceRow.locator('.row-actions .duplicate a');
	const dupHref = await dupLink.getAttribute('href').catch(() => null);
	check('dup-group', 'OPF list shows nonce-protected Duplicate row action on published group',
		!!dupHref && dupHref.includes('action=opf_duplicate_field_group') && dupHref.includes(`post_id=${sourceId}`) && dupHref.includes('_wpnonce='),
		dupHref || 'none');
	await sourceRow.locator('.row-title').hover();
	await Promise.all([
		page.waitForURL(u => u.searchParams.get('opf_duplicated') === '1', { timeout: 15000 }),
		dupLink.click(),
	]);
	const notice = await page.locator('.notice-success').innerText().catch(() => '');
	check('dup-group', 'OPF duplicate action redirects to list with success notice', /Field group duplicated/.test(notice), notice);
	const copyLink = page.getByRole('link', { name: copyTitle, exact: true });
	await copyLink.waitFor({ state: 'visible', timeout: 5000 });
	const copyRow = page.locator('tr[id^="post-"]').filter({ has: copyLink });
	const copyId = Number((await copyRow.getAttribute('id'))?.replace('post-', ''));
	const copyStatus = wp(['post', 'get', String(copyId), '--field=post_status']);
	check('dup-group', 'OPF copy is published with " (Copy)" title', copyId > 0 && copyStatus === 'publish', JSON.stringify({ copyId, copyStatus }));
	const srcFields = fieldsFromDb(sourceId);
	const cpFields = fieldsFromDb(copyId);
	const srcIds = srcFields.map(f => f.id), cpIds = cpFields.map(f => f.id);
	const remapped = cpIds.every(id => !srcIds.includes(id));
	const derived = cpFields.find(f => /Derived/.test(f.label || ''));
	const formula = derived?.pricing?.formula || '';
	const cond = derived?.conditionals?.[0]?.rules?.[0]?.field || '';
	const copiedSource = cpFields.find(f => /Source/.test(f.label || ''));
	check('dup-group', 'OPF copy remaps field ids + condition + [field.*]/[price.*] formula references',
		remapped && copiedSource && formula.includes(`field.${copiedSource.id}`) && formula.includes(`price.${copiedSource.id}`) && cond === copiedSource.id,
		JSON.stringify({ cpIds, formula, cond }));

	// ---------- scheduling: WP-native list labels ----------
	const fut = Number(wp(['eval', `echo wp_insert_post(["post_type"=>"opf_field_group","post_status"=>"future","post_title"=>"LANE OPF SCHEDULED","post_date"=>"2031-01-01 00:00:00","post_date_gmt"=>"2031-01-01 00:00:00"],true);`])) || 0;
	const mis = Number(wp(['eval', `$id=wp_insert_post(["post_type"=>"opf_field_group","post_status"=>"future","post_title"=>"LANE OPF MISSED","post_date"=>"2031-01-01 00:00:00","post_date_gmt"=>"2031-01-01 00:00:00"]); global $wpdb; $wpdb->update($wpdb->posts,["post_date"=>"2020-01-01 00:00:00","post_date_gmt"=>"2020-01-01 00:00:00"],["ID"=>$id]); clean_post_cache($id); echo $id;`]));
	await page.goto(`${base}/wp-admin/edit.php?post_type=opf_field_group&orderby=date&order=asc`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(500);
	const listText = await page.locator('table.wp-list-table').innerText().catch(async () => await page.locator('body').innerText());
	check('scheduling', 'OPF CPT list labels past-due future group "Missed schedule" (native WP)',
		/Missed schedule/.test(listText) && /LANE OPF MISSED/.test(listText),
		listText.slice(0, 300).replace(/\n+/g, ' | '));
	await page.goto(`${base}/wp-admin/edit.php?post_type=opf_field_group&orderby=date&order=desc`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(500);
	const listText2 = await page.locator('table.wp-list-table').innerText().catch(async () => await page.locator('body').innerText());
	check('scheduling', 'OPF CPT list labels future group "Scheduled" (native WP)',
		/Scheduled/.test(listText2) && /LANE OPF SCHEDULED/.test(listText2),
		listText2.slice(0, 300).replace(/\n+/g, ' | '));
	// storefront availability: only published groups resolve
	const futResolved = wp(['eval', `\\OPF\\Service\\FieldGroups::flush_cache(); $ids=array_map(function($g){return $g['id'];}, \\OPF\\Service\\FieldGroups::all()); echo in_array(${fut}, $ids) ? "yes" : "no";`]).trim();
	check('scheduling', 'future OPF group is excluded from published-group resolution until fired',
		futResolved === 'no', `resolved=${futResolved}`);
	wp(['post', 'delete', String(fut), '--force']);
	wp(['post', 'delete', String(mis), '--force']);

	console.log('=== opfJsErrors ==='); console.log(errors.join('\n') || 'none');
} catch (e) {
	failures++;
	console.log('FAIL flow:', e.message);
} finally {
	await browser.close();
	for (const id of [A]) { try { wp(['post', 'delete', String(id), '--force']); } catch (e) {} }
	try { wp(['eval-file', fixtureScript, 'cleanup', token]); } catch (e) {}
	try {
		wp(['eval', `foreach(get_posts(["post_type"=>"opf_field_group","post_status"=>"any","s"=>"LANE OPF","numberposts"=>-1,"fields"=>"ids"]) as $i){wp_delete_post($i,true);echo "cleaned $i\\n";}`]);
	} catch (e) {}
}

// ---------- archive export/import (CLI surface; admin UI is WAPF-import only) ----------
try {
	const out = `${outDir}/opf-archive-export.json`;
	// Save via FieldGroups::save so post_content is canonical normalized JSON
	// (export self-validates with ArchiveImporter::decode and rejects
	// hand-written minimal payloads that normalize() would mutate).
	const expSrc = Number(wp(['eval-file', path.join(here, 'e2e-archive-fixture.php')]));
	wp(['opf', 'export', `--group=${expSrc}`, `--output=${out}`]);
	const archive = JSON.parse(readFileSync(out, 'utf8'));
	const srcFields3 = archive.groups?.[0]?.data?.fields || [];
	check('import-export', 'wp opf export emits a parseable OPF archive for a group',
		Array.isArray(archive.groups) && archive.groups.length === 1 && srcFields3.length === 2,
		JSON.stringify({ format: archive.format, groups: archive.groups?.length, fields: srcFields3.length }));
	const dry = wp(['opf', 'import_archive', out]);
	check('import-export', 'wp opf import-archive dry-run validates without writing',
		/Would import 1 group/.test(dry), dry.slice(0, 200));
	const commit = wp(['opf', 'import_archive', out, '--commit']);
	check('import-export', 'wp opf import-archive --commit imports the archive',
		/Imported 1 group/.test(commit), commit.slice(0, 200));
	const imported = wp(['post', 'list', '--post_type=opf_field_group', '--post_status=any', `--title=${archive.groups[0].title}`, '--field=ID'])
		.split('\n').filter(Boolean).map(Number).filter(id => id !== expSrc);
	const impFields = imported.length ? fieldsFromDb(Number(imported[0])) : [];
	// OPF archives are the plugin's own round-trip format: field ids/structure
	// are preserved verbatim (unlike WAPF cross-site remapping).
	const preservedIds = impFields.every(f => srcFields3.some(s => s.id === f.id && s.label === f.label));
	check('import-export', 'imported archive creates a new group preserving field structure + ids (OPF round-trip format)',
		imported.length === 1 && impFields.length === 2 && preservedIds,
		JSON.stringify({ imported, impIds: impFields.map(f => f.id) }));
	// checksum dedupe: re-importing reports already-imported rather than duplicating
	const again = wp(['opf', 'import_archive', out, '--commit']);
	check('import-export', 're-import is idempotent (already-imported, no duplicate posts)',
		/already-imported/.test(again) || /Would|Imported 0/.test(again), again.slice(0, 200));
	for (const id of imported) { try { wp(['post', 'delete', id, '--force']); } catch (e) {} }
	try { wp(['post', 'delete', String(expSrc), '--force']); } catch (e) {}
} catch (e) {
	failures++;
	console.log('FAIL import-export:', e.message);
}

writeFileSync(`${outDir}/opf-admin-lane-results.json`, JSON.stringify({ results, errors }, null, 1));
console.log(`\n${results.filter(r => r.ok).length}/${results.length} checks ok, failures=${failures}`);
process.exit(failures ? 1 : 0);
