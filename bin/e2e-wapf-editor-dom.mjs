// Recon 2: deep-dump WAPF editor field DOM + hidden model inputs.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = 'http://127.0.0.1:8316';
const wpPath = path.resolve('/tmp/opf-image-adminmisc-wp');
if (!readFileSync(path.join(wpPath, '.opf-disposable-e2e'), 'utf8').includes('opf-image-adminmisc-wp')) throw new Error('marker missing');
const password = readFileSync('/tmp/opf-lane-adminmisc-evidence/laneadmin-password.txt', 'utf8').trim();

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));

await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await page.locator('#user_login').fill('laneadmin');
await page.locator('#user_pass').fill(password);
await page.locator('#wp-submit').click();
await page.waitForURL('**/wp-admin/**');

await page.goto(`${base}/wp-admin/post.php?post=762&action=edit`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);
const out = await page.evaluate(() => {
	const fields = document.querySelectorAll('.wapf-field, [class*="wapf-field-"]');
	const first = document.querySelector('.wapf-field');
	const val = (n) => { const i = document.querySelector(`input[name="${n}"],textarea[name="${n}"]`); return i ? i.value.slice(0, 4000) : null; };
	return {
		fieldNodes: fields.length,
		firstFieldHtml: first ? first.outerHTML.slice(0, 6000) : 'NONE',
		wapfFields: val('wapf-fields'),
		wapfConditions: val('wapf-conditions'),
		wapfLayout: val('wapf-layout'),
		wapfVariables: val('wapf-variables'),
		wapfType: val('wapf-fieldgroup-type'),
	};
});
console.log('FIELD NODES:', out.fieldNodes);
console.log('FIRST FIELD HTML:\n', out.firstFieldHtml);
console.log('=== wapf-fields input ===\n', out.wapfFields);
console.log('=== wapf-conditions ===\n', (out.wapfConditions||'').slice(0,1500));
console.log('=== wapf-layout ===\n', (out.wapfLayout||'').slice(0,1500));
console.log('=== wapf-variables ===\n', (out.wapfVariables||'').slice(0,1500));
console.log('=== type ===\n', out.wapfType);
console.log('=== ERRORS ===\n' + (errors.join('\n') || 'none'));
await browser.close();
