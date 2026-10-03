const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const script = fs.readFileSync( 'assets/js/opf-builder.js', 'utf8' );

async function loadBuilder( fields ) {
	let request;
	const elements = {};
	const makeElement = ( tag ) => {
		const node = {
			tagName: tag,
			children: [],
			listeners: {},
			attributes: {},
			textContent: '',
			value: '',
			checked: false,
			appendChild( child ) { this.children.push( child ); return child; },
			setAttribute( key, value ) { this.attributes[ key ] = value; if ( 'id' === key ) elements[ value ] = this; },
			getAttribute( key ) { return this.attributes[ key ] ?? null; },
			addEventListener( type, callback ) { this.listeners[ type ] = callback; },
			querySelector() { return null; },
			querySelectorAll() { return []; },
		};
		return node;
	};
	const mount = makeElement( 'div' );
	mount.dataset = { postId: '0', model: JSON.stringify( { fields, rule_groups: [] } ), nonce: 'test', rest: '/opf/v1/groups', previewRest: '/opf/v1/preview' };
	const status = { textContent: '' };
	const document = {
		getElementById( id ) { return elements[ id ] || ( 'opf-builder-app' === id ? mount : ( 'opf-b-status' === id ? status : null ) ); },
		querySelectorAll() { return []; },
		querySelector() { return null; },
		createElement: makeElement,
		createTextNode( text ) { return { nodeType: 3, textContent: text }; },
	};
	const window = {
		fetch: async ( url, options ) => {
			request = { url, options, body: JSON.parse( options.body ) };
			return { json: async () => ( { id: 10 } ) };
		},
	};
	vm.runInNewContext( script, { window, document, console, setTimeout, clearTimeout, JSON, Math, Date } );
	return { mount, document, getRequest: () => request, elements };
}

( async () => {
	const { document, getRequest } = await loadBuilder( [
		{
			id: 'total', label: 'Total', description: '', type: 'calc', required: false, width: 100,
			calc_type: 'cost', formula: '[field.rate] * 0.5', result_format: 'none', result_text: 'Total {result}',
			choices: [], pricing: { type: 'none', amount: 0, formula: '' }, conditionals: [],
		},
	] );

	// Rendering the calc field must not throw and must expose the type in the
	// field type dropdown (the builder registers `calc`).
	const app = document.getElementById( 'opf-builder-fields' );
	assert.ok( app && app.children.length === 1, 'calc field card rendered' );
	const card = app.children[ 0 ];
	const typeSelect = card.children[ 0 ].children[ 1 ];
	const typeValues = typeSelect.children.map( ( option ) => option.value );
	assert.ok( typeValues.includes( 'calc' ), 'field type dropdown offers calc' );

	const saveButton = ( () => {
		const toolbar = document.getElementById( 'opf-builder-app' ).children[ 0 ];
		return toolbar.children.find( ( child ) => 'Save' === child.textContent );
	} )();
	assert.ok( saveButton, 'save button rendered' );
	saveButton.listeners.click();
	await new Promise( ( resolve ) => setImmediate( resolve ) );

	const saved = getRequest().body.data.fields[ 0 ];
	assert.equal( saved.type, 'calc' );
	assert.equal( saved.calc_type, 'cost' );
	assert.equal( saved.formula, '[field.rate] * 0.5' );
	assert.equal( saved.result_text, 'Total {result}' );

	console.log( 'OPF builder calc registration checks passed' );
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
