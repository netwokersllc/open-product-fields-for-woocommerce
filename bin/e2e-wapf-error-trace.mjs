// Debug: capture WAPF admin JS error stack + environment state.
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
page.on('pageerror', (e) => errors.push('pageerror: ' + (e.stack || e.message)));
await page.addInitScript(() => {
	window.__errs = [];
	window.addEventListener('error', (e) => { try { window.__errs.push(String(e.error && e.error.stack || e.message)); } catch (_) {} });
});

await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await page.locator('#user_login').fill('laneadmin');
await page.locator('#user_pass').fill(password);
await page.locator('#wp-submit').click();
await page.waitForURL('**/wp-admin/**');

await page.goto(`${base}/wp-admin/post.php?post=762&action=edit`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(4000);
const info = await page.evaluate(() => ({
	errs: window.__errs || [],
	wapf_config: typeof wapf_config !== 'undefined' ? wapf_config : 'UNDEF',
	rivets: typeof rivets !== 'undefined' ? 'present' : 'MISSING',
	jquery: typeof jQuery !== 'undefined' ? jQuery.fn.jquery : 'MISSING',
	blockPro: getComputedStyle(document.querySelector('.wapf-block-pro') || document.body).display,
	fieldsInput: (document.querySelector('input[name="wapf-fields"]') || {}).value,
	fieldsNodes: document.querySelectorAll('.wapf-field').length,
	scripts: [...document.querySelectorAll('script[src]')].map(s => s.src.split('/').pop()),
}));
console.log(JSON.stringify(info, null, 1));
console.log('=== PAGEERRORS ===');
console.log(errors.join('\n---\n') || 'none');
await browser.close();
