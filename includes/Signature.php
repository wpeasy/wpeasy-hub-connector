<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Signs what this plugin sends the Hub (version 1). The Hub builds the same text and checks it:
 *
 *   v1 \n METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256(body)    HMAC-SHA256 with the secret agreed at pairing, hex
 *
 * The secret is never sent after pairing; the timestamp and a one-time nonce stop a copied request being played again.
 */
class Signature {
	public static function make( $secret, $method, $path, $timestamp, $nonce, $body ) {
		return hash_hmac( 'sha256', implode( "\n", array( 'v1', strtoupper( $method ), $path, $timestamp, $nonce, hash( 'sha256', $body ) ) ), $secret );
	}
}
