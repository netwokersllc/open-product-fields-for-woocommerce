// Authenticated Chromium proof for core admin title search on the opf_field_group list.
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
const groupCount = () => Number(wp(['post', 'list', '--post_type=opf_field_group', '--post_status=any', '--format=count']));
const token = randomUUID().replaceAll('-', '').slice(0, 12);
const fixtureScript = path.join(path.dirname(fileURLToPath(import.meta.url)), 'e2e-admin-search-fixture.php');
const baselineGroups = groupCount();
const fixtureId = Number(wp(['eval-file', fixtureScript, 'create', token]));
const fixtureTitle = `OPF Admin Title Search ${token}`;
const otherTerm = 'WXR E2E';
const absentTerm = `zz-nomatch-${token}`;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
const errors = [];
let failures = 0;
page.on('pageerror', (error) => errors.push(error.message));
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

try {
	console.log(`browser: Chromium ${browser.version()}, viewport 1920x1080`);
	console.log(`baseline opf_field_group count ${baselineGroups}; fixture post-${fixtureId}`);

	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill('admin');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');

	await page.goto(`${base}/wp-admin/edit.php?post_type=opf_field_group`, { waitUntil: 'domcontentloaded' });
	const searchInput = page.locator('#post-search-input');
	await searchInput.waitFor({ state: 'visible' });
	const submit = page.locator('#search-submit');
	check('authenticated group list heading and CPT-labelled search box render',
		(await page.locator('h1.wp-heading-inline').innerText()).includes('Field Groups')
		&& (await submit.getAttribute('value')) === 'Search Field Groups');

	const fixtureRow = page.locator(`tr#post-${fixtureId}`);
	check('unfiltered list renders the fixture row with its exact title',
		await fixtureRow.isVisible()
		&& (await fixtureRow.locator('.row-title').innerText()) === fixtureTitle);

	await searchInput.fill(fixtureTitle);
	await Promise.all([
		page.waitForURL((url) => url.searchParams.get('s') === fixtureTitle),
		submit.click(),
	]);
	const hitUrl = new URL(page.url());
	check('title search submits to the CPT list with the term in the URL',
		hitUrl.pathname.endsWith('/wp-admin/edit.php')
		&& hitUrl.searchParams.get('post_type') === 'opf_field_group'
		&& hitUrl.searchParams.get('s') === fixtureTitle);
	const hitRows = page.locator('#the-list tr[id^="post-"]');
	check('exact-title search returns exactly one row, the fixture itself',
		(await hitRows.count()) === 1
		&& (await hitRows.first().getAttribute('id')) === `post-${fixtureId}`
		&& (await hitRows.first().locator('.row-title').innerText()) === fixtureTitle);

	await page.locator('#post-search-input').fill(otherTerm);
	await Promise.all([
		page.waitForURL((url) => url.searchParams.get('s') === otherTerm),
		page.locator('#search-submit').click(),
	]);
	const otherTitles = await page.locator('#the-list .row-title').allInnerTexts();
	check('a distinct matching term lists other groups while the fixture disappears',
		otherTitles.length >= 1
		&& !otherTitles.includes(fixtureTitle)
		&& (await page.locator(`tr#post-${fixtureId}`).count()) === 0);

	await page.locator('#post-search-input').fill(absentTerm);
	await Promise.all([
		page.waitForURL((url) => url.searchParams.get('s') === absentTerm),
		page.locator('#search-submit').click(),
	]);
	const noItems = page.locator('#the-list tr.no-items');
	check('a non-matching term shows the list-table empty state and no fixture row',
		(await noItems.count()) === 1
		&& /no .+ found/i.test(await noItems.innerText())
		&& (await page.locator(`tr#post-${fixtureId}`).count()) === 0);
	check('no uncaught browser errors during the whole flow', errors.length === 0);
	if (errors.length) console.log(errors.join('\n'));
} catch (error) {
	failures++;
	console.log(`FAIL browser flow: ${error.message}`);
} finally {
	await browser.close();
	try {
		const fixtureRemoved = Number(wp(['eval-file', fixtureScript, 'cleanup', token]));
		const after = groupCount();
		console.log(`${fixtureRemoved === 1 && after === baselineGroups ? 'ok' : 'FAIL'} fixture cleanup removed ${fixtureRemoved} tagged group(s); group count ${baselineGroups} -> ${after}`);
		if (fixtureRemoved !== 1 || after !== baselineGroups) failures++;
	} catch (error) {
		failures++;
		console.log(`FAIL fixture cleanup: ${error.message}`);
	}
}

if (failures) process.exit(1);
