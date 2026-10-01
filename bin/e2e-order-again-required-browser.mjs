// Real Chromium, WooCommerce requests and authenticated account order again.
import fs from 'node:fs';
import { createRequire } from 'node:module';
const { chromium } = createRequire('/tmp/jsdom-test/index.js')('playwright');
const base = 'http://127.0.0.1:8173';
const dir = '/tmp/opf-order-again-required-artifacts';
if (process.env.OPF_ORDER_AGAIN_E2E_ALLOW !== '1') throw new Error('Explicit fixture guard required.');
const state = JSON.parse(fs.readFileSync(dir + '/state.json'));
const phase = process.env.OPF_ORDER_AGAIN_PHASE || 'fresh';
const browser = await chromium.launch();
const checks = [], errors = [], orders = {};
function check(label, pass) { checks.push({label,pass:!!pass}); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); if (!pass) throw new Error(label); }
try {
 for (const type of ['simple','variation']) for (const method of ['classic','store']) {
  const key = `${type}-${method}`;
  const context = await browser.newContext();
  const page = await context.newPage();
  page.on('pageerror', e => errors.push(e.message));
  const productId = type === 'simple' ? state.simple : state.parent;
  const variationId = type === 'variation' ? state.variation : 0;
  const url = base + `/product/opf-order-again-required-${type === 'simple' ? 'choices' : 'variation'}/`;
  await page.goto(url);
  let cartResponse = await context.request.get(base + '/wp-json/wc/store/v1/cart');
  if (phase === 'fresh') {
   for (const field of ['finish','style']) {
    const values = {[state.group]:{finish:'matte',style:'gloss',[field]:'unknown'}};
    if (method === 'classic') {
     const response = await context.request.post(url,{form:{'add-to-cart':String(productId),product_id:String(productId),variation_id:String(variationId),attribute_size:'Small',quantity:'2',[`opf[${state.group}][finish]`]:values[state.group].finish,[`opf[${state.group}][style]`]:values[state.group].style}});
     check(`${key} invalid ${field} rejected by classic server`, (await response.text()).includes('is a required field'));
    } else {
     const response = await context.request.post(base + '/wp-json/wc/store/v1/cart/add-item',{headers:{Nonce:cartResponse.headers().nonce},data:{id:variationId || productId,quantity:2,opf_fields:values}});
     check(`${key} invalid ${field} rejected by Store API`, response.status() >= 400);
    }
    cartResponse = await context.request.get(base + '/wp-json/wc/store/v1/cart');
    check(`${key} invalid ${field} leaves cart empty`, (await cartResponse.json()).items.length === 0);
   }
   if (method === 'classic') {
    await context.request.post(url,{form:{'add-to-cart':String(productId),product_id:String(productId),variation_id:String(variationId),attribute_size:'Small',quantity:'2',[`opf[${state.group}][finish]`]:'matte',[`opf[${state.group}][style]`]:'gloss'}});
    cartResponse = await context.request.get(base + '/wp-json/wc/store/v1/cart');
   } else {
    cartResponse = await context.request.post(base + '/wp-json/wc/store/v1/cart/add-item',{headers:{Nonce:cartResponse.headers().nonce},data:{id:variationId || productId,quantity:2,opf_fields:{[state.group]:{finish:'matte',style:'gloss'}}}});
    check(`${key} valid Store API add succeeds`, cartResponse.ok());
   }
  } else {
   await page.goto(base + '/?opf_order_again_login=1');
   const originals = JSON.parse(fs.readFileSync(dir + '/fresh-orders.json'));
   await page.goto(base + '/my-account/view-order/' + originals[key] + '/');
   const action = page.getByRole('link',{name:'Order again',exact:true});
   check(`${key} authenticated account exposes actual order-again link`, await action.count() === 1);
   await action.click();
   await page.waitForLoadState('domcontentloaded');
   cartResponse = await context.request.get(base + '/wp-json/wc/store/v1/cart');
  }
  const cart = await cartResponse.json();
  fs.writeFileSync(dir + `/${key}-${phase}-cart.json`,JSON.stringify(cart,null,2));
  if (phase === 'retired') {
   check(`${key} current disabled choice blocks actual order-again`, cart.items.length === 0);
   await context.close(); continue;
  }
  const expected = phase === 'fresh' ? (type === 'simple' ? 25 : 65) : (type === 'simple' ? 31 : 77);
  check(`${key} ${phase} required selections quantity and current prices preserved`, cart.items.length === 1 && cart.items[0].quantity === 2 && JSON.stringify(cart.items[0].item_data).includes('Matte finish') && JSON.stringify(cart.items[0].item_data).includes('Gloss style') && Number(cart.totals.total_price)/10**cart.totals.currency_minor_unit === expected);
  await page.goto(base + '/cart/');
  await page.screenshot({path:dir + `/${key}-${phase}-cart.png`,fullPage:true});
  if (method === 'classic') {
   await page.goto(base + '/order-again-checkout/');
   const nonce = await page.locator('[name="woocommerce-process-checkout-nonce"]').inputValue();
   const response = await context.request.post(base + '/?wc-ajax=checkout',{form:{'woocommerce-process-checkout-nonce':nonce,billing_first_name:'Test',billing_last_name:'Buyer',billing_country:'US',billing_address_1:'1 Test Street',billing_city:'Testville',billing_state:'CA',billing_postcode:'90210',billing_phone:'5551234567',billing_email:'order-again@example.invalid',payment_method:'bacs',terms:'on'}});
   const data = await response.json();
   check(`${key} ${phase} actual classic checkout succeeds`, data.result === 'success');
   orders[key] = Number(data.redirect.match(/order-received\/(\d+)/)?.[1]);
   await page.goto(data.redirect);
  } else {
   cartResponse = await context.request.get(base + '/wp-json/wc/store/v1/cart');
   const response = await context.request.post(base + '/wp-json/wc/store/v1/checkout',{headers:{Nonce:cartResponse.headers().nonce},data:{payment_method:'bacs',billing_address:{first_name:'Test',last_name:'Buyer',email:'order-again@example.invalid',address_1:'1 Test Street',city:'Testville',postcode:'90210',country:'US',state:'CA'}}});
   const data = await response.json();
   check(`${key} ${phase} actual Store API checkout succeeds`, response.ok() && data.order_id > 0);
   orders[key] = data.order_id;
   await page.goto(data.payment_result.redirect_url);
  }
  check(`${key} ${phase} order confirmation shows required selections`, (await page.textContent('body')).includes('Matte finish') && (await page.textContent('body')).includes('Gloss style'));
  await context.close();
 }
 check(`${phase} no uncaught browser errors`, errors.length === 0);
 if (phase !== 'retired') fs.writeFileSync(dir + `/${phase}-orders.json`,JSON.stringify(orders,null,2));
} finally {
 fs.writeFileSync(dir + `/${phase}-browser-results.json`,JSON.stringify({base,phase,checks,errors},null,2));
 await browser.close();
}
