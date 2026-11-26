<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Shield mode: while the Hub has it switched on, a visitor with no preview link sees a page instead of the website: the one chosen in the Hub ("under development" or "coming soon", ordinary pages,
 * HTTP 200; or "under maintenance", HTTP 503 with Retry-After), else a plain "in development" page (HTTP 200). All are kept out of search engines and carry an X-WPEasy-Shield header, so the Hub knows
 * the page is the shield's and not the website. The Hub sends the settings: on or off, the message, the chosen page, and a list of preview links as fingerprints (never the links themselves). A visitor who opens a preview link gets a cookie that
 * lasts until the link expires or is revoked. People signed in to WordPress, the sign-in page, cron, the Hub's own signed requests and the one-click login are never stopped, and neither is the
 * plugin's own check after an update (a short-lived signed pass), which has to see the real website.
 */
class Shield {
	const OPTION  = 'wpeasy_hub_shield';
	const COOKIE  = 'wpeasy_preview';
	const PARAM   = 'wpeasy_preview';
	/** The chosen page: kept apart from the settings, and read only when a visitor is stopped (it can be large). */
	const PAGE     = 'wpeasy_hub_shield_page';
	const MAX_PAGE = 400000; // bytes
	/** The kinds of page the Hub offers => what a visitor's browser is answered with. */
	const MODES = array( 'development' => 200, 'coming_soon' => 200, 'maintenance' => 503 );
	const ACTIONS = array( 'shield' );
	/** The address argument carrying the after-update check's pass, and how long (seconds) a pass is good for. */
	const HEALTH       = 'wpeasy_hub_health';
	const HEALTH_LASTS = 300;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'guard' ), 2 ); // after the Hub's own signed requests (priority 1) have been answered
	}

	public static function config() {
		$c = get_option( self::OPTION, array() );
		return is_array( $c ) ? $c : array();
	}

	public static function enabled() {
		$c = self::config();
		return ! empty( $c['enabled'] );
	}

	/** What the report tells the Hub, so it can notice if the website's settings drifted from what it set. */
	public static function status() {
		$c = self::config();
		$page = self::page();
		return array( 'enabled' => ! empty( $c['enabled'] ), 'links' => isset( $c['links'] ) ? count( (array) $c['links'] ) : 0, 'page' => $page ? md5( $page['mode'] . "\n" . $page['html'] ) : '' );
	}

	/** The chosen page, or null when none is chosen. @return array{mode: string, html: string}|null */
	public static function page() {
		$p = get_option( self::PAGE, null );
		return is_array( $p ) && self::page_ok( $p ) ? array( 'mode' => $p['mode'], 'html' => $p['html'] ) : null;
	}

	private static function page_ok( $p ) {
		return isset( $p['mode'], $p['html'] ) && is_string( $p['mode'] ) && isset( self::MODES[ $p['mode'] ] ) && is_string( $p['html'] ) && '' !== $p['html'] && strlen( $p['html'] ) <= self::MAX_PAGE;
	}

	/**
	 * The Hub sets everything at once: {enabled, message, template: {mode, html} or null, links: [{id, hash, expires}]}. It may also just ask what the website holds ("status", changes nothing):
	 * some hosts replace a 503 with their own error page, and this is how the Hub learns that the error is the maintenance shield.
	 */
	public static function handle( $action, $data ) {
		$op = isset( $data['op'] ) ? $data['op'] : '';
		if ( 'status' === $op ) {
			return array( 'ok' => true, 'shield' => self::status() );
		}
		if ( 'set' !== $op ) {
			return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown request.' );
		}
		$links = array();
		foreach ( array_slice( is_array( $data['links'] ?? null ) ? $data['links'] : array(), 0, 100 ) as $l ) {
			if ( is_array( $l ) && isset( $l['id'], $l['hash'] ) && preg_match( '/^[a-z0-9]{6,24}$/', (string) $l['id'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $l['hash'] ) ) {
				$links[] = array( 'id' => (string) $l['id'], 'hash' => (string) $l['hash'], 'expires' => max( 0, (int) ( $l['expires'] ?? 0 ) ) );
			}
		}
		$msg = isset( $data['message'] ) ? trim( wp_strip_all_tags( (string) $data['message'] ) ) : '';
		update_option( self::OPTION, array( 'enabled' => ! empty( $data['enabled'] ), 'message' => substr( $msg, 0, 600 ), 'links' => $links, 'set' => time() ), true ); // autoloaded: it is read on every request
		if ( isset( $data['template'] ) && is_array( $data['template'] ) && self::page_ok( $data['template'] ) ) {
			update_option( self::PAGE, array( 'mode' => $data['template']['mode'], 'html' => $data['template']['html'] ), false ); // written in the Hub by an administrator, arrived in a signed request
		} else {
			delete_option( self::PAGE );
		}
		return array( 'ok' => true, 'shield' => self::status() );
	}

	/** Whether this request must be let through without a preview link. */
	private static function exempt() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || is_user_logged_in() ) {
			return true;
		}
		global $pagenow;
		if ( 'wp-login.php' === $pagenow ) {
			return true;
		}
		// The Hub's one-click login checks its own signed token.
		return isset( $_GET['wpeasy_hub_login'] ) || isset( $_GET['wpeasy_hub_action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private static function sign( $id, $exp ) {
		return hash_hmac( 'sha256', $id . '.' . $exp, (string) Store::get( 'secret' ) );
	}

	private static function link_by_id( $id ) {
		$c = self::config();
		foreach ( (array) ( $c['links'] ?? array() ) as $l ) {
			if ( $l['id'] === $id && ( 0 === (int) $l['expires'] || (int) $l['expires'] > time() ) ) {
				return $l;
			}
		}
		return null;
	}

	/** A pass for the plugin's own check of the website: signed with the pairing secret, good for a few minutes. */
	public static function health_pass( $secret, $now ) {
		return (int) $now . '.' . hash_hmac( 'sha256', 'health-v1.' . (int) $now, (string) $secret );
	}

	public static function health_pass_ok( $value, $secret, $now ) {
		if ( '' === (string) $secret || ! preg_match( '/^([1-9]\d{0,11})\.[a-f0-9]{64}$/', (string) $value, $m ) ) {
			return false;
		}
		return abs( (int) $now - (int) $m[1] ) <= self::HEALTH_LASTS && hash_equals( self::health_pass( $secret, $m[1] ), (string) $value );
	}

	/** A cookie made when a preview link was opened: it names the link, so revoking or expiring the link ends it. */
	private static function cookie_ok() {
		$v = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked below
		if ( ! preg_match( '/^([a-z0-9]{6,24})\.(\d{1,12})\.([a-f0-9]{64})$/', $v, $m ) ) {
			return false;
		}
		if ( (int) $m[2] < time() || ! hash_equals( self::sign( $m[1], $m[2] ), $m[3] ) ) {
			return false;
		}
		return null !== self::link_by_id( $m[1] );
	}

	public static function guard() {
		if ( ! self::enabled() || self::exempt() ) {
			return;
		}
		// A preview link: ?wpeasy_preview=TOKEN
		$given = isset( $_GET[ self::PARAM ] ) ? (string) wp_unslash( $_GET[ self::PARAM ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as a hash
		if ( '' !== $given && strlen( $given ) <= 100 ) {
			$hash = hash( 'sha256', $given );
			foreach ( (array) ( self::config()['links'] ?? array() ) as $l ) {
				if ( hash_equals( $l['hash'], $hash ) && ( 0 === (int) $l['expires'] || (int) $l['expires'] > time() ) ) {
					$exp = 0 === (int) $l['expires'] ? time() + 30 * DAY_IN_SECONDS : min( (int) $l['expires'], time() + 30 * DAY_IN_SECONDS );
					setcookie( self::COOKIE, $l['id'] . '.' . $exp . '.' . self::sign( $l['id'], $exp ), array( 'expires' => $exp, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
					nocache_headers();
					wp_safe_redirect( remove_query_arg( self::PARAM ) );
					exit;
				}
			}
		}
		if ( self::cookie_ok() ) {
			return;
		}
		// The after-update check sees the real website. That answer must never be stored by a cache, or visitors would be handed the website behind the shield.
		$pass = isset( $_GET[ self::HEALTH ] ) && is_string( $_GET[ self::HEALTH ] ) ? $_GET[ self::HEALTH ] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- verified as a signature
		if ( '' !== $pass && self::health_pass_ok( $pass, Store::get( 'secret' ), time() ) ) {
			self::never_cache();
			return;
		}
		self::block();
	}

	/**
	 * What a stopped visitor is answered with.
	 *
	 * @param array{mode: string, html: string}|null $page The page chosen in the Hub, or null for the built-in one.
	 * @return array{status: int, headers: array<int, string>, body: string}
	 */
	public static function answer( $page, $message, $name ) {
		$headers = array( 'X-Robots-Tag: noindex, nofollow, noarchive', 'Content-Type: text/html; charset=utf-8', 'X-WPEasy-Shield: ' . ( $page ? $page['mode'] : 'default' ) );
		$msg = '' !== (string) $message ? $message : __( 'This website is in development. You need a preview link from the developer to view it.', 'wpeasy-hub-connector' );
		if ( $page ) {
			if ( 'maintenance' === $page['mode'] ) {
				$headers[] = 'Retry-After: 3600';
			}
			// One pass, so a name or message that itself looks like a placeholder is left as typed.
			return array( 'status' => self::MODES[ $page['mode'] ], 'headers' => $headers, 'body' => strtr( $page['html'], array( '{{site_name}}' => esc_html( $name ), '{{message}}' => esc_html( $msg ) ) ) );
		}
		$name = esc_html( $name );
		return array(
			'status'  => 200,
			'headers' => $headers,
			'body'    => '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>' . esc_html__( 'In development', 'wpeasy-hub-connector' ) . ( $name ? ' · ' . $name : '' ) . '</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#141414;color:#f4f4f4;font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif}main{max-width:32rem;padding:2rem}h1{font-size:1.4rem;margin:0 0 .75rem;color:#ffd64f}p{margin:0;color:#d6d6d6}</style></head><body><main><h1>' . esc_html__( 'In development', 'wpeasy-hub-connector' ) . '</h1><p>' . esc_html( $msg ) . '</p></main></body></html>',
		);
	}

	private static function never_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the name page caches look for
		}
		do_action( 'litespeed_control_set_nocache', 'WP Easy Hub shield' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook
		nocache_headers();
	}

	private static function block() {
		self::never_cache();
		$c      = self::config();
		$answer = self::answer( self::page(), isset( $c['message'] ) ? $c['message'] : '', get_bloginfo( 'name' ) );
		status_header( $answer['status'] );
		foreach ( $answer['headers'] as $h ) {
			header( $h );
		}
		echo $answer['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the built-in page escapes every dynamic part; a chosen page is the agency's own HTML from a signed request, with the name escaped
		exit;
	}
}
