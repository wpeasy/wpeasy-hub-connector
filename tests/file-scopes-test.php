<?php
// Run: php tests/file-scopes-test.php
// The places a file search and replace may look: every theme, must-use plugins, and other folders inside wp-content (but never caches, backups or the places already chosen), with no folder looked at twice.
namespace {
$tmp = realpath( sys_get_temp_dir() ) . '/hubscopes-' . getmypid();
foreach ( array( 'themes/active', 'themes/other', 'plugins/p', 'mu-plugins', 'uploads', 'cache', 'languages', 'my-data/deep', 'builder-css', 'updraft', 'wflogs', 'wpeasy-hub-backups', '.hidden', 'node_modules' ) as $d ) { mkdir( $tmp . '/wp-content/' . $d, 0777, true ); }
symlink( $tmp . '/wp-content/themes', $tmp . '/wp-content/linked' );
file_put_contents( $tmp . '/wp-content/loose.txt', 'x' );
define( 'ABSPATH', $tmp . '/' );
define( 'WP_CONTENT_DIR', $tmp . '/wp-content' );
define( 'WP_PLUGIN_DIR', $tmp . '/wp-content/plugins' );
define( 'WPMU_PLUGIN_DIR', $tmp . '/wp-content/mu-plugins' );
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
function wp_upload_dir() { return array( 'basedir' => WP_CONTENT_DIR . '/uploads' ); }
function get_stylesheet_directory() { return WP_CONTENT_DIR . '/themes/active'; }
function get_template_directory() { return WP_CONTENT_DIR . '/themes/active'; }
function get_theme_root() { return WP_CONTENT_DIR . '/themes'; }
function __( $s ) { return $s; }
require __DIR__ . '/../includes/FileReplace.php';
$roots = new ReflectionMethod( 'WPEasy\HubConnector\FileReplace', 'roots' );
$roots->setAccessible( true );
$fail = function ( $m ) use ( $tmp ) { fwrite( STDERR, "FAIL: $m\n" ); shell_exec( 'rm -rf ' . escapeshellarg( $tmp ) ); exit( 1 ); };
$names = function ( array $scopes ) use ( $roots, $tmp ) {
	$out = array();
	foreach ( $roots->invoke( null, $scopes ) as $r ) { $out[] = substr( $r[1], strlen( realpath( $tmp ) . '/wp-content/' ) ) . ' (' . $r[0] . ')'; }
	sort( $out );
	return $out;
};

// the old places still work as before
if ( $names( array( 'uploads', 'theme', 'plugins' ) ) !== array( 'plugins (plugins)', 'themes/active (theme)', 'uploads (uploads)' ) ) { $fail( 'old places: ' . json_encode( $names( array( 'uploads', 'theme', 'plugins' ) ) ) ); }

// every theme, must-use plugins
$got = $names( array( 'themes', 'mu-plugins' ) );
if ( $got !== array( 'mu-plugins (mu-plugins)', 'themes (themes)' ) ) { $fail( 'themes + mu-plugins: ' . json_encode( $got ) ); }

// the active theme is inside "all themes": looked at once, not twice (whichever order they are named in)
foreach ( array( array( 'theme', 'themes' ), array( 'themes', 'theme' ) ) as $order ) {
	$got = $names( $order );
	if ( $got !== array( 'themes (themes)' ) ) { $fail( 'overlap ' . json_encode( $order ) . ': ' . json_encode( $got ) ); }
}

// other wp-content folders: a site's own, a page builder's; never caches, backups, logs, hidden folders, links, the places above, the plugin's own folders, loose files
$got = $names( array( 'content' ) );
if ( $got !== array( 'builder-css (content)', 'my-data (content)' ) ) { $fail( 'content: ' . json_encode( $got ) ); }

// everything at once: each folder once
$got = $names( array( 'uploads', 'theme', 'themes', 'plugins', 'mu-plugins', 'content' ) );
$want = array( 'builder-css (content)', 'mu-plugins (mu-plugins)', 'my-data (content)', 'plugins (plugins)', 'themes (themes)', 'uploads (uploads)' );
if ( $got !== $want ) { $fail( 'all: ' . json_encode( $got ) ); }

// an unknown place gives nothing
if ( array() !== $roots->invoke( null, array( 'wp-admin', '../x' ) ) ) { $fail( 'unknown places must give nothing' ); }

shell_exec( 'rm -rf ' . escapeshellarg( $tmp ) );
echo "ok\n";
}
