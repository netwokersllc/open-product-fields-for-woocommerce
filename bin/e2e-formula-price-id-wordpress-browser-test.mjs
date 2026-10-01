import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

// Run only against a private disposable clone, never a shared or production WP.
// Example:
// OPF_WP_PATH=/tmp/opf-priceid-wp-e2e-20261001 \
// OPF_BASE_URL=http://127.0.0.1:8094 \
// PLAYWRIGHT_BROWSERS_PATH=/tmp/fy-playwright-browsers \
// node bin/e2e-formula-price-id-wordpress-browser-test.mjs
const wpPath = process.env.OPF_WP_PATH || '';
const baseUrl = process.env.OPF_BASE_URL || '';
const wpCli = process.env.WP_CLI_BIN || 'wp';
if (!wpPath || !fs.existsSync(path.join(wpPath, 'wp-config.php')) || !fs.realpathSync(wpPath).startsWith('/tmp/opf-priceid-wp-e2e-')) {
	throw new Error('OPF_WP_PATH must point to a private /tmp/opf-priceid-wp-e2e-* WordPress clone.');
}
const parsedBase = new URL(baseUrl);
if (!['127.0.0.1', 'localhost', '[::1]'].includes(parsedBase.hostname)) {
	throw new Error('OPF_BASE_URL must use a loopback host for the isolated clone.');
}

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const runWp = (args) => execFileSync(wpCli, [`--path=${wpPath}`, `--url=${baseUrl}`, ...args], {
	encoding: 'utf8',
	maxBuffer: 8 * 1024 * 1024,
});
const evalPhp = (code) => runWp(['eval', code]).trim();
const parseLastJsonLine = (output) => {
	for (const line of output.split(/\r?\n/).reverse()) {
		try { return JSON.parse(line); } catch { /* ignore non-JSON WP-CLI output */ }
	}
	throw new Error(`WP-CLI did not return JSON: ${output.slice(-1000)}`);
};
const marker = `OPF Price ID browser E2E ${Date.now()} ${crypto.randomBytes(4).toString('hex')}`;
const sentinel = `__OPF_MISSING_${crypto.randomBytes(8).toString('hex')}__`;
let optionSnapshot = null;
let fixture = null;
let browser = null;
let failures = 0;
const cleanupErrors = [];
const check = (name, ok, details = '') => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}${ok || !details ? '' : `: ${details}`}`);
	if (!ok) failures++;
};

try {
	optionSnapshot = parseLastJsonLine(evalPhp(`$keys = ['opf_admin_only', 'opf_show_totals']; $snapshot = []; foreach ($keys as $key) { $value = get_option($key, '${sentinel}'); $snapshot[$key] = ['exists' => $value !== '${sentinel}', 'value' => $value]; } update_option('opf_admin_only', 'no'); update_option('opf_show_totals', 'yes'); echo wp_json_encode($snapshot);`));

	const fixturePhp = `
