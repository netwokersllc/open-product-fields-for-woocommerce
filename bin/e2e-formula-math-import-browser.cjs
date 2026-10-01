const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { chromium } = require('playwright');

const proof = path.join(__dirname, 'e2e-formula-math-import.php');
const run = (mode, fixture = {}) => execFileSync('wp', ['--path=/tmp/opf-math-import-wp', 'eval-file', proof], {
	encoding: 'utf8', env: { ...process.env, OPF_MATH_IMPORT_E2E_ALLOW: '1', OPF_MATH_IMPORT_E2E_MODE: mode,
		OPF_MATH_IMPORT_PRODUCT: String(fixture.product_id || ''), OPF_MATH_IMPORT_GROUP: String(fixture.group_id || '') },
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
		await page.locator('[data-opf-field="count"] input').fill('3');
		await page.locator('[data-opf-field="plan"] select').selectOption('math');
		const total = page.locator('.opf-grand-total');
		await page.waitForFunction(() => document.querySelector('.opf-grand-total')?.textContent.includes('22.00'));
		assert.match(await total.textContent(), /22\.00/);
		await page.locator('form.cart input[name="quantity"]').fill('3');
		await page.waitForFunction(() => document.querySelector('.opf-grand-total')?.textContent.includes('66.00'));
		assert.match(await total.textContent(), /66\.00/);
		await page.locator('[data-opf-field="count"] input').fill('6');
		await page.waitForFunction(() => document.querySelector('.opf-grand-total')?.textContent.includes('93.00'));
		assert.match(await total.textContent(), /93\.00/);
		assert.deepEqual(errors, []);
		console.log('Real Chromium imported 11-function choice: count=3/q=1 $22.00; count=3/q=3 $66.00; count=6/q=3 $93.00; no page errors.');
		console.log(run('commerce', fixture).trim());
	} finally {
		if (browser) await browser.close();
		console.log(run('cleanup', fixture).trim());
	}
})().catch((error) => { console.error(error); process.exitCode = 1; });
