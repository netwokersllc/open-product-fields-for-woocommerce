// Actual browser/admin/classic + Store API checkout and Woo order-again proof.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const { chromium } = createRequire(process.cwd() + '/index.js')('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8193';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only');
const dir = '/tmp/opf-toggle-artifacts';
fs.mkdirSync(dir, { recursive: true });
const fixture = JSON.parse(fs.readFileSync('/tmp/opf-toggle-state.json', 'utf8'));
const checks = [], errors = [], orders = {};
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); if (!pass) throw new Error(label); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('pageerror', e => errors.push(e.message));
const cartURL = base + '/wp-json/wc/store/v1/cart';
const cart = async () => await page.request.get(cartURL);
async function empty() {
  const response = await cart();
  for (const item of (await response.json()).items) await page.request.delete(cartURL + '/items/' + item.key, { headers: { Nonce: response.headers().nonce } });
}
const toggle = id => page.locator(`[data-opf-field="${id}"] input[type=checkbox]`);
try {
  await page.goto(base + '/?opf_toggle_login=1', { waitUntil: 'domcontentloaded' });
  if (process.env.OPF_TOGGLE_ORDER_AGAIN === '1') {
    const previous = JSON.parse(fs.readFileSync(dir + '/browser-orders.json', 'utf8'));
    for (const [kind, id] of Object.entries(previous)) {
      await empty();
      await page.goto(base + '/my-account/view-order/' + id + '/', { waitUntil: 'domcontentloaded' });
      check(kind + ' actual order-again action exists', await page.getByRole('link', { name: 'Order again', exact: true }).count() === 1);
      await page.getByRole('link', { name: 'Order again', exact: true }).click();
      await page.waitForURL(/\/cart\//);
      const data = await (await cart()).json(), checked = kind.endsWith('1');
      check(kind + ' order-again real cart preserves boolean', data.items.length === 1 && data.items[0].item_data.find(r => r.name === 'Gift wrap')?.value === (checked ? 'Yes' : 'No'));
      check(kind + ' order-again real cart retains price', data.items[0].prices.price === (checked ? '1200' : '1000'));
    }
  } else {
    const first = page.locator('.opf-b-field').first();
    await first.waitFor();
    check('admin exposes toggle type', await first.locator('select').first().inputValue() === 'toggle');
    await first.locator('.opf-b-label').fill('Accept terms edited');
    await first.locator('input[title="Required"]').check();
    await first.getByLabel('Checkbox message', { exact: true }).fill('Accept & continue');
    await first.getByLabel('Default value', { exact: true }).selectOption('1');
    const falseCondition = page.locator('.opf-b-field').nth(3).getByLabel('Condition value', { exact: true });
    await falseCondition.selectOption('0');
    const saving = page.waitForResponse(r => r.url().includes('/opf/v1/groups') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    check('admin real REST save succeeds', (await saving).ok());
    await page.getByText('Saved.', { exact: true }).waitFor();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await first.waitFor();
    check('admin reload retains label type required message default', await first.locator('select').first().inputValue() === 'toggle' && await first.locator('.opf-b-label').inputValue() === 'Accept terms edited' && await first.locator('input[title="Required"]').isChecked() && await first.getByLabel('Checkbox message', { exact: true }).inputValue() === 'Accept & continue' && await first.getByLabel('Default value', { exact: true }).inputValue() === '1');
    check('admin reload retains Not checked zero condition', await falseCondition.inputValue() === '0');
    await page.screenshot({ path: dir + '/admin.png', fullPage: true });
    await page.goto(base + '/product/opf-toggle-lifecycle/', { waitUntil: 'domcontentloaded' });
    await toggle('accept').waitFor();
    check('native checkbox has accessible field and message labels', await toggle('accept').evaluate(n => [...n.labels].map(l => l.textContent).join(' ').includes('Accept terms edited') && [...n.labels].some(l => l.textContent === 'Accept & continue')));
    check('required and checked defaults render', await toggle('accept').isChecked() && await toggle('accept').getAttribute('required') !== null && await toggle('preset').isChecked());
    check('optional unchecked is native and optional', !(await toggle('wrap').isChecked()) && await toggle('wrap').getAttribute('required') === null);
    check('false dependent initially visible and true dependent hidden disabled', await toggle('false_only').isVisible() && !(await toggle('true_only').isVisible()) && await toggle('true_only').isDisabled());
    await toggle('wrap').focus();
    await page.keyboard.press('Space');
    check('Space toggles native checkbox and updates dependent state', await toggle('wrap').isChecked() && await toggle('true_only').isVisible() && !(await toggle('false_only').isVisible()));
    await page.keyboard.press('Space');
    check('Space restores false condition', !(await toggle('wrap').isChecked()) && await toggle('false_only').isVisible());
    const snapshot = await page.locator('form.cart').ariaSnapshot();
    fs.writeFileSync(dir + '/accessibility.txt', snapshot);
    check('accessibility tree exposes native checkboxes and checked state', snapshot.includes('checkbox "Accept terms edited') && snapshot.includes('[checked]') && snapshot.includes('checkbox "Gift wrap"'));
    const values = await page.locator('form.cart').evaluate(form => [...new FormData(form)].filter(([n]) => n.startsWith('opf[')));
    check('native form carries hidden zero before checked one and unchecked zero', values.filter(([n]) => n.endsWith('[accept]')).map(([,v]) => v).join(',') === '0,1' && values.filter(([n]) => n.endsWith('[wrap]')).map(([,v]) => v).join(',') === '0');
    await toggle('accept').uncheck();
    let posts = 0;
    page.on('request', r => { if (r.method() === 'POST' && r.url().includes('/product/opf-toggle-lifecycle/')) posts++; });
    await page.locator('button.single_add_to_cart_button').click();
    check('native required unchecked blocks submit', posts === 0 && await toggle('accept').evaluate(n => n.validity.valueMissing));
    await empty();
    const name = await toggle('accept').getAttribute('name');
    const reject = await page.request.post(base + '/product/opf-toggle-lifecycle/', { form: { 'add-to-cart': String(fixture.product), quantity: '1', [name]: '0' } });
    check('forged classic HTTP rejects required false', (await reject.text()).includes('is a required field'));
    const response = await cart();
    const rejected = await page.request.post(cartURL + '/add-item', { headers: { Nonce: response.headers().nonce }, data: { id: fixture.product, quantity: 1, opf_fields: { [fixture.group]: { accept: '0' } } } });
    check('forged Store API HTTP rejects required false', rejected.status() >= 400 && (await rejected.text()).includes('is a required field'));
    check('rejected HTTP requests leave empty cart', (await (await cart()).json()).items.length === 0);
    await toggle('accept').check();
    await page.setViewportSize({ width: 390, height: 844 });
    check('mobile controls and message fit viewport', await toggle('accept').evaluate(n => { const r = n.closest('[data-opf-field]').getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; }));
    await page.screenshot({ path: dir + '/mobile.png', fullPage: true });
    await page.setViewportSize({ width: 1280, height: 960 });
    for (const kind of ['classic', 'blocks']) for (const value of ['0', '1']) {
      await empty();
      await page.goto(base + '/product/opf-toggle-lifecycle/', { waitUntil: 'domcontentloaded' });
      if (kind === 'classic') {
        await toggle('wrap').setChecked(value === '1');
        await toggle(value === '1' ? 'true_only' : 'false_only').check();
        const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
        await page.locator('button.single_add_to_cart_button').click();
        await navigation;
      } else {
        const response = await cart();
        const result = await page.request.post(cartURL + '/add-item', { headers: { Nonce: response.headers().nonce }, data: { id: fixture.product, quantity: 1, opf_fields: { [fixture.group]: { accept: '1', wrap: value, preset: '1', [value === '1' ? 'true_only' : 'false_only']: '1', [value === '1' ? 'false_only' : 'true_only']: '0' } } } });
        check('actual Store API add-item ' + value + ' succeeds', result.ok());
      }
      const data = await (await cart()).json();
      check(kind + value + ' real cart exact boolean and checked-only price', data.items[0].item_data.find(r => r.name === 'Gift wrap')?.value === (value === '1' ? 'Yes' : 'No') && data.items[0].prices.price === (value === '1' ? '1200' : '1000'));
      await page.goto(base + (kind === 'classic' ? '/toggle-classic-checkout/' : '/checkout/'), { waitUntil: 'domcontentloaded' });
      if (kind === 'classic') {
        await page.locator('#billing_first_name').fill('Toggle');
        await page.locator('#billing_last_name').fill('Buyer');
        await page.locator('#billing_country').selectOption('US');
        await page.locator('#billing_address_1').fill('1 Test Street');
        await page.locator('#billing_city').fill('Testville');
        await page.locator('#billing_state').selectOption('CA');
        await page.locator('#billing_postcode').fill('90210');
        await page.locator('#billing_email').fill('toggle@example.invalid');
        await page.locator('#payment_method_bacs').check();
        const response = page.waitForResponse(r => r.url().includes('wc-ajax=checkout') && r.request().method() === 'POST').then(r => r.ok());
        await page.locator('#place_order').click();
        check(kind + value + ' actual classic checkout HTTP succeeds', await response);
        await page.waitForURL(/order-received/, { timeout: 30000 });
        orders[kind + value] = Number(page.url().match(/order-received\/(\d+)/)[1]);
      } else {
        await page.getByLabel(/^email address$/i).first().fill('toggle@example.invalid');
        if (await page.getByLabel(/^first name$/i).first().isVisible()) {
          await page.getByLabel(/^first name$/i).first().fill('Toggle');
          await page.getByLabel(/^last name$/i).first().fill('Buyer');
          await page.getByLabel(/^address$/i).first().fill('1 Test Street');
          await page.getByLabel(/^city$/i).first().fill('Testville');
          await page.getByLabel(/^state$/i).first().selectOption('CA');
          await page.getByLabel(/^zip code$/i).first().fill('90210');
        }
        const response = page.waitForResponse(r => r.url().includes('/wc/store/v1/checkout') && r.request().method() === 'POST').then(async r => ({ ok: r.ok(), data: await r.json() }));
        await page.getByRole('button', { name: /place order/i }).click();
        const result = await response;
        check(kind + value + ' actual Checkout Block Store API checkout succeeds', result.ok);
        orders[kind + value] = result.data.order_id;
        await page.waitForURL(/order-received/, { timeout: 30000 });
      }
      check(kind + value + ' confirmation displays native boolean', (await page.textContent('body')).includes('Gift wrap') && (await page.textContent('body')).includes(value === '1' ? 'Yes' : 'No'));
      await page.screenshot({ path: dir + '/' + kind + value + '-order.png', fullPage: true });
    }
  }
  check('no uncaught browser errors', errors.length === 0);
} finally {
  const again = process.env.OPF_TOGGLE_ORDER_AGAIN === '1';
  if (!again) fs.writeFileSync(dir + '/browser-orders.json', JSON.stringify(orders, null, 2));
  fs.writeFileSync(dir + (again ? '/order-again-results.json' : '/browser-results.json'), JSON.stringify({ base, time: new Date().toISOString(), checks, errors }, null, 2));
  await browser.close();
}
