// Real-Chromium lifecycle proof for edit-in-cart (WAPF-INTERACTION-CART-EDIT)
// against the live fixture installed by bin/e2e-cartedit-lifecycle.php.
// Covers: classic cart edit link -> prefilled form -> modify -> cart replaced;
// block (Store API) cart edit link -> prefill -> update; upload token retain;
// and an order placed before the edit staying untouched.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
const require = createRequire('/tmp/opf-url-native-parity/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8318';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only');
const wpPath = process.env.OPF_WP_PATH || '/tmp/opf-image-cartedit-wp';
const dir = '/tmp/opf-lane-cartedit-evidence';
fs.mkdirSync(dir, { recursive: true });
const state = JSON.parse(fs.readFileSync('/tmp/opf-cartedit-e2e/state.json', 'utf8'));
const gid = String(state.edit_gid);
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=', 'base64');

const checks = [], errors = [];
const check = (label, pass, extra) => {
	checks.push({ label, pass: !!pass, extra: extra ?? null });
	console.log(`${pass ? 'ok' : 'FAIL'} ${label}${extra !== undefined ? ' :: ' + JSON.stringify(extra) : ''}`);
};
const wp = (code) => {
	const r = spawnSync('wp', ['--path=' + wpPath, 'eval', code], { encoding: 'utf8' });
	if (r.status) throw new Error(r.stderr || 'wp eval failed');
	return r.stdout.trim();
};

// Isolation: purge any upload records left by earlier runs. Logged-in
// customers keep a stable upload owner id, so unbound per-field quotas would
// otherwise accumulate across runs. The fixture cleanup removes these anyway.
wp('global $wpdb; $rows=$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \'opf_upload_%\'"); foreach($rows as $o){ delete_option($o); } foreach((glob(OPF_UPLOAD_PRIVATE_DIR."/*.bin") ?: []) as $f){ @unlink($f); } echo "purged";');

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
const page = await context.newPage();
page.on('pageerror', (e) => errors.push(String(e.message || e)));

const cartURL = base + '/wp-json/wc/store/v1/cart';
const storeCart = async () => {
	const r = await page.request.get(cartURL);
	return { nonce: r.headers()['nonce'], data: await r.json() };
};
const emptyCart = async () => {
	const { nonce, data } = await storeCart();
	for (const it of data.items || []) await page.request.delete(`${cartURL}/items/${it.key}`, { headers: { Nonce: nonce } });
};
const ourItems = async () => {
	const { data } = await storeCart();
	return (data.items || []).filter((i) => i.id === state.edit_pid || (i.name || '').includes('CE Simple'));
};
const itemMeta = (item) => (item.item_data || []).map((d) => `${d.name}=${d.value}`).join('|');

// --- auth ------------------------------------------------------------------
await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
await page.fill('#user_login', state.customer_user);
await page.fill('#user_pass', state.customer_pass);
await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null), page.click('#wp-submit')]);
check('fixture customer logged in', /wp-admin|my-account|logged|dashboard/i.test(page.url()) || (await context.cookies()).some((c) => c.name.startsWith('wordpress_logged_in')), page.url());

const fillProductForm = async ({ note, matte = true, gift = true, seat = 'front', attendee = ['Ada', 'Grace'], ticket = 'Ada', withFile = true }) => {
	await page.goto(base + '/product/opf-ce-simple/', { waitUntil: 'domcontentloaded' });
	await page.locator(`[data-opf-field="note"]`).waitFor();
	await page.locator(`input[name="opf[${gid}][note]"]`).fill(note);
	await page.locator(`select[name="opf[${gid}][finish]"]`).selectOption(matte ? 'matte' : 'gloss');
	if (gift) await page.locator(`input[name="opf[${gid}][extras][]"][value="gift"]`).check();
	await page.locator(`input[name="opf[${gid}][seat]"][value="${seat}"]`).check();
	await page.locator(`[data-opf-field="attendee"] .opf-field-repeat__add`).click();
	await page.locator(`input[name="opf[${gid}][attendee][0]"]`).fill(attendee[0]);
	await page.locator(`input[name="opf[${gid}][attendee][1]"]`).fill(attendee[1]);
	await page.locator(`input[name="opf[${gid}][ticket][0]"]`).fill(ticket);
	// Repeating section: row 0 server-rendered, row 1 cloned by the add button.
	await page.locator(`input[name="opf[${gid}][gs_name][0]"]`).fill('G1');
	await page.locator(`input[name="opf[${gid}][gs_meal][0]"][value="veg"]`).check();
	await page.locator(`[data-opf-field="gsec"] .opf-field-repeat__add`).click();
	await page.locator(`input[name="opf[${gid}][gs_name][1]"]`).fill('G2');
	await page.locator(`input[name="opf[${gid}][gs_meal][1]"][value="meat"]`).check();
	if (withFile) {
		// Native transport mints the token server-side on POST; there is no
		// client-side token to wait for until the form round-trips.
		await page.locator(`#opf-${gid}-art`).setInputFiles({ name: 'proof-upload.png', mimeType: 'image/png', buffer: png });
	}
};

