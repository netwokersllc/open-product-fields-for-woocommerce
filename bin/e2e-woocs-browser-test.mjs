import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

// WP_TEST_PATH supplies WordPress's actual jQuery; no network or live site is used.
const wpPath = process.env.WP_TEST_PATH;
if (!wpPath) throw new Error('Set WP_TEST_PATH to a disposable WordPress clone.');
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
try {
  await page.setContent(`<!doctype html><form class="cart variations_form">
    <input name="quantity" class="qty" type="number" value="1">
    <div data-opf-fields><div data-opf-group="g">
      <div data-opf-field="fixed"><input type="text" value="yes"></div>
      <div data-opf-field="percent"><input type="text" value="yes"></div>
      <div data-opf-field="formula"><input type="text" value="yes"></div>
    </div></div>
    <div class="opf-product-totals" data-product-price="20">
      <span class="opf-product-total"></span><span class="opf-options-total"></span><span class="opf-grand-total"></span>
    </div></form>`);
  await page.addScriptTag({ content: fs.readFileSync(path.join(wpPath, 'wp-includes/js/jquery/jquery.min.js'), 'utf8') });
  await page.evaluate(() => {
    window.OPF_FIELDS = { g: {
      fixed: { type: 'text', pricing: { type: 'fixed', amount: 3 } },
      percent: { type: 'text', pricing: { type: 'percent', amount: 10 } },
      formula: { type: 'text', pricing: { type: 'formula', formula: '[price]' } },
    } };
    window.opf_config = { product_base_price: 10, formula_base_price: 10, currency_rate: 2,
      display_options: { symbol: '€', format: '%2$s&nbsp;%1$s', thousand: '.', decimal: ',', decimals: 2 } };
  });
  await page.addScriptTag({ content: fs.readFileSync(path.join(here, '../assets/js/opf-frontend.js'), 'utf8') });
  const total = async (selector) => (await page.locator(selector).innerText()).replace(/\u00a0/g, ' ').trim();
  assert.equal(await total('.opf-product-total'), '20,00 €');
  assert.equal(await total('.opf-options-total'), '28,00 €');
  assert.equal(await total('.opf-grand-total'), '48,00 €');

  await page.evaluate(() => window.jQuery('form.variations_form').trigger('found_variation', [{ display_price: 30, opf_base_price: 15, opf_formula_base_price: 15 }]));
  assert.equal(await total('.opf-grand-total'), '69,00 €');
  await page.evaluate(() => window.jQuery('form.variations_form').trigger('reset_data'));
  assert.equal(await total('.opf-grand-total'), '48,00 €');

  await page.evaluate(() => window.jQuery('form.variations_form').trigger('found_variation', [{ display_price: 50, opf_base_price: 25, opf_formula_base_price: 10 }]));
  assert.equal(await total('.opf-product-total'), '50,00 €');
  assert.equal(await total('.opf-options-total'), '31,00 €');
  assert.equal(await total('.opf-grand-total'), '81,00 €');

  await page.evaluate(() => {
    window.opf_config.currency_rate = 1;
    window.opf_config.product_base_price = 1234;
    window.opf_config.formula_base_price = 0;
    window.opf_config.display_options = { symbol: '¥', format: '%1$s%2$s', thousand: '', decimal: '.', decimals: 0 };
    window.OPF_FIELDS.g.percent.pricing.type = 'none';
  });
  await page.locator('[data-opf-field="fixed"] input').dispatchEvent('input');
  await page.waitForFunction(() => document.querySelector('.opf-grand-total').textContent === '¥1237');
  assert.equal(await total('.opf-grand-total'), '¥1237');
  assert.deepEqual(errors, []);
  console.log('WOOCS Chromium contract passed: initial totals, fixed/percent/formula bases, real jQuery variation/reset, right-space/decimal/empty-separator formatting; no JS errors.');
} finally {
  await browser.close();
}
