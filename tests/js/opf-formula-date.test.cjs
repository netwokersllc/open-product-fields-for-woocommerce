const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.join(__dirname, '..', '..', 'assets', 'js', 'opf-frontend.js');
const context = {
	window: { OPF_DATE_FORMAT: 'mm-dd-yyyy', OPF_TODAY: '2026-06-15' },
	document: { readyState: 'loading', addEventListener() {} },
	console,
};
vm.createContext(context);
vm.runInContext(`${fs.readFileSync(sourcePath, 'utf8')}\nglobalThis.__evalFormula = evalFormula;`, context);

test('WAPF date formula functions use Sunday-zero weekdays and one-based months', () => {
	assert.equal(context.__evalFormula("dow('01-10-2023')", 10, 1, 0, ''), 2);
	assert.equal(context.__evalFormula("month('03-01-2023')", 10, 1, 0, ''), 3);
	assert.equal(context.__evalFormula("dow('2024-01-01')", 10, 1, 0, ''), 1);
});

test('WAPF date functions accept the selected field value and site today', () => {
	assert.equal(context.__evalFormula('dow([val])', 10, 1, 0, '01-10-2023'), 2);
	assert.equal(context.__evalFormula('month(today())', 10, 1, 0, ''), 6);
});

test('WAPF date functions honor configured formats and reject invalid calendar dates', () => {
	context.window.OPF_DATE_FORMAT = 'dd/mm/yy';
	assert.equal(context.__evalFormula("month('31/12/23')", 10, 1, 0, ''), 12);
	assert.equal(context.__evalFormula("dow('02-30-2023')", 10, 1, 0, ''), 0);
});
