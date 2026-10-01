// Authenticated Chromium proof for high-count field-builder save/reload.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const fixture = path.join(here, 'e2e-choice-capacity-fixture.php');
const base = process.env.OPF_BASE_URL || '';
const wpPath = process.env.OPF_WP_PATH || '';
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE || '';
const fieldCount = Number(process.env.OPF_CHOICE_CAPACITY_FIELDS || 512);
const choiceCount = Number(process.env.OPF_CHOICE_CAPACITY_CHOICES || 512);
if (!base || !wpPath || !passwordFile) {
	throw new Error('OPF_BASE_URL, OPF_WP_PATH, and OPF_E2E_PASSWORD_FILE are required');
}
const realWpPath = path.resolve(wpPath);
if (!realWpPath.startsWith('/tmp/') || !readFileSync(path.join(realWpPath, '.opf-disposable-e2e'), 'utf8').includes('opf-choice-capacity-wp')) {
	throw new Error('Refusing to run outside the marked disposable /tmp WordPress clone');
}
const baseUrl = new URL(base);
if (!['127.0.0.1', 'localhost'].includes(baseUrl.hostname)) {
	throw new Error('Browser base URL must use localhost');
}
if (!Number.isInteger(fieldCount) || fieldCount < 2 || !Number.isInteger(choiceCount) || choiceCount < 2) {
	throw new Error('Field and choice counts must be integers of at least 2');
}

