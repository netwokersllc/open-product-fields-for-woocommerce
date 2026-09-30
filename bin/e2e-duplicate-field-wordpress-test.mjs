// Browser proof against an isolated, running WordPress + WooCommerce clone.
// Required: OPF_E2E_WP_PATH, OPF_E2E_BASE_URL, OPF_E2E_ADMIN_ID.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const wpPath = process.env.OPF_E2E_WP_PATH;
const baseUrl = process.env.OPF_E2E_BASE_URL;
const adminId = process.env.OPF_E2E_ADMIN_ID;
if ( ! wpPath || ! baseUrl || ! adminId ) throw new Error('Set OPF_E2E_WP_PATH, OPF_E2E_BASE_URL, and OPF_E2E_ADMIN_ID.');

const wp = (code) => execFileSync('wp', [ `--path=${wpPath}`, `--user=${adminId}`, 'eval', code ], { encoding: 'utf8' }).trim();
const encodedSession = wp(`$expiration=time()+3600; $token=WP_Session_Tokens::get_instance(get_current_user_id())->create($expiration); $auth=wp_generate_auth_cookie(get_current_user_id(), $expiration, 'auth', $token); $logged=wp_generate_auth_cookie(get_current_user_id(), $expiration, 'logged_in', $token); $_COOKIE[AUTH_COOKIE]=$auth; $_COOKIE[LOGGED_IN_COOKIE]=$logged; $_REQUEST[AUTH_COOKIE]=$auth; $_REQUEST[LOGGED_IN_COOKIE]=$logged; echo base64_encode(AUTH_COOKIE . "\\n" . $auth . "\\n" . LOGGED_IN_COOKIE . "\\n" . $logged . "\\n" . wp_create_nonce('wp_rest'));`);
const [authCookieName, authCookie, loggedCookieName, loggedCookie, restNonce] = Buffer.from(encodedSession, 'base64').toString('utf8').split('\n');
if ( ! authCookieName || ! authCookie || ! loggedCookieName || ! loggedCookie || ! restNonce ) throw new Error('Could not create a test-only WordPress auth session.');
const authToken = loggedCookie.split('|')[2];

const marker = `OPF field duplicate E2E ${Date.now()}`;
const fixture = {
	fields: [
		{
			id: 'e2e-size', label: 'Size', description: 'Choose a size', type: 'select', required: true, width: 70,
			choices: [ { slug: 'large', label: 'Large', selected: true, disabled: false, pricing: { type: 'fixed', amount: 4, formula: '' } } ],
			pricing: { type: 'formula', amount: 0, formula: '[price] + checked(e2e-size-copy)', formula_raw: '' },
			conditionals: [],
		},
		{
			id: 'e2e-size-copy', label: 'Collision guard', type: 'text', required: false, choices: [],
			conditionals: [
				{ action: 'show', logic: 'all', rules: [ { field: 'e2e-size', operator: 'is', value: 'large' }, { field: 'external-trigger', operator: 'is', value: 'yes' } ] },
				{ action: 'hide', logic: 'any', rules: [ { field: 'e2e-size', operator: 'is', value: 'small' } ] },
			],
			pricing: { type: 'none', amount: 0, formula: '' },
		},
	],
	rule_groups: [],
};
const fixtureEncoded = Buffer.from(JSON.stringify({ ...fixture, title: marker })).toString('base64');
const fixtureId = Number(wp(`$p=json_decode(base64_decode('${fixtureEncoded}'), true); $id=OPF\\Service\\FieldGroups::save(0, $p, ['title'=>$p['title'], 'status'=>'publish']); echo (int)$id;`));
if ( ! fixtureId ) throw new Error('Could not create the WordPress builder fixture.');

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const builderJs = fs.readFileSync(path.join(here, '../assets/js/opf-builder.js'), 'utf8');
const browser = await chromium.launch();
const context = await browser.newContext();
const origin = new URL(baseUrl);
await context.addCookies([
	{ name: authCookieName, value: authCookie, domain: origin.hostname, path: '/wp-admin', httpOnly: true, secure: 'https:' === origin.protocol, sameSite: 'Lax' },
	{ name: loggedCookieName, value: loggedCookie, domain: origin.hostname, path: '/', httpOnly: true, secure: 'https:' === origin.protocol, sameSite: 'Lax' },
]);
const page = await context.newPage();
const errors = [];
let interceptedBuilder = false;
let saveResponse = null;
page.on('pageerror', (error) => errors.push(error.message));
page.on('console', (message) => { if ( 'error' === message.type() ) errors.push(message.text()); });
page.on('response', (response) => { if ( response.url().includes('/wp-json/opf/v1/groups') ) saveResponse = response; });
await context.route('**/assets/js/opf-builder.js*', async (route) => {
	interceptedBuilder = true;
	await route.fulfill({ status: 200, contentType: 'application/javascript', body: builderJs });
});

