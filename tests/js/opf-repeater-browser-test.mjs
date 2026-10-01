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
        <div class="opf-field-repeat__rows"><div data-opf-repeat-instance="1"><div class="opf-field-label"><span>Name</span></div><input id="opf-1-name-repeat-0" data-field-id="name-repeat-0" name="opf[1][name][0]" value="" /></div></div>
        <button type="button" class="opf-field-repeat__add">Add guest</button><span class="opf-field-repeat__status" aria-live="polite"></span>
      </div>
      <div class="opf-field-container opf-field-repeat" data-opf-field="choices" data-opf-repeat="button" data-opf-repeat-max="2">
        <div class="opf-field-repeat__rows"><div data-opf-repeat-instance="1"><input type="checkbox" name="opf[1][choices][0][]" value="oak" /><input type="checkbox" name="opf[1][choices][0][]" value="blue" /></div></div>
        <button type="button" class="opf-field-repeat__add">Add another</button><span class="opf-field-repeat__status" aria-live="polite"></span>
      </div>
      <div class="opf-field-container opf-field-repeat" data-opf-field="ticket" data-opf-repeat="quantity">
        <div class="opf-field-repeat__rows"><div data-opf-repeat-instance="1"><div class="opf-field-label"><span>Ticket name</span></div><input id="opf-1-ticket-repeat-0" name="opf[1][ticket][0]" value="" /></div></div>
      </div>
      <div class="opf-field-container opf-field-repeat opf-section-repeat" data-opf-field="attendees" data-opf-repeat="quantity" data-opf-section-repeat="1">
        <div class="opf-field-repeat__rows"><div data-opf-repeat-instance="1"><div class="opf-section-repeat__label"><span>Attendees</span></div><div class="opf-section"><div class="opf-field-container" data-opf-field="guest_name"><div class="opf-field-label"><label for="opf-1-guest_name"><span>Name</span></label></div><input id="opf-1-guest_name" name="opf[1][guest_name][0]" value="" /></div></div></div></div>
      </div>
      <div data-opf-field="conditional"><input name="opf[1][conditional]" value="" /></div>
    </div>
  </div>
  <div class="opf-product-totals" data-product-price="10"><div class="opf--inner"><span class="opf-product-total"></span><span class="opf-options-total"></span><span class="opf-grand-total"></span></div></div>
  <form class="cart"><input name="quantity" value="1" /></form>
