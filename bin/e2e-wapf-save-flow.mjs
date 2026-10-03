// Probe: does the licensed WAPF editor save fields? Add field, dupe, save, reload.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = 'http://127.0.0.1:8316';
const wpPath = path.resolve('/tmp/opf-image-adminmisc-wp');
if (!readFileSync(path.join(wpPath, '.opf-disposable-e2e'), 'utf8').includes('opf-image-adminmisc-wp')) throw new Error('marker missing');
const password = readFileSync('/tmp/opf-lane-adminmisc-evidence/laneadmin-password.txt', 'utf8').trim();
const postId = process.env.WAPF_POST_ID || '15864';

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
// Licensed-reference shim: pre-seed WAPF's client-side pro-feature cache
// (localStorage _ga_atob_enq_ = base64 {ok:true,last:now}) — mirrors a real
// licensed install whose api.studiowombat.com check returned ok:true.
await page.addInitScript(() => {
	try { localStorage.setItem('_ga_atob_enq_', btoa(JSON.stringify({ ok: true, last: Date.now() }))); } catch (e) {}
});
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await page.locator('#user_login').fill('laneadmin');
await page.locator('#user_pass').fill(password);
await page.locator('#wp-submit').click();
await page.waitForURL('**/wp-admin/**');

await page.goto(`${base}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3000);
console.log('fields rendered:', await page.locator('.wapf-field').count());
console.log('block-pro visible:', await page.locator('.wapf-block-pro').isVisible().catch(() => false));

// Click "Add your first field" or "Add a Field"
const addFirst = page.locator('a', { hasText: 'Add your first field' });
const addMore = page.locator('a', { hasText: 'Add a Field' });
if (await addFirst.count() && await addFirst.first().isVisible()) { await addFirst.first().click(); }
else if (await addMore.count()) { await addMore.first().click(); }
await page.waitForTimeout(1500);
const n1 = await page.locator('.wapf-field').count();
console.log('fields after add:', n1);

// set the label of the new field
const labelInput = page.locator('.wapf-field').last().locator('input[rv-value="field.label"], input[rv-value=\'field.label\']').first();
if (await labelInput.count()) { await labelInput.fill('Lane Ref Text'); await labelInput.dispatchEvent('input'); }
// check hidden input now
const hf = await page.evaluate(() => document.querySelector('input[name="wapf-fields"]')?.value || 'EMPTY');
console.log('wapf-fields input now:', hf.slice(0, 200));

// click Duplicate on the field
const dupe = page.locator('.wapf-field').last().locator('a.wapf-action-dupe');
if (await dupe.count()) { await dupe.first().click(); await page.waitForTimeout(1000); }
console.log('fields after dupe:', await page.locator('.wapf-field').count());

// Save: submit the post form
await Promise.all([
	page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => null),
	page.locator('#publish, input[name="save"], button[name="save"], #save-post').first().click().catch(async () => { await page.evaluate(() => document.querySelector('form#post')?.submit()); }),
]);
await page.waitForTimeout(2000);
console.log('after save url:', page.url());
// reload + check
await page.goto(`${base}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3000);
const n2 = await page.locator('.wapf-field').count();
console.log('fields after reload:', n2);
const ids = await page.evaluate(() => [...document.querySelectorAll('.wapf-field')].map(f => ({ id: f.getAttribute('data-field-id') || f.getAttribute('rv-data-field-id'), type: f.getAttribute('data-type') || f.getAttribute('rv-data-type'), label: (f.querySelector('.wapf-field-label span') || {}).textContent })));
console.log('fields:', JSON.stringify(ids));
const hf2 = await page.evaluate(() => document.querySelector('input[name="wapf-fields"]')?.value || 'EMPTY');
console.log('wapf-fields after reload:', hf2.slice(0, 300));
console.log('=== ERRORS ===\n' + (errors.join('\n') || 'none'));
await browser.close();
