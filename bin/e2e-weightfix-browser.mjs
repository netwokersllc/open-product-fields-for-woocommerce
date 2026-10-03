/**
 * weightfix lane — browser proof for the Renderer data-tax + pricing-hint
 * tax conversion work (WAPF-COMMERCE-WEIGHT companion display rows).
 *
 * Asserts on the disposable weightfix clone (product "opf weightfix browser",
 * 10% standard tax rate):
 *  - .opf-product-totals carries data-tax=1.1 (real multiplier, WAPF parity);
 *  - fixed pricing hints follow woocommerce_tax_display_shop (excl 10 / incl 11);
 *  - percent hints stay percent-derived (never tax-adjusted);
 *  - end-to-end add-to-cart works and the merged price shows in the cart;
 *  - zero OPF page errors.
 *
 * Run: PHASE=excl|incl node bin/e2e-weightfix-browser.mjs  (from the lane worktree)
 */
import { createRequire } from 'node:module';
const require2 = createRequire(process.cwd() + '/index.js');
const { chromium } = require2('playwright');

const BASE = process.env.OPF_BASE_URL || 'http://127.0.0.1:8319';
const PHASE = process.env.PHASE || 'excl';
const SHOTS = process.env.SHOT_DIR || '/tmp/opf-lane-weightfix-evidence';
let pass = 0, fail = 0;
const check = (l, c, detail = '') => { if (c) { pass++; console.log('  ok   ', l, detail); } else { fail++; console.log('  FAIL ', l, detail); } };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', e => errors.push('pageerror: ' + e.message));

await page.goto(BASE + '/product/opf-weightfix-browser/', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('.opf-fields', { timeout: 15000 });

// data-tax is the real product tax multiplier (1 + 10% = 1.1).
const dataTax = await page.locator('.opf-product-totals').first().getAttribute('data-tax');
check('totals data-tax is the real multiplier 1.1 (WAPF get_tax_multiplier)', '1.1' === dataTax, 'got ' + dataTax);

// Pricing hints follow the shop tax display setting.
const body = await page.textContent('body');
const expectedFixed = 'incl' === PHASE ? '$11.00' : '$10.00';
const unexpectedFixed = 'incl' === PHASE ? '$10.00' : '$11.00';
check(`fixed hint shows ${expectedFixed} (shop display ${PHASE})`, body.includes(expectedFixed) && !body.includes(unexpectedFixed), expectedFixed);
check('percent hint stays percent-derived $50.00 (never tax-adjusted)', body.includes('$50.00'), '');

await page.screenshot({ path: `${SHOTS}/browser-weightfix-product-${PHASE}.png`, fullPage: true });

// End-to-end add-to-cart with the weighted + priced choice.
await page.locator('[data-opf-field="pack"] select').first().selectOption('light');
// The disposable clone carries pre-existing global required fields from other
// fixture groups — fill every required OPF input so validation passes.
const requiredFields = await page.$$eval('[data-opf-field]', els =>
	els.filter(e => e.querySelector('.required, [required]') !== null)
		.map(e => e.getAttribute('data-opf-field')),
);
for (const fid of requiredFields) {
	const wrap = page.locator(`[data-opf-field="${fid}"]`).first();
	const sel = wrap.locator('select');
	const input = wrap.locator('input[type="text"], input:not([type]), textarea').first();
	try {
		if (await sel.count() > 0) {
			await sel.first().selectOption({ index: 1 });
		} else if (await input.count() > 0) {
			await input.fill('wf');
		}
	} catch { /* non-fillable field types are not present in this fixture */ }
}
await Promise.all([
	page.waitForLoadState('domcontentloaded'),
	page.locator('form.cart button[name="add-to-cart"], button.single_add_to_cart_button').first().click(),
]);
await page.goto(BASE + '/cart/', { waitUntil: 'domcontentloaded' });
let cartShown = true;
try { await page.waitForSelector('text=Packaging', { timeout: 15000 }); } catch { cartShown = false; }
check('cart shows Packaging selection', cartShown);
const cartText = await page.textContent('body');
check('cart shows Light pack choice', cartText.includes('Light pack'));
const expectedLine = 'incl' === PHASE ? '121' : '110';
check(`cart merged unit price present (${expectedLine})`, cartText.includes(expectedLine), '');
await page.screenshot({ path: `${SHOTS}/browser-weightfix-cart-${PHASE}.png`, fullPage: true });

check('no JS page errors during flow', errors.length === 0, errors.slice(0, 3).join(' | '));

console.log(fail === 0 ? `\nSUCCESS: all ${pass} browser checks passed (phase=${PHASE}).` : `\n${fail} FAILURES of ${pass + fail}`);
await browser.close();
process.exit(fail === 0 ? 0 : 1);
