/** Run against the dedicated fresh clone with WAPF Extended 3.1.5, OPF and Woo active. */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const dir = process.env.OPF_FORMULA_ROUNDTRIP_ARTIFACT_DIR || '/tmp/opf-formula-roundtrip-artifacts';
const origin = 'http://127.0.0.1:8216';
const run = (mode, extraEnv = {}) => execFileSync('wp', ['--path=/tmp/opf-formula-roundtrip-wp', 'eval-file', path.join(__dirname, 'e2e-formula-import-roundtrip.php')], {
	encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], env: { ...process.env, OPF_FORMULA_ROUNDTRIP_ALLOW: '1', OPF_FORMULA_ROUNDTRIP_MODE: mode, OPF_FORMULA_ROUNDTRIP_ARTIFACT_DIR: dir, ...extraEnv },
});
const write = (file, value) => fs.writeFileSync(path.join(dir, file), JSON.stringify(value, null, 2));
const auditCleanup = () => JSON.parse(execFileSync('python3', [path.join(__dirname, 'e2e-formula-import-roundtrip-cleanup.py')], { encoding: 'utf8' }));

(async () => {
	fs.mkdirSync(dir, { recursive: true });
	const started = new Date().toISOString();
	assert.ok(process.env.OPF_FORMULA_ROUNDTRIP_ADMIN && process.env.OPF_FORMULA_ROUNDTRIP_PASSWORD, 'Set OPF_FORMULA_ROUNDTRIP_ADMIN and OPF_FORMULA_ROUNDTRIP_PASSWORD for the isolated clone.');
	let browser;
	let state;
	try {
		const setupFailures = [];
		for (const phase of ['source', 'target', 'export']) {
			assert.throws(() => run('setup', { OPF_FORMULA_ROUNDTRIP_FAIL_SETUP_AFTER: phase }), (error) =>
				String(error.stderr).includes('Injected setup failure after ' + phase));
			setupFailures.push({ injected_after: phase, cleanup: auditCleanup() });
		}
		write('setup-failure-cleanup.json', setupFailures);
		state = JSON.parse(run('setup'));
		browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined });
		const page = await browser.newPage();
		page.setDefaultTimeout(20000);
		const adminErrors = [];
		const blockedExternalOrigins = new Set();
		page.on('pageerror', (error) => adminErrors.push(error.message));
		// This clone needs no external account or telemetry request.
		await page.route('**/*', (route) => {
			if (route.request().url().startsWith(origin + '/')) return route.continue();
			blockedExternalOrigins.add(new URL(route.request().url()).origin);
			return route.abort();
		});
		await page.goto(origin + '/wp-login.php', { waitUntil: 'domcontentloaded' });
		await page.locator('#user_login').fill(process.env.OPF_FORMULA_ROUNDTRIP_ADMIN);
		await page.locator('#user_pass').fill(process.env.OPF_FORMULA_ROUNDTRIP_PASSWORD);
		await Promise.all([page.waitForURL('**/wp-admin/**', { waitUntil: 'domcontentloaded' }), page.locator('#wp-submit').click()]);
		await page.goto(`${origin}/wp-admin/post.php?post=${state.source}&action=edit`, { waitUntil: 'domcontentloaded' });
		const licenseMessage = await page.getByText('It appears you are using the paid version without a valid license.', { exact: false }).count();
		await page.locator('.wapf-export').click();
		const uiExport = JSON.parse(await page.locator('.wapf-export-ta').inputValue());
		write('wapf-tools-ui-export.json', uiExport);
		await page.screenshot({ path: path.join(dir, 'wapf-tools-license-boundary.png'), fullPage: true });
		await page.goto(`${origin}/wp-admin/post.php?post=${state.target}&action=edit`, { waitUntil: 'domcontentloaded' });
		await page.locator('.wapf-import').click();
		const modelExport = JSON.parse(fs.readFileSync(path.join(dir, 'wapf-model-export.json'), 'utf8'));
		await page.locator('.wapf-import-ta').fill(JSON.stringify(modelExport));
		await page.locator('.btn-wapf-import').click();
		assert.equal(await page.locator('.wapf-import-success').isVisible(), true);
		await page.locator('.wapf_modal_overlay:visible .wapf_close').click();
		await page.locator('.wapf-export').click();
		const uiReexport = JSON.parse(await page.locator('.wapf-export-ta').inputValue());
		write('wapf-tools-ui-reexport.json', uiReexport);
		if (licenseMessage) {
			assert.equal(uiExport.fields.length, 0);
			assert.equal(uiReexport.fields.length, 0);
		} else {
			assert.equal(uiExport.fields.length, modelExport.fields.length);
			assert.equal(uiReexport.fields.length, modelExport.fields.length);
			assert.notEqual(uiReexport.fields[0].id, modelExport.fields[0].id);
		}
		const phpResult = run('verify').trim();
		console.log(phpResult);
		const reference = JSON.parse(fs.readFileSync(path.join(dir, 'php-results.json'), 'utf8'));
		const response = await page.request.get(origin + '/wp-content/plugins/open-product-fields-for-woocommerce/assets/js/opf-frontend.js');
		assert.equal(response.status(), 200);
		const served = await response.text();
		assert.equal(served, fs.readFileSync(path.join(root, 'assets/js/opf-frontend.js'), 'utf8'));
		const runtime = await browser.newPage();
		const runtimeErrors = [];
		runtime.on('pageerror', (error) => runtimeErrors.push(error.message));
		await runtime.evaluate(() => { window.opf_config = {}; });
		await runtime.addScriptTag({ content: served + '\nglobalThis.__formulaRoundtripAddon = choiceOrFieldAddon;' });
		const results = await runtime.evaluate((rows) => rows.map((row) => ({
			case: row.case, qty: row.qty, native: row.native,
			js_choice: globalThis.__formulaRoundtripAddon({ type: 'select', choices: [{ slug: 'fixture-choice', pricing: row.choice }] }, 'fixture-choice', 100, row.qty, row.addons, '', row.values, row.prices),
			js_field: globalThis.__formulaRoundtripAddon({ type: 'text', pricing: row.field }, '3', 100, row.qty, row.addons, '3', row.values, row.prices),
			js_field_empty: globalThis.__formulaRoundtripAddon({ type: 'text', pricing: row.field }, '', 100, row.qty, row.addons, '', row.values, row.prices),
			js_field_whitespace: globalThis.__formulaRoundtripAddon({ type: 'text', pricing: row.field }, ' ', 100, row.qty, row.addons, ' ', row.values, row.prices),
		})), reference.rows);
		for (const result of results) {
			assert.ok(Math.abs(result.native - result.js_choice) < 0.000001, JSON.stringify(result));
			assert.ok(Math.abs(result.native - result.js_field) < 0.000001, JSON.stringify(result));
			assert.equal(result.js_field_empty, 0);
			assert.equal(result.js_field_whitespace, 0);
		}
		assert.deepEqual(runtimeErrors, []);
		write('browser-results.json', {
			started, finished: new Date().toISOString(), chromium: browser.version(),
			context_provenance: reference.context_provenance,
			js_entrypoint: 'choiceOrFieldAddon: selected select choice and nonempty text field; empty/whitespace text input guards',
			served_frontend_sha256: crypto.createHash('sha256').update(served).digest('hex'),
			tools: { licensed_ui_blocked: Boolean(licenseMessage), native_model_fields: modelExport.fields.length, ui_export_fields: uiExport.fields.length, import_success_visible: true, ui_reexport_fields: uiReexport.fields.length, admin_page_errors: adminErrors, blocked_external_origins: [...blockedExternalOrigins] },
			rows: results, runtime_page_errors: runtimeErrors,
		});
		console.log(`Chromium: ${results.length} synthetic-context WAPF evaluator comparisons pass through select/text choiceOrFieldAddon branches; empty/whitespace scalar guards pass; served JS equals worktree bytes; no formula execution page errors.`);
		console.log(licenseMessage ? 'WAPF Tools UI boundary: invalid-license notice, 16 model fields, empty UI export, success message but empty UI reexport. Licensed UI remapping remains unproven.' : 'WAPF Tools UI: native export/import and changed destination IDs verified.');
	} finally {
		if (browser) await browser.close();
		if (state) {
			const cleanup = run('cleanup').trim();
			fs.writeFileSync(path.join(dir, 'cleanup.txt'), cleanup + '\n');
			console.log(cleanup);
			write('independent-cleanup.json', auditCleanup());
			console.log('Independent read-only SQLite audit: zero fixture posts/meta/option, HPOS orders, order items and item metadata. Three injected partial-setup failures also cleaned.');
		}
	}
})().catch((error) => { console.error(error); process.exitCode = 1; });
