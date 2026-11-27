<?php
// Run: php tests/auto-updates-test.php
// Holding WordPress's own automatic updates: only while connected and recently confirmed by the Hub; the report's answer starts and ends it.
namespace {
define( 'ABSPATH', __DIR__ );
function __( $s ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
define( 'WPEasy\\HubConnector\\OPTION', 'wpeasy_hub' );
define( 'WPEasy\\HubConnector\\VERSION', 'test' );
define( 'WPEasy\\HubConnector\\CRON', 'cron' );
$GLOBALS['options'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['reply']   = array( 200, '{"ok":true}' );
$GLOBALS['sent']    = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['options'][ $k ] = $v; return true; }
function add_filter( $name, $fn, $priority = 10 ) { $GLOBALS['filters'][ $name ] = array( $fn, $priority ); }
function add_action() {}
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_password() { return bin2hex( random_bytes( 12 ) ); }
function wp_remote_post( $url, $args ) { $GLOBALS['sent'][] = $args['body']; return $GLOBALS['reply']; }
function is_wp_error() { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r[0]; }
function wp_remote_retrieve_body( $r ) { return $r[1]; }
}
namespace WPEasy\HubConnector {
// The report itself is another file's business: here it only says whether the hold is on.
class Report { public static function collect() { return array( 'auto_updates_held' => AutoUpdates::held() ); } }
}
namespace {
require __DIR__ . '/../includes/Signature.php';
require __DIR__ . '/../includes/Store.php';
require __DIR__ . '/../includes/AutoUpdates.php';
require __DIR__ . '/../includes/Client.php';
use WPEasy\HubConnector\AutoUpdates;
use WPEasy\HubConnector\Client;
use WPEasy\HubConnector\Store;

$fail = function ( $m ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); };

AutoUpdates::init();
foreach ( AutoUpdates::FILTERS as $f ) {
	if ( ! isset( $GLOBALS['filters'][ $f ] ) || PHP_INT_MAX !== $GLOBALS['filters'][ $f ][1] ) { $fail( "$f is not filtered last" ); }
}
if ( true !== AutoUpdates::decide( true ) || null !== AutoUpdates::decide( null ) ) { $fail( 'with no hold, WordPress decides' ); }

Store::set( array( 'hub_url' => 'https://hub.example.com', 'connection' => 'c1', 'secret' => 's' ) );

// An older Hub says nothing: nothing changes.
Client::send_report();
if ( AutoUpdates::held() || 1 !== count( $GLOBALS['sent'] ) ) { $fail( 'a reply without the word should change nothing' ); }

// The Hub says hold: it starts, and one more report says so.
$GLOBALS['sent']  = array();
$GLOBALS['reply'] = array( 200, '{"ok":true,"hold_auto_updates":true}' );
Client::send_report();
if ( ! AutoUpdates::held() || false !== AutoUpdates::decide( true ) || false !== AutoUpdates::decide( null ) ) { $fail( 'the hold did not start' ); }
if ( 2 !== count( $GLOBALS['sent'] ) || false === strpos( $GLOBALS['sent'][1], '"auto_updates_held":true' ) ) { $fail( 'the Hub was not told once how things stand' ); }
$GLOBALS['sent'] = array();
Client::send_report();
if ( 1 !== count( $GLOBALS['sent'] ) ) { $fail( 'an unchanged hold should send one report' ); }

// Stale after three days without a word.
Store::set( array( AutoUpdates::KEY => array( 'on' => true, 'at' => time() - AutoUpdates::FRESH - 1 ) ) );
if ( AutoUpdates::held() || true !== AutoUpdates::decide( true ) ) { $fail( 'an old word should not hold anything' ); }

// The Hub says stop.
AutoUpdates::apply( true );
$GLOBALS['reply'] = array( 200, '{"ok":true,"hold_auto_updates":false}' );
Client::send_report();
if ( AutoUpdates::held() ) { $fail( 'the Hub could not lift it' ); }

// The Hub refuses the website (disconnected there): lifted. Something in front of the Hub refusing it, or an error, leaves it as it is.
AutoUpdates::apply( true );
$GLOBALS['reply'] = array( 403, '<html>blocked</html>' );
Client::send_report();
if ( ! AutoUpdates::held() ) { $fail( 'a firewall page is not the Hub letting go' ); }
$GLOBALS['reply'] = array( 500, '' );
Client::send_report();
if ( ! AutoUpdates::held() ) { $fail( 'an error should leave the hold' ); }
$GLOBALS['reply'] = array( 403, '{"message":"That did not work."}' );
Client::send_report();
if ( AutoUpdates::held() ) { $fail( 'a refusal by the Hub should lift it' ); }

// Disconnecting here forgets it with the pairing.
AutoUpdates::apply( true );
function wp_clear_scheduled_hook() {}
Store::forget();
if ( AutoUpdates::held() ) { $fail( 'a disconnected website holds nothing' ); }

echo "auto updates: ok\n";
}
