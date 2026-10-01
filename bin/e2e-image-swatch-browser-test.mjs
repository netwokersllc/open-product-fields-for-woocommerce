import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const css = fs.readFileSync(path.join(here, '../assets/css/opf-frontend.css'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

await page.setContent('<!doctype html><html><body><div class="opf-image-swatch-wrapper" data-grid-layout="flexible" style="--opf-image-swatch-cols:4;--opf-image-swatch-cols-tablet:2;--opf-image-swatch-cols-mobile:1;width:400px"><div class="opf-swatch opf-swatch--image-zoom"><label><img class="opf-swatch-image" src="https://example.test/oak-medium.jpg" width="260" height="200" alt="Oak"><img class="opf-swatch-zoom-preview" src="https://example.test/oak-full.jpg" width="260" height="200" alt="" aria-hidden="true"><span>Oak</span><input type="radio" aria-label="Oak"></label></div></div></body></html>');
await page.addStyleTag({ content: css });
const columnsAt = async (width) => {
	await page.setViewportSize({ width, height: 900 });
	return page.locator('.opf-image-swatch-wrapper').evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').length);
};
const desktop = await columnsAt(1200);
const tablet = await columnsAt(850);
const mobile = await columnsAt(700);
await page.locator('.opf-swatch--image-zoom').hover();
const hoverZoom = await page.locator('.opf-swatch-zoom-preview').isVisible();
const hoverState = await page.locator('.opf-swatch--image-zoom').evaluate((node) => ({ hovered: node.matches(':hover'), display: getComputedStyle(node.querySelector('.opf-swatch-zoom-preview')).display }));
await page.locator('.opf-swatch--image-zoom').evaluate((node) => node.querySelector('input').focus());
const focusZoom = await page.locator('.opf-swatch-zoom-preview').isVisible();
const focusState = await page.locator('.opf-swatch--image-zoom').evaluate((node) => ({ focused: node.matches(':focus-within'), display: getComputedStyle(node.querySelector('.opf-swatch-zoom-preview')).display }));
await page.emulateMedia({ reducedMotion: 'reduce' });
const reducedTransition = await page.locator('.opf-swatch-image').evaluate((node) => getComputedStyle(node).transitionDuration);
const ok = desktop === 4 && tablet === 2 && mobile === 1
	&& hoverZoom && focusZoom
	&& reducedTransition === '0s' && errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} image swatch responsive columns, hover/focus zoom, and reduced motion`);
if (!ok) console.log(JSON.stringify({ desktop, tablet, mobile, hoverZoom, hoverState, focusZoom, focusState, reducedTransition, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
