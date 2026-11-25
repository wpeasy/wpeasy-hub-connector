=== WP Easy Hub Connector ===
Contributors: wpeasy
Tags: management, updates, monitoring
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.14.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pairs this website with your WP Easy Hub, which then shows its WordPress and PHP versions and any updates waiting.

== Description ==

Open Settings > Hub Connector, enter your Hub's address and the one-time pairing code the Hub shows for this website, and press Connect.
After that the website reports, once a day, its WordPress and PHP versions, its active theme, and which plugins, themes and WordPress itself have an update waiting. Nothing else is sent.

Every report is signed with a secret agreed at pairing, so the Hub can tell it really came from this website.

== Changelog ==

= 0.14.0 =
* Moving a website: the search and replace inside files can now also look in every installed theme, in must-use plugins and in other folders inside wp-content, so the old address is changed wherever it is found.
* Moving a website: when the Hub finishes a move it can ask this website to rebuild its pretty-address (permalink) rules and write .htaccess, so pages other than the home page work straight away.

= 0.13.3 =
* The database scan no longer says a website's role list is missing when the website has hundreds of leftover role lists from an old network: it now asks for the website's own by exact name.

= 0.13.0 =
* Search and replace can also look inside files (style sheets, scripts, SVG, JSON...) in uploads, the theme or other plugins: a preview first, files written through a temporary copy, originals kept outside the web for 30 days so it can be undone. Text files only, never symbolic links, PHP only when asked.

= 0.12.0 =
* Search and replace the Hub can ask for: a dry run first, then a careful replace that keeps serialised data intact, also matches addresses written the JSON way, never touches passwords, sessions, the Hub Connector's own settings or (unless asked) post addresses (guid), keeps the old values for 30 days so it can be undone.

= 0.11.0 =
* The Hub can list the database tables with their sizes and wasted space and the size of the options loaded on every page, and optimise the tables it is told to (only this website's own tables, nothing dropped or emptied).

= 0.10.0 =
* Shield mode. While the Hub has it on, visitors without a preview link see a plain "in development" page (kept out of search engines) instead of the website. The Hub makes and revokes the preview links. People signed in to WordPress, the sign-in page and the Hub's own requests are never stopped.

= 0.9.3 =
* Clean-up the Hub can ask for: count, then remove old revisions, auto-drafts never saved, things in the trash, spam and trashed comments, expired temporary data and details left behind by things already deleted. Only those kinds, in batches.

= 0.9.2 =
* The Hub can list the people of one role and delete the ones it is told to (for example fake sign-ups). It never deletes anyone who can edit the website or who has written content, and each person deleted is saved on the website for 30 days so it can be undone.

= 0.9.1 =
* A fix the Hub can ask for after the database scan: a person whose role list is saved more than once keeps one copy. Only identical copies are removed, they are saved on the website first for 30 days so it can be undone, and nothing else is touched.

= 0.9.0 =
* A read-only database scan the Hub can ask for: how roles are stored for users (duplicates, damaged lists, people with no role here, roles left under another prefix after a multisite move, leftover tables). It changes nothing.

= 0.8.4 =
* The report now includes how many people hold each user role and when each administrator was added, so the Hub can show them and flag new administrators.

= 0.8.3 =
* Backups: the fix for tables with a binary key in 0.8.2 did not reach the request handler, so the table was still read in circles. It now does.

= 0.8.2 =
* Backups: a database table whose key is binary (for example some Wordfence tables) is now read page by page correctly. Before, the position was damaged on the way to the Hub and the table was read round and round without ever finishing.

= 0.8.1 =
* Restore now uses the plugin's own maintenance notice (WordPress's switches every plugin off, so the Hub could not be answered), and keeps its pairing safe while a restored settings table is put back.

= 0.8.0 =
* Your Hub can restore a backup onto this website: files, database tables and WordPress core. It takes a safety copy first, puts the site in maintenance mode while it works, never deletes anything and never overwrites this plugin. A tick in Settings > Hub Connector turns it off.

= 0.7.2 =
* Backups now cover everything in the website's folder except WordPress core (extra files like ads.txt, verification files and .well-known are no longer missed). The Hub can also ask for the core files when a site is set to include them.

= 0.7.1 =
* "Refresh now" on the Hub now makes WordPress look for new plugin, theme and core versions straight away, instead of showing its saved answer for up to 12 hours.

= 0.7.0 =
* Your Hub can back this website up: its wp-content files and its database, read a little at a time only when the Hub asks. A tick in Settings > Hub Connector turns it off.

= 0.6.2 =
* Broken pictures can be taken out of a page (or pointed at a new address) from the Hub too, not only text links.

= 0.6.1 =
* Refresh now on the Hub also forgets this plugin's remembered newest version, and that memory is kept for an hour instead of six, so a new release shows up on the Updates tab straight away.

= 0.6.0 =
* The Hub can take a broken link out of the text of a page or post (keeping its words) or point it at a new address. Each change is a normal save, so the page keeps a revision.

= 0.5.1 =
* An update that fails before WordPress has touched any files (the download or the package) now says it failed and that nothing was changed, instead of saying the old version was put back.

= 0.5.0 =
* Backups made before an update or removal are now one zip file each, never loose copies of the old plugin. They are kept outside the website's public folder when the host allows it (otherwise in a closed folder with a hidden name), only the newest two per plugin or theme are kept, and nothing is kept longer than 14 days. A daily job clears what is left.
* On the first run, the loose backup folders older versions left in the uploads folder are zipped (the recent ones) or deleted, and the old folder is removed.
* The report tells the Hub how many backups are kept and where.

= 0.4.2 =
* Each administrator is reported once, even when the website's database stores their role twice.

= 0.4.1 =
* The parent of the child theme in use is now reported as in use (and as a parent), so the Hub no longer offers to remove it.

= 0.4.0 =
* The Hub can remove a plugin or theme that is not in use (one at a time, when a person asks): a backup of its files is made first, anything in use is refused, and so is the Hub Connector itself. Deleting a plugin runs its own uninstall routine, which some plugins use to delete their data.

= 0.3.6 =
* Asked to update something that is already on that version (for example you updated it by hand), the website now answers "Already updated." instead of an error.

= 0.3.5 =
* WordPress can keep offering a version that is already installed until it next looks for updates. The report no longer calls that an update, and asking to update it is answered "already on this version" instead of failing and putting the same version back.

= 0.3.4 =
* After an update the website check now asks up to three times, a few seconds apart, before calling the website broken. Updating clears caches, so the first page can be built from scratch and, on a heavy website, run out of memory once and then work.

= 0.3.3 =
* A premium plugin or theme whose licence has lapsed offers an update WordPress cannot download. The report now marks it, and an update request for it fails at once with a plain reason instead of looking like the website broke.

= 0.3.2 =
* WP-CLI: `wp wpeasy-hub connect --code=...` pairs the website without logging in, and `wp wpeasy-hub status` shows the connection. Command line only; it adds no way in from the web.

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
