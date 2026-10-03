const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const context = { window: {}, document: { readyState: 'loading', addEventListener() {} } };
vm.createContext(context);
vm.runInContext(`${fs.readFileSync(path.join(root, 'assets/js/opf-frontend.js'), 'utf8')}\nglobalThis.evaluate = evalFormula;`, context);

test('len matches WAPF browser semantics', () => {
	// WAPF's second argument is case-sensitive: only the literal 'true' strips.
	assert.equal(context.evaluate('len(a quick brown fox; true)', 10, 1, 0, ''), 14);
	assert.equal(context.evaluate('len(a quick brown fox; TRUE)', 10, 1, 0, ''), 17);
	assert.equal(context.evaluate('len(a quick brown fox)', 10, 1, 0, ''), 17);
	// JavaScript .length counts UTF-16 code units: an emoji counts as two.
	assert.equal(context.evaluate('len(A\u{1F600}B)', 10, 1, 0, ''), 4);
	assert.equal(context.evaluate('len(Ae\u{0301}B)', 10, 1, 0, ''), 4);
	// JS \s strips NBSP and em-space but not NEL.
	assert.equal(context.evaluate('len(A\u{00A0}B; true)', 10, 1, 0, ''), 2);
	assert.equal(context.evaluate('len(A\u{2003}B; true)', 10, 1, 0, ''), 2);
	assert.equal(context.evaluate('len(A\u{0085}B; true)', 10, 1, 0, ''), 3);
	// Browser side counts the character "0" (the PHP empty() quirk is server-only).
	assert.equal(context.evaluate('len(0)', 10, 1, 0, ''), 1);
});

test('[field.X] resolves submitted slugs to choice labels', () => {
	const fieldValues = { size: 'xl', __opf_labels: { size: { xl: 'Extra Large', m: 'Medium' } } };
	assert.equal(context.evaluate('len([field.size])', 10, 1, 0, '', fieldValues), 11);
	assert.equal(context.evaluate('if([field.size]=Extra Large;10;20)', 10, 1, 0, '', fieldValues), 10);
	// The X_slug suffix picks one submitted value when a field has several.
	const multi = { size: ['xl', 'm'], __opf_labels: { size: { xl: 'Extra Large', m: 'Medium' } } };
	assert.equal(context.evaluate('len([field.size_m])', 10, 1, 0, '', multi), 6);
	// WAPF substitutes '0' for a missing slug; JS counts it (PHP's empty() does not).
	assert.equal(context.evaluate('len([field.size_missing])', 10, 1, 0, '', multi), 1);
	// Without a labels map the raw submitted value is used.
	assert.equal(context.evaluate('len([field.size])', 10, 1, 0, '', { size: 'xl' }), 2);
});
