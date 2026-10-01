// Reference probe of the installed WAPF importer handler, without changing a license.
// This exercises its real model/handler, not the licensed field creation UI or save path.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL;
const login = process.env.OPF_E2E_LOGIN_URL;
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base || '') || !login?.startsWith(base + '/')) throw new Error('Use the private loopback WordPress clone and login fixture.');
const browser = await chromium.launch();
try {
    const page = await browser.newPage();
    await page.goto(login, { waitUntil: 'domcontentloaded' });
    await page.goto(`${base}/wp-admin/post-new.php?post_type=wapf_product`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.WAPF?.Models?.Field && window.jQuery?._data(document.querySelector('#btn-bulkopts'), 'events')?.click?.length);
    const results = await page.evaluate(() => {
        const probe = (type, text) => {
            const field = new WAPF.Models.Field();
            field.type = type;
            field.addChoice('Existing preserved');
            field.choices[0].selected = true;
            const existing = JSON.stringify(field.choices[0]);
            jQuery('#btn-bulkopts').data('field', field);
            jQuery('#wapf-bulkopts').val(text);
            jQuery('#btn-bulkopts').triggerHandler('click');
            return { type, choices: field.choices, existingPreserved: JSON.stringify(field.choices[0]) === existing, status: jQuery('#bulkopts-msg').text() };
        };
        return [
            probe('select', ' \r\n Duplicate\r\nDuplicate\nLabel, literal comma\n東京\n\t'),
            probe('color-swatch', 'Red, #ff0000\nRed, #00ff00\nWhite\n\n'),
        ];
    });
    assert.deepEqual(results[0].choices.slice(1).map(c => c.label), ['Duplicate', 'Duplicate', 'Label, literal comma', '東京']);
    assert.equal(results[0].status, '4 out of 6 items imported');
    assert.deepEqual(results[1].choices.slice(1).map(c => c.label), ['Red', 'Red', 'White']);
    assert.deepEqual(results[1].choices.slice(1).map(c => c.color), [' #ff0000', ' #00ff00', '#ffffff']);
    for (const result of results) {
        assert.ok(result.existingPreserved);
        assert.equal(new Set(result.choices.map(c => c.slug)).size, result.choices.length);
        assert.ok(result.choices.slice(1).every(c => !c.selected && !c.disabled && c.pricing_type === 'none' && c.pricing_amount === 0));
    }
    const output = { at: new Date().toISOString(), base, method: 'installed WAPF model and registered importer handler; licensed UI/save not exercised', results };
    const file = process.env.OPF_WAPF_REFERENCE_FILE || '/tmp/opf-bulk-artifacts/wapf-runtime-reference.json';
    fs.writeFileSync(file, JSON.stringify(output, null, 2));
    console.log('ok installed WAPF importer: newline/trim/blanks/duplicates/append/colors/neutral pricing/unique IDs');
} finally {
    await browser.close();
}
