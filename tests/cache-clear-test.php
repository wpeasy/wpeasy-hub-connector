<?php
// Run: php tests/cache-clear-test.php
// Clearing caches asks only the caches that are there, and one that breaks does not stop the rest.
define( 'ABSPATH', __DIR__ );
$GLOBALS['done'] = array();
function wp_cache_flush() { $GLOBALS['done'][] = 'object'; return true; }
function rocket_clean_domain() { throw new RuntimeException( 'broken' ); }
function w3tc_flush_all() { $GLOBALS['done'][] = 'w3tc'; }
function do_action( $hook ) { $GLOBALS['done'][] = $hook; }
define( 'LSCWP_V', '1' );
require __DIR__ . '/../includes/CacheClear.php';

$r = WPEasy\HubConnector\CacheClear::run();
if ( true !== $r['ok'] || array( 'WordPress object cache', 'W3 Total Cache', 'LiteSpeed Cache' ) !== $r['cleared'] ) { fwrite( STDERR, 'FAIL: ' . json_encode( $r ) . "\n" ); exit( 1 ); }
if ( array( 'object', 'w3tc', 'litespeed_purge_all' ) !== $GLOBALS['done'] ) { fwrite( STDERR, "FAIL: asked the wrong caches\n" ); exit( 1 ); }
echo "cache clear: ok\n";
