<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI: pair or check this website without anyone logging in to WordPress (used to connect many websites from the server).
 * Only available on the command line, where the person already has full control of the website; it adds no way in from the web.
 */
class Cli {
	/**
	 * Pairs this website with the Hub using the one-time code the Hub made for it.
	 *
	 * ## OPTIONS
	 *
	 * --code=<code>
	 * : The pairing code from the Hub (it works once and expires after 15 minutes).
	 *
	 * [--hub=<url>]
	 * : The Hub address. Defaults to the WP Easy Hub.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpeasy-hub connect --code=ABCD-EFGH-JKLM
	 *
	 * @param array $args  Positional arguments (none).
	 * @param array $assoc Named arguments.
	 */
	public function connect( $args, $assoc ) {
		if ( Store::connected() ) {
			\WP_CLI::success( 'Already connected to ' . Store::get( 'hub_url' ) . '. Nothing changed.' );
			return;
		}
		$hub = isset( $assoc['hub'] ) ? $assoc['hub'] : Settings::DEFAULT_HUB_URL;
		$r   = Client::pair( $hub, isset( $assoc['code'] ) ? $assoc['code'] : '' );
		if ( empty( $r['ok'] ) ) {
			\WP_CLI::error( $r['message'] );
		}
		\WP_CLI::success( $r['message'] );
	}

	/**
	 * Says whether this website is connected to the Hub, and when it last reported.
	 *
	 * @param array $args  Positional arguments (none).
	 * @param array $assoc Named arguments (none).
	 */
	public function status( $args, $assoc ) {
		$last = Store::get( 'last_report', array() );
		\WP_CLI::line( Store::connected() ? 'connected to ' . Store::get( 'hub_url' ) : 'not connected' );
		\WP_CLI::line( 'plugin ' . VERSION . '; last report: ' . ( ! empty( $last['time'] ) ? human_time_diff( (int) $last['time'] ) . ' ago' . ( ! empty( $last['ok'] ) ? ' (sent)' : ' (failed)' ) : 'never' ) );
	}
}
