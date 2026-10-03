// Actual admin Media Library and product DOM. No media/fetch/REST stubs.
import {createRequire} from 'node:module';
import fs from 'node:fs';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const {chromium}=createRequire(process.cwd()+'/index.js')('playwright');
const base='http://127.0.0.1:8247', clone='/tmp/opf-image-admin-wp-20261003';
const out=process.env.OPF_IMAGE_ADMIN_OUT;
if (!out) throw new Error('Existing evidence directory required');
const state=JSON.parse(fs.readFileSync(out+'/state.json'));
const login=JSON.parse(fs.readFileSync(out+'/private-login.json'));
const fixture=path.join(path.dirname(fileURLToPath(import.meta.url)),'e2e-content-image-admin.php');
const phase=(name,stage='')=>execFileSync('wp',['--path='+clone,'eval-file',fixture],{env:{...process.env,OPF_IMAGE_ADMIN_ALLOW:'1',OPF_IMAGE_ADMIN_PHASE:name,OPF_IMAGE_ADMIN_STAGE:stage},encoding:'utf8'});
const checks=[], observations={}, network=[], errors=[];
const check=(label,pass)=>{checks.push({label,pass:!!pass}); if (!pass) throw new Error(label);};
const browser=await chromium.launch({headless:true});
const page=await browser.newPage({viewport:{width:1280,height:900}});
page.on('pageerror',e=>errors.push({message:e.message,page:page.url(),stack:e.stack||''}));
page.on('response',async r=>{
  if (!r.url().includes('/opf-admin-image-')) return;
  const bytes=await r.body().catch(()=>null);
  network.push({url:r.url(),status:r.status(),contentType:r.headers()['content-type'],sha256:bytes?crypto.createHash('sha256').update(bytes).digest('hex'):null});
});
const save=async()=>{
  const response=page.waitForResponse(r=>r.url().includes('/opf/v1/groups')&&r.request().method()==='POST');
  await page.getByRole('button',{name:'Save',exact:true}).click();
  const r=await response; check('actual authenticated OPF REST save HTTP 200',r.status()===200);
  await page.locator('#opf-b-status').filter({hasText:'Saved.'}).waitFor();
};
const selectMedia=async(name)=>{
  await page.getByRole('button',{name:'Choose image',exact:true}).click();
  const dialog=page.locator('.media-modal');
  await dialog.getByRole('tab',{name:'Media Library',exact:true}).click();
  const image=dialog.locator('.attachment[data-id="'+state.attachments[name].id+'"]');
  await image.waitFor(); await image.click();
  observations['media-'+name]=await dialog.ariaSnapshot();
  await page.screenshot({path:out+'/media-'+name+'.png',fullPage:true});
  await dialog.getByRole('button',{name:'Use image',exact:true}).click();
  check(name+' actual selected media URL reflected in editor',await page.getByRole('textbox',{name:'Content image URL'}).inputValue()===state.attachments[name].url);
};
try {
  await page.goto(base+'/wp-login.php'); await page.locator('#user_login').fill(login.user); await page.locator('#user_pass').fill(login.password);
  await Promise.all([page.waitForURL(/wp-admin/),page.locator('#wp-submit').click()]);
  await page.goto(base+'/wp-admin/post.php?post='+state.product+'&action=edit',{waitUntil:'networkidle'});
  await page.locator('.customfields_options a').click();
  const notice=await page.locator('#customfields_options').innerText();
  observations.referenceAdmin={notice,licenseGateObserved:notice.includes('activate your license')};
  check('real Extended admin license gate recorded',observations.referenceAdmin.licenseGateObserved);
  await page.screenshot({path:out+'/reference-admin-license-gate.png',fullPage:true});
  await page.goto(base+'/wp-admin/post.php?post='+state.group+'&action=edit',{waitUntil:'networkidle'});
  const initial=JSON.parse(await page.locator('#opf-builder-app').getAttribute('data-model'));
  check('real admin initially has only imported default controller',initial.fields.length===1&&initial.fields[0].default==='off');
  await page.getByRole('button',{name:'+ Add field',exact:true}).click();
  let card=page.locator('.opf-b-field').last();
  await card.locator('.opf-b-field-head select').selectOption('content_image');
  await card.locator('.opf-b-label').fill('Conditional image & "quoted"');
  await selectMedia('first');
  await card.getByRole('button',{name:'+ Add condition',exact:true}).click();
  await card.getByRole('combobox',{name:'Condition field'}).selectOption(initial.fields[0].id);
  await card.getByRole('combobox',{name:'Condition operator'}).selectOption('is');
  await card.getByRole('textbox',{name:'Condition value'}).fill('on');
  await save(); phase('snapshot','created');
  await page.reload({waitUntil:'networkidle'});
  let stored=JSON.parse(await page.locator('#opf-builder-app').getAttribute('data-model'));
  let img=stored.fields[1]; observations.created=img;
  check('new image attachment ID and URL persisted on actual reload',img.type==='content_image'&&img.image_id===state.attachments.first.id&&img.image_url===state.attachments.first.url);
  check('new image informative defaults persisted',img.required===false&&img.choices.length===0&&img.pricing.type==='none');
  check('new image show condition persisted',img.conditionals[0].action==='show'&&img.conditionals[0].rules[0].field===initial.fields[0].id&&img.conditionals[0].rules[0].value==='on');
  await selectMedia('second'); await save(); phase('snapshot','edited');
  await page.reload({waitUntil:'networkidle'});
  stored=JSON.parse(await page.locator('#opf-builder-app').getAttribute('data-model')); img=stored.fields[1]; observations.edited=img;
  check('replacement attachment ID and URL persisted on actual reload',img.image_id===state.attachments.second.id&&img.image_url===state.attachments.second.url);
  check('default controller unchanged after image edits',stored.fields[0].default==='off');
  await page.screenshot({path:out+'/opf-admin-reloaded.png',fullPage:true});
  // Direct URL edit intentionally clears attachment identity, then actual selection restores it.
  await page.getByRole('textbox',{name:'Content image URL'}).fill(state.attachments.second.url+'?direct=1'); await save(); phase('snapshot','direct-url');
  await page.reload({waitUntil:'networkidle'});
  img=JSON.parse(await page.locator('#opf-builder-app').getAttribute('data-model')).fields[1];
  check('direct URL edit clears stored attachment identity',!img.image_id&&img.image_url.endsWith('?direct=1'));
  await selectMedia('second'); await save(); phase('native-conditional'); phase('snapshot','final');
  for (const width of [1280,390]) {
    await page.setViewportSize({width,height:900});
    await page.goto(base+'/?page_id='+state.page,{waitUntil:'networkidle'});
    const opf=page.locator('[data-opf-field="'+img.id+'"]');
    const wapf=page.locator('.wapf-field-container.wapf-field-img');
    const oGate=page.locator('[data-opf-field="'+initial.fields[0].id+'"] input');
    const wGate=page.locator('.wapf input[data-field-id="image_gate"]');
    check(width+' both controllers use stored off default',await oGate.inputValue()==='off'&&await wGate.inputValue()==='off');
    check(width+' both informative images initially hidden',!(await opf.isVisible())&&!(await wapf.isVisible()));
    await oGate.fill('on'); await wGate.fill('on'); await wGate.press('Tab');
    await opf.waitFor({state:'visible'}); await wapf.waitFor({state:'visible'});
    check(width+' matching value shows both real product image wrappers',await opf.isVisible()&&await wapf.isVisible());
    const images={};
    for (const [name,container] of [['opf',opf],['wapf',wapf]]) {
      const image=container.locator('img'); await image.scrollIntoViewIfNeeded();
      await image.evaluate(el=>el.complete&&el.naturalWidth>0?true:new Promise((resolve,reject)=>{el.addEventListener('load',resolve,{once:true});el.addEventListener('error',reject,{once:true});}));
      images[name]=await image.evaluate(el=>({src:el.src,currentSrc:el.currentSrc,srcset:el.srcset,alt:el.getAttribute('alt'),width:el.getBoundingClientRect().width,naturalWidth:el.naturalWidth,html:el.outerHTML}));
      check(width+' '+name+' real image decoded',images[name].naturalWidth>0);
    }
    check(width+' selected image URL and WordPress srcset agree',images.opf.src===images.wapf.src&&images.opf.srcset===images.wapf.srcset);
    check(width+' OPF informative image stays inside viewport',images.opf.width<=width);
    check(width+' OPF label and native Media Library alt semantics recorded',images.opf.alt==='Conditional image & "quoted"'&&images.wapf.alt===state.attachments.second.alt);
    check(width+' image wrappers create no submitted controls',await opf.locator('input,select,textarea').count()===0&&await wapf.locator('input,select,textarea').count()===0);
    observations[width]={images,a11y:await opf.ariaSnapshot()};
    await page.screenshot({path:out+'/conditional-visible-'+width+'.png',fullPage:true});
    await oGate.fill('off'); await wGate.fill('off'); await wGate.press('Tab');
    await opf.waitFor({state:'hidden'}); await wapf.waitFor({state:'hidden'});
    check(width+' nonmatching value hides both image wrappers again',!(await opf.isVisible())&&!(await wapf.isVisible()));
    observations[width].hiddenA11y=await opf.ariaSnapshot();
    await page.screenshot({path:out+'/conditional-hidden-'+width+'.png',fullPage:true});
    await page.reload({waitUntil:'networkidle'});
    check(width+' reload restores controller defaults and hidden images',await oGate.inputValue()==='off'&&await wGate.inputValue()==='off'&&!(await opf.isVisible())&&!(await wapf.isVisible()));
  }
  phase('alt-update');
  await page.goto(base+'/?page_id='+state.page,{waitUntil:'networkidle'});
  await page.locator('[data-opf-field="'+initial.fields[0].id+'"] input').fill('on');
  await page.locator('.wapf input[data-field-id="image_gate"]').fill('on');
  const native=page.locator('.wapf-field-img img'), image=page.locator('[data-opf-field="'+img.id+'"] img');
  check('updated Media Library alt reaches reference while OPF keeps field label',await native.getAttribute('alt')==='Updated Media alt & "quoted"'&&await image.getAttribute('alt')==='Conditional image & "quoted"');
  for (const width of [1280,390]) for (const name of ['wapf','opf']) {
    const current=observations[width].images[name].currentSrc;
    check(width+' '+name+' browser-selected bytes match owned Media Library file',network.some(n=>n.url===current&&n.status===200&&n.contentType==='image/png'&&n.sha256===state.asset_sha256[current]));
  }
  observations.referenceJsErrors=errors.filter(e=>/advanced-product-fields/.test(e.stack));
  check('no uncaught JavaScript exceptions on OPF-served surfaces',errors.filter(e=>!/advanced-product-fields/.test(e.stack)).length===0);
} finally {
  await browser.close();
  fs.writeFileSync(out+'/browser-results.json',JSON.stringify({time:new Date().toISOString(),base,checks,observations,network,errors},null,2));
}
console.log(JSON.stringify({passed:checks.filter(c=>c.pass).length,total:checks.length}));
