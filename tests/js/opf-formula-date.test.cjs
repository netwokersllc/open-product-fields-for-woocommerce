const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.join(__dirname, '..', '..', 'assets', 'js', 'opf-frontend.js');
const context = {
	window: { OPF_DATE_FORMAT: 'mm-dd-yyyy', OPF_TODAY: '2026-06-15', opf_config: { date_format: 'mm-dd-yyyy' } },
	opf_config: { date_format: 'mm-dd-yyyy' },
	OPF_TODAY: '2026-06-15',
	document: { readyState: 'loading', addEventListener() {}, querySelector() { return null; } },
	console,
};
vm.createContext(context);
vm.runInContext(`${fs.readFileSync(sourcePath, 'utf8')}\nglobalThis.__evalFormula = evalFormula; globalThis.__choiceOrFieldAddon = choiceOrFieldAddon; globalThis.__writeTotals = writeTotals;`, context);

test('WAPF date formula functions use Sunday-zero weekdays and one-based months', () => {
	assert.equal(context.__evalFormula("dow('01-10-2023')", 10, 1, 0, ''), 2);
	assert.equal(context.__evalFormula("month('03-01-2023')", 10, 1, 0, ''), 3);
	assert.equal(context.__evalFormula("dow('2024-01-01')", 10, 1, 0, ''), 1);
});

test('sumQty reads only tagged image choice quantities and image quantity pricing multiplies per choice', () => {
	const quantities = { _opf_type: 'image_quantity', quantities: { oak: 2, ash: 3 } };
	assert.equal(context.__evalFormula('sumQty(images)', 10, 1, 0, '', { images: quantities }), 5);
	assert.equal(context.__evalFormula('sumQty(unrelated)', 10, 1, 0, '', { unrelated: [2, 3] }), 0);
	assert.equal(context.__choiceOrFieldAddon({
		type: 'image_quantity',
		choices: [
			{ slug: 'oak', pricing: { type: 'fixed', amount: 2 } },
			{ slug: 'ash', pricing: { type: 'fixed', amount: 1 } },
		],
	}, quantities, 10, 1, 0, '', { images: quantities }), 7);
});

test('WAPF date functions accept the selected field value and site today', () => {
	assert.equal(context.__evalFormula('month(today())', 10, 1, 0, ''), 6);
	assert.equal(context.__evalFormula("datediff('01-10-2023'; '01-12-2023')", 10, 1, 0, ''), 2);
	assert.equal(context.__evalFormula("datediff('01-12-2023'; '01-10-2023')", 10, 1, 0, ''), 2);
	assert.equal(context.__evalFormula("datediff(today(); '06-18-2026')", 10, 1, 0, ''), 3);
	assert.equal(context.__evalFormula("datediff('02-30-2023'; '03-01-2023')", 10, 1, 0, ''), 0);
	assert.equal(context.__evalFormula('datediff([field.start_date]; [field.end_date])', 10, 1, 0, '', {
		start_date: '2024-02-28',
		end_date: '2024-03-01',
	}), 2);
	assert.equal(context.__evalFormula("if(datediff('2024-02-28'; [field.end_date]) > 1; datediff('2024-02-28'; [field.end_date]); 3)", 10, 1, 0, '', {
		end_date: '2024-03-01',
	}), 2);
	assert.equal(context.__evalFormula('month([field.end_date]) + dow([field.start_date])', 10, 1, 0, '', {
		end_date: '2024-02-29',
		start_date: '2024-01-01',
	}), 3);
	assert.equal(context.__choiceOrFieldAddon({
		type: 'text',
		pricing: { type: 'formula', formula: 'month([field.end_date]) + dow([field.start_date])' },
	}, 'selected', 10, 1, 0, 'selected', { end_date: '2024-02-29', start_date: '2024-01-01' }), 3);
	assert.equal(context.__evalFormula('dow([field.start_date])', 10, 1, 0, '', { start_date: ['2024-01-01'] }), 1);
	assert.equal(context.__choiceOrFieldAddon({
		type: 'select',
		choices: [{ slug: 'selected', pricing: { type: 'formula', formula: '[price.plan] + 1' } }],
	}, 'selected', 10, 1, 0, 'selected', {}, { plan: 5 }), 6);
	assert.equal(context.__choiceOrFieldAddon({
		type: 'select',
		choices: [{ slug: 'selected', pricing: { type: 'formula', formula: '[price.plan] + 1' } }],
	}, 'selected', 10, 1, 0, 'selected', {}, { plan: [5, 6] }), 12);
});

