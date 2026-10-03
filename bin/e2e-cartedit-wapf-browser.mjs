// WAPF Extended 3.1.5 storefront comparison for edit-in-cart: classic cart
// link -> edit permalink prefill -> remove-then-add update. Requires WAPF
// active and OPF inactive (see bin/e2e-cartedit-wapf-reference.php).
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('/tmp/opf-url-native-parity/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8318';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only');
const dir = '/tmp/opf-lane-cartedit-evidence';
fs.mkdirSync(dir, { recursive: true });
const state = JSON.parse(fs.readFileSync('/tmp/opf-cartedit-e2e/wapf-state.json', 'utf8'));
const checks = [], errors = [];
const check = (label, pass, extra) => {
	checks.push({ label, pass: !!pass, extra: extra ?? null });
	console.log(`${pass ? 'ok' : 'FAIL'} ${label}${extra !== undefined ? ' :: ' + JSON.stringify(extra) : ''}`);
};
const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
const page = await context.newPage();
page.on('pageerror', (e) => errors.push(String(e.message || e)));

const cartURL = base + '/wp-json/wc/store/v1/cart';
const storeCart = async () => { const r = await page.request.get(cartURL); return { nonce: r.headers()['nonce'], data: await r.json() }; };
const emptyCart = async () => { const { nonce, data } = await storeCart(); for (const it of data.items || []) await page.request.delete(`${cartURL}/items/${it.key}`, { headers: { Nonce: nonce } }); };

await emptyCart();
await page.goto(base + '/product/wapf-cartedit-reference/', { waitUntil: 'domcontentloaded' });
await page.locator('input[name="wapf[field_source]"]').waitFor();
await page.locator('input[name="wapf[field_source]"]').fill('WORIG');
await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null), page.locator('button.single_add_to_cart_button').click()]);
await page.waitForTimeout(400);
{
	const { data } = await storeCart();
	check('WAPF add produced one cart line', (data.items || []).length === 1, (data.items || []).length);
}

await page.goto(base + '/opf-ce-cart/', { waitUntil: 'domcontentloaded' });
const link = page.locator('a.wapf-edit-cartitem').first();
await link.waitFor({ timeout: 15000 });
const href = await link.getAttribute('href');
check('WAPF classic cart exposes a.wapf-edit-cartitem with _edit key', /_edit=[a-f0-9]{32}/.test(href || ''), href);
await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null), link.click()]);
check('WAPF edit permalink prefills the field', (await page.locator('input[name="wapf[field_source]"]').inputValue()) === 'WORIG');
check('WAPF emits hidden _wapf_edit state', (await page.locator('input[name="_wapf_edit"]').count()) === 1);
await page.locator('input[name="wapf[field_source]"]').fill('WEDIT');
await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null), page.locator('button.single_add_to_cart_button').click()]);
await page.waitForTimeout(400);
{
	const { data } = await storeCart();
	const items = data.items || [];
	check('WAPF remove-then-add leaves exactly one line', items.length === 1, items.length);
	// WAPF's own cart label render is is_cart()-gated, so assert on the page.
	await page.goto(base + '/opf-ce-cart/', { waitUntil: 'domcontentloaded' });
	const body = await page.locator('body').innerText();
	check('WAPF classic cart shows the edited value', /WEDIT/.test(body) && !/WORIG/.test(body), /WEDIT/.test(body) ? 'WEDIT' : 'missing');
}

fs.writeFileSync(`${dir}/wapf-browser-results.json`, JSON.stringify({ base, checks, pageErrors: errors }, null, 2));
const failed = checks.filter((c) => !c.pass);
console.log(`${checks.length} WAPF browser checks, ${failed.length} failed, ${errors.length} page errors`);
await browser.close();
process.exit(failed.length ? 1 : 0);
