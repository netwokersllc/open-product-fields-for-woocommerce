import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

// Run the PHP fake-API Woo fixture first. Browser uses its actual adapter output.
const wpPath = process.env.WP_TEST_PATH;
if (!wpPath || !path.resolve(wpPath).startsWith('/tmp/opf-aelia-')) {
  throw new Error('Set WP_TEST_PATH to the dedicated /tmp/opf-aelia-* clone.');
}
const fixture = JSON.parse(fs.readFileSync(path.join(wpPath, 'wp-content/database/opf-aelia-config.json'), 'utf8'));
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
  await page.evaluate(config => {
    window.OPF_FIELDS = { g: {
      fixed: { type: 'text', pricing: { type: 'fixed', amount: 3 } },
      percent: { type: 'text', pricing: { type: 'percent', amount: 10 } },
      formula: { type: 'text', pricing: { type: 'formula', formula: '[price]' } },
    } };
    window.opf_config = config;
  }, fixture.initial);
  await page.addScriptTag({ content: fs.readFileSync(path.join(here, '../assets/js/opf-frontend.js'), 'utf8') });
  const total = async selector => (await page.locator(selector).innerText()).replace(/\u00a0/g, ' ').trim();
  assert.equal(await total('.opf-product-total'), '€20.00');
  assert.equal(await total('.opf-options-total'), '€28.00');
  assert.equal(await total('.opf-grand-total'), '€48.00');
  await page.evaluate(variation => window.jQuery('form.variations_form').trigger('found_variation', [variation]), fixture.variation);
  assert.equal(await total('.opf-product-total'), '€30.00');
  assert.equal(await total('.opf-grand-total'), '€69.00');
  await page.evaluate(variation => window.jQuery('form.variations_form').trigger('found_variation', [variation]), fixture.fixed_variation);
  assert.equal(await total('.opf-product-total'), '€50.00');
  assert.equal(await total('.opf-options-total'), '€41.00');
  assert.equal(await total('.opf-grand-total'), '€91.00');
  await page.evaluate(() => window.jQuery('form.variations_form').trigger('reset_data'));
  assert.equal(await total('.opf-grand-total'), '€48.00');
  await page.evaluate(() => {
    window.opf_config.currency_rate = 3;
    window.opf_config.display_options = { symbol: '€', format: '%2$s&nbsp;%1$s', thousand: '.', decimal: ',', decimals: 2 };
  });
  await page.locator('[data-opf-field="fixed"] input').dispatchEvent('input');
  await page.waitForFunction(() => document.querySelector('.opf-grand-total').textContent.replace(/\u00a0/g, ' ') === '72,00 €');
  assert.equal(await total('.opf-grand-total'), '72,00 €');
  assert.deepEqual(errors, []);
  console.log('Aelia Chromium fake-API contract passed: Woo adapter config -> initial 48; variation 69; fixed variation 91; reset 48; changed rate/right-space decimal formatting 72,00 €; no JS errors.');
} finally {
  await browser.close();
}
