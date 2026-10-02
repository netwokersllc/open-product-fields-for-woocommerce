// Follow WooCommerce's actual order-again URL and reload the real cart session.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const { chromium } = createRequire(process.cwd() + '/index.js')('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8181';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only');
const browser = await chromium.launch();
const page = await browser.newPage();
const checks = [];
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); if (!pass) throw new Error(label); };
try {
  await page.goto(base + '/?opf_email_login=1', { waitUntil: 'domcontentloaded' });
  const orders = JSON.parse(fs.readFileSync('/tmp/opf-email-artifacts/browser-orders.json', 'utf8'));
  for (const [kind, id] of Object.entries(orders)) {
    const cart = await page.request.get(base + '/wp-json/wc/store/v1/cart');
    for (const item of (await cart.json()).items) await page.request.delete(base + '/wp-json/wc/store/v1/cart/items/' + item.key, { headers: { Nonce: cart.headers().nonce } });
    await page.goto(base + '/my-account/view-order/' + id + '/', { waitUntil: 'domcontentloaded' });
    const again = page.getByRole('link', { name: 'Order again', exact: true });
    check(kind + ' actual Woo order-again action exists', await again.count() === 1);
    await again.click();
    await page.waitForURL(/\/cart\//);
    const data = await (await page.request.get(base + '/wp-json/wc/store/v1/cart')).json();
    check(kind + ' real order-again cart contains restored email', data.items.length === 1 && data.items[0].item_data.find(r => r.name === 'Contact email edited')?.value === 'a&amp;b@example.test');
    check(kind + ' real order-again cart retains unchanged price', data.items[0].prices.price === '1000');
  }
} finally {
  fs.writeFileSync('/tmp/opf-email-artifacts/order-again-results.json', JSON.stringify({ time: new Date().toISOString(), checks }, null, 2));
  await browser.close();
}
