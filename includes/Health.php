<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** Is the website still working? Asks for its own home page and sign-in page the way a visitor would (a fresh request, so it loads whatever was just updated). */
class Health {
	/** @return array{ok: bool, message: string} */
	public static function check() {
		foreach ( array( home_url( '/' ), site_url( 'wp-login.php' ) ) as $url ) {
			$r = self::fetch( $url );
			if ( ! $r['ok'] ) {
				return $r;
			}
		}
		return array( 'ok' => true, 'message' => '' );
	}

	private static function fetch( $url ) {
		$res = wp_remote_get( add_query_arg( 'wpeasy_hub_health', wp_generate_password( 8, false ), $url ), array(
			'timeout'     => 30,
			'redirection' => 5,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		) );
		if ( is_wp_error( $res ) ) {
			/* translators: %s: the error */
			return array( 'ok' => false, 'message' => sprintf( __( 'The website did not answer: %s', 'wpeasy-hub-connector' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $code >= 500 ) {
			/* translators: %d: the HTTP status */
			return array( 'ok' => false, 'message' => sprintf( __( 'The website answered with an error (%d).', 'wpeasy-hub-connector' ), $code ) );
		}
		if ( false !== stripos( $body, 'There has been a critical error' ) || false !== stripos( $body, 'Fatal error:' ) ) {
			return array( 'ok' => false, 'message' => __( 'The website shows a critical error.', 'wpeasy-hub-connector' ) );
		}
		return array( 'ok' => true, 'message' => '' );
	}
}