const submitForm = async () => {
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
		page.locator('button.single_add_to_cart_button').click(),
	]);
	await page.waitForTimeout(400);
	const errs = await page.locator('.woocommerce-error li, .wc-block-components-notice-banner.is-error').allInnerTexts().catch(() => []);
	if (errs.length) console.log('DEBUG submit notices:', JSON.stringify(errs), 'url=', page.url());
};

/* =================== CLASSIC CART =================== */
await emptyCart();
await fillProductForm({ note: 'ORIG' });
await page.screenshot({ path: `${dir}/ce-product-filled.png`, fullPage: true });
await submitForm();
{
	const items = await ourItems();
	check('classic add produced one cart line', items.length === 1, items.map((i) => i.key));
	check('cart line stores original values + upload', /Note=ORIG/.test(itemMeta(items[0])) && /proof-upload\.png/.test(itemMeta(items[0])), itemMeta(items[0]));
}

// Order placed BEFORE the edit must stay untouched.
const { nonce: orderNonce } = await storeCart();
const checkout = await page.request.post(base + '/wp-json/wc/store/v1/checkout', {
	headers: { Nonce: orderNonce },
	data: {
		billing_address: { first_name: 'CE', last_name: 'Customer', company: '', address_1: '1 Fixture St', address_2: '', city: 'Fixtureville', state: 'CA', postcode: '94103', country: 'US', email: 'ce-customer@example.invalid', phone: '5550001' },
		payment_method: 'bacs', payment_data: [],
	},
});
const orderJson = await checkout.json();
const orderId = orderJson.order_id || 0;
check('pre-edit Store API checkout created an order', checkout.status() === 200 && orderId > 0, { status: checkout.status(), order_id: orderId, code: orderJson.code });
if (orderId > 0) {
	state.order_id = orderId;
	fs.writeFileSync('/tmp/opf-cartedit-e2e/browser-order.json', JSON.stringify({ order_id: orderId }));
}

// Store API checkout empties the cart; re-add an identical line to exercise edit.
await fillProductForm({ note: 'ORIG' });
await submitForm();

await page.goto(base + '/opf-ce-cart/', { waitUntil: 'domcontentloaded' });
const link = page.locator('a.opf-edit-cartitem').first();
await link.waitFor();
const href = await link.getAttribute('href');
check('classic cart exposes an edit link carrying the cart key', /opf_edit=[a-f0-9]{32}/.test(href || ''), href);
await page.screenshot({ path: `${dir}/ce-classic-cart-link.png`, fullPage: true });

await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null), link.click()]);
check('edit link lands on the product page with opf_edit', /opf_edit=/.test(page.url()) && /opf-ce-simple/.test(page.url()), page.url());
const hidden = page.locator('input[name="_opf_edit"]');
check('edit state hidden input carries the key', (await hidden.count()) === 1 && /^[a-f0-9]{32}$/.test(await hidden.inputValue()), await hidden.inputValue().catch(() => null));
check('button text becomes Update cart', /update cart/i.test(await page.locator('button.single_add_to_cart_button').innerText()));

// Prefill assertions.
check('text prefilled', (await page.locator(`input[name="opf[${gid}][note]"]`).inputValue()) === 'ORIG');
check('select prefilled', (await page.locator(`select[name="opf[${gid}][finish]"]`).inputValue()) === 'matte');
check('checkbox prefilled', await page.locator(`input[name="opf[${gid}][extras][]"][value="gift"]`).isChecked());
check('radio prefilled', await page.locator(`input[name="opf[${gid}][seat]"][value="front"]`).isChecked());
check('quantity prefilled from cart line', (await page.locator('input.qty').inputValue()) === '1');
check('button-repeat rows restored', (await page.locator(`[data-opf-field="attendee"][data-opf-repeat] [data-opf-repeat-instance]`).count()) === 2 && (await page.locator(`input[name="opf[${gid}][attendee][1]"]`).inputValue()) === 'Grace');
check('upload token retained in prefilled form', (await page.locator(`[data-opf-field="art"] [data-opf-upload-token]`).count()) === 1 && /proof-upload\.png/.test(await page.locator('[data-opf-field="art"] .opf-upload__file--existing').innerText()));
check('section-repeat rows cloned + prefilled', (await page.locator('[data-opf-field="gsec"][data-opf-section-repeat] [data-opf-repeat-instance]').count()) === 2 && (await page.locator(`input[name="opf[${gid}][gs_name][1]"]`).inputValue()) === 'G2' && await page.locator(`input[name="opf[${gid}][gs_meal][1]"][value="meat"]`).isChecked(), await page.locator('[data-opf-field="gsec"] [data-opf-repeat-instance]').count());
await page.screenshot({ path: `${dir}/ce-prefilled-form.png`, fullPage: true });

