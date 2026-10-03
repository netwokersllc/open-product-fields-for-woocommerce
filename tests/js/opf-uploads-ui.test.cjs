const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

// ---- uploader preview decision helper (pure logic) ----------------------
const uploadSource = fs.readFileSync( 'assets/js/opf-uploads.js', 'utf8' )
	.replace( /\n}\s*\)\(\);\s*$/, "\n\twindow.__opfUploadTest = { isPreviewableImage };\n} )();" );
const revoked = [];
const uploadWindow = { URL: { createObjectURL: () => 'blob:test', revokeObjectURL: ( url ) => revoked.push( url ) } };
vm.runInNewContext( uploadSource, {
	window: uploadWindow,
	document: { readyState: 'loading', addEventListener() {} },
	console, setTimeout, clearTimeout, Event, URL: uploadWindow.URL,
} );
const { isPreviewableImage } = uploadWindow.__opfUploadTest;
assert.equal( isPreviewableImage( 'image/png' ), true );
assert.equal( isPreviewableImage( 'image/jpeg' ), true );
assert.equal( isPreviewableImage( 'image/tiff' ), false );
assert.equal( isPreviewableImage( 'image/heic' ), false );
assert.equal( isPreviewableImage( 'application/pdf' ), false );
assert.equal( isPreviewableImage( '' ), false );
assert.equal( isPreviewableImage( undefined ), false );

// ---- builder: upload type + option surface ------------------------------
function makeElement( tag ) {
	const node = {
		tagName: tag,
		children: [],
		listeners: {},
		attributes: {},
		_class: '',
		textContent: '',
		_value: '',
		checked: false,
		get className() { return this._class; },
		set className( value ) { this._class = value; },
		appendChild( child ) { this.children.push( child ); return child; },
		insertBefore( child ) { this.children.unshift( child ); return child; },
		removeChild( child ) { const i = this.children.indexOf( child ); if ( i >= 0 ) this.children.splice( i, 1 ); return child; },
		setAttribute( key, value ) { this.attributes[ key ] = value; if ( 'id' === key ) elements[ value ] = this; },
		getAttribute( key ) { return null == this.attributes[ key ] ? null : this.attributes[ key ]; },
		addEventListener( type, callback ) { this.listeners[ type ] = callback; },
		querySelector() { return null; },
		querySelectorAll() { return []; },
		get options() { return this.children.filter( ( child ) => 'option' === child.tagName ); },
	};
	Object.defineProperty( node, 'innerHTML', { get() { return ''; }, set( value ) { if ( '' === value ) this.children = []; } } );
	Object.defineProperty( node, 'value', {
		get() {
			if ( 'select' === this.tagName ) {
				const chosen = this.children.find( ( child ) => 'option' === child.tagName && child.selected );
				return chosen ? chosen.value : this._value;
			}
			return this._value;
		},
		set( value ) {
			this._value = value;
			if ( 'select' === this.tagName ) {
				this.children.forEach( ( child ) => { if ( 'option' === child.tagName ) child.selected = child.value === value; } );
			}
		},
	} );
	return node;
}
const elements = {};
const makeDom = ( fields ) => {
	const mount = makeElement( 'div' );
	mount.dataset = { postId: '0', model: JSON.stringify( { fields, rule_groups: [] } ), nonce: 'test', rest: '/opf/v1/groups', previewRest: '/opf/v1/preview' };
	const authSelect = makeElement( 'select' ); authSelect.value = '';
	const document = {
		getElementById( id ) {
			if ( elements[ id ] ) return elements[ id ];
			if ( 'opf-builder-app' === id ) return mount;
			if ( 'opf-placement-auth' === id ) return authSelect;
			if ( 'opf-b-status' === id ) return makeElement( 'span' );
			return null;
		},
		querySelectorAll() { return []; },
		querySelector( selector ) { return '#opf-placement-auth' === selector ? authSelect : null; },
		createElement: makeElement,
		createTextNode( text ) { return { tagName: '#text', textContent: String( text ), children: [], getAttribute() { return null; } }; },
		body: makeElement( 'body' ),
	};
	const window = { fetch: async () => ( { json: async () => ( { id: 1 } ) } ) };
	vm.runInNewContext(
		fs.readFileSync( 'assets/js/opf-builder.js', 'utf8' ),
		{ window, document, console, setTimeout, clearTimeout, JSON, Math, Date, Object, Array, String, Number, parseInt, parseFloat, RegExp, Error, isFinite, encodeURIComponent }
	);
	return mount;
};
const walk = ( node, visit ) => {
	visit( node );
	( node.children || [] ).forEach( ( child ) => walk( child, visit ) );
};
const find = ( root, predicate ) => {
	let found = null;
	walk( root, ( node ) => { if ( ! found && predicate( node ) ) found = node; } );
	return found;
};
const findAll = ( root, predicate ) => {
	const found = [];
	walk( root, ( node ) => { if ( predicate( node ) ) found.push( node ); } );
	return found;
};

