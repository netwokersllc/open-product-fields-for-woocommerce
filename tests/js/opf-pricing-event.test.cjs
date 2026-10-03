const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.join(__dirname, '..', '..', 'assets', 'js', 'opf-frontend.js');
const events = [];
const context = {
	window: { opf_config: {} },
	opf_config: {},
	document: {
		readyState: 'loading',
		addEventListener() {},
		dispatchEvent(event) { events.push(event); return true; },
		querySelector() { return null; },
		querySelectorAll() { return []; },
	},
	CustomEvent: class {
		constructor(type, init) {
			this.type = type;
			this.detail = init && init.detail;
		}
	},
	console,
};
vm.createContext(context);
vm.runInContext(`${fs.readFileSync(sourcePath, 'utf8')}\nglobalThis.__writeTotals = writeTotals;`, context);

const makeField = (id) => ({
	getAttribute(name) { return 'data-opf-field' === name ? id : null; },
	hasAttribute() { return false; },
	matches() { return false; },
	closest() { return null; },
	querySelector(selector) {
		return selector === 'input:checked' ? { value: 'a', checked: true } : null;
	},
	querySelectorAll() { return []; },
});

const run = (taxFactor) => {
	events.length = 0;
	const group = {
		getAttribute() { return 'g1'; },
		querySelectorAll(selector) { return '[data-opf-field]' === selector ? [makeField('plan')] : []; },
		querySelector(selector) {
			return /data-opf-field="plan"\] input:checked/.test(selector) ? { value: 'a', checked: true } : null;
		},
	};
	const totals = Object.fromEntries(['product', 'options', 'grand'].map((name) => [name, { innerHTML: '' }]));
	const totalsEl = {
		getAttribute(name) {
			if ('data-product-price' === name) return '10';
			if ('data-opf-tax-factor' === name) return taxFactor;
			return null;
		},
		querySelector(selector) {
			if (selector.includes('opf-product-total')) return totals.product;
			if (selector.includes('opf-options-total')) return totals.options;
			if (selector.includes('opf-grand-total')) return totals.grand;
			return null;
		},
	};
	context.document.querySelector = (selector) => {
		if (selector.includes('product-totals')) return totalsEl;
		if (selector.includes('form.cart')) return { value: '4' };
		return null;
	};
	context.document.querySelectorAll = (selector) => ('[data-opf-group]' === selector ? [group] : []);
	context.window.OPF_FIELDS = { g1: { plan: { type: 'select', choices: [{ slug: 'a', pricing: { type: 'formula', formula: '[price] * 0.2', per_unit: true } }] } } };
	context.__writeTotals();
	return { totals, event: events[events.length - 1] || null, eventCount: events.length };
};

test('writeTotals dispatches a native opf:pricing CustomEvent with raw + displayed totals', () => {
	const { totals, event, eventCount } = run('1');
	// base 10 x qty 4 = 40; per-unit addon 2 x 4 = 8; grand 48.
	assert.equal(totals.product.innerHTML, '$40.00');
	assert.equal(totals.options.innerHTML, '$8.00');
	assert.equal(totals.grand.innerHTML, '$48.00');
	assert.equal(eventCount, 1);
	assert.equal(event.type, 'opf:pricing');
	assert.equal(event.detail.base, 40);
	assert.equal(event.detail.options, 8);
	assert.equal(event.detail.final, 48);
	assert.equal(event.detail.quantity, 4);
	assert.equal(event.detail.displayed.base, 40);
	assert.equal(event.detail.displayed.options, 8);
	assert.equal(event.detail.displayed.final, 48);
});

test('writeTotals applies the shop tax display factor to the preview and the event', () => {
	const { totals, event } = run('1.1');
	// Tax-inclusive shop display: 48 * 1.1 = 52.80.
	assert.equal(totals.product.innerHTML, '$44.00');
	assert.equal(totals.options.innerHTML, '$8.80');
	assert.equal(totals.grand.innerHTML, '$52.80');
	// The consumer reads displayed.final; raw final stays untaxed.
	assert.equal(event.detail.final, 48);
	assert.equal(event.detail.displayed.base, 44);
	assert.equal(event.detail.displayed.options, 8.8);
	assert.ok(Math.abs(event.detail.displayed.final - 52.8) < 1e-9);
});
