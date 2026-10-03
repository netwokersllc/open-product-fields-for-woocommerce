// Real served WAPF templates, admin UI, classic/Store API/checkout and reorder.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8137';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only.');
const dir = process.env.OPF_URL_NATIVE_ARTIFACT_DIR || '/tmp/opf-url-native-artifacts';
fs.mkdirSync(dir, { recursive: true });
const cases = JSON.parse(fs.readFileSync('tests/fixtures/url-native-cases.json'));
const phase = process.env.OPF_URL_NATIVE_PHASE || 'commerce';
const checks = [], observations = [], orders = [], errors = [];
const check = (label, pass) => {
 checks.push({ label, pass: !!pass });
 console.log(`${pass ? 'ok' : 'FAIL'} ${label}`);
 if (!pass) throw new Error(label);
};
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('pageerror', e => errors.push(e.message));
const clearCart = async () => {
 const response = await page.request.get(base + '/wp-json/wc/store/v1/cart');
 const cart = await response.json();
 for (const item of cart.items) {
  const removed = await page.request.post(base + '/wp-json/wc/store/v1/cart/remove-item', { headers: { Nonce: response.headers()['nonce'] }, data: { key: item.key } });
  check('clear disposable cart', removed.ok());
 }
};
try {
 await page.goto(base + '/?opf_url_login=1', { waitUntil: 'domcontentloaded' });
 await page.locator('.opf-b-field').first().waitFor();
 if (phase === 'again') {
  const reorders = JSON.parse(fs.readFileSync(dir + '/order-again-urls.json'));
  for (const order of reorders) {
   await clearCart();
   await page.goto(base + '/my-account/view-order/' + order.order_id + '/', { waitUntil: 'domcontentloaded' });
   const orderAgainLink = page.getByText('Order again', { exact: true });
   await orderAgainLink.waitFor();
   await page.goto(await orderAgainLink.getAttribute('href'), { waitUntil: 'domcontentloaded' });
   const cart = await (await page.request.get(base + '/wp-json/wc/store/v1/cart')).json();
   check('actual Woo order-again route adds one item ' + order.value, cart.items.length === 1);
   check('actual order-again API preserves exact URL ' + order.value,
    cart.items[0].item_data.some(row => row.name === 'Profile URL' && row.value === order.value.replace(/&/g, '&amp;')));
   orders.push({ ...order, cart: cart.items[0].item_data });
  }
  await clearCart();
 } else {
  for (const [value, nativeExpected, accepted] of cases) {
   await clearCart();
   if (accepted) {
    await page.goto(base + '/?opf_url_login=1', { waitUntil: 'domcontentloaded' });
    const defaultInput = page.locator('.opf-b-field').nth(1).getByRole('textbox', { name: 'Default value', exact: true });
    await defaultInput.fill('  ' + value + '  ');
    const response = page.waitForResponse(r => r.url().includes('/opf/v1/groups') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    const saved = await response;
    check('admin REST saves native default ' + value, saved.ok() && (await saved.json()).data.fields[1].default === value);
    await page.getByText('Saved.', { exact: true }).waitFor();
    await page.reload({ waitUntil: 'domcontentloaded' });
    check('admin reload preserves exact native default ' + value, await defaultInput.inputValue() === value);
   }
   await page.goto(base + '/product/opf-url-lifecycle/', { waitUntil: 'domcontentloaded' });
   const opf = page.locator('[data-opf-field="message"] input[type="url"]');
   const free = page.locator('#wapf-url-contract');
   const extended = page.locator('#wapf-extended-url-contract');
   await opf.fill(value); await free.fill(value); await extended.fill(value);
   const native = await Promise.all([opf, free, extended].map(l => l.evaluate(n => n.validity.valid)));
   check('served OPF/Free/Extended native validity ' + value, native.every(v => v === nativeExpected));
   if (accepted) check('storefront renders exact saved default ' + value, await page.locator('[data-opf-field="prefill"] input').inputValue() === value);
   const requiredName = await opf.getAttribute('name');
   const group = requiredName.match(/^opf\[([^\]]+)\]/)[1];
   const id = Number(await page.locator('button.single_add_to_cart_button').getAttribute('value'));
   check('native URL has an accessible associated label', await page.getByRole('textbox', { name: /^Profile URL/ }).count() === 1);
   await opf.focus(); await page.keyboard.press('Tab');
   check('keyboard moves to default URL input', await page.locator('[data-opf-field="prefill"] input').evaluate(n => n === document.activeElement));
   const classic = await page.request.post(base + '/product/opf-url-lifecycle/', { form: { 'add-to-cart': String(id), quantity: '1', [requiredName]: '  ' + value + '  ' } });
   const classicRejected = (await classic.text()).includes('must be a valid URL');
   const classicCart = await (await page.request.get(base + '/wp-json/wc/store/v1/cart')).json();
   check('actual classic HTTP acceptance ' + value, (classicCart.items.length === 1) === accepted && classicRejected === !accepted);
   await clearCart();
   const cartResponse = await page.request.get(base + '/wp-json/wc/store/v1/cart');
   const store = await page.request.post(base + '/wp-json/wc/store/v1/cart/add-item', { headers: { Nonce: cartResponse.headers()['nonce'] }, data: { id, quantity: 1, opf_fields: { [group]: { message: '  ' + value + '  ' } } } });
   const storeBody = await store.json();
   check('actual Store API HTTP acceptance ' + value, store.ok() === accepted && (accepted || JSON.stringify(storeBody).includes('valid URL')));
   if (accepted) {
    await page.goto(base + '/cart/', { waitUntil: 'domcontentloaded' });
    const cartData = await (await page.request.get(base + '/wp-json/wc/store/v1/cart')).json();
    check('actual Store API cart preserves trimmed native value ' + value,
     cartData.items.length === 1 && cartData.items[0].item_data.some(row => row.name === 'Profile URL' && row.value === value.replace(/&/g, '&amp;')));
    const cr = await page.request.get(base + '/wp-json/wc/store/v1/cart');
    const checkout = await page.request.post(base + '/wp-json/wc/store/v1/checkout', {
     headers: { Nonce: cr.headers()['nonce'] }, data: {
      payment_method: 'bacs', billing_address: { first_name: 'URL', last_name: 'Parity', email: 'url@example.invalid', address_1: '1 Test Street', city: 'Testville', postcode: '90210', country: 'US', state: 'CA' }
     }
    });
    const placed = await checkout.json();
    check('actual Store API checkout persists native value ' + value, checkout.ok() && placed.order_id > 0);
    orders.push({ value, group, order_id: placed.order_id });
    check('checkout route returns real order confirmation', placed.payment_result?.redirect_url?.includes('order-received'));
    await page.goto(placed.payment_result.redirect_url, { waitUntil: 'domcontentloaded' });
    check('order confirmation renders readable native URL ' + value, (await page.textContent('body')).includes(decodeURI(value)));
   }
   observations.push({ value, nativeExpected, accepted, native, classicAccepted: classicCart.items.length === 1, storeAccepted: store.ok(), storeStatus: store.status() });
  }
  await page.goto(base + '/product/opf-url-lifecycle/', { waitUntil: 'domcontentloaded' });
  const field = page.locator('[data-opf-field="message"] input');
  await field.fill('https://例え.テスト/こんにちは?q=✓');
  await page.screenshot({ path: dir + '/native-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  const bounds = await field.boundingBox();
  check('native input stays within mobile viewport', bounds.x >= 0 && bounds.x + bounds.width <= 390);
  await page.screenshot({ path: dir + '/native-mobile.png', fullPage: true });
 }
 check('no uncaught browser errors', errors.length === 0);
 console.log(JSON.stringify({ phase, checks: checks.length, cases: observations.length, orders: orders.length, browser: browser.version() }));
} finally {
 fs.writeFileSync(dir + '/native-' + phase + '-results.json', JSON.stringify({ base, phase, checks, observations, orders, errors, browser: browser.version() }, null, 2));
 if (phase === 'commerce') fs.writeFileSync(dir + '/native-orders.json', JSON.stringify(orders, null, 2));
 await browser.close();
}
