// Authenticated Chromium proof for the real field-group list Duplicate action.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8093';
const wpPath = process.env.OPF_WP_PATH;
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
if (!wpPath || !passwordFile) throw new Error('OPF_WP_PATH and OPF_E2E_PASSWORD_FILE are required');

const realWpPath = path.resolve(wpPath);
if (realWpPath !== '/tmp/opf-group-duplicate-browser-clone') {
	throw new Error('Refusing to run outside /tmp/opf-group-duplicate-browser-clone');
}
if (!readFileSync(path.join(realWpPath, '.opf-disposable-e2e'), 'utf8').includes('opf-group-duplicate-browser-clone')) {
	throw new Error('Disposable clone marker is missing');
}
const baseUrl = new URL(base);
if (!['127.0.0.1', 'localhost'].includes(baseUrl.hostname)) throw new Error('Browser base URL must be localhost');

const wp = (args) => execFileSync('wp', [`--path=${realWpPath}`, ...args], { encoding: 'utf8' }).trim();
const token = randomUUID().replaceAll('-', '').slice(0, 12);
const fixtureScript = path.join(path.dirname(fileURLToPath(import.meta.url)), 'e2e-duplicate-group-fixture.php');
const sourceId = Number(wp(['eval-file', fixtureScript, 'create', token]));
const sourceTitle = `OPF Browser Duplicate ${token}`;
const copyTitle = `${sourceTitle} (Copy)`;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
const errors = [];
let failures = 0;
let fixtureRemoved = 0;
page.on('pageerror', (error) => errors.push(error.message));
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

try {
	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill('admin');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');

	await page.goto(`${base}/wp-admin/edit.php?post_type=opf_field_group`, { waitUntil: 'domcontentloaded' });
	const sourceRow = page.locator(`tr#post-${sourceId}`);
	await sourceRow.waitFor({ state: 'visible' });
	const duplicateLink = sourceRow.locator('.row-actions .duplicate a');
	const duplicateHref = await duplicateLink.getAttribute('href');
	check('authenticated admin list displays the source group and Duplicate action',
		(await sourceRow.innerText()).includes(sourceTitle) && Boolean(duplicateHref));
	check('list action is bound to this source and carries its nonce',
		duplicateHref?.includes('action=opf_duplicate_field_group') === true
		&& duplicateHref.includes(`post_id=${sourceId}`)
		&& duplicateHref.includes('_wpnonce=') );

	await sourceRow.locator('.row-title').hover();
	await Promise.all([
		page.waitForURL((url) => url.pathname.endsWith('/wp-admin/edit.php') && url.searchParams.get('opf_duplicated') === '1'),
		duplicateLink.click(),
	]);
	check('real admin-post action returns to the group list with success notice',
		await page.locator('.notice-success').innerText().then((text) => text.includes('Field group duplicated.')));

	const sourceAfter = page.locator(`tr#post-${sourceId}`);
	const copyLink = page.getByRole('link', { name: copyTitle, exact: true });
	await copyLink.waitFor({ state: 'visible' });
	const copyRow = page.locator('tr[id^="post-"]').filter({ has: copyLink });
	const copyRowId = await copyRow.getAttribute('id');
	const copyId = Number(copyRowId?.replace('post-', ''));
	check('published copy appears beside the unchanged original group',
		Number.isInteger(copyId) && copyId > 0 && await sourceAfter.isVisible());

	await copyLink.click();
	await page.locator('#opf-builder-app').waitFor({ state: 'visible' });
	const copied = await page.locator('#opf-builder-app').evaluate((node) => JSON.parse(node.dataset.model));
	const [source, derived] = copied.fields;
	check('copied field IDs are remapped collision-safely', source?.id === 'browser-source-copy' && derived?.id === 'browser-derived-copy');
	check('conditional source reference points to the copied field',
		derived?.conditionals?.[0]?.rules?.[0]?.field === 'browser-source-copy');
	check('formula and raw formula remap internal field and price references',
		derived?.pricing?.formula === '[field.browser-source-copy] + [price.browser-source-copy]'
		&& derived?.pricing?.formula_raw === '[field.browser-source-copy] + [price.browser-source-copy]');
	check('copy preserves the source field schema and has no uncaught browser errors',
		source?.label === 'Source' && source?.type === 'text' && errors.length === 0);
	if (errors.length) console.log(errors.join('\n'));
} catch (error) {
	failures++;
	console.log(`FAIL browser flow: ${error.message}`);
} finally {
	await browser.close();
	try {
		fixtureRemoved = Number(wp(['eval-file', fixtureScript, 'cleanup', token]));
		console.log(`${fixtureRemoved >= 1 ? 'ok' : 'FAIL'} fixture cleanup removed ${fixtureRemoved} tagged group(s)`);
		if (fixtureRemoved < 1) failures++;
	} catch (error) {
		failures++;
		console.log(`FAIL fixture cleanup: ${error.message}`);
	}
}

if (failures) process.exit(1);
