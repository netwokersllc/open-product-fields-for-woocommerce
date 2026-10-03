import { createRequire } from 'node:module';
import fs from 'node:fs';
const { chromium } = createRequire( process.cwd() + '/index.js' )( 'playwright' );
const base = process.env.OPF_VRULES_BASE || 'http://127.0.0.1:8309';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback clone only' );
const out = process.env.OPF_VRULES_ARTIFACT_DIR || '/tmp/opf-lane-variations-evidence/variation-rules';
const state = JSON.parse( fs.readFileSync( out + '/state.json', 'utf8' ) );
const checks = [];
const observations = {};
const errors = [];
const check = ( label, pass, detail ) => checks.push( { label, pass: !! pass, detail } );

const fieldHidden = async ( page, fid ) => page.evaluate( ( id ) => {
	const el = document.querySelector( '[data-opf-field="' + id + '"]' );
	if ( ! el ) return 'absent';
	return el.hasAttribute( 'hidden' ) || el.classList.contains( 'opf-hide' );
}, fid );

const browser = await chromium.launch( { headless: true } );
try {
	const page = await browser.newPage( { viewport: { width: 1280, height: 1000 } } );
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	await page.goto( state.parent_url, { waitUntil: 'networkidle' } );
	await page.waitForSelector( 'form.variations_form' );
	await page.waitForSelector( '[data-opf-field="vr_vonlyred"]', { state: 'attached' } );

	const snap = async () => ( {
		vOnlyRed: await fieldHidden( page, 'vr_vonlyred' ),
		vNotRed: await fieldHidden( page, 'vr_vnotred' ),
		vAttrRed: await fieldHidden( page, 'vr_vattrred' ),
		vAttrAny: await fieldHidden( page, 'vr_vattrany' ),
		vAlways: await fieldHidden( page, 'vr_valways' ),
		variationId: await page.inputValue( 'input.variation_id, input[name="variation_id"]' ),
	} );

	// Initial: no variation selected.
	let s = await snap();
	observations.initial = s;
	check( 'initial control field visible', s.vAlways === false );
	check( 'initial vOnlyRed hidden', s.vOnlyRed === true );
	check( 'initial vNotRed hidden', s.vNotRed === true );
	check( 'initial vAttrRed hidden', s.vAttrRed === true );
	check( 'initial vAttrAny hidden', s.vAttrAny === true );
	await page.screenshot( { path: out + '/storefront-initial.png', fullPage: true } );

	// Select Red.
	await page.selectOption( 'select[name="attribute_pa_opf_varcolor"]', 'red' );
	await page.waitForFunction( ( id ) => {
		const el = document.querySelector( 'input.variation_id, input[name="variation_id"]' );
		return el && String( el.value ) === String( id );
	}, String( state.red_var ) );
	await page.waitForTimeout( 150 );
	s = await snap();
	observations.red = s;
	check( 'red selected sets variation_id', String( state.red_var ) === s.variationId, s.variationId );
	check( 'red shows vOnlyRed', s.vOnlyRed === false );
	check( 'red hides vNotRed', s.vNotRed === true );
	check( 'red shows vAttrRed', s.vAttrRed === false );
	check( 'red shows vAttrAny', s.vAttrAny === false );
	check( 'red keeps control visible', s.vAlways === false );
	await page.screenshot( { path: out + '/storefront-red.png', fullPage: true } );

	// Select Blue.
	await page.selectOption( 'select[name="attribute_pa_opf_varcolor"]', 'blue' );
	await page.waitForFunction( ( id ) => {
		const el = document.querySelector( 'input.variation_id, input[name="variation_id"]' );
		return el && String( el.value ) === String( id );
	}, String( state.blue_var ) );
	await page.waitForTimeout( 150 );
	s = await snap();
	observations.blue = s;
	check( 'blue selected sets variation_id', String( state.blue_var ) === s.variationId, s.variationId );
	check( 'blue hides vOnlyRed (group not placed)', s.vOnlyRed === 'absent' || s.vOnlyRed === true );
	check( 'blue shows vNotRed', s.vNotRed === false );
	check( 'blue hides vAttrRed by strict variation gate', s.vAttrRed === true );
	check( 'blue shows vAttrAny', s.vAttrAny === false );
	await page.screenshot( { path: out + '/storefront-blue.png', fullPage: true } );

	// Back to Red and add to cart with a value in the variation-scoped field.
	await page.selectOption( 'select[name="attribute_pa_opf_varcolor"]', 'red' );
	await page.waitForFunction( ( id ) => {
		const el = document.querySelector( 'input.variation_id, input[name="variation_id"]' );
		return el && String( el.value ) === String( id );
	}, String( state.red_var ) );
	await page.waitForTimeout( 150 );
	const fieldName = 'opf[' + state.groups.vOnlyRed + '][vr_vonlyred]';
	await page.fill( '[data-opf-field="vr_vonlyred"] input[type="text"]', 'browser red value' );
	let postedBody = '';
	const capturePost = ( request ) => {
		if ( 'POST' === request.method() && request.url() === state.parent_url ) {
			postedBody = request.postData() || '';
		}
	};
	page.on( 'request', capturePost );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'networkidle' } ),
		page.click( 'button.single_add_to_cart_button' ),
	] );
	page.off( 'request', capturePost );
	observations.postedBodyHasField = postedBody.includes( fieldName ) && postedBody.includes( 'browser red value' );
	check( 'add-to-cart POST carries variation-scoped field', observations.postedBodyHasField );

	const cartUrl = base + '/cart/';
	await page.goto( cartUrl, { waitUntil: 'networkidle' } );
	const cartText = await page.locator( 'body' ).innerText();
	observations.cartNonEmpty = ! /cart is currently empty/i.test( cartText );
	check( 'cart contains the added variation item', observations.cartNonEmpty );
	observations.cartHasValue = cartText.includes( 'browser red value' );
	await page.screenshot( { path: out + '/cart-red.png', fullPage: true } );

	// Best-effort cart cleanup in the browser; the PHP fixture's cleanup phase
	// performs a targeted session sweep for the fixture products as well.
	for ( const selector of [ 'a.remove', 'button.wc-block-cart-item__remove-link', '.wc-block-cart-item__remove-link' ] ) {
		const controls = await page.locator( selector ).all();
		for ( const control of controls ) {
			await control.click().catch( () => {} );
			await page.waitForTimeout( 400 );
		}
	}
	observations.cartRemovedByBrowser = ( await page.locator( 'a.remove, .wc-block-cart-item__remove-link' ).count() ) === 0;
} finally {
	await browser.close();
	fs.writeFileSync( out + '/browser-results.json', JSON.stringify( {
		time: new Date().toISOString(),
		base,
		parent_url: state.parent_url,
		checks,
		observations,
		errors,
	}, null, 2 ) );
}
console.log( JSON.stringify( { checks, errors }, null, 2 ) );
if ( checks.some( ( c ) => ! c.pass ) || errors.length ) process.exitCode = 1;
