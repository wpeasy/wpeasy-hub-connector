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
		if ( ! self::claim_nonce( $p['n'] ) ) {
			return null; // a login works once, even if two requests arrive at the same instant
		}

		$user = get_userdata( (int) $p['u'] );
		if ( ! $user || ! in_array( 'administrator', (array) $user->roles, true ) || ! user_can( $user, 'manage_options' ) ) {
			return null;
		}
		return $user;
	}

	/**
	 * Marks a token as used, atomically: a single INSERT IGNORE on the options table (option_name is unique), so of two requests carrying the same token at the
	 * same moment exactly one gets true. (A get-then-set check would let both through.) Old marks are removed as they expire.
	 */
	public static function claim_nonce( $nonce ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( 'wpeasy_hub_used_' ) . '%', time() - 600 ) );
		$added = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'wpeasy_hub_used_' . md5( $nonce ), (string) time() ) );
		return 1 === (int) $added;
	}

	private static function refuse( $message ) {
		wp_die( esc_html( $message ), esc_html__( 'Hub Connector', 'wpeasy-hub-connector' ), array( 'response' => 403 ) );
	}
}
