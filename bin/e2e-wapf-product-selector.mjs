// Browser/source UX comparison on an isolated loopback WAPF fixture, without production access.
import { createRequire } from 'node:module'; import fs from 'node:fs';
const require=createRequire(process.cwd()+'/index.js'); const {chromium}=require('playwright');
const base=process.env.OPF_BASE_URL||'http://127.0.0.1:8143';
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base))throw new Error('Disposable loopback only');
const dir=process.env.OPF_WAPF_UX_ARTIFACT_DIR||'/tmp/opf-product-wapf-ux-artifacts';fs.mkdirSync(dir,{recursive:true});
const checks=[],errors=[];const check=(label,pass)=>{checks.push({label,pass:!!pass});console.log(`${pass?'ok':'FAIL'} ${label}`);if(!pass)throw new Error(label);};
const b=await chromium.launch();const p=await b.newPage({viewport:{width:1280,height:960}});p.on('pageerror',e=>errors.push(e.message));
try{
 await p.goto(base+'/?opf_product_login=1',{waitUntil:'domcontentloaded'});
 await p.waitForFunction(()=>document.querySelector('.apf-conditions-wrapper')?.innerHTML.includes('tinybind: each-group'));
 await p.getByRole('link',{name:'Add your first rule',exact:true}).click();
 const row=p.locator('.wapf-rulegroup-0 .wapf-rulegroup-rule-0');
 await row.locator('td').nth(0).locator('select').selectOption('product');
 for(const condition of ['products','!products']){
  await row.locator('td').nth(1).locator('select').selectOption(condition);
  const search=row.locator('.select2-search__field'); await search.waitFor();
  const response=p.waitForResponse(r=>r.url().includes('admin-ajax.php')&&(r.url().includes('wapf_search_products')||(r.request().postData()||'').includes('wapf_search_products')));
  await search.click(); await search.fill(''); await search.pressSequentially('OPF targeting selected');
  const ajax=await response;check(`${condition} WAPF actual product-name AJAX search succeeds`,ajax.ok());
  await p.locator('.select2-results__option').filter({hasText:'OPF targeting selected'}).first().click();
  check(`${condition} WAPF selector stores numeric product ID and displays product name`,/^\d+$/.test(await row.locator('.wapf-select2 option:checked').getAttribute('value'))&&(await row.locator('.select2-selection__choice').innerText()).includes('OPF targeting selected'));
  await p.screenshot({path:dir+'/'+condition.replace('!','negative-')+'.png',fullPage:true});
 }
 check('no uncaught WAPF browser errors',errors.length===0);
}finally{fs.writeFileSync(dir+'/results.json',JSON.stringify({base,checks,errors},null,2));await b.close();}
