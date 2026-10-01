// Audit real native WAPF/OPF URL validity against actual classic/Store API HTTP.
// Requires disposable url-compatibility-probe.php described in the evidence.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8126';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only.');
const artifactDir = '/tmp/opf-url-compatibility-artifacts';
fs.mkdirSync(artifactDir, { recursive: true });
// Last two booleans: observed native validity and existing OPF acceptance.
const cases = [
 ['https://example.invalid/?a=1&b=2', true, true],
 ['https://例え.テスト/こんにちは?q=✓', true, false],
 ['https://example.invalid/café?q=é', true, false],
 ['https://xn--r8jz45g.xn--zckzah/%E3%81%93?q=%E2%9C%93', true, true],
 ['https://example.invalid/a b', true, false],
 ['customsafe://example.invalid/path', true, false],
 ['mailto:é@example.invalid', true, false],
 ['tel:+123', true, false],
 ['urn:isbn:978123', true, false],
 ['http:example.invalid', true, false],
 ['mailto:', true, true],
 ['javascript:alert(1)', true, false],
 ['data:text/html,x', true, false],
 ['https://example.invalid/<script>', true, false],
 ['http://256.256.256.256', false, true],
 ['http://[no]/', false, false],
 ['https://example.invalid:99999/', false, false],
 ['https://', false, false],
 ['not-a-url', false, false],
];
const checks = [];
const observations = [];
const check = (label, pass) => {
 checks.push({ label, pass: !!pass });
 if (!pass) throw new Error(label);
};
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
try {
 await page.goto(base + '/product/opf-url-lifecycle/', { waitUntil: 'domcontentloaded' });
 const opf = page.locator('[data-opf-field="message"] input[type="url"]');
 const wapf = page.locator('#wapf-url-contract[type="url"]');
 await wapf.waitFor();
 const requiredName = await opf.getAttribute('name');
 const group = requiredName.match(/^opf\[([^\]]+)\]/)[1];
 const id = Number(await page.locator('button.single_add_to_cart_button').getAttribute('value'));
 const clearCart = async () => {
  const cartResponse = await page.request.get(base + '/wp-json/wc/store/v1/cart');
  const cart = await cartResponse.json();
  for (const item of cart.items) {
   const removed = await page.request.post(base + '/wp-json/wc/store/v1/cart/remove-item', { headers: { Nonce: cartResponse.headers()['nonce'] }, data: { key: item.key } });
   check('clear disposable cart item', removed.ok());
  }
 };
 for (const [value, nativeExpected, opfExpected] of cases) {
  await clearCart();
  await opf.fill(value);
  await wapf.fill(value);
  const opfNative = await opf.evaluate(n => n.validity.valid);
  const wapfNative = await wapf.evaluate(n => n.validity.valid);
  check('served native WAPF/OPF validity ' + value, opfNative === nativeExpected && wapfNative === nativeExpected);
  const classic = await page.request.post(base + '/product/opf-url-lifecycle/', { form: { 'add-to-cart': String(id), quantity: '1', [requiredName]: value } });
  const classicRejected = (await classic.text()).includes('must be a valid URL');
  const classicCart = await (await page.request.get(base + '/wp-json/wc/store/v1/cart')).json();
  const classicAccepted = classicCart.items.length === 1;
  check('observed classic acceptance ' + value, classicAccepted === opfExpected && classicRejected === !opfExpected);
  await clearCart();
  const cartResponse = await page.request.get(base + '/wp-json/wc/store/v1/cart');
  const store = await page.request.post(base + '/wp-json/wc/store/v1/cart/add-item', { headers: { Nonce: cartResponse.headers()['nonce'] }, data: { id, quantity: 1, opf_fields: { [group]: { message: value } } } });
  const storeBody = await store.json();
  check('observed Store API acceptance ' + value, store.ok() === opfExpected && (opfExpected || JSON.stringify(storeBody).includes('valid URL')));
  observations.push({ value, wapfNative, opfNative, classicAccepted, storeAccepted: store.ok(), storeStatus: store.status() });
 }
 await clearCart();
 await page.screenshot({ path: artifactDir + '/served-wapf-opf-inputs.png', fullPage: true });
 check('no browser page errors', errors.length === 0);
 console.log(JSON.stringify({ checks: checks.length, cases: observations.length, observedDivergences: observations.filter(o => o.wapfNative !== o.storeAccepted).length }));
} finally {
 fs.writeFileSync(artifactDir + '/browser-results.json', JSON.stringify({ base, checks, observations, errors }, null, 2));
 await browser.close();
}
