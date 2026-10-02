const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '../..');
const context = { window: {}, document: { readyState: 'loading', addEventListener() {} } };
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(root, 'assets/js/opf-frontend.js'), 'utf8') + '\nglobalThis.evaluate = evalFormula;', context);
const tagged = (quantities) => ({ _opf_type: 'image_quantity', quantities });
const evaluatePHP = (cases) => JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/tests/bootstrap.php';
$cases = json_decode(stream_get_contents(STDIN), true);
echo json_encode(array_map(static function ($case) {
 return OPF\\Engine\\Calculator::evaluate_formula($case['formula'], 10, 2, 0, '', null, $case['values']);
}, $cases));
`, root], { input: JSON.stringify(cases), encoding: 'utf8' }));

test('sumQty canonical image quantities, field references and nested arithmetic match PHP', () => {
	const cases = [
		{ formula: 'sumQty(prints)', values: { prints: tagged({ oak: 2, ash: 3, disabled: 0 }) }, expected: 5 },
		{ formula: " SUMQTY( 'PRINTS' ) ", values: { prints: tagged({ oak: '02', ash: '3' }) }, expected: 5 },
		{ formula: 'sumQty(prints) * [qty] + max(1;sumQty(prints))', values: { prints: tagged({ oak: 2, ash: 3 }) }, expected: 15 },
		{ formula: 'sumQty(missing) + 7', values: { prints: tagged({ oak: 2 }) }, expected: 7 },
		{ formula: 'sumQty()', values: {}, expected: 0 },
		...['text', 'checked', 'wrong_tag', 'missing_quantities'].map((id) => ({
			formula: `sumQty(${id})`, values: { text: '23', checked: [2, 3], wrong_tag: { quantities: { oak: 2 } }, missing_quantities: { _opf_type: 'image_quantity' } }, expected: 0,
		})),
	];
	assert.deepEqual(evaluatePHP(cases), cases.map((item) => item.expected));
	for (const item of cases) assert.equal(context.evaluate(item.formula, 10, 2, 0, '', item.values), item.expected, item.formula);
});

test('sumQty excludes malformed quantities without coercing arrays, objects or booleans', () => {
	const cases = [null, true, false, [2], { value: 2 }, -1, 2.5, '2.5', '-1', '2foo', '', ' 2 '].map((invalid) => ({
		formula: 'sumQty(prints)', values: { prints: tagged({ valid: 3, invalid }) }, expected: 3,
	}));
	for (const item of cases) assert.equal(context.evaluate(item.formula, 10, 2, 0, '', item.values), item.expected, JSON.stringify(item.values));
	assert.deepEqual(evaluatePHP(cases), cases.map((item) => item.expected));
});
