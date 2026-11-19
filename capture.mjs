// Full-page screenshots of every page. Runs on GitHub Actions. Free.
// URL list + settings come from your WordPress plugin REST endpoint.
// Fallback: wp-sitemap.xml.
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const cfg = JSON.parse(fs.readFileSync(new URL('./config.json', import.meta.url), 'utf8'));
const SITE = (process.env.SITE_URL || cfg.site || '').replace(/\/+$/, '');
if (!SITE) { console.error('Set "site" in config.json'); process.exit(2); }

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const OUT = path.resolve('out', 'shots');
fs.rmSync(path.resolve('out'), { recursive: true, force: true });
fs.mkdirSync(OUT, { recursive: true });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const get = async (u) => {
  const r = await fetch(u, { headers: { 'user-agent': UA, accept: '*/*' }, redirect: 'follow' });
  if (!r.ok) throw new Error(`HTTP ${r.status} for ${u}`);
  return r;
};

async function fromPlugin() {
  const r = await get(`${SITE}/wp-json/site-shots/v1/urls`);
  const j = await r.json();
  if (!Array.isArray(j.urls) || !j.urls.length) throw new Error('Empty URL list');
  return j;
}

async function sitemapUrls(u, depth = 0) {
  const xml = await (await get(u)).text();
  const locs = [...xml.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/g)].map((m) => m[1].replace(/&amp;/g, '&'));
  let out = [];
  for (const l of locs) {
    if (/\.xml(\?|$)/i.test(l) && depth < 2) out = out.concat(await sitemapUrls(l, depth + 1).catch(() => []));
    else out.push(l);
  }
  return out;
}

async function fromSitemap() {
  const urls = [SITE + '/', ...(await sitemapUrls(`${SITE}/wp-sitemap.xml`))];
  return { urls: [...new Set(urls)] };
}

let conf;
try {
  conf = await fromPlugin();
  console.log(`Plugin endpoint OK: ${conf.urls.length} URLs`);
} catch (e) {
  console.log(`Plugin endpoint failed (${e.message}). Using sitemap.`);
  conf = await fromSitemap();
  console.log(`Sitemap: ${conf.urls.length} URLs`);
}

const width = parseInt(conf.width || cfg.width || 1440, 10);
const wait = parseInt(conf.wait_ms ?? cfg.wait_ms ?? 1500, 10);
const format = (conf.format || cfg.format || 'png') === 'jpg' ? 'jpg' : 'png';
const hide = conf.hide || cfg.hide || ['#cookie-notice', '#CybotCookiebotDialog', '.cc-window', '.cookie-banner', '#onetrust-banner-sdk', '.cmplz-cookiebanner'];

const used = new Set();
function fileFor(u) {
  const p = new URL(u);
  let slug = p.pathname.replace(/^\/+|\/+$/g, '').replace(/\//g, '__') || 'home';
  slug = slug.replace(/[^A-Za-z0-9._-]+/g, '-').slice(0, 100) || 'page';
  if (p.search) slug += '-' + crypto.createHash('md5').update(p.search).digest('hex').slice(0, 6);
  let name = `${slug}.${format}`;
  let i = 2;
  while (used.has(name)) name = `${slug}-${i++}.${format}`;
  used.add(name);
  return name;
}

const browser = await puppeteer.launch({
  executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome',
  headless: true,
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--hide-scrollbars'],
});

const items = [];
for (const url of conf.urls) {
  const file = fileFor(url);
  const item = { url, file: '', ok: false, time: new Date().toISOString(), error: '' };
  const page = await browser.newPage();
  try {
    await page.setUserAgent(UA);
    await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
    await page.goto(url, { waitUntil: 'load', timeout: 60000 });
    await page.waitForNetworkIdle({ idleTime: 800, timeout: 10000 }).catch(() => {});
    await page.evaluate(() => document.querySelectorAll('img[loading="lazy"]').forEach((i) => { i.loading = 'eager'; }));
    await page.evaluate(async () => {
      await new Promise((resolve) => {
        let y = 0;
        const t = setInterval(() => {
          window.scrollBy(0, 500);
          y += 500;
          if (y >= document.documentElement.scrollHeight || y > 40000) { clearInterval(t); window.scrollTo(0, 0); resolve(); }
        }, 120);
      });
    });
    for (const sel of hide) {
      try { await page.addStyleTag({ content: `${sel}{display:none!important}` }); } catch { /* bad selector */ }
    }
    await page.waitForNetworkIdle({ idleTime: 500, timeout: 5000 }).catch(() => {});
    await sleep(wait);
    const shot = { path: path.join(OUT, file), fullPage: true, type: format === 'jpg' ? 'jpeg' : 'png' };
    if (format === 'jpg') shot.quality = 85;
    await page.screenshot(shot);
    item.file = file;
    item.ok = true;
    console.log('OK   ', url);
  } catch (e) {
    item.error = String(e.message || e).slice(0, 200);
    console.log('FAIL ', url, item.error);
  } finally {
    await page.close().catch(() => {});
  }
  items.push(item);
}
await browser.close();

fs.writeFileSync(path.join(OUT, 'index.json'), JSON.stringify({ site: SITE, generated: new Date().toISOString(), items }, null, 2));
const okCount = items.filter((i) => i.ok).length;
console.log(`Done: ${okCount}/${items.length} captured`);
process.exit(okCount ? 0 : 1);
