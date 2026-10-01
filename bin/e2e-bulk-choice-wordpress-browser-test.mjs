// Actual authenticated WordPress admin, REST persistence, and WAPF roundtrip.
// OPF_WP_PATH=/tmp/opf-bulk-wp-e2e-* OPF_BASE_URL=http://127.0.0.1:PORT
// OPF_E2E_LOGIN_URL=http://127.0.0.1:PORT/?opf_bulk_login=1
// PLAYWRIGHT_BROWSERS_PATH=/tmp/fy-playwright-browsers node bin/e2e-bulk-choice-wordpress-browser-test.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import { isDeepStrictEqual } from 'node:util';

const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const wpPath = fs.realpathSync(process.env.OPF_WP_PATH || '');
const base = process.env.OPF_BASE_URL;
const login = process.env.OPF_E2E_LOGIN_URL;
if (!wpPath.startsWith('/tmp/opf-bulk-wp-e2e-') || !fs.existsSync(path.join(wpPath, 'wp-config.php'))) throw new Error('Use a private /tmp/opf-bulk-wp-e2e-* WordPress clone.');
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base || '') || !login?.startsWith(base + '/')) throw new Error('Use a loopback site and its isolated login fixture.');
const here = path.dirname(fileURLToPath(import.meta.url));
const artifacts = process.env.OPF_BULK_ARTIFACT_DIR || '/tmp/opf-bulk-artifacts';
fs.mkdirSync(artifacts, { recursive: true });
const wp = (...args) => execFileSync('wp', [`--path=${wpPath}`, 'eval-file', path.join(here, 'e2e-bulk-choice-fixture.php'), ...args.map(String)], { encoding: 'utf8' }).trim();
const fixture = JSON.parse(wp('create'));
const checks = [];
const check = (name, value) => { checks.push({ name, pass: !!value }); console.log(`${value ? 'ok' : 'FAIL'} ${name}`); assert.ok(value, name); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let cleanup;
try {
    await page.goto(login, { waitUntil: 'domcontentloaded' });
    await page.goto(`${base}/wp-admin/post.php?post=${fixture.id}&action=edit`, { waitUntil: 'domcontentloaded' });
    await page.locator('.opf-b-field').first().waitFor();
    const script = await page.locator('script[src*="opf-builder.js"]').getAttribute('src');
    check('served builder bytes equal this worktree', await (await page.request.get(script)).text() === fs.readFileSync(path.join(here, '../assets/js/opf-builder.js'), 'utf8'));
    check('all five choice field types expose bulk import', await page.locator('.opf-b-bulk-import').count() === 5);
    const long = 'A'.repeat(60);
    const labels = ['Duplicate', 'Duplicate', 'Café élite', '東京', '__proto__', 'constructor', 'Label, literal comma', '<img src=x onerror=alert(1)>', long, long];
    for (let i = 0; i < 5; i++) {
        const card = page.locator('.opf-b-field').nth(i);
        const detail = card.locator('.opf-b-bulk-import');
        await detail.locator('summary').focus();
        await page.keyboard.press('Enter');
        check(`field ${i + 1}: disclosure opens from keyboard`, await detail.getAttribute('open') !== null);
        const input = detail.getByRole('textbox', { name: 'Choices to import' });
        const button = detail.getByRole('button', { name: 'Import choices', exact: true });
        await input.fill(' \n\t\r\n');
        check(`field ${i + 1}: blank-only input cannot import`, await button.isDisabled());
        await input.fill(i === 3 ? ' Red, #ff0000\r\n\nRed, #00ff00\nWhite\nInvalid, nope\nShort, #abc\nAlpha, #12345678\n, #ff0000' : ' \r\n' + labels.join('\r\n') + '\r\n\t');
        await button.focus();
        await page.keyboard.press('Enter');
        check(`field ${i + 1}: import appends immediately and returns focus`, await card.locator('.opf-b-choice').count() === (i === 3 ? 7 : 11) && await input.evaluate(node => node === document.activeElement));
        check(`field ${i + 1}: status announces imported line count`, await detail.getByRole('status').innerText() === (i === 3 ? '6 out of 8 lines imported.' : '10 out of 12 lines imported.'));
        check(`field ${i + 1}: textarea resets and duplicate import is prevented`, await input.inputValue() === '' && await button.isDisabled());
        if (i === 4) check('image quantity controls appear immediately for imported choices', await card.getByRole('spinbutton', { name: 'Duplicate — Default quantity', exact: true }).count() === 2);
    }
    const first = page.locator('.opf-b-field').first();
    const firstImport = first.locator('.opf-b-bulk-import');
    await firstImport.locator('summary').click();
    await firstImport.getByRole('textbox', { name: 'Choices to import' }).fill('Duplicate');
    await firstImport.getByRole('button', { name: 'Import choices', exact: true }).click();
    check('repeat import reserves IDs from previous batches', await first.locator('.opf-b-choice').last().locator('.opf-b-slug').inputValue() === 'duplicate-4');
    await first.locator('.opf-b-choice').last().locator('.opf-b-remove').click();
    check('imported choice deletion retains existing and earlier imported rows', await first.locator('.opf-b-choice').count() === 11 && await first.locator('.opf-b-choice').first().locator('.opf-b-slug').inputValue() === 'duplicate');
    check('imported markup stays literal in input values', await page.locator('.opf-b-choices img').count() === 0);
    await page.screenshot({ path: path.join(artifacts, 'admin-imported.png'), fullPage: true });
    const responsePromise = page.waitForResponse(r => r.url().includes('/opf/v1/groups') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    const response = await responsePromise;
    check('authenticated actual REST save succeeds', response.status() === 200);
    const saved = (await response.json()).data;
    for (let i = 0; i < 5; i++) {
        const choices = saved.fields[i].choices;
        check(`field ${i + 1}: existing choice survives exactly`, JSON.stringify(choices[0]) === JSON.stringify(fixture.data.fields[i].choices[0]));
        check(`field ${i + 1}: slugs remain unique and safe`, new Set(choices.map(c => c.slug)).size === choices.length && choices.every(c => /^[a-z0-9-]{1,40}$/.test(c.slug)));
        check(`field ${i + 1}: imported choices have neutral pricing and flags`, choices.slice(1).every(c => !c.selected && !c.disabled && c.pricing.type === 'none' && c.pricing.amount === 0));
        if (i !== 3) {
            check(`field ${i + 1}: trimmed labels retain duplicates, Unicode, commas, and markup`, JSON.stringify(choices.slice(1).map(c => c.label)) === JSON.stringify(labels));
            check(`field ${i + 1}: collision suffixes and Unicode fallback`, choices[1].slug === 'duplicate-2' && choices[2].slug === 'duplicate-3' && choices[3].slug === 'cafe-elite' && choices[4].slug === 'option');
        } else {
            check('color importer saves colors and safe defaults', JSON.stringify(choices.slice(1).map(c => c.color)) === JSON.stringify(['#FF0000', '#00FF00', '#FFFFFF', '#FFFFFF', '#ABC', '#12345678']));
        }
    }
    check('image quantity choices have usable quantity defaults', saved.fields[4].choices.slice(1).every(c => c.quantity.default === 0 && c.quantity.min === 0 && c.quantity.max === 999999));
    await page.getByText('Saved.', { exact: true }).waitFor();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator('.opf-b-field').first().waitFor();
    const reloaded = await page.locator('#opf-builder-app').evaluate(node => JSON.parse(node.dataset.model));
    check('actual admin reload retains complete normalized choice data', JSON.stringify(reloaded) === JSON.stringify(saved));
    check('authoritative database matches REST data exactly', JSON.stringify(JSON.parse(wp('read', fixture.id))) === JSON.stringify(saved));
    const roundtrip = JSON.parse(wp('roundtrip', fixture.id));
    fs.writeFileSync(path.join(artifacts, 'wapf-roundtrip.json'), JSON.stringify(roundtrip, null, 2));
    check('WAPF export explicitly rejects HTML labels without silently losing data', roundtrip.html_rejected);
    check('actual WAPF parser accepts all four plain-label choice field exports', roundtrip.wapf_stored.fields.length === 4 && roundtrip.wapf_stored.fields.every((f, i) => isDeepStrictEqual(f.options.choices.map(c => [c.slug, c.label]), roundtrip.original.fields[i].choices.map(c => [c.slug, c.label]))));
    check('every reimported WAPF field preserves its plain choices',
        roundtrip.reimported.group.fields.every(f => isDeepStrictEqual(f.choices, roundtrip.original.fields.find(original => original.label === f.label)?.choices)));
    const knownGaps = roundtrip.original.fields.filter(f => !roundtrip.reimported.group.fields.some(mapped => mapped.label === f.label)).map(f => `${f.type} reimport missing`);
    knownGaps.forEach(gap => console.log(`remaining gap: ${gap}`));
    fs.writeFileSync(path.join(artifacts, 'remaining-gaps.json'), JSON.stringify({ knownGaps, notes: roundtrip.reimported.notes }, null, 2));
    fs.writeFileSync(path.join(artifacts, 'saved-data.json'), JSON.stringify(saved, null, 2));
    for (const width of [320, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        const detail = page.locator('.opf-b-bulk-import').first();
        await detail.locator('summary').click();
        const box = await detail.getByRole('textbox', { name: 'Choices to import' }).boundingBox();
        check(`import textarea fits viewport at ${width}px`, box.x >= 0 && box.x + box.width <= width);
        await page.screenshot({ path: path.join(artifacts, `admin-${width}.png`), fullPage: true });
        await detail.screenshot({ path: path.join(artifacts, `import-${width}.png`) });
        await detail.locator('summary').click();
    }
    check('no uncaught browser errors', errors.length === 0);
} finally {
    await browser.close();
    cleanup = JSON.parse(wp('cleanup', fixture.id));
    check('private fixture removed', cleanup.removed);
    fs.writeFileSync(path.join(artifacts, 'results.json'), JSON.stringify({ at: new Date().toISOString(), base, checks, errors, cleanup }, null, 2));
}
