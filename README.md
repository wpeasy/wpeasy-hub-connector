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

## Updates (through the Hub)
This repo is public (the plugin holds no secrets), but a website never talks to GitHub: it asks **its own Hub**, which reads the releases and passes the zip through.
- The plugin asks `GET {hub}/api/connector/update-check` (signed like a report). If a newer release exists, WordPress shows its normal "update available" notice.
- WordPress's download is intercepted (`upgrader_pre_download`); the plugin fetches `GET {hub}/api/connector/update-download` with a signed request. Only paired websites are answered; disconnecting a site stops its updates.
- **Release:** bump the version (header, `VERSION` constant, `readme.txt` Stable tag), commit, then `bin/release.sh "what changed"`. It tags `v<version>` and creates a GitHub release with `wpeasy-hub-connector-<version>.zip` attached. The Hub offers it within about an hour.
- The first install on each site is a manual zip upload (a site must be paired before it can ask).

## Tests
`php tests/signature-test.php` checks the signature against the Hub's. The Hub's own `ConnectorTest` covers the server side.
