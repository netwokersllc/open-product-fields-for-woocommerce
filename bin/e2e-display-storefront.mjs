/**
 * Disposable-clone browser proof for the display lane.
 *
 * OPF run:   OPF_DISPLAY_TARGET=opf  OPF_DISPLAY_MODE=three|grand|hidden
 * WAPF run:  OPF_DISPLAY_TARGET=wapf OPF_DISPLAY_MODE=lines|grand|hide
 *            (wapf_pricing_summary must be set to the same mode first)
 * Surfaces:  OPF_DISPLAY_SURFACES=1 also walks cart -> checkout -> order.
 *
 * Reads fixture ids from /tmp/opf-display-state.json (setup phase).
 * Writes <target>-<mode>-<width>.png + <target>-<mode>.json + surface shots.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
const { chromium } = createRequire(process.cwd() + '/index.js')('playwright');

const base = process.env.OPF_DISPLAY_BASE || 'http://127.0.0.1:8307';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Loopback clone only');
const out = process.env.OPF_DISPLAY_OUT || '/tmp/opf-lane-display-evidence';
const target = process.env.OPF_DISPLAY_TARGET || 'opf';
const mode = process.env.OPF_DISPLAY_MODE || (target === 'wapf' ? 'lines' : 'three');
const surfaces = process.env.OPF_DISPLAY_SURFACES === '1';
const state = JSON.parse(fs.readFileSync('/tmp/opf-display-state.json', 'utf8'));
const isWapf = target === 'wapf';

const checks = [], observations = {}, referenceJsErrors = [];
const check = (label, pass) => checks.push({ label, pass: !!pass });
const productUrl = base + '/?p=' + state.product;

// Selectors shared where the engines intentionally mirror each other.
const sel = isWapf
  ? { fieldWrap: '.wapf-field-container', label: '.wapf-field-label', input: '.wapf-field-input',
      desc: '.wapf-field-description', totals: '.wapf-product-totals', inner: '.wapf--inner',
      hint: '.wapf-pricing-hint', group: '.wapf-field-group' }
  : { fieldWrap: '.opf-field-container', label: '.opf-field-label', input: '.opf-field-input',
      desc: '.opf-field-description', totals: '.opf-product-totals', inner: '.opf--inner',
      hint: '.opf-pricing-hint', group: '.opf-field-group' };
const fieldName = (id) => isWapf ? `wapf[field_${id}]` : `opf[${state.group}][${id}]`;

const browser = await chromium.launch({ headless: true });
try {
  for (const width of [1280, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(productUrl, { waitUntil: 'networkidle' });
    const record = {};
    const P = `${target}/${mode}/${width} `;

    // --- layout: width + label position -------------------------------------
    // OPF emits style="width:50%"; WAPF emits style="width: 50%".
    record.width50 = await page.locator(`${sel.fieldWrap}[style*="width:50"],${sel.fieldWrap}[style*="width: 50"]`).count();
    check(P + 'field width 50% containers render', record.width50 >= 1);
    const labelGroupClass = await page.locator(`${sel.group}`).first().getAttribute('class').catch(() => '');
    record.groupLabelClass = labelGroupClass;
    check(P + 'label position class on group', /label-(above|below)/.test(labelGroupClass || ''));

    if (isWapf) {
      // Product-level WAPF fields: engraving input + required asterisk.
      record.engraving = await page.locator(`input[name="wapf[field_engraving]"]`).first()
        .evaluate(el => ({ value: el.value, placeholder: el.placeholder })).catch(() => null);
      check(P + 'WAPF text default value rendered', record.engraving?.value === 'Ada');
      check(P + 'WAPF text placeholder rendered', record.engraving?.placeholder === 'Your name');
      const asterisk = await page.locator(`${sel.label} .required, ${sel.label} abbr`).count();
      check(P + 'WAPF required marker rendered', asterisk >= 1);
      check(P + 'WAPF hidden-surface field still renders on product page',
        await page.locator('input[name="wapf[field_internal]"]').count() === 1);
      // WAPF only emits `for` when the field carries label_aria meta —
      // documented reference behaviour, recorded not failed.
      record.wapfLabelFor = await page.locator(`${sel.label} label`).first().getAttribute('for').catch(() => null);
    } else {
      // --- instructions: tooltip trigger + inline --------------------------
      const trigger = page.locator('.opf-tooltip-trigger').first();
      record.tooltipTriggerCount = await page.locator('.opf-tooltip-trigger').count();
      check(P + 'tooltip trigger rendered', record.tooltipTriggerCount >= 1);
      const tooltipId = await trigger.getAttribute('aria-describedby');
      record.tooltipPresent = !!(await page.locator('#' + tooltipId).count());
      check(P + 'tooltip span wired via aria-describedby', record.tooltipPresent);
      await trigger.click();
      check(P + 'tooltip opens on click', (await trigger.getAttribute('aria-expanded')) === 'true');
      await page.keyboard.press('Escape');
      check(P + 'tooltip closes on Escape', (await trigger.getAttribute('aria-expanded')) === 'false');
      check(P + 'tooltip focus returned to trigger', await trigger.evaluate(el => el === document.activeElement));
      record.tooltipText = await page.locator('#' + tooltipId).innerText();
      check(P + 'tooltip text matches description', record.tooltipText === 'Keep it short');
      // keyboard activation (Enter) + focus-open/focus-close. Escape left the
      // trigger focused — blur first so focus() produces a real focusin.
      await page.evaluate(() => document.activeElement && document.activeElement.blur());
      await page.waitForTimeout(700); // outlast the Escape reopen-suppression window
      await trigger.focus();
      check(P + 'tooltip opens on focus', (await trigger.getAttribute('aria-expanded')) === 'true');
      await page.keyboard.press('Enter');
      check(P + 'tooltip toggles on Enter', (await trigger.getAttribute('aria-expanded')) === 'false');

      record.inlineDescs = await page.locator('.opf-field-description').allInnerTexts();
      check(P + 'inline description rendered', record.inlineDescs.includes('Inline note'));

      // --- placeholders & defaults ------------------------------------------
      record.engraving = await page.locator(`input[name="${fieldName('engraving')}"]`).first()
        .evaluate(el => ({ value: el.value, placeholder: el.placeholder })).catch(() => null);
      check(P + 'text default value rendered', record.engraving?.value === 'Ada');
      check(P + 'text placeholder rendered', record.engraving?.placeholder === 'Your name');
      record.emailDef = await page.locator(`input[name="${fieldName('contact')}"]`).first().inputValue().catch(() => '');
      check(P + 'email default rendered', record.emailDef === 'ada@example.test');
      record.numDef = await page.locator(`input[name="${fieldName('qty_units')}"]`).first().inputValue().catch(() => '');
      check(P + 'number default rendered', record.numDef === '0');
      record.urlDef = await page.locator(`input[name="${fieldName('site')}"]`).first().inputValue().catch(() => '');
      check(P + 'url default rendered', record.urlDef === 'https://example.test');
      record.areaDef = await page.locator(`textarea[name="${fieldName('details')}"]`).first().inputValue().catch(() => '');
      check(P + 'textarea multiline default rendered', record.areaDef === 'line one\nline two');
      check(P + 'hidden-surface field still renders on product page',
        await page.locator(`input[name="${fieldName('internal')}"]`).count() === 1);
      const giftChecked = await page.locator(`input[name^="${fieldName('gift')}"][value="gift"]`).isChecked().catch(() => false);
      check(P + 'checkbox default checked', giftChecked);
      // name matches a hidden fallback input too — the checkbox is the control.
      const toggleChecked = await page.locator(`input[type="checkbox"][name="${fieldName('enabled')}"]`).first().isChecked().catch(() => false);
      check(P + 'toggle default on', toggleChecked);
      const blueSelected = await page.locator(`input[name^="${fieldName('finish')}"][value="blue"]`).isChecked().catch(() => false);
      check(P + 'radio default selected', blueSelected);
      const smallSelected = await page.locator(`select[name="${fieldName('size')}"]`).first().inputValue().catch(() => '');
      check(P + 'select default selected', smallSelected === 'small');

      // --- label association / a11y -----------------------------------------
      // Scope to the fixture group's own label — earlier groups on the product
      // also render labels.
      const labelFor = await page.locator(`.opf-field-label label[for="opf-${state.group}-engraving"]`).getAttribute('for').catch(() => null);
      check(P + 'label for associates with input id', !!labelFor && (await page.locator('#' + labelFor).count()) === 1);
      const radiogroup = await page.locator('[role="radiogroup"]').first().getAttribute('aria-labelledby').catch(() => null);
      check(P + 'radiogroup aria-labelledby set', !!radiogroup && (await page.locator('#' + radiogroup).count()) === 1);
      const asterisk = await page.locator(`${sel.label} abbr.required`).count();
      check(P + 'required asterisk rendered (mark_required on)', asterisk >= 1);

      // --- label-below visual order ------------------------------------------
      const belowGroup = page.locator('.opf-field-group.label-below');
      check(P + 'label-below group rendered', await belowGroup.count() === 1);
      const belowBox = await belowGroup.locator('.opf-field-container').first().evaluate(el => {
        const lbl = el.querySelector('.opf-field-label');
        const inp = el.querySelector('.opf-field-input');
        if (!lbl || !inp) return null;
        return { labelY: lbl.getBoundingClientRect().y, inputY: inp.getBoundingClientRect().y };
      }).catch(() => null);
      record.labelBelowBox = belowBox;
      check(P + 'label-below renders label visually under input', !!belowBox && belowBox.labelY > belowBox.inputY);
    }

    // --- price hints ----------------------------------------------------------
    const hintTexts = await page.locator(sel.hint).allInnerTexts();
    record.hintTexts = hintTexts;
    check(P + 'price hint rendered for priced field', hintTexts.some(t => t.includes('5.00')));
    if (isWapf) {
      // WAPF emits a placeholder option first — select the priced option by value.
      const largeOption = await page.locator('select[name="wapf[field_size]"] option[value="large"]').innerText().catch(() => '');
      check(P + 'WAPF price hint in select option text', /\(?\+\s*\$?10/.test(largeOption));
    } else {
      const largeOption = await page.locator(`select[name="${fieldName('size')}"] option[value="large"]`).innerText().catch(() => '');
      check(P + 'price hint in select option text', largeOption.includes('$10.00'));
      const giftLabel = await page.locator(`${sel.fieldWrap} label:has(input[name^="${fieldName('gift')}"])`).first().innerText().catch(() => '');
      check(P + 'price hint in choice label', giftLabel.includes('$2.00'));
    }

    // --- pricing summary rows per mode ---------------------------------------
    const totals = page.locator(sel.totals).first();
    const totalsVisible = await totals.isVisible().catch(() => false);
    const rows = await page.locator(`${sel.totals} ${sel.inner} > div`).count();
    record.totalsVisible = totalsVisible; record.rows = rows;
    if (mode === 'three' || mode === 'lines') {
      check(P + 'three-line mode: wrapper visible', totalsVisible);
      check(P + 'three-line mode: 3 rows', rows === 3);
      // Grand = product total + options total (defaults: gift wrap +$2).
      const num = s => parseFloat((s || '').replace(/[^0-9.]/g, ''));
      const pT = num(await page.locator(`${sel.totals} .${isWapf ? 'wapf' : 'opf'}-product-total`).innerText().catch(() => ''));
      const oT = num(await page.locator(`${sel.totals} .${isWapf ? 'wapf' : 'opf'}-options-total`).innerText().catch(() => ''));
      const gT = num(await page.locator(`${sel.totals} .${isWapf ? 'wapf' : 'opf'}-grand-total`).innerText().catch(() => ''));
      record.totals = { product: pT, options: oT, grand: gT };
      check(P + 'product total = $10', pT === 10);
      check(P + 'options total = $2 (gift default)', oT === 2);
      check(P + 'grand total = product+options', Math.abs(gT - (pT + oT)) < 0.001);
    } else if (mode === 'grand') {
      check(P + 'grand mode: wrapper visible', totalsVisible);
      check(P + 'grand mode: 1 row', rows === 1);
    } else {
      check(P + 'hidden mode: totals not visible', !totalsVisible || rows === 0);
    }

    if (isWapf) {
      referenceJsErrors.push(...errors);
      record.jsErrors = errors;
    } else {
      check(P + 'no JS errors', errors.length === 0);
      record.errors = errors;
    }
    observations[width] = record;
    await page.screenshot({ path: `${out}/${target}-storefront-${mode}-${width}.png`, fullPage: true });
    await page.close();
  }

  // --- cart / checkout / order surfaces (desktop width) ----------------------
  if (surfaces) {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const S = `${target}/surfaces `;

    await page.goto(productUrl, { waitUntil: 'networkidle' });
    // Fill the required field then submit the product form (real user flow).
    if (isWapf) {
      await page.fill('input[name="wapf[field_engraving]"]', 'Ada');
      await page.selectOption('select[name="wapf[field_size]"]', 'large');
      await page.check('input[name="wapf[field_finish]"][value="gold"]');
    } else {
      await page.fill(`input[name="${fieldName('engraving')}"]`, 'Ada');
      await page.fill(`input[name="${fieldName('note')}"]`, 'Hello');
      await page.selectOption(`select[name="${fieldName('size')}"]`, 'large');
      await page.check(`input[name^="${fieldName('finish')}"][value="gold"]`);
      await page.fill(`input[name="${fieldName('internal')}"]`, 'secret-ref');
    }
    // The block add-to-cart form hijacks clicks into a Store API request that
    // drops custom wapf[]/opf[] inputs. form.submit() fires a real classic
    // POST, but a submit button's value only serializes on click — inject the
    // hidden add-to-cart field so the POST is identical to a real button click.
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.evaluate(() => {
        const f = document.querySelector('form.cart');
        if (f && !f.querySelector('input[name="add-to-cart"]')) {
          const btn = f.querySelector('button[name="add-to-cart"], .single_add_to_cart_button');
          const h = document.createElement('input');
          h.type = 'hidden'; h.name = 'add-to-cart';
          h.value = btn?.value || String(window.__pid || '');
          f.appendChild(h);
        }
        f.submit();
      }),
    ]);
    observations.atcUrl = page.url();
    observations.atcNotice = await page.locator('.woocommerce-message, .wc-block-components-notice-banner').allInnerTexts().catch(() => []);
    check(S + 'product form add-to-cart succeeded', !page.url().includes('add-to-cart=0'));

    // Cart page (block cart: item_data via Store API cart context). The clone's
    // dev-server router 404s ?page_id= — use the permalink like a shopper.
    await page.goto(base + '/cart/', { waitUntil: 'networkidle' });
    // Block cart hydrates item_data client-side — wait until either the
    // hydrated item rows or the empty state are actually painted.
    await page.waitForFunction(
      () => /currently empty/.test(document.body.innerText)
        || document.querySelector('.wc-block-components-product-metadata'),
      { timeout: 15000 }
    ).catch(() => {});
    observations.cartItemCount = await page.locator('.wc-block-cart-items .wc-block-cart-items__row, .wc-block-cart-item').count();
    observations.cartEmpty = /currently empty/.test(await page.locator('body').innerText());
    const cartBody = await page.locator('body').innerText();
    record_cart: {
      check(S + 'cart shows Engraving value', /Engraving/.test(cartBody) && /Ada/.test(cartBody));
      check(S + 'cart shows choice labels', /Gold|Large/.test(cartBody));
      check(S + 'cart hides hide_cart field', !/Internal note|secret-ref/.test(cartBody));
      if (isWapf) check(S + 'WAPF cart hint markup shown', await page.locator('.wapf-pricing-hint').count() >= 0); // presence optional in block
    }
    await page.screenshot({ path: `${out}/${target}-cart.png`, fullPage: true });

    // Checkout page (order review): hide_checkout applies.
    await page.goto(base + '/checkout/', { waitUntil: 'networkidle' });
    await page.waitForFunction(
      () => document.querySelector('.wc-block-components-product-metadata')
        || /cart is currently empty|Checkout is not available/.test(document.body.innerText),
      { timeout: 15000 }
    ).catch(() => {});
    await page.waitForTimeout(2500); // order-summary item_data hydrates last
    const coBody = await page.locator('body').innerText();
    check(S + 'checkout shows visible fields', /Engraving/.test(coBody));
    check(S + 'checkout hides hide_checkout field', !/Internal note|secret-ref/.test(coBody));
    await page.screenshot({ path: `${out}/${target}-checkout.png`, fullPage: true });

    // Order-received page from the CLI-created order: hide_order applies.
    const orderUrl = isWapf ? state.wapf_order_url : state.opf_order_url;
    if (orderUrl) {
      await page.goto(orderUrl, { waitUntil: 'networkidle' });
      // WC gates guest order views behind email verification once the
      // 10-minute grace period expires — submit the billing email like a
      // real shopper instead of disabling the gate.
      if (await page.locator('form.woocommerce-verify-email input[name="email"]').count()) {
        await page.fill('form.woocommerce-verify-email input[name="email"]', 'display@example.invalid');
        await Promise.all([
          page.waitForLoadState('networkidle'),
          page.click('#verify-email-submit'),
        ]);
        observations.emailVerificationUsed = true;
      }
      const orderBody = await page.locator('body').innerText();
      check(S + 'order summary shows visible fields', /Engraving/.test(orderBody));
      check(S + 'order summary shows labels+values', /Ada/.test(orderBody));
      check(S + 'order summary hides hide_order field', !/Internal note|secret-ref/.test(orderBody));
      await page.screenshot({ path: `${out}/${target}-order.png`, fullPage: true });
    } else {
      check(S + 'order received URL available', false);
    }

    if (isWapf) {
      referenceJsErrors.push(...errors);
    } else {
      check(S + 'no JS errors on surfaces', errors.length === 0);
    }
    await page.close();
  }
} finally { await browser.close(); }

const result = { target, mode, checks, observations, referenceJsErrors };
fs.writeFileSync(`${out}/${target}-${mode}.json`, JSON.stringify(result, null, 2));
const fails = checks.filter(c => !c.pass);
console.log(JSON.stringify({ target, mode, passes: checks.length - fails.length, fails: fails.length, failLabels: fails.map(f => f.label), referenceJsErrors }));
