// Run against a disposable localhost clone: OPF_DATE_PROOF_WP_PATH=/path/to/wp node bin/e2e-date-format-fallback-browser.cjs
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { chromium } = require('playwright');
const wpPath = process.env.OPF_DATE_PROOF_WP_PATH;
if (!wpPath) throw new Error('Set OPF_DATE_PROOF_WP_PATH to a disposable localhost WordPress installation.');
const fixture = path.join(__dirname, 'e2e-date-format-fallback.php');
const wp = (action, scenario) => execFileSync('wp', [`--path=${wpPath}`, 'eval-file', fixture], {
	encoding: 'utf8', env: { ...process.env, OPF_DATE_PROOF_ACTION: action, OPF_DATE_PROOF_CASE: scenario },
});
const check = (condition, message) => { if (!condition) throw new Error(message); };

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.OPF_CHROME_PATH || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox'] });
	try {
		for (const scenario of ['fallback', 'default', 'opf']) {
			const context = await browser.newContext();
			try {
				const proof = JSON.parse(wp('setup', scenario).trim());
				const page = await context.newPage();
				const errors = [];
				page.on('pageerror', error => errors.push(error.message));
				const response = await page.goto(proof.url, { waitUntil: 'networkidle' });
				check(response.status() === 200, 'Product response must be 200.');
				check(await page.evaluate(() => window.OPF_DATE_FORMAT) === proof.format, 'Global format mismatch.');
				const date = page.locator(`input[name="opf[${proof.group_id}][proof_date]"]`);
				check(await date.getAttribute('data-opf-date-format') === proof.format, 'Renderer format mismatch.');
				await date.fill('2026-10-15');
				const browserIso = await date.inputValue();
				check(browserIso === '2026-10-15', 'Native value must stay ISO.');
				check(await page.locator('.opf-date-picker__toggle').first().textContent() === proof.display, 'Picker display mismatch.');
				await page.locator(`input[name="opf[${proof.group_id}][formula_date]"]`).fill(proof.display);
				await page.screenshot({ path: `/tmp/opf-date-format-fallback-${scenario}-20261002.png`, fullPage: true });
				await Promise.all([
					page.waitForNavigation({ waitUntil: 'networkidle' }),
					page.locator('button[name="add-to-cart"]').click(),
				]);
				const cart = await page.evaluate(async () => {
					const response = await fetch('/wp-json/wc/store/v1/cart');
					return { status: response.status, body: await response.json() };
				});
				check(cart.status === 200, 'Store API cart response must be 200.');
				const item = cart.body.items.find(item => item.id === proof.product_id);
				check(item, 'Browser-submitted item must reach the cart.');
				check(item.item_data.some(row => row.name === 'Proof date' && row.value === proof.display), `Store API cart label mismatch: ${JSON.stringify(item.item_data)}`);
				check(item.prices.price === '1400', 'Browser-formatted date must price the cart at 14.00.');
				await page.goto(proof.cart_url, { waitUntil: 'networkidle' });
				await page.getByText(proof.display, { exact: true }).first().waitFor({ state: 'visible', timeout: 15000 });
				await page.screenshot({ path: `/tmp/opf-date-format-fallback-${scenario}-cart-20261002.png`, fullPage: true });
				check((await page.locator('body').innerText()).includes(proof.display), `Rendered cart label mismatch at ${page.url()}: ${(await page.locator('body').innerText()).slice(0, 1200)}`);
				await page.goto(proof.checkout_url, { waitUntil: 'networkidle' });
				await page.getByText(proof.display, { exact: true }).first().waitFor({ state: 'visible', timeout: 15000 });
				check((await page.locator('body').innerText()).includes(proof.display), 'Rendered checkout label mismatch.');
				await page.screenshot({ path: `/tmp/opf-date-format-fallback-${scenario}-checkout-20261002.png`, fullPage: true });
				check(errors.length === 0, `Browser errors: ${errors.join('; ')}`);
				console.log(JSON.stringify({ ...proof, browser_iso: browserIso, store_api_display: item.item_data.find(row => row.name === 'Proof date').value, browser_cart_price: item.prices.price, cart_and_checkout_labels: true, page_errors: errors }));
			} finally {
				wp('cleanup', scenario);
				await context.close();
			}
		}
	} finally {
		await browser.close();
	}
})().catch(error => { console.error(error); process.exitCode = 1; });
