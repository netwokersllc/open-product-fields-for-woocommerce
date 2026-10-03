// Linked child-products storefront lifecycle on the disposable loopback clone:
// render → gallery swap/restore → qty stepper → add-to-cart → child cart lines
// → parent-qty sync → locked child controls → orphan cleanup → real checkout.
import fs from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire( '/tmp/opf-url-native-parity/index.js' );
const { chromium } = require( 'playwright' );
const base = process.env.OPF_CHILD_BASE_URL || 'http://127.0.0.1:8301';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback clone required.' );
const dir = process.env.OPF_CHILD_ARTIFACT_DIR || '/tmp/opf-lane-child-evidence';
fs.mkdirSync( dir, { recursive: true } );
const state = JSON.parse( fs.readFileSync( dir + '/state.json' ) );
const gid = String( state.group );
const checks = [];
const errors = [];
const check = ( label, pass ) => { checks.push( { label, pass: !! pass } ); console.log( `${ pass ? 'ok' : 'FAIL' } ${ label }` ); if ( ! pass ) throw new Error( label ); };
const minor = ( amount, unit ) => Number( amount ) / 10 ** unit;
const browser = await chromium.launch();
try {
	const context = await browser.newContext();
	const page = await context.newPage();
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );

	/* ---------------- render + gallery swap ---------------- */
	await page.goto( base + '/product/opf-linked-parent/', { waitUntil: 'domcontentloaded' } );
	await page.locator( '.opf-products--card' ).first().waitFor();
	check( 'card products field renders two product cards', await page.locator( '.opf-products--card .opf-card' ).count() === 2 );
	check( 'qty products field renders two quantity cards', await page.locator( '.opf-products--card-qty .opf-card' ).count() === 2 );
	await page.screenshot( { path: dir + '/render.png', fullPage: true } );

	const mainImg = page.locator( '.woocommerce-product-gallery .wp-post-image' ).first();
	const originalSrc = await mainImg.getAttribute( 'src' );
	const alphaCard = page.locator( '.opf-products--card input.opf-product-input[value="child-alpha"]' );
	const betaCard = page.locator( '.opf-products--card input.opf-product-input[value="child-beta"]' );
	await alphaCard.check();
	await page.waitForFunction( ( orig ) => {
		const img = document.querySelector( '.woocommerce-product-gallery .wp-post-image' );
		return img && img.getAttribute( 'src' ) !== orig && img.getAttribute( 'src' ).includes( 'opf-lp-alpha' );
	}, originalSrc );
	check( 'selecting a child card swaps the main gallery image', true );
	await betaCard.check();
	await alphaCard.uncheck();
	await betaCard.uncheck();
	await page.waitForFunction( ( orig ) => document.querySelector( '.woocommerce-product-gallery .wp-post-image' )?.getAttribute( 'src' ) === orig, originalSrc );
	check( 'deselecting all children restores the original gallery image', true );
	await page.screenshot( { path: dir + '/swap.png', fullPage: true } );

	/* ---------------- qty stepper + add to cart ---------------- */
	await alphaCard.check();
	await betaCard.check();
	const betaQty = page.locator( '.opf-products--card-qty input.opf-qty[data-choice-slug="child-beta"]' );
	const betaRow = betaQty.locator( 'xpath=ancestor::*[contains(@class,"opf-card-qty")]' );
	await betaRow.locator( '.opf-qty-plus' ).click();
	await betaRow.locator( '.opf-qty-plus' ).click();
	check( 'plus stepper drives qty input to 2', ( await betaQty.inputValue() ) === '2' );
	const price = page.locator( 'form.cart button.single_add_to_cart_button' );
	await price.click();
	// Block themes surface success via wc-block-components notice banners.
	await page.locator( '.wc-block-components-notice-banner.is-success, .woocommerce-message' ).first().waitFor( { timeout: 15000 } );
	check( 'classic add-to-cart succeeds', true );

	/* ---------------- Store API child lines ---------------- */
	let cartReq = await context.request.get( base + '/wp-json/wc/store/v1/cart' );
	let cart = await cartReq.json();
	let nonce = cartReq.headers()[ 'nonce' ];
	check( 'cart holds parent + 3 child lines', cart.items.length === 4 );
	const byName = {};
	for ( const item of cart.items ) byName[ item.name ] = item;
	const unit = cart.totals.currency_minor_unit;
	check( 'alpha child is 1x $8', byName[ 'OPF Child Alpha' ]?.quantity === 1 && minor( byName[ 'OPF Child Alpha' ].totals.line_total, unit ) === 8 );
	const betaLines = cart.items.filter( ( i ) => i.name === 'OPF Child Beta' );
	check( 'beta contributes a free card line and a priced qty line', betaLines.length === 2 && betaLines.map( ( i ) => minor( i.totals.line_total, unit ) ).sort().join( ',' ) === '0,24' );
	check( 'child lines expose opf.childItem extension', cart.items.filter( ( i ) => i.extensions?.opf?.childItem ).length === 3 );
	check( 'cart total is parent 20 + 8 + 0 + 24 = 52', minor( cart.totals.total_price, unit ) === 52 );
	fs.writeFileSync( dir + '/cart-after-add.json', JSON.stringify( cart.items.map( ( i ) => ( { name: i.name, key: i.key, qty: i.quantity, total: i.totals.line_total, child: !! i.extensions?.opf?.childItem } ) ), null, 2 ) );
	const parentKey = cart.items.find( ( i ) => ! i.extensions?.opf?.childItem )?.key;
	check( 'parent line identified', !! parentKey );

	/* ---------------- cart page: locked child controls (block cart) ---------------- */
	await page.goto( base + '/cart/', { waitUntil: 'domcontentloaded' } );
	await page.locator( 'tr.wc-block-cart-items__row .wc-block-components-product-name' ).first().waitFor( { timeout: 30000 } );
	const rowInfo = await page.locator( 'tr.wc-block-cart-items__row' ).evaluateAll( ( rows ) => rows.map( ( r ) => ( {
		name: r.querySelector( '.wc-block-components-product-name' )?.textContent.trim(),
		childClass: r.className.includes( 'opf-child-item' ),
		remove: !! r.querySelector( '.wc-block-cart-item__remove-link' ),
		included: r.textContent.includes( 'Included with' ),
	} ) ) );
	fs.writeFileSync( dir + '/cart-rows.json', JSON.stringify( rowInfo, null, 2 ) );
	const parentRow = rowInfo.find( ( r ) => r.name === 'OPF Linked Parent' );
	const childRows = rowInfo.filter( ( r ) => r.name !== 'OPF Linked Parent' );
	check( 'block cart child rows get opf-child-item class via registered filter', childRows.length === 3 && childRows.every( ( r ) => r.childClass ) );
	check( 'block cart child rows show Included-with link', childRows.every( ( r ) => r.included ) );
	check( 'block cart child rows hide remove link', childRows.every( ( r ) => ! r.remove ) );
	check( 'parent row keeps remove link', !! parentRow && parentRow.remove && ! parentRow.childClass );
	await page.screenshot( { path: dir + '/cart.png', fullPage: true } );

	/* ---------------- parent qty sync ---------------- */
	const upd = await context.request.post( base + '/wp-json/wc/store/v1/cart/update-item', { headers: { Nonce: nonce }, data: { key: parentKey, quantity: 3 } } );
	cart = await upd.json();
	check( 'parent qty update accepted', upd.ok() && cart.items.find( ( i ) => i.key === parentKey )?.quantity === 3 );
	const sync = {};
	for ( const item of cart.items ) if ( item.extensions?.opf?.childItem ) sync[ item.name + ':' + minor( item.totals.line_total, unit ) ] = item.quantity;
	const alphaQ = cart.items.find( ( i ) => i.name === 'OPF Child Alpha' )?.quantity;
	const betaQtys = cart.items.filter( ( i ) => i.name === 'OPF Child Beta' ).map( ( i ) => i.quantity ).sort( ( a, b ) => a - b );
	check( 'parent-method children scale to qty 3', alphaQ === 3 && betaQtys.includes( 3 ) );
	check( 'relative qty child scales 2→6', betaQtys.join( ',' ) === '3,6' );
	check( 'synced total 60+24+0+72 = 156', minor( cart.totals.total_price, unit ) === 156 );
	fs.writeFileSync( dir + '/cart-after-sync.json', JSON.stringify( cart.items.map( ( i ) => ( { name: i.name, qty: i.quantity, total: i.totals.line_total } ) ), null, 2 ) );

	/* ---------------- real classic checkout ---------------- */
	await page.goto( base + '/linked-proof-checkout/', { waitUntil: 'domcontentloaded' } );
	const checkoutNonce = await page.locator( '[name="woocommerce-process-checkout-nonce"]' ).inputValue();
	const checkout = await context.request.post( base + '/?wc-ajax=checkout', { form: {
		'woocommerce-process-checkout-nonce': checkoutNonce,
		billing_first_name: 'Test', billing_last_name: 'Buyer', billing_company: '',
		billing_country: 'US', billing_address_1: '1 Test Street', billing_address_2: '',
		billing_city: 'Testville', billing_state: 'CA', billing_postcode: '90210',
		billing_phone: '5551234567', billing_email: 'linked-child@example.invalid',
		payment_method: 'bacs', terms: 'on',
	} } );
	const body = await checkout.json();
	if ( body.result !== 'success' ) console.log( JSON.stringify( body ) );
	check( 'classic wc-ajax checkout succeeds', body.result === 'success' );
	const orderId = Number( body.redirect.match( /order-received\/(\d+)/ )?.[ 1 ] );
	check( 'persisted order id', orderId > 0 );
	fs.writeFileSync( dir + '/child-order-id.json', JSON.stringify( orderId ) );
	fs.writeFileSync( dir + '/child-order-ids.json', JSON.stringify( [ orderId ] ) );

	/* ---------------- orphan removal ---------------- */
	const context2 = await browser.newContext();
	const req = context2.request;
	const add = await req.post( base + '/product/opf-linked-parent/', { form: {
		'add-to-cart': String( state.parent ), quantity: '1',
		[ `opf[${ gid }][linked_cards][]` ]: 'child-alpha',
	} } );
	check( 'orphan-phase add request succeeds', add.ok() );
	let c2 = await ( await req.get( base + '/wp-json/wc/store/v1/cart' ) ).json();
	check( 'orphan phase: parent + 1 child', c2.items.length === 2 );
	const p2 = c2.items.find( ( i ) => ! i.extensions?.opf?.childItem )?.key;
	const n2 = ( await req.get( base + '/wp-json/wc/store/v1/cart' ) ).headers()[ 'nonce' ];
	await req.post( base + '/wp-json/wc/store/v1/cart/remove-item', { headers: { Nonce: n2 }, data: { key: p2 } } );
	c2 = await ( await req.get( base + '/wp-json/wc/store/v1/cart' ) ).json();
	check( 'removing the parent removes its orphaned child', c2.items.length === 0 );
	await context2.close();

	check( 'no uncaught storefront page errors', errors.length === 0 );
	fs.writeFileSync( dir + '/browser-results.json', JSON.stringify( { base, checks, errors }, null, 2 ) );
	console.log( 'SUCCESS linked child-products browser lifecycle' );
} finally {
	if ( ! checks.length || checks.some( ( c ) => ! c.pass ) ) fs.writeFileSync( dir + '/browser-results.json', JSON.stringify( { base, checks, errors }, null, 2 ) );
	await browser.close();
}
