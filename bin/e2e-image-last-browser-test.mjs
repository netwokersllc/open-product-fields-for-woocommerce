// Runs OPF's frontend image helper in Chromium against a WooCommerce gallery DOM fixture.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '../assets/js/opf-frontend.js'), 'utf8');
const start = source.indexOf('const imageAttributes =');
const end = source.indexOf('\n\nconst initialImageField', start);
if ( start < 0 || end < 0 ) throw new Error('Could not locate product-image runtime helpers.');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 960, height: 720 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('console', (message) => { if ( 'error' === message.type() ) errors.push(message.text()); });
await page.route('https://**/*', (route) => {
	const path = new URL(route.request().url()).pathname;
	const color = path.includes('back') ? '#2b6cb0' : path.includes('custom') ? '#d69e2e' : '#2f855a';
	return route.fulfill({ status: 200, contentType: 'image/svg+xml', body: `<svg xmlns="http://www.w3.org/2000/svg" width="480" height="320"><rect width="100%" height="100%" fill="${color}"/></svg>` });
});
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if ( ! ok ) failures++;
};

await page.setContent(`<!doctype html><html><body>
	<div class="woocommerce-product-gallery">
		<div class="woocommerce-product-gallery__image flex-active-slide"><a href="https://images.test/front-large.jpg"><img src="https://images.test/front.jpg" srcset="https://images.test/front-2x.jpg 2x" sizes="100vw" alt="Front" data-large_image="https://images.test/front-large.jpg"></a></div>
		<div class="woocommerce-product-gallery__image"><a href="https://images.test/back-large.jpg"><img src="https://images.test/back.jpg" srcset="https://images.test/back-2x.jpg 2x" sizes="100vw" alt="Back" data-large_image="https://images.test/back-large.jpg"></a></div>
		<div class="flex-control-nav"><a href="#front">Front</a><a href="#back">Back</a></div>
	</div>
</body></html>`);
await page.addScriptTag({ content: `${source.slice(start, end)}\nwindow.updateProductImage = updateProductImage;` });
await page.evaluate(() => {
	document.querySelectorAll('.flex-control-nav a').forEach((control, index) => control.addEventListener('click', (event) => {
		event.preventDefault();
		document.querySelectorAll('.woocommerce-product-gallery__image').forEach((slide, slideIndex) => slide.classList.toggle('flex-active-slide', slideIndex === index));
	}));
});

const rules = [
	{ target_url: 'https://images.test/back.jpg', conditions: [ { field: 'finish', value: 'blue' } ] },
	{ target_url: 'https://cdn.test/custom.jpg', conditions: [ { field: 'size', value: 'large' } ] },
];
const apply = (changedField, values) => page.evaluate(({ changedField, values, rules }) => window.updateProductImage(document, [ { changedField, values, rules, mode: 'last' } ]), { changedField, values, rules });
const activeIndex = () => page.locator('.woocommerce-product-gallery__image').evaluateAll((slides) => slides.findIndex((slide) => slide.classList.contains('flex-active-slide')));
const activeSrc = () => page.locator('.woocommerce-product-gallery__image.flex-active-slide img').getAttribute('src');

await apply('finish', { finish: 'blue', size: 'small' });
check('latest field selects its matching WooCommerce gallery slide', await activeIndex() === 1 && await activeSrc() === 'https://images.test/back.jpg');

await apply('size', { finish: 'blue', size: 'large' });
check('latest field can replace active image with external URL', await activeSrc() === 'https://cdn.test/custom.jpg');
await page.screenshot({ path: '/tmp/opf-last-image-external-state.png' });

await apply('finish', { finish: 'red', size: 'large' });
check('unmatched latest field restores original slide and image attributes', await activeIndex() === 0 && await activeSrc() === 'https://images.test/front.jpg' && await page.locator('.woocommerce-product-gallery__image img').nth(1).getAttribute('srcset') === 'https://images.test/back-2x.jpg 2x');

check('no uncaught browser errors during image transitions', errors.length === 0);
if ( errors.length ) console.log(errors.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
