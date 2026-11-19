# Site Shots runner

Free full-page screenshots via GitHub Actions. Used with the "Site Shots Auto" WordPress plugin.

## Setup
1. Edit `config.json`: set `site`. Set `login_url` only if login page is not /wp-login.php.
2. Logged-in capture: Settings > Secrets and variables > Actions > New repository secret
   - `WP_USER`  username of a dedicated member account
   - `WP_PASS`  its password
   Optional `WP_COOKIES`: JSON cookie export (use when login has captcha or 2FA).
3. Use a PRIVATE repo when capturing members-only pages.
4. Actions > Site Shots > Run workflow.
