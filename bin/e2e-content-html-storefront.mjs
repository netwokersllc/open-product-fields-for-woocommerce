import { createRequire } from 'node:module';
import fs from 'node:fs';
const { chromium } = createRequire(process.cwd() + '/index.js')('playwright');
const base = process.env.OPF_CONTENT_HTML_BASE_URL || 'http://127.0.0.1:8216';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Disposable loopback only');
const outDir = process.env.OPF_CONTENT_HTML_OUT || '/tmp/opfch-artifacts';
const statePath = process.env.OPF_CONTENT_HTML_STATE || '/tmp/opfch-content-html-state-20261002.json';
const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
if (!state.page) throw new Error('Fixture state missing host page id; run setup phase first');
fs.mkdirSync(outDir, { recursive: true });

const checks = [], errors = [];
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); return !!pass; };
const norm = (s) => (s || '').replace(/\s+/g, ' ').replace(/>\s+</g, '><').trim();

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 960 } });
page.on('pageerror', e => errors.push(e.message));
page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

try {
	await page.goto(base + '/?page_id=' + state.page, { waitUntil: 'networkidle' });

	const wapfSel = '.wapf [data-field-id="opfch_html"]';
	const opfSel = '[data-opf-field="opfch_html"] > .opf-field-content';
	const noscSel = '[data-opf-field="opfch_nosc"] > .opf-field-content';
	await page.waitForSelector(wapfSel, { timeout: 15000 });
	await page.waitForSelector(opfSel, { timeout: 15000 });
	await page.waitForSelector(noscSel, { timeout: 15000 });

	const wapf = await page.locator(wapfSel).first().innerHTML();
	const opf = await page.locator(opfSel).first().innerHTML();
	const nosc = await page.locator(noscSel).first().innerHTML();
	fs.writeFileSync(outDir + '/wapf-fragment.html', wapf);
	fs.writeFileSync(outDir + '/opf-fragment.html', opf);
	fs.writeFileSync(outDir + '/opf-nosc-fragment.html', nosc);

	const fragments = { wapf, opf };
	for (const [name, frag] of Object.entries(fragments)) {
		check(`${name}: allowlisted markup preserved (strong/em/a/ul/h3/table/img/span/div/hr/br)`,
			frag.includes('<strong class="opfch-keep">OPFCH_BOLD</strong>')
			&& frag.includes('<em>OPFCH_EM</em>')
			&& frag.includes('class="opfch-ln"') && frag.includes('target="_blank"') && frag.includes('id="opfch-a"')
			&& frag.includes('<ul class="opfch-lst">') && frag.includes('id="opfch-li1"')
			&& frag.includes('<h3 class="opfch-h">OPFCH_H3</h3>')
			&& frag.includes('<td>OPFCH_TD</td>') && frag.includes('<th>OPFCH_TH</th>')
			&& frag.includes('class="opfch-im"') && frag.includes('id="opfch-img"')
			&& frag.includes('class="opfch-sp"') && frag.includes('id="opfch-d1"')
			&& frag.includes('<hr class="opfch-hr">') && frag.includes('<br>'));
		check(`${name}: disallowed markup stripped (script/u/iframe/object/onclick/javascript:)`,
			!/<script|<u>|<iframe|<object|onclick|javascript:/i.test(frag));
		check(`${name}: shortcode executed after sanitization (non-allowlisted <mark> survives)`,
			frag.includes('<mark data-opfch="probe">OPFCH_SC_OK_20261002</mark>') && !frag.includes('[opfch_probe_20261002]'));
		check(`${name}: img alt and target attributes preserved`,
			/<img\b[^>]*\balt="OPFCH_ALT"/.test(frag) && /<img\b[^>]*\btarget="_blank"/.test(frag));
		check(`${name}: entity escaping preserved`, frag.includes('&amp;'));
	}
	check('OPF opt-out paragraph keeps literal shortcode text', nosc.includes('[opfch_probe_20261002]') && !nosc.includes('<mark'));
	check('WAPF and OPF rendered fragments are equivalent', norm(wapf) === norm(opf));
	if (norm(wapf) !== norm(opf)) { console.log('--- wapf ---\n' + norm(wapf) + '\n--- opf ---\n' + norm(opf)); }
	check('no uncaught browser errors', errors.length === 0);
} finally {
	fs.writeFileSync(outDir + '/browser-results.json', JSON.stringify({ base, time: new Date().toISOString(), checks, errors }, null, 2));
	await browser.close();
}
const failed = checks.filter(c => !c.pass);
if (failed.length) { console.log(`FAIL ${failed.length} check(s)`); process.exit(1); }
console.log('ok all browser checks passed');