const mount = makeDom( [ {
	id: 'art', label: 'Artwork', type: 'upload', required: true, choices: [],
	pricing: { type: 'none', amount: 0, formula: '' }, conditionals: [],
	multiple: true, accepted_types: [ 'png', 'pdf' ], max_size: 1,
} ] );
const card = find( mount, ( node ) => 'opf-b-field' === node.className );
assert.ok( card, 'upload field card renders' );
const typeSelect = find( card, ( node ) => 'select' === node.tagName && node.children.some( ( option ) => 'upload' === option.value ) );
assert.ok( typeSelect, 'type select offers the upload type' );
assert.equal( typeSelect.value, 'upload', 'stored upload type is selected' );
const settings = find( card, ( node ) => 'opf-b-upload-settings' === node.className );
assert.ok( settings, 'upload option surface renders' );
const multiple = find( settings, ( node ) => 'multiple' === node.getAttribute( 'data-opf-upload-setting' ) );
assert.equal( multiple.checked, true, 'multiple flag reflects stored value' );
const types = find( settings, ( node ) => 'accepted_types' === node.getAttribute( 'data-opf-upload-setting' ) );
const selectedTypes = types.options.filter( ( option ) => option.selected ).map( ( option ) => option.value );
assert.deepEqual( selectedTypes.sort(), [ 'pdf', 'png' ], 'accepted types reflect stored extensions' );
const size = find( settings, ( node ) => 'max_size' === node.getAttribute( 'data-opf-upload-setting' ) );
assert.equal( String( size.value ), '1', 'max size reflects stored MB value' );
assert.equal( findAll( settings, ( node ) => node.getAttribute( 'data-opf-upload-setting' ) ).length, 3, 'upload exposes exactly multiple/types/max size' );
assert.equal( findAll( settings, ( node ) => 'select' === node.tagName && node.getAttribute( 'multiple' ) ).length, 1, 'accepted types is a native multi-select' );

// Switching a fresh scalar field to upload seeds the WAPF-compatible defaults.
const mount2 = makeDom( [ {
	id: 'note', label: 'Note', type: 'text', required: false, choices: [],
	pricing: { type: 'fixed', amount: 3, formula: '' }, conditionals: [],
} ] );
const firstCard = find( mount2, ( node ) => 'opf-b-field' === node.className );
const freshType = find( firstCard, ( node ) => 'select' === node.tagName && node.children.some( ( option ) => 'upload' === option.value ) );
freshType.value = 'upload';
freshType.listeners.change();
const freshSettings = find( mount2, ( node ) => 'opf-b-upload-settings' === node.className );
assert.ok( freshSettings, 'switching a scalar field to upload renders the option surface' );
const freshSize = find( freshSettings, ( node ) => 'max_size' === node.getAttribute( 'data-opf-upload-setting' ) );
assert.equal( String( freshSize.value ), '1', 'switch seeds the 1 MB WAPF default' );
assert.equal( find( freshSettings, ( node ) => 'multiple' === node.getAttribute( 'data-opf-upload-setting' ) ).checked, false, 'switch defaults multiple off' );
const freshTypes = find( freshSettings, ( node ) => 'accepted_types' === node.getAttribute( 'data-opf-upload-setting' ) );
assert.equal( freshTypes.options.filter( ( option ) => option.selected ).length, 0, 'switch seeds an empty allow-list (all permitted types)' );

console.log( 'OPF upload UI builder + preview checks passed (17)' );
