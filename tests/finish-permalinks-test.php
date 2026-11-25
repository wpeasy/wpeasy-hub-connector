<?php
// Run: php tests/finish-permalinks-test.php
// When the Hub asks, finishing a restore or a move also makes WordPress rebuild its pretty-address rules and write .htaccess; otherwise it does not; and a problem there is never an error.
namespace WPEasy\HubConnector { const OPTION = 'wpeasy_hub_connector'; }
namespace {
$tmp = realpath( sys_get_temp_dir() ) . '/hubperma-' . getmypid();
@mkdir( $tmp . '/wp-content', 0777, true );
@mkdir( $tmp . '/wp-admin/includes', 0777, true );
define( 'ABSPATH', $tmp . '/' );
define( 'WP_CONTENT_DIR', $tmp . '/wp-content' );
define( 'AUTH_KEY', 'k' );
define( 'SECURE_AUTH_SALT', 's' );
file_put_contents( $tmp . '/wp-admin/includes/misc.php', '<?php $GLOBALS["loaded"][] = "misc";' );
file_put_contents( $tmp . '/wp-admin/includes/file.php', '<?php $GLOBALS["loaded"][] = "file"; function get_home_path() { return ABSPATH; }' );
$GLOBALS['opts'] = array( 'wpeasy_hub_connector' => array( 'connection' => 'c', 'secret' => 'sec', 'hub_url' => 'https://hub.test' ) );
$GLOBALS['flush'] = 0;
$GLOBALS['loaded'] = array();
function get_option( $k, $d = false ) { return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_cache_flush() {}
function flush_rewrite_rules( $hard = true ) { ++$GLOBALS['flush']; if ( ! empty( $GLOBALS['boom'] ) ) { throw new \RuntimeException( 'no' ); } if ( $hard ) { file_put_contents( ABSPATH . '.htaccess', "# BEGIN WordPress\n" ); } }
require __DIR__ . '/../includes/Store.php';
require __DIR__ . '/../includes/RestoreState.php';
use WPEasy\HubConnector\RestoreState;
$fail = function ( $m ) use ( $tmp ) { fwrite( STDERR, "FAIL: $m\n" ); shell_exec( 'rm -rf ' . escapeshellarg( $tmp ) ); exit( 1 ); };

// not asked: nothing is flushed and the answer is as before
RestoreState::begin();
$r = RestoreState::finish();
if ( ! $r['ok'] || isset( $r['permalinks'] ) || 0 !== $GLOBALS['flush'] ) { $fail( 'finish without permalinks must not flush: ' . json_encode( $r ) ); }

// asked: flushed, the pieces WordPress writes .htaccess with are loaded, and it says whether the file is there
RestoreState::begin();
$r = RestoreState::finish( true );
if ( ! $r['ok'] || empty( $r['permalinks']['flushed'] ) || empty( $r['permalinks']['htaccess'] ) || 1 !== $GLOBALS['flush'] ) { $fail( 'finish with permalinks: ' . json_encode( $r ) ); }
if ( array( 'misc', 'file' ) !== $GLOBALS['loaded'] ) { $fail( 'the admin-side files were not loaded: ' . json_encode( $GLOBALS['loaded'] ) ); }
if ( ! is_file( $tmp . '/.htaccess' ) ) { $fail( '.htaccess was not written' ); }

// a problem while flushing is never an error for the move
$GLOBALS['boom'] = true;
RestoreState::begin();
$r = RestoreState::finish( true );
if ( ! $r['ok'] || ! empty( $r['permalinks']['flushed'] ) ) { $fail( 'a failing flush must be reported quietly: ' . json_encode( $r ) ); }

// the pairing is still put back either way
if ( 'sec' !== $GLOBALS['opts']['wpeasy_hub_connector']['secret'] ) { $fail( 'pairing lost' ); }

shell_exec( 'rm -rf ' . escapeshellarg( $tmp ) );
echo "ok\n";
}
