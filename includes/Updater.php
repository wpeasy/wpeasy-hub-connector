<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Updates this plugin through the Hub, which reads the releases of its private GitHub repo with a token that only the Hub holds. The site never sees a
 * GitHub token: it asks the Hub with the same signed requests as its reports, and only a paired site is answered. WordPress's own update screen does the rest.
 */
class Updater {
	const SLUG       = 'wpeasy-hub-connector';
	const CHECK_PATH = '/api/connector/update-check';
	const CACHE      = 'wpeasy_hub_update';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
	}

	private static function plugin_file() {
		return plugin_basename( dirname( __DIR__ ) . '/wpeasy-hub-connector.php' );
	}

	/** What the Hub says about the newest version (asked at most every few hours). @return array|null */
	private static function latest() {
		if ( ! Store::connected() ) {
			return null;
		}
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$r    = Client::signed_get( self::CHECK_PATH );
		$info = ( $r['ok'] && ! empty( $r['data']['version'] ) ) ? $r['data'] : array( 'version' => null );
		set_site_transient( self::CACHE, $info, $info['version'] ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		return $info['version'] ? $info : null;
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$latest = self::latest();
		$entry  = (object) array(
			'id'          => self::SLUG,
			'slug'        => self::SLUG,
			'plugin'      => self::plugin_file(),
			'new_version' => $latest ? $latest['version'] : VERSION,
			'url'         => '',
			'package'     => $latest && ! empty( $latest['package'] ) ? $latest['package'] : '',
		);
		if ( $latest && version_compare( $latest['version'], VERSION, '>' ) && '' !== $entry->package ) {
			$transient->response[ self::plugin_file() ] = $entry;
		} else {
			$transient->no_update[ self::plugin_file() ] = $entry;
		}
		return $transient;
	}

	/** The "View details" box on the Plugins screen. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$latest = self::latest();
		if ( ! $latest ) {
			return $result;
		}
		return (object) array(
			'name'     => 'WP Easy Hub Connector',
			'slug'     => self::SLUG,
			'version'  => $latest['version'],
			'author'   => 'WP Easy',
			'sections' => array( 'changelog' => '<pre>' . esc_html( isset( $latest['notes'] ) ? $latest['notes'] : '' ) . '</pre>' ),
		);
	}

	/** WordPress cannot add our signature to a download, so the plugin fetches its own package, from the Hub only. */
	public static function download( $reply, $package, $upgrader ) {
		if ( ! Store::connected() || Store::get( 'hub_url' ) . '/api/connector/update-download' !== $package ) {
			return $reply;
		}
		return Client::download_package( '/api/connector/update-download' );
	}
}
