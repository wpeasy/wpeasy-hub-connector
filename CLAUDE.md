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

## Not built yet
One-time login from the Hub, running updates from the Hub, a report on demand from the Hub (needs an endpoint on the site), per-plugin details beyond name and version, translations (strings are already wrapped).
