const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.join(__dirname, '..', '..', 'assets', 'js', 'opf-frontend.js');
const context = {
	window: {
		opf_config: {
			display_options: { symbol: '$', decimals: 2, thousand: ',', decimal: '.' },
		},
	},
	opf_config: {},
	document: { readyState: 'loading', addEventListener() {}, querySelector() { return null; }, querySelectorAll() { return []; } },
	console,
};
vm.createContext(context);
vm.runInContext(
	`${fs.readFileSync(sourcePath, 'utf8')}
globalThis.__calcDependencies = calcDependencies;
globalThis.__resolveCalcValues = resolveCalcValues;
globalThis.__formatCalcDisplay = formatCalcDisplay;`,
	context
);

test('calcDependencies finds field, price and checked references', () => {
	const deps = Array.from(context.__calcDependencies('[field.rate] * [price.addon] + checked(extras)'));
	assert.deepEqual(deps.sort(), ['addon', 'extras', 'rate']);
});

test('informational calc formats result and applies the result_text template', () => {
	const defs = {
		qty: { id: 'qty', type: 'number' },
		total: { id: 'total', type: 'calc', calc_type: 'default', formula: '[field.qty] * 2', result_format: 'number', result_text: 'Total: {result}' },
	};
	const resolved = context.__resolveCalcValues(defs, { qty: '3' }, { base: 10, qty: 1, addons: 0 });
	assert.equal(resolved.results.total.raw, '6');
	assert.equal(resolved.results.total.display, 'Total: 6.00');
	assert.equal(resolved.results.total.invalid, false);
});

test('result_format none keeps the verbatim numeric string', () => {
	assert.equal(context.__formatCalcDisplay({ calc_type: 'default', result_format: 'none', result_text: '{result}' }, 12.5), '12.5');
});

test('cost calc displays as formatted currency', () => {
	assert.equal(context.__formatCalcDisplay({ calc_type: 'cost', result_text: '{result}' }, 12.5), '$12.50');
	assert.equal(context.__formatCalcDisplay({ calc_type: 'cost', result_text: 'Fee {result}' }, -3), 'Fee -$3.00');
});

test('calc dependencies resolve regardless of declaration order', () => {
	// b references a even though b is (id-)ordered before a in the defs object.
	const defs = {
		b: { id: 'b', type: 'calc', calc_type: 'default', formula: '[field.a] + 1', result_format: 'none', result_text: '{result}' },
		a: { id: 'a', type: 'calc', calc_type: 'default', formula: '[field.x] * 3', result_format: 'none', result_text: '{result}' },
		x: { id: 'x', type: 'number' },
	};
	const resolved = context.__resolveCalcValues(defs, { x: '4' }, { base: 0, qty: 1, addons: 0 });
	assert.equal(resolved.results.a.raw, '12');
	assert.equal(resolved.results.b.raw, '13');
	assert.equal(resolved.invalid.size, 0);
});

test('a calc dependency cycle fails closed', () => {
	const defs = {
		a: { id: 'a', type: 'calc', calc_type: 'cost', formula: '[field.b] + 5', result_format: 'none', result_text: '{result}' },
		b: { id: 'b', type: 'calc', calc_type: 'default', formula: '[field.a] + 1', result_format: 'none', result_text: '{result}' },
	};
	const resolved = context.__resolveCalcValues(defs, {}, { base: 0, qty: 1, addons: 0 });
	assert.equal(resolved.results.a.invalid, true);
	assert.equal(resolved.results.b.invalid, true);
	assert.equal(resolved.results.a.raw, '');
	assert.equal(resolved.results.b.display, '');
});

test('a calc that depends on a cyclic calc is invalid too', () => {
	const defs = {
		a: { id: 'a', type: 'calc', formula: '[field.a] + 1', result_format: 'none', result_text: '{result}' },
		downstream: { id: 'downstream', type: 'calc', formula: '[field.a] * 2', result_format: 'none', result_text: '{result}' },
	};
	const resolved = context.__resolveCalcValues(defs, {}, { base: 0, qty: 1, addons: 0 });
	assert.equal(resolved.results.a.invalid, true);
	assert.equal(resolved.results.downstream.invalid, true);
	assert.equal(resolved.results.downstream.raw, '');
});

test('cost calc result feeds later formulas through calc context', () => {
	const defs = {
		rate: { id: 'rate', type: 'number' },
		sub: { id: 'sub', type: 'calc', calc_type: 'cost', formula: '[field.rate] * [field.rate]', result_format: 'none', result_text: '{result}' },
		tax: { id: 'tax', type: 'calc', calc_type: 'cost', formula: '[field.sub] * 0.2', result_format: 'none', result_text: '{result}' },
	};
	const resolved = context.__resolveCalcValues(defs, { rate: '5' }, { base: 0, qty: 1, addons: 0 });
	assert.equal(resolved.results.sub.raw, '25');
	assert.equal(resolved.results.tax.raw, '5');
	assert.equal(resolved.results.tax.display, '$5.00');
});
