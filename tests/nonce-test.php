<?php
// Run: php tests/nonce-test.php
// A one-click login token must work once. This checks Login::claim_nonce against a stand-in for the WordPress database that, like the real options table, refuses a second INSERT of the same option_name.
define( 'ABSPATH', __DIR__ );
define( 'WPINC', 'wp-includes' );
require __DIR__ . '/../includes/Login.php';

class FakeWpdb {
	public $options = 'wp_options';
	public $rows = array();
	public function esc_like( $s ) { return $s; }
	public function prepare( $q, ...$a ) { return array( $q, $a ); }
	public function query( $prepared ) {
		list( $q, $a ) = $prepared;
		if ( 0 === strpos( $q, 'INSERT IGNORE' ) ) {
			if ( isset( $this->rows[ $a[0] ] ) ) { return 0; }
			$this->rows[ $a[0] ] = (int) $a[1];
			return 1;
		}
		foreach ( $this->rows as $k => $v ) { if ( $v < $a[1] ) { unset( $this->rows[ $k ] ); } } // the expiry clean-up
		return 0;
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

$fail = function ( $m ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); };
if ( ! WPEasy\HubConnector\Login::claim_nonce( 'abc' ) ) { $fail( 'the first use of a token was refused' ); }
if ( WPEasy\HubConnector\Login::claim_nonce( 'abc' ) ) { $fail( 'a token was accepted twice' ); }
if ( ! WPEasy\HubConnector\Login::claim_nonce( 'def' ) ) { $fail( 'a different token was refused' ); }
$GLOBALS['wpdb']->rows['wpeasy_hub_used_' . md5( 'old' )] = time() - 9999;
WPEasy\HubConnector\Login::claim_nonce( 'ghi' );
if ( isset( $GLOBALS['wpdb']->rows['wpeasy_hub_used_' . md5( 'old' )] ) ) { $fail( 'an expired mark was not cleaned up' ); }
echo "ok: a login token works once\n";
