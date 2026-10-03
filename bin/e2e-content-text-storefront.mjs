import { createRequire } from 'node:module';
import fs from 'node:fs';
import { createHash } from 'node:crypto';
const { chromium } = createRequire(process.cwd() + '/index.js')('playwright');
const base = process.env.OPF_CONTENT_TEXT_BASE || 'http://127.0.0.1:8243';
const out = process.env.OPF_CONTENT_TEXT_OUT;
const freeSource = process.env.OPF_CONTENT_TEXT_FREE_SOURCE;
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base) || !out?.startsWith('/tmp/') || !freeSource?.startsWith('/tmp/')) throw new Error('Disposable loopback, /tmp output and Free source required');
const state = JSON.parse(fs.readFileSync(out + '/state.json', 'utf8'));
const checks = [], errors = [], responses = [];
const servedAssets = {}, pendingAssets = [];
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
page.on('pageerror', err => errors.push(err.message));
page.on('response', response => {
  if (response.status() >= 400) responses.push({ url: response.url(), status: response.status() });
  if (/\/(opf-content-text-lane\/assets\/js\/opf-frontend\.js|advanced-product-fields-for-woocommerce\/assets\/js\/frontend\.min\.js)(\?|$)/.test(response.url())) {
    pendingAssets.push(response.body().then(body => { servedAssets[response.url()] = createHash('sha256').update(body).digest('hex'); }));
  }
});
const wapfContent = id => page.locator(`.wapf [data-field-id="${id}"]`).first();
const opfWrapper = index => page.locator(`[data-opf-field="${state.opf_ids[index]}"]`).first();
try {
  const response = await page.goto(base + '/?page_id=' + state.page, { waitUntil: 'networkidle' });
  check('real storefront response is HTTP 200', response.status() === 200);
  await wapfContent('current').waitFor({ state: 'attached' });
  await opfWrapper(1).waitFor({ state: 'attached' });
  // Parse expected escaped bytes with a detached document, without altering the page.
  const expected = await page.evaluate(html => { const node = document.createElement('div'); node.innerHTML = html; return { html: node.innerHTML, text: node.textContent }; }, state.expected_html);
  for (const [id, index] of [['current', 1], ['legacy', 2]]) {
    const wapf = wapfContent(id), opf = opfWrapper(index).locator('.opf-field-content');
    const [wh, oh, wt, ot] = await Promise.all([wapf.innerHTML(), opf.innerHTML(), wapf.textContent(), opf.textContent()]);
    // WAPF view adds indentation around the escaped value; OPF does not.
    check(`${id}: WAPF escaped content equals esc_html sanitized fixture`, wh.trim() === expected.html.trim());
    check(`${id}: OPF imported plain content equals WAPF escaped content`, oh.trim() === wh.trim());
    check(`${id}: browser text preserves entities, quotes, newline, tab and literal shortcode`, wt.trim() === expected.text.trim() && ot.trim() === expected.text.trim() && ot.includes('\nSecond\tline') && ot.includes('[opfct_unregistered]'));
    check(`${id}: static content has no child markup or submitted input`, await wapf.locator('b,strong,script,input,textarea,select').count() === 0 && await opfWrapper(index).locator('b,strong,script,input,textarea,select').count() === 0);
    check(`${id}: WAPF and OPF initially hidden by mapped condition`, !await wapf.isVisible() && !await opfWrapper(index).isVisible());
    fs.writeFileSync(out + '/' + id + '-fragments.json', JSON.stringify({ wapf: wh, opf: oh, browserText: ot }, null, 2));
  }
  const raw = wapfContent('storedraw');
  check('raw stored WAPF content is escaped with literal markup and zero child tags', (await raw.innerHTML()).trim() === state.raw_expected_html && await raw.locator('b,script').count() === 0);
  check('raw stored OPF import removes markup and script body, retaining review-required boundary', !(await opfWrapper(3).textContent()).includes('<b>') && !(await opfWrapper(3).textContent()).includes('opfctExecuted'));
  const wapfGate = page.locator('.wapf input[data-field-id="gate"]');
  const opfGate = opfWrapper(0).locator('input');
  await wapfGate.fill('show');
  await opfGate.fill('show');
  await page.waitForTimeout(200);
  for (const [id, index] of [['current', 1], ['legacy', 2]]) check(`${id}: both visible when gate equals show`, await wapfContent(id).isVisible() && await opfWrapper(index).isVisible());
  await page.screenshot({ path: out + '/visible.png', fullPage: true });
  await wapfGate.fill('hide');
  await opfGate.fill('hide');
  await page.waitForTimeout(200);
  for (const [id, index] of [['current', 1], ['legacy', 2]]) check(`${id}: both hidden after gate changes away from show`, !await wapfContent(id).isVisible() && !await opfWrapper(index).isVisible());
  check('escaped script probes never execute', await page.evaluate(() => typeof window.opfctExecuted === 'undefined'));
  check('no browser console or page errors', errors.length === 0);
  check('no failed served resource responses', responses.length === 0);
  await Promise.all(pendingAssets);
  for (const [suffix, source] of [
    ['opf-content-text-lane/assets/js/opf-frontend.js', state.runtime.opf_dir + '/assets/js/opf-frontend.js'],
    ['advanced-product-fields-for-woocommerce/assets/js/frontend.min.js', freeSource + '/assets/js/frontend.min.js'],
  ]) {
    const served = Object.entries(servedAssets).find(([url]) => url.includes(suffix));
    check(`${suffix}: browser-served bytes match reference source`, !!served && served[1] === createHash('sha256').update(fs.readFileSync(source)).digest('hex'));
  }
} finally {
  fs.writeFileSync(out + '/browser-results.json', JSON.stringify({ time: new Date().toISOString(), base, chromium: browser.version(), checks, errors, failedResponses: responses, servedAssets }, null, 2));
  await browser.close();
}
if (checks.some(c => !c.pass)) process.exit(1);
