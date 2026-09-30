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
vm.runInContext(`${fs.readFileSync(sourcePath, 'utf8')}\nglobalThis.__evalFormula = evalFormula; globalThis.__choiceOrFieldAddon = choiceOrFieldAddon;`, context);

test('WAPF date formula functions use Sunday-zero weekdays and one-based months', () => {
	assert.equal(context.__evalFormula("dow('01-10-2023')", 10, 1, 0, ''), 2);
	assert.equal(context.__evalFormula("month('03-01-2023')", 10, 1, 0, ''), 3);
	assert.equal(context.__evalFormula("dow('2024-01-01')", 10, 1, 0, ''), 1);
});

test('WAPF date functions accept the selected field value and site today', () => {
	assert.equal(context.__evalFormula('month(today())', 10, 1, 0, ''), 6);
	assert.equal(context.__evalFormula('month([field.end_date]) + dow([field.start_date])', 10, 1, 0, '', {
		end_date: '2024-02-29',
		start_date: '2024-01-01',
	}), 3);
	assert.equal(context.__choiceOrFieldAddon({
		type: 'text',
		pricing: { type: 'formula', formula: 'month([field.end_date]) + dow([field.start_date])' },
	}, 'selected', 10, 1, 0, 'selected', { end_date: '2024-02-29', start_date: '2024-01-01' }), 3);
	assert.equal(context.__evalFormula('dow([field.start_date])', 10, 1, 0, '', { start_date: ['2024-01-01'] }), 0);
});

test('WAPF date functions honor configured formats and reject invalid calendar dates', () => {
	context.window.OPF_DATE_FORMAT = 'dd/mm/yy';
	context.window.opf_config.date_format = 'dd/mm/yy';
	context.opf_config.date_format = 'dd/mm/yy';
	assert.equal(context.__evalFormula("month('31/12/23')", 10, 1, 0, ''), 12);
	assert.equal(context.__evalFormula("dow('02-30-2023')", 10, 1, 0, ''), 0);
});
