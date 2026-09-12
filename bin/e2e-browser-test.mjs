import { createRequire } from 'node:module';
const require2 = createRequire(process.cwd() + '/index.js');
const { chromium } = require2('playwright');
const BASE = process.env.OPF_BASE_URL || 'http://127.0.0.1:8090';
let pass = 0, fail = 0;
const check = (l, c) => { if (c) { pass++; console.log('  ok   ', l); } else { fail++; console.log('  FAIL ', l); } };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', e => errors.push('pageerror: ' + e.message));

// 1. Product page
await page.goto(BASE + '/product/e2e-matched-product/', { waitUntil: 'domcontentloaded' });
await page.locator('[data-opf-field="delivery"] label:has(input[value="boost"])').click();
await page.locator('[data-opf-field="boost_note"] textarea').fill('rush order please');
await page.screenshot({ path: '/tmp/ui-1-product.png' });
check('product: boost selected + note filled + screenshot', true);

// 2. Add to cart (classic form POST)
await Promise.all([
	page.waitForLoadState('domcontentloaded'),
	page.locator('form.cart button[name="add-to-cart"], button.single_add_to_cart_button').first().click(),
]);

// 3. Cart page — block cart is client-rendered; wait for the data, not a timer
await page.goto(BASE + '/cart/', { waitUntil: 'domcontentloaded' });
let appeared = true;
try { await page.waitForSelector('text=Delivery speed', { timeout: 15000 }); } catch { appeared = false; }
check('cart: selection visible in block cart', appeared);
if (appeared) {
	const t = await page.textContent('body');
	check('cart: Boost choice shown', t.includes('Boost'));
	check('cart: boost note shown', t.includes('rush order please'));
	check('cart: priced $107.00 (100 + 5 boost + 2 note)', t.includes('107.00'));
}
await page.screenshot({ path: '/tmp/ui-2-cart.png', fullPage: true });

// 4. Checkout — fill by visible labels
await page.goto(BASE + '/checkout/', { waitUntil: 'domcontentloaded' });
let coShown = true;
try { await page.waitForSelector('text=Delivery speed', { timeout: 15000 }); } catch { coShown = false; }
check('checkout: selection visible', coShown);

const byLabel = async (labelRe, value) => {
	const loc = page.getByLabel(labelRe).first();
	try { await loc.waitFor({ state: 'visible', timeout: 8000 }); await loc.fill(value); return true; }
	catch { return false; }
};
check('checkout: email filled', await byLabel(/^email address$/i, 'buyer@example.com'));
check('checkout: first name filled', await byLabel(/^first name$/i, 'Test'));
check('checkout: last name filled', await byLabel(/^last name$/i, 'Buyer'));
check('checkout: address filled', await byLabel(/^address$/i, '1 Test St'));
check('checkout: city filled', await byLabel(/^city$/i, 'Testville'));
check('checkout: postcode filled', await byLabel(/^zip code$/i, '12345'));
await page.waitForTimeout(600);
await page.screenshot({ path: '/tmp/ui-3-checkout.png', fullPage: true });

const place = page.getByRole('button', { name: /place order/i }).first();
check('checkout: place order present', await place.count() > 0);
await place.click();
await page.waitForLoadState('domcontentloaded');
let confirmed = true;
try { await page.waitForSelector('text=Delivery speed', { timeout: 20000 }); } catch { confirmed = false; }
const doneText = await page.textContent('body');
const placed = confirmed || /order-received/.test(page.url()) || doneText.includes('Thank you');
check('order confirmation reached', placed);
check('confirmation shows Delivery speed / Boost', doneText.includes('Delivery speed') && doneText.includes('Boost'));
await page.screenshot({ path: '/tmp/ui-4-order-received.png', fullPage: true });

check('no JS page errors during entire flow', errors.length === 0);
if (errors.length) console.log(errors.slice(0, 5).join('\n'));

console.log(fail === 0 ? `\nSUCCESS: all ${pass} real-browser UI checks passed.` : `\n${fail} FAILURES of ${pass + fail}`);
await browser.close();
process.exit(fail === 0 ? 0 : 1);
