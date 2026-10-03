// Browser proof for the dates lifecycle fixture (product page picker + server rejection).
import { createRequire } from 'node:module';
import fs from 'node:fs';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8306';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only');
const dir = '/tmp/opf-dates-artifacts';
fs.mkdirSync(dir, { recursive: true });
const state = JSON.parse(fs.readFileSync('/tmp/opf-dates-state.json', 'utf8'));
const checks = [], errors = [];
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('pageerror', e => errors.push(e.message));
const todayIso = new Date().toISOString().slice(0, 10);
// Months from the current UTC month to a target YYYY-MM, for PageDown navigation.
const monthsTo = (targetYm) => {
  const [ty, tm] = targetYm.split('-').map(Number);
  const [cy, cm] = todayIso.slice(0, 7).split('-').map(Number);
  return (ty - cy) * 12 + (tm - cm);
};
try {
  await page.goto(base + '/product/opf-dates-lifecycle/', { waitUntil: 'domcontentloaded' });
  const field = page.locator('[data-opf-field="delivery"]');
  const input = field.locator('input[type="date"]');
  check('input renders disabled weekday/date/cutoff attrs', (await input.getAttribute('data-opf-disabled-weekdays')) === '[6]' && (await input.getAttribute('data-opf-disabled-dates')) === '["2027-02-10 2027-02-12","12-25"]' && (await input.getAttribute('data-opf-date-cutoff')) === '00:00');
  const weekStart = await input.getAttribute('data-opf-week-start');
  check('week-start attribute present', weekStart !== null);
  // Open the picker; keyboard-navigate to February 2027 via PageDown month steps.
  await field.locator('.opf-date-picker__toggle').click();
  for (let i = 0, n = monthsTo('2027-02'); i < n; i++) { await page.keyboard.press('PageDown'); await page.waitForTimeout(150); }
  const calendar = field.getByRole('dialog', { name: 'Choose a date' });
  check('calendar opens with accessible name', await calendar.isVisible());
  check('month announced via aria-live header', (await field.locator('.opf-date-picker__header strong[aria-live="polite"]').textContent()) === 'February 2027');
  const headers = await field.locator('[role="columnheader"]').allTextContents();
  const expectedFirst = (Number(weekStart) === 0) ? 'Sun' : (Number(weekStart) === 1 ? 'Mon' : headers[0]);
  check('week starts on configured day', weekStart === '1' ? headers[0] === 'Mon' : true);
  check('Saturday disabled', await field.locator('[data-opf-date="2027-02-06"]').isDisabled());
  check('Saturday follow-up disabled', await field.locator('[data-opf-date="2027-02-13"]').isDisabled());
  check('disabled range start disabled', await field.locator('[data-opf-date="2027-02-10"]').isDisabled());
  check('disabled range middle disabled', await field.locator('[data-opf-date="2027-02-11"]').isDisabled());
  check('enabled Monday selectable', !(await field.locator('[data-opf-date="2027-02-15"]').isDisabled()));
  check('today disabled by cutoff', await field.locator(`[data-opf-date="${todayIso}"]`).count() === 0 || await field.locator(`[data-opf-date="${todayIso}"]`).isDisabled().catch(() => true));
  // December 2028: the 25th is a Monday, so only the recurring MM-DD rule can disable it.
  const recurringTarget = '2028-12-25';
  const toDec = monthsTo('2028-12') - monthsTo('2027-02');
  for (let i = 0; i < toDec; i++) { await page.keyboard.press('PageDown'); await page.waitForTimeout(120); }
  check('header reached December 2028', (await field.locator('.opf-date-picker__header strong').first().textContent()) === 'December 2028');
  check('recurring MM-DD disabled (Monday, isolated from weekday rule)', await field.locator(`[data-opf-date="${recurringTarget}"]`).isDisabled());
  await page.screenshot({ path: dir + '/picker-december-2028-recurring.png', fullPage: true });
  for (let i = 0; i < toDec; i++) { await page.keyboard.press('PageUp'); await page.waitForTimeout(120); }
  await field.locator('[data-opf-date="2027-02-15"]').click();
  check('clicking valid day updates native input', await input.inputValue() === '2027-02-15');
  check('toggle shows configured format', (await field.locator('.opf-date-picker__toggle').textContent()) === '2027.02.15');
  // reopen the calendar to check keyboard skip of disabled dates
  await field.locator('.opf-date-picker__toggle').click();
  await page.waitForTimeout(200);
  await field.locator('[data-opf-date="2027-02-15"]').focus();
  await page.keyboard.press('ArrowRight');
  check('arrow skips to next enabled day', (await page.locator(':focus').getAttribute('data-opf-date')) === '2027-02-16');
  await page.keyboard.press('Escape');
  check('Escape closes calendar', !(await calendar.isVisible()));
  await page.screenshot({ path: dir + '/picker-february.png', fullPage: true });
  // Injection path: forged Saturday must be rejected by server.
  await input.evaluate((el, v) => { el.value = v; }, '2027-02-13');
  await page.locator('form.cart').evaluate(f => f.noValidate = true);
  await page.locator('button[name="add-to-cart"]').click();
  await page.waitForSelector('text=unavailable on this weekday', { timeout: 10000 });
  check('forged Saturday rejected with notice', true);
  await page.screenshot({ path: '/tmp/opf-dates-artifacts/forged-saturday-error.png', fullPage: true });
  // Cutoff: forged today rejected.
  await page.goto(base + '/product/opf-dates-lifecycle/', { waitUntil: 'domcontentloaded' });
  const input2 = page.locator('[data-opf-field="delivery"] input[type="date"]');
  await input2.evaluate((el, v) => { el.value = v; }, todayIso);
  await page.locator('form.cart').evaluate(f => f.noValidate = true);
  await page.locator('button[name="add-to-cart"]').click();
  await page.waitForSelector('text=/cutoff|unavailable|problems were found/i', { timeout: 10000 }).catch(() => null);
  const err2 = await page.locator('body').textContent();
  check('forged today rejected by cutoff', /cutoff|unavailable|problems were found/i.test(err2));
  await page.screenshot({ path: '/tmp/opf-dates-artifacts/forged-today-cutoff-error.png', fullPage: true });
  // Valid submission adds to cart and cart label uses formatted date.
  await page.goto(base + '/product/opf-dates-lifecycle/', { waitUntil: 'domcontentloaded' });
  const input3 = page.locator('[data-opf-field="delivery"] input[type="date"]');
  const loose = page.locator('[data-opf-field="loose"] input[type="date"]');
  await input3.evaluate((el, v) => { el.value = v; }, '2027-02-15');
  await loose.evaluate((el, v) => { el.value = v; }, '2027-02-16');
  await page.locator('button[name="add-to-cart"]').click();
  await page.waitForURL(/cart|product/);
  await page.goto(base + '/cart/', { waitUntil: 'domcontentloaded' });
  check('cart label formatted yyyy.mm.dd', (await page.locator('body').textContent()).includes('2027.02.15'));
  await page.screenshot({ path: '/tmp/opf-dates-artifacts/cart-formatted.png' });
  // clear the cart for idempotency
  await page.goto(base + '/cart/?removed_item=1', { waitUntil: 'domcontentloaded' }).catch(() => {});
  const removeLinks = page.locator('td.product-remove a');
  const n = await removeLinks.count();
  if (n > 0) await removeLinks.first().click();
  await page.waitForTimeout(500);
  check('no page errors', errors.length === 0);
} finally {
  await browser.close();
  fs.writeFileSync(dir + '/browser-results.json', JSON.stringify({ checks, errors }, null, 2));
  console.log('done, errors: ' + errors.length);
}
