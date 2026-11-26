<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** Is the website still working? Asks for its own home page and sign-in page the way a visitor would (a fresh request, so it loads whatever was just updated). */
class Health {
	/** @return array{ok: bool, message: string} */
	public static function check() {
		foreach ( array( home_url( '/' ), site_url( 'wp-login.php' ) ) as $url ) {
			$r = self::fetch_patiently( $url );
			if ( ! $r['ok'] ) {
				return $r;
			}
		}
		return array( 'ok' => true, 'message' => '' );
	}

	/**
	 * An update clears caches, so the first page the check asks for is built from scratch (the check skips the page cache on purpose) and a heavy page can run out of
	 * memory once, then work. Ask again a couple of times, a few seconds apart, before calling it broken: a website that is really broken fails every time.
	 */
	private static function fetch_patiently( $url ) {
		$r = self::fetch( $url );
		for ( $try = 0; $try < 2 && ! $r['ok']; $try++ ) {
			sleep( 4 );
			$r = self::fetch( $url );
		}
		return $r;
	}

	private static function fetch( $url ) {
		// A fresh value each time, so no cache can answer. On a paired website it is also the pass that lets this request through a shield: the real pages are what must be checked.
		$secret = (string) Store::get( 'secret' );
		$value  = '' !== $secret ? Shield::health_pass( $secret, time() ) : wp_generate_password( 8, false );
		$res    = wp_remote_get( add_query_arg( Shield::HEALTH, $value, $url ), array(
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
