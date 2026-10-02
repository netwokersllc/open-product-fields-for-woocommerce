import { createRequire } from 'node:module';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

await page.setContent(`<!doctype html><html><body>
  <div data-opf-fields>
    <div data-opf-group="1">
      <div data-opf-field="seat" data-opf-repeat="quantity"><div class="opf-field-repeat__rows">
        <div data-opf-repeat-instance><div class="opf-field-label"><span>Seat</span></div><input name="opf[1][seat][0]" value="same"></div>
        <div data-opf-repeat-instance><div class="opf-field-label"><span>Seat 2</span></div><input name="opf[1][seat][1]" value="same"></div>
      </div></div>
    </div>
    <div data-opf-group="2">
      <div data-opf-field="zone" data-opf-repeat="quantity"><div class="opf-field-repeat__rows">
        <div data-opf-repeat-instance><div class="opf-field-label"><span>Zone</span></div><input name="opf[2][zone][0]" value="north"></div>
        <div data-opf-repeat-instance><div class="opf-field-label"><span>Zone 2</span></div><input name="opf[2][zone][1]" value="south"></div>
      </div></div>
    </div>
  </div>
  <div class="opf-product-totals" data-product-price="10"><span class="opf-product-total"></span><span class="opf-options-total"></span><span class="opf-grand-total"></span></div>
  <form class="cart"><input name="quantity" value="2"></form>
</body></html>`);
await page.evaluate(() => {
  window.opf_config = { product_base_price: 10 };
  window.OPF_FIELDS = {
    1: { seat: { type: 'text', repeat: { enabled: true, mode: 'quantity', label: 'Seat {n}' }, pricing: { type: 'formula', formula: '[qty]' } } },
    2: { zone: { type: 'text', repeat: { enabled: true, mode: 'quantity', label: 'Zone {n}' }, pricing: { type: 'none' } } }
  };
});
await page.addScriptTag({ path: 'assets/js/opf-frontend.js' });
await page.waitForFunction(() => document.querySelector('.opf-options-total').textContent !== '');
const groupedTotal = await page.locator('.opf-options-total').textContent();
const labels = await page.locator('[data-opf-field="seat"] .opf-field-label span').allTextContents();

const quantityInput = page.locator('form.cart input[name="quantity"]');
await quantityInput.fill('1');
await page.waitForTimeout(100);
const quantityOneTotal = await page.locator('.opf-options-total').textContent();
await quantityInput.fill('4');
await page.waitForTimeout(100);
for (const field of ['seat', 'zone']) {
  const inputs = page.locator(`[data-opf-field="${field}"] [data-opf-repeat-instance] input`);
  for (let i = 0; i < 4; i++) await inputs.nth(i).fill('same');
}
await page.waitForTimeout(100);
const quantityFourTotal = await page.locator('.opf-options-total').textContent();
await quantityInput.fill('2');
await page.waitForTimeout(100);

// Make the submitted row count invalid after normal quantity synchronization.
await page.locator('[data-opf-field="zone"] [data-opf-repeat-instance]').last().evaluate((row) => row.remove());
await page.locator('[data-opf-field="seat"] input').first().dispatchEvent('input');
await page.waitForTimeout(100);
const invalidTotal = await page.locator('.opf-options-total').textContent();
const invalidGrand = await page.locator('.opf-grand-total').textContent();

// Keep the row count but create a submitted-index gap, then an extra row.
await page.locator('[data-opf-field="zone"] .opf-field-repeat__rows').evaluate((rows) => {
  const clone = rows.querySelector(':scope > [data-opf-repeat-instance]').cloneNode(true);
  clone.querySelector('[name]').name = 'opf[2][zone][1]';
  rows.appendChild(clone);
  clone.querySelector('[name]').name = 'opf[2][zone][3]';
});
await page.locator('[data-opf-field="seat"] input').first().dispatchEvent('input');
await page.waitForTimeout(100);
const gappedTotal = await page.locator('.opf-options-total').textContent();
await page.locator('[data-opf-field="zone"] .opf-field-repeat__rows').evaluate((rows) => {
  const clone = rows.querySelector(':scope > [data-opf-repeat-instance]').cloneNode(true);
  clone.querySelector('[name]').name = 'opf[2][zone][2]';
  rows.appendChild(clone);
});
await page.locator('[data-opf-field="seat"] input').first().dispatchEvent('input');
await page.waitForTimeout(100);
const extraTotal = await page.locator('.opf-options-total').textContent();

const ok = groupedTotal === '$2.00' && quantityOneTotal === '$1.00' && quantityFourTotal === '$16.00' && labels.join(',') === 'Seat,Seat 2' && invalidTotal === '' && invalidGrand === '' && gappedTotal === '' && extraTotal === '' && errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} product-wide qty-repeat grouping, qty1/qty4 pricing, label independence, and invalid row suppression`);
if (!ok) console.log(JSON.stringify({ groupedTotal, quantityOneTotal, quantityFourTotal, labels, invalidTotal, invalidGrand, gappedTotal, extraTotal, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
