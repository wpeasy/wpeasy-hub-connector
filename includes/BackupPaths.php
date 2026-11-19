<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * What the Hub may look at when it backs this website up, and nothing else. A backup covers the website's own files: everything in wp-content (uploads, plugins, themes,
 * mu-plugins, languages) plus wp-config.php, .htaccess and robots.txt. WordPress itself is not backed up (it can be reinstalled; the version is recorded). Every path the Hub
 * sends is checked here: it must be inside one of those places once symlinks are resolved, must not be a symlink, and must not be something a backup never needs (caches,
 * other backup tools' files, logs, version-control folders). A path that is not allowed behaves as if it does not exist.
 */
class BackupPaths {
	/** WordPress core: left out unless the Hub asks for it (it can be reinstalled from wordpress.org, and is large). */
	const CORE_DIRS  = array( 'wp-admin', 'wp-includes' );
	const CORE_FILES = array( 'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php' );

	/** Top-level names never backed up: other backup tools' folders and the host's own folders. */
	const ROOT_SKIP = array( 'backups', 'backup', 'cgi-bin', 'error_log', 'lost+found' );

	/** Folders under wp-content (path from wp-content, lower case) that are left out: caches, other backup tools' files and this plugin's own backups. A path starting with one of these is skipped. */
	const SKIP_UNDER = array(
		'cache', 'litespeed', 'et-cache', 'wpo-cache', 'upgrade', 'wflogs', 'updraft', 'ai1wm-backups', 'backups', 'backup-db', 'backupwordpress', 'backwpup',
		'uploads/cache', 'uploads/backup', 'uploads/backups', 'uploads/wpeasy-hub-backups', 'uploads/sucuri', 'uploads/wp-clone', 'uploads/wc-logs',
	);

	/** Names skipped wherever they are. */
	const SKIP_NAMES = array( '.git', '.svn', 'node_modules', '.ds_store', 'thumbs.db', 'error_log', 'debug.log' );

	/**
	 * The top-level places a backup covers, by the name the Hub uses for them: name => absolute path. Everything in the website's folder except WordPress core (unless `$core`),
	 * with wp-content taken from where WordPress says it is. wp-config.php may sit one level above WordPress.
	 *
	 * @param bool $core Include WordPress core (wp-admin, wp-includes and the loose core files).
	 * @return array<string, string>
	 */
	public static function roots( $core = false ) {
		$roots   = array();
		$abspath = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : $abspath . '/wp-content';
		$roots['wp-content'] = rtrim( str_replace( '\\', '/', $content ), '/' );
		$h = @opendir( $abspath );
		if ( $h ) {
			while ( false !== ( $name = readdir( $h ) ) ) {
				if ( '.' === $name || '..' === $name || 'wp-content' === $name ) {
					continue;
				}
				$lower = strtolower( $name );
				if ( in_array( $lower, self::SKIP_NAMES, true ) || in_array( $lower, self::ROOT_SKIP, true ) || '.log' === substr( $lower, -4 ) ) {
					continue;
				}
				if ( ! $core && ( in_array( $lower, self::CORE_DIRS, true ) || in_array( $lower, self::CORE_FILES, true ) ) ) {
					continue;
				}
				$full = $abspath . '/' . $name;
				if ( is_link( $full ) || ( ! is_dir( $full ) && ! is_file( $full ) ) || 1 !== preg_match( '//u', $name ) ) {
					continue;
				}
				$roots[ $name ] = $full;
			}
			closedir( $h );
		}
		if ( ! isset( $roots['wp-config.php'] ) ) { // one level above WordPress
			$above = dirname( $abspath ) . '/wp-config.php';
			if ( is_file( $above ) && ! is_link( $above ) ) {
				$roots['wp-config.php'] = $above;
			}
		}
		ksort( $roots );
		return $roots;
	}

	/** True when a path (from the Hub's point of view, like "wp-content/uploads/2026") is one a backup never needs. */
	public static function excluded( $rel ) {
		$rel   = strtolower( trim( str_replace( '\\', '/', (string) $rel ), '/' ) );
		$parts = explode( '/', $rel );
		foreach ( $parts as $part ) {
			if ( in_array( $part, self::SKIP_NAMES, true ) ) {
				return true;
			}
		}
		$name = end( $parts );
		if ( '.log' === substr( (string) $name, -4 ) ) {
			return true;
		}
		if ( 0 === strpos( $rel, 'wp-content/' ) ) {
			$under = substr( $rel, 11 );
			foreach ( self::SKIP_UNDER as $skip ) {
				if ( $under === $skip || 0 === strpos( $under, $skip . '/' ) || ( 'uploads/wpeasy-hub-backups' === $skip && 0 === strpos( $under, $skip ) ) ) { // this plugin's own folder has a random ending
					return true;
				}
			}
		}
		return false;
	}

	/** The real path on disk for a path the Hub sent, or null when it is not allowed (outside the backed-up places, a symlink, excluded, or not there). */
	public static function resolve( $rel, $core = false ) {
		$rel = trim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( '' === $rel || false !== strpos( $rel, "\0" ) ) {
			return null;
		}
		$parts = explode( '/', $rel );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return null;
			}
		}
		$roots = self::roots( $core );
		$first = $parts[0];
		if ( ! isset( $roots[ $first ] ) || self::excluded( $rel ) ) {
			return null;
		}
		$base = realpath( $roots[ $first ] );
		if ( false === $base ) {
			return null;
		}
		if ( 1 === count( $parts ) ) { // the place itself (the wp-content folder, or one of the single files, which roots() has already checked are not symlinks)
			return $base;
		}
		if ( ! is_dir( $base ) ) {
			return null;
		}
		$abs = $base . '/' . implode( '/', array_slice( $parts, 1 ) );
		if ( is_link( $abs ) ) {
			return null;
		}
		$real = realpath( $abs );
		if ( false === $real || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) { // a symlink in the middle may not lead out of the place
			return null;
		}
		return $real;
	}
}
