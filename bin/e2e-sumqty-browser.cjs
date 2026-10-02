const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.join(__dirname, '..');
const proof = path.join(__dirname, 'e2e-sumqty-browser-fixture.php');
const run = (mode, fixture = {}, rows = []) => execFileSync('wp', ['--path=' + path.join(root, 'vendor/sumqty-wordpress'), 'eval-file', proof], {
	encoding: 'utf8', env: { ...process.env, OPF_SUMQTY_E2E_ALLOW: '1', OPF_SUMQTY_E2E_MODE: mode,
		OPF_SUMQTY_PRODUCT: String(fixture.product_id || ''), OPF_SUMQTY_GROUP: String(fixture.group_id || ''), OPF_SUMQTY_OBSERVATIONS: JSON.stringify(rows) },
});

(async () => {
	const fixture = JSON.parse(run('create'));
	let browser;
	try {
		browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined });
		const page = await browser.newPage();
		const errors = [];
		page.on('pageerror', (error) => errors.push(error.message));
		await page.goto(fixture.url, { waitUntil: 'networkidle' });
		assert.match(new URL(page.url()).origin, /^http:\/\/127\.0\.0\.1:8182$/);
		const response = await page.request.get(new URL('/wp-content/plugins/open-product-fields-for-woocommerce/assets/js/opf-frontend.js', page.url()).toString());
		assert.equal(response.status(), 200);
		assert.equal(await response.text(), fs.readFileSync(path.join(root, 'assets/js/opf-frontend.js'), 'utf8'));
		await page.locator('[data-opf-field="notes"] input').fill('23');
		for (const field of ['fee', 'unrelated', 'missing']) await page.locator(`[data-opf-field="${field}"] select`).selectOption('selected');
		const oak = page.locator('.opf-image-quantity__input[data-choice-slug="oak"]');
		const ash = page.locator('.opf-image-quantity__input[data-choice-slug="ash"]');
		assert.equal(await page.locator('.opf-image-quantity__input[data-choice-slug="disabled"]').isDisabled(), true);
		const quantity = page.locator('form.cart input[name="quantity"]');
		const rows = [];
		for (const [o, a, q, expected] of [[2, 3, 1, 28], [2, 3, 2, 43], [3, 0, 2, 32], [5, 3, 2, 55]]) {
			await oak.fill(String(o));
			await ash.fill(String(a));
			await quantity.fill(String(q));
			await page.waitForFunction((value) => document.querySelector('.opf-grand-total')?.textContent.includes(value), expected.toFixed(2), { timeout: 5000 }).catch(async (error) => {
				console.error(JSON.stringify(await page.evaluate(() => ({ total: document.querySelector('.opf-grand-total')?.textContent, fields: window.OPF_FIELDS, inputs: [...document.querySelectorAll('form.cart input')].map((input) => ({ name: input.name, value: input.value, disabled: input.disabled })) }))));
				throw error;
			});
			const text = await page.locator('.opf-grand-total').textContent();
			const total = Number(text.replace(/[^0-9.]/g, ''));
			assert.equal(total, expected);
			assert.equal(await oak.evaluate((input) => input.checkValidity()), true);
			rows.push({ oak: o, ash: a, quantity: q, total });
		}
		await oak.fill('5');
		await ash.fill('4');
		assert.match(await oak.evaluate((input) => input.validationMessage), /no more than 8 items/);
		await oak.fill('1');
		await ash.fill('1');
		assert.match(await oak.evaluate((input) => input.validationMessage), /at least 3 items/);
		await oak.fill('2.5');
		await ash.fill('3');
		assert.equal(await oak.evaluate((input) => input.checkValidity()), false);
		await oak.fill('2');
		await ash.fill('3');
		assert.equal(await oak.evaluate((input) => input.checkValidity()), true);
		assert.deepEqual(errors, []);
		const artifacts = path.join(root, 'vendor/sumqty-artifacts');
		fs.mkdirSync(artifacts, { recursive: true });
		await page.screenshot({ path: path.join(artifacts, 'sumqty-desktop.png'), fullPage: true });
		await page.setViewportSize({ width: 390, height: 844 });
		await page.screenshot({ path: path.join(artifacts, 'sumqty-mobile.png'), fullPage: true });
		console.log(JSON.stringify({ browser: 'Chromium', observations: rows, invalid: ['aggregate minimum', 'aggregate maximum', 'fraction'], page_errors: errors, served_js_matches_worktree: true }));
		console.log(run('commerce', fixture, rows).trim());
	} finally {
		if (browser) await browser.close();
		console.log(run('cleanup', fixture).trim());
	}
})().catch((error) => { console.error(error); process.exitCode = 1; });
