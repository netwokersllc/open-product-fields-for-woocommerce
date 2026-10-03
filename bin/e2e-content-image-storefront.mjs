import { createRequire } from 'node:module';
import fs from 'node:fs';
import crypto from 'node:crypto';
const { chromium } = createRequire(process.cwd() + '/index.js')('playwright');
const base = process.env.OPF_IMAGE_BASE || 'http://127.0.0.1:8241';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Loopback clone only');
const out = process.env.OPF_IMAGE_OUT;
const state = JSON.parse(fs.readFileSync(out + '/state.json', 'utf8'));
const checks = [], observations = {}, network = [];
const check = (label, pass) => checks.push({label, pass: !!pass});
const browser = await chromium.launch({headless:true});
try {
  for (const width of [1280, 390]) {
    const page = await browser.newPage({viewport:{width, height:900}});
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('response', async r => {
      if (r.url().includes('opf-informative-image-proof')) {
        const body = await r.body().catch(()=>null);
        network.push({width,url:r.url(),status:r.status(),contentType:r.headers()['content-type'],sha256:body ? crypto.createHash('sha256').update(body).digest('hex'):null});
      }
    });
    await page.goto(base + '/?page_id=' + state.page, {waitUntil:'networkidle'});
    const record = {};
    for (const [index, name] of ['url','attachment','hostile','quoted'].entries()) {
      const w = page.locator('.wapf [data-field-id="opi_'+name+'"]');
      const container = page.locator('[data-opf-field="'+state.ids[index]+'"]');
      const o = container.locator('img');
      if (await o.count()) { await o.scrollIntoViewIfNeeded(); await page.waitForFunction(id => {const el=document.querySelector('[data-opf-field="'+id+'"] img');return el && el.complete && el.naturalWidth>0;}, state.ids[index]); }
      if (['url','attachment'].includes(name)) { await w.scrollIntoViewIfNeeded(); }
      const inspect = el => ({html:el.outerHTML,src:el.getAttribute('src'),currentSrc:el.currentSrc,srcset:el.getAttribute('srcset'),sizes:el.getAttribute('sizes'),alt:el.getAttribute('alt'),loading:el.getAttribute('loading'),decoding:el.getAttribute('decoding'),naturalWidth:el.naturalWidth,naturalHeight:el.naturalHeight,width:el.getBoundingClientRect().width,onerror:el.getAttribute('onerror')});
      record[name] = {wapf:await w.evaluate(inspect), opf:await o.count()?await o.evaluate(inspect):null};
      if (['url','attachment'].includes(name)) {
        check(width+' '+name+' source URL matches',record[name].wapf.src===record[name].opf.src);
        check(width+' '+name+' loaded real PNG',record[name].opf.naturalWidth>0 && record[name].wapf.naturalWidth>0);
        check(width+' '+name+' responsive image stays inside viewport',record[name].opf.width<=width);
        check(width+' '+name+' OPF has escaped accessible alt',record[name].opf.alt==='Image label & "quoted" <b>text</b>' && !record[name].opf.onerror);
        check(width+' '+name+' OPF lazy async loading',record[name].opf.loading==='lazy' && record[name].opf.decoding==='async');
      } else {
        check(width+' '+name+' unsafe import is omitted by OPF',record[name].opf===null);
        check(width+' '+name+' WAPF raw stored URL exposes event attribute',!!record[name].wapf.onerror);
      }
    }
    check(width+' attachment srcset matches WordPress reference',record.attachment.wapf.srcset===record.attachment.opf.srcset);
    check(width+' informative images create no submitted OPF controls',(await Promise.all(state.ids.map(id => page.locator('[data-opf-field="'+id+'"] input, [data-opf-field="'+id+'"] select, [data-opf-field="'+id+'"] textarea').count()))).every(n=>n===0));
    check(width+' no uncaught browser error',errors.length===0);
    record.accessibility = await page.locator('[data-opf-field="'+state.ids[1]+'"]').ariaSnapshot();
    record.errors = errors;
    observations[width]=record;
    await page.screenshot({path:out+'/storefront-'+width+'.png',fullPage:true});
    await page.close();
  }
  check('loaded URL PNG response bytes match actual Media Library file',network.some(r=>r.url.includes('probe=one') && r.status===200 && r.sha256===state.source_sha256 && r.contentType==='image/png'));
  for (const width of [1280,390]) {
    for (const plugin of ['wapf','opf']) {
      const selected = observations[width].attachment[plugin].currentSrc;
      check(width+' '+plugin+' selected attachment PNG response bytes match Media Library asset',network.some(r=>r.width===width && r.url===selected && r.status===200 && r.sha256===state.asset_sha256[selected] && r.contentType==='image/png'));
    }
  }
} finally {
  await browser.close();
  fs.writeFileSync(out+'/browser-results.json',JSON.stringify({time:new Date().toISOString(),base,checks,observations,network},null,2));
}
console.log(JSON.stringify(checks,null,2));
if (checks.some(c=>!c.pass)) process.exitCode=1;
