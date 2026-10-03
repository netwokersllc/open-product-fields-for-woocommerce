// Executes the installed Extended helper unchanged in Chromium; this is a
// reference lookup proof, not a WordPress/cart lifecycle proof.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const scriptPath = process.env.WAPF_FRONTEND_SCRIPT;
const jqueryPath = process.env.WAPF_JQUERY_SCRIPT;
if (!scriptPath || !jqueryPath) throw new Error('Set WAPF_FRONTEND_SCRIPT and WAPF_JQUERY_SCRIPT to installed source files.');
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
try {
	await page.setContent('<!doctype html><html><body><form class="cart"><div id="first"><input class="input-source" type="text" value="first" data-price="0"></div><div><input class="input-source" type="text" value="later" data-price="11"></div></form></body></html>');
	await page.addScriptTag({ content: fs.readFileSync(jqueryPath, 'utf8') });
	await page.addScriptTag({ content: 'window.wapf_config = { page_type: "reference" };' });
	await page.addScriptTag({ content: fs.readFileSync(scriptPath, 'utf8') });
	const replacement = () => page.evaluate(() => WAPF.Util.replaceFx('[price.source] * 2', 1, 100, '', jQuery('form.cart')));
	for (const hidden of [false, true, false]) {
		await page.locator('#first').evaluate((node, hide) => { node.hidden = hide; }, hidden);
		const result = await replacement();
		if (result !== '0 * 2') throw new Error(`First zero source hidden=${hidden}: ${result}`);
		console.log(`ok installed WAPF first duplicate stays zero with hidden=${hidden}`);
	}
	await page.locator('#first input').evaluate(node => { jQuery(node).data('price', 7); });
	if (await replacement() !== '7 * 2') throw new Error('First nonzero duplicate not retained');
	console.log('ok installed WAPF first nonzero duplicate is retained');
	if (errors.length) throw new Error(JSON.stringify(errors));
} finally { await browser.close(); }
