# WP Easy Hub Connector

WordPress plugin, a separate repo from the Hub app (`../wpeasy-hub-app`, `app/Connector`, `ConnectorApiController`). Slug and folder `wpeasy-hub-connector`, namespace `WPEasy\HubConnector`, option `wpeasy_hub_connector` (never autoloaded), text domain `wpeasy-hub-connector`.

## Rules
- **Push by default, narrow signed calls where needed:** the plugin reports to the Hub; the Hub does not poll or pull from the site. The site has **no open API**: the only inbound requests are POSTs that must carry a valid signature, fresh timestamp and one-time nonce (or a login token), and only for a fixed list of actions (`Remote::ACTIONS`: `report`; plus the one-click login). Reason: every inbound door is attack surface, and a signed, single-action door is small and reviewable. A new Hub-to-site action needs: a signature check, a nonce claim (`Login::claim_nonce`), an entry in `Remote::ACTIONS`, a test, and a note here. Never add an unsigned or read-everything endpoint.
- **Never send more than the report** (versions, theme, plugin and theme names and versions, update versions, site URL). No users, content, settings or secrets.
- **Signatures:** `includes/Signature.php` must match the Hub's `app/Connector/Signature.php`; both assert the vector in `tests/signature-test.php` / `ConnectorTest`. Changing the format means a new version tag (`v2`) on both sides, never a silent change.
- Hub address: https only, except localhost, `.test` and `.localhost` for development. Requests never follow redirects.
- Admin actions are administrators only (`manage_options`) and nonce-checked. Escape all output.
- Plain, non-technical wording in everything a person sees. Brand: WP Easy.
- Version: edit the `Version:` header, the `VERSION` constant and `readme.txt` Stable tag together; `bin/build-zip.sh` builds the zip.

## One-click login
`includes/Login.php` + `includes/LoginToken.php`. Only a POST with a genuine, current, unused token signs anyone in; the user must still be an administrator; there is an off switch (`allow_login`, default on); failures are rate-limited by IP. "Works once" is an atomic `INSERT IGNORE` (`Login::claim_nonce`), never get-then-set (two simultaneous requests would both pass). Never accept the token in a GET address, never log it, never add another way to sign in. The report lists administrators by id, login and display name only (no emails).

## Updating things for the Hub
`Runner` (plugin, theme, core), `Backup`, `Health`. Rules learned by testing on a real site: **the answer must be JSON only**: WordPress's updater prints text, so `Remote` buffers all output (`ob_start`) and discards it; **`Automatic_Upgrader_Skin` has no `get_errors()`** (a call to it crashed the request right after the update: check the return value and `$skin->result` instead, `Runner::why_not`); anything unexpected mid-update must lead to the **put-back**, never a crash (try/catch Throwable around upgrade + check). **Never remove the backup-first step or the put-back**, and never update without a successful non-empty backup. Test every change to this on a throwaway site with a deliberate break (a mu-plugin that fatals once the new version is in place) and confirm the put-back.

## Updates
Through the Hub, never directly from GitHub: this repo is public and the plugin holds no secrets, and it only ever talks to its own Hub (`includes/Updater.php`, signed `GET`s). **Never put a token or any secret in this plugin or its zip** (the repo is public). Release with `bin/release.sh` (version header, `VERSION` constant and readme Stable tag must agree; the asset must be named `wpeasy-hub-connector-<version>.zip` with the plugin folder `wpeasy-hub-connector/` inside, which `bin/build-zip.sh` does).

## WP-CLI
`includes/Cli.php`: `wp wpeasy-hub connect --code=<code> [--hub=<url>]` and `wp wpeasy-hub status`, loaded only when `WP_CLI` is defined. It uses the same `Client::pair` as the settings page. Command line only: whoever can run it already controls the website, and it adds no way in from the web.

## Not built yet
One-time login from the Hub, running updates from the Hub, a report on demand from the Hub (needs an endpoint on the site), per-plugin details beyond name and version, translations (strings are already wrapped).
