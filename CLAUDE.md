# WP Easy Hub Connector

WordPress plugin, a separate repo from the Hub app (`../wpeasy-hub-app`, `app/Connector`, `ConnectorApiController`). Slug and folder `wpeasy-hub-connector`, namespace `WPEasy\HubConnector`, option `wpeasy_hub_connector` (never autoloaded), text domain `wpeasy-hub-connector`.

## Rules
- **Push model only:** the plugin reports to the Hub; the Hub never calls the site. That means no endpoint is exposed on the WordPress site. Keep it so unless login or update actions need one (then design it with signed, short-lived requests and ask first).
- **Never send more than the report** (versions, theme, plugin and theme names and versions, update versions, site URL). No users, content, settings or secrets.
- **Signatures:** `includes/Signature.php` must match the Hub's `app/Connector/Signature.php`; both assert the vector in `tests/signature-test.php` / `ConnectorTest`. Changing the format means a new version tag (`v2`) on both sides, never a silent change.
- Hub address: https only, except localhost, `.test` and `.localhost` for development. Requests never follow redirects.
- Admin actions are administrators only (`manage_options`) and nonce-checked. Escape all output.
- Plain, non-technical wording in everything a person sees. Brand: WP Easy.
- Version: edit the `Version:` header, the `VERSION` constant and `readme.txt` Stable tag together; `bin/build-zip.sh` builds the zip.

## One-click login
`includes/Login.php` + `includes/LoginToken.php`. Only a POST with a genuine, current, unused token signs anyone in; the user must still be an administrator; there is an off switch (`allow_login`, default on); failures are rate-limited by IP. "Works once" is an atomic `INSERT IGNORE` (`Login::claim_nonce`), never get-then-set (two simultaneous requests would both pass). Never accept the token in a GET address, never log it, never add another way to sign in. The report lists administrators by id, login and display name only (no emails).

## Updates
Through the Hub, never directly from GitHub: this repo is public and the plugin holds no secrets, and it only ever talks to its own Hub (`includes/Updater.php`, signed `GET`s). **Never put a token or any secret in this plugin or its zip** (the repo is public). Release with `bin/release.sh` (version header, `VERSION` constant and readme Stable tag must agree; the asset must be named `wpeasy-hub-connector-<version>.zip` with the plugin folder `wpeasy-hub-connector/` inside, which `bin/build-zip.sh` does).

## Not built yet
One-time login from the Hub, running updates from the Hub, a report on demand from the Hub (needs an endpoint on the site), per-plugin details beyond name and version, translations (strings are already wrapped).
