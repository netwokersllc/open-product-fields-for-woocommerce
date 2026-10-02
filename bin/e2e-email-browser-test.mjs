// Actual Chromium + WordPress/WooCommerce. No mocked requests or synthetic HTML.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8181';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only');
const dir = '/tmp/opf-email-artifacts';
fs.mkdirSync(dir, { recursive: true });
const fixture = JSON.parse(fs.readFileSync('/tmp/opf-email-state.json', 'utf8'));
const checks = [], errors = [], orders = {};
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); if (!pass) throw new Error(label); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('pageerror', e => errors.push(e.message));
const cartURL = base + '/wp-json/wc/store/v1/cart';
async function cart() { return await page.request.get(cartURL); }
async function empty() {
  const response = await cart(), data = await response.json();
  for (const item of data.items) await page.request.delete(cartURL + '/items/' + item.key, { headers: { Nonce: response.headers().nonce } });
}
try {
  await page.goto(base + '/?opf_email_login=1', { waitUntil: 'domcontentloaded' });
  const first = page.locator('.opf-b-field').first();
  await first.waitFor();
  check('admin exposes email type', await first.locator('select').inputValue() === 'email');
  await first.locator('.opf-b-label').fill('Contact email edited');
  await first.locator('input[title="Required"]').check();
  const saving = page.waitForResponse(r => r.url().includes('/opf/v1/groups') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  check('admin actual REST save succeeds', (await saving).ok());
  await page.getByText('Saved.', { exact: true }).waitFor();
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.locator('.opf-b-field').first().waitFor();
  check('admin reload persists email label/type/required', await page.locator('.opf-b-field').first().locator('select').inputValue() === 'email' && await page.locator('.opf-b-field').first().locator('.opf-b-label').inputValue() === 'Contact email edited' && await page.locator('.opf-b-field').first().locator('input[title="Required"]').isChecked());
  await page.screenshot({ path: dir + '/admin.png', fullPage: true });
  await page.goto(base + '/product/opf-email-lifecycle/', { waitUntil: 'domcontentloaded' });
  const input = page.locator('[data-opf-field="contact"] input[type=email]');
  await input.waitFor();
  check('native email has required semantics and accessible label', await page.getByLabel('Contact email edited', { exact: false }).count() === 1 && await input.getAttribute('required') !== null);
  check('optional native email is optional', await page.locator('[data-opf-field="optional"] input[type=email]').getAttribute('required') === null);
  await input.focus();
  check('email input receives keyboard focus', await input.evaluate(n => document.activeElement === n));
  for (const value of ['a@localhost', 'a..b@example.test', '.a@example.test', 'a.@example.test', 'a+b@example.test', 'a&b@example.test', 'a@xn--bcher-kva.test']) {
    await input.fill(value);
    check('native browser accepts ' + value, await input.evaluate(n => n.validity.valid));
  }
  await input.fill('a@bücher.test');
  check('native browser canonicalizes internationalized domain to accepted ASCII transport', await input.inputValue() === 'a@xn--bcher-kva.test' && await input.evaluate(n => n.validity.valid));
  for (const value of ['not-email', '"a"@example.test', 'a@-example.test', 'a@example-.test', 'a@ex_ample.test', 'a<b>@example.test']) {
    await input.fill(value);
    check('native browser rejects ' + value, await input.evaluate(n => !n.validity.valid));
  }
  let productPosts = 0;
  page.on('request', r => { if (r.method() === 'POST' && r.url().includes('/product/opf-email-lifecycle/')) productPosts++; });
  await input.fill('not-email');
  await page.locator('button.single_add_to_cart_button').click();
  check('invalid email browser submit is blocked', productPosts === 0);
  const name = await input.getAttribute('name');
  await empty();
  for (const value of ['not-email', '"a"@example.test', 'a<b>@example.test']) {
    const rejected = await page.request.post(base + '/product/opf-email-lifecycle/', { form: { 'add-to-cart': String(fixture.product), quantity: '1', [name]: value } });
    check('forged classic HTTP rejects ' + value, (await rejected.text()).includes('must be a valid email address'));
  }
  const cartResponse = await cart(), nonce = cartResponse.headers().nonce;
  for (const value of ['not-email', '"a"@example.test', 'a<b>@example.test', ['a@example.test'], { address: 'a@example.test' }]) {
    const rejected = await page.request.post(cartURL + '/add-item', { headers: { Nonce: nonce }, data: { id: fixture.product, quantity: 1, opf_fields: { [fixture.group]: { contact: 'valid@example.test', optional: value } } } });
    check('forged Store API optional email rejects ' + JSON.stringify(value), rejected.status() >= 400 && (await rejected.text()).includes('must be a valid email address'));
  }
  check('all rejected HTTP requests leave cart empty', (await (await cart()).json()).items.length === 0);
  await page.setViewportSize({ width: 390, height: 844 });
  await input.fill('a&b@example.test');
  check('mobile email fits viewport', await input.evaluate(n => { const b = n.getBoundingClientRect(); return b.left >= 0 && b.right <= innerWidth; }));
  await page.screenshot({ path: dir + '/mobile.png', fullPage: true });
  await page.setViewportSize({ width: 1280, height: 960 });
  for (const kind of ['classic', 'blocks']) {
    await empty();
    await page.goto(base + '/product/opf-email-lifecycle/', { waitUntil: 'domcontentloaded' });
    await page.locator('[data-opf-field="contact"] input').fill('a&b@example.test');
    const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
    await page.locator('button.single_add_to_cart_button').click();
    await navigation;
    const data = await (await cart()).json();
    check(kind + ' cart exact email and unchanged price', data.items[0].item_data.find(r => r.name === 'Contact email edited')?.value === 'a&amp;b@example.test' && data.items[0].prices.price === '1000');
    await page.goto(base + (kind === 'classic' ? '/email-classic-checkout/' : '/checkout/'), { waitUntil: 'domcontentloaded' });
    if (kind === 'classic') {
      await page.locator('#billing_first_name').fill('Email');
      await page.locator('#billing_last_name').fill('Buyer');
      await page.locator('#billing_country').selectOption('US');
      await page.locator('#billing_address_1').fill('1 Test Street');
      await page.locator('#billing_city').fill('Testville');
      await page.locator('#billing_state').selectOption('CA');
      await page.locator('#billing_postcode').fill('90210');
      await page.locator('#billing_email').fill('email@example.invalid');
      await page.locator('#payment_method_bacs').check();
      const resultPromise = page.waitForResponse(r => r.url().includes('wc-ajax=checkout') && r.request().method() === 'POST').then(r => r.ok());
      await page.locator('#place_order').click();
      const result = await resultPromise;
      check('classic HTTP checkout returns success', result);
      await page.waitForURL(/order-received/, { timeout: 30000 });
      orders.classic = Number(page.url().match(/order-received\/(\d+)/)[1]);
    } else {
      await page.getByLabel(/^email address$/i).first().fill('email@example.invalid');
      if (await page.getByLabel(/^first name$/i).first().isVisible()) {
        await page.getByLabel(/^first name$/i).first().fill('Email');
        await page.getByLabel(/^last name$/i).first().fill('Buyer');
        await page.getByLabel(/^address$/i).first().fill('1 Test Street');
        await page.getByLabel(/^city$/i).first().fill('Testville');
        await page.getByLabel(/^state$/i).first().selectOption('CA');
        await page.getByLabel(/^zip code$/i).first().fill('90210');
      }
      const resultPromise = page.waitForResponse(r => r.url().includes('/wc/store/v1/checkout') && r.request().method() === 'POST');
      await page.getByRole('button', { name: /place order/i }).click();
      const response = await resultPromise;
      check('actual block Store API checkout succeeds', response.ok());
      orders.blocks = (await response.json()).order_id;
      await page.waitForURL(/order-received/, { timeout: 30000 });
    }
    check(kind + ' confirmation displays exact email with escaped DOM', (await page.textContent('body')).includes('a&b@example.test') && (await page.content()).includes('a&amp;b@example.test'));
    await page.screenshot({ path: dir + '/' + kind + '-order.png', fullPage: true });
  }
  check('no uncaught browser errors', errors.length === 0);
} finally {
  fs.writeFileSync(dir + '/browser-orders.json', JSON.stringify(orders, null, 2));
  fs.writeFileSync(dir + '/browser-results.json', JSON.stringify({ base, time: new Date().toISOString(), checks, errors }, null, 2));
  await browser.close();
}
