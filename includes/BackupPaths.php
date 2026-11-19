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
	/** Single files at the top of the website that are backed up. */
	const ROOT_FILES = array( 'wp-config.php', '.htaccess', 'robots.txt' );

	/** Folders under wp-content (path from wp-content, lower case) that are left out: caches, other backup tools' files and this plugin's own backups. A path starting with one of these is skipped. */
	const SKIP_UNDER = array(
		'cache', 'litespeed', 'et-cache', 'wpo-cache', 'upgrade', 'wflogs', 'updraft', 'ai1wm-backups', 'backups', 'backup-db', 'backupwordpress', 'backwpup',
		'uploads/cache', 'uploads/backup', 'uploads/backups', 'uploads/wpeasy-hub-backups', 'uploads/sucuri', 'uploads/wp-clone', 'uploads/wc-logs',
	);

	/** Names skipped wherever they are. */
	const SKIP_NAMES = array( '.git', '.svn', 'node_modules', '.ds_store', 'thumbs.db', 'error_log', 'debug.log' );

	/** The places a backup covers, by the name the Hub uses for them: name => absolute path. @return array<string, string> */
	public static function roots() {
		$roots   = array();
		$abspath = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : $abspath . '/wp-content';
		$roots['wp-content'] = rtrim( str_replace( '\\', '/', $content ), '/' );
		foreach ( self::ROOT_FILES as $file ) {
			foreach ( array( $abspath . '/' . $file, dirname( $abspath ) . '/' . $file ) as $candidate ) { // wp-config.php may sit one level above WordPress
				if ( is_file( $candidate ) && ! is_link( $candidate ) ) {
					$roots[ $file ] = $candidate;
					break;
				}
			}
		}
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
	public static function resolve( $rel ) {
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
		$roots = self::roots();
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
