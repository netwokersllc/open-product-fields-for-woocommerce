// Real Chromium proof for WAPF-UPLOAD-AJAX-UI residuals: builder authoring,
// thumbnail preview, a11y, and the upload -> cart -> order -> order-again chain.
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const require = createRequire( import.meta.url );
let chromium;
try {
	chromium = require( 'playwright' ).chromium;
} catch ( e ) {
	chromium = createRequire( '/tmp/opf-url-native-parity/package.json' )( 'playwright' ).chromium;
}

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8323';
const wpPath = process.env.OPF_WP_PATH || '/tmp/opf-image-uploadui-wp';
const output = process.env.OPF_UPLOAD_UI_PROOF_DIR || '/tmp/opf-lane-uploadui-evidence/browser';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Disposable loopback only' );
if ( wpPath !== '/tmp/opf-image-uploadui-wp' ) throw new Error( 'Disposable clone only' );
mkdirSync( output, { recursive: true } );

const checks = [];
const errors = [];
const check = ( label, pass, details = {} ) => {
	checks.push( { label, pass: !! pass, ...details } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ label }` );
	if ( ! pass ) throw new Error( label );
};
const wp = ( code ) => {
	const result = spawnSync( 'wp', [ '--path=' + wpPath, 'eval', code ], { encoding: 'utf8' } );
	if ( result.status ) throw new Error( result.stderr || result.stdout );
	return result.stdout.trim();
};
const wpFile = ( file ) => {
	const result = spawnSync( 'wp', [ '--path=' + wpPath, 'eval-file', file ], { encoding: 'utf8' } );
	if ( result.status ) throw new Error( result.stderr || result.stdout );
	return result.stdout.trim();
};

const png = Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=', 'base64' );
const pdf = Buffer.from( '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF', 'utf8' );

const login = async ( browser, user, pass ) => {
	const context = await browser.newContext();
	const page = await context.newPage();
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	await page.goto( base + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.locator( '#user_login' ).fill( user );
	await page.locator( '#user_pass' ).fill( pass );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'domcontentloaded' } ), page.locator( '#wp-submit' ).click() ] );
	if ( page.url().includes( 'wp-login.php' ) ) throw new Error( 'Login failed for ' + user );
	return { context, page };
};

const fixture = JSON.parse( wpFile( '/tmp/opf-lane-uploadui/bin/e2e-upload-ui-fixture.php' ) );
const { product_id: productId, group_id: groupId } = fixture;
console.log( 'fixture', fixture );
const browser = await chromium.launch( { headless: true } );
let buyerId = 0;
try {
	// ---- Builder authoring surface --------------------------------------
	const admin = await login( browser, process.env.OPF_ADMIN_USER || 'admin', process.env.OPF_ADMIN_PASS || 'admin' );
	await admin.page.goto( base + `/wp-admin/post.php?post=${ groupId }&action=edit`, { waitUntil: 'domcontentloaded' } );
	await admin.page.waitForSelector( '#opf-builder-app .opf-b-field-head', { timeout: 15000 } );
	await admin.page.waitForTimeout( 500 );
	const firstCard = admin.page.locator( '.opf-b-field' ).first();
	const typeSel = firstCard.locator( 'select' ).first();
	check( 'builder exposes the upload type', await typeSel.locator( 'option[value="upload"]' ).count() === 1 && await typeSel.inputValue() === 'upload' );
	check( 'builder renders the upload option surface', await firstCard.locator( '[data-opf-upload-setting="multiple"]' ).count() === 1 && await firstCard.locator( '[data-opf-upload-setting="accepted_types"]' ).count() === 1 && await firstCard.locator( '[data-opf-upload-setting="max_size"]' ).count() === 1 );
	check( 'builder reflects stored multiple flag', await firstCard.locator( '[data-opf-upload-setting="multiple"]' ).isChecked() );
	const typesBox = firstCard.locator( '[data-opf-upload-setting="accepted_types"]' );
	check( 'builder reflects stored accepted types', await typesBox.locator( 'option[value="png"]' ).evaluate( ( option ) => option.selected ) && await typesBox.locator( 'option[value="pdf"]' ).evaluate( ( option ) => option.selected ) );
	check( 'builder reflects stored max size', await firstCard.locator( '[data-opf-upload-setting="max_size"]' ).inputValue() === '1' );

	// Exercise authoring: add DOCX to the allow-list and raise the size cap.
	await typesBox.selectOption( [ 'png', 'pdf', 'docx' ] );
	await firstCard.locator( '[data-opf-upload-setting="max_size"]' ).fill( '2' );
	const saved = admin.page.waitForResponse( ( response ) => response.url().includes( '/opf/v1/groups' ) && response.request().method() === 'POST' );
	await admin.page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	check( 'builder upload save succeeds', ( await saved ).ok() );
	await admin.page.reload( { waitUntil: 'domcontentloaded' } );
	await admin.page.waitForSelector( '#opf-builder-app .opf-b-field-head', { timeout: 15000 } );
	await admin.page.waitForTimeout( 500 );
	const reloadedCard = admin.page.locator( '.opf-b-field' ).first();
	check( 'builder upload options survive reload', await reloadedCard.locator( '[data-opf-upload-setting="max_size"]' ).inputValue() === '2' && await reloadedCard.locator( '[data-opf-upload-setting="accepted_types"] option[value="docx"]' ).evaluate( ( option ) => option.selected ) );
	await admin.page.screenshot( { path: output + '/builder-upload.png', fullPage: true } );
	await admin.context.close();

	// ---- Storefront upload UI + preview + a11y --------------------------
	const context = await browser.newContext();
	const page = await context.newPage();
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	await page.goto( base + '/?product=opf-upload-ui-product', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-opf-upload-ready="1"]' );
	const art = page.locator( `[data-opf-field="art"] .opf-upload` );
	const artInput = page.locator( `#opf-${ groupId }-art` );
	check( 'modern multi input renders with merged allow-list', await art.getAttribute( 'data-opf-upload' ) === 'modern' && await artInput.getAttribute( 'multiple' ) !== null && ( await artInput.getAttribute( 'accept' ) ).includes( '.png' ) && ( await artInput.getAttribute( 'accept' ) ).includes( '.docx' ) );
	check( 'file input is labelled and described by the drop hint', ( await artInput.evaluate( ( input ) => [ ...input.labels ].map( ( label ) => label.textContent ).join( ' ' ) ) ).includes( 'Artwork' ) && Boolean( await artInput.getAttribute( 'aria-describedby' ) ) && ( await page.locator( '#' + ( await artInput.getAttribute( 'aria-describedby' ) ) ).count() ) === 1 );
	check( 'upload status is a polite live region', await art.locator( '.opf-upload__status' ).getAttribute( 'role' ) === 'status' && await art.locator( '.opf-upload__status' ).getAttribute( 'aria-live' ) === 'polite' );

	await artInput.setInputFiles( { name: 'opal.png', mimeType: 'image/png', buffer: png } );
	await page.waitForFunction( () => document.querySelectorAll( '[data-opf-field="art"] [data-opf-upload-token]' ).length === 1 );
	const thumb = page.locator( '[data-opf-field="art"] .opf-upload__thumb' );
	const thumbSrc = await thumb.getAttribute( 'src' );
	check( 'image upload shows an inline blob thumbnail', await thumb.count() === 1 && thumbSrc.startsWith( 'blob:' ) && await thumb.evaluate( ( image ) => image.complete && image.naturalWidth > 0 ) );
	check( 'preview row is labelled with the file name and a Remove control', await page.locator( '[data-opf-field="art"] .opf-upload__name' ).first().textContent() === 'opal.png' && await page.getByRole( 'button', { name: 'Remove opal.png', exact: true } ).count() === 1 );

	await artInput.setInputFiles( { name: 'brief.pdf', mimeType: 'application/pdf', buffer: pdf } );
	await page.waitForFunction( () => document.querySelectorAll( '[data-opf-field="art"] [data-opf-upload-token]' ).length === 2 );
	check( 'non-image upload keeps the name without an image preview', await page.locator( '[data-opf-field="art"] .opf-upload__thumb' ).count() === 1 && ( await page.locator( '[data-opf-field="art"] .opf-upload__name' ).allTextContents() ).includes( 'brief.pdf' ) );

	// Single-file field refuses a second file and announces why.
	const singleInput = page.locator( `#opf-${ groupId }-single` );
	await singleInput.setInputFiles( { name: 'one.png', mimeType: 'image/png', buffer: png } );
	await page.waitForFunction( () => document.querySelectorAll( '[data-opf-field="single"] [data-opf-upload-token]' ).length === 1 );
	await singleInput.setInputFiles( { name: 'two.png', mimeType: 'image/png', buffer: png } );
	await page.waitForFunction( () => document.querySelector( '[data-opf-field="single"] .opf-upload__status' ).textContent.length > 0 );
	check( 'single-file field blocks a second upload with a live announcement', await page.locator( '[data-opf-field="single"] [data-opf-upload-token]' ).count() === 1 && ( await page.locator( '[data-opf-field="single"] .opf-upload__status' ).textContent() ).length > 0 );

	// Keyboard removal works through the native button.
	await page.getByRole( 'button', { name: 'Remove brief.pdf', exact: true } ).focus();
	await page.keyboard.press( 'Enter' );
	await page.waitForFunction( () => document.querySelectorAll( '[data-opf-field="art"] [data-opf-upload-token]' ).length === 1 );
	check( 'remove control is keyboard operable', await page.locator( '[data-opf-field="art"] .opf-upload__thumb' ).count() === 1 );

	await page.screenshot( { path: output + '/storefront-desktop.png', fullPage: true } );
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.screenshot( { path: output + '/storefront-mobile.png', fullPage: true } );
	await page.setViewportSize( { width: 1280, height: 960 } );

	const artTokens = await page.locator( '[data-opf-field="art"] [data-opf-upload-token]' ).evaluateAll( ( inputs ) => inputs.map( ( input ) => input.value ) );
	const singleToken = await page.locator( '[data-opf-field="single"] [data-opf-upload-token]' ).inputValue();
	check( 'rendered tokens are opaque', [ ...artTokens, singleToken ].every( ( token ) => /^[a-f0-9]{64}$/.test( token ) ) );

	// ---- Upload -> classic cart -> Store API order ----------------------
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'domcontentloaded' } ), page.locator( 'button[name="add-to-cart"]' ).click() ] );
	check( 'classic add-to-cart carries the previewed upload to the cart', ( await page.locator( 'body' ).textContent() ).includes( 'opal.png' ) );

	let cart = await context.request.get( base + '/?rest_route=/wc/store/v1/cart' );
	let cartNonce = cart.headers().nonce;
	const cartData = await cart.json();
	check( 'Store API cart displays the uploaded file names', cartData.items.length === 1 && JSON.stringify( cartData.items[ 0 ].item_data ).includes( 'opal.png' ) );

	const checkout = await context.request.post( base + '/?rest_route=/wc/store/v1/checkout', {
		headers: { Nonce: cartNonce },
		data: {
			billing_address: { first_name: 'Upload', last_name: 'UI', company: '', address_1: '1 Proof Way', address_2: '', city: 'San Francisco', state: 'CA', postcode: '94103', country: 'US', email: 'opfuibuyer@example.test', phone: '5551234567' },
			payment_method: 'cod', payment_data: [], customer_note: 'Disposable upload UI proof',
		},
	} );
	const order = await checkout.json();
	check( 'checkout creates an order with the private upload', checkout.status() === 200 && order.order_id > 0 );
	const orderProof = JSON.parse( wp( `$o=wc_get_order(${ order.order_id });$i=array_values($o->get_items())[0];echo wp_json_encode(["uploads"=>$i->get_meta("_opf_uploads",true),"record"=>OPF\\Service\\Uploads::record("${ artTokens[0] }")]);` ) );
	check( 'order binds both rendered tokens and bytes stay private', orderProof.uploads.length === 2 && orderProof.uploads.some( ( file ) => file.token === artTokens[ 0 ] ) && orderProof.record.order_id === order.order_id );

	// ---- Order again: UI reflects the reissued file ---------------------
	buyerId = Number( wp( `$u=get_user_by("login","opfuibuyer");$id=$u?$u->ID:wp_create_user("opfuibuyer","Disposable-UI-Buyer","opfuibuyer@example.test");$o=wc_get_order(${ order.order_id });$o->set_customer_id($id);$o->set_billing_email("opfuibuyer@example.test");$o->set_status("completed");$o->save();echo $id;` ) );
	check( 'completed order assigned to a real customer', buyerId > 0 );
	const buyer = await login( browser, 'opfuibuyer', 'Disposable-UI-Buyer' );
	await buyer.page.goto( base + `/my-account/view-order/${ order.order_id }/`, { waitUntil: 'domcontentloaded' } );
	check( 'customer order page exposes Order again', await buyer.page.locator( 'p.order-again a' ).count() === 1 );
	await Promise.all( [ buyer.page.waitForNavigation( { waitUntil: 'domcontentloaded' } ), buyer.page.locator( 'p.order-again a' ).click() ] );
	check( 'order-again cart reflects the reordered upload name', ( await buyer.page.locator( 'body' ).textContent() ).includes( 'opal.png' ) );

	const buyerCart = await buyer.context.request.get( base + '/?rest_route=/wc/store/v1/cart' );
	const buyerNonce = buyerCart.headers().nonce;
	const secondCheckout = await buyer.context.request.post( base + '/?rest_route=/wc/store/v1/checkout', {
		headers: { Nonce: buyerNonce },
		data: {
			billing_address: { first_name: 'Upload', last_name: 'Reorder', company: '', address_1: '1 Proof Way', address_2: '', city: 'San Francisco', state: 'CA', postcode: '94103', country: 'US', email: 'opfuibuyer@example.test', phone: '5551234567' },
			payment_method: 'cod', payment_data: [], customer_note: 'Disposable upload UI reorder proof',
		},
	} );
	const secondOrder = await secondCheckout.json();
	check( 'order-again checkout creates a second order', secondCheckout.status() === 200 && secondOrder.order_id > 0 && secondOrder.order_id !== order.order_id );
	const secondProof = JSON.parse( wp( `$o=wc_get_order(${ secondOrder.order_id });$i=array_values($o->get_items())[0];$u=$i->get_meta("_opf_uploads",true);$opal=null;foreach($u as $f){if($f["name"]==="opal.png")$opal=$f["token"];}echo wp_json_encode(["uploads"=>$u,"opal"=>$opal,"record"=>$opal?OPF\\Service\\Uploads::record($opal):null]);` ) );
	check( 'order-again UI reissued a fresh session-owned token', secondProof.uploads.length === 2 && secondProof.opal && secondProof.opal !== artTokens[ 0 ] && secondProof.record.order_id === secondOrder.order_id && secondProof.record.reissued_from === artTokens[ 0 ] );
	await buyer.context.close();

	check( 'no browser runtime errors', errors.length === 0, { errors } );
	writeFileSync( output + '/browser-results.json', JSON.stringify( { base, wpPath, fixture, orderId: order.order_id, secondOrderId: secondOrder.order_id, artTokens, singleToken, checks }, null, 2 ) + '\n' );
	await context.close();
} catch ( error ) {
	writeFileSync( output + '/browser-results.json', JSON.stringify( { base, wpPath, fixture, checks, error: error.message, errors }, null, 2 ) + '\n' );
	throw error;
} finally {
	await browser.close();
	const cleanup = wpFile( '/tmp/opf-lane-uploadui/bin/e2e-upload-ui-cleanup.php' );
	console.log( 'cleanup', cleanup );
	writeFileSync( output + '/cleanup.json', cleanup + '\n' );
}
