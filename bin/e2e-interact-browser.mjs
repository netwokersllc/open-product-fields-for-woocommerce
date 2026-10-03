// Real-Chromium lifecycle proof for the interaction lane against the live
// fixture installed by e2e-interact-lifecycle.php (phase=setup).
// Covers: INTERACTION-REPEAT, INTERACTION-QUANTITY-REPEAT,
// INTERACTION-IMAGE-CHANGE, PRODUCT-VARIABLE, INTERACTION-CART-EDIT (absence),
// plus a real classic checkout so order meta can be verified server-side.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('/tmp/opf-url-native-parity/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8315';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only');
const dir = '/tmp/opf-lane-interact-evidence';
fs.mkdirSync(dir, { recursive: true });
const state = JSON.parse(fs.readFileSync('/tmp/opf-interact-e2e/state.json', 'utf8'));
const gid = String(state.repeat_gid), vgid = String(state.var_gid);

const checks = [], errors = [];
const check = (label, pass, extra) => {
	checks.push({ label, pass: !!pass, extra: extra ?? null });
	console.log(`${pass ? 'ok' : 'FAIL'} ${label}${extra !== undefined ? ' :: ' + JSON.stringify(extra) : ''}`);
};
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
page.on('pageerror', e => errors.push(String(e.message || e)));

const cartURL = base + '/wp-json/wc/store/v1/cart';
async function storeCart() {
	const r = await page.request.get(cartURL);
	return { nonce: r.headers()['nonce'], data: await r.json() };
}
async function emptyCart() {
	const { nonce, data } = await storeCart();
	for (const it of data.items) await page.request.delete(`${cartURL}/items/${it.key}`, { headers: { Nonce: nonce } });
}

