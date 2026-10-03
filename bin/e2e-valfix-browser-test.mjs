// Real Chromium proof for the valfix lanes: rendered text-length/regex attrs,
// checkbox selection limits, client max guard, and hidden forged-value handling.
//
// OPF_MODE=opf|wapf OPF_BASE_URL=http://127.0.0.1:8325 \
//   node bin/e2e-valfix-browser-test.mjs
//
// OPF mode targets product /product/opf-fieldb-lifecycle/ (group 15989).
// WAPF mode targets /product/opf-fieldb-wapf-ref/ (installed Extended 3.1.5).
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const requireFromCwd = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromCwd('playwright');

const mode = process.env.OPF_MODE || 'opf';
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8325';
const baseUrl = new URL(base);
if (!['127.0.0.1', 'localhost'].includes(baseUrl.hostname)) {
  throw new Error('Browser base URL must use localhost');
}
const product = mode === 'wapf' ? '/product/opf-fieldb-wapf-ref/' : '/product/opf-fieldb-lifecycle/';
const groupId = '15989';
const productId = mode === 'wapf' ? 15981 : 15987;

const checks = [];
const check = (name, ok, detail = '') => {
  checks.push({ name, ok, detail });
  console.log(`${ok ? 'ok' : 'FAIL'} ${name}${detail ? ` (${detail})` : ''}`);
};

const storeFetch = async (page, route, body) => {
  const nonceRes = await page.request.get(`${base}/?rest_route=/wc/store/v1/cart`);
  const nonce = nonceRes.headers()['nonce'];
  const res = await page.request.post(`${base}/?rest_route=/wc/store/v1/cart/${route}`, {
    headers: { Nonce: nonce, 'Content-Type': 'application/json' },
    data: body,
  });
  return res;
};

let browser;
let page;
const errors = [];
try {
  browser = await chromium.launch();
  page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`${base}${product}`, { waitUntil: 'domcontentloaded' });

  const textInput = mode === 'wapf'
    ? page.locator('input[name="wapf[field_txt]"]')
    : page.locator('input[name="opf[' + groupId + '][mintext]"]');
  const textAttrs = await textInput.evaluate((el) => ({
    minlength: el.getAttribute('minlength'),
    maxlength: el.getAttribute('maxlength'),
    pattern: el.getAttribute('pattern'),
  })).catch(() => ({}));
  check(`${mode}: text field renders minlength/maxlength/pattern`,
    textAttrs.minlength === '3' && textAttrs.maxlength === '5' && textAttrs.pattern === '[a-z]+',
    JSON.stringify(textAttrs));

  if (mode === 'opf') {
    const wrapper = page.locator('.field-cbx .opf-swatch-wrapper');
    check('opf: checkbox wrapper carries data-min-choices=1 / data-max-choices=2',
      (await wrapper.getAttribute('data-min-choices')) === '1' && (await wrapper.getAttribute('data-max-choices')) === '2');

    // Hidden section child is disabled on load (gate unchecked).
    const sectextInput = page.locator('input[name="opf[' + groupId + '][sectext][0]"]');
    check('opf: hidden conditional-section child input is disabled',
      await sectextInput.isDisabled());
    await page.locator('input[value="1"][name="opf[' + groupId + '][gate]"]').check();
    check('opf: showing the section re-enables its child input', !(await sectextInput.isDisabled()));

    // Client max guard: after 2 checked, the third unchecked choice is disabled.
    const boxes = page.locator('.field-cbx input[type="checkbox"]:not([type="hidden"])');
    await boxes.nth(0).check();
    await boxes.nth(1).check();
    await boxes.nth(2).click({ force: true });
    const checkedCount = await boxes.evaluateAll((els) => els.filter((el) => el.checked).length);
    check('opf: client max=2 guard keeps at most two choices selected', checkedCount === 2, `checked=${checkedCount}`);

    // Forged over-max through the Store API is rejected server-side.
    const overRes = await storeFetch(page, 'add-item', {
      id: productId, quantity: 1,
      opf_fields: { [groupId]: { gate: '1', sectext: ['x'], site: 'https://example.com', multitext: ['a'], imgqty: { red: 1, blu: 1 }, cbx: ['x', 'y', 'w'] } },
    });
    check('opf: forged checkbox over max=2 rejected (Store API)', overRes.status() >= 400, `HTTP ${overRes.status()}`);

    // Forged value in a hidden section is accepted but never persisted.
    const hiddenRes = await storeFetch(page, 'add-item', {
      id: productId, quantity: 1,
      opf_fields: { [groupId]: { gate: '0', sectext: ['forged'], site: 'https://example.com', multitext: ['a'], imgqty: { red: 1, blu: 1 } } },
    });
    check('opf: hidden forged value accepted without error', hiddenRes.status() < 400, `HTTP ${hiddenRes.status()}`);
    const cart = await page.request.get(`${base}/?rest_route=/wc/store/v1/cart`);
    const cartJson = await cart.json();
    const names = (cartJson.items || []).flatMap((item) => (item.item_data || []).map((d) => d.name));
    check('opf: hidden forged value absent from cart display data', !names.includes('Section text'), names.join(','));

    // Missing required value in a visible section is still rejected.
    const missingRes = await storeFetch(page, 'add-item', {
      id: productId, quantity: 1,
      opf_fields: { [groupId]: { gate: '1', site: 'https://example.com', multitext: ['a'], imgqty: { red: 1, blu: 1 } } },
    });
    check('opf: missing required value in visible section rejected', missingRes.status() >= 400, `HTTP ${missingRes.status()}`);
  } else {
    const container = page.locator('.has-minmax').first();
    check('wapf: limited checkbox container carries data-minc/data-maxc',
      (await container.getAttribute('data-minc')) === '1' && (await container.getAttribute('data-maxc')) === '2');
    const boxes = page.locator('.has-minmax').first().locator('input[type="checkbox"]:not([type="hidden"])');
    await boxes.nth(0).check();
    await boxes.nth(1).check();
    check('wapf: browser prevents a third checkbox beyond max=2', await boxes.nth(2).isDisabled());
  }

  check(`${mode}: no uncaught page errors`, errors.length === 0, errors.join(' | '));

  const failures = checks.filter((c) => !c.ok);
  console.log(`DONE ${mode} (checks: ${checks.length}, failures: ${failures.length})`);
  if (failures.length) process.exit(1);
} finally {
  if (browser) await browser.close();
}
