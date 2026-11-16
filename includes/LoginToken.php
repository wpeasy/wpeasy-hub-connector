<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * The one-click login token the Hub makes (version 1). It is base64url( JSON ) + "." + HMAC-SHA256 hex, signed with the secret agreed at pairing:
 *
 *   payload = {"v":1,"c":<connection id>,"u":<WordPress user id>,"e":<expires, seconds>,"n":<nonce>}
 *   sig     = HMAC-SHA256( secret, "login-v1\n" . base64url(payload) )
 *
 * It is only ever sent in a POST body (never in an address), lasts about a minute and works once.
 */
class LoginToken {
	const MAX_LIFETIME = 120;

	public static function b64( $s ) {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	public static function sign( $secret, $b64payload ) {
		return hash_hmac( 'sha256', "login-v1\n" . $b64payload, $secret );
	}

	/** @return array|null the payload when the token is genuine, current and addressed to this connection; null otherwise */
	public static function read( $token, $secret, $connection, $now ) {
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) || strlen( $token ) > 2000 ) {
			return null;
		}
		if ( ! hash_equals( self::sign( $secret, $parts[0] ), strtolower( $parts[1] ) ) ) {
			return null;
		}
		$p = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
		if ( ! is_array( $p ) || 1 !== ( $p['v'] ?? 0 ) || ( $p['c'] ?? '' ) !== $connection || empty( $p['u'] ) || empty( $p['n'] ) || ! is_string( $p['n'] ) ) {
			return null;
		}
		$exp = (int) ( $p['e'] ?? 0 );
		if ( $exp < $now || $exp - $now > self::MAX_LIFETIME ) {
			return null;
		}
		return $p;
	}
}
