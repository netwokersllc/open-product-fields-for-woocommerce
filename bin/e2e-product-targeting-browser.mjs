// Real isolated Chromium, WordPress admin REST, classic form, block cart/checkout.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8142';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only.');
const dir = process.env.OPF_PRODUCT_ARTIFACT_DIR || '/tmp/opf-product-artifacts';
fs.mkdirSync(dir, { recursive: true });
const state = JSON.parse(fs.readFileSync(process.env.OPF_PRODUCT_STATE_FILE || '/tmp/opf-product-state.json', 'utf8'));
const checks = [], errors = [];
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); if (!pass) throw new Error(label); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('pageerror', e => errors.push(e.message));
try {
 for (const key of ['positive', 'negative']) {
  await page.goto(base + '/?opf_product_login=1' + (key === 'negative' ? '&negative=1' : ''), { waitUntil: 'domcontentloaded' });
  await page.locator('.opf-b-field').first().waitFor();
  const expected = [{rules:[{subject:'product',operator:key === 'positive'?'in':'not_in',terms:[String(state.selected),String(state.parent)]}]}];
  const ids = page.locator(key === 'positive' ? '#opf-placement-products' : '#opf-placement-excluded-products');
  await ids.fill('invalid product');
  let saves = 0;
  const countSaves = r => { if (r.url().includes('/opf/v1/groups') && r.method() === 'POST') saves++; };
  page.on('request', countSaves);
  await page.getByRole('button', {name:'Save',exact:true}).click();
  check(`${key} invalid product IDs prevent admin submission`, await ids.evaluate(node => !node.validity.valid) && saves === 0);
  page.off('request', countSaves);
  await ids.fill('');
  const picker = page.locator(key === 'positive' ? '#opf-placement-products-picker' : '#opf-placement-excluded-products-picker');
  for (const productName of ['selected', 'parent']) {
   const search = picker.locator('+ .select2-container .select2-search__field');
   const searchResponse = page.waitForResponse(r=>r.url().includes('admin-ajax.php') && r.url().includes('action=woocommerce_json_search_products'));
   await search.click(); await search.fill(''); await search.pressSequentially('OPF targeting ' + productName);
   const ajax = await searchResponse;
   check(`${key} native product-name search returns actual Woo AJAX results for ${productName}`, ajax.ok() && Object.keys(await ajax.json()).includes(String(state[productName])));
   await page.locator('.select2-results__option').filter({hasText:'OPF targeting ' + productName}).first().click();
  }
  check(`${key} native product picker populates exact validated IDs`, await ids.inputValue() === `${state.selected}, ${state.parent}`);
  await picker.locator('+ .select2-container .select2-selection__choice').filter({hasText:'OPF targeting selected'}).locator('.select2-selection__choice__remove').click();
  check(`${key} native picker removes a selected product`, await ids.inputValue() === String(state.parent));
  await ids.fill(`${state.selected}, ${state.parent}, ${state.selected}`);
  await page.locator('.opf-b-label').fill('Saved targeting ' + key);
  const responsePromise = page.waitForResponse(r => r.url().includes('/opf/v1/groups') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  const response = await responsePromise, saved = await response.json();
  check(`${key} actual admin REST save retains exact targeting`, response.ok() && JSON.stringify(saved.data.rule_groups) === JSON.stringify(expected));
  await page.getByText('Saved.', { exact:true }).waitFor();
  await page.reload({ waitUntil:'domcontentloaded' });
  await page.locator('.opf-b-field').first().waitFor();
  const reload = JSON.parse(await page.locator('#opf-builder-app').getAttribute('data-model'));
  check(`${key} real admin reload retains exact targeting and edited label`, JSON.stringify(reload.rule_groups) === JSON.stringify(expected) && await page.locator('.opf-b-label').inputValue() === 'Saved targeting ' + key);
  check(`${key} product IDs reload in native authoring control`, await ids.inputValue() === `${state.selected}, ${state.parent}`);
  check(`${key} native picker reloads selected product names`, (await picker.locator('option:checked').allTextContents()).every(text=>text.includes('OPF targeting')) && await picker.locator('option:checked').count()===2);
  const unchangedPromise = page.waitForResponse(r=>r.url().includes('/opf/v1/groups') && r.request().method()==='POST');
  await page.getByRole('button',{name:'Save',exact:true}).click();
  check(`${key} unchanged native save preserves product rule`, JSON.stringify((await (await unchangedPromise).json()).data.rule_groups) === JSON.stringify(expected));
  if (key === 'positive') {
   const saveRules = async () => {
    const result = page.waitForResponse(r=>r.url().includes('/opf/v1/groups') && r.request().method()==='POST');
    await page.getByRole('button',{name:'Save',exact:true}).click();
    return (await (await result).json()).data.rule_groups;
   };
   await page.locator('#opf-placement-excluded-products').fill(String(state.unselected));
   const mixed = await saveRules();
   check('native include and exclude authoring persists both predicates', mixed[0].rules.length === 2 && mixed[0].rules[0].operator === 'in' && mixed[0].rules[1].operator === 'not_in' && mixed[0].rules[1].terms[0] === String(state.unselected));
   await ids.fill(''); const excludeOnly = await saveRules();
   check('clearing inclusion preserves exclusion', excludeOnly[0].rules.length === 1 && excludeOnly[0].rules[0].operator === 'not_in');
   await page.locator('#opf-placement-excluded-products').fill('');
   check('clearing both product conditions removes placement restriction', (await saveRules()).length === 0);
   await ids.fill(`${state.selected}, ${state.parent}`);
   check('restoring inclusion restores exact product rule', JSON.stringify(await saveRules()) === JSON.stringify(expected));
  }
  await page.screenshot({ path:dir + '/admin-' + key + '.png', fullPage:true });
 }
 for (const [key, match] of Object.entries({selected:'positive',unselected:'negative',parent:'positive',otherparent:'negative'})) {
  await page.goto(base + '/product/opf-targeting-' + key + '/', {waitUntil:'domcontentloaded'});
  await page.locator(`[data-opf-field="${match}"] input`).waitFor();
  const wrong = match === 'positive' ? 'negative' : 'positive';
  check(`${key} storefront renders only matching product group`, await page.locator(`[data-opf-field="${match}"] input`).count() === 1 && await page.locator(`[data-opf-field="${wrong}"]`).count() === 0);
  await page.screenshot({path:dir + '/product-' + key + '.png',fullPage:true});
 }
 // Real HTTP forgery: unmatched product group alone must not satisfy selected required input.
 const invalid = await page.request.post(base + '/product/opf-targeting-selected/', { form:{'add-to-cart':String(state.selected),quantity:'1',[`opf[${state.negative}][negative]`]:'Forged negative'} });
 check('classic HTTP rejects wrong group alone', (await invalid.text()).includes('is a required field'));
 check('classic HTTP rejection leaves cart empty', (await (await page.request.get(base + '/wp-json/wc/store/v1/cart')).json()).items.length === 0);
 // Browser submits each matching storefront form; cart/checkout contain both groups on their proper lines.
 for (const [key, match] of Object.entries({selected:'positive',unselected:'negative'})) {
  await page.goto(base + '/product/opf-targeting-' + key + '/', {waitUntil:'domcontentloaded'});
  await page.locator(`[data-opf-field="${match}"] input`).fill('Browser ' + key);
  const nav = page.waitForNavigation({waitUntil:'domcontentloaded'});
  await page.locator('button.single_add_to_cart_button').click(); await nav;
  check(`${key} browser classic form adds correct group`, (await (await page.request.get(base + '/wp-json/wc/store/v1/cart')).json()).items.some(item => item.id === state[key] && JSON.stringify(item.item_data).includes('Browser ' + key)));
 }
 await page.goto(base + '/cart/', {waitUntil:'domcontentloaded'});
 await page.getByText('Browser selected', {exact:true}).first().waitFor();
 await page.getByText('Browser unselected', {exact:true}).first().waitFor();
 check('real block cart displays both matched group labels and values', (await page.textContent('body')).includes('Saved targeting positive') && (await page.textContent('body')).includes('Saved targeting negative') && !(await page.textContent('body')).includes('Forged negative'));
 await page.screenshot({path:dir + '/cart.png',fullPage:true});
 await page.goto(base + '/checkout/', {waitUntil:'domcontentloaded'});
 await page.getByText('Browser selected', {exact:true}).first().waitFor();
 check('real block checkout displays selected and excluded-product selections', (await page.textContent('body')).includes('Browser unselected'));
 await page.getByLabel(/^email address$/i).first().fill('targeting@example.invalid');
 await page.getByLabel(/^first name$/i).first().fill('Targeting');
 await page.getByLabel(/^last name$/i).first().fill('Buyer');
 await page.getByLabel(/^address$/i).first().fill('1 Test Street');
 await page.getByLabel(/^city$/i).first().fill('Testville');
 const region = page.getByLabel(/^state$/i).first(); if (await region.count()) await region.selectOption('CA');
 await page.getByLabel(/^zip code$/i).first().fill('90210');
 await page.screenshot({path:dir + '/checkout.png',fullPage:true});
 const placedPromise = page.waitForResponse(r=>r.url().includes('/wc/store/v1/checkout') && r.request().method()==='POST');
 await page.getByRole('button',{name:/place order/i}).click();
 const placed = await placedPromise; check('actual browser Store API checkout succeeds',placed.ok());
 const order = await placed.json(); fs.writeFileSync(dir+'/browser-order.json',JSON.stringify({order_id:order.order_id},null,2));
 await page.waitForURL(/order-received/,{timeout:30000});
 check('browser order received renders both matching selections', (await page.textContent('body')).includes('Browser selected') && (await page.textContent('body')).includes('Browser unselected') && !(await page.textContent('body')).includes('Forged negative'));
 await page.screenshot({path:dir + '/order-received.png',fullPage:true});
 check('no uncaught browser errors',errors.length===0);
} finally { fs.writeFileSync(dir+'/browser-results.json',JSON.stringify({base,checks,errors},null,2)); await browser.close(); }
