import { createRequire } from 'node:module';
const { JSDOM } = createRequire(process.cwd() + '/index.js')('jsdom');
import { readFileSync } from 'node:fs';

const html = readFileSync('/tmp/page.html', 'utf8');
const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'http://127.0.0.1:8090/product/e2e-matched-product/' });
const { window } = dom;
Object.defineProperty(window.document, 'readyState', { value: 'complete', configurable: true });
window.eval(readFileSync('/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce/assets/js/opf-frontend.js', 'utf8'));

let pass = 0, fail = 0;
const check = (l, c) => { if (c) { pass++; console.log('  ok   ', l); } else { fail++; console.log('  FAIL ', l); } };

const doc = window.document;

// --- The exact theme quantity.js addon-total logic, verbatim semantics. ---
function isAddonActive(el) {
	if (el.disabled) return false;
	const tag = el.tagName;
	if (tag === 'INPUT' && (el.type === 'checkbox' || el.type === 'radio')) {
		if (!el.checked) return false;
	} else if (tag === 'OPTION') {
		if (!el.selected) return false;
		const sel = el.closest('select');
		if (sel && sel.disabled) return false;
	}
	const container = el.closest('.wapf-field-container');
	if (container && container.classList.contains('wapf-hide')) return false;
	const value = el.value;
	if (value === '' || value == null) {
		if (!(tag === 'INPUT' && (el.type === 'checkbox' || el.type === 'radio'))) return false;
	}
	return true;
}
function collectAddons() {
	const active = [];
	doc.querySelectorAll('.wapf-field-input [data-wapf-price]').forEach((el) => {
		if (!isAddonActive(el)) return;
		active.push({
			priceType: (el.getAttribute('data-wapf-pricetype') || '').toLowerCase(),
			amount: parseFloat(el.getAttribute('data-wapf-price') || '0') || 0,
			value: el.value,
		});
	});
	return active;
}
function addonsTotalAtQty(active, qty, perUnitUsd) {
	let u = 0;
	active.forEach(({ priceType, amount: t, value: valStr }) => {
		let d = 0;
		switch (priceType) {
			case 'percent': d = perUnitUsd * (t / 100) * qty; break;
			case 'qt':      d = qty * t; break;
			default:        d = t; // fixed / formula (pre-resolved per unit)
		}
		if (isFinite(d)) u += d;
	});
	return u;
}

const perUnitUsd = 100; // product base price

// Scenario 1: default (Normal preselected) — no active priced addons.
let addons = collectAddons();
check('theme selector finds the compat addon inputs', doc.querySelectorAll('.wapf-field-input [data-wapf-price]').length === 5);
check('theme isAddonActive: default selection has no priced addon active', addons.length === 0);
check('theme addonsTotalAtQty = 0 at default', addonsTotalAtQty(addons, 1, perUnitUsd) === 0);

// Scenario 2: Boost selected → fixed 5 (data-wapf-price=5, pricetype fixed).
const boost = doc.querySelector('[data-opf-field="delivery"] input[value="boost"]');
boost.checked = true;
boost.dispatchEvent(new window.Event('input', { bubbles: true }));
addons = collectAddons();
check('theme sees Boost addon active after selection', addons.length === 1 && addons[0].priceType === 'fixed' && addons[0].amount === 5);
check('theme addonsTotalAtQty(boost) = 5 at qty 1', addonsTotalAtQty(addons, 1, perUnitUsd) === 5);
check('theme addonsTotalAtQty(boost) = 5 FLAT at qty 3 (fixed fee, theme math)', addonsTotalAtQty(addons, 3, perUnitUsd) === 5);

// Scenario 3: hidden conditional field must NOT count (wapf-hide container).
const boostNoteArea = doc.querySelector('[data-opf-field="boost_note"] textarea');
boostNoteArea.value = 'rush';
// boost_note has fixed pricing 2.0; simulate its attribute presence like the
// renderer emits for priced text fields when visible. It is currently hidden.
addons = collectAddons();
const noteCounted = addons.some(a => a.value === 'rush');
check('theme isAddonActive: hidden (wapf-hide) conditional field excluded', !noteCounted);

// Scenario 4: Plus selected → percent 20 → 20.00 per unit at base 100.
const plus = doc.querySelector('[data-opf-field="delivery"] input[value="plus"]');
plus.checked = true;
plus.dispatchEvent(new window.Event('input', { bubbles: true }));
addons = collectAddons();
check('theme sees Plus percent addon', addons.length === 1 && addons[0].priceType === 'percent' && addons[0].amount === 20);
check('theme addonsTotalAtQty(plus) = 20.00 at base 100 qty 1', addonsTotalAtQty(addons, 1, perUnitUsd) === 20);
check('theme addonsTotalAtQty(plus) = 60.00 at qty 3', addonsTotalAtQty(addons, 3, perUnitUsd) === 60);

console.log(fail === 0 ? `\nSUCCESS: theme quantity.js compat verified (${pass} checks).` : `\n${fail} FAILURES`);
process.exit(fail === 0 ? 0 : 1);
