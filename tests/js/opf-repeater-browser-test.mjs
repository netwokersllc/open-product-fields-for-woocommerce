import { createRequire } from 'node:module';
import fs from 'node:fs';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
await page.setContent(`<!doctype html><html><body>
  <div class="opf-fields" data-opf-fields="1">
    <div class="opf-field-group" data-opf-group="1">
      <div class="opf-field-container opf-field-repeat" data-opf-field="name" data-opf-repeat="button" data-opf-repeat-max="2">
        <div class="opf-field-repeat__rows"><div data-opf-repeat-instance="1"><input id="opf-1-name-repeat-0" data-field-id="name-repeat-0" name="opf[1][name][0]" value="" /></div></div>
        <button type="button" class="opf-field-repeat__add">Add another</button><span class="opf-field-repeat__status" aria-live="polite"></span>
      </div>
      <div class="opf-field-container opf-field-repeat" data-opf-field="choices" data-opf-repeat="button" data-opf-repeat-max="2">
        <div class="opf-field-repeat__rows"><div data-opf-repeat-instance="1"><input type="checkbox" name="opf[1][choices][0][]" value="oak" /><input type="checkbox" name="opf[1][choices][0][]" value="blue" /></div></div>
        <button type="button" class="opf-field-repeat__add">Add another</button><span class="opf-field-repeat__status" aria-live="polite"></span>
      </div>
      <div data-opf-field="conditional"><input name="opf[1][conditional]" value="" /></div>
    </div>
  </div>
  <div class="opf-product-totals" data-product-price="10"><div class="opf--inner"><span class="opf-product-total"></span><span class="opf-options-total"></span><span class="opf-grand-total"></span></div></div>
  <form class="cart"><input name="quantity" value="1" /></form>
</body></html>`);
await page.evaluate(() => {
  window.OPF_FIELDS = { 1: {
    name: { type: 'text', repeat: { enabled: true, mode: 'button', max: 2 }, conditionals: [], pricing: { type: 'fixed', amount: 2 } },
    choices: { type: 'checkbox', multiple: true, repeat: { enabled: true, mode: 'button', max: 2 }, conditionals: [], pricing: { type: 'none', amount: 0 }, choices: [ { slug: 'oak', pricing: { type: 'fixed', amount: 2 } }, { slug: 'blue', pricing: { type: 'fixed', amount: 3 } } ] },
    conditional: { type: 'text', conditionals: [ { action: 'show', logic: 'all', rules: [ { field: 'name', operator: 'contains', value: 'Grace' } ] } ] }
  } };
});
await page.addScriptTag({ path: 'assets/js/opf-frontend.js' });
await page.locator('[data-opf-field="name"] .opf-field-repeat__add').click();
await page.locator('[data-opf-repeat-instance]').nth(0).locator('input').fill('Ada');
await page.locator('[data-opf-repeat-instance]').nth(1).locator('input').fill('Grace');
await page.locator('[data-opf-field="choices"] .opf-field-repeat__add').click();
await page.locator('[data-opf-field="choices"] [data-opf-repeat-instance]').nth(0).locator('input[value="oak"]').check();
await page.locator('[data-opf-field="choices"] [data-opf-repeat-instance]').nth(1).locator('input[value="blue"]').check();
const rowCount = await page.locator('[data-opf-repeat-instance]').count();
const names = await page.locator('[data-opf-field="name"] [data-opf-repeat-instance] input').evaluateAll((inputs) => inputs.map((input) => input.name));
const checkboxNames = await page.locator('[data-opf-field="choices"] [data-opf-repeat-instance] input').evaluateAll((inputs) => inputs.map((input) => input.name));
const addDisabledAtMax = await page.locator('[data-opf-field="name"] .opf-field-repeat__add').isDisabled();
const conditionalVisible = await page.locator('[data-opf-field="conditional"]').isVisible();
await page.waitForFunction(() => document.querySelector('.opf-options-total').textContent === '$9.00');
const optionsTotal = await page.locator('.opf-options-total').textContent();
await page.locator('[data-opf-repeat-instance]').nth(0).locator('.opf-field-repeat__remove').click();
const remainingName = await page.locator('[data-opf-field="name"] [data-opf-repeat-instance] input').getAttribute('name');
const removeDisabledAtOne = await page.locator('[data-opf-field="name"] .opf-field-repeat__remove').isDisabled();
const ok = rowCount === 4 && names.join(',') === 'opf[1][name][0],opf[1][name][1]' && checkboxNames.join(',') === 'opf[1][choices][0][],opf[1][choices][0][],opf[1][choices][1][],opf[1][choices][1][]' && addDisabledAtMax && conditionalVisible && optionsTotal === '$9.00' && remainingName === 'opf[1][name][0]' && removeDisabledAtOne && errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} repeater add/remove, max, indexed text/checkbox names, conditional values, pricing, and browser errors`);
if (!ok) console.log(JSON.stringify({ rowCount, names, checkboxNames, addDisabledAtMax, conditionalVisible, optionsTotal, remainingName, removeDisabledAtOne, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
