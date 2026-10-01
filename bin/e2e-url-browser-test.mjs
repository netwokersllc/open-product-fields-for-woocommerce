// Real Chromium + actual WordPress REST save + classic cart and block checkout.
// Run after e2e-url-lifecycle.php setup, then run its commerce and cleanup phases.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8126';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Use a disposable loopback site only.');
const artifactDir = process.env.OPF_URL_ARTIFACT_DIR || '/tmp/opf-url-artifacts';
fs.mkdirSync(artifactDir, { recursive: true });
const checks = [];
const check = (label, condition) => {
	checks.push({ label, pass: !!condition });
	console.log(`${condition ? 'ok' : 'FAIL'} ${label}`);
	if (!condition) throw new Error(label);
};
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
try {
	await page.goto(base + '/?opf_url_login=1', { waitUntil: 'domcontentloaded' });
	await page.locator('.opf-b-field').first().waitFor();
	let adminPosts = 0;
	let adminNavigations = 0;
	page.on('request', request => {
		if (request.method() === 'POST' && new URL(request.url()).pathname === '/wp-admin/post.php') adminPosts++;
	});
	page.on('framenavigated', frame => { if (frame === page.mainFrame()) adminNavigations++; });
	await page.getByRole('button', { name: '+ Add field', exact: true }).click();
	check('add field stays in the editor without WordPress form submission', await page.locator('.opf-b-field').count() === 4 && adminPosts === 0 && adminNavigations === 0);
	const temporary = page.locator('.opf-b-field').nth(3);
	await temporary.locator('.opf-b-field-head select').selectOption('select');
	await temporary.getByRole('button', { name: '+ Add choice', exact: true }).click();
	check('add choice stays in the editor without WordPress form submission', await temporary.locator('.opf-b-choice').count() === 2 && adminPosts === 0 && adminNavigations === 0);
	check('every mounted builder action explicitly uses button type', await page.locator('#opf-builder-app button').evaluateAll(buttons => buttons.length > 0 && buttons.every(button => button.getAttribute('type') === 'button')));
	await temporary.locator('.opf-b-remove').last().click();
	check('remove choice stays in the editor without WordPress form submission', await temporary.locator('.opf-b-choice').count() === 1 && adminPosts === 0 && adminNavigations === 0);
	await temporary.getByRole('button', { name: 'Delete field', exact: true }).click();
	check('delete field stays in the editor without WordPress form submission', await page.locator('.opf-b-field').count() === 3 && adminPosts === 0 && adminNavigations === 0);
	const required = page.locator('.opf-b-field').nth(0);
	const prefill = page.locator('.opf-b-field').nth(1);
	await required.locator('.opf-b-label').fill('Profile URL edited');
	await required.locator('input[title="Required"]').check();
	await required.getByRole('textbox', { name: 'Placeholder', exact: true }).fill('https://example.invalid/');
	await prefill.getByRole('textbox', { name: 'Default value', exact: true }).fill('https://example.invalid/default?a=1&b=2');
	await prefill.locator('input[title="Required"]').check();
	const previewResponse = page.waitForResponse(response => response.url().includes('/opf/v1/preview') && response.request().method() === 'POST');
	await page.getByRole('button', { name: 'Refresh preview', exact: true }).click();
	check('actual preview REST response succeeds', (await previewResponse).ok());
	await page.locator('#opf-b-preview input[data-field-id="prefill"]').waitFor();
	check('refresh preview preserves edited inputs without WordPress form submission', await required.locator('.opf-b-label').inputValue() === 'Profile URL edited' && await prefill.getByRole('textbox', { name: 'Default value', exact: true }).inputValue() === 'https://example.invalid/default?a=1&b=2' && adminPosts === 0 && adminNavigations === 0);
	const responsePromise = page.waitForResponse(response => response.url().includes('/opf/v1/groups') && response.request().method() === 'POST');
	await page.getByRole('button', { name: 'Save', exact: true }).click();
	const response = await responsePromise;
	check('actual admin REST save succeeds', response.ok());
	const saved = await response.json();
	check('actual REST response preserves URL settings', saved.data.fields[0].required === true && saved.data.fields[0].placeholder === 'https://example.invalid/' && saved.data.fields[1].default === 'https://example.invalid/default?a=1&b=2');
	await page.getByText('Saved.', { exact: true }).waitFor();
	await page.reload({ waitUntil: 'domcontentloaded' });
	await page.locator('.opf-b-field').first().waitFor();
	check('admin reload preserves label', await required.locator('.opf-b-label').inputValue() === 'Profile URL edited');
	check('admin reload preserves required', await required.locator('input[title="Required"]').isChecked());
	check('admin reload preserves default and placeholder', await prefill.getByRole('textbox', { name: 'Default value', exact: true }).inputValue() === 'https://example.invalid/default?a=1&b=2' && await required.getByRole('textbox', { name: 'Placeholder', exact: true }).inputValue() === 'https://example.invalid/');
	await page.screenshot({ path: artifactDir + '/admin-reload.png', fullPage: true });
	await page.goto(base + '/product/opf-url-lifecycle/', { waitUntil: 'domcontentloaded' });
	const input = page.locator('[data-opf-field="message"] input[type="url"]');
	const defaultInput = page.locator('[data-opf-field="prefill"] input[type="url"]');
	await input.waitFor();
	check('storefront has required single line input and placeholder', await input.getAttribute('required') !== null && await input.getAttribute('placeholder') === 'https://example.invalid/');
	check('storefront renders saved default', await defaultInput.inputValue() === 'https://example.invalid/default?a=1&b=2');
	check('optional field is optional', await page.locator('[data-opf-field="optional"] input').getAttribute('required') === null);
	let productPosts = 0;
	page.on('request', request => { if (request.method() === 'POST' && request.url().includes('/product/opf-url-lifecycle/')) productPosts++; });
	await page.locator('button.single_add_to_cart_button').click();
	check('empty required field blocks browser submission', await input.evaluate(node => !node.validity.valid) && productPosts === 0);
	await page.screenshot({ path: artifactDir + '/required-invalid.png', fullPage: true });
	const productId = await page.locator('button.single_add_to_cart_button').getAttribute('value');
	const requiredName = await input.getAttribute('name');
	const defaultName = await defaultInput.getAttribute('name');
	await input.fill('not-a-url');
	await page.locator('button.single_add_to_cart_button').click();
	check('malformed URL blocks real browser submission', await input.evaluate(node => node.validity.typeMismatch) && productPosts === 0);
	await input.fill('javascript:alert(1)');
	check('browser URL constraint permits javascript scheme', await input.evaluate(node => node.validity.valid));
	await input.fill('https://example.invalid/browser?a=1&b=2');
	const invalid = await page.request.post(base + '/product/opf-url-lifecycle/', {
		form: { 'add-to-cart': productId, quantity: '1', [requiredName]: 'not-a-url', [defaultName]: 'https://example.invalid/default' },
	});
	check('server rejects forged malformed classic HTTP submission', (await invalid.text()).includes('must be a valid URL'));
	const emptyCart = await page.request.get(base + '/wp-json/wc/store/v1/cart');
	check('rejected classic HTTP submission leaves cart empty', (await emptyCart.json()).items.length === 0);
	const nonce = emptyCart.headers()['nonce'];
	check('actual Store API cart response provides a nonce', !!nonce);
	const groupId = requiredName.match(/^opf\[([^\]]+)\]/)[1];
	for (const malformed of ['not-a-url', 'javascript:alert(1)', 'data:text/html,x', 'https://example.invalid/<script>', ['https://example.invalid/']]) {
		const rejected = await page.request.post(base + '/wp-json/wc/store/v1/cart/add-item', {
			headers: { Nonce: nonce },
			data: { id: Number(productId), quantity: 1, opf_fields: { [groupId]: { message: malformed } } },
		});
		check('actual Store API HTTP rejects malformed URL ' + JSON.stringify(malformed), rejected.status() >= 400 && (await rejected.text()).includes('valid URL'));
	}
	check('HTTP Store API rejection leaves cart empty', (await (await page.request.get(base + '/wp-json/wc/store/v1/cart')).json()).items.length === 0);
	await input.fill('https://example.invalid/browser?a=1&b=2');
	const nav = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
	await page.locator('button.single_add_to_cart_button').click();
	await nav;
	check('valid URL submits classic product form', productPosts === 1);
	await page.goto(base + '/cart/', { waitUntil: 'domcontentloaded' });
	await page.getByText('https://example.invalid/browser?a=1&b=2', { exact: true }).first().waitFor({ timeout: 30000 });
	check('browser cart renders URL and default', (await page.textContent('body')).includes('https://example.invalid/default?a=1&b=2'));
	check('cart DOM escapes URL query text', (await page.content()).includes('a=1&amp;b=2'));
	await page.screenshot({ path: artifactDir + '/classic-cart.png', fullPage: true });
	await page.goto(base + '/checkout/', { waitUntil: 'domcontentloaded' });
	await page.getByText('https://example.invalid/browser?a=1&b=2', { exact: true }).first().waitFor({ timeout: 30000 });
	check('browser checkout renders URL and default', (await page.textContent('body')).includes('https://example.invalid/default?a=1&b=2'));
	await page.getByLabel(/^email address$/i).first().fill('url@example.invalid');
	await page.getByLabel(/^first name$/i).first().fill('Text');
	await page.getByLabel(/^last name$/i).first().fill('Buyer');
	await page.getByLabel(/^address$/i).first().fill('1 Test Street');
	await page.getByLabel(/^city$/i).first().fill('Testville');
	const state = page.getByLabel(/^state$/i).first();
	if (await state.count()) await state.selectOption('CA');
	await page.getByLabel(/^zip code$/i).first().fill('90210');
	await page.screenshot({ path: artifactDir + '/checkout.png', fullPage: true });
	const checkoutResponse = page.waitForResponse(response => response.url().includes('/wc/store/v1/checkout') && response.request().method() === 'POST');
	await page.getByRole('button', { name: /place order/i }).click();
	const placed = await checkoutResponse;
	check('browser Store API checkout succeeds', placed.ok());
	const order = await placed.json();
	fs.writeFileSync(artifactDir + '/browser-order.json', JSON.stringify({ order_id: order.order_id }, null, 2));
	await page.waitForURL(/order-received/, { timeout: 30000 });
	check('browser order confirmation displays URL and default', (await page.textContent('body')).includes('https://example.invalid/browser?a=1&b=2') && (await page.textContent('body')).includes('https://example.invalid/default?a=1&b=2'));
	check('order DOM escapes URL query text', (await page.content()).includes('a=1&amp;b=2'));
	await page.screenshot({ path: artifactDir + '/order-received.png', fullPage: true });
	check('no browser page errors', errors.length === 0);
} finally {
	fs.writeFileSync(artifactDir + '/browser-results.json', JSON.stringify({ base, checks, errors }, null, 2));
	await browser.close();
}
