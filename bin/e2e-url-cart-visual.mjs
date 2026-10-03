// Follow native form submission and visible classic/block carts in one browser context.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire(process.env.OPF_BROWSER_DEPENDENCIES || process.cwd() + '/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8251';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only.');
const out = process.env.OPF_URL_CART_OUT || '/tmp/opf-url-cart-artifacts';
const state = JSON.parse(fs.readFileSync(out + '/state.json'));
const engine = process.env.OPF_URL_CART_ENGINE || 'opf';
const submitMode = process.env.OPF_URL_CART_SUBMIT || 'form';
const authenticated = process.env.OPF_URL_CART_AUTH === '1';
const run = engine + '-' + submitMode + (authenticated ? '-authenticated' : '-guest');
const browser = await chromium.launch();
const errors = [], network = [], checks = [], observations = [];
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); };
const values = ['https://例え.テスト/こんにちは?q=✓', 'https://example.invalid/profile?a=1&b=2', 'mailto:ada@example.invalid', 'https:example.invalid'];
try {
 for (const [index, value] of values.entries()) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 960 } });
  const product = await context.newPage();
  product.on('pageerror', error => errors.push(error.message));
  if (authenticated) await product.goto(base + '/?opf_url_cart_login=1', { waitUntil: 'networkidle' });
  await product.goto(base + '/product/url-cart-visual/', { waitUntil: 'networkidle' });
  const input = product.locator('form.cart input[type=url]').first();
  await input.fill(value);
  check(engine + ' native input accepts ' + value, await input.evaluate(node => node.validity.valid));
  let requestNames, submitStatus;
  if (submitMode === 'store') {
   const initial = await context.request.get(base + '/wp-json/wc/store/v1/cart');
   const added = await context.request.post(base + '/wp-json/wc/store/v1/cart/add-item', { headers: { Nonce: initial.headers().nonce }, data: { id: state.product, quantity: 1, opf_fields: { [state.group]: { profile: value } } } });
   submitStatus = added.status();
   requestNames = ['id', 'quantity', 'opf_fields'];
   check(run + ' direct Store API accepts value ' + value, added.ok());
  } else {
   const submission = product.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/product/url-cart-visual/'));
   await product.locator('button.single_add_to_cart_button').click();
   const submitted = await submission;
   submitStatus = submitted.status();
   await product.waitForLoadState('networkidle');
   requestNames = Array.from(new URLSearchParams(submitted.request().postData()).keys());
  }
  const api = await context.request.get(base + '/wp-json/wc/store/v1/cart');
  const cart = await api.json();
  check(engine + ' real form creates one API cart item ' + value, cart.items?.length === 1);
  // Reuse exactly the same context as the product form.
  const cartPage = await context.newPage();
  cartPage.on('pageerror', error => errors.push(error.message));
  cartPage.on('response', response => { if (response.status() >= 400) network.push({ status: response.status(), url: response.url() }); });
  await cartPage.goto(base + '/url-classic-cart/', { waitUntil: 'networkidle' });
  const classicBody = await cartPage.locator('body').innerText();
  check(engine + ' classic cart visibly has Profile URL ' + value, classicBody.includes('Profile URL'));
  if (index === 0) await cartPage.screenshot({ path: out + '/' + run + '-classic.png', fullPage: true });
  await cartPage.goto(base + '/cart/', { waitUntil: 'domcontentloaded' });
  const rowsAtDOMContentLoaded = await cartPage.locator('.wc-block-cart-items__row').count();
  await cartPage.waitForLoadState('networkidle');
  await cartPage.waitForTimeout(2000);
  const blockBody = await cartPage.locator('body').innerText();
  const blockRows = await cartPage.locator('.wc-block-cart-items__row').count();
  check(engine + ' block cart visibly has URL label ' + value, blockBody.includes('Profile URL') && blockRows === 1);
  const block = { body: blockBody, rows: blockRows, html: await cartPage.locator('.wp-block-woocommerce-cart').evaluateAll(nodes => nodes.map(n => n.outerHTML)), scripts: await cartPage.locator('script[src]').evaluateAll(nodes => nodes.map(n => n.src)) };
  if (index === 0) await cartPage.screenshot({ path: out + '/' + run + '-block.png', fullPage: true });
  observations.push({ value, requestNames, submitStatus, apiStatus: api.status(), cart: cart.items?.map(item => ({ id: item.id, item_data: item.item_data, extensions: item.extensions })), classicBody, rowsAtDOMContentLoaded, block });
  await context.close();
 }
} finally {
 fs.writeFileSync(out + '/' + run + '-results.json', JSON.stringify({ base, engine, submitMode, authenticated, browser: browser.version(), checks, observations, errors, network }, null, 2));
 await browser.close();
}
if (checks.some(check => !check.pass)) process.exitCode = 1;
