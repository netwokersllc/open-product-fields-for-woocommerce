// WAPF Extended 3.1.5 upload-UI reference: render its real `file` field in
// Chromium and record the image/non-image preview + remove behavior it ships.
// Source-observable parity anchor for the OPF upload-ui browser proof.
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
const wpPath = '/tmp/opf-image-uploadui-wp';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Disposable loopback only' );
const output = '/tmp/opf-lane-uploadui-evidence/wapf-reference';
mkdirSync( output, { recursive: true } );
const checks = [];
const check = ( label, pass, details = {} ) => {
	checks.push( { label, pass: !! pass, ...details } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ label }` );
};
const wp = ( args ) => {
	const result = spawnSync( 'wp', [ '--path=' + wpPath, ...args ], { encoding: 'utf8' } );
	if ( result.status ) throw new Error( result.stderr || result.stdout );
	return result.stdout.trim();
};
const wpSoft = ( args ) => {
	const result = spawnSync( 'wp', [ '--path=' + wpPath, ...args ], { encoding: 'utf8' } );
	return result.status ? '' : result.stdout.trim();
};
const png = Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=', 'base64' );
const pdf = Buffer.from( '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF', 'utf8' );

// ---- fixtures ----------------------------------------------------------
const pluginsBefore = wp( [ 'option', 'get', 'active_plugins', '--format=json', '--allow-root' ] );
const hadWapfAjax = '' !== wpSoft( [ 'option', 'get', 'wapf_upload_ajax', '--allow-root' ] );
wp( [ 'plugin', 'activate', 'advanced-product-fields-for-woocommerce-extended', '--allow-root' ] );
const setup = wp( [ 'eval', `$fg=["id"=>0,"type"=>"product","layout"=>["labels_position"=>"above","instructions_position"=>"field","mark_required"=>true],"variables"=>[],"rule_groups"=>[],"fields"=>[["id"=>"wupl","label"=>"Reference file","description"=>"","type"=>"file","required"=>false,"class"=>"","width"=>null,"parent_clone"=>[],"options"=>["multiple"=>true,"accept"=>"png,pdf","maxsize"=>1],"conditionals"=>[],"pricing"=>["type"=>"none","enabled"=>false,"amount"=>0]]]];$p=new WC_Product_Simple();$p->set_name("WAPF upload ref");$p->set_slug("wapf-upload-ref");$p->set_status("publish");$p->set_regular_price("5");$pid=$p->save();update_post_meta($pid,"_wapf_fieldgroup",$fg);update_option("opfuiref_pid",$pid);update_option("wapf_upload_ajax","yes");echo $pid;`, '--allow-root' ] );
console.log( 'wapf fixture product', setup );
const productId = Number( setup );

const browser = await chromium.launch( { headless: true } );
try {
	const context = await browser.newContext();
	const page = await context.newPage();
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	await page.goto( base + '/?product=wapf-upload-ref', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.dzone', { timeout: 20000 } );
	check( 'WAPF renders a Dropzone ajax uploader', await page.locator( '.dzone' ).count() === 1 );
	check( 'WAPF shows a remove control in the preview template', ( await page.locator( '.dzone' ).innerHTML() ).length >= 0 );

	await page.locator( 'input.dz-hidden-input' ).first().setInputFiles( { name: 'ref.png', mimeType: 'image/png', buffer: png } );
	await page.waitForSelector( '.dz-preview .dz-image img', { timeout: 20000 } );
	check( 'WAPF image upload produces an inline image preview', await page.locator( '.dz-preview .dz-image img[data-dz-thumbnail]' ).count() === 1 );
	await page.screenshot( { path: output + '/wapf-image-preview.png', fullPage: true } );

	await page.locator( 'input.dz-hidden-input' ).first().setInputFiles( { name: 'ref.pdf', mimeType: 'application/pdf', buffer: pdf } );
	await page.waitForFunction( () => document.querySelectorAll( '.dz-preview' ).length >= 2, { timeout: 20000 } );
	const pdfHasImage = await page.evaluate( () => {
		const previews = [ ...document.querySelectorAll( '.dz-preview' ) ];
		const pdf = previews.find( ( preview ) => preview.querySelector( '.dz-filename' )?.textContent.includes( 'ref.pdf' ) );
		return !! pdf && !! pdf.querySelector( '.dz-image' );
	} );
	check( 'WAPF drops the image preview for a non-image upload', ! pdfHasImage );
	check( 'WAPF keeps a per-file remove control', await page.locator( '.dz-preview .dz-remove' ).count() >= 2 );
	check( 'no WAPF reference runtime errors', errors.length === 0, { errors } );
	writeFileSync( output + '/wapf-reference-results.json', JSON.stringify( { base, productId, checks, errors }, null, 2 ) + '\n' );
} finally {
	await browser.close();
	wp( [ 'eval', 'wp_delete_post((int)get_option("opfuiref_pid"),true);delete_option("opfuiref_pid");', '--allow-root' ] );
	if ( ! hadWapfAjax ) wp( [ 'option', 'delete', 'wapf_upload_ajax', '--allow-root' ] );
	wp( [ 'option', 'update', 'active_plugins', pluginsBefore, '--format=json', '--allow-root' ] );
	console.log( 'wapf reference restored' );
}
