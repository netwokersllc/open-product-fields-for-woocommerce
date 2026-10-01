// Real Chromium proof on an isolated loopback WordPress/WooCommerce clone.
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire( process.cwd() + '/index.js' );
const { chromium } = require( 'playwright' );
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8142';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Disposable loopback only.' );
const state = JSON.parse( fs.readFileSync( process.env.OPF_PRODUCT_STATE_FILE || '/tmp/opf-product-state.json', 'utf8' ) );
const checks = [], errors = [];
const check = ( label, pass ) => {
	checks.push( { label, pass: !! pass } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ label }` );
	if ( ! pass ) throw new Error( label );
};
const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1280, height: 960 } } );
page.setDefaultTimeout( 10000 );
page.on( 'pageerror', error => errors.push( error.message ) );
try {
	await page.goto( base + '/?opf_product_login=1', { waitUntil: 'domcontentloaded' } );
	console.log( `admin URL ${ page.url() }` );
	console.log( `builder field count ${ await page.locator( '.opf-b-field' ).count() }` );
	await page.locator( '.opf-b-field' ).first().waitFor();
	for ( const index of [ 0, 1 ] ) {
		const field = page.locator( '.opf-b-field' ).nth( index );
		const row = field.locator( '.opf-b-choice' ).first();
		const control = row.getByRole( 'checkbox', { name: 'Unavailable' } );
		await control.check();
		check( `field ${ index + 1 } exposes labeled unavailable setting`, await control.isChecked() );
	}
	const save = page.waitForResponse( response => response.url().includes( '/opf/v1/groups' ) && response.request().method() === 'POST' );
	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	const saved = await save;
	check( 'actual admin REST save accepts disabled choices', saved.ok() );
	await page.getByText( 'Saved.', { exact: true } ).waitFor();
	await page.reload( { waitUntil: 'domcontentloaded' } );
	const model = JSON.parse( await page.locator( '#opf-builder-app' ).getAttribute( 'data-model' ) );
	check( 'admin reload preserves disabled choice and clears default state', model.fields[0].choices[0].disabled && ! model.fields[0].choices[0].selected );
	check( 'admin reload preserves disabled checkbox choice', model.fields[1].choices[0].disabled );
	await page.screenshot( { path: '/tmp/opf-disabled-choice-admin.png', fullPage: true } );

	await page.goto( base + '/product/opf-targeting-selected/', { waitUntil: 'domcontentloaded' } );
	const selectOption = page.locator( `select[name="opf[${ state.positive }][finish]"] option[value="unavailable"]` );
	const checkbox = page.locator( `input[name="opf[${ state.positive }][extras][]"][value="unavailable-extra"]` );
	check( 'storefront disables unavailable select option', await selectOption.isDisabled() );
	check( 'storefront disables unavailable checkbox input', await checkbox.isDisabled() );
	await page.screenshot( { path: '/tmp/opf-disabled-choice-product.png', fullPage: true } );

	const classic = await page.request.post( base + '/product/opf-targeting-selected/', { form: {
		'add-to-cart': String( state.selected ), quantity: '1',
		[ `opf[${ state.positive }][finish]` ]: 'unavailable',
	} } );
	check( 'classic forged disabled value is rejected', ( await classic.text() ).includes( 'includes unavailable choice' ) );
	check( 'classic rejection leaves cart empty', ( await ( await page.request.get( base + '/wp-json/wc/store/v1/cart' ) ).json() ).items.length === 0 );

	const initialStoreCart = await page.request.get( base + '/wp-json/wc/store/v1/cart' );
	const storeNonce = initialStoreCart.headers()[ 'nonce' ];
	const storeApi = await page.request.post( base + '/wp-json/wc/store/v1/cart/add-item', { headers: { Nonce: storeNonce }, data: {
		id: state.selected, quantity: 1,
		opf_fields: { [ String( state.positive ) ]: { finish: 'unavailable' } },
	} } );
	const storeApiBody = await storeApi.text();
	console.log( `Store API status ${ storeApi.status() }: ${ storeApiBody.slice( 0, 500 ) }` );
	check( 'Store API forged disabled value is rejected by OPF validation', storeApi.status() >= 400 && storeApiBody.includes( 'includes unavailable choice' ) );
	check( 'Store API rejection leaves cart empty', ( await ( await page.request.get( base + '/wp-json/wc/store/v1/cart' ) ).json() ).items.length === 0 );

	await page.request.post( base + '/product/opf-targeting-selected/', { form: {
		'add-to-cart': String( state.selected ), quantity: '1',
		[ `opf[${ state.positive }][finish]` ]: 'available',
		[ `opf[${ state.positive }][extras][]` ]: 'available-extra',
	} } );
	const availableCart = await ( await page.request.get( base + '/wp-json/wc/store/v1/cart' ) ).json();
	check( 'available classic choices still reach Woo cart', availableCart.items.length === 1 && JSON.stringify( availableCart.items[0].item_data ).includes( 'Available finish' ) && JSON.stringify( availableCart.items[0].item_data ).includes( 'Available extra' ) );
	check( 'browser console stays clean', errors.length === 0 );
	fs.writeFileSync( 'docs/compatibility/disabled-choice-browser-results.json', JSON.stringify( { checks, errors }, null, 2 ) + '\n' );
} finally {
	await browser.close();
}