</body></html>`);
await page.evaluate(() => {
  window.OPF_FIELDS = { 1: {
    name: { type: 'text', repeat: { enabled: true, mode: 'button', max: 2, add: 'Add guest', del: 'Remove guest', label: 'Guest {n}' }, conditionals: [], pricing: { type: 'fixed', amount: 2 } },
    choices: { type: 'checkbox', multiple: true, repeat: { enabled: true, mode: 'button', max: 2 }, conditionals: [], pricing: { type: 'none', amount: 0 }, choices: [ { slug: 'oak', pricing: { type: 'fixed', amount: 2 } }, { slug: 'blue', pricing: { type: 'fixed', amount: 3 } } ] },
    ticket: { type: 'text', repeat: { enabled: true, mode: 'quantity', label: 'Ticket {n}' }, conditionals: [], pricing: { type: 'fixed', amount: 1 } },
    attendees: { type: 'section', repeat: { enabled: true, mode: 'quantity', label: 'Guest {n}' }, conditionals: [] },
    guest_name: { type: 'text', conditionals: [], pricing: { type: 'none', amount: 0 } },
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
const quantityInput = page.locator('form.cart input[name="quantity"]');
await quantityInput.fill('3');
await quantityInput.dispatchEvent('change');
const sectionInputsAtThree = page.locator('[data-opf-field="attendees"] [data-opf-repeat-instance] input');
await sectionInputsAtThree.nth(0).fill('Sam');
await sectionInputsAtThree.nth(1).fill('Lee');
await sectionInputsAtThree.nth(2).fill('Jo');
const sectionNamesAtThree = await sectionInputsAtThree.evaluateAll((inputs) => inputs.map((input) => input.name));
const sectionIdsAtThree = await sectionInputsAtThree.evaluateAll((inputs) => inputs.map((input) => input.id));
const sectionLabelsAtThree = await page.locator('[data-opf-field="attendees"] .opf-section-repeat__label span').allTextContents();
await page.locator('[data-opf-field="ticket"] [data-opf-repeat-instance]').nth(0).locator('input').fill('Alice');
await page.locator('[data-opf-field="ticket"] [data-opf-repeat-instance]').nth(1).locator('input').fill('Bob');
await page.locator('[data-opf-field="ticket"] [data-opf-repeat-instance]').nth(2).locator('input').fill('Cara');
const ticketNamesAtThree = await page.locator('[data-opf-field="ticket"] [data-opf-repeat-instance] input').evaluateAll((inputs) => inputs.map((input) => input.name));
const ticketLabelsAtThree = await page.locator('[data-opf-field="ticket"] [data-opf-repeat-instance] .opf-field-label span').allTextContents();
await quantityInput.fill('2');
await quantityInput.dispatchEvent('change');
const ticketValuesAtTwo = await page.locator('[data-opf-field="ticket"] [data-opf-repeat-instance] input').evaluateAll((inputs) => inputs.map((input) => input.value));
const sectionValuesAtTwo = await page.locator('[data-opf-field="attendees"] [data-opf-repeat-instance] input').evaluateAll((inputs) => inputs.map((input) => input.value));
const rowCounts = {
  names: await page.locator('[data-opf-field="name"] [data-opf-repeat-instance]').count(),
  choices: await page.locator('[data-opf-field="choices"] [data-opf-repeat-instance]').count(),
  tickets: await page.locator('[data-opf-field="ticket"] [data-opf-repeat-instance]').count(),
  sections: await page.locator('[data-opf-field="attendees"] [data-opf-repeat-instance]').count(),
};
const names = await page.locator('[data-opf-field="name"] [data-opf-repeat-instance] input').evaluateAll((inputs) => inputs.map((input) => input.name));
const checkboxNames = await page.locator('[data-opf-field="choices"] [data-opf-repeat-instance] input').evaluateAll((inputs) => inputs.map((input) => input.name));
const addDisabledAtMax = await page.locator('[data-opf-field="name"] .opf-field-repeat__add').isDisabled();
const conditionalVisible = await page.locator('[data-opf-field="conditional"]').isVisible();
await page.waitForFunction(() => document.querySelector('.opf-options-total').textContent === '$11.00');
const optionsTotal = await page.locator('.opf-options-total').textContent();
const rowLabels = await page.locator('[data-opf-field="name"] [data-opf-repeat-instance] .opf-field-label span').allTextContents();
await page.locator('[data-opf-repeat-instance]').nth(0).locator('.opf-field-repeat__remove').click();
const remainingName = await page.locator('[data-opf-field="name"] [data-opf-repeat-instance] input').getAttribute('name');
const removeDisabledAtOne = await page.locator('[data-opf-field="name"] .opf-field-repeat__remove').isDisabled();
const addLabel = await page.locator('[data-opf-field="name"] .opf-field-repeat__add').textContent();
const removeLabel = await page.locator('[data-opf-field="name"] .opf-field-repeat__remove').last().textContent();
const ok = rowCounts.names === 2 && rowCounts.choices === 2 && rowCounts.tickets === 2 && rowCounts.sections === 2 && names.join(',') === 'opf[1][name][0],opf[1][name][1]' && checkboxNames.join(',') === 'opf[1][choices][0][],opf[1][choices][0][],opf[1][choices][1][],opf[1][choices][1][]' && ticketNamesAtThree.join(',') === 'opf[1][ticket][0],opf[1][ticket][1],opf[1][ticket][2]' && ticketLabelsAtThree.join(',') === 'Ticket name,Ticket 2,Ticket 3' && ticketValuesAtTwo.join(',') === 'Alice,Bob' && sectionNamesAtThree.join(',') === 'opf[1][guest_name][0],opf[1][guest_name][1],opf[1][guest_name][2]' && new Set(sectionIdsAtThree).size === 3 && sectionLabelsAtThree.join(',') === 'Attendees,Guest 2,Guest 3' && sectionValuesAtTwo.join(',') === 'Sam,Lee' && addDisabledAtMax && conditionalVisible && optionsTotal === '$11.00' && remainingName === 'opf[1][name][0]' && removeDisabledAtOne && addLabel === 'Add guest' && removeLabel === 'Remove guest' && rowLabels.join(',') === 'Name,Guest 2' && errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} button, field-quantity, and section-quantity repeaters; indexed names, clone labels, preserved values, and browser errors`);
if (!ok) console.log(JSON.stringify({ rowCounts, names, checkboxNames, ticketNamesAtThree, ticketLabelsAtThree, ticketValuesAtTwo, sectionNamesAtThree, sectionIdsAtThree, sectionLabelsAtThree, sectionValuesAtTwo, addDisabledAtMax, conditionalVisible, optionsTotal, remainingName, removeDisabledAtOne, addLabel, removeLabel, rowLabels, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
