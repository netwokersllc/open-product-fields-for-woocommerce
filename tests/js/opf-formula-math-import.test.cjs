const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const formulas = {
	'min(5; 1; 3)': 1, 'max(5; 8; 3)': 8, 'round(2.546; 2)': 2.55,
	'abs(-4)': 4, 'floor(2.9)': 2, 'ceil(2.1)': 3, 'sqrt(9)': 3,
	'pow(2; 3)': 8, 'sin(0)': 0, 'cos(0)': 1, 'tan(0)': 0,
	'ROUND(max(abs(-4); pow(2; 3)) / (2 + 2); 2)': 2,
};
const normalized = JSON.parse(execFileSync('php', ['-r',
	'require $argv[1]; $formulas = json_decode($argv[2], true); echo json_encode(array_map(static fn($formula) => \\OPF\\Engine\\WapfMapper::normalize_formula($formula . " * [qty]"), $formulas));',
	path.join(root, 'tests/bootstrap.php'), JSON.stringify(Object.keys(formulas)),
], { encoding: 'utf8' }));
const context = { window: {}, document: { readyState: 'loading', addEventListener() {} } };
vm.createContext(context);
vm.runInContext(`${fs.readFileSync(path.join(root, 'assets/js/opf-frontend.js'), 'utf8')}\nglobalThis.evaluate = evalFormula;`, context);

test('actual PHP-imported math formulas retain PHP/browser numeric results', () => {
	Object.entries(formulas).forEach(([formula, expected], index) => {
		assert.equal(normalized[index], formula);
		assert.ok(Math.abs(context.evaluate(normalized[index], 10, 3, 0, '') - expected) < 0.000001, formula);
	});
});

test('negative square roots fail the whole browser formula closed', () => {
	assert.equal(context.evaluate('(sqrt(-1) + 7)', 10, 1, 0, ''), 0);
});
