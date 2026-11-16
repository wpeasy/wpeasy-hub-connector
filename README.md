# WP Easy Hub Connector

WordPress plugin that pairs a website with the [WP Easy Hub](https://github.com/wpeasy/wpeasy-hub-app).

**Version 0.1.0 does two things only:** pairing, and a daily status report (WordPress and PHP versions, active theme, plugins and themes with an update waiting). No login and no update actions yet.

## Install
`bin/build-zip.sh` makes `dist/wpeasy-hub-connector-<version>.zip`. Upload it at Plugins > Add New > Upload Plugin, activate it, then open Settings > Hub Connector.

## Pairing
1. In the Hub, open the website (Websites list) and choose **Connect the plugin**. It shows a one-time code (works once, 15 minutes).
2. In WordPress, Settings > Hub Connector: enter the Hub address and the code, press **Connect**.
3. The Hub answers with a connection id and a secret. From then on the plugin sends signed reports; the secret is never sent again.

## Protocol (version 1)
`POST {hub}/api/connector/pair` `{code, site_url, plugin_version, wp_version, php_version}` returns `{connection, secret}`. The code must have been made for the same domain as `site_url`.

`POST {hub}/api/connector/report` with a JSON body and headers `X-Hub-Connection`, `X-Hub-Timestamp` (seconds, within 5 minutes), `X-Hub-Nonce` (used once) and `X-Hub-Signature` = HMAC-SHA256 hex, with the secret, of
`v1 \n METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256(body)`. See `includes/Signature.php`; the Hub's copy is `app/Connector/Signature.php` and both assert the same test vector.

## Refresh on request (0.2.2)
The Hub can ask the site to send its report now (the **Refresh now** button on the website's card), so a person need not wait for the daily report. The Hub posts a signed request to `/?wpeasy_hub_action=1` (`includes/Remote.php`: connection id, timestamp within 5 minutes, one-time nonce, HMAC-SHA256 over `v1 METHOD /wpeasy-hub-action TIMESTAMP NONCE sha256(body)`); the plugin then pushes its normal report. Only the actions in `Remote::ACTIONS` exist (`report`), and the site hands back nothing but `ok` and its plugin version.

## Updating from the Hub (0.3.0)
The Hub can ask this website to update a plugin, a theme, or WordPress itself (`includes/Remote.php` action `update`, signed like every Hub request, one item per request). `includes/Runner.php` does it with WordPress's own upgrader: a **plugin or theme is backed up first** (`includes/Backup.php`: a copy in `wp-content/uploads/wpeasy-hub-backups/`, closed to the web, newest 3 per item, 60 days), updated, **the website is checked** (`includes/Health.php`: a fresh request for its home page and sign-in page that loads the updated code), and if it is broken, or the update failed or threw an error part-way, **the backup is put back in the same request** (so it works even when the updated code crashes the site) and checked again. The answer is `updated`, `reverted` or `failed` with a plain message. WordPress core cannot be backed up or put back: it is updated and checked, and a broken site is reported as failed. Refused when `DISALLOW_FILE_MODS` is set or WordPress needs FTP details. The connector never updates itself this way (it updates through WordPress, via the Hub). The report now carries each plugin's file and each theme's folder (`id`) so the Hub can ask for the right one.

## One-click login (0.2.0)
The Hub can sign you in to this website's admin as an administrator chosen in the Hub. The Hub posts a signed token (`includes/LoginToken.php`: `base64url(JSON) . HMAC-SHA256`, secret from pairing, valid 60 seconds, works once) to `/?wpeasy_hub_login=1`; the plugin checks the signature, expiry, connection and nonce, re-checks the user is still an administrator, then signs them in and redirects to the dashboard. The token is only ever in a POST body. It skips any two-factor step on the site. It can be switched off in Settings > Hub Connector. The report lists the administrators (id, login, name) so the Hub can offer a choice.

## Updates (through the Hub)
This repo is public (the plugin holds no secrets), but a website never talks to GitHub: it asks **its own Hub**, which reads the releases and passes the zip through.
- The plugin asks `GET {hub}/api/connector/update-check` (signed like a report). If a newer release exists, WordPress shows its normal "update available" notice.
- WordPress's download is intercepted (`upgrader_pre_download`); the plugin fetches `GET {hub}/api/connector/update-download` with a signed request. Only paired websites are answered; disconnecting a site stops its updates.
- **Release:** bump the version (header, `VERSION` constant, `readme.txt` Stable tag), commit, then `bin/release.sh "what changed"`. It tags `v<version>` and creates a GitHub release with `wpeasy-hub-connector-<version>.zip` attached. The Hub offers it within about an hour.
- The first install on each site is a manual zip upload (a site must be paired before it can ask).

## Tests
`php tests/signature-test.php` and `php tests/login-token-test.php` check the signature and the login token against the Hub's; `php tests/nonce-test.php` checks a login token works only once. See `SECURITY.md` for reporting a problem. The Hub's own `ConnectorTest` covers the server side.
