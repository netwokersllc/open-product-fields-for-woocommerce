import { createRequire } from 'node:module';
import fs from 'node:fs';
const { chromium } = createRequire(process.cwd() + '/index.js')('playwright');
const base = process.env.OPF_WAPF_BASE_URL || 'http://127.0.0.1:8194';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only');
const checks = [], errors = [];
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); if (!pass) throw new Error(label); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('pageerror', e => errors.push(e.message));
try {
  await page.goto(base + '/product/wapf-toggle-reference/', { waitUntil: 'networkidle' });
  const accept = page.locator('input[type=checkbox][name="wapf[field_accept]"]');
  const wrap = page.locator('input[type=checkbox][name="wapf[field_wrap]"]');
  await accept.waitFor();
  check('Free native checked default and required semantics', await accept.isChecked() && await accept.getAttribute('required') !== null);
  check('Free optional unchecked default', !(await wrap.isChecked()) && await wrap.getAttribute('required') === null);
  check('Free message renders escaped beside checkbox with accessible label', await page.getByLabel('Accept & continue', { exact: false }).count() === 1 && (await page.content()).includes('Accept &amp; continue'));
  check('Free hidden false and checked true transport order', await page.locator('form.cart').evaluate(f => new FormData(f).getAll('wapf[field_accept]').join(',') === '0,1' && new FormData(f).getAll('wapf[field_wrap]').join(',') === '0'));
  await wrap.focus();
  await page.keyboard.press('Space');
  check('Free keyboard Space toggles native checkbox', await wrap.isChecked());
  await page.keyboard.press('Space');
  await accept.uncheck();
  let posts = 0;
  page.on('request', r => { if (r.method() === 'POST' && r.url().includes('/product/wapf-toggle-reference/')) posts++; });
  await page.locator('button.single_add_to_cart_button').click();
  check('Free native required unchecked blocks submit', posts === 0 && await accept.evaluate(n => n.validity.valueMissing));
  await accept.check();
  await page.screenshot({ path: '/tmp/opf-toggle-artifacts/wapf-reference.png', fullPage: true });
  fs.writeFileSync('/tmp/opf-toggle-artifacts/wapf-accessibility.txt', await page.locator('form.cart').ariaSnapshot());
  check('Free no uncaught browser errors', errors.length === 0);
} finally {
  fs.writeFileSync('/tmp/opf-toggle-artifacts/wapf-browser-results.json', JSON.stringify({ base, time: new Date().toISOString(), checks, errors }, null, 2));
  await browser.close();
}
