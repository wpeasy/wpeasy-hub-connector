<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** What the plugin remembers: the Hub's address, the connection and secret from pairing, and how the last report went. One option, never autoloaded. */
class Store {
	public static function all() {
		$o = get_option( OPTION, array() );
		return is_array( $o ) ? $o : array();
	}

	public static function get( $key, $default = '' ) {
		$o = self::all();
		return isset( $o[ $key ] ) ? $o[ $key ] : $default;
	}

	public static function set( array $values ) {
		update_option( OPTION, array_merge( self::all(), $values ), false );
	}

	public static function connected() {
		return self::get( 'connection' ) !== '' && self::get( 'secret' ) !== '';
	}

	/** Forgets the pairing (the Hub address is kept so it need not be typed again). */
	public static function forget() {
		$hub = self::get( 'hub_url' );
		update_option( OPTION, array( 'hub_url' => $hub ), false );
		wp_clear_scheduled_hook( CRON );
	}
}
