// Actual installed WAPF Tools controls and WooCommerce HTTP checkout handlers.
import fs from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire( '/tmp/opf-disabled-commerce-proof/index.js' );
const { chromium } = require( 'playwright' );
const base = process.env.OPF_DISABLED_BASE_URL || 'http://127.0.0.1:8156';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback clone required.' );
const dir = process.env.OPF_DISABLED_ARTIFACT_DIR || '/tmp/opf-disabled-commerce-artifacts';
const state = JSON.parse( fs.readFileSync( dir + '/state.json' ) );
const phase = process.env.OPF_DISABLED_BROWSER_PHASE || 'tools';
const checks = [], errors = [];
const check = (label, pass) => { checks.push({label,pass:!!pass}); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); if(!pass) throw new Error(label); };
const browser = await chromium.launch();
try {
  if ( phase === 'tools' ) {
    const page = await browser.newPage();
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + '/?disabled_proof_login=1');
    await page.locator('a.wapf-import').click();
    await page.locator('.wapf-import-ta').fill(fs.readFileSync(dir + '/opf-tools-export.json','utf8'));
    await page.locator('.btn-wapf-import').click();
    await page.locator('.wapf-import-success').waitFor({state:'visible'});
    check('actual installed WAPF Tools import accepts OPF payload', true);
    await page.locator('.wapf_modal_overlay:visible .wapf_close').click();
    await page.screenshot({path:dir + '/wapf-after-import.png',fullPage:true});
    await page.locator('a.wapf-export').click();
    fs.writeFileSync(dir + '/wapf-tools-before-save.json',await page.locator('.wapf-export-ta').inputValue());
    await page.locator('.wapf_modal_overlay:visible .wapf_close').click();
    const post = page.waitForRequest(r => r.url().includes('/wp-admin/post.php') && r.method() === 'POST');
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#publish').click()]);
    const form = new URLSearchParams((await post).postData());
    const submitted = form.get('wapf-fields');
    fs.writeFileSync(dir + '/wapf-save-fields.json',submitted || 'null');
    check('actual WAPF save POST contains imported fields',!!submitted && JSON.parse(submitted).length === 2);
    await page.reload();
    await page.locator('a.wapf-export').click();
    const exported = await page.locator('.wapf-export-ta').inputValue();
    fs.writeFileSync(dir + '/wapf-tools-export.json',exported);
    const payload = JSON.parse(exported);
    check('WAPF reload then Tools export retains both field types', payload.fields.length === 2 && payload.fields[0].type === 'select' && payload.fields[1].type === 'checkboxes');
    check('WAPF Tools export distinguishes disabled and available choices',payload.fields.every(f => f.choices[0].disabled === true && f.choices[1].disabled === false));
    await page.screenshot({path:dir + '/wapf-tools-export.png',fullPage:true});
    check('WAPF Tools browser has no page errors', errors.length === 0);
  } else {
    const orders = {};
    for (const path of ['classic','store']) {
      const context = await browser.newContext();
      const page = await context.newPage();
      page.on('pageerror',e => errors.push(e.message));
      await page.goto(base + '/product/disabled-choice-commerce/');
      if (phase === 'order-again') {
        await page.goto(base + '/?disabled_proof_login=1');
        const originals = JSON.parse(fs.readFileSync(dir + '/commerce-order-ids.json'));
        await page.goto(base + '/my-account/view-order/' + originals[path] + '/');
        const againUrl = await page.locator('a[href*="order_again="]').getAttribute('href');
        check(`${path} actual account page exposes order-again action`,againUrl.startsWith(base));
        await context.request.get(againUrl);
        const cart = await (await context.request.get(base + '/wp-json/wc/store/v1/cart')).json();
        check(`${path} real Woo order-again restores one cart line`,cart.items.length === 1 && cart.items[0].quantity === 2);
        check(`${path} real Woo order-again retains available labels`,JSON.stringify(cart.items[0].item_data).includes('Available finish') && JSON.stringify(cart.items[0].item_data).includes('Available extras'));
        check(`${path} real Woo order-again retains base plus flat fees`,Number(cart.totals.total_price) / 10 ** cart.totals.currency_minor_unit === 23);
      }
      if(path === 'classic') {
        if (phase !== 'order-again') {
        const add = await context.request.post(base + '/product/disabled-choice-commerce/',{form:{'add-to-cart':String(state.product),quantity:'2',[`opf[${state.group}][finish]`]:'available-finish',[`opf[${state.group}][extras][]`]:'available-extras'}});
        check('classic add request succeeds',add.ok());
        }
        await page.goto(base + '/disabled-proof-checkout/');
        const nonce = await page.locator('[name="woocommerce-process-checkout-nonce"]').inputValue();
        const checkout = await context.request.post(base + '/?wc-ajax=checkout',{form:{'woocommerce-process-checkout-nonce':nonce,billing_first_name:'Test',billing_last_name:'Buyer',billing_company:'',billing_country:'US',billing_address_1:'1 Test Street',billing_address_2:'',billing_city:'Testville',billing_state:'CA',billing_postcode:'90210',billing_phone:'5551234567',billing_email:'disabled-classic@example.invalid',payment_method:'bacs',terms:'on'}});
        const body = await checkout.json();
        if(body.result !== 'success') console.log(JSON.stringify(body));
        check('classic actual wc-ajax checkout succeeds',body.result === 'success');
        orders[path] = Number(body.redirect.match(/order-received\/(\d+)/)?.[1]);
      } else {
        const initial = await context.request.get(base + '/wp-json/wc/store/v1/cart');
        const nonce = initial.headers().nonce;
        const add = phase === 'order-again' ? initial : await context.request.post(base + '/wp-json/wc/store/v1/cart/add-item',{headers:{Nonce:nonce},data:{id:state.product,quantity:2,opf_fields:{[state.group]:{finish:'available-finish',extras:['available-extras']}}}});
        const cart = await add.json();
        check('Store API available selection accepts real add request',add.ok() && cart.items.length === 1);
        fs.writeFileSync(dir + '/store-cart-price.json',JSON.stringify(cart.items[0].prices,null,2)+'\n');
        check('Store API unit price adds only available flat choice fees',Number(cart.items[0].prices.price) / 10 ** cart.items[0].prices.currency_minor_unit === 11.5);
        const checkout = await context.request.post(base + '/wp-json/wc/store/v1/checkout',{headers:{Nonce:add.headers().nonce || nonce},data:{payment_method:'bacs',billing_address:{first_name:'Test',last_name:'Buyer',email:'disabled-store@example.invalid',address_1:'1 Test Street',city:'Testville',postcode:'90210',country:'US',state:'CA',phone:'5551234567'}}});
        const body = await checkout.json();
        if(!checkout.ok()) console.log(JSON.stringify(body));
        check('Store API actual checkout succeeds',checkout.ok() && body.order_id > 0);
        orders[path] = body.order_id;
      }
      check(`${path} returns persisted order id`,orders[path] > 0);
      await context.close();
    }
    fs.writeFileSync(dir + (phase === 'order-again' ? '/order-again-order-ids.json' : '/commerce-order-ids.json'),JSON.stringify(orders,null,2)+'\n');
    check('commerce browser has no page errors',errors.length === 0);
  }
  fs.writeFileSync(`docs/compatibility/disabled-commerce-${phase}-results.json`,JSON.stringify({phase,checks,errors},null,2)+'\n');
} finally {
  fs.writeFileSync(`docs/compatibility/disabled-commerce-${phase}-results.json`,JSON.stringify({phase,checks,errors},null,2)+'\n');
  await browser.close();
}
