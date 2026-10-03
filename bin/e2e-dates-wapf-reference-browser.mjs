// WAPF Extended 3.1.5 reference browser proof for the dates lifecycle fixture.
// Requires WAPF active + OPF inactive + reference fixture (e2e-dates-wapf-reference.php setup).
// Run with a playwright node_modules dir as cwd, e.g.:
//   cd /tmp/opf-url-native-parity && OPF_BASE_URL=http://127.0.0.1:8306 node /tmp/opf-lane-dates/bin/e2e-dates-wapf-reference-browser.mjs
import { createRequire } from 'node:module';
import fs from 'node:fs';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8306';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only');
const dir = '/tmp/opf-dates-artifacts';
fs.mkdirSync(dir, { recursive: true });
const state = JSON.parse(fs.readFileSync('/tmp/opf-dates-wapf-ref-state.json', 'utf8'));
const todayIso = new Date().toISOString().slice(0, 10);
const fmt = (iso) => iso.replaceAll('-', '.'); // fixture run uses wapf_date_format=yyyy.mm.dd
const checks = [], referenceJsErrors = [];
const check = (label, pass, extra) => { checks.push({ label, pass: !!pass, ...(extra ? { extra } : {}) }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('pageerror', e => referenceJsErrors.push(e.message));

const productUrl = `${base}/product/wapf-dates-lifecycle-reference/`;
const dayCell = (day) => page.locator(`.dp-days li[data-action="day"], .dp-days li[data-action="day disabled"], .dp-days li[data-action="day picked"]`).nth(day - 1);
const gotoMonth = async (year, monthIdx) => {
  await page.locator('input.wapf-dp-year').fill(String(year));
  await page.locator('input.wapf-dp-year').dispatchEvent('input');
  await page.waitForTimeout(150);
  await page.locator('select.wapf-dp-month').selectOption(String(monthIdx));
  await page.waitForTimeout(150);
};

try {
  await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
  const input = page.locator('input.wapf-input.input-dk');
  await input.waitFor({ state: 'visible', timeout: 15000 });
  check('input renders WAPF restriction attrs',
    (await input.getAttribute('data-disabled-days')) === '6'
    && (await input.getAttribute('data-disabled-dates')) === '02-10-2027 02-12-2027, 12-25'
    && (await input.getAttribute('data-disable-after')) === '00:00'
    && (await input.getAttribute('data-format')) === 'yyyy.mm.dd');
  const a11y = await input.evaluate(() => {
    const c = document.querySelector('.wapf-dp-c');
    return {
      containerRole: c?.getAttribute('role') ?? null,
      containerAriaLabel: c?.getAttribute('aria-label') ?? null,
      containerTabindex: c?.getAttribute('tabindex') ?? null,
    };
  });
  check('reference picker container has no dialog role/label (documented)', a11y.containerRole === null && a11y.containerAriaLabel === null, a11y);
  await input.focus();
  await page.waitForTimeout(300);
  const picker = page.locator('.wapf-dp-c');
  check('reference picker opens on focus', (await picker.getAttribute('aria-hidden')) === 'false');
  const weeks = await page.locator('.dp-weeks li').allTextContents();
  // daysMin are locale single/short letters; Monday first is the decisive signal.
  check('reference week starts on configured Monday', ['M', 'Mo', 'Mon', 'Monday'].includes(weeks[0]), { weeks });
  const dayLi = await page.locator('.dp-days li').first().evaluate(el => ({ tag: el.tagName, tabindex: el.getAttribute('tabindex'), role: el.getAttribute('role') }));
  check('reference day cells are non-focusable li without roles (documented)', dayLi.tag === 'LI' && dayLi.tabindex === null && dayLi.role === null, dayLi);

  // February 2027: Saturdays, disabled range, cutoff today.
  await gotoMonth(2027, 1);
  const monthSel = await page.locator('select.wapf-dp-month').inputValue();
  const yearVal = await page.locator('input.wapf-dp-year').inputValue();
  check('reference picker at February 2027', monthSel === '1' && yearVal === '2027', { monthSel, yearVal });
  check('reference Saturday 2027-02-06 disabled', await (await dayCell(6)).getAttribute('class') === 'disabled' || (await (await dayCell(6)).getAttribute('data-action')) === 'day disabled');
  check('reference Saturday 2027-02-13 disabled', (await dayCell(13).getAttribute('data-action')) === 'day disabled');
  check('reference range 2027-02-10..12 disabled',
    (await dayCell(10).getAttribute('data-action')) === 'day disabled'
    && (await dayCell(11).getAttribute('data-action')) === 'day disabled'
    && (await dayCell(12).getAttribute('data-action')) === 'day disabled');
  check('reference Monday 2027-02-15 selectable', (await dayCell(15).getAttribute('data-action')) === 'day');
  await page.screenshot({ path: dir + '/wapf-picker-february-2027.png', fullPage: true });

  // December 2028: 25th is a Monday — only the recurring MM-DD rule can disable it.
  await gotoMonth(2028, 11);
  check('reference Dec 2028 recurring 12-25 disabled client-side', (await dayCell(25).getAttribute('data-action')) === 'day disabled');
  await page.screenshot({ path: dir + '/wapf-picker-december-2028.png', fullPage: true });

  // Pick a valid Monday via UI; input stores the configured format string.
  await gotoMonth(2027, 1);
  await dayCell(15).click();
  await page.waitForTimeout(200);
  check('reference picked day fills formatted value', (await input.inputValue()) === fmt('2027-02-15'));

  // Forged Saturday via DOM injection -> server-side rejection.
  await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
  const input2 = page.locator('input.wapf-input.input-dk');
  await input2.fill(fmt('2027-02-13'));
  await page.locator('form.cart').evaluate(f => f.noValidate = true);
  await page.mouse.click(20, 20); // dismiss the open datepicker overlay
  await page.waitForTimeout(300);
  await page.locator('button[name="add-to-cart"]').click();
  await page.waitForSelector('.woocommerce-error, .wc-block-components-notice-banner', { timeout: 10000 }).catch(() => null);
  const bodyAfterForge = await page.locator('body').textContent();
  check('forged Saturday rejected server-side', /invalid date|disallowed date|Error adding product/i.test(bodyAfterForge));
  await page.screenshot({ path: dir + '/wapf-forged-saturday-error.png', fullPage: true });

  // Forged today on the cutoff-only field -> isolated cutoff rejection
  // (today is a disabled weekday for dk, so the dk check would be ambiguous).
  await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
  await page.locator('input.wapf-input.input-dk').fill(fmt('2027-02-15'));
  const input3 = page.locator('input.wapf-input.input-co');
  await input3.fill(fmt(todayIso));
  await page.locator('form.cart').evaluate(f => f.noValidate = true);
  await page.mouse.click(20, 20);
  await page.waitForTimeout(300);
  await page.locator('button[name="add-to-cart"]').click();
  await page.waitForSelector('.woocommerce-error, .wc-block-components-notice-banner', { timeout: 10000 }).catch(() => null);
  const bodyAfterToday = await page.locator('body').textContent();
  check('forged today rejected by cutoff server-side', /can't be selected|invalid date|disallowed date|Error adding product/i.test(bodyAfterToday));
  await page.screenshot({ path: dir + '/wapf-forged-today-error.png', fullPage: true });

  // Valid submission -> cart displays the configured-format value.
  await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
  const input4 = page.locator('input.wapf-input.input-dk');
  await input4.fill(fmt('2027-02-15'));
  await page.mouse.click(20, 20);
  await page.waitForTimeout(300);
  await page.locator('button[name="add-to-cart"]').click();
  await page.waitForURL(/cart|product/, { timeout: 15000 }).catch(() => null);
  await page.goto(base + '/cart/', { waitUntil: 'domcontentloaded' });
  const cartBody = await page.locator('body').textContent();
  check('reference cart displays configured-format date', cartBody.includes(fmt('2027-02-15')));
  await page.screenshot({ path: dir + '/wapf-cart-formatted.png', fullPage: true });
  // Empty the cart for idempotency.
  const remove = page.locator('td.product-remove a, .wc-block-cart-item__remove-link');
  while (await remove.count() > 0) { await remove.first().click(); await page.waitForTimeout(600); }
} finally {
  await browser.close();
  fs.writeFileSync(dir + '/wapf-reference-results.json', JSON.stringify({ checks, referenceJsErrors }, null, 2));
  console.log('done, referenceJsErrors: ' + referenceJsErrors.length);
  if (referenceJsErrors.length) console.log(referenceJsErrors.join('\n'));
}
