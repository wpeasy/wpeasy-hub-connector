<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * One-click login from the Hub. The Hub posts a signed token (see LoginToken) to /?wpeasy_hub_login=1; if it is genuine, current, unused and names a
 * user who is still an administrator, that user is signed in and sent to the dashboard. Can be switched off in Settings > Hub Connector.
 */
class Login {
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_login' ), 1 );
	}

	public static function maybe_login() {
		if ( ! isset( $_GET['wpeasy_hub_login'] ) || 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );

		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$tries = 'wpeasy_hub_login_fail_' . md5( $ip );
		if ( (int) get_transient( $tries ) >= 10 ) {
			self::refuse( __( 'Too many tries. Wait a few minutes.', 'wpeasy-hub-connector' ) );
		}

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$user  = self::check( $token );
		if ( ! $user ) {
			set_transient( $tries, (int) get_transient( $tries ) + 1, 10 * MINUTE_IN_SECONDS );
			self::refuse( __( 'This login did not work. It may have expired or already been used, or one-click login is switched off for this website.', 'wpeasy-hub-connector' ) );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		do_action( 'wpeasy_hub_connector_login', $user );
		wp_safe_redirect( admin_url() );
		exit;
	}

	/** @return \WP_User|null */
	private static function check( $token ) {
		if ( '' === $token || ! Store::connected() || ! Store::get( 'allow_login', true ) ) {
			return null;
		}
		$p = LoginToken::read( $token, Store::get( 'secret' ), Store::get( 'connection' ), time() );
		if ( ! $p ) {
			return null;
		}
		$key = 'wpeasy_hub_nonce_' . md5( $p['n'] );
		if ( get_transient( $key ) ) {
			return null; // a login works once
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );

		$user = get_userdata( (int) $p['u'] );
		if ( ! $user || ! in_array( 'administrator', (array) $user->roles, true ) || ! user_can( $user, 'manage_options' ) ) {
			return null;
		}
		return $user;
	}

	private static function refuse( $message ) {
		wp_die( esc_html( $message ), esc_html__( 'Hub Connector', 'wpeasy-hub-connector' ), array( 'response' => 403 ) );
	}
}
