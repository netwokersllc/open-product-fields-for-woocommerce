// Cards + image-zoom cluster storefront lifecycle on the disposable clone:
// card render → card-qty conditionals show/hide → card click swaps the main
// image (gallery rule + field swap) and restores → image-quantity/swatch zoom
// attrs + hover enlargement → classic add-to-cart → child lines → checkout.
import fs from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire( process.cwd() + '/index.js' );
const { chromium } = require( 'playwright' );
const base = process.env.OPF_CARDS_BASE_URL || 'http://127.0.0.1:8308';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback clone required.' );
const dir = process.env.OPF_CARDS_ARTIFACT_DIR || '/tmp/opf-lane-cards-evidence';
fs.mkdirSync( dir, { recursive: true } );
const state = JSON.parse( fs.readFileSync( dir + '/cards-zoom-state.json' ) );
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

	await page.goto( base + '/product/opf-zoom-parent/', { waitUntil: 'domcontentloaded' } );
	await page.locator( '.opf-products--card .opf-card' ).first().waitFor();

	/* ---------------- render ---------------- */
	check( 'card field renders two product cards', await page.locator( '.opf-products--card .opf-card' ).count() === 2 );
	check( 'quantity card field renders two cards', await page.locator( '.opf-products--card-qty .opf-card' ).count() === 2 );
	check( 'group emits WAPF gallery-image attributes', await page.locator( '[data-opf-group="' + gid + '"][data-opf-gi]' ).count() === 1 );
	check( 'gallery swap type is rules', ( await page.locator( '[data-opf-group="' + gid + '"]' ).getAttribute( 'data-opf-st' ) ) === 'rules' );

	/* ---------------- card-qty conditionals ---------------- */
	const note = page.locator( '[data-opf-field="note"]' );
	check( 'dependent field starts hidden when no quantity is set', await note.evaluate( ( el ) => el.classList.contains( 'opf-hide' ) ) );
	const alphaQty = page.locator( '.opf-products--card-qty input.opf-qty[data-choice-slug="child-alpha"]' );
	await alphaQty.fill( '2' );
	await page.waitForFunction( () => ! document.querySelector( '[data-opf-field="note"]' ).classList.contains( 'opf-hide' ) );
	check( 'dependent field shows once a card quantity is positive', true );
	await alphaQty.fill( '0' );
	await page.waitForFunction( () => document.querySelector( '[data-opf-field="note"]' ).classList.contains( 'opf-hide' ) );
	check( 'dependent field hides again when quantity returns to zero', true );
	await page.screenshot( { path: dir + '/cards-conditionals.png', fullPage: true } );

	/* ---------------- zoom attributes + interaction ---------------- */
	check( 'image+quantity choices expose data-zoom-url', await page.locator( '[data-opf-field="prints"] .opf-image-quantity__img[data-zoom-url]' ).count() === 2 );
	check( 'image swatches expose data-zoom-url', await page.locator( '[data-opf-field="finish"] .opf-swatch--image-zoom[data-zoom-url]' ).count() === 2 );
	const iqWrap = page.locator( '[data-opf-field="prints"] .opf-image-quantity__img[data-zoom-url]' ).first();
	check( 'image+quantity zoom preview is hidden before hover', ! ( await iqWrap.locator( '.opf-swatch-zoom-preview' ).isVisible() ) );
	await iqWrap.hover();
	check( 'image+quantity zoom preview enlarges on hover', await iqWrap.locator( '.opf-swatch-zoom-preview' ).isVisible() );
	const swatchWrap = page.locator( '[data-opf-field="finish"] .opf-swatch--image-zoom[data-zoom-url]' ).first();
	check( 'image swatch zoom preview is hidden before hover', ! ( await swatchWrap.locator( '.opf-swatch-zoom-preview' ).isVisible() ) );
	await swatchWrap.hover();
	check( 'image swatch zoom preview enlarges on hover', await swatchWrap.locator( '.opf-swatch-zoom-preview' ).isVisible() );
	await page.screenshot( { path: dir + '/cards-zoom-hover.png', fullPage: true } );

	/* ---------------- main image swap / restore ---------------- */
	const mainImg = page.locator( '.woocommerce-product-gallery .wp-post-image' ).first();
	const originalSrc = await mainImg.getAttribute( 'src' );
	const alphaCard = page.locator( '.opf-products--card input.opf-product-input[value="child-alpha"]' );
	const betaCard = page.locator( '.opf-products--card input.opf-product-input[value="child-beta"]' );
	await alphaCard.check();
	await page.waitForFunction( () => {
		const img = document.querySelector( '.woocommerce-product-gallery .wp-post-image' );
		return img && img.getAttribute( 'src' ).includes( 'opf-oz-gallery' );
	} );
	check( 'selecting the alpha card applies the WAPF gallery rule image', true );
	await page.screenshot( { path: dir + '/cards-swap-rule.png', fullPage: true } );
	await alphaCard.uncheck();
	await betaCard.check();
	await page.waitForFunction( () => {
		const img = document.querySelector( '.woocommerce-product-gallery .wp-post-image' );
		return img && img.getAttribute( 'src' ).includes( 'opf-oz-beta' );
	} );
	check( 'an unmatched card falls back to its field swap image', true );
	await betaCard.uncheck();
	await page.waitForFunction( ( orig ) => {
		const img = document.querySelector( '.woocommerce-product-gallery .wp-post-image' );
		return img && img.getAttribute( 'src' ) === orig;
	}, originalSrc );
	check( 'deselecting every card restores the original main image', true );
	await page.screenshot( { path: dir + '/cards-restore.png', fullPage: true } );

	/* ---------------- cart: qty-enabled cards ---------------- */
	await alphaCard.check();
	await page.locator( '.opf-products--card-qty input.opf-qty[data-choice-slug="child-alpha"]' ).fill( '2' );
	await page.locator( 'form.cart button.single_add_to_cart_button' ).click();
	await page.locator( '.wc-block-components-notice-banner.is-success, .woocommerce-message' ).first().waitFor( { timeout: 15000 } );
	check( 'classic add-to-cart succeeds', true );

	const cartReq = await context.request.get( base + '/wp-json/wc/store/v1/cart' );
	const cart = await cartReq.json();
	const nonce = cartReq.headers()[ 'nonce' ];
	check( 'cart holds parent + two child lines', cart.items.length === 3 );
	const unit = cart.totals.currency_minor_unit;
	const childItems = cart.items.filter( ( i ) => i.extensions?.opf?.childItem );
	check( 'both children expose opf.childItem', childItems.length === 2 );
	check( 'card child qty 1 and qty-card child qty 2', childItems.map( ( i ) => i.quantity ).sort().join( ',' ) === '1,2' );
	check( 'cart total is parent 20 + 8 + 16 = 44', minor( cart.totals.total_price, unit ) === 44 );
	fs.writeFileSync( dir + '/cards-cart.json', JSON.stringify( cart.items.map( ( i ) => ( { name: i.name, qty: i.quantity, total: i.totals.line_total, child: !! i.extensions?.opf?.childItem } ) ), null, 2 ) );

	/* ---------------- real classic checkout ---------------- */
	await page.goto( base + '/zoom-proof-checkout/', { waitUntil: 'domcontentloaded' } );
	const checkoutNonce = await page.locator( '[name="woocommerce-process-checkout-nonce"]' ).inputValue();
	const checkout = await context.request.post( base + '/?wc-ajax=checkout', { form: {
		'woocommerce-process-checkout-nonce': checkoutNonce,
		billing_first_name: 'Test', billing_last_name: 'Buyer', billing_company: '',
		billing_country: 'US', billing_address_1: '1 Test Street', billing_address_2: '',
		billing_city: 'Testville', billing_state: 'CA', billing_postcode: '90210',
		billing_phone: '5551234567', billing_email: 'cards-zoom@example.invalid',
		payment_method: 'bacs', terms: 'on',
	} } );
	const body = await checkout.json();
	if ( body.result !== 'success' ) console.log( JSON.stringify( body ) );
	check( 'classic wc-ajax checkout succeeds', body.result === 'success' );
	const orderId = Number( body.redirect.match( /order-received\/(\d+)/ )?.[ 1 ] );
	check( 'persisted order id', orderId > 0 );
	fs.writeFileSync( dir + '/cards-order-id.json', JSON.stringify( orderId ) );
	fs.writeFileSync( dir + '/cards-order-ids.json', JSON.stringify( [ orderId ] ) );

	check( 'no uncaught storefront page errors', errors.length === 0 );
	fs.writeFileSync( dir + '/cards-browser-results.json', JSON.stringify( { base, gid, checks, errors }, null, 2 ) );
	console.log( 'SUCCESS cards-zoom browser lifecycle' );
} finally {
	if ( ! checks.length || checks.some( ( c ) => ! c.pass ) ) fs.writeFileSync( dir + '/cards-browser-results.json', JSON.stringify( { base, gid, checks, errors }, null, 2 ) );
	await browser.close();
}