/* ---------------- REPEAT + QUANTITY-REPEAT ---------------- */
await emptyCart();
await page.goto(`${base}/product/opf-ix-repeat/`, { waitUntil: 'domcontentloaded' });
const attendeeWrap = page.locator(`[data-opf-field="attendee"][data-opf-repeat="button"]`);
await attendeeWrap.waitFor();
check('button repeat renders with add control', await attendeeWrap.locator('.opf-field-repeat__add').count() === 1);
// Add a clone → new indexed instance.
await attendeeWrap.locator('.opf-field-repeat__add').click();
check('button clone added with indexed name', await page.locator(`input[name="opf[${gid}][attendee][1]"]`).count() === 1);
check('button clone label rewritten to Guest 2', await page.locator(`label[for="opf-${gid}-attendee-repeat-1"]`).first().innerText().then(t => /Guest 2/.test(t)));
// Section clone.
const extrasWrap = page.locator(`[data-opf-field="extras"][data-opf-section-repeat]`);
await extrasWrap.locator('.opf-field-repeat__add').click();
check('section clone added with indexed child name', await page.locator(`input[name="opf[${gid}][seat][1]"][type="radio"]`).count() === 2);
// Add+remove cycle proves removal works.
await extrasWrap.locator('.opf-field-repeat__add').click();
check('third section clone added', await extrasWrap.locator('[data-opf-repeat-instance]').count() === 3);
await extrasWrap.locator('[data-opf-repeat-instance]').nth(2).locator('.opf-field-repeat__remove').click();
check('section clone removed', await extrasWrap.locator('[data-opf-repeat-instance]').count() === 2);
// Fill values across clones.
await page.locator(`input[name="opf[${gid}][attendee][0]"]`).fill('Ada');
await page.locator(`input[name="opf[${gid}][attendee][1]"]`).fill('Grace');
await page.locator(`input[name="opf[${gid}][seat][0]"][value="front"]`).check();
await page.locator(`input[name="opf[${gid}][seat][1]"][value="back"]`).check();
await page.locator(`input[name="opf[${gid}][meal_note][0]"]`).fill('abc');
await page.locator(`input[name="opf[${gid}][meal_note][1]"]`).fill('x');
await page.locator(`input[name="opf[${gid}][note_fee][0]"]`).fill('n1');
await page.locator(`input[name="opf[${gid}][note_fee][1]"]`).fill('n2');
// Quantity repeat: qty=2 must produce a second ticket row.
await page.locator('input.qty').fill('2');
await page.locator(`input[name="opf[${gid}][ticket][1]"]`).waitFor();
check('quantity-repeat cloned a row for qty=2', true);
await page.locator(`input[name="opf[${gid}][ticket][0]"]`).fill('Ada');
await page.locator(`input[name="opf[${gid}][ticket][1]"]`).fill('Grace');
await page.screenshot({ path: `${dir}/repeat-form.png`, fullPage: true });
// Submit the real product form (classic POST add-to-cart).
await Promise.all([
	page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
	page.locator('button.single_add_to_cart_button').click(),
]);
await page.waitForTimeout(400);
{
	const { data } = await storeCart();
	const ours = data.items.filter(i => i.id === state.repeat_pid || (i.name || '').includes('IX Repeat'));
	check('cart has 2 split unit lines after browser submit', ours.length === 2, ours.map(i => i.quantity));
	const meta = (ours[0]?.item_data || []).map(d => d.name);
	check('cart meta shows numbered clone labels', meta.includes('Guest 2') && meta.includes('Extra 2 - Seat'), meta);
	const prices = ours.map(i => parseFloat(i.prices.price) / 100);
	check('unit line prices equal 34.00', prices.every(p => Math.abs(p - 34) < 0.01), prices);
	await page.screenshot({ path: `${dir}/repeat-cart.png`, fullPage: true });
}
// Real classic checkout (bacs) → order meta verified server-side afterwards.
await page.goto(`${base}/opf-ix-checkout/`, { waitUntil: 'domcontentloaded' });
const billing = {
	'#billing_first_name': 'Ix', '#billing_last_name': 'Customer',
	'#billing_address_1': '1 Fixture St', '#billing_city': 'Fixtureville',
	'#billing_postcode': '10001', '#billing_email': 'ix-customer@example.invalid',
	'#billing_phone': '5550001',
};
for (const [sel, val] of Object.entries(billing)) {
	if (await page.locator(sel).count()) await page.locator(sel).fill(val);
}
const country = page.locator('#billing_country');
if (await country.count() && await country.inputValue().catch(() => '') === '') {
	await country.selectOption('US').catch(() => {});
}
const stateSel = page.locator('#billing_state');
if (await stateSel.count() && await stateSel.evaluate(n => n.tagName === 'SELECT').catch(() => false)) {
	await stateSel.selectOption({ label: 'California' }).catch(() => {});
} else if (await stateSel.count()) {
	await stateSel.fill('CA');
}
const bacs = page.locator('#payment_method_bacs');
if (await bacs.count()) await bacs.check();
await page.locator('#place_order').click();
await page.waitForURL(u => /order-received/.test(u.href), { timeout: 20000 }).catch(() => {});
const received = /order-received\/(\d+)/.exec(page.url());
check('classic checkout placed order', !!received, page.url());
if (received) {
	state.order_id = parseInt(received[1], 10);
	fs.writeFileSync('/tmp/opf-interact-e2e/browser-order.json', JSON.stringify({ order_id: state.order_id }));
	await page.screenshot({ path: `${dir}/repeat-order-received.png`, fullPage: true });
}

/* ---------------- IMAGE-CHANGE (linked-product gallery swap) ---------------- */
await emptyCart();
await page.goto(`${base}/product/opf-ix-swap/`, { waitUntil: 'domcontentloaded' });
const mainImg = page.locator('.woocommerce-product-gallery__image img, .wp-post-image').first();
await mainImg.waitFor();
const origSrc = await mainImg.getAttribute('src');
check('gallery baseline is fixture main image', /opf-ix-main/.test(origSrc || ''), origSrc);
const choiceA = page.locator(`input[name="opf[${state.swap_gid}][addon_prod][]"][value="ca"]`);
const choiceB = page.locator(`input[name="opf[${state.swap_gid}][addon_prod][]"][value="cb"]`);
await choiceA.check();
await page.waitForTimeout(300);
const srcA = await mainImg.getAttribute('src');
check('selecting child A swaps main image', /opf-ix-child-a/.test(srcA || ''), srcA);
// First-checked wins in DOM order while multiple boxes are ticked.
await choiceB.check();
await page.waitForTimeout(300);
const srcAB = await mainImg.getAttribute('src');
check('child B added while A stays checked keeps first-in-DOM image', /opf-ix-child-a/.test(srcAB || ''), srcAB);
await choiceA.uncheck();
await page.waitForTimeout(300);
const srcB = await mainImg.getAttribute('src');
check('unchecking A falls through to remaining checked child B', /opf-ix-child-b/.test(srcB || ''), srcB);
await choiceB.uncheck();
await page.waitForTimeout(300);
const srcRestored = await mainImg.getAttribute('src');
check('deselecting all restores original gallery image', /opf-ix-main/.test(srcRestored || ''), srcRestored);
await page.screenshot({ path: `${dir}/image-swap.png`, fullPage: true });

