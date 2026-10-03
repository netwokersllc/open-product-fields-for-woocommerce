// Recon: dump the licensed WAPF Extended group editor DOM structure.
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
const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));

await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await page.locator('#user_login').fill('laneadmin');
await page.locator('#user_pass').fill(password);
await page.locator('#wp-submit').click();
await page.waitForURL('**/wp-admin/**');

// Existing group editor
await page.goto(`${base}/wp-admin/post.php?post=762&action=edit`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);
const html = await page.evaluate(() => {
	const fieldsBox = document.querySelector('#wapf-field-list, .wapf-fields, [rv-controller]');
	const inputs = [...document.querySelectorAll('input[name^="wapf-"], textarea[name^="wapf-"], select[name^="wapf-"]')].map(i => `${i.tagName}[name=${i.name}]`);
	const buttons = [...document.querySelectorAll('.wapf-field-list a, .wapf-field-list button, [rv-on-click]')].slice(0, 40).map(b => `${b.tagName}.${b.className} text="${(b.textContent||'').trim().slice(0,60)}"`);
	return { fieldsBoxHtml: fieldsBox ? fieldsBox.outerHTML.slice(0, 3000) : 'NONE', inputs, buttons };
});
console.log(JSON.stringify(html, null, 1));
console.log('=== ERRORS ===\n' + (errors.join('\n') || 'none'));
await page.screenshot({ path: '/tmp/opf-lane-adminmisc-evidence/wapf-editor-762.png', fullPage: false });
await browser.close();
