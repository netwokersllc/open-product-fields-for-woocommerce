const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const window = { OPF_FIELDS: {} };
const document = { readyState: 'loading', addEventListener() {} };
vm.runInNewContext(fs.readFileSync('assets/js/opf-frontend.js', 'utf8') + '\nwindow.toggleProof = { isVisible, choiceOrFieldAddon };', { window, document, console, setTimeout, clearTimeout });
for (const type of ['fixed', 'percent', 'formula']) {
  const field = { type: 'toggle', pricing: { type, amount: 2, formula: '2' } };
  assert.equal(window.toggleProof.choiceOrFieldAddon(field, '0', 10, 1, 0, '0'), 0);
  assert.ok(window.toggleProof.choiceOrFieldAddon(field, '1', 10, 1, 0, '1') > 0);
}
const dependent = { conditionals: [{ action: 'show', logic: 'all', rules: [{ field: 'wrap', operator: 'is', value: '0' }] }] };
assert.equal(window.toggleProof.isVisible(dependent, { wrap: '0' }), true);
assert.equal(window.toggleProof.isVisible(dependent, { wrap: '1' }), false);
console.log('Toggle browser pricing and false-condition checks passed (8)');
