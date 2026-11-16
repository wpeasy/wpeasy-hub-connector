<?php
// Run: php tests/login-token-test.php
// The Hub's token for (secret "test-secret", connection "c_abc", user 7, issued at 1700000000, nonce "nonce123") must be read by this plugin, and bad ones refused.
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/LoginToken.php';
use WPEasy\HubConnector\LoginToken;

$token = 'eyJ2IjoxLCJjIjoiY19hYmMiLCJ1Ijo3LCJlIjoxNzAwMDAwMDYwLCJuIjoibm9uY2UxMjMifQ.67b7ad5942a1d48435360048457c8879af72b6c6d89c8ac47e9b80b846087381';
$fail  = function ( $m ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); };

$p = LoginToken::read( $token, 'test-secret', 'c_abc', 1700000010 );
if ( ! $p || 7 !== $p['u'] ) { $fail( 'a genuine token was not read' ); }
if ( null !== LoginToken::read( $token, 'wrong-secret', 'c_abc', 1700000010 ) ) { $fail( 'a token signed with another secret was accepted' ); }
if ( null !== LoginToken::read( $token, 'test-secret', 'c_other', 1700000010 ) ) { $fail( 'a token for another connection was accepted' ); }
if ( null !== LoginToken::read( $token, 'test-secret', 'c_abc', 1700000100 ) ) { $fail( 'an expired token was accepted' ); }
if ( null !== LoginToken::read( $token, 'test-secret', 'c_abc', 1699999000 ) ) { $fail( 'a token valid far in the future was accepted' ); }
$parts = explode( '.', $token );
if ( null !== LoginToken::read( $parts[0] . '.' . str_repeat( 'a', 64 ), 'test-secret', 'c_abc', 1700000010 ) ) { $fail( 'a forged signature was accepted' ); }
echo "ok: login token matches the Hub\n";
