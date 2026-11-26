<?php
// Run: php tests/shield-page-test.php
// The page a shield shows: what it answers with for each kind, that it says it is the shield's, and that the settings keep only a sound page.
define( 'ABSPATH', __DIR__ );
function __( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s ) { return esc_html( $s ); }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_doing_cron() { return false; }
function is_user_logged_in() { return false; }
function nocache_headers() {}
function do_action() {}
define( 'WPEasy\\HubConnector\\OPTION', 'wpeasy_hub' );
$GLOBALS['options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
require __DIR__ . '/../includes/Store.php';
require __DIR__ . '/../includes/Shield.php';
use WPEasy\HubConnector\Shield;

$fail = function ( $m ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); };
$has = function ( array $a, $line ) { return in_array( $line, $a['headers'], true ); };

$default = Shield::answer( null, '', 'Acme' );
if ( 200 !== $default['status'] || ! $has( $default, 'X-WPEasy-Shield: default' ) || false === strpos( $default['body'], 'In development' ) ) { $fail( 'the built-in page changed' ); }

$soon = Shield::answer( array( 'mode' => 'coming_soon', 'html' => '<h1>{{site_name}} soon</h1>' ), '', 'A <b> & co' );
if ( 200 !== $soon['status'] || ! $has( $soon, 'X-WPEasy-Shield: coming_soon' ) || ! $has( $soon, 'X-Robots-Tag: noindex, nofollow, noarchive' ) ) { $fail( 'coming soon answers wrongly' ); }
if ( '<h1>A &lt;b&gt; &amp; co soon</h1>' !== $soon['body'] ) { $fail( 'the site name was not put in safely' ); }
if ( preg_grep( '/^Retry-After/', $soon['headers'] ) ) { $fail( 'coming soon should not ask to be retried' ); }

$busy = Shield::answer( array( 'mode' => 'maintenance', 'html' => '<p>Back soon</p>' ), '', 'Acme' );
if ( 503 !== $busy['status'] || ! $has( $busy, 'Retry-After: 3600' ) || ! $has( $busy, 'X-WPEasy-Shield: maintenance' ) ) { $fail( 'maintenance answers wrongly' ); }

$dev = Shield::answer( array( 'mode' => 'development', 'html' => '<h1>{{site_name}}</h1><p>{{message}}</p>' ), 'Ask {{site_name}} <b>', 'Acme' );
if ( 200 !== $dev['status'] || ! $has( $dev, 'X-WPEasy-Shield: development' ) || preg_grep( '/^Retry-After/', $dev['headers'] ) ) { $fail( 'under development answers wrongly' ); }
if ( '<h1>Acme</h1><p>Ask {{site_name}} &lt;b&gt;</p>' !== $dev['body'] ) { $fail( 'the message was not put in safely' ); }
if ( false === strpos( Shield::answer( array( 'mode' => 'development', 'html' => '{{message}}' ), '', 'Acme' )['body'], 'preview link' ) ) { $fail( 'with no message the usual sentence should show' ); }

Shield::handle( 'shield', array( 'op' => 'set', 'enabled' => true, 'links' => array(), 'template' => array( 'mode' => 'maintenance', 'html' => '<p>x</p>' ) ) );
if ( array( 'mode' => 'maintenance', 'html' => '<p>x</p>' ) !== Shield::page() || md5( "maintenance\n<p>x</p>" ) !== Shield::status()['page'] ) { $fail( 'the chosen page was not kept' ); }
foreach ( array( array( 'mode' => 'other', 'html' => '<p>x</p>' ), array( 'mode' => 'maintenance', 'html' => '' ), array( 'mode' => 'maintenance', 'html' => str_repeat( 'x', 400001 ) ), null ) as $bad ) {
	Shield::handle( 'shield', array( 'op' => 'set', 'enabled' => true, 'links' => array(), 'template' => $bad ) );
	if ( null !== Shield::page() || '' !== Shield::status()['page'] ) { $fail( 'a page that is not sound, or none, should clear the chosen one' ); }
}
Shield::handle( 'shield', array( 'op' => 'set', 'enabled' => true, 'links' => array(), 'template' => array( 'mode' => 'maintenance', 'html' => '<p>x</p>' ) ) );
$before = $GLOBALS['options'];
$asked = Shield::handle( 'shield', array( 'op' => 'status' ) );
if ( empty( $asked['ok'] ) || empty( $asked['shield']['enabled'] ) || md5( "maintenance\n<p>x</p>" ) !== $asked['shield']['page'] || $before !== $GLOBALS['options'] ) { $fail( 'asking the state should answer it and change nothing' ); }
// The after-update check's pass: only the pairing secret can make one, and only for a few minutes.
$now  = 1800000000;
$pass = Shield::health_pass( 'the-secret', $now );
if ( ! Shield::health_pass_ok( $pass, 'the-secret', $now + 200 ) ) { $fail( 'a fresh pass should be accepted' ); }
foreach ( array( array( $pass, 'another-secret', $now ), array( $pass, 'the-secret', $now + 301 ), array( $pass, 'the-secret', $now - 301 ), array( Shield::health_pass( '', $now ), '', $now ), array( substr( $pass, 0, -1 ) . ( '0' === substr( $pass, -1 ) ? '1' : '0' ), 'the-secret', $now ), array( ( $now + 100 ) . strstr( $pass, '.' ), 'the-secret', $now ), array( 'abc12345', 'the-secret', $now ), array( '', 'the-secret', $now ) ) as $i => $c ) {
	if ( Shield::health_pass_ok( $c[0], $c[1], $c[2] ) ) { $fail( "a bad pass was accepted ($i)" ); }
}
// With a good pass the shielded website lets the request through (a stopped request would end the script before the last line).
update_option( 'wpeasy_hub', array( 'secret' => 'the-secret' ) );
$_GET[ Shield::HEALTH ] = Shield::health_pass( 'the-secret', time() );
Shield::guard();
if ( ! defined( 'DONOTCACHEPAGE' ) ) { $fail( 'the page seen with a pass must not be cached' ); }
echo "OK shield-page-test\n";
