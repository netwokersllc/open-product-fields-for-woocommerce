const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/js/opf-frontend.js'), 'utf8');
const start = source.indexOf('const imageAttributes =');
const end = source.indexOf('\n\nconst initialImageField', start);
assert.notEqual(start, -1, 'product image helpers must remain in the frontend asset');
assert.notEqual(end, -1, 'product image helpers must end before total rendering');
const sandbox = {};
vm.runInNewContext(`${source.slice(start, end)}\nglobalThis.resolveProductImageRule = resolveProductImageRule; globalThis.updateProductImage = updateProductImage;`, sandbox);

test('last-changed mode matches only the most recently changed field and value', () => {
	const rules = [
		{ target_url: '/red.jpg', conditions: [{ field: 'finish', value: 'red' }] },
		{ target_url: '/blue.jpg', conditions: [{ field: 'finish', value: 'blue' }] },
	];
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'red' }, 'last', 'finish'), rules[0]);
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'blue' }, 'last', 'finish'), rules[1]);
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'red' }, 'last', 'size'), null);
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'green' }, 'last', 'finish'), null);
});

test('an unmatched last-changed field restores the original product image', () => {
	const attrs = new Map([['src', 'https://shop.test/original.jpg'], ['srcset', 'original-2x.jpg 2x'], ['alt', 'Original']]);
	const linkAttrs = new Map([['href', 'https://shop.test/original-large.jpg']]);
	const link = { getAttribute: (name) => linkAttrs.get(name) ?? null, setAttribute: (name, value) => linkAttrs.set(name, String(value)), removeAttribute: (name) => linkAttrs.delete(name) };
	const image = { getAttribute: (name) => attrs.get(name) ?? null, setAttribute: (name, value) => attrs.set(name, String(value)), removeAttribute: (name) => attrs.delete(name), closest: () => link };
	const doc = { querySelector: () => image };
	const evaluation = { values: { finish: 'red', size: 'large' }, rules: [{ target_url: 'https://cdn.test/red.jpg', conditions: [{ field: 'finish', value: 'red' }] }], mode: 'last', changedField: 'finish' };
	sandbox.updateProductImage(doc, [evaluation]);
	assert.equal(attrs.get('src'), 'https://cdn.test/red.jpg');
	evaluation.changedField = 'size';
	sandbox.updateProductImage(doc, [evaluation]);
	assert.equal(attrs.get('src'), 'https://shop.test/original.jpg');
	assert.equal(attrs.get('srcset'), 'original-2x.jpg 2x');
	assert.equal(linkAttrs.get('href'), 'https://shop.test/original-large.jpg');
});
