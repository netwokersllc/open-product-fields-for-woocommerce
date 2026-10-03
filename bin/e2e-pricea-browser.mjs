// Price-formula lifecycle browser proof. Real Chromium only, never production.
// For each fixture case: storefront render -> submitted value -> preview ->
// add to cart -> Store API cart line -> classic checkout -> durable order ->
// partial refund. Runs OPF and WAPF Extended 3.1.5 on the same products.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import crypto from 'node:crypto';

const runtime = '/tmp/opf-image-pricea-wp';
const base = 'http://127.0.0.1:8304';
const out = process.env.OPF_PRICEA_OUT;
if (process.env.OPF_PRICEA_ALLOW !== '1' || !out || !fs.existsSync(out) || fs.realpathSync(runtime) !== runtime || fs.realpathSync(runtime + '/wp-content/database/.ht.sqlite') !== runtime + '/wp-content/database/.ht.sqlite') throw new Error('Explicit owned pricea SQLite clone required');
const { chromium } = createRequire(process.env.OPF_PRICEA_PLAYWRIGHT || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json')('playwright');
const state = JSON.parse(fs.readFileSync(out + '/state.json'));
const wp = code => execFileSync('wp', ['--path=' + runtime, 'eval', code], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 }).trim();
const activate = provider => execFileSync('wp', ['--path=' + runtime, '--skip-plugins', '--skip-themes', 'option', 'update', 'active_plugins', JSON.stringify(['sqlite-database-integration/load.php', 'woocommerce/woocommerce.php', provider === 'wapf' ? 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' : 'open-product-fields-for-woocommerce/open-product-fields-for-woocommerce.php']), '--format=json'], { encoding: 'utf8' });
const uploadFixtures = out + '/upload-fixtures';
fs.mkdirSync(uploadFixtures, { recursive: true });
const fileA = uploadFixtures + '/pricea-a.txt', fileB = uploadFixtures + '/pricea-b.txt';
fs.writeFileSync(fileA, 'pricea upload proof file one\n');
fs.writeFileSync(fileB, 'pricea upload proof file two\n');

// expectedUnit = base 10 + formula addon (+priced choice where applicable).
const cases = [
	{ id: 'minmax', expectedUnit: 17, fill: { opf: async (p, g) => p.locator('#opf-' + g + '-x').fill('8'), wapf: async p => p.locator('input[name="wapf[field_x]"]').fill('8') } },
	{ id: 'textcmp', expectedUnit: 30, fill: { opf: async (p, g) => p.locator('#opf-' + g + '-size').selectOption('xl'), wapf: async p => p.locator('select[name="wapf[field_size]"]').selectOption('xl') } },
	{ id: 'advanced', expectedUnit: 12, fill: { opf: async (p, g) => p.locator('#opf-' + g + '-x').fill('2'), wapf: async p => p.locator('input[name="wapf[field_x]"]').fill('2') } },
	{ id: 'date', expectedUnit: 10 + Math.round(Math.abs(new Date('2026-01-15T00:00:00Z') - new Date(new Date().toISOString().slice(0, 10) + 'T00:00:00Z')) / 86400000), fill: { opf: async (p, g) => p.locator('#opf-' + g + '-d').fill('2026-01-15'), wapf: async p => { await p.locator('input[name="wapf[field_d]"]').fill('01-15-2026'); await p.keyboard.press('Escape'); await p.locator('body').click({ position: { x: 8, y: 8 } }); } } },
	{ id: 'dow', expectedUnit: 52, fill: { opf: async (p, g) => p.locator('#opf-' + g + '-d').fill('2026-10-03'), wapf: async p => { await p.locator('input[name="wapf[field_d]"]').fill('10-03-2026'); await p.keyboard.press('Escape'); await p.locator('body').click({ position: { x: 8, y: 8 } }); } } },
	{ id: 'month', expectedUnit: 19, fill: { opf: async (p, g) => p.locator('#opf-' + g + '-d').fill('2026-03-15'), wapf: async p => { await p.locator('input[name="wapf[field_d]"]').fill('03-15-2026'); await p.keyboard.press('Escape'); await p.locator('body').click({ position: { x: 8, y: 8 } }); } } },
	{ id: 'checked', expectedUnit: 18, fill: {
		opf: async (p, g) => { await p.locator('input[name="opf[' + g + '][opts][]"][value="a"]').check(); await p.locator('input[name="opf[' + g + '][opts][]"][value="b"]').check(); },
		wapf: async p => { await p.locator('input[name="wapf[field_opts][]"][value="a"]').check(); await p.locator('input[name="wapf[field_opts][]"][value="b"]').check(); },
	} },
	{ id: 'sumqty', expectedUnit: 15, fill: {
		opf: async (p, g) => { await p.locator('input[name="opf[' + g + '][prints][oak]"]').fill('2'); await p.locator('input[name="opf[' + g + '][prints][ash]"]').fill('3'); },
		wapf: async p => { await p.locator('input[name="wapf[field_prints_oak]"]').fill('2'); await p.locator('input[name="wapf[field_prints_ash]"]').fill('3'); },
	} },
	{ id: 'trig', expectedUnit: 110, fill: { opf: async (p, g) => p.locator('#opf-' + g + '-x').fill('0'), wapf: async p => p.locator('input[name="wapf[field_x]"]').fill('0') } },
	{ id: 'priceid', expectedUnit: 25, fill: { opf: async (p, g) => p.locator('#opf-' + g + '-plan').selectOption('premium'), wapf: async p => p.locator('select[name="wapf[field_plan]"]').selectOption('premium') } },
	{ id: 'len', expectedUnit: 12, fill: { opf: async (p, g) => p.locator('#opf-' + g + '-t').fill('  a b '), wapf: async p => p.locator('input[name="wapf[field_t]"]').fill('  a b ') } },
	{ id: 'filestate', expectedUnit: { opf: 10, wapf: 16 }, fill: {
		opf: async (p, g) => {
			// Native upload mode: files ride the multipart add-to-cart POST.
			await p.locator('#opf-' + g + '-docs').setInputFiles([fileA, fileB]);
			await p.waitForTimeout(300);
		},
		wapf: async p => p.locator('input[name="wapf[field_docs][]"]').setInputFiles([fileA, fileB]),
	} },
];
const shotCases = new Set(['minmax', 'sumqty', 'filestate', 'len']);
// Optional narrowing for re-runs: OPF_PRICEA_ONLY=wapf:date,dow,month.
const only = (process.env.OPF_PRICEA_ONLY || '').split(':');
const onlyProviders = only[0] ? only[0].split(',').filter(Boolean) : null;
const onlyCases = only[1] ? only[1].split(',').filter(Boolean) : null;
const existingResults = fs.existsSync(out + '/browser-lifecycle-results.json') ? JSON.parse(fs.readFileSync(out + '/browser-lifecycle-results.json', 'utf8')) : null;
const results = onlyCases && existingResults ? existingResults : { utc: new Date().toISOString(), runtime: state.runtime, cases: [], order_again: [], errors: [] };
const write = () => fs.writeFileSync(out + '/browser-lifecycle-results.json', JSON.stringify(results, null, 2));
const browser = await chromium.launch({ executablePath: process.env.OPF_PRICEA_CHROMIUM || '/home/followersya-5hqi7/.cache/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-linux64/chrome-headless-shell' });
try {
	for (const provider of ['wapf', 'opf']) {
		if (onlyProviders && !onlyProviders.includes(provider)) continue;
		activate(provider);
		for (const c of cases) {
			if (onlyCases && !onlyCases.includes(c.id)) continue;
			const gid = state.groups[c.id], pid = state.products[c.id];
			const expected = typeof c.expectedUnit === 'object' ? c.expectedUnit[provider] : c.expectedUnit;
			const row = { provider, case: c.id, product: pid, group: gid, expected_unit: expected, pageErrors: [], consoleErrors: [] };
			results.cases = results.cases.filter(r => !(r.provider === provider && r.case === c.id));
			results.cases.push(row); write();
			const context = await browser.newContext(); const page = await context.newPage();
			page.on('pageerror', e => row.pageErrors.push(e.message));
			page.on('console', m => { if (m.type() === 'error') row.consoleErrors.push(m.text()); });
			try {
				const response = await page.goto(base + '/?p=' + pid, { waitUntil: 'networkidle' });
				row.http_status = response.status();
				row.rendered = await page.locator('form.cart').innerText().catch(() => '');
				await c.fill[provider](page, gid);
				const feeSel = provider === 'wapf' ? 'select[name="wapf[field_fee]"]' : '#opf-' + gid + '-fee';
				await page.locator(feeSel).selectOption('a');
				await page.locator('input.qty').fill('1'); await page.locator('input.qty').dispatchEvent('change');
				await page.waitForTimeout(400);
				row.preview = await page.locator(provider === 'wapf' ? '.wapf-product-totals' : '.opf-product-totals').innerText().catch(() => '');
				if (shotCases.has(c.id)) await page.screenshot({ path: out + '/' + provider + '-' + c.id + '.png', fullPage: true });
				await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('button[name="add-to-cart"],button.single_add_to_cart_button').click()]);
				const cartResponse = await context.request.get(base + '/?rest_route=/wc/store/v1/cart');
				const cart = await cartResponse.json();
				if (cartResponse.status() !== 200 || cart.items?.length !== 1) throw new Error('Expected one real cart line: ' + JSON.stringify(cart).slice(0, 500));
				row.cart = { quantity: cart.items[0].quantity, unit_price: cart.items[0].prices.price, line_total: cart.items[0].totals.line_total, total: cart.totals.total_price, item_data: cart.items[0].item_data };
				row.cart_unit_ok = Number(cart.items[0].prices.price) === expected * 100;
				await page.goto(base + '/checkout/', { waitUntil: 'networkidle' });
				await page.locator('#billing_first_name').fill('Pricea'); await page.locator('#billing_last_name').fill('Proof');
				await page.locator('#billing_address_1').fill('123 Proof Street'); await page.locator('#billing_city').fill('San Francisco');
				await page.locator('#billing_postcode').fill('94103'); await page.locator('#billing_phone').fill('5551234567');
				await page.locator('#billing_email').fill('pricea-proof@example.test'); await page.locator('#billing_state').selectOption('CA');
				if (await page.locator('#payment_method_cod').count()) await page.locator('#payment_method_cod').check();
				await page.waitForTimeout(300);
				row.checkout_rendered = (await page.locator('#order_review').innerText()).slice(0, 600);
				let resolveCheckout;
				const checkoutResponse = new Promise(resolve => { resolveCheckout = resolve; });
				await page.route('**/*wc-ajax=checkout*', async route => {
					const resp = await route.fetch(); const data = await resp.json();
					await route.fulfill({ response: resp }); resolveCheckout(data);
				});
				await page.locator('#place_order').click();
				const checkout = await Promise.race([checkoutResponse, new Promise((_, reject) => setTimeout(() => reject(new Error('Checkout response timeout')), 25000))]);
				if (checkout.result !== 'success') throw new Error('Actual checkout failed: ' + JSON.stringify(checkout).slice(0, 500));
				row.checkout_result = checkout.result;
				const orderId = Number(new URL(checkout.redirect).searchParams.get('order-received') || checkout.redirect.match(/order-received\/(\d+)/)?.[1]);
				if (!orderId) throw new Error('Missing checkout generated order');
				row.order = JSON.parse(wp('$o=wc_get_order(' + orderId + ');$i=array_values($o->get_items())[0];$m=[];foreach($i->get_meta_data() as $d){$m[]=$d->get_data();}echo wp_json_encode(["id"=>$o->get_id(),"status"=>$o->get_status(),"quantity"=>$i->get_quantity(),"line_total"=>$i->get_total(),"order_total"=>$o->get_total(),"metadata"=>$m]);'));
				row.order_total_ok = Math.abs(Number(row.order.order_total) - expected) < 0.005;
				const refund = JSON.parse(wp('$r=wc_create_refund(["order_id"=>'+orderId+',"amount"=>2.00,"reason"=>"pricea browser partial refund"]);if(is_wp_error($r)){echo wp_json_encode(["error"=>$r->get_error_message()]);}else{$o=wc_get_order('+orderId+');echo wp_json_encode(["refund_id"=>$r->get_id(),"order_total"=>$o->get_total(),"refunded"=>$o->get_total_refunded()]);}'));
				row.refund = refund;
				row.field_meta_key = provider === 'wapf' ? '_wapf_meta' : '_opf_fields';
				row.has_field_meta = (row.order.metadata || []).some(m => m.key === row.field_meta_key);
				wp('$o=wc_get_order(' + orderId + ');if($o){$o->delete(true);}');
				console.log('OBSERVED', provider, c.id, 'unit=', row.cart.unit_price, 'order=', row.order.order_total, 'meta=', row.has_field_meta, 'refund=', JSON.stringify(row.refund));
			} catch (e) { row.failure = e.stack; results.errors.push(provider + ' ' + c.id + ': ' + e.message); write(); throw e; }
			finally { await context.close(); write(); }
		}
		// Order-again leg: real login, real order-again link, restored cart.
		if (!onlyCases || onlyCases.includes('orderagain')) {
			const row = { provider, pageErrors: [] };
			results.order_again = results.order_again.filter(r => r.provider !== provider);
			results.order_again.push(row); write();
			const context = await browser.newContext(); const page = await context.newPage();
			page.on('pageerror', e => row.pageErrors.push(e.message));
			try {
				const orderId = provider === 'wapf' ? state.wapf_account_order : state.account_order;
				await page.goto(base + '/wp-login.php');
				await page.locator('#user_login').fill('pricea-proof'); await page.locator('#user_pass').fill('pricea-proof-pass-123');
				await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('#wp-submit').click()]);
				await page.goto(base + '/my-account/view-order/' + orderId + '/', { waitUntil: 'networkidle' });
				const action = page.getByRole('link', { name: 'Order again', exact: true });
				row.action_found = await action.count() === 1;
				if (!row.action_found) throw new Error('No order-again link on view-order ' + orderId);
				row.action_href = await action.getAttribute('href');
				await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), action.click()]);
				row.landed = page.url();
				await page.screenshot({ path: out + '/' + provider + '-order-again-cart.png', fullPage: true });
				const cartResponse = await context.request.get(base + '/?rest_route=/wc/store/v1/cart');
				const cart = await cartResponse.json();
				row.restored = cart.items.map(i => ({ product_id: i.id, quantity: i.quantity, unit_price: i.prices.price, line_total: i.totals.line_total }));
				// Engines price [qty] differently: OPF multiplies the choice addon by
			// the line quantity (x=8 -> 7*3=21+10=31, len -> 2*3=6+10=16), while
			// WAPF stores a fixed per-unit calc_price (17 and 12 at any qty). The
			// restored cart must match each engine's own original unit price.
			row.expected = provider === 'wapf'
				? [ { product_id: state.products.minmax, unit: 17 }, { product_id: state.products.len, unit: 12 } ]
				: [ { product_id: state.products.minmax, unit: 31 }, { product_id: state.products.len, unit: 16 } ];
				row.restored_ok = row.expected.every(e => row.restored.some(r => r.product_id === e.product_id && r.quantity === 3 && Number(r.unit_price) === e.unit * 100));
				console.log('ORDER-AGAIN', provider, 'order=', orderId, 'restored=', JSON.stringify(row.restored));
			} catch (e) { row.failure = e.stack; results.errors.push(provider + ' order-again: ' + e.message); write(); throw e; }
			finally { await context.close(); write(); }
		}
	}
} finally { await browser.close(); write(); }
console.log('Recorded ' + results.cases.length + ' browser lifecycle cases + ' + results.order_again.length + ' order-again runs.');