$product_id = 0;
$group_ids = [];
$saved_group_statuses = [];
try {
    if (!class_exists('WooCommerce') || !class_exists('OPF\\Service\\FieldGroups')) { throw new RuntimeException('WooCommerce or OPF is not active in the disposable clone.'); }
    foreach (get_posts(['post_type' => 'opf_field_group', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids']) as $existing_group_id) {
        $saved_group_statuses[(int) $existing_group_id] = get_post_status((int) $existing_group_id);
        wp_update_post(['ID' => (int) $existing_group_id, 'post_status' => 'draft']);
    }
    \\OPF\\Service\\FieldGroups::flush_cache();
    $product = new WC_Product_Simple();
    $product->set_name('${marker}');
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_virtual(true);
    $product->set_regular_price('100');
    $product->set_price('100');
    $product_id = (int) $product->save();
    if (!$product_id) { throw new RuntimeException('Could not create the isolated product fixture.'); }
    $placement = [['rules' => [['subject' => 'product', 'operator' => 'in', 'terms' => [(string) $product_id]]]]];
    $plain = ['type' => 'none', 'amount' => 0, 'formula' => ''];
    $source_group = [
        'fields' => [
            ['id' => 'visibility', 'label' => 'Source visibility', 'type' => 'select', 'required' => false, 'choices' => [
                ['slug' => 'show', 'label' => 'Show source', 'selected' => true, 'pricing' => $plain],
                ['slug' => 'hide', 'label' => 'Hide source', 'selected' => false, 'pricing' => $plain],
            ], 'pricing' => $plain],
            ['id' => 'source', 'label' => 'Priced source', 'type' => 'radio', 'required' => false, 'choices' => [
                ['slug' => 'selected', 'label' => 'Selected source', 'selected' => true, 'pricing' => ['type' => 'fixed', 'amount' => 7]],
            ], 'pricing' => $plain, 'conditionals' => [['action' => 'hide', 'logic' => 'all', 'rules' => [['field' => 'visibility', 'operator' => 'is', 'value' => 'hide']]]]],
        ],
        'rule_groups' => $placement,
    ];
    $formula_group = [
        'fields' => [
            ['id' => 'derived', 'label' => 'Formula multiplier', 'type' => 'text', 'required' => false, 'choices' => [], 'pricing' => ['type' => 'formula', 'amount' => 0, 'formula' => '[price.source] * 2']],
        ],
        'rule_groups' => $placement,
    ];
    $group_ids[] = \\OPF\\Service\\FieldGroups::save(0, $source_group, ['title' => '${marker} earlier', 'status' => 'publish', 'menu_order' => 10]);
    $group_ids[] = \\OPF\\Service\\FieldGroups::save(0, $formula_group, ['title' => '${marker} later', 'status' => 'publish', 'menu_order' => 20]);
    if (in_array(0, $group_ids, true)) { throw new RuntimeException('Could not create both ordered field groups.'); }
    \\OPF\\Service\\FieldGroups::flush_cache();
    echo wp_json_encode(['product_id' => $product_id, 'group_ids' => $group_ids, 'saved_group_statuses' => $saved_group_statuses, 'url' => get_permalink($product_id)]);
} catch (Throwable $error) {
    foreach (array_reverse($group_ids) as $group_id) { if ($group_id) { wp_delete_post((int) $group_id, true); } }
    if ($product_id) { wp_delete_post($product_id, true); }
    foreach ($saved_group_statuses as $id => $status) { wp_update_post(['ID' => (int) $id, 'post_status' => $status]); }
    \\OPF\\Service\\FieldGroups::flush_cache();
    fwrite(STDERR, $error->getMessage());
    exit(1);
}`;
	fixture = parseLastJsonLine(evalPhp(fixturePhp));
	if (!Array.isArray(fixture.group_ids) || fixture.group_ids.length !== 2 || !fixture.url) {
		throw new Error('WP-CLI returned an incomplete WordPress fixture.');
	}

	browser = await chromium.launch();
	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
	const pageErrors = [];
	const consoleErrors = [];
	const failedRequests = [];
	page.on('pageerror', (error) => pageErrors.push(error.message));
	page.on('console', (message) => {
		if (message.type() === 'error') consoleErrors.push(message.text());
	});
	page.on('requestfailed', (request) => failedRequests.push(`${request.url()}: ${request.failure()?.errorText || 'failed'}`));
	const pageUrl = new URL(fixture.url);
	pageUrl.protocol = parsedBase.protocol;
	pageUrl.host = parsedBase.host;
	await page.goto(pageUrl.href, { waitUntil: 'domcontentloaded' });
	const groups = page.locator('[data-opf-group]');
	await groups.first().waitFor({ state: 'attached', timeout: 15000 });
	const renderedGroupIds = await groups.evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-opf-group')));
	check('actual WordPress product page renders two ordered OPF groups',
		await groups.count() === 2
		&& await groups.nth(0).getAttribute('data-opf-group') === String(fixture.group_ids[0])
		&& await groups.nth(1).getAttribute('data-opf-group') === String(fixture.group_ids[1]),
		JSON.stringify({ expected: fixture.group_ids, rendered: renderedGroupIds }));
	check('earlier priced choice is selected in rendered product HTML',
		await page.locator(`[data-opf-group="${fixture.group_ids[0]}"] [data-opf-field="source"] input[type="radio"]:checked`).count() === 1);

	const formulaInput = page.locator(`[data-opf-group="${fixture.group_ids[1]}"] [data-opf-field="derived"] input[type="text"]`);
	await formulaInput.fill('2');
	const options = page.locator('.opf-product-totals .opf-options-total');
	const grand = page.locator('.opf-product-totals .opf-grand-total');
	await page.waitForTimeout(250);
	check('initial browser totals after formula input', (await options.textContent()).trim() === '$21.00' && (await grand.textContent()).trim() === '$121.00',
		JSON.stringify({ options: await options.textContent(), grand: await grand.textContent(), pageErrors, consoleErrors, failedRequests }));
	await page.waitForFunction(() => document.querySelector('.opf-grand-total')?.textContent.trim() === '$121.00', null, { timeout: 5000 });
	check('rendered cross-group [price.ID] totals are $21 options and $121 grand',
		(await options.textContent()).trim() === '$21.00' && (await grand.textContent()).trim() === '$121.00');

	await page.locator(`[data-opf-group="${fixture.group_ids[0]}"] [data-opf-field="visibility"] select`).selectOption('hide');
	await page.locator(`[data-opf-group="${fixture.group_ids[0]}"] [data-opf-field="source"]`).waitFor({ state: 'hidden', timeout: 5000 });
	await page.waitForFunction(() => document.querySelector('.opf-grand-total')?.textContent.trim() === '$100.00', null, { timeout: 5000 });
	check('conditionally hidden source resolves to zero',
		(await options.textContent()).trim() === '$0.00' && (await grand.textContent()).trim() === '$100.00');

	await page.locator(`[data-opf-group="${fixture.group_ids[0]}"] [data-opf-field="visibility"] select`).selectOption('show');
	await page.locator(`[data-opf-group="${fixture.group_ids[0]}"] [data-opf-field="source"]`).waitFor({ state: 'visible', timeout: 5000 });
	await page.waitForFunction(() => document.querySelector('.opf-grand-total')?.textContent.trim() === '$121.00', null, { timeout: 5000 });
	check('restoring source visibility restores cross-group totals',
		(await options.textContent()).trim() === '$21.00' && (await grand.textContent()).trim() === '$121.00');
	check('WordPress-rendered page has no JavaScript errors', pageErrors.length === 0 && consoleErrors.length === 0,
		JSON.stringify({ pageErrors, consoleErrors }));
} catch (error) {
	failures++;
	console.error(`FAIL WordPress browser run: ${error.stack || error}`);
} finally {
	if (browser) await browser.close();
	if (fixture) {
		try {
			const ids = Buffer.from(JSON.stringify(fixture)).toString('base64');
			const cleanup = evalPhp(`$fixture = json_decode(base64_decode('${ids}'), true); foreach (array_reverse($fixture['group_ids'] ?? []) as $id) { wp_delete_post((int) $id, true); } if (!empty($fixture['product_id'])) { wp_delete_post((int) $fixture['product_id'], true); } foreach (($fixture['saved_group_statuses'] ?? []) as $id => $status) { wp_update_post(['ID' => (int) $id, 'post_status' => $status]); } \\OPF\\Service\\FieldGroups::flush_cache(); echo 'cleaned';`);
			if (cleanup !== 'cleaned') throw new Error(`unexpected cleanup response: ${cleanup}`);
			const expectedStatuses = Buffer.from(JSON.stringify(fixture.saved_group_statuses || {})).toString('base64');
			const remaining = parseLastJsonLine(evalPhp(`$ids = ${JSON.stringify(fixture.group_ids.concat(fixture.product_id))}; $posts = get_posts(['post_type' => ['product', 'opf_field_group'], 'post_status' => 'any', 'post__in' => $ids, 'numberposts' => -1, 'fields' => 'ids']); $saved = json_decode(base64_decode('${expectedStatuses}'), true); $statuses = []; foreach ($saved as $id => $status) { $statuses[$id] = get_post_status((int) $id); } echo wp_json_encode(['remaining' => $posts, 'statuses' => $statuses]);`));
			if (remaining.remaining.length || JSON.stringify(remaining.statuses) !== JSON.stringify(fixture.saved_group_statuses || {})) throw new Error(`fixture cleanup mismatch: ${JSON.stringify(remaining)}`);
			console.log('ok created product and field groups removed from clone');
		} catch (error) {
			cleanupErrors.push(`fixture cleanup: ${error.message}`);
		}
	}
	if (optionSnapshot) {
		try {
			const snapshot = Buffer.from(JSON.stringify(optionSnapshot)).toString('base64');
			evalPhp(`$snapshot = json_decode(base64_decode('${snapshot}'), true); foreach ($snapshot as $key => $entry) { if ($entry['exists']) { update_option($key, $entry['value']); } else { delete_option($key); } }`);
			const after = parseLastJsonLine(evalPhp(`$keys = ['opf_admin_only', 'opf_show_totals']; $current = []; foreach ($keys as $key) { $value = get_option($key, '${sentinel}'); $current[$key] = ['exists' => $value !== '${sentinel}', 'value' => $value]; } echo wp_json_encode($current);`));
			if (JSON.stringify(after) !== JSON.stringify(optionSnapshot)) throw new Error('option values did not restore exactly');
			console.log('ok clone options restored exactly');
		} catch (error) {
			cleanupErrors.push(`option restoration: ${error.message}`);
		}
	}
}

if (cleanupErrors.length) {
	failures++;
	console.error(cleanupErrors.join('\n'));
}
if (failures) process.exit(1);
