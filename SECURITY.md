# Security

## Reporting a problem
Please report security problems **privately**, not as a public issue:

- On GitHub: **Security** tab > **Report a vulnerability** (private vulnerability reporting is switched on for this repository), or
- by email to the WP Easy team via https://wpeasy.au/ (mark it "Hub Connector security").

Tell us what you found, how to reproduce it, and which version. We aim to reply within a few working days. Please give us reasonable time to fix a problem before sharing it publicly. We will credit you if you wish.

## How the plugin is protected (so you know what to test)
- **Nothing runs without pairing.** The plugin does nothing until an administrator pairs it with a Hub using a one-time code (works once, 15 minutes).
- **Every request is signed.** After pairing, the plugin and the Hub sign requests with a shared secret (HMAC-SHA256) that includes a timestamp and a one-time nonce. The secret is never sent again.
- **One-click login.** The Hub posts a signed token (valid 60 seconds, works once, tied to one connection) to the website. The plugin checks the signature, expiry, connection and that the token has not been used, then re-checks the user is still an administrator. It can be switched off in Settings > Hub Connector. The token is only ever in a POST body.
- **No secrets in this repository.** This repository is public: the code contains no keys, tokens or passwords.

## Out of scope
Problems that need an attacker to already control the WordPress database or the Hub's server are not vulnerabilities in the plugin (they can already do more), though we are glad to hear ideas for hardening.
