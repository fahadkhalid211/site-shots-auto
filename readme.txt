=== Site Shots Auto ===
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later

Full-page screenshots of every page and URL on your site. Free. No paid APIs.

== Description ==
* Auto-collects URLs: homepage, pages, posts, any public post type, extra URLs.
* Full-page capture with Puppeteer (open source). Lazy images loaded. Cookie banners hidden.
* Three engines: Puppeteer, Chrome CLI, WordPress mShots (free web service).
* Manual run with progress bar. Scheduled run daily or weekly.
* Download ZIP of all images. Export CSV mapping page URL to image URL.

== Setup: Puppeteer engine (recommended) ==
Needs Node.js 18+ and Chrome, Chromium or Edge on the same machine as WordPress.
No browser download. No paid service.

1. Upload plugin. Activate.
2. Terminal: cd wp-content/plugins/site-shots-auto/bin && npm install
3. Tools > Site Shots > Status. All green. Set Chrome path if red.
4. Tools > Site Shots > Capture all pages.

Windows / LocalWP: install Node from nodejs.org. Run npm install in bin folder. Chrome or Edge auto-detected.
Linux VPS: sudo apt install -y nodejs npm chromium  (or google-chrome-stable)

== Setup: GitHub Actions engine (full page, free, any hosting) ==
1. Create GitHub repo (public = unlimited free minutes).
2. Upload files from site-shots-github-repo.zip. Edit config.json with your site URL.
   Workflow file path must be .github/workflows/site-shots.yml
3. WordPress: Tools > Site Shots > Settings > Engine = GitHub Actions. Repo = username/repo. Save.
4. Repo > Actions tab > Site Shots > Run workflow. Wait until green.
5. Tools > Site Shots > Sync images.
Daily runs: edit cron line in workflow. Optional token enables "Run capture on GitHub" button.

== Setup: no shell access ==
Settings > Engine > WordPress mShots. Works on shared hosting. Viewport only, not full page. Public sites only (not localhost). URLs go to WordPress.com's free mShots service.

== Notes ==
* Screenshots saved in wp-content/uploads/site-shots/
* Password-protected and non-public pages need extra work (login cookies not supported).
* Scheduled runs use WP-Cron. Add a real server cron for reliability:
  */5 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null
* Filters: ssa_urls (modify URL list).

== Changelog ==
= 1.1.1 =
mShots waits longer for new pages. Shows real error.
= 1.1.0 =
GitHub Actions engine. REST endpoint /wp-json/site-shots/v1/urls.
= 1.0.1 =
Switch to puppeteer-core. No Chromium download. Edge and Windows paths. Clearer errors.
= 1.0.0 =
Initial release.