let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if ( ! ok ) failures++;
};

try {
	const response = await page.goto(`${baseUrl}/wp-admin/post.php?post=${fixtureId}&action=edit`, { waitUntil: 'networkidle' });
	check('authenticated WordPress editor responds successfully', !!response && response.ok() && page.url().includes('/wp-admin/post.php?') && !page.url().includes('wp-login.php'));
	try {
		await page.locator('#opf-builder-app').waitFor({ state: 'attached', timeout: 8000 });
	} catch (error) {
		const context = await page.locator('#wpbody-content').innerText().catch(() => 'admin content unavailable');
		throw new Error(`Builder metabox missing (${page.url()}): ${context.slice(0, 600)}`);
	}
	check('feature branch builder script loaded', interceptedBuilder);
	check('admin REST nonce belongs to the authenticated cookie session', await page.locator('#opf-builder-app').getAttribute('data-nonce') === restNonce);
	check('WordPress renders both source fields', await page.locator('.opf-b-field').count() === 2);
	await page.locator('.opf-b-duplicate').first().click();
	check('duplicate is inserted beside its source with WAPF copy label', await page.locator('.opf-b-field').count() === 3 && await page.locator('.opf-b-field').nth(1).locator('.opf-b-label').inputValue() === 'Size (Copy)');
	await page.locator('.opf-b-field').nth(1).locator('.opf-b-label').fill('Custom size');
	await page.locator('.opf-b-toolbar button').nth(1).click();
	await page.waitForFunction(() => /^(Saved\.|Save failed:)/.test(document.querySelector('#opf-b-status')?.textContent || ''), null, { timeout: 10000 });
	const saveStatus = await page.locator('#opf-b-status').innerText();
	check('real WordPress REST save succeeds', saveStatus === 'Saved.');
	if ( saveStatus !== 'Saved.' ) console.log(`REST status ${saveResponse ? saveResponse.status() : 'no response'}: ${saveStatus}`);

	const storedEncoded = wp(`$p=get_post(${fixtureId}); echo base64_encode($p->post_content);`);
	const stored = JSON.parse(Buffer.from(storedEncoded, 'base64').toString('utf8'));
	const fields = stored.fields;
	check('REST save persists all fields with a fresh ID that avoids collisions', fields.length === 3 && fields[1].id !== fields[0].id && fields[1].id !== fields[2].id && fields[1].id === 'e2e-size-copy-2');
	check('copy retains source settings and choices while edits stay independent', fields[0].label === 'Size' && fields[1].label === 'Custom size' && fields[1].description === fields[0].description && JSON.stringify(fields[1].choices) === JSON.stringify(fields[0].choices));
	check('source formula and sibling field stay unchanged', fields[0].pricing.formula === '[price] + checked(e2e-size-copy)' && fields[2].id === 'e2e-size-copy');
	await page.locator('.opf-b-field').first().locator('.button-link-delete').click();
	check('WordPress editor removes the selected field', await page.locator('.opf-b-field').count() === 2);
	await page.locator('.opf-b-toolbar button').nth(1).click();
	await page.waitForFunction(() => document.querySelector('#opf-b-status')?.textContent === 'Saved.', null, { timeout: 10000 });
	const afterDeleteEncoded = wp(`$p=get_post(${fixtureId}); echo base64_encode($p->post_content);`);
	const afterDelete = JSON.parse(Buffer.from(afterDeleteEncoded, 'base64').toString('utf8'));
	check('WordPress REST deletion saves without dangling or empty conditional rules', afterDelete.fields.length === 2 && afterDelete.fields[1].conditionals.length === 1 && afterDelete.fields[1].conditionals[0].rules.length === 1 && afterDelete.fields[1].conditionals[0].rules[0].field === 'external-trigger');
	check('browser has no uncaught errors', errors.length === 0);
	if ( errors.length ) console.log(errors.join('\n'));
} finally {
	await browser.close();
	const cleanup = wp(`wp_delete_post(${fixtureId}, true); echo get_post(${fixtureId}) ? 'leftover' : 'removed';`);
	if ( cleanup !== 'removed' ) throw new Error('WordPress field-duplication fixture was not removed.');
	wp(`WP_Session_Tokens::get_instance(${adminId})->destroy('${authToken}');`);
}

process.exit(failures ? 1 : 0);
