'use strict';
/**
 * Full-page screenshot via Puppeteer (open source, free).
 * Usage: node capture.js --url=... --out=... [--width=1440] [--wait=1500]
 *        [--format=png|jpg] [--hide=sel1||sel2] [--timeout=60000] [--chrome=/path]
 */
const puppeteer = require('puppeteer-core');

const a = {};
process.argv.slice(2).forEach((x) => {
	const m = x.match(/^--([^=]+)=([\s\S]*)$/);
	if (m) { a[m[1]] = m[2]; }
});

const width   = parseInt(a.width || '1440', 10);
const wait    = parseInt(a.wait || '1500', 10);
const timeout = Math.max(15000, parseInt(a.timeout || '60000', 10));
const hide    = (a.hide || '').split('||').map((s) => s.trim()).filter(Boolean);
const sleep   = (ms) => new Promise((r) => setTimeout(r, ms));

if (!a.url || !a.out) {
	console.error('Missing --url or --out');
	process.exit(2);
}

(async () => {
	const opts = {
		headless: true,
		args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--hide-scrollbars'],
	};
	if (!a.chrome) {
		console.error('No Chrome/Chromium/Edge path. Set "Chrome path" in plugin settings.');
		process.exit(3);
	}
	opts.executablePath = a.chrome;

	const browser = await puppeteer.launch(opts);
	try {
		const page = await browser.newPage();
		await page.setViewport({ width: width, height: 900, deviceScaleFactor: 1 });
		await page.goto(a.url, { waitUntil: 'load', timeout: timeout });
		await page.waitForNetworkIdle({ idleTime: 800, timeout: 10000 }).catch(() => {});

		// Force lazy images to load, then scroll through page.
		await page.evaluate(() => {
			document.querySelectorAll('img[loading="lazy"]').forEach((i) => { i.loading = 'eager'; });
		});
		await page.evaluate(async () => {
			await new Promise((resolve) => {
				let y = 0;
				const step = 500;
				const t = setInterval(() => {
					window.scrollBy(0, step);
					y += step;
					if (y >= document.documentElement.scrollHeight || y > 40000) {
						clearInterval(t);
						window.scrollTo(0, 0);
						resolve();
					}
				}, 120);
			});
		});

		// Hide cookie banners and other noise.
		for (const sel of hide) {
			try { await page.addStyleTag({ content: sel + '{display:none!important}' }); } catch (e) { /* ignore bad selector */ }
		}

		await page.waitForNetworkIdle({ idleTime: 500, timeout: 5000 }).catch(() => {});
		await sleep(wait);

		const shot = { path: a.out, fullPage: true };
		if (a.format === 'jpg') { shot.type = 'jpeg'; shot.quality = 85; } else { shot.type = 'png'; }
		await page.screenshot(shot);
	} finally {
		await browser.close();
	}
})().catch((e) => {
	console.error(String((e && e.message) || e));
	process.exit(1);
});
