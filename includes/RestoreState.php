<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * The start and end of a restore, and the plugin's own maintenance mode while it runs.
 *
 * WordPress's own maintenance mode cannot be used: it switches every plugin off, including this one, so the Hub could not be answered. Instead, while a restore is active (a small state file exists
 * and was touched in the last ten minutes) this plugin shows visitors a "back soon" notice itself and keeps answering the Hub. The state file also holds this plugin's pairing (connection and secret):
 * a restored options table brings back the pairing as it was at backup time, which could be an old one, so during a restore the pairing is read from the file, and it is put back at the end. If a
 * restore is abandoned, the next request after ten minutes puts the pairing back and ends the notice, so a site is never left locked or cut off.
 *
 * The file sits in wp-content under a name only this website can work out (made from its secret keys), and exists only while a restore runs.
 */
class RestoreState {
	const WINDOW = 600; // seconds without a restore request before the notice ends by itself

	public static function file() {
		$key = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . ABSPATH;
		return rtrim( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content', '/' ) . '/.wpeasy-hub-restore-' . substr( hash_hmac( 'sha256', 'restore-state', $key ), 0, 24 ) . '.json';
	}

	/** @return array<string, mixed>|null The saved state while a restore is active (touched in the last ten minutes). */
	public static function state() {
		$f = self::file();
		if ( ! is_file( $f ) ) {
			return null;
		}
		$s = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $s ) ? $s : null;
	}

	public static function active() {
		$f = self::file();
		return is_file( $f ) && ( time() - (int) filemtime( $f ) ) < self::WINDOW;
	}

	/** The connection and secret to check requests against: the kept copy during a restore, else the saved ones. @return array{connection: string, secret: string} */
	public static function pairing() {
		if ( self::active() ) {
			$s = self::state();
			if ( $s && ! empty( $s['store']['connection'] ) && ! empty( $s['store']['secret'] ) ) {
				return array( 'connection' => (string) $s['store']['connection'], 'secret' => (string) $s['store']['secret'] );
			}
		}
		return array( 'connection' => (string) Store::get( 'connection' ), 'secret' => (string) Store::get( 'secret' ) );
	}

	/** Keeps the restore going: every restore request renews it. */
	public static function renew() {
		$f = self::file();
		if ( is_file( $f ) ) {
			@touch( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		}
	}

	public static function begin() {
		$f = self::file();
		if ( false === @file_put_contents( $f, wp_json_encode( array( 't' => time(), 'store' => Store::all() ) ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return array( 'ok' => false, 'message' => 'The plugin could not start the restore (wp-content is not writable).' );
		}
		@chmod( $f, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod
		$free = @disk_free_space( rtrim( ABSPATH, '/' ) );
		return array( 'ok' => true, 'free_bytes' => false === $free ? null : (int) $free );
	}

	/** Puts the pairing back, ends the notice and clears caches. */
	public static function finish( $permalinks = false ) {
		$s    = self::state();
		$kept = ( $s && isset( $s['store'] ) && is_array( $s['store'] ) ) ? $s['store'] : null;
		if ( is_array( $kept ) && ! empty( $kept['secret'] ) ) {
			update_option( OPTION, $kept, false ); // the pairing as it is today, not as the restored table had it
		}
		$f = self::file();
		if ( is_file( $f ) ) {
			@unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		wp_cache_flush();
		$res = array( 'ok' => true, 'settings_kept' => is_array( $kept ) );
		if ( $permalinks ) {
			$res['permalinks'] = self::flush_permalinks();
		}
		return $res;
	}

	/**
	 * After a website has been moved: makes WordPress rebuild its pretty-address rules for where it now lives, and write them to .htaccess where the server uses one (WordPress only writes that file from
	 * its admin side, so the pieces it needs are loaded here). Never an error: a server that does not use .htaccess, or a folder that cannot be written to, simply gives htaccess false.
	 *
	 * @return array{flushed: bool, htaccess: bool}
	 */
	public static function flush_permalinks() {
		if ( ! function_exists( 'flush_rewrite_rules' ) ) {
			return array( 'flushed' => false, 'htaccess' => false );
		}
		foreach ( array( 'wp-admin/includes/misc.php', 'wp-admin/includes/file.php' ) as $inc ) {
			if ( defined( 'ABSPATH' ) && is_file( ABSPATH . $inc ) ) {
				require_once ABSPATH . $inc;
			}
		}
		try {
			flush_rewrite_rules( true );
		} catch ( \Throwable $e ) {
			return array( 'flushed' => false, 'htaccess' => false );
		}
		$home = function_exists( 'get_home_path' ) ? get_home_path() : ( defined( 'ABSPATH' ) ? ABSPATH : '' );
		return array( 'flushed' => true, 'htaccess' => '' !== $home && is_file( $home . '.htaccess' ) );
	}

	/**
	 * Runs very early on every request (after plugins load): visitors get a 503 "back soon" notice while a restore is active, but the Hub's own signed requests pass. A restore that was abandoned
	 * (no request for ten minutes) is cleaned up here: the pairing is put back and the notice ends.
	 */
	public static function guard() {
		$f = self::file();
		if ( ! is_file( $f ) ) {
			return;
		}
		if ( ! self::active() ) {
			self::finish();
			return;
		}
		if ( isset( $_GET['wpeasy_hub_action'] ) && 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! headers_sent() ) {
			status_header( 503 );
			header( 'Retry-After: 600' );
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		echo '<!doctype html><meta charset="utf-8"><title>Back soon</title><p style="font:16px sans-serif;margin:3em auto;max-width:30em">This website is being restored from a backup and will be back in a few minutes. Briefly unavailable for scheduled maintenance.</p>';
		exit;
	}
}
