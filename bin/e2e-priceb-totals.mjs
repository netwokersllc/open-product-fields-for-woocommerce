/**
 * PriceB totals-display browser comparison vs WAPF Extended 3.1.5.
 *
 * Reads the guarded fixture written by bin/e2e-priceb-totals.php
 * (priceb-totals-state.json) and compares the rendered pre-cart totals of the
 * native WAPF product and the mapper-imported OPF product for:
 *   - positive options total at q=1/q=3 (WAPF-PRICE-TOTAL-DISPLAY)
 *   - negative fx options total: numeric value + raw sign placement
 *     (WAPF-PRICE-OPTIONS-TOTAL-NEGATIVE-FORMAT)
 *   - charq (per-unit) options total scaled by quantity at q=3
 *
 * Run with a playwright-capable cwd:
 *   cd /tmp/opf-url-native-parity && node /tmp/opf-lane-priceb/bin/e2e-priceb-totals.mjs
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
const { chromium } = createRequire(process.cwd() + '/index.js')('playwright');

const out = process.env.OPF_PRICEB_OUT || '/tmp/opf-lane-priceb-evidence';
const state = JSON.parse(fs.readFileSync(out + '/priceb-totals-state.json', 'utf8'));
const base = state.base;
if (!/^http:\/\/127\.0\.0\.1:8317$/.test(base)) throw new Error('Loopback clone (8317) only: ' + base);

const gid = state.opf_group;
const field = {
  opf: { select: `opf[${gid}][pack]`, text: `opf[${gid}][message]`, prefix: 'opf' },
  wapf: { select: 'wapf[field_pack]', text: 'wapf[field_msg]', prefix: 'wapf' },
};

const num = (s) => {
  if (s === null || s === undefined) return NaN;
  const cleaned = String(s).replace(/[^0-9.,\-]/g, '').replace(/,/g, '');
  return cleaned === '' || cleaned === '-' ? NaN : parseFloat(cleaned);
};

const scenarios = [
  { id: 'default',     choice: undefined,      qty: 1, msg: null,   expectOptions: 0 },
  { id: 'plus_q1',     choice: 'plus',         qty: 1, msg: null,   expectOptions: 5 },
  { id: 'minus_q1',    choice: 'minus',        qty: 1, msg: null,   expectOptions: -15 },
  { id: 'plus_q3',     choice: 'plus',         qty: 3, msg: null,   expectOptions: 5 },
  { id: 'minus_q3',    choice: 'minus',        qty: 3, msg: null,   expectOptions: -15 },
  { id: 'charq_q3',    choice: undefined,      qty: 3, msg: 'abcd', expectOptions: 6 },
  { id: 'plus_charq_q3', choice: 'plus',       qty: 3, msg: 'abcd', expectOptions: 11 },
];

const targets = process.env.OPF_PRICEB_TARGET
  ? [ process.env.OPF_PRICEB_TARGET ]
  : [ 'wapf', 'opf' ];

const browser = await chromium.launch({ headless: true });
const results = {};
const referenceJsErrors = [];
const pageErrors = {};

const readTotals = async (page, prefix) => {
  const read = async (kind) => {
    const loc = page.locator(`.${prefix}-product-totals .${prefix}-${kind}`).first();
    if (await loc.count() === 0) return { text: null, html: null };
    return {
      text: (await loc.innerText().catch(() => '')).trim(),
      html: (await loc.innerHTML().catch(() => '')).trim(),
    };
  };
  return { product: await read('product-total'), options: await read('options-total'), grand: await read('grand-total') };
};

try {
  for (const target of targets) {
    const prefix = field[target].prefix;
    const pid = target === 'wapf' ? state.wapf_product : state.opf_product;
    const url = `${base}/?p=${pid}`;
    results[target] = {};
    pageErrors[target] = [];

    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    page.on('pageerror', (e) => pageErrors[target].push(e.message));

    for (const scenario of scenarios) {
      await page.goto(url, { waitUntil: 'networkidle' });
      // Wait for the JS totals writer to publish non-empty spans.
      await page.waitForFunction((sel) => {
        const el = document.querySelector(sel);
        return !!el && el.innerHTML.trim() !== '';
      }, `.${prefix}-product-totals .${prefix}-grand-total`, { timeout: 15000 }).catch(() => {});

      const select = page.locator(`select[name="${field[target].select}"]`);
      if (await select.count()) {
        await select.selectOption(scenario.choice === undefined ? { index: 0 } : { value: scenario.choice }).catch(() => {});
      }
      if (scenario.msg !== null) {
        const text = page.locator(`input[name="${field[target].text}"]`);
        await text.fill(scenario.msg);
      }
      const qty = page.locator('form.cart input.qty, form.cart input[name="quantity"]').first();
      if (await qty.count()) {
        await qty.fill(String(scenario.qty));
        await qty.dispatchEvent('input');
        await qty.dispatchEvent('change');
      }
      await page.waitForTimeout(250);

      const totals = await readTotals(page, prefix);
      if ([ 'minus_q1', 'plus_charq_q3' ].includes(scenario.id)) {
        await page.screenshot({
          path: `${out}/priceb-totals-${target}-${scenario.id}.png`,
          clip: { x: 0, y: 0, width: 900, height: 700 },
        }).catch(() => {});
      }
      results[target][scenario.id] = {
        choice: scenario.choice === undefined ? '(default)' : scenario.choice,
        qty: scenario.qty,
        msg: scenario.msg,
        product: num(totals.product.text),
        options: num(totals.options.text),
        grand: num(totals.grand.text),
        raw: {
          product: totals.product.html ?? totals.product.text,
          options: totals.options.html ?? totals.options.text,
          grand: totals.grand.html ?? totals.grand.text,
        },
      };
    }
    await page.close();
  }
} finally {
  await browser.close();
}

// --- comparison -----------------------------------------------------------
const checks = [];
const comparisons = {};
if (targets.length === 2) {
  for (const scenario of scenarios) {
    const w = results.wapf[scenario.id];
    const o = results.opf[scenario.id];
    const match =
      Math.abs(w.product - o.product) < 0.005 &&
      Math.abs(w.options - o.options) < 0.005 &&
      Math.abs(w.grand - o.grand) < 0.005 &&
      Math.abs(o.options - scenario.expectOptions) < 0.005;
    comparisons[scenario.id] = { match, wapf: { product: w.product, options: w.options, grand: w.grand }, opf: { product: o.product, options: o.options, grand: o.grand }, expectOptions: scenario.expectOptions };
    checks.push({ label: `numeric totals match WAPF @ ${scenario.id}`, pass: match });
  }
  // Negative sign placement: OPF follows wc_price (minus before symbol);
  // WAPF keeps the minus inside its number formatter. Record both raw strings.
  const wNeg = results.wapf.minus_q1.raw.options;
  const oNeg = results.opf.minus_q1.raw.options;
  comparisons.negative = {
    wapf_raw: wNeg,
    opf_raw: oNeg,
    wapf_minus_before_symbol: /^-\s*[^0-9]*15/.test(wNeg) || /^-[^0-9]*\$/.test(wNeg),
    opf_minus_before_symbol: /^-\s*[^0-9]*15/.test(oNeg) || /^-[^0-9]*\$/.test(oNeg),
    note: 'OPF matches WooCommerce wc_price() sign placement; WAPF formatter places the sign inside the currency format.',
  };
}
const mismatches = checks.filter((c) => !c.pass).map((c) => c.label);

const evidence = {
  generated: new Date().toISOString(),
  wapf_version: state.wapf_version,
  scenarios,
  results,
  comparisons,
  checks,
  mismatches,
  pageErrors,
  referenceJsErrors,
};
fs.writeFileSync(out + '/priceb-totals-display.json', JSON.stringify(evidence, null, 2));

console.log(JSON.stringify({ checks, mismatches, comparisons, pageErrors, referenceJsErrors }, null, 2));
if (mismatches.length) {
  console.error('FAIL: ' + mismatches.join(' | '));
  process.exit(1);
}
console.log('PriceB totals-display parity passed.');
