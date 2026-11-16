=== WP Easy Hub Connector ===
Contributors: wpeasy
Tags: management, updates, monitoring
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pairs this website with your WP Easy Hub, which then shows its WordPress and PHP versions and any updates waiting.

== Description ==

Open Settings > Hub Connector, enter your Hub's address and the one-time pairing code the Hub shows for this website, and press Connect.
After that the website reports, once a day, its WordPress and PHP versions, its active theme, and which plugins, themes and WordPress itself have an update waiting. Nothing else is sent.

Every report is signed with a secret agreed at pairing, so the Hub can tell it really came from this website.

== Changelog ==

= 0.3.1 =
* The Hub can now update this connector too (backed up first, checked afterwards, put back if the website breaks, like everything else).

= 0.3.0 =
* The Hub can update this website's plugins, themes and WordPress itself (signed request, one item at a time). A plugin or theme is backed up first, the website is checked afterwards, and the old version is put back automatically if the website is broken. Nothing runs unless the Hub asks.

= 0.2.3 =
* After an update the plugin reports to the Hub within a minute, so the Hub learns the new version at once.

= 0.2.2 =
* The Hub can ask this website to send its report right now (signed request; nothing else can be asked).

= 0.2.1 =
* Security: a one-click login token can now be used once even if two requests carrying it arrive at the same instant.

= 0.2.0 =
* One-click login from the Hub (as an administrator chosen in the Hub). Can be switched off in Settings > Hub Connector. The report now lists the website's administrators.

= 0.1.1 =
* A clearer message when a firewall in front of the Hub refuses this website, the Hub address starts as WP Easy's own Hub (https://hub.wpeasy.au) and stays filled in after a failed attempt.

= 0.1.0 =
* First version: pairing and a daily status report.