test('browser totals resolve prior-group prices and treat hidden references as zero', () => {
	const makeField = (id, value, type, hidden = false) => {
		const input = { value, type: 'radio', checked: true };
		return {
			getAttribute(name) { return 'data-opf-field' === name ? id : null; },
			hasAttribute(name) { return 'hidden' === name && hidden; },
			matches() { return false; },
			closest() { return null; },
			querySelector(selector) {
				if ('input:checked' === selector) return null;
				return type === 'text' && selector.startsWith('input:not(') ? input : null;
			},
			querySelectorAll() { return []; },
		};
	};
	const plan = makeField('plan', 'premium', 'select');
	const fee = makeField('fee', 'selected', 'text');
	const group = (id, fields, choices) => ({
		getAttribute() { return id; },
		querySelectorAll(selector) { return '[data-opf-field]' === selector ? fields : []; },
		querySelector(selector) {
			const match = selector.match(/data-opf-field="([^"]+)"/);
			return match && choices[match[1]] ? choices[match[1]] : null;
		},
	});
	const groups = [group('a', [plan], { plan: { value: 'premium', type: 'radio', checked: true } }), group('b', [fee], {})];
	const totals = Object.fromEntries(['product', 'options', 'grand'].map((name) => [name, { innerHTML: '' }]));
	const totalsEl = {
		getAttribute(name) { return 'data-product-price' === name ? '10' : null; },
		querySelector(selector) {
			if (selector.includes('opf-product-total')) return totals.product;
			if (selector.includes('opf-options-total')) return totals.options;
			if (selector.includes('opf-grand-total')) return totals.grand;
			return null;
		},
	};
	context.window.OPF_FIELDS = {
		a: { plan: { type: 'select', choices: [{ slug: 'premium', pricing: { type: 'fixed', amount: 5 } }] } },
		b: { fee: { type: 'text', pricing: { type: 'formula', formula: '[price.plan] + 1' } } },
	};
	context.document.querySelector = (selector) => selector.includes('product-totals') ? totalsEl : null;
	context.document.querySelectorAll = () => groups;
	context.__writeTotals();
	assert.equal(totals.options.innerHTML, '$11.00');
	assert.equal(totals.grand.innerHTML, '$21.00');

	plan.hasAttribute = (name) => 'hidden' === name;
	context.__writeTotals();
	assert.equal(totals.options.innerHTML, '$1.00');
	assert.equal(totals.grand.innerHTML, '$11.00');
});

test('WAPF math, text, and conditional formula functions evaluate in browser previews', () => {
	assert.equal(context.__evalFormula('min(5; 1; 3) + max(5; 8; 3)', 10, 1, 0, ''), 9);
	assert.equal(context.__evalFormula('len(a quick brown fox; true)', 10, 1, 0, ''), 14);
	assert.equal(context.__evalFormula('abs(-4) + floor(2.9) + ceil(2.1) + sqrt(9) + pow(2; 3)', 10, 1, 0, ''), 20);
	assert.equal(context.__evalFormula('round(2.546; 2)', 10, 1, 0, ''), 2.55);
	assert.equal(context.__evalFormula('if(or(2 < 1; 3 >= 3); 20; 10)', 10, 1, 0, ''), 20);
	assert.equal(context.__evalFormula('if(and(2 < 1; 3 >= 3); 20; 10)', 10, 1, 0, ''), 10);
	assert.equal(context.__evalFormula('if([field.size]=Large;10;20)', 10, 1, 0, '', { size: 'Large' }), 10);
	assert.equal(context.__evalFormula('min([field.count]+2;7)', 10, 1, 0, '', { count: '2' }), 4);
	assert.equal(context.__evalFormula('checked(tags)', 10, 1, 0, '', { tags: ['red', 'blue'] }), 2);
	assert.equal(context.__evalFormula('checked(missing)', 10, 1, 0, '', { tags: ['red'] }), 0);
});

test('WAPF date functions honor configured formats and reject invalid calendar dates', () => {
	context.window.OPF_DATE_FORMAT = 'dd/mm/yy';
	context.window.opf_config.date_format = 'dd/mm/yy';
	context.opf_config.date_format = 'dd/mm/yy';
	assert.equal(context.__evalFormula("month('31/12/23')", 10, 1, 0, ''), 12);
	assert.equal(context.__evalFormula("datediff('31/12/23'; '02/01/24')", 10, 1, 0, ''), 2);
	assert.equal(context.__evalFormula("dow('02-30-2023')", 10, 1, 0, ''), 0);
});
