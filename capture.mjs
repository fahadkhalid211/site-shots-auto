// Full-page screenshots of every page. Runs on GitHub Actions. Free.
// URL list + settings come from your WordPress plugin REST endpoint.
// Fallback: wp-sitemap.xml.
// Logged-in capture: set repo secrets WP_USER + WP_PASS (or WP_COOKIES).
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const cfg = JSON.parse(fs.readFileSync(new URL('./config.json', import.meta.url), 'utf8'));
const SITE = (process.env.SITE_URL || cfg.site || '').replace(/\/+$/, '');
if (!SITE) { console.error('Set "site" in config.json'); process.exit(2); }

const WP_USER = process.env.WP_USER || '';
const WP_PASS = process.env.WP_PASS || '';
const WP_COOKIES = process.env.WP_COOKIES || '';
const LOGIN_URL = process.env.LOGIN_URL || cfg.login_url || `${SITE}/wp-login.php`;

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

const IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
const dev = conf.device || cfg.device || { width: 393, height: 852, scale: 3, mobile: true, ua: IOS_UA };
const mode = (conf.mode || cfg.mode || 'full') === 'screen' ? 'screen' : 'full';
const MAX_PX = 16000; // Chrome texture limit safety
const wait = parseInt(conf.wait_ms ?? cfg.wait_ms ?? 1500, 10);
const format = (conf.format || cfg.format || 'png') === 'jpg' ? 'jpg' : 'png';
const stamp = (conf.stamp ?? cfg.stamp ?? true) !== false;
const tzName = conf.tz_name || cfg.tz_name || 'UTC';
const tzOffMin = Number.isFinite(conf.tz_offset_min) ? conf.tz_offset_min : (cfg.tz_offset_min || 0);
const hide = conf.hide || cfg.hide || ['#cookie-notice', '#CybotCookiebotDialog', '.cc-window', '.cookie-banner', '#onetrust-banner-sdk', '.cmplz-cookiebanner'];

const pad = (n) => String(n).padStart(2, '0');
const fmt = (d) => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())} ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())}`;
function stampText(url, now) {
  const utc = `${fmt(now)} UTC`;
  if (tzOffMin === 0 && (tzName === 'UTC' || tzName === '+00:00')) return `${url}\nCaptured: ${utc}`;
  const local = fmt(new Date(now.getTime() + tzOffMin * 60000));
  return `${url}\nCaptured: ${local} (${tzName})  |  ${utc}`;
}

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

// ---------- Login (optional) ----------
let loggedIn = false;
async function doLogin() {
  const page = await browser.newPage();
  try {
    await page.setUserAgent(UA);
    await page.setViewport({ width: 1280, height: 800 });
    if (WP_COOKIES) {
      const arr = JSON.parse(WP_COOKIES);
      const host = new URL(SITE).hostname;
      const cookies = arr.map((c) => ({
        name: c.name,
        value: c.value,
        domain: c.domain || host,
        path: c.path || '/',
        secure: !!c.secure,
        httpOnly: !!c.httpOnly,
        ...(c.expirationDate ? { expires: Math.floor(c.expirationDate) } : {}),
      }));
      await page.setCookie(...cookies);
      console.log(`Cookies loaded: ${cookies.length}`);
      return true;
    }
    if (!WP_USER || !WP_PASS) return false;
    await page.goto(LOGIN_URL, { waitUntil: 'load', timeout: 60000 });
    try {
      await page.waitForSelector('#user_login', { timeout: 15000 });
    } catch {
      throw new Error(`Login form not found at ${LOGIN_URL}. Set "login_url" in config.json.`);
    }
    await page.type('#user_login', WP_USER, { delay: 20 });
    await page.type('#user_pass', WP_PASS, { delay: 20 });
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load', timeout: 60000 }),
      page.click('#wp-submit'),
    ]);
    const cookies = await page.cookies(SITE);
    if (!cookies.some((c) => c.name.startsWith('wordpress_logged_in'))) {
      const err = await page.$eval('#login_error', (e) => e.textContent.trim()).catch(() => '');
      throw new Error('Login failed. ' + err);
    }
    console.log('Login OK');
    return true;
  } finally {
    await page.close().catch(() => {});
  }
}

try {
  loggedIn = await doLogin();
} catch (e) {
  console.error('LOGIN ERROR:', e.message);
  await browser.close();
  process.exit(1); // Stop. Never publish logged-out shots by mistake.
}
if (!loggedIn) console.log('No login credentials. Capturing as visitor.');

// ---------- Capture ----------
const items = [];
for (const url of conf.urls) {
  const file = fileFor(url);
  const item = { url, file: '', ok: false, time: new Date().toISOString(), error: '' };
  const page = await browser.newPage();
  try {
    await page.setUserAgent(dev.ua || UA);
    await page.setViewport({ width: dev.width, height: dev.height, deviceScaleFactor: dev.scale, isMobile: !!dev.mobile, hasTouch: !!dev.mobile });
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
    const css = hide.map((s) => `${s}{display:none!important}`);
    if (loggedIn) css.push('#wpadminbar{display:none!important}', 'html{margin-top:0!important}');
    for (const rule of css) {
      try { await page.addStyleTag({ content: rule }); } catch { /* bad selector */ }
    }
    await page.waitForNetworkIdle({ idleTime: 500, timeout: 5000 }).catch(() => {});
    await sleep(wait);

    const now = new Date();
    if (stamp) {
      await page.evaluate((text, mob) => {
        const d = document.createElement('div');
        d.id = 'ssa-stamp';
        d.style.cssText = 'all:initial;display:block;box-sizing:border-box;width:100%;padding:8px 12px;background:#111;color:#fff;font:600 ' + (mob ? '12' : '15') + 'px/1.4 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-all;position:relative;z-index:2147483647;';
        d.textContent = text;
        document.body.insertBefore(d, document.body.firstChild);
      }, stampText(page.url() || url, now), !!dev.mobile);
    }

    if (mode === 'full') {
      const h = await page.evaluate(() => document.documentElement.scrollHeight);
      if (h * dev.scale > MAX_PX) {
        await page.setViewport({ width: dev.width, height: dev.height, deviceScaleFactor: Math.max(1, Math.min(dev.scale, MAX_PX / h)), isMobile: !!dev.mobile, hasTouch: !!dev.mobile });
      }
    }
    const shot = { path: path.join(OUT, file), fullPage: mode === 'full', type: format === 'jpg' ? 'jpeg' : 'png' };
    if (format === 'jpg') shot.quality = 85;
    await page.screenshot(shot);
    item.file = file;
    item.ok = true;
    item.time = now.toISOString();
    item.final_url = page.url();
    console.log('OK   ', url, item.final_url !== url ? `(-> ${item.final_url})` : '');
  } catch (e) {
    item.error = String(e.message || e).slice(0, 200);
    console.log('FAIL ', url, item.error);
  } finally {
    await page.close().catch(() => {});
  }
  items.push(item);
}
await browser.close();

fs.writeFileSync(path.join(OUT, 'index.json'), JSON.stringify({ site: SITE, logged_in: loggedIn, generated: new Date().toISOString(), items }, null, 2));
const okCount = items.filter((i) => i.ok).length;
console.log(`Done: ${okCount}/${items.length} captured`);
process.exit(okCount ? 0 : 1);
