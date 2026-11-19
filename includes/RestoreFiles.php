<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Puts files back, a piece at a time. Each piece is written to "<name>.hubtmp" and, when the last piece has arrived and the size and fingerprint match what the Hub said, moved over the real file in
 * one step, so a half-sent file never replaces a good one. Permissions and the modified time are put back too. Nothing is ever deleted.
 */
class RestoreFiles {
	/**
	 * @param array<int, array<string, mixed>> $items Each: p (path), o (offset of this piece), data (base64), total (size), sha (sha256 of the whole file), x (permission bits), m (modified time), last (bool).
	 * @return array{ok: bool, done: int, message?: string, path?: string}
	 */
	public static function apply( array $items, $core = false ) {
		$done = 0;
		foreach ( $items as $it ) {
			$path = isset( $it['p'] ) ? (string) $it['p'] : '';
			$abs  = RestoreGuard::file( $path, $core );
			if ( null === $abs ) {
				return array( 'ok' => false, 'done' => $done, 'path' => $path, 'message' => 'This website does not allow that path to be restored.' );
			}
			$dir = dirname( $abs );
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				return array( 'ok' => false, 'done' => $done, 'path' => $path, 'message' => 'The folder could not be made.' );
			}
			if ( is_dir( $abs ) || is_link( $abs ) ) {
				return array( 'ok' => false, 'done' => $done, 'path' => $path, 'message' => 'A folder or link is where this file should go.' );
			}
			$tmp    = $abs . '.hubtmp';
			$offset = isset( $it['o'] ) ? max( 0, (int) $it['o'] ) : 0;
			$data   = base64_decode( isset( $it['data'] ) ? (string) $it['data'] : '', true );
			if ( false === $data ) {
				return array( 'ok' => false, 'done' => $done, 'path' => $path, 'message' => 'A piece of the file was damaged on the way.' );
			}
			if ( 0 === $offset ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			} elseif ( ! is_file( $tmp ) || (int) filesize( $tmp ) !== $offset ) {
				return array( 'ok' => false, 'done' => $done, 'path' => $path, 'message' => 'The pieces of the file arrived out of order.' );
			}
			$h = @fopen( $tmp, 0 === $offset ? 'wb' : 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $h ) {
				return array( 'ok' => false, 'done' => $done, 'path' => $path, 'message' => 'The file could not be written (check the folder\'s permissions).' );
			}
			$written = '' === $data ? 0 : fwrite( $h, $data );
			fclose( $h );
			if ( strlen( $data ) !== (int) $written ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				return array( 'ok' => false, 'done' => $done, 'path' => $path, 'message' => 'The disk is full or the file could not be written completely.' );
			}
			if ( ! empty( $it['last'] ) ) {
				$total = isset( $it['total'] ) ? (int) $it['total'] : -1;
				if ( (int) filesize( $tmp ) !== $total || ( isset( $it['sha'] ) && ! hash_equals( (string) $it['sha'], (string) hash_file( 'sha256', $tmp ) ) ) ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					return array( 'ok' => false, 'done' => $done, 'path' => $path, 'message' => 'The restored file did not match its fingerprint, so it was not put in place.' );
				}
				if ( isset( $it['x'] ) && is_numeric( $it['x'] ) ) {
					@chmod( $tmp, (int) $it['x'] & 0777 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod
				}
				if ( isset( $it['m'] ) && (int) $it['m'] > 0 ) {
					@touch( $tmp, (int) $it['m'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
				}
				if ( ! @rename( $tmp, $abs ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					return array( 'ok' => false, 'done' => $done, 'path' => $path, 'message' => 'The restored file could not be moved into place.' );
				}
				$done++;
			}
		}
		return array( 'ok' => true, 'done' => $done );
	}

	/** Makes folders (with their permissions). @param array<int, array{p: string, x?: int}> $dirs */
	public static function dirs( array $dirs, $core = false ) {
		$made = 0;
		foreach ( $dirs as $d ) {
			$abs = RestoreGuard::dir( isset( $d['p'] ) ? (string) $d['p'] : '', $core );
			if ( null === $abs ) {
				continue; // a folder that may not be written is simply not made; its files are refused one by one
			}
			if ( ! is_dir( $abs ) && @mkdir( $abs, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				$made++;
			}
			if ( is_dir( $abs ) && isset( $d['x'] ) && is_numeric( $d['x'] ) ) {
				@chmod( $abs, (int) $d['x'] & 0777 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod
			}
		}
		return array( 'ok' => true, 'made' => $made );
	}
}
