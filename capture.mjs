// Site Shots: logged-in mobile capture (puppeteer)
import puppeteer, { KnownDevices } from 'puppeteer';
import fs from 'fs';
import path from 'path';

const cfg = JSON.parse(fs.readFileSync('config.json', 'utf8'));
const SITE = cfg.site.replace(/\/+$/, '');
const LOGIN_URL = cfg.login_url || SITE + '/wp-login.php';
const DEVICE = KnownDevices[cfg.device || 'iPhone 15 Pro'] || KnownDevices['iPhone 15 Pro Max'];
const OUT = 'out/shots';
const USER = process.env.WP_USER, PASS = process.env.WP_PASS;
const LOGGED_OUT_HINTS = (cfg.logged_out_text || [
  'you need to login', 'you must be logged in', 'please log in', 'please login',
  'log in to view', 'login to view', 'members only', 'restricted content'
]).map(s => s.toLowerCase());

if (!USER || !PASS) { console.error('FATAL: WP_USER / WP_PASS secrets missing'); process.exit(1); }
fs.mkdirSync(OUT, { recursive: true });

const browser = await puppeteer.launch({ args: ['--no-sandbox', '--disable-setuid-sandbox'] });
// ONE page for login AND capture, so cookies are always shared.
const page = await browser.newPage();
await page.emulate(DEVICE);

async function isLoggedIn() {
  const cookies = await page.cookies(SITE);
  return cookies.some(c => c.name.startsWith('wordpress_logged_in_'));
}

// ---- PHONE FRAME ----
async function frameShot(buf, outFile, dsf) {
  const W = DEVICE.viewport.width, bez = 14, pad = 40;
  const bg = cfg.frame_bg || '#e9edf3';
  const fp = await browser.newPage();
  await fp.setViewport({ width: W + 2 * (bez + pad), height: 400, deviceScaleFactor: dsf });
  await fp.setContent(`<html><body style="margin:0;background:${bg};padding:${pad}px;box-sizing:border-box">
    <div style="position:relative;width:${W + 2 * bez}px;background:#111;border-radius:56px;padding:${bez}px;box-sizing:border-box;box-shadow:0 20px 50px rgba(0,0,0,.35),inset 0 0 0 2px #444">
      <div style="position:absolute;left:-4px;top:140px;width:4px;height:60px;background:#333;border-radius:3px 0 0 3px"></div>
      <div style="position:absolute;right:-4px;top:200px;width:4px;height:90px;background:#333;border-radius:0 3px 3px 0"></div>
      <div style="position:relative;border-radius:42px;overflow:hidden;line-height:0">
        <img src="data:image/png;base64,${Buffer.from(buf).toString('base64')}" style="width:${W}px;display:block">
        <div style="position:absolute;top:10px;left:50%;transform:translateX(-50%);width:110px;height:30px;background:#000;border-radius:20px"></div>
      </div>
    </div></body></html>`);
  await fp.screenshot({ path: outFile, fullPage: true });
  await fp.close();
}

// ---- LOGIN ----
await page.goto(LOGIN_URL, { waitUntil: 'networkidle2', timeout: 60000 });
await page.waitForSelector('#user_login', { timeout: 20000 });
await page.type('#user_login', USER, { delay: 30 });
await page.type('#user_pass', PASS, { delay: 30 });
await Promise.all([
  page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {}),
  page.click('#wp-submit'),
]);
if (!(await isLoggedIn())) {
  const err = await page.$eval('#login_error', e => e.innerText).catch(() => 'no login cookie set (captcha, 2FA, or custom login page?)');
  console.error('FATAL: login failed: ' + err);
  process.exit(1);
}
console.log('Login OK');