const wp = (args) => execFileSync('wp', [`--path=${realWpPath}`, ...args], { encoding: 'utf8' }).trim();
const token = randomUUID().replaceAll('-', '').slice(0, 12);
const runFixture = (mode) => wp(['eval-file', fixture, mode, token]);
const groupId = Number(runFixture('create'));
let browser;
let page;
const errors = [];
const checks = [];
const check = (name, ok, detail = '') => {
	checks.push({ name, ok, detail });
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}${detail ? ` (${detail})` : ''}`);
};

try {
	browser = await chromium.launch();
	page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
	page.on('pageerror', (error) => errors.push(error.message));
	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill('opf_capacity_runner');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');
	check('authenticated as the disposable administrator', new URL(page.url()).pathname.startsWith('/wp-admin/'));

	const editUrl = `${base}/wp-admin/post.php?post=${groupId}&action=edit`;
	const started = performance.now();
	await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
	await page.locator('#opf-builder-fields .opf-b-field').nth(fieldCount - 1).waitFor({ state: 'visible', timeout: 120000 });
	const loadMs = Math.round(performance.now() - started);
	const initial = await page.locator('#opf-builder-app').evaluate((node) => JSON.parse(node.dataset.model));
	const initialChoices = initial.fields.at(-1)?.choices || [];
	check('admin builder loads the complete field count', initial.fields.length === fieldCount, `${initial.fields.length} fields in ${loadMs}ms`);
	check('admin builder loads the complete choice count', initialChoices.length === choiceCount, `${initialChoices.length} choices`);
	check('first and last field identifiers survive initial admin render',
		initial.fields[0]?.id === 'capacity-field-0001' && initial.fields.at(-1)?.id === `capacity-field-${String(fieldCount).padStart(4, '0')}`);
	check('first and last choice identifiers survive initial admin render',
		initialChoices[0]?.slug === 'capacity-choice-0001' && initialChoices.at(-1)?.slug === `capacity-choice-${String(choiceCount).padStart(4, '0')}`);

	const editedLastFieldLabel = `Capacity field ${String(fieldCount).padStart(4, '0')} saved`;
	await page.locator('#opf-builder-fields input.opf-b-label').nth(fieldCount - 1).fill(editedLastFieldLabel);
	const saveResponsePromise = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/wp-json/opf/v1/groups'), { timeout: 120000 });
	await page.getByRole('button', { name: 'Save', exact: true }).click();
	const saveResponse = await saveResponsePromise;
	const saved = await saveResponse.json();
	await page.getByText('Saved.', { exact: true }).waitFor({ timeout: 120000 });
	const savedFields = saved?.data?.fields || [];
	const savedChoices = savedFields.at(-1)?.choices || [];
	check('builder save uses authenticated REST successfully', saveResponse.status() === 200, `HTTP ${saveResponse.status()}`);
	check('REST save response retains all fields and choices', savedFields.length === fieldCount && savedChoices.length === choiceCount,
		`${savedFields.length} fields / ${savedChoices.length} choices`);
	check('REST response retains boundary labels and slugs',
		savedFields[0]?.label === 'Capacity field 0001'
		&& savedFields.at(-1)?.label === editedLastFieldLabel
		&& savedChoices[0]?.slug === 'capacity-choice-0001'
		&& savedChoices.at(-1)?.slug === `capacity-choice-${String(choiceCount).padStart(4, '0')}`);

	await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
	await page.locator('#opf-builder-fields .opf-b-field').nth(fieldCount - 1).waitFor({ state: 'visible', timeout: 120000 });
	const reloaded = await page.locator('#opf-builder-app').evaluate((node) => JSON.parse(node.dataset.model));
	const reloadedChoices = reloaded.fields.at(-1)?.choices || [];
	check('fresh admin reload retains all fields and choices', reloaded.fields.length === fieldCount && reloadedChoices.length === choiceCount,
		`${reloaded.fields.length} fields / ${reloadedChoices.length} choices`);
	check('fresh admin reload retains first and last field identifiers',
		reloaded.fields[0]?.id === 'capacity-field-0001' && reloaded.fields.at(-1)?.id === `capacity-field-${String(fieldCount).padStart(4, '0')}`);
	check('fresh admin reload retains the actual builder edit', reloaded.fields.at(-1)?.label === editedLastFieldLabel);
	check('fresh admin reload retains first and last choice identifiers',
		reloadedChoices[0]?.slug === 'capacity-choice-0001' && reloadedChoices.at(-1)?.slug === `capacity-choice-${String(choiceCount).padStart(4, '0')}`);
	check('no uncaught JavaScript exceptions in admin browser', errors.length === 0, errors.length ? errors.join(' | ') : '0');

	const stored = JSON.parse(wp(['eval', `echo wp_json_encode(OPF\\Service\\FieldGroups::group_from_post(get_post(${groupId}))->data);`]));
	check('database reload retains all fields and choices', stored.fields.length === fieldCount && stored.fields.at(-1)?.choices.length === choiceCount,
		`${stored.fields.length} fields / ${stored.fields.at(-1)?.choices.length || 0} choices`);
	const raw = JSON.parse(wp(['eval', `echo wp_json_encode(json_decode((string) get_post_field('post_content', ${groupId}), true));`]));
	const storedBytes = Number(wp(['eval', `echo strlen((string) get_post_field('post_content', ${groupId}));`]));
	check('raw database post content decodes with all fields and choices',
		raw.fields.length === fieldCount && raw.fields.at(-1)?.choices.length === choiceCount,
		`${raw.fields.length} fields / ${raw.fields.at(-1)?.choices.length || 0} choices`);
	check('database post content is valid UTF-8 JSON', Number.isInteger(storedBytes) && storedBytes > 0, `${storedBytes} bytes`);
} catch (error) {
	check('browser run completes without an uncaught runner failure', false, error.message.split('\n')[0]);
} finally {
	if (browser) await browser.close();
	const removed = Number(runFixture('cleanup'));
	check('tagged field-group fixture is removed', removed >= 1, `${removed} group(s)`);
}

const failures = checks.filter((entry) => !entry.ok);
console.log(JSON.stringify({ fields: fieldCount, choices: choiceCount, checks: checks.length, passed: checks.length - failures.length, failed: failures.length }));
process.exit(failures.length ? 1 : 0);
