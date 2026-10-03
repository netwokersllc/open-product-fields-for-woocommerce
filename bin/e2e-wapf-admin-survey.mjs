// Recon: survey licensed WAPF Extended 3.1.5 admin surfaces on the disposable clone.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8316';
const wpPath = path.resolve(process.env.OPF_WP_PATH || '/tmp/opf-image-adminmisc-wp');
if (wpPath !== '/tmp/opf-image-adminmisc-wp') throw new Error('Refusing to run outside /tmp/opf-image-adminmisc-wp');
const marker = readFileSync(path.join(wpPath, '.opf-disposable-e2e'), 'utf8');
if (!marker.includes('opf-image-adminmisc-wp')) throw new Error('Disposable clone marker is missing');
const password = readFileSync('/tmp/opf-lane-adminmisc-evidence/laneadmin-password.txt', 'utf8').trim();
const outDir = '/tmp/opf-lane-adminmisc-evidence';

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await page.locator('#user_login').fill('laneadmin');
await page.locator('#user_pass').fill(password);
await page.locator('#wp-submit').click();
await page.waitForURL('**/wp-admin/**');
console.log('logged in:', page.url());

// 1. Field groups list
await page.goto(`${base}/wp-admin/admin.php?page=wapf-field-groups`, { waitUntil: 'domcontentloaded' });
await page.screenshot({ path: `${outDir}/wapf-field-groups-list.png`, fullPage: false });
const body = await page.locator('body').innerText();
console.log('--- list page title:', await page.title());
console.log('--- has duplicate action:', /duplicate/i.test(body));
console.log('--- body head:', body.slice(0, 400).replace(/\n+/g, ' | '));
const dupLinks = await page.locator('a[href*="wapf_duplicate"]').count();
console.log('--- wapf_duplicate links:', dupLinks);
const newGroup = await page.locator('a[href*="post-new.php?post_type=wapf_product"], a.page-title-action, a[href*="post_type=wapf_product"]').count();
console.log('--- new-group links:', newGroup);
console.log('--- errors so far:', JSON.stringify(errors));

// 2. Open first existing group editor
const firstEdit = page.locator('table a[href*="post.php?post="]').first();
if (await firstEdit.count()) {
	const href = await firstEdit.getAttribute('href');
	console.log('--- opening group editor:', href);
	await page.goto(href, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1500);
	await page.screenshot({ path: `${outDir}/wapf-group-editor.png`, fullPage: true });
	const ed = await page.locator('body').innerText();
	console.log('--- editor has Tools/Import/Export:', /Import/.test(ed), /Export/.test(ed));
	console.log('--- editor body head:', ed.slice(0, 300).replace(/\n+/g, ' | '));
}
console.log('=== errors ==='); console.log(errors.join('\n') || 'none');
await browser.close();
