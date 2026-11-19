// Site Shots: logged-in mobile capture (puppeteer)
import puppeteer, { KnownDevices } from 'puppeteer';
import fs from 'fs';
import path from 'path';

const cfg = JSON.parse(fs.readFileSync('config.json', 'utf8'));
const SITE = cfg.site.replace(/\/+$/, '');
const LOGIN_URL = cfg.login_url || SITE + '/wp-login.php';
const DEVICE = KnownDevices[cfg.device || 'iPhone 15 Pro'] || KnownDevices['iPhone 15 Pro Max'];
const OUT = 'shots';
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
  // REST API with login cookies: includes member-only content
  for (const type of ['pages', 'posts']) {
    for (let p = 1; p < 20; p++) {
      const r = await page.evaluate(async (u) => {
        const res = await fetch(u, { credentials: 'include' });
        return res.ok ? res.json() : null;
      }, `${SITE}/wp-json/wp/v2/${type}?per_page=100&page=${p}&_fields=link`);
      if (!r || !r.length) break;
      r.forEach(x => urls.add(x.link));
    }
  }
  (cfg.extra_urls || []).forEach(u => urls.add(u));
  return [...urls];
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
    await page.screenshot({ path: path.join(OUT, file), fullPage: cfg.capture !== 'viewport' });
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
