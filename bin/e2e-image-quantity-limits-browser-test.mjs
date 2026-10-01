import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '../assets/js/opf-frontend.js'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

await page.setContent(`<!doctype html><html><body>
	<div data-opf-fields><div data-opf-group="17">
		<div data-opf-field="prints">
			<div class="opf-image-quantity">
				<input class="opf-image-quantity__input" type="number" data-choice-slug="disabled" min="0" max="12" value="4" disabled>
				<input class="opf-image-quantity__input" type="number" data-choice-slug="oak" min="0" max="12" value="8">
				<input class="opf-image-quantity__input" type="number" data-choice-slug="ash" min="0" max="12" value="0">
			</div>
		</div>
	</div></div>
</body></html>`);
await page.evaluate(() => {
	window.OPF_FIELDS = { 17: { prints: { type: 'image_quantity', min_choices: 3, max_choices: 8, conditionals: [] } } };
});
await page.addScriptTag({ content: source });

const oak = page.locator('[data-choice-slug="oak"]');
const ash = page.locator('[data-choice-slug="ash"]');
const initial = await oak.evaluate((input) => input.validationMessage);
await oak.fill('1');
await ash.fill('1');
const below = await oak.evaluate((input) => input.validationMessage);
await oak.fill('5');
await ash.fill('4');
const above = await oak.evaluate((input) => input.validationMessage);
await ash.fill('3');
const boundary = await oak.evaluate((input) => ({ message: input.validationMessage, max: input.max, value: input.value }));

const ok = initial === ''
	&& below === 'Choose at least 3 items in total.'
	&& above === 'Choose no more than 8 items in total.'
	&& boundary.message === '' && boundary.max === '12' && boundary.value === '5'
	&& errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} image quantity browser validates aggregate limits and preserves choice max`);
if (!ok) console.log(JSON.stringify({ initial, below, above, boundary, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