// ---- URL LIST ----
async function getUrls() {
  if (cfg.urls && cfg.urls.length) return cfg.urls;
  const urls = new Set([SITE + '/']);
  const only = cfg.post_types && cfg.post_types.length ? cfg.post_types : null;

  // 1) REST API, authenticated with a nonce so private/members content is listed
  const nonce = await page.evaluate(async (u) => {
    try { const r = await fetch(u + '/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'include' }); return r.ok ? (await r.text()).trim() : ''; } catch { return ''; }
  }, SITE);
  const api = (u) => page.evaluate(async (u, n) => {
    try { const r = await fetch(u, { credentials: 'include', headers: n ? { 'X-WP-Nonce': n } : {} }); return r.ok ? r.json() : null; } catch { return null; }
  }, u, nonce);

  const types = await api(`${SITE}/wp-json/wp/v2/types?context=view`);
  const bases = [];
  if (types) for (const [slug, t] of Object.entries(types)) {
    if (['attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'].includes(slug)) continue;
    if (only && !only.includes(slug)) continue;
    bases.push(t.rest_base || slug);
  } else bases.push('pages', 'posts');
  for (const base of bases) {
    let n = 0;
    for (let p = 1; p < 50; p++) {
      const r = await api(`${SITE}/wp-json/wp/v2/${base}?per_page=100&page=${p}&_fields=link`);
      if (!r || !r.length) break;
      r.forEach(x => { if (x.link) { urls.add(x.link); n++; } });
    }
    console.log(`REST ${base}: ${n}`);
  }

  // 2) Sitemaps: catches custom post types that are not in the REST API
  for (const root of ['wp-sitemap.xml', 'sitemap_index.xml', 'sitemap.xml']) {
    const xml = await page.evaluate(async (u) => { try { const r = await fetch(u, { credentials: 'include' }); return r.ok ? r.text() : ''; } catch { return ''; } }, `${SITE}/${root}`);
    if (!xml) continue;
    const locs = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1].trim());
    const subs = locs.filter(l => l.endsWith('.xml') && !/user|author|taxonom|tax-|category|tag/i.test(l)
      && (!only || only.some(t => l.includes('-' + t + '-') || l.includes(t + '-sitemap'))));
    for (const sub of subs.length ? subs : []) {
      const x = await page.evaluate(async (u) => { try { const r = await fetch(u, { credentials: 'include' }); return r.ok ? r.text() : ''; } catch { return ''; } }, sub);
      [...x.matchAll(/<loc>([^<]+)<\/loc>/g)].forEach(m => urls.add(m[1].trim()));
    }
    if (subs.length) { console.log(`Sitemap ${root}: ${subs.length} sub-sitemaps`); break; }
  }

  (cfg.extra_urls || []).forEach(u => urls.add(u));
  return [...urls].filter(u => !/\.(xml|pdf|jpe?g|png|gif|webp|zip)$/i.test(u));
}

// ---- CAPTURE ----
const urls = await getUrls();
console.log(`${urls.length} URLs`);
const items = [];
let blocked = 0;

for (const url of urls) {
  try {
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });
    if (!(await isLoggedIn())) throw new Error('session lost');

    // logged-out detection
    const text = (await page.evaluate(() => document.body.innerText)).toLowerCase();
    const hit = LOGGED_OUT_HINTS.find(h => text.includes(h));
    if (hit) { blocked++; console.log(`BLOCKED (${hit}) ${url}`); }

    await page.addStyleTag({ content: '#wpadminbar{display:none!important}html{margin-top:0!important}' });
    // trigger lazy load
    await page.evaluate(async () => {
      await new Promise(res => {
        let y = 0; const t = setInterval(() => {
          window.scrollBy(0, 600); y += 600;
          if (y >= document.body.scrollHeight) { clearInterval(t); window.scrollTo(0, 0); res(); }
        }, 120);
      });
    });
    await new Promise(r => setTimeout(r, 800));

    const now = new Date();
    const stamp = now.toISOString().replace('T', ' ').slice(0, 19) + ' UTC';
    if (cfg.timestamp !== false) {
      await page.evaluate((u, s) => {
        const d = document.createElement('div');
        d.style.cssText = 'position:relative;background:#000;color:#fff;font:11px/1.4 monospace;padding:6px 8px;z-index:2147483647;word-break:break-all';
        d.textContent = u + '  |  ' + s;
        document.body.prepend(d);
      }, url, stamp);
    }

    const slug = (new URL(url).pathname.replace(/\W+/g, '_').replace(/^_|_$/g, '') || 'home');
    const file = `${slug}.png`;
    // keep inside Chrome's ~16k px limit
    const full = cfg.capture !== 'viewport';
    const h = full ? await page.evaluate(() => document.documentElement.scrollHeight) : DEVICE.viewport.height;
    const dsf = Math.max(1, Math.min(DEVICE.viewport.deviceScaleFactor || 3, 15000 / h));
    await page.setViewport({ ...DEVICE.viewport, deviceScaleFactor: dsf });
    const shot = await page.screenshot({ fullPage: full });
    await page.setViewport(DEVICE.viewport);
    if (cfg.frame === false) fs.writeFileSync(path.join(OUT, file), shot);
    else await frameShot(shot, path.join(OUT, file), dsf);
    items.push({ url, file, captured_at: now.toISOString(), logged_out_text_found: !!hit });
    console.log('OK ' + url);
  } catch (e) {
    console.log('FAIL ' + url + ' :: ' + e.message);
    items.push({ url, file: null, error: e.message });
  }
}

fs.writeFileSync(path.join(OUT, 'index.json'), JSON.stringify({ generated: new Date().toISOString(), device: cfg.device || 'iPhone 15 Pro', items }, null, 2));
await browser.close();
console.log(`Done. ${items.filter(i => i.file).length} captured, ${blocked} still showed login message.`);
if (blocked > urls.length / 2) { console.error('Most pages still logged-out. Check member role.'); process.exit(1); }
