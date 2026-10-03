'use strict';
// WAPF-PRICE-FORMULA-CUSTOM-VARIABLE browser parity: files(), lookuptable(),
// [var_*] custom variables, [x], and the unregistered-call residual eval
// (WAPF Extended 3.1.5 never registered map()/reduce() — its browser evalFx
// strips ALL letters, so map(1;2) → 12 and reduce(1;2) → 12 in JS, while the
// PHP reference keeps e/E and yields reduce(1;2) → 0 there).
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const context = {
  window: {},
  document: { readyState: 'loading', addEventListener() {} },
};
vm.createContext(context);
vm.runInContext(`${fs.readFileSync(path.join(root, 'assets/js/opf-frontend.js'), 'utf8')}\nglobalThis.evaluate = evalFormula;`, context);

const TABLES = { cutting: { 10: { 5: 100, 20: 150 }, 30: { 5: 200, 20: 300 } }, deep: { 10: { 5: { 1: 11, 9: 19 }, 20: { 1: 21, 9: 29 } } } };
const OPTS = {
  variables: [
    { name: 'fee', default: '1.5', rules: [] },
    { name: 'dyn', default: '1', rules: [{ type: 'field', field: 'sizes', condition: '==', value: 'lg', variable: '2.5' }] },
    { name: 'qtyvar', default: '10', rules: [{ type: 'qty', field: 'qty', condition: 'gt', value: '2', variable: '99' }] },
    { name: 'nested', default: '[var_fee]*2', rules: [] },
    { name: 'fxvar', default: '[field.widthf]*files(upfiles)', rules: [] },
    { name: 'tworule', default: '0', rules: [
      { type: 'field', field: 'sizes', condition: '==', value: 'lg', variable: '7' },
      { type: 'field', field: 'sizes', condition: '==', value: 'lg', variable: '8' },
    ] },
  ],
  fields: [{ id: 'sizes', type: 'select' }],
  lookupTables: TABLES,
};
const VALUES = { upfiles: ['tok_a', 'tok_b', 'tok_c'], widthf: '15', heightf: '5', sizes: 'lg' };
const ev = (formula, extra = {}) => context.evaluate(formula, extra.price ?? 10, extra.qty ?? 1, extra.addons ?? 0, extra.val ?? '', extra.fieldValues ?? VALUES, null, {}, OPTS);

test('files() counts the submitted upload list (comma-label parity)', () => {
  assert.equal(ev('files(upfiles)'), 3);
  assert.equal(context.evaluate('files(upfiles)', 10, 1, 0, '', { upfiles: ['tok_a'] }, null, {}, OPTS), 1);
  assert.equal(context.evaluate('files(upfiles)', 10, 1, 0, '', { upfiles: [] }, null, {}, OPTS), 0);
  assert.equal(context.evaluate('files(textf)', 10, 1, 0, '', { textf: 'a,b' }, null, {}, OPTS), 2);
  assert.equal(context.evaluate('files(nope)', 10, 1, 0, '', VALUES, null, {}, OPTS), 0);
  assert.equal(context.evaluate('files(upfiles)*[qty]', 10, 2, 0, '', VALUES, null, {}, OPTS), 6);
});

test('lookuptable() traverses WAPF nested tables with nearest-axis rounding', () => {
  assert.equal(ev('lookuptable(cutting;widthf;heightf)'), 200);
  assert.equal(context.evaluate('lookuptable(cutting;widthf;heightf)', 10, 1, 0, '', { widthf: '15', heightf: '12' }, null, {}, OPTS), 300);
  assert.equal(context.evaluate('lookuptable(cutting;widthf;heightf)', 10, 1, 0, '', { widthf: '2', heightf: '1' }, null, {}, OPTS), 100);
  assert.equal(ev('lookuptable(cutting;15;5)'), 200);
  // PHP/browser divergence: PHP floatval('abcde')=0 clamps to the first key
  // (100); the browser's findNearest NaN-falls to an out-of-bounds key →
  // traversal on a non-axis throws → caught → 0.
  assert.equal(ev('lookuptable(cutting;abcde;5)'), 0);
  assert.equal(ev('lookuptable(cutting;zzzzzzz;5)'), 0);
  assert.equal(ev('lookuptable(nothere;widthf;heightf)'), 0);
  assert.equal(ev("lookuptable('cutting';widthf;heightf)"), 0);
  // Beyond the last axis key the browser reference yields undefined → the
  // whole call resolves 0 while the surrounding formula still evaluates.
  assert.equal(context.evaluate('lookuptable(cutting;widthf;heightf)', 10, 1, 0, '', { widthf: '99', heightf: '99' }, null, {}, OPTS), 0);
  assert.equal(ev('lookuptable(cutting;widthf;heightf)*[qty]', { qty: 3 }), 600);
  assert.equal(context.evaluate('lookuptable(deep;widthf;heightf;2)', 10, 1, 0, '', { widthf: '10', heightf: '20' }, null, {}, OPTS), 29);
  // wapf_lookup_tables global is WAPF's own browser extension point.
  context.window.wapf_lookup_tables = { cutting: { 10: { 5: 7 } } };
  assert.equal(context.evaluate('lookuptable(cutting;widthf;heightf)', 10, 1, 0, '', { widthf: '10', heightf: '5' }, null, {}, {}), 7);
  delete context.window.wapf_lookup_tables;
});

test('[var_name] custom variables resolve WAPF-style', () => {
  assert.equal(ev('[var_fee]*2'), 3);
  assert.equal(ev('[var_dyn]'), 2.5);
  assert.equal(context.evaluate('[var_dyn]', 10, 1, 0, '', { sizes: 'sm' }, null, {}, OPTS), 1);
  assert.equal(ev('[var_qtyvar]', { qty: 5 }), 99);
  assert.equal(ev('[var_qtyvar]', { qty: 1 }), 10);
  assert.equal(ev('[var_nested]'), 3);
  assert.equal(ev('[var_fxvar]'), 45);
  assert.equal(ev('[var_tworule]'), 7);
  assert.equal(ev('[var_nothere]'), 0);
  assert.equal(ev('[VAR_FEE]'), 0); // case-sensitive names, like WAPF.
  assert.equal(ev('([var_fee]+1)*[qty]', { qty: 4 }), 10);
});

test('[x] aliases the current field value', () => {
  assert.equal(context.evaluate('[x]*2', 10, 1, 0, '4', VALUES, null, {}, OPTS), 8);
});

test('unregistered calls hit WAPF browser residual eval (map/reduce quirk)', () => {
  // Browser evalFx strips ALL letters — reduce(1;2) → '(12)' → 12.
  assert.equal(ev('map(1;2)'), 12);
  assert.equal(ev('reduce(1;2)'), 12);
  // map(widthF;x*2): '(*2)' has no left operand for '*' → WAPF browser → 0.
  assert.equal(ev('map(widthF;x*2)'), 0);
});

test("';' inside quoted text still separates arguments (WAPF split is quote-blind)", () => {
  assert.equal(ev("len('a;b')", { fieldValues: {} }), 2);
});
