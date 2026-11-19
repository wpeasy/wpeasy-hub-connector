<?php
// Run: php tests/restore-state-test.php
// While a restore is active the plugin answers the Hub with the pairing it kept aside (a restored options table may bring back an old one), the notice to visitors is shown, an abandoned restore cleans itself up, and the pairing is put back at the end.
namespace WPEasy\HubConnector { const OPTION = 'wpeasy_hub_connector'; }
namespace {
$tmp = sys_get_temp_dir() . '/hubstate-' . getmypid();
@mkdir( $tmp . '/wp-content', 0777, true );
define( 'ABSPATH', $tmp . '/' );
define( 'WP_CONTENT_DIR', $tmp . '/wp-content' );
define( 'AUTH_KEY', 'test-auth-key' );
define( 'SECURE_AUTH_SALT', 'test-salt' );
$GLOBALS['opts'] = array( 'wpeasy_hub_connector' => array( 'connection' => 'c_NOW', 'secret' => 'secret-NOW', 'hub_url' => 'https://hub.test' ) );
function get_option( $k, $d = false ) { return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_cache_flush() { $GLOBALS['flushed'] = true; }
require __DIR__ . '/../includes/Store.php';
require __DIR__ . '/../includes/RestoreState.php';
use WPEasy\HubConnector\RestoreState;
use WPEasy\HubConnector\Store;
$fail = function ( $m ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); };

$name = basename( RestoreState::file() );
if ( 0 !== strpos( $name, '.wpeasy-hub-restore-' ) || strlen( $name ) < 40 || RestoreState::file() === $tmp . '/wp-content/.wpeasy-hub-restore-' ) { $fail( 'state file name: ' . $name ); }
if ( RestoreState::active() ) { $fail( 'active before anything started' ); }
if ( 'c_NOW' !== RestoreState::pairing()['connection'] ) { $fail( 'pairing before a restore should be the saved one' ); }

$r = RestoreState::begin();
if ( ! $r['ok'] || ! RestoreState::active() ) { $fail( 'begin did not start a restore' ); }
if ( ( fileperms( RestoreState::file() ) & 0777 ) !== 0600 ) { $fail( 'the state file must be private (0600)' ); }

// the restored options table brings back an OLD pairing: requests are still checked against the kept one
$GLOBALS['opts']['wpeasy_hub_connector'] = array( 'connection' => 'c_OLD', 'secret' => 'secret-OLD' );
$p = RestoreState::pairing();
if ( 'c_NOW' !== $p['connection'] || 'secret-NOW' !== $p['secret'] ) { $fail( 'during a restore the kept pairing must be used: ' . json_encode( $p ) ); }

// at the end the pairing is put back as it is today
$f = RestoreState::finish();
if ( ! $f['ok'] || empty( $f['settings_kept'] ) || 'secret-NOW' !== $GLOBALS['opts']['wpeasy_hub_connector']['secret'] || is_file( RestoreState::file() ) || empty( $GLOBALS['flushed'] ) ) { $fail( 'finish did not put the pairing back and clean up' ); }
if ( RestoreState::active() ) { $fail( 'still active after finish' ); }

// an abandoned restore (no request for ten minutes) is cleaned up by the next request, pairing restored
RestoreState::begin();
$GLOBALS['opts']['wpeasy_hub_connector'] = array( 'connection' => 'c_OLD', 'secret' => 'secret-OLD' );
touch( RestoreState::file(), time() - 700 );
if ( RestoreState::active() ) { $fail( 'a restore with no request for ten minutes must not count as active' ); }
RestoreState::guard();
if ( 'secret-NOW' !== $GLOBALS['opts']['wpeasy_hub_connector']['secret'] || is_file( RestoreState::file() ) ) { $fail( 'an abandoned restore was not cleaned up' ); }

// a request keeps it going
RestoreState::begin();
touch( RestoreState::file(), time() - 500 );
RestoreState::renew();
if ( ( time() - filemtime( RestoreState::file() ) ) > 5 ) { $fail( 'renew did not refresh the restore' ); }
RestoreState::finish();
exec( 'rm -rf ' . escapeshellarg( $tmp ) );
echo "ok: the pairing is kept during a restore, put back at the end, and an abandoned restore cleans itself up\n";
}
