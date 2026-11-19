<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Where a restore may write. A path the Hub sends is only ever written inside this website's own folders: not outside them, not through a symlink, not onto a folder, never WordPress core (unless the
 * restore says core is included), never this plugin's own folder (it is the code running the restore), never the maintenance file, and never the places a backup does not cover (caches, other backup tools).
 */
class RestoreGuard {
	const OWN_DIR = 'wp-content/plugins/wpeasy-hub-connector';

	/** The absolute path a file may be written to, or null when it is not allowed. */
	public static function file( $rel, $core = false ) {
		return self::resolve( $rel, $core );
	}

	/** The absolute path of a folder that may be created, or null. */
	public static function dir( $rel, $core = false ) {
		return self::resolve( $rel, $core );
	}

	private static function resolve( $rel, $core ) {
		$rel = trim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || 1 !== preg_match( '//u', $rel ) ) {
			return null;
		}
		$parts = explode( '/', $rel );
		foreach ( $parts as $p ) {
			if ( '' === $p || '.' === $p || '..' === $p ) {
				return null;
			}
		}
		$last = strtolower( end( $parts ) );
		if ( '.maintenance' === $last || '.hubtmp' === substr( $last, -7 ) || 0 === strpos( $last, '.wpeasy-hub-restore-' ) ) {
			return null;
		}
		$lower = strtolower( $rel );
		if ( $lower === self::OWN_DIR || 0 === strpos( $lower, self::OWN_DIR . '/' ) ) {
			return null;
		}
		$first = $parts[0];
		if ( BackupPaths::excluded( $rel ) ) {
			return null;
		}
		$lf = strtolower( $first );
		if ( 'wp-content' === $first ) {
			$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : rtrim( ABSPATH, '/' ) . '/wp-content';
			$rest = array_slice( $parts, 1 );
			if ( ! $rest ) {
				return null;
			}
		} else {
			if ( ! $core && ( in_array( $lf, BackupPaths::CORE_DIRS, true ) || in_array( $lf, BackupPaths::CORE_FILES, true ) ) ) {
				return null;
			}
			if ( in_array( $lf, BackupPaths::ROOT_SKIP, true ) ) {
				return null;
			}
			$base = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
			$rest = $parts;
			if ( 'wp-config.php' === $first && count( $parts ) === 1 && ! is_file( $base . '/wp-config.php' ) && is_file( dirname( $base ) . '/wp-config.php' ) ) {
				$base = dirname( $base ); // wp-config.php one level above WordPress
			}
		}
		$real_base = realpath( $base );
		if ( false === $real_base ) {
			return null;
		}
		$real_base = str_replace( '\\', '/', $real_base );
		$cur       = $real_base;
		foreach ( $rest as $i => $part ) {
			$next = $cur . '/' . $part;
			if ( is_link( $next ) ) {
				return null;
			}
			if ( file_exists( $next ) ) {
				$is_last = ( count( $rest ) - 1 === $i );
				if ( ! $is_last && ! is_dir( $next ) ) {
					return null;
				}
				$rp = realpath( $next );
				if ( false === $rp || 0 !== strpos( str_replace( '\\', '/', $rp ) . '/', $real_base . '/' ) ) {
					return null;
				}
			}
			$cur = $next;
		}
		return $cur;
	}
}
