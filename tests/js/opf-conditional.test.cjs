const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

const script = fs.readFileSync( 'assets/js/opf-frontend.js', 'utf8' );
const window = { OPF_FIELDS: {} };
const document = { readyState: 'loading', addEventListener() {} };
vm.runInNewContext( `${script}\nwindow.__testIsVisible = isVisible;`, { window, document, console, setTimeout, clearTimeout } );

const field = { conditionals: [ { action: 'show', logic: 'all', rules: [ { field: 'source', operator: 'not_contains', value: 'blocked' } ] } ] };
assert.equal( window.__testIsVisible( field, { source: 'safe option' } ), true );
assert.equal( window.__testIsVisible( field, { source: 'blocked option' } ), false );
console.log( 'OPF browser conditional checks passed (2)' );
