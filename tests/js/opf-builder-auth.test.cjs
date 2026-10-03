const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const script = fs.readFileSync( 'assets/js/opf-builder.js', 'utf8' );

async function savePlacement( access ) {
	let request;
	const elements = {};
	const makeElement = ( tag ) => {
		const node = {
			tagName: tag,
			children: [],
			listeners: {},
			attributes: {},
			textContent: '',
			appendChild( child ) { this.children.push( child ); return child; },
			setAttribute( key, value ) { this.attributes[ key ] = value; if ( 'id' === key ) elements[ value ] = this; },
			addEventListener( type, callback ) { this.listeners[ type ] = callback; },
			querySelector() { return null; },
		};
		return node;
	};
	const mount = makeElement( 'div' );
	mount.dataset = { postId: '0', model: JSON.stringify( { fields: [], rule_groups: [] } ), nonce: 'test', rest: '/opf/v1/groups', previewRest: '/opf/v1/preview' };
	const authSelect = { value: '' };
	const status = { textContent: '' };
	const document = {
		getElementById( id ) {
			if ( elements[ id ] ) return elements[ id ];
			if ( 'opf-builder-app' === id ) return mount;
			if ( 'opf-placement-auth' === id ) return authSelect;
			if ( 'opf-b-status' === id ) return status;
			return null;
		},
		querySelectorAll() { return []; },
		querySelector( selector ) { return '#opf-placement-auth' === selector ? authSelect : null; },
		createElement: makeElement,
	};
	const window = {
		fetch: async ( url, options ) => {
			request = { url, options, body: JSON.parse( options.body ) };
			return { json: async () => ( { id: 10 } ) };
		},
	};
	vm.runInNewContext( script, { window, document, console, setTimeout, clearTimeout, JSON, Math, Date } );
	authSelect.value = access;
	const toolbar = mount.children[ 0 ];
	const saveButton = toolbar.children.find( ( child ) => 'Save' === child.textContent );
	assert.ok( saveButton, 'save button is rendered' );
	saveButton.listeners.click();
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	return request.body.data.rule_groups;
}

( async () => {
	assert.deepEqual( await savePlacement( 'logged_in' ), [ { rules: [ { subject: 'user_auth', operator: 'in', terms: [ 'logged_in' ] } ] } ] );
	assert.deepEqual( await savePlacement( 'logged_out' ), [ { rules: [ { subject: 'user_auth', operator: 'not_in', terms: [ 'logged_in' ] } ] } ] );
	assert.deepEqual( await savePlacement( '' ), [] );
	console.log( 'OPF builder visitor targeting checks passed (3)' );
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
