// Focused real Chromium regression for clearing one of several placement OR branches.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8142';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only.');
const dir = '/tmp/opf-product-or-artifacts'; fs.mkdirSync(dir,{recursive:true});
const checks=[], errors=[];
const check=(label,pass)=>{checks.push({label,pass:!!pass});console.log(`${pass?'ok':'FAIL'} ${label}`);if(!pass)throw new Error(label);};
const browser=await chromium.launch(); const page=await browser.newPage({viewport:{width:1280,height:960}});
page.on('pageerror',error=>errors.push(error.message));
const save=async()=>{
 const result=page.waitForResponse(r=>r.url().includes('/opf/v1/groups')&&r.request().method()==='POST');
 await page.getByRole('button',{name:'Save',exact:true}).click();
 const response=await result; check('actual group save succeeds',response.ok()); return (await response.json()).data.rule_groups;
};
try {
 await page.goto(base+'/?opf_product_login=1&or=1',{waitUntil:'domcontentloaded'});
 await page.locator('.opf-b-field').first().waitFor();
 const initial=JSON.parse(await page.locator('#opf-builder-app').getAttribute('data-model')).rule_groups;
 check('fixture has product-only OR branch and separate category branch',initial.length===2&&initial[0].rules[0].subject==='product'&&initial[1].rules[0].subject==='product_cat');
 const remaining=[initial[1]];
 await page.locator('#opf-placement-products').fill('');
 check('clearing product-only OR branch preserves exact separate category branch',JSON.stringify(await save())===JSON.stringify(remaining));
 await page.getByText('Saved.',{exact:true}).waitFor(); await page.reload({waitUntil:'domcontentloaded'});
 await page.locator('.opf-b-field').first().waitFor();
 check('actual reload retains category restriction after clearing product branch',JSON.stringify(JSON.parse(await page.locator('#opf-builder-app').getAttribute('data-model')).rule_groups)===JSON.stringify(remaining));
 await page.screenshot({path:dir+'/remaining-category.png',fullPage:true});
 await page.locator('#opf-placement-cats').selectOption([]);
 check('placement becomes global only after every OR branch is empty',(await save()).length===0);
 await page.getByText('Saved.',{exact:true}).waitFor(); await page.reload({waitUntil:'domcontentloaded'});
 await page.locator('.opf-b-field').first().waitFor();
 check('actual reload preserves global placement after clearing all branches',JSON.parse(await page.locator('#opf-builder-app').getAttribute('data-model')).rule_groups.length===0);
 check('no uncaught browser errors',errors.length===0);
} finally { fs.writeFileSync(dir+'/results.json',JSON.stringify({base,checks,errors},null,2)); await browser.close(); }
