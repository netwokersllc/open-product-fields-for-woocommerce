const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const context = { window: {}, document: { readyState: 'loading', addEventListener() {} } };
vm.createContext(context);
vm.runInContext(`${fs.readFileSync(path.join(__dirname, '../../assets/js/opf-frontend.js'), 'utf8')}\nglobalThis.evaluate = evalFormula;`, context);

test('WAPF semicolon arguments remain supported and commas remain literal text', () => {
	assert.equal(context.evaluate('pow(2;3)', 10, 1, 0, ''), 8);
	assert.equal(context.evaluate('pow(2,3)', 10, 1, 0, ''), 0);
	assert.equal(context.evaluate('len(a,b)', 10, 1, 0, ''), 3);
});

test('scientific numeric results remain usable through nested arithmetic', () => {
	for (const formula of ['min(0.00001;1)*100000', 'pow(2;-24)*16777216', 'max(pow(2;-24);0)*16777216', 'pow(10;22)/10000000000000000000000']) {
		assert.ok(Math.abs(context.evaluate(formula, 10, 1, 0, '') - 1) < 1e-9, formula);
	}
});

test('decimal and exponent literals require a complete valid expression', () => {
	assert.equal(context.evaluate('.5 + 5e-1', 10, 1, 0, ''), 1);
	assert.equal(context.evaluate('1E+3 / 1000', 10, 1, 0, ''), 1);
	for (const formula of ['2 junk', '2 3', '2e', '2e+', '(2+3', '2+3)']) {
		assert.equal(context.evaluate(formula, 10, 1, 0, ''), 0, formula);
	}
});