// Modify and update.
await page.locator(`input[name="opf[${gid}][note]"]`).fill('EDITED');
await page.locator(`select[name="opf[${gid}][finish]"]`).selectOption('gloss');
await page.locator(`input[name="opf[${gid}][extras][]"][value="gift"]`).uncheck();
await page.locator(`input[name="opf[${gid}][extras][]"][value="rush"]`).check();
await page.locator(`input[name="opf[${gid}][seat]"][value="back"]`).check();
await page.locator(`input[name="opf[${gid}][attendee][0]"]`).fill('A2');
await page.locator(`input[name="opf[${gid}][attendee][1]"]`).fill('B2');
await page.locator(`input[name="opf[${gid}][ticket][0]"]`).fill('A2');
await submitForm();
check('edit submit redirected to the cart', /\/cart\//.test(page.url()) || /opf-ce-cart/.test(page.url()), page.url());
{
	const items = await ourItems();
	check('cart still holds exactly one line after edit', items.length === 1, items.map((i) => i.key));
	const meta = itemMeta(items[0]);
	check('edited values replace the original ones', /Note=EDITED/.test(meta) && /Gloss/.test(meta) && /Rush/.test(meta) && /Back/.test(meta), meta);
	check('upload file retained after edit', /proof-upload\.png/.test(meta), meta);
	await page.goto(base + '/opf-ce-cart/', { waitUntil: 'domcontentloaded' });
	check('classic cart shows the single updated line', (await page.locator('.woocommerce-cart-form__cart-item').count()) === 1, await page.locator('.woocommerce-cart-form__cart-item').count());
	await page.screenshot({ path: `${dir}/ce-classic-cart-updated.png`, fullPage: true });
}

// Order untouched by the edit.
if (orderId > 0) {
	const orderProof = JSON.parse(wp(`$i=array_values(wc_get_order(${orderId})->get_items())[0]; $f=json_decode($i->get_meta("_opf_fields",true),true); echo wp_json_encode(["note"=>$f["${gid}"]["note"]??null,"finish"=>$f["${gid}"]["finish"]??null,"total"=>wc_get_order(${orderId})->get_total()]);`));
	check('order placed before the edit keeps its original values', orderProof.note === 'ORIG' && orderProof.finish === 'matte', orderProof);
}

/* =================== BLOCK CART =================== */
await emptyCart();
await fillProductForm({ note: 'BLOCKORIG', withFile: false });
await submitForm();
await page.goto(base + '/cart/', { waitUntil: 'networkidle' });
const blockLink = page.locator('.wc-block-cart a.opf-edit-cartitem, a.opf-edit-cartitem').first();
await blockLink.waitFor({ timeout: 15000 });
const blockHref = await blockLink.getAttribute('href');
check('block cart exposes an edit link via Store API extension', /opf_edit=[a-f0-9]{32}/.test(blockHref || ''), blockHref);
await page.screenshot({ path: `${dir}/ce-block-cart-link.png`, fullPage: true });
await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null), blockLink.click()]);
check('block edit link prefills the form', (await page.locator(`input[name="opf[${gid}][note]"]`).inputValue()) === 'BLOCKORIG');
await page.locator(`input[name="opf[${gid}][note]"]`).fill('BLOCKEDITED');
await submitForm();
{
	const items = await ourItems();
	check('block edit updated the single line', items.length === 1 && /Note=BLOCKEDITED/.test(itemMeta(items[0])), items.map((i) => itemMeta(i)));
}

fs.writeFileSync(`${dir}/browser-results.json`, JSON.stringify({ base, checks, pageErrors: errors }, null, 2));
const failed = checks.filter((c) => !c.pass);
console.log(`${checks.length} browser checks, ${failed.length} failed, ${errors.length} page errors`);
if (errors.length) console.log('pageErrors:', errors);
await browser.close();
process.exit(failed.length ? 1 : 0);
