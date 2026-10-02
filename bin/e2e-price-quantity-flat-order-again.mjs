// Real Chromium account flow: click WooCommerce's authenticated order-again link.
import fs from 'node:fs';
import { createRequire } from 'node:module';

if ( process.env.OPF_QFL_ORDER_AGAIN_ALLOW !== '1' ) throw new Error( 'Explicit disposable fixture guard required.' );
const { chromium } = createRequire( '/tmp/jsdom-test/index.js' )( 'playwright' );
const base = process.env.OPF_QFL_ORDER_AGAIN_BASE_URL || 'http://127.0.0.1:8207';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback disposable clone required.' );
const state = JSON.parse( fs.readFileSync( '/tmp/opf-qfl-order-again-artifacts/state.json', 'utf8' ) );
const checks = [], errors = [], observed = {};
const check = ( label, pass ) => {
	checks.push( { label, pass: !!pass } );
	console.log( `${pass ? 'ok' : 'FAIL'} ${label}` );
	if ( !pass ) throw new Error( label );
};

const browser = await chromium.launch();
try {
	for ( const engine of [ 'wapf', 'opf' ] ) {
		const context = await browser.newContext();
		const page = await context.newPage();
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		await page.goto( `${base}/?qfl_order_again_login=1` );
		const loginProbe = await ( await context.request.get( `${base}/?qfl_cart_probe=1` ) ).json();
		check( `${engine} loopback login authenticated the isolated customer`, loginProbe.authenticated && loginProbe.user_id === state.user );
		const orderId = state.orders[ engine ];
		const orderUrl = new URL( state.order_urls[engine] );
		if ( orderUrl.origin !== base ) throw new Error( 'Order endpoint must stay on the disposable loopback clone.' );
		await page.goto( orderUrl.href );
		const action = page.getByRole( 'link', { name: 'Order again', exact: true } );
		check( `${engine} authenticated My Account displays actual Order again action`, await action.count() === 1 );
		const href = await action.getAttribute( 'href' );
		check( `${engine} action targets this completed order with Woo nonce`, href.includes( `order_again=${orderId}` ) && href.includes( '_wpnonce=' ) );
		const [ navigation ] = await Promise.all( [ page.waitForNavigation( { waitUntil: 'domcontentloaded' } ), action.click() ] );
		const actualCartUrl = new URL( page.url() );
		const expectedCartUrl = new URL( state.cart_url );
		const isCart = actualCartUrl.origin === base && ( actualCartUrl.pathname === expectedCartUrl.pathname || actualCartUrl.searchParams.get( 'page_id' ) === expectedCartUrl.searchParams.get( 'page_id' ) );
		check( `${engine} real Order again click redirects to cart`, isCart && navigation.status() === 200 );
		await page.screenshot( { path: `/tmp/opf-qfl-order-again-${engine}-cart.png`, fullPage: true } );
		const body = await page.locator( 'body' ).innerText();
		check( `${engine} cart page confirms WooCommerce restored the prior order`, body.includes( 'The cart has been filled with the items from your previous order.' ) );
		const probeResponse = await context.request.get( `${base}/?qfl_cart_probe=1` );
		check( `${engine} authenticated cart probe responds`, probeResponse.ok() );
		const probe = await probeResponse.json();
		console.log( `${engine} observed server cart line`, JSON.stringify( probe.lines[0] ?? null ) );
		check( `${engine} cart probe remains the fixture customer`, probe.authenticated && probe.user_id === state.user );
		check( `${engine} order-again restores exactly one q=3 item`, probe.lines.length === 1 && probe.lines[0].quantity === 3 );
		const line = probe.lines[0];
		check( `${engine} restored fixture product has $31.005 line subtotal and total`, line.product_id === state.products[engine === 'wapf' ? 0 : 1] && line.line_subtotal === 31.005 && line.line_total === 31.005 );
		check( `${engine} line tax recalculates to $2.56`, line.line_subtotal_tax === 2.56 && line.line_tax === 2.56 );
		if ( engine === 'wapf' ) {
			check( 'WAPF order-again restores the native qt choice', line.wapf?.[0]?.values?.[0]?.slug === 'qtyflat' && line.wapf?.[0]?.values?.[0]?.price_type === 'qt' );
		} else {
			check( 'OPF order-again restores its native selected choice', line.opf_fields?.[String( state.groups[0] )]?.plan === 'qtyflat' );
		}
		observed[engine] = { quantity: line.quantity, line_subtotal: line.line_subtotal, line_subtotal_tax: line.line_subtotal_tax, line_total: line.line_total, line_tax: line.line_tax, restored_choice: 'qtyflat' };
		await context.close();
	}
	check( 'no uncaught Chromium page errors', errors.length === 0 );
} finally {
	fs.writeFileSync( new URL( '../docs/compatibility/qfl-order-again-browser-results.json', import.meta.url ), JSON.stringify( { base, checks, errors, observed }, null, 2 ) + '\n' );
	await browser.close();
}
