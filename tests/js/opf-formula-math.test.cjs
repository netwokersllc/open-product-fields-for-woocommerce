const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.join(__dirname, '..', '..', 'assets', 'js', 'opf-frontend.js');
const context = {
	window: {},
	document: { readyState: 'loading', addEventListener() {}, querySelector() { return null; } },
	console,
};
vm.createContext(context);
vm.runInContext(`${fs.readFileSync(sourcePath, 'utf8')}\nglobalThis.__evalFormula = evalFormula;`, context);

test('WAPF numeric formula functions match the server parser and accept semicolon arguments', () => {
	const evaluate = (formula) => context.__evalFormula(formula, 10, 1, 0, '');
	assert.equal(evaluate('abs(-2) + ceil(1.2) + floor(1.8)'), 5);
	assert.equal(evaluate('max(1; 4; 2) + min(1; 4; 2)'), 5);
	assert.equal(evaluate('pow(3; 2) + sqrt(16)'), 13);
	assert.equal(evaluate('round(1.25; 1) + round(-1.5)'), -0.7);
	assert.ok(Math.abs(evaluate('sin(0) + cos(0) + tan(0)') - 1) < 1e-12);
});

test('invalid WAPF numeric function calls fail closed', () => {
	assert.equal(context.__evalFormula('sqrt(-1)', 10, 1, 0, ''), 0);
	assert.equal(context.__evalFormula('pow(2)', 10, 1, 0, ''), 0);
	assert.equal(context.__evalFormula('round()', 10, 1, 0, ''), 0);
});

test('WAPF checked(field ID) previews selected multi-select choices', () => {
	assert.equal(context.__evalFormula('checked(material) * 5', 10, 1, 0, '', { material: ['red', 'blue'] }), 10);
	assert.equal(context.__evalFormula('checked(material)', 10, 1, 0, '', { material: [] }), 0);
	assert.equal(context.__evalFormula('checked(material)', 10, 1, 0, '', { material: 'red' }), 0);
});

test('WAPF len accepts text, scalar field values, Unicode, and ignore-spaces flag', () => {
	const evaluate = (formula, values = {}) => context.__evalFormula(formula, 10, 1, 0, '', values);
	assert.equal(evaluate('len(a quick, brown fox)'), 18);
	assert.equal(evaluate('len(a quick, brown fox; true)'), 15);
	assert.equal(evaluate("len('café 😀')"), 6);
	assert.equal(evaluate('len([field.material])', { material: 'blue' }), 4);
	assert.equal(evaluate('len([field.material])', { material: ['blue', 'red'] }), 0);
});

test('WAPF if/and/or and comparisons work with nested numeric and field conditions', () => {
	const evaluate = (formula, values = {}) => context.__evalFormula(formula, 10, 1, 0, '', values);
	assert.equal(evaluate('if(75 > 50; 10; 20)'), 10);
	assert.equal(evaluate('if(2 <= 1; 10; 20)'), 20);
	assert.equal(evaluate('if(and(75 >= 50; 2 < 3); if(or(1 = 2; 2 != 3); 8; 9); 20)'), 8);
	assert.equal(evaluate('if(and(1 = 1; 2 > 3); 10; 20)'), 20);
	assert.equal(evaluate('if(or(1 = 2; 2 = 3); 10; 20)'), 20);
	assert.equal(evaluate('if([field.size] >= 5; 10; 20)', { size: '7' }), 10);
	assert.equal(evaluate('if([field.color] = Red; 10; 20)', { color: 'Red' }), 10);
	assert.equal(evaluate('if([field.color] != Red; 10; 20)', { color: 'Red' }), 20);
	assert.equal(evaluate('if([field.color] = [field.finish]; 5; 9)', { color: 'Blue', finish: 'Blue' }), 5);
});
