/** Real Chromium, native WooCommerce coupon editor, native POST save/reload. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const wpPath = process.env.OPF_WP_PATH || '/tmp/opf-price-coupon-scope-wp';
const artifactDir = process.env.OPF_COUPON_ARTIFACT_DIR || '/tmp/opf-coupon-admin-artifacts';
const run = (mode, fixture = {}) => execFileSync('wp', ['--path=' + wpPath, 'eval-file', path.join(__dirname, 'e2e-coupon-admin.php')], {
	encoding: 'utf8', env: { ...process.env, OPF_COUPON_E2E_ALLOW: '1', OPF_COUPON_ADMIN_MODE: mode,
		OPF_COUPON_ADMIN_ID: String(fixture.coupon_id || ''), OPF_COUPON_ADMIN_USER_ID: String(fixture.user_id || '') },
});

(async () => {
	fs.mkdirSync(artifactDir, { recursive: true });
	const fixture = JSON.parse(run('create'));
	assert.match(fixture.url, /^http:\/\/127\.0\.0\.1:\d+$/);
	const checks = [];
	const check = (label, condition) => { assert.ok(condition, label); checks.push({ label, pass: true }); console.log('ok ' + label); };
	let browser;
	try {
		browser = await chromium.launch();
		const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await page.goto(fixture.url + '/wp-login.php', { waitUntil: 'networkidle' });
		await page.locator('#user_login').fill(fixture.username);
		await page.locator('#user_pass').fill(fixture.password);
		await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
		await page.goto(fixture.url + '/wp-admin/post.php?post=' + fixture.coupon_id + '&action=edit', { waitUntil: 'networkidle' });
		const checkbox = page.getByRole('checkbox', { name: 'Calculate only on base price', exact: true });
		const type = page.locator('#discount_type');
		check('fixed-cart coupon hides the OPF percentage scope control', !(await checkbox.isVisible()));
		await type.selectOption('percent');
		check('selecting percent shows the native accessible checkbox, unchecked by default', await checkbox.isVisible() && !(await checkbox.isChecked()));
		await checkbox.check();
		await page.screenshot({ path: path.join(artifactDir, 'percent-checked.png'), fullPage: true });
		await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('#publish').click()]);
		await page.reload({ waitUntil: 'networkidle' });
		check('native update and reload preserves checked percent scope', await checkbox.isVisible() && await checkbox.isChecked() && await type.inputValue() === 'percent');
		check('Woo coupon CRUD reload persists the imported WAPF metadata key', JSON.parse(run('inspect', fixture)).meta === 'yes');
		await type.selectOption('fixed_product');
		check('switching to fixed-product hides the checkbox', !(await checkbox.isVisible()));
		await type.selectOption('percent');
		check('switching back to percent preserves checked state', await checkbox.isVisible() && await checkbox.isChecked());
		await checkbox.uncheck();
		await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('#publish').click()]);
		await page.reload({ waitUntil: 'networkidle' });
		check('native unchecked save and reload restores the default off state', await checkbox.isVisible() && !(await checkbox.isChecked()) && JSON.parse(run('inspect', fixture)).meta === '');
		await page.screenshot({ path: path.join(artifactDir, 'percent-unchecked.png'), fullPage: true });
		await page.setViewportSize({ width: 390, height: 844 });
		check('percentage checkbox remains visible at mobile width', await checkbox.isVisible());
		await checkbox.focus();
		await page.keyboard.press('Space');
		check('keyboard Space toggles the native checkbox', await checkbox.isChecked());
		await page.screenshot({ path: path.join(artifactDir, 'mobile-keyboard.png'), fullPage: true });
		check('coupon editor has no uncaught browser errors', errors.length === 0);
		fs.writeFileSync(path.join(artifactDir, 'results.json'), JSON.stringify({ timestamp: new Date().toISOString(), url: fixture.url, viewport: [1280, 960], mobileViewport: [390, 844], checks, errors }, null, 2) + '\n');
	} finally {
		if (browser) await browser.close();
		console.log(run('cleanup', fixture).trim());
	}
})().catch(error => { console.error(error); process.exitCode = 1; });
