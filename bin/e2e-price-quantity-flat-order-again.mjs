// Real Chromium account flow: click WooCommerce's authenticated order-again link.
import fs from 'node:fs';
import { randomBytes } from 'node:crypto';
import { dirname } from 'node:path';
import { createRequire } from 'node:module';

if ( process.env.OPF_QFL_ORDER_AGAIN_ALLOW !== '1' ) throw new Error( 'Explicit disposable fixture guard required.' );
const { chromium } = createRequire( '/tmp/jsdom-test/index.js' )( 'playwright' );
const base = process.env.OPF_QFL_ORDER_AGAIN_BASE_URL || 'http://127.0.0.1:8207';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback disposable clone required.' );
const statePath = '/tmp/opf-qfl-order-again-artifacts/state.json';
if ( fs.lstatSync( statePath ).isSymbolicLink() ) throw new Error( 'Refusing symlink fixture state artifact.' );
const state = JSON.parse( fs.readFileSync( statePath, 'utf8' ) );
const checks = [], errors = [], observed = {};
const expectedLabels = [
	'wapf loopback login authenticated the isolated customer',
	'wapf authenticated My Account displays actual Order again action',
	'wapf action targets this completed order with Woo nonce',
	'wapf real Order again click redirects to cart',
	'wapf cart page confirms WooCommerce restored the prior order',
	'wapf authenticated cart probe responds',
	'wapf cart probe remains the fixture customer',
	'wapf order-again restores exactly one q=3 item',
	'wapf restored fixture product has $31.005 line subtotal and total',
	'wapf line tax recalculates to $2.56',
	'WAPF order-again restores the native qt choice',
	'opf loopback login authenticated the isolated customer',
	'opf authenticated My Account displays actual Order again action',
	'opf action targets this completed order with Woo nonce',
	'opf real Order again click redirects to cart',
	'opf cart page confirms WooCommerce restored the prior order',
	'opf authenticated cart probe responds',
	'opf cart probe remains the fixture customer',
	'opf order-again restores exactly one q=3 item',
	'opf restored fixture product has $31.005 line subtotal and total',
	'opf line tax recalculates to $2.56',
	'OPF order-again restores its native selected choice',
	'no uncaught Chromium page errors',
];
const check = ( label, pass ) => {
	checks.push( { label, pass: !!pass } );
	console.log( `${pass ? 'ok' : 'FAIL'} ${label}` );
	if ( !pass ) throw new Error( label );
};
const safeAtomicWrite = ( path, data ) => {
	const rejectSymlink = ( candidate ) => {
		try { if ( fs.lstatSync( candidate ).isSymbolicLink() ) throw new Error( `Refusing symlink artifact path: ${candidate}` ); }
		catch ( error ) { if ( error.code !== 'ENOENT' ) throw error; }
	};
	const directory = dirname( path );
	rejectSymlink( directory );
	rejectSymlink( path );
	const temp = `${path}.${process.pid}.${randomBytes( 8 ).toString( 'hex' )}.tmp`;
	const fd = fs.openSync( temp, 'wx', 0o600 );
	try { fs.writeFileSync( fd, data ); fs.fsyncSync( fd ); } catch ( error ) { fs.closeSync( fd ); fs.rmSync( temp, { force: true } ); throw error; }
	fs.closeSync( fd );
	try { rejectSymlink( path ); fs.renameSync( temp, path ); } catch ( error ) { fs.rmSync( temp, { force: true } ); throw error; }
};

const browser = await chromium.launch();
try {
	for ( const engine of [ 'wapf', 'opf' ] ) {
		const context = await browser.newContext();
		const page = await context.newPage();
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		await page.goto( `${base}/?qfl_order_again_login=1&engine=${engine}&token=${encodeURIComponent( state.login_tokens[engine] )}` );
		const accountUrl = new URL( page.url() );
		const accountGreeting = await page.locator( '.woocommerce-MyAccount-content' ).innerText().catch( () => '' );
		check( `${engine} loopback login authenticated the isolated customer`, accountUrl.origin === base && accountGreeting.includes( 'Hello' ) );
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
		const screenshot = await page.screenshot( { fullPage: true } );
		safeAtomicWrite( `/tmp/opf-qfl-order-again-${engine}-cart.png`, screenshot );
		const body = await page.locator( 'body' ).innerText();
		check( `${engine} cart page confirms WooCommerce restored the prior order`, body.includes( 'The cart has been filled with the items from your previous order.' ) );
		const probeResponse = await context.request.get( `${base}/?qfl_cart_probe=1&engine=${engine}&token=${encodeURIComponent( state.probe_tokens[engine] )}` );
		check( `${engine} authenticated cart probe responds`, probeResponse.ok() );
		const probe = await probeResponse.json();
		console.log( `${engine} observed server cart line`, JSON.stringify( probe.lines[0] ?? null ) );
		check( `${engine} cart probe remains the fixture customer`, probe.authenticated && probe.user_id === state.user );
		check( `${engine} order-again restores exactly one q=3 item`, probe.lines.length === 1 && probe.lines[0].quantity === 3 );
		const line = probe.lines[0];
		check( `${engine} restored fixture product has $31.005 line subtotal and total`, line.product_id === state.products[engine === 'wapf' ? 0 : 1] && line.line_subtotal === 31.005 && line.line_total === 31.005 );
		check( `${engine} line tax recalculates to $2.56`, line.line_subtotal_tax === 2.56 && line.line_tax === 2.56 );
		let restoredChoice;
		if ( engine === 'wapf' ) {
			restoredChoice = { slug: line.wapf?.[0]?.values?.[0]?.slug, price_type: line.wapf?.[0]?.values?.[0]?.price_type };
			check( 'WAPF order-again restores the native qt choice', restoredChoice.slug === 'qtyflat' && restoredChoice.price_type === 'qt' );
		} else {
			restoredChoice = { slug: line.opf_fields?.[String( state.groups[0] )]?.plan };
			check( 'OPF order-again restores its native selected choice', restoredChoice.slug === 'qtyflat' );
		}
		observed[engine] = { quantity: line.quantity, line_subtotal: line.line_subtotal, line_subtotal_tax: line.line_subtotal_tax, line_total: line.line_total, line_tax: line.line_tax, restored_choice: restoredChoice };
		await context.close();
	}
	check( 'no uncaught Chromium page errors', errors.length === 0 );
} finally {
	await browser.close();
}

if ( checks.length !== expectedLabels.length || checks.some( ( entry, index ) => entry.label !== expectedLabels[index] || !entry.pass ) || errors.length || Object.keys( observed ).length !== 2 ) {
	throw new Error( 'Browser result did not reach the exact complete successful check set; refusing artifact write.' );
}
const artifact = { completed: true, run_id: state.run_id, base, checks, errors, observed, runtime: state.runtime, orders: state.orders, suppressed_mail_calls: state.suppressed_mail_calls };
safeAtomicWrite( new URL( '../docs/compatibility/qfl-order-again-browser-results.json', import.meta.url ).pathname, JSON.stringify( artifact, null, 2 ) + '\n' );
