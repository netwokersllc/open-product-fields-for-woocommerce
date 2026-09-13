import { createRequire } from 'node:module';
const { JSDOM } = createRequire(process.cwd() + '/index.js')('jsdom');
import { readFileSync } from 'node:fs';

const html = readFileSync('/tmp/page.html', 'utf8');
const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'http://127.0.0.1:8090/product/e2e-matched-product/' });
const { window } = dom;
// jsdom keeps readyState 'loading' when external resources are unavailable;
// real browsers execute deferred modules only after it leaves 'loading'.
Object.defineProperty(window.document, 'readyState', { value: 'complete', configurable: true });

let pass = 0, fail = 0;
const check = (label, cond) => { if (cond) { pass++; console.log('  ok   ', label); } else { fail++; console.log('  FAIL ', label); } };

// Inline classic scripts executed by jsdom: registry must exist.
check('window.OPF_FIELDS present with conditionals', !!window.OPF_FIELDS && Object.values(window.OPF_FIELDS).some(g => Object.values(g).some(f => (f.conditionals||[]).length > 0)));
check('window.opf_config present (compat)', !!window.opf_config && !!window.opf_config.display_options?.symbol);

// Execute the real frontend module source in the page context.
const src = readFileSync('/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce/assets/js/opf-frontend.js', 'utf8');
window.eval(src);

const doc = window.document;
const boostNote = doc.querySelector('[data-opf-field="boost_note"]');
const delivery  = doc.querySelector('[data-opf-field="delivery"]');
check('conditional field exists on page', !!boostNote && !!delivery);
check('boost_note initially HIDDEN (default choice = Normal)', boostNote.hasAttribute('hidden') && boostNote.classList.contains('opf-field--hidden') && boostNote.classList.contains('opf-hide'));
check('default swatch has opf-checked (Normal preselected)', !!delivery.querySelector('input[value="normal"]')?.closest('.opf-swatch')?.classList.contains('opf-checked'));

// Click the Boost radio and fire input event (module listens on the group).
const boostInput = delivery.querySelector('input[value="boost"]');
boostInput.checked = true;
boostInput.dispatchEvent(new window.Event('input', { bubbles: true }));

check('boost_note becomes VISIBLE after choosing Boost', !boostNote.hasAttribute('hidden'));
check('boost radio swatch got opf-checked', !!boostInput.closest('.opf-swatch')?.classList.contains('opf-checked'));
check('normal radio swatch lost opf-checked', !delivery.querySelector('input[value="normal"]')?.closest('.opf-swatch')?.classList.contains('opf-checked'));

// Switch back to Normal: boost_note must hide again.
const normalInput = delivery.querySelector('input[value="normal"]');
normalInput.checked = true;
normalInput.dispatchEvent(new window.Event('input', { bubbles: true }));
check('boost_note hides again after switching back', boostNote.hasAttribute('hidden'));

// data-wapf-price attributes present for the theme's quantity.js.
check('compat data-opf-price attrs on choices', !!delivery.querySelector('input[data-opf-price]'));

console.log(fail === 0 ? `\nSUCCESS: all ${pass} browser-behavior checks passed (real module, real page HTML, real events).` : `\n${fail} FAILURES`);
process.exit(fail === 0 ? 0 : 1);
