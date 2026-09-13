/**
 * Theme integration coverage for the OPF block.
 *
 * Verifies the deployed theme assets style and enhance the opf-* markup:
 *  - built CSS contains the opf-* selector duplicates
 *  - built product JS contains the dual opf/wapf hooks
 *  - the live product page carries the OPF contract (registry, config,
 *    field containers, pricing attributes) with ZERO legacy wapf leakage
 *    for gated viewers.
 *
 * Reads the page HTML the runner captured at /tmp/opf-http-page.html.
 */
import { readFileSync } from 'node:fs';

const pagePath = process.argv[2] || '/tmp/opf-http-page.html';
const html = readFileSync(pagePath, 'utf8');
const themeDir = '/home/followersya-5hqi7/followersya.com/bedrock/web/app/themes/framework';

let pass = 0, fail = 0;
const check = (l, c) => { if (c) { pass++; console.log('  ok   ', l); } else { fail++; console.log('  FAIL ', l); } };

check('page: field containers present', html.includes('opf-field-container'));
check('page: pricing attributes present', html.includes('data-opf-price'));
check('page: registry present', html.includes('OPF_FIELDS'));
check('page: compat config present', html.includes('opf_config'));
check('page: no legacy wapf-named inputs (gate clean)', !html.includes('name="wapf['));

const prodCss = themeDir + '/public/build/assets/style-product-44647c9a94ad-BEYEUTXC.css';
const css = readFileSync(prodCss, 'utf8');
const ownCss = readFileSync('/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce/assets/css/opf-frontend.css', 'utf8');
check('built CSS: opf-field-container rules present', css.includes('.opf-field-container'));
check('built CSS: opf-swatch rules present', css.includes('.opf-swatch'));
check('OPF CSS: opf-hide rule present', ownCss.includes('.opf-hide'));

const prodJs = themeDir + '/public/build/assets/script-product-DCJhPjLn.js';
const js = readFileSync(prodJs, 'utf8');
check('built JS: dual addon selector present', js.includes('.opf-field-input [data-opf-price],.wapf-field-input [data-wapf-price]'));
check('built JS: dual grand-total selector present', js.includes('.opf-grand-total') && js.includes('.wapf-grand-total'));
check('built JS: reads opf_config', js.includes('window.opf_config'));

console.log(fail === 0 ? `\nSUCCESS: all ${pass} theme-integration checks passed.` : `\n${fail} FAILURES`);
process.exit(fail === 0 ? 0 : 1);
