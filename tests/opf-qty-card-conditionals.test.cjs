const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const script = fs.readFileSync( 'assets/js/opf-frontend.js', 'utf8' );
const window = { OPF_FIELDS: {} };
const document = { readyState: 'loading', addEventListener() {} };
vm.runInNewContext(
	`${script}\nwindow.__qtyMapOf = qtyMapOf; window.__qtyRulePasses = qtyRulePasses; window.__isVisible = isVisible;`,
	{ window, document, console, setTimeout, clearTimeout }
);

const { __qtyMapOf: qtyMapOf, __qtyRulePasses: qtyRulePasses, __isVisible: isVisible } = window;

/* ---- qty_map detection ---- */
assert.equal( JSON.stringify( qtyMapOf( { _opf_type: 'products', quantities: { a: 2, b: 0 } } ) ), JSON.stringify( { a: 2, b: 0 } ) );
// The browser registry only ever carries the structured shape; a bare assoc
// array is request-space (handled by the PHP Evaluator), not a DOM value.
assert.equal( qtyMapOf( { a: 2, b: 0 } ), null );
assert.equal( qtyMapOf( [ 'a', 'b' ] ), null );
assert.equal( qtyMapOf( 'a' ), null );

/* ---- WAPF card-qty semantics (only empty / !empty in 3.1.5) ---- */
const zeroed = { _opf_type: 'products', quantities: { a: 0, b: 0 } };
const some = { _opf_type: 'products', quantities: { a: 0, b: 3 } };
assert.equal( qtyRulePasses( { operator: 'empty', value: '' }, { a: 0, b: 0 } ), true );
assert.equal( qtyRulePasses( { operator: 'not_empty', value: '' }, { a: 0, b: 0 } ), false );
assert.equal( qtyRulePasses( { operator: 'empty', value: '' }, { a: 0, b: 3 } ), false );
assert.equal( qtyRulePasses( { operator: 'not_empty', value: '' }, { a: 0, b: 3 } ), true );

/* ---- OPF superset operators over qty maps ---- */
assert.equal( qtyRulePasses( { operator: 'contains', value: '3' }, { a: 0, b: 3 } ), true );
assert.equal( qtyRulePasses( { operator: 'contains', value: '3' }, { a: 2, b: 0 } ), false );
assert.equal( qtyRulePasses( { operator: 'greater', value: '4' }, { a: 2, b: 3 } ), true );
assert.equal( qtyRulePasses( { operator: 'less', value: '4' }, { a: 2, b: 3 } ), false );
assert.equal( qtyRulePasses( { operator: 'is_not', value: '3' }, { a: 2, b: 0 } ), true );

/* ---- conditional visibility with a qty-card subject ---- */
const field = { conditionals: [ { action: 'show', logic: 'all', rules: [ { field: 'units', operator: 'not_empty', value: '' } ] } ] };
assert.equal( isVisible( field, { units: zeroed } ), false );
assert.equal( isVisible( field, { units: some } ), true );

console.log( 'OPF qty-card conditional checks passed (15)' );
