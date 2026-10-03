// WAPF parity: variation-scoped conditional visibility in opf-frontend.js.
// Exercises `variationRulePasses` / `isVisible` with a fake Woo variations form
// so `product_var` / `var_att` gates can be verified without a browser.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const script = fs.readFileSync( 'assets/js/opf-frontend.js', 'utf8' );

const makeForm = ( { id, attributes, variations } = {} ) => {
	const attrs = attributes || {};
	return {
		dataset: {},
		getAttribute( name ) {
			if ( name === 'data-product_variations' ) return variations === undefined ? null : JSON.stringify( variations );
			return null;
		},
		querySelectorAll( selector ) {
			return Object.entries( attrs ).map( ( [ name, value ] ) => ( { name, value } ) );
		},
		querySelector() {
			return { value: String( id || 0 ) };
		},
	};
};

const run = ( form ) => {
	const document = {
		readyState: 'loading',
		addEventListener() {},
		querySelector( selector ) {
			return 'form.variations_form' === selector ? form : null;
		},
		dispatchEvent() {},
	};
	const window = { OPF_FIELDS: {}, jQuery: null };
	vm.runInNewContext(
		`${script}\nwindow.__testIsVisible = isVisible; window.__testVariationRulePasses = variationRulePasses;`,
		{ window, document, console, setTimeout, clearTimeout, CustomEvent: class {} }
	);
	return window;
};

const gate = ( subject, operator, terms ) => ( {
	conditionals: [ { action: 'var', logic: 'all', generated: true, rules: [ { subject, operator, terms } ] } ],
} );

// Variable parent with a real Woo variation payload; select variation 55.
{
	const form = makeForm( {
		id: 55,
		variations: [
			{ variation_id: 55, attributes: { attribute_pa_color: 'red' } },
			{ variation_id: 56, attributes: { attribute_pa_color: 'blue' } },
		],
	} );
	const win = run( form );
	const w = win;
	assert.equal( w.__testIsVisible( gate( 'product_var', 'in', [ '55' ] ), {} ), true );
	assert.equal( w.__testIsVisible( gate( 'product_var', 'in', [ '56' ] ), {} ), false );
	assert.equal( w.__testIsVisible( gate( 'var_att', 'in', [ 'color|red' ] ), {} ), true );
	assert.equal( w.__testIsVisible( gate( 'var_att', 'in', [ 'color|blue' ] ), {} ), false );
}

// No variation selected → every variation rule fails.
{
	const form = makeForm( { id: 0, variations: [] } );
	const win = run( form );
	assert.equal( win.__testIsVisible( gate( 'product_var', 'in', [ '55' ] ), {} ), false );
	assert.equal( win.__testIsVisible( gate( 'product_var', 'not_in', [ '55' ] ), {} ), false );
}

// Non-variable product page → variation rules pass through.
{
	const document = {
		readyState: 'loading',
		addEventListener() {},
		querySelector() {
			return null;
		},
		dispatchEvent() {},
	};
	const window = { OPF_FIELDS: {}, jQuery: null };
	vm.runInNewContext(
		`${script}\nwindow.__testIsVisible = isVisible;`,
		{ window, document, console, setTimeout, clearTimeout, CustomEvent: class {} }
	);
	assert.equal( window.__testIsVisible( gate( 'product_var', 'in', [ '55' ] ), {} ), true );
}

// `not_in` negates once a variation is selected.
{
	const form = makeForm( { id: 56, variations: [ { variation_id: 56, attributes: { attribute_pa_color: 'blue' } } ] } );
	const win = run( form );
	assert.equal( win.__testIsVisible( gate( 'product_var', 'not_in', [ '55' ] ), {} ), true );
	assert.equal( win.__testIsVisible( gate( 'var_att', 'not_in', [ 'color|red' ] ), {} ), true );
}

// Gate ANDs with a normal show conditional.
{
	const form = makeForm( { id: 55, variations: [ { variation_id: 55, attributes: { attribute_pa_color: 'red' } } ] } );
	const win = run( form );
	const field = {
		conditionals: [
			{ action: 'show', logic: 'all', rules: [ { field: 'a', operator: 'is', value: 'yes' } ] },
			{ action: 'var', logic: 'all', generated: true, rules: [ { subject: 'product_var', operator: 'in', terms: [ '55' ] } ] },
		],
	};
	assert.equal( win.__testIsVisible( field, { a: 'yes' } ), true );
	assert.equal( win.__testIsVisible( field, { a: 'no' } ), false );
}

console.log( 'OPF variation conditional checks passed (11)' );
