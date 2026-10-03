import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import crypto from 'node:crypto';
const runtime = '/tmp/opf-image-formula-len-wp';
const base = 'http://127.0.0.1:8276';
const out = process.env.OPF_LEN_OUT;
if (process.env.OPF_LEN_ALLOW !== '1' || !out || !fs.existsSync(out) || fs.realpathSync(runtime) !== runtime || fs.realpathSync(runtime + '/wp-content/database/.ht.sqlite') !== runtime + '/wp-content/database/.ht.sqlite') throw new Error('Explicit owned LEN SQLite clone required');
const { chromium } = createRequire(process.env.OPF_LEN_PLAYWRIGHT || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json')('playwright');
const state = JSON.parse(fs.readFileSync(out + '/state.json'));
const wp = code => execFileSync('wp', ['--path=' + runtime, 'eval', code], {encoding:'utf8', maxBuffer:8*1024*1024}).trim();
const activate = provider => execFileSync('wp', ['--path=' + runtime, '--skip-plugins', '--skip-themes', 'option', 'update', 'active_plugins', JSON.stringify(['sqlite-database-integration/load.php','woocommerce/woocommerce.php',provider === 'wapf' ? 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' : 'open-product-fields-for-woocommerce/open-product-fields-for-woocommerce.php']), '--format=json'], {encoding:'utf8'});
const cases = [
  {name:'ascii-plain', text:'a quick brown fox', fee:'text', qty:1},
  {name:'ascii-strip-q3', text:'a quick brown fox', fee:'strip', qty:3},
  {name:'emoji', text:'A😀B', fee:'text', qty:1},
  {name:'combining', text:'Ae\u0301B', fee:'text', qty:1},
  {name:'nbsp-strip', text:'A\u00a0B', fee:'strip', qty:1},
  {name:'emspace-strip', text:'A\u2003B', fee:'strip', qty:1},
  {name:'bom-strip', text:'A\ufeffB', fee:'strip', qty:1},
  {name:'nel-strip', text:'A\u0085B', fee:'strip', qty:1},
  {name:'zero', text:'0', fee:'text', qty:1},
  {name:'uppercase-TRUE', text:'a quick brown fox', fee:'upper', qty:1},
  {name:'choice-label-q3', text:'unused', fee:'choice', qty:3},
  {name:'literal-q3', text:'unused', fee:'literal', qty:3},
];
const results = {utc:new Date().toISOString(),runtime:state.runtime,cases:[],errors:[]};
const write = () => fs.writeFileSync(out + '/browser-commerce-results.json', JSON.stringify(results,null,2));
const browser = await chromium.launch({executablePath:process.env.OPF_LEN_CHROMIUM || '/home/followersya-5hqi7/.cache/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-linux64/chrome-headless-shell'});
try {
 for (const provider of ['wapf','opf']) {
  activate(provider);
  for (const c of cases) {
   const row = {provider,...c,pageErrors:[],consoleErrors:[]}; results.cases.push(row); write();
   const context = await browser.newContext(); const page = await context.newPage();
   page.on('pageerror', e => row.pageErrors.push(e.message));
   page.on('console', m => { if(m.type()==='error') row.consoleErrors.push(m.text()); });
   try {
    const response = await page.goto(base+'/?page_id='+state.host,{waitUntil:'networkidle'});
    row.http_status = response.status();
    const jsPath = provider==='wapf' ? '/advanced-product-fields-for-woocommerce-extended/assets/js/frontend.min.js' : '/open-product-fields-for-woocommerce/assets/js/opf-frontend.js';
    const script = await context.request.get(base+'/wp-content/plugins'+jsPath); row.served_js_sha256=crypto.createHash('sha256').update(await script.body()).digest('hex');
    await page.locator(provider==='wapf' ? 'input[name="wapf[field_TextID]"]' : '#opf-'+state.group+'-text').fill(c.text);
    await page.locator(provider==='wapf' ? 'select[name="wapf[field_SourceID]"]' : '#opf-'+state.group+'-source').selectOption('zz');
    await page.locator(provider==='wapf' ? 'select[name="wapf[field_FeeID]"]' : '#opf-'+state.group+'-fee').selectOption(c.fee);
    await page.locator('input.qty').fill(String(c.qty)); await page.locator('input.qty').dispatchEvent('change');
    await page.waitForTimeout(300);
    row.preview=await page.locator(provider==='wapf' ? '.wapf-product-totals' : '.opf-product-totals').innerText();
    if (['emoji','choice-label-q3','nbsp-strip'].includes(c.name)) await page.screenshot({path:out+'/'+provider+'-'+c.name+'.png',fullPage:true});
    await Promise.all([page.waitForNavigation({waitUntil:'networkidle'}), page.locator('button[name="add-to-cart"]').click()]);
    await page.goto(base+'/?page_id='+state.cart,{waitUntil:'networkidle'});
    row.cart_rendered=await page.locator('.woocommerce-cart-form').innerText();
    const cartResponse=await context.request.get(base+'/?rest_route=/wc/store/v1/cart'); const cart=await cartResponse.json();
    if(cartResponse.status()!==200 || cart.items?.length!==1) throw new Error('Expected one real cart line: '+JSON.stringify(cart));
    row.cart={quantity:cart.items[0].quantity,unit_price:cart.items[0].prices.price,line_total:cart.items[0].totals.line_total,total:cart.totals.total_price,item_data:cart.items[0].item_data};
    await page.goto(base+'/?page_id='+state.checkout,{waitUntil:'networkidle'});
    await page.locator('#billing_first_name').fill('Length'); await page.locator('#billing_last_name').fill('Proof');
    await page.locator('#billing_address_1').fill('123 Proof Street'); await page.locator('#billing_city').fill('San Francisco');
    await page.locator('#billing_postcode').fill('94103'); await page.locator('#billing_phone').fill('5551234567');
    await page.locator('#billing_email').fill('len-proof@example.test'); await page.locator('#billing_state').selectOption('CA');
    if(await page.locator('#payment_method_cod').count()) await page.locator('#payment_method_cod').check();
    await page.waitForTimeout(300);
    row.checkout_rendered=await page.locator('#order_review').innerText();
    let resolveCheckout;
    const checkoutResponse=new Promise(resolve=>{resolveCheckout=resolve;});
    await page.route('**/*wc-ajax=checkout*',async route=>{
     const response=await route.fetch(); const data=await response.json();
     await route.fulfill({response}); resolveCheckout(data);
    });
    await page.locator('#place_order').click();
    const checkout=await Promise.race([checkoutResponse,new Promise((_,reject)=>setTimeout(()=>reject(new Error('Checkout response timeout')),20000))]);
    if(checkout.result!=='success') throw new Error('Actual checkout failed: '+JSON.stringify(checkout));
    row.checkout_result=checkout.result;
    const orderId=Number(new URL(checkout.redirect).searchParams.get('order-received') || checkout.redirect.match(/order-received\/(\d+)/)?.[1]);
    if(!orderId) throw new Error('Missing checkout generated order');
    row.order=JSON.parse(wp('$o=wc_get_order('+orderId+');$i=array_values($o->get_items())[0];$m=[];foreach($i->get_meta_data() as $d){$m[]=$d->get_data();}echo wp_json_encode(["id"=>$o->get_id(),"quantity"=>$i->get_quantity(),"line_total"=>$i->get_total(),"order_total"=>$o->get_total(),"metadata"=>$m]);'));
    wp('$o=wc_get_order('+orderId+');if($o){$o->delete(true);}');
    console.log('OBSERVED '+provider+' '+c.name+' cart='+row.cart.line_total+' order='+row.order.line_total+' preview='+row.preview.replaceAll('\n',' '));
   } catch(e) { row.failure=e.stack; results.errors.push(provider+' '+c.name+': '+e.message); write(); throw e; }
   finally { await context.close(); write(); }
  }
 }
} finally { await browser.close(); write(); }
console.log('Recorded '+results.cases.length+' real browser/cart/classic-checkout/order cases.');
