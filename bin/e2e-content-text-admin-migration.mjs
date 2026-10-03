import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const { chromium } = createRequire(process.cwd() + '/index.js')('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const wpPath = process.env.OPFCTM_WP;
const out = process.env.OPFCTM_OUT;
const base = process.env.OPFCTM_BASE || 'http://127.0.0.1:8244';
if (!wpPath?.startsWith('/tmp/') || !out?.startsWith('/tmp/') || !/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Independent /tmp clone and loopback required');
const readState = () => JSON.parse(fs.readFileSync(out + '/state.json', 'utf8'));
const wp = args => execFileSync('wp', ['--path=' + wpPath, ...args], { encoding: 'utf8', env: { ...process.env, OPFCTM_ALLOW: '1' } });
const phase = value => wp(['eval-file', root + '/bin/e2e-content-text-admin-migration.php']).trim();
const checks = [], errors = [], assets = {}, assetPromises = [], requests = [], responses = [];
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); if (!pass) throw new Error(label); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('pageerror', e => errors.push(e.message));
page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).origin === base) requests.push({ path: new URL(request.url()).pathname, method: 'POST' }); });
page.on('response', response => {
  const url = new URL(response.url());
  if (url.origin === base) {
    responses.push(response.body().then(body => ({ path: url.pathname, status: response.status(), sha256: createHash('sha256').update(body).digest('hex'), bytes: body.length })).catch(error => ({ path: url.pathname, status: response.status(), bodyUnavailable: error.message })));
  }
  if (/\/(opf-content-text-migration-lane\/assets\/js\/opf-builder\.js|advanced-product-fields-for-woocommerce\/assets\/js\/admin\.min\.js)(\?|$)/.test(response.url())) assetPromises.push(response.body().then(body => { assets[response.url()] = createHash('sha256').update(body).digest('hex'); }));
});
const state = readState();
const contents = [
  'Current edited &amp; &lt;tag&gt; &#169; "quotes" \'single\' & raw\nSecond\tline [opfctm_literal]',
  'Legacy edited ñ € &lt;legacy&gt; &amp;\nSecond\tline [opfctm_literal]',
];
try {
  await page.goto(base + '/wp-login.php', { waitUntil: 'networkidle' });
  await page.locator('#user_login').fill(state.login);
  await page.locator('#user_pass').fill(state.password);
  await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
  await page.goto(`${base}/wp-admin/post.php?post=${state.source}&action=edit`, { waitUntil: 'networkidle' });
  await page.locator('.wapf-field').first().waitFor();
  check('Free admin has both current and legacy-origin fields', await page.locator('.wapf-field').count() === 2);
  check('Free admin has no JSON Tools import/export controls', await page.locator('.wapf-export,.wapf-import,.wapf-export-ta,.wapf-import-ta').count() === 0 && await page.getByRole('button', { name: /^(Tools|Export|Import)$/ }).count() === 0);
  const raw = JSON.parse(await page.locator('input[name="wapf-fields"]').inputValue());
  check('Free admin canonicalizes both types to content', raw.every(field => field.type === 'content'));
  for (let index = 0; index < 2; index++) {
    const card = page.locator('.wapf-field').nth(index);
    await card.locator('.wapf-field__label').click();
    await card.locator('[data-setting="p_content"] textarea').fill(contents[index]);
    // Free's textarea binding uses keyup for its serialized form state.
    await card.locator('[data-setting="p_content"] textarea').press('End');
  }
  const save = page.waitForResponse(response => response.url() === base + '/wp-admin/post.php' && response.request().method() === 'POST');
  await page.locator('#publish').click();
  const response = await save;
  check('Free real classic admin form save redirects successfully', response.status() === 302);
  await page.waitForLoadState('networkidle');
  await page.reload({ waitUntil: 'networkidle' });
  const savedFree = JSON.parse(await page.locator('input[name="wapf-fields"]').inputValue());
  check('Free current content survives actual admin save/reload byte-for-byte', savedFree[0].p_content === contents[0]);
  check('Free legacy-origin content survives actual admin save/reload byte-for-byte', savedFree[1].p_content === contents[1]);
  await page.screenshot({ path: out + '/free-admin-reload.png', fullPage: true });
  process.env.OPFCTM_PHASE = 'migrate';
  console.log(phase('migrate'));
  const migrated = readState();
  check('Free model JSON imported into OPF preserves both p_content strings', migrated.free_saved.every((value, i) => value === contents[i]) && migrated.opf_imported.every((value, i) => value === contents[i]) && !migrated.needs_review);
  await page.goto(`${base}/wp-admin/post.php?post=${migrated.imported}&action=edit`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: out + '/opf-admin-before-display-assertion.png', fullPage: true });
  const paragraphs = page.getByRole('textbox', { name: 'Paragraph content', exact: true });
  const displayActual = await page.evaluate(() => {
    const selectors = ['#opf-builder', '[data-opf-builder]', '.opf-builder', '.opf-b-fields', '.opf-b-field', '.opf-b-paragraph-content'];
    return {
      title: document.title,
      url: location.href,
      bodyText: document.body.innerText,
      candidates: selectors.map(selector => ({ selector, count: document.querySelectorAll(selector).length, html: Array.from(document.querySelectorAll(selector)).slice(0, 5).map(node => node.outerHTML.slice(0, 4000)) })),
      textareas: Array.from(document.querySelectorAll('textarea,input,[contenteditable="true"]')).map((node, index) => ({ index, tag: node.tagName, type: node.type || null, id: node.id, name: node.name, ariaLabel: node.getAttribute('aria-label'), placeholder: node.getAttribute('placeholder'), value: 'value' in node ? node.value : node.textContent, outerHTML: node.outerHTML.slice(0, 2000) })),
      scripts: Array.from(document.scripts).map(script => ({ src: script.src, type: script.type, id: script.id })),
      roots: Array.from(document.querySelectorAll('[id], [data-reactroot], [data-v-app]')).map(node => ({ tag: node.tagName, id: node.id, className: typeof node.className === 'string' ? node.className : '', role: node.getAttribute('role') })).slice(0, 100),
    };
  });
  const displayInputValues = await paragraphs.evaluateAll(nodes => nodes.map(node => node.value));
  fs.writeFileSync(out + '/display-diagnostics.json', JSON.stringify({ expected: contents, roleTextboxCount: displayInputValues.length, roleTextboxValues: displayInputValues, actual: displayActual }, null, 2));
  check('OPF imported current and legacy content displayed correctly', displayInputValues[0] === contents[0] && displayInputValues[1] === contents[1]);
  for (let index = 0; index < 2; index++) await paragraphs.nth(index).fill(contents[index] + '\nOPF REST edit');
  const restSave = page.waitForResponse(response => response.url().includes('/opf/v1/groups') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  const rest = await restSave;
  const restBody = await rest.json();
  check('OPF actual authenticated REST save returns HTTP 200', rest.status() === 200);
  check('OPF REST response preserves both content values', restBody.data.fields.every((field, i) => field.content === contents[i] + '\nOPF REST edit'));
  await page.getByText('Saved.', { exact: true }).waitFor();
  await page.reload({ waitUntil: 'networkidle' });
  check('OPF current-origin content survives admin REST save/reload', await paragraphs.nth(0).inputValue() === contents[0] + '\nOPF REST edit');
  check('OPF legacy-origin content survives admin REST save/reload', await paragraphs.nth(1).inputValue() === contents[1] + '\nOPF REST edit');
  await page.screenshot({ path: out + '/opf-admin-rest-reload.png', fullPage: true });
  // Revert only fixture fields through the same REST UI before fidelity comparison.
  for (let index = 0; index < 2; index++) await paragraphs.nth(index).fill(contents[index]);
  const revert = page.waitForResponse(response => response.url().includes('/opf/v1/groups') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  check('fixture REST content restoration succeeds', (await revert).status() === 200);
  await page.getByText('Saved.', { exact: true }).waitFor();
  console.log(wp(['opf', 'export', '--group=' + migrated.imported, '--format=wapf-json', '--output=' + out + '/opf-wapf-export.json']).trim());
  const exported = JSON.parse(fs.readFileSync(out + '/opf-wapf-export.json', 'utf8'));
  check('OPF real CLI WAPF JSON export preserves p_content and canonical content type', exported.fields.every((field, i) => field.p_content === contents[i] && field.type === 'content'));
  process.env.OPFCTM_PHASE = 'reverse';
  console.log(phase('reverse'));
  const reverse = readState();
  check('OPF exported JSON persisted through Free model without p_content loss', reverse.reverse_values.every((value, i) => value === contents[i]));
  await page.goto(`${base}/wp-admin/post.php?post=${reverse.reverse}&action=edit`, { waitUntil: 'networkidle' });
  const finalFields = JSON.parse(await page.locator('input[name="wapf-fields"]').inputValue());
  check('Free real admin reload displays both model-reimported p_content values', finalFields.every((field, i) => field.p_content === contents[i]));
  await Promise.all(assetPromises);
  for (const [suffix, source] of [ ['opf-content-text-migration-lane/assets/js/opf-builder.js', root + '/assets/js/opf-builder.js'], ['advanced-product-fields-for-woocommerce/assets/js/admin.min.js', wpPath + '/wp-content/plugins/advanced-product-fields-for-woocommerce/assets/js/admin.min.js'] ]) {
    const observed = Object.entries(assets).find(([url]) => url.includes(suffix));
    check(suffix + ': Chromium-served bytes equal source', !!observed && observed[1] === createHash('sha256').update(fs.readFileSync(source)).digest('hex'));
  }
  check('zero browser console/page errors', errors.length === 0);
} finally {
  await Promise.all(assetPromises);
  const responseDiagnostics = await Promise.all(responses);
  fs.writeFileSync(out + '/browser-results.json', JSON.stringify({ time: new Date().toISOString(), chromium: browser.version(), checks, errors, assets, requests, responses: responseDiagnostics }, null, 2));
  await browser.close();
}
