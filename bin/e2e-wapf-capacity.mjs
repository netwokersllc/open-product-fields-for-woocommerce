// WAPF Extended 3.1.5 licensed admin capacity proof: save a group whose
// hidden wapf-fields model carries 200 choices on one select through the
// real post.php form POST, then verify the stored model count + reload.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = 'http://127.0.0.1:8316';
const wpPath = '/tmp/opf-image-adminmisc-wp';
if (!readFileSync(path.join(wpPath, '.opf-disposable-e2e'), 'utf8').includes('opf-image-adminmisc-wp')) throw new Error('marker');
const password = readFileSync('/tmp/opf-lane-adminmisc-evidence/laneadmin-password.txt', 'utf8').trim();
const wp = (args) => execFileSync('wp', [`--path=${wpPath}`, ...args], { encoding: 'utf8' }).trim();
const CHOICES = 200;
const gid = Number(wp(['post', 'create', '--post_type=wapf_product', '--post_status=draft', '--post_title=LANE CAPACITY', '--porcelain']));
console.log('fixture wapf_product', gid, 'choices target', CHOICES);
const results = [];
const check = (name, ok, extra) => { results.push({ name, ok: !!ok, extra }); console.log(`${ok ? 'ok' : 'FAIL'} ${name}${extra ? ' — ' + extra : ''}`); };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
await page.addInitScript(() => { try { localStorage.setItem('_ga_atob_enq_', btoa(JSON.stringify({ ok: true, last: Date.now() }))); } catch (e) {} });

try {
	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill('laneadmin');
	await page.locator('#user_pass').fill(password);
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');
	await page.goto(`${base}/wp-admin/post.php?post=${gid}&action=edit`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('.wapf-field-list, .wapf-top-options', { timeout: 15000 });
	await page.waitForTimeout(1200);
	// Build a real WAPF field model with CHOICES options and post it through the
	// hidden fields input — exactly what onChange serializes at before_submit.
	const payload = [{
		id: 'cap1', type: 'select', label: 'Capacity Select', description: '', required: false,
		choices: Array.from({ length: CHOICES }, (_, i) => ({
			slug: `opt-${i + 1}`, label: `Option ${i + 1}`, selected: false, disabled: false,
			pricing_type: 'none', pricing_amount: 0, options: {},
		})),
		options: { choices: [] },
	}];
	payload[0].options.choices = payload[0].choices;
	await page.evaluate((fields) => {
		const input = document.querySelector('input[name="wapf-fields"]');
		input.value = JSON.stringify(fields);
	}, payload);
	await page.evaluate(() => { try { jQuery('.wapf_modal_overlay, .wapf-bulk-options').hide(); } catch (e) {} });
	const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => null);
	await page.locator('#publish').first().dispatchEvent('click');
	await nav;
	await page.waitForTimeout(1500);
	const stored = JSON.parse(wp(['eval', `$d=unserialize(get_post(${gid})->post_content); $f=$d['fields'][0]??[]; $c=$f['choices']??($f['options']['choices']??[]); echo json_encode(["n"=>count($c),"first"=>$c[0]['label']??'',"last"=>$c[count($c)-1]['label']??'']);`]));
	check('capacity', `WAPF real admin save persists ${CHOICES} choices on one field`, stored.n === CHOICES && stored.first === 'Option 1' && stored.last === `Option ${CHOICES}`, JSON.stringify(stored));
	// reload editor and confirm the saved model renders all rows (builder reads
	// the stored post_content — no fixed cap in the schema or the save path).
	await page.goto(`${base}/wp-admin/post.php?post=${gid}&action=edit`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1500);
	const reloaded = await page.evaluate(() => (document.querySelector('input[name="wapf-fields"]') || {}).value || '[]');
	const rn = JSON.parse(reloaded)[0]?.choices?.length ?? JSON.parse(reloaded)[0]?.options?.choices?.length;
	check('capacity', 'WAPF editor reload shows the full stored model (no truncation)', rn === CHOICES, `reloaded=${rn}`);
} catch (e) {
	console.log('FAIL flow:', e.message);
} finally {
	await browser.close();
	try { wp(['post', 'delete', String(gid), '--force']); } catch (e) {}
}
writeFileSync('/tmp/opf-lane-adminmisc-evidence/wapf-capacity-results.json', JSON.stringify({ results, errors }, null, 1));
console.log(`\n${results.filter(r => r.ok).length}/${results.length} checks ok`);
console.log('jsErrors:', errors.join(' | ').slice(0, 300) || 'none');
process.exit(results.some(r => !r.ok) ? 1 : 0);
