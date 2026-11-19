<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * The start and end of a restore. Start: the website goes into WordPress's own maintenance mode (visitors see "briefly unavailable"; it is renewed with every restore request and WordPress itself drops
 * it after ten minutes without one, so a restore that is abandoned never leaves the site locked) and this plugin's own settings (the pairing) are kept safely aside. End: those settings are put back
 * (a restored options table holds the settings as they were at backup time, which could be an old pairing), caches are cleared and maintenance mode ends.
 */
class RestoreState {
	public static function maintenance( $on ) {
		$file = rtrim( ABSPATH, '/' ) . '/.maintenance';
		if ( $on ) {
			return false !== @file_put_contents( $file, '<?php $upgrading = ' . time() . ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return ! file_exists( $file ) || @unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	public static function begin() {
		$path = Backup::base() . '/restore-state-' . wp_generate_password( 12, false, false ) . '.json';
		if ( false === @file_put_contents( $path, wp_json_encode( Store::all() ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return array( 'ok' => false, 'message' => 'The plugin could not keep its own settings safe (its backup folder is not writable).' );
		}
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod
		Store::set( array( 'restore_state' => $path ) );
		self::maintenance( true );
		$free = @disk_free_space( rtrim( ABSPATH, '/' ) );
		return array( 'ok' => true, 'free_bytes' => false === $free ? null : (int) $free );
	}

	public static function finish() {
		$path = (string) Store::get( 'restore_state' );
		$kept = ( '' !== $path && is_file( $path ) ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_array( $kept ) && ! empty( $kept['secret'] ) ) {
			unset( $kept['restore_state'] );
			update_option( OPTION, $kept, false ); // the pairing as it is today, not as the restored table had it
		}
		if ( '' !== $path && is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		Store::set( array( 'restore_state' => '' ) );
		wp_cache_flush();
		self::maintenance( false );
		return array( 'ok' => true, 'settings_kept' => is_array( $kept ) );
	}
}