/* ---------------- PRODUCT-VARIABLE ---------------- */
await emptyCart();
await page.goto(`${base}/product/opf-ix-variable/`, { waitUntil: 'domcontentloaded' });
const attrSel = page.locator('select[name="attribute_ixcolor"]');
await attrSel.waitFor();
await attrSel.selectOption('Blue');
await page.waitForTimeout(600);
// Parent-targeted group renders; variation price/sale must show.
const engraving = page.locator(`input[name="opf[${vgid}][engraving]"]`);
check('engraving field present for variation', await engraving.count() === 1);
const shownPrice = await page.locator('.single_variation .price, .woocommerce-variation-price .price').first().innerText().catch(() => '');
check('blue variation shows sale price 12', /12/.test(shownPrice), shownPrice);
await engraving.fill('IX-ENG');
await page.locator(`select[name="opf[${vgid}][wrap]"]`).selectOption('gift');
await Promise.all([
	page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
	page.locator('button.single_add_to_cart_button').click(),
]);
await page.waitForTimeout(400);
{
	const { data } = await storeCart();
	const ours = data.items.filter(i => i.id === state.var_blue || (i.variation || []).some(v => /blue/i.test(v.value || '')));
	check('variation cart line present', ours.length === 1, ours.map(i => i.id));
	const meta = (ours[0]?.item_data || []).map(d => `${d.name}=${d.value}`);
	check('variation cart meta keeps field + attribute', meta.some(m => /Engraving=IX-ENG/.test(m)) && meta.some(m => /Gift wrap/i.test(m)), meta);
	const price = ours[0] ? parseFloat(ours[0].prices.price) / 100 : 0;
	check('variation line priced 15.00 (sale base + wrap)', Math.abs(price - 15) < 0.01, price);
	await page.screenshot({ path: `${dir}/variable-cart.png`, fullPage: true });
}
// Reset: clear variation → price returns to parent range display.
await page.goto(`${base}/product/opf-ix-variable/`, { waitUntil: 'domcontentloaded' });
await attrSel.selectOption('Blue');
await page.waitForTimeout(600);
const resetLink = page.locator('a.reset_variations');
if (await resetLink.count()) {
	await resetLink.click();
	await page.waitForTimeout(300);
	check('clear resets variation selection', await attrSel.inputValue() === '');
}

/* ---------------- CART-EDIT (implemented surface) ---------------- */
// Implemented by the cartedit lane; the fixture enables opf_edit_cart so the
// OPF cart line above must expose its edit link on the block cart.
await page.goto(`${base}/cart/`, { waitUntil: 'networkidle' });
const editLinks = await page.locator('.wc-block-cart a.opf-edit-cartitem, .woocommerce-cart-form a.opf-edit-cartitem').count();
check('OPF cart exposes per-item edit links when enabled', editLinks > 0, editLinks);

fs.writeFileSync(`${dir}/browser-results.json`, JSON.stringify({ checks, pageErrors: errors }, null, 2));
const failed = checks.filter(c => !c.pass);
console.log(`${checks.length} browser checks, ${failed.length} failed, ${errors.length} page errors`);
if (errors.length) console.log('pageErrors:', errors);
await browser.close();
process.exit(failed.length ? 1 : 0);
