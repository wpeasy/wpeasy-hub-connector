<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** Talks to the Hub: pairs with a one-time code, then sends signed reports. Never anything but plain words come back to the person using the settings page. */
class Client {
	/** The Hub's address, cleaned: https only (plain http only for a Hub on this machine or a .test / .localhost name, while developing). */
	public static function clean_hub_url( $url ) {
		$url   = trim( (string) $url );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host  = strtolower( $parts['host'] );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) || substr( $host, -5 ) === '.test' || substr( $host, -10 ) === '.localhost';
		if ( 'https' !== $parts['scheme'] && ! ( 'http' === $parts['scheme'] && $local ) ) {
			return '';
		}
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/** @return array{ok: bool, message: string} */
	public static function pair( $hub_url, $code ) {
		$hub = self::clean_hub_url( $hub_url );
		if ( '' === $hub ) {
			return array( 'ok' => false, 'message' => __( 'Enter the Hub address, starting with https://.', 'wpeasy-hub-connector' ) );
		}
		if ( '' === trim( (string) $code ) ) {
			return array( 'ok' => false, 'message' => __( 'Enter the pairing code from the Hub.', 'wpeasy-hub-connector' ) );
		}

		$res = self::post( $hub . '/api/connector/pair', array(
			'code'           => $code,
			'site_url'       => home_url(),
			'plugin_version' => VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		) );
		if ( ! $res['ok'] ) {
			return $res;
		}
		$data = $res['data'];
		if ( empty( $data['connection'] ) || empty( $data['secret'] ) ) {
			return array( 'ok' => false, 'message' => __( 'The Hub answered, but not in the way expected. Check the Hub address.', 'wpeasy-hub-connector' ) );
		}

		Store::set( array( 'hub_url' => $hub, 'connection' => (string) $data['connection'], 'secret' => (string) $data['secret'], 'connected_at' => time() ) );
		if ( ! wp_next_scheduled( CRON ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', CRON );
		}
		self::send_report( true );

		return array( 'ok' => true, 'message' => __( 'Connected. This website will now report to the Hub once a day.', 'wpeasy-hub-connector' ) );
	}

	/** Sends the report (the daily schedule, or the button). @return array{ok: bool, message: string} */
	public static function send_report( $refresh = false ) {
		if ( ! Store::connected() ) {
			return array( 'ok' => false, 'message' => __( 'Not connected.', 'wpeasy-hub-connector' ) );
		}
		$body = wp_json_encode( Report::collect( (bool) $refresh ) );
		$path = '/api/connector/report';
		$ts   = time();
		$nonce = wp_generate_password( 24, false, false );

		$res = self::post( Store::get( 'hub_url' ) . $path, $body, array(
			'X-Hub-Connection' => Store::get( 'connection' ),
			'X-Hub-Timestamp'  => (string) $ts,
			'X-Hub-Nonce'      => $nonce,
			'X-Hub-Signature'  => Signature::make( Store::get( 'secret' ), 'POST', $path, $ts, $nonce, $body ),
		) );

		Store::set( array( 'last_report' => array( 'time' => time(), 'ok' => $res['ok'], 'message' => $res['ok'] ? '' : $res['message'] ) ) );
		return $res['ok'] ? array( 'ok' => true, 'message' => __( 'The report was sent.', 'wpeasy-hub-connector' ) ) : $res;
	}

	/** @return array{ok: bool, message: string, data?: array} */
	private static function post( $url, $payload, array $headers = array() ) {
		$body = is_string( $payload ) ? $payload : wp_json_encode( $payload );
		$res  = wp_remote_post( $url, array(
			'timeout'     => 20,
			'redirection' => 0, // a redirect could carry a signed request somewhere else
			'headers'     => array_merge( array( 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'User-Agent' => 'WPEasyHubConnector/' . VERSION ), $headers ),
			'body'        => $body,
		) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'message' => __( 'Could not reach the Hub. Check the address and that this website can make outgoing requests.', 'wpeasy-hub-connector' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'ok' => true, 'message' => '', 'data' => is_array( $data ) ? $data : array() );
		}
		if ( 403 === $code ) {
			return array( 'ok' => false, 'message' => __( 'The Hub did not accept that. Check the code is current and was made for this website.', 'wpeasy-hub-connector' ) );
		}
		if ( 429 === $code ) {
			return array( 'ok' => false, 'message' => __( 'The Hub says too many tries. Wait a minute and try again.', 'wpeasy-hub-connector' ) );
		}
		/* translators: %d: the HTTP status code */
		return array( 'ok' => false, 'message' => sprintf( __( 'The Hub answered with an error (%d).', 'wpeasy-hub-connector' ), $code ) );
	}
}
