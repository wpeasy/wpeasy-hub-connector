<?php
// Run: php tests/backup-test.php
// The backup pieces: what paths are allowed, folder listing, file frames, and the database dump (against a real MySQL/MariaDB if reachable; skipped otherwise).
$tmp = sys_get_temp_dir() . '/hubbackup-' . getmypid();
@mkdir( $tmp . '/site/wp-content/uploads/2026', 0777, true );
@mkdir( $tmp . '/site/wp-content/cache', 0777, true );
@mkdir( $tmp . '/site/wp-content/plugins/x/.git', 0777, true );
@mkdir( $tmp . '/outside', 0777, true );
file_put_contents( $tmp . '/site/wp-content/uploads/2026/a.txt', 'hello world' );
file_put_contents( $tmp . '/site/wp-content/uploads/big.bin', str_repeat( 'abcdefghij', 1000 ) );
file_put_contents( $tmp . '/site/wp-content/cache/c.txt', 'cache' );
file_put_contents( $tmp . '/site/wp-content/plugins/x/.git/config', 'git' );
file_put_contents( $tmp . '/site/wp-content/debug.log', 'log' );
file_put_contents( $tmp . '/site/wp-config.php', '<?php // config' );
file_put_contents( $tmp . '/site/index.php', '<?php // core' );
@mkdir( $tmp . '/site/wp-admin', 0777, true );
file_put_contents( $tmp . '/site/wp-admin/admin.php', '<?php // core admin' );
@mkdir( $tmp . '/site/.well-known', 0777, true );
file_put_contents( $tmp . '/site/.well-known/security.txt', 'Contact: x' );
@mkdir( $tmp . '/site/.git', 0777, true );
file_put_contents( $tmp . '/site/.git/config', 'git' );
file_put_contents( $tmp . '/site/.htaccess', 'RewriteEngine On' );
file_put_contents( $tmp . '/site/.user.ini', 'memory_limit=512M' );
file_put_contents( $tmp . '/site/ads.txt', 'google.com, pub-1' );
file_put_contents( $tmp . '/site/robots.txt', 'User-agent: *' );
file_put_contents( $tmp . '/outside/secret.txt', 'secret' );
chmod( $tmp . '/site/wp-content/uploads/2026/a.txt', 0640 );
chmod( $tmp . '/site/wp-content/uploads/2026', 0750 );
symlink( $tmp . '/outside', $tmp . '/site/wp-content/uploads/escape' );
symlink( $tmp . '/outside/secret.txt', $tmp . '/site/wp-content/uploads/link.txt' );
define( 'ABSPATH', $tmp . '/site/' );
define( 'WP_CONTENT_DIR', $tmp . '/site/wp-content' );
foreach ( array( 'BackupPaths', 'BackupLister', 'BackupReader', 'BackupDb', 'BackupDumper' ) as $f ) { require __DIR__ . "/../includes/$f.php"; }
use WPEasy\HubConnector\BackupPaths;
use WPEasy\HubConnector\BackupLister;
use WPEasy\HubConnector\BackupReader;
use WPEasy\HubConnector\BackupDb;
use WPEasy\HubConnector\BackupDumper;

$fail = function ( $m ) use ( $tmp ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); };

// Paths
if ( null === BackupPaths::resolve( 'wp-content/uploads/2026/a.txt' ) ) { $fail( 'a normal file was refused' ); }
if ( null === BackupPaths::resolve( 'wp-config.php' ) ) { $fail( 'wp-config.php was refused' ); }
foreach ( array( 'index.php', 'wp-admin/admin.php', '.git/config', 'wp-content/../index.php', '../outside/secret.txt', 'wp-content/uploads/escape/secret.txt', 'wp-content/uploads/link.txt', 'wp-content/cache/c.txt', 'wp-content/plugins/x/.git/config', 'wp-content/debug.log', '/etc/passwd', "wp-content/uploads/a\0.txt", '' ) as $bad ) {
	if ( null !== BackupPaths::resolve( $bad ) ) { $fail( "a path that must be refused was allowed: " . json_encode( $bad ) ); }
}

// Lister
$r = BackupLister::dirs( array( '', 'wp-content', 'wp-content/uploads', 'wp-content/cache', 'wp-content/uploads/escape' ) );
$names = array_column( $r['dirs']['wp-content'], 'n' );
if ( in_array( 'cache', $names, true ) || in_array( 'debug.log', $names, true ) || ! in_array( 'uploads', $names, true ) ) { $fail( 'wp-content listing wrong: ' . json_encode( $names ) ); }
$up = array_column( $r['dirs']['wp-content/uploads'], 'n' );
if ( in_array( 'escape', $up, true ) || in_array( 'link.txt', $up, true ) || ! in_array( 'big.bin', $up, true ) ) { $fail( 'uploads listing wrong (symlinks must be left out): ' . json_encode( $up ) ); }
if ( null !== $r['dirs']['wp-content/uploads/escape'] ) { $fail( 'a symlinked folder could be listed' ); }
if ( null !== $r['dirs']['wp-content/cache'] ) { $fail( 'an excluded folder could be listed' ); }
$modes = array();
foreach ( BackupLister::dirs( array( 'wp-content/uploads', 'wp-content/uploads/2026' ) )['dirs'] as $list ) { foreach ( $list as $e ) { $modes[ $e['n'] ] = $e['p']; } }
if ( 0750 !== $modes['2026'] || 0640 !== $modes['a.txt'] ) { $fail( 'permissions not reported: ' . json_encode( $modes ) ); }
$top = array_column( $r['dirs'][''], 'n' );
foreach ( array( 'wp-content', 'wp-config.php', '.htaccess', '.user.ini', 'ads.txt', 'robots.txt', '.well-known' ) as $want ) { if ( ! in_array( $want, $top, true ) ) { $fail( "top level is missing $want: " . json_encode( $top ) ); } }
foreach ( array( 'index.php', 'wp-admin', '.git' ) as $no ) { if ( in_array( $no, $top, true ) ) { $fail( "top level wrongly has $no (core or version control)" ); } }
$with = array_column( BackupLister::dirs( array( '' ), 20000, 20, true )['dirs'][''], 'n' );
if ( ! in_array( 'index.php', $with, true ) || ! in_array( 'wp-admin', $with, true ) ) { $fail( 'asking for core did not include it: ' . json_encode( $with ) ); }
if ( null === BackupPaths::resolve( 'wp-admin/admin.php', true ) || null === BackupPaths::resolve( '.htaccess' ) ) { $fail( 'core with the flag, or .htaccess, was refused' ); }

// Reader frames
function frames( $data ) {
	$magic = "WPEHB1\n";
	if ( 0 !== strpos( $data, $magic ) ) { return false; }
	$pos = strlen( $magic ); $out = array();
	while ( $pos < strlen( $data ) ) {
		$nl = strpos( $data, "\n", $pos ); $h = json_decode( substr( $data, $pos, $nl - $pos ), true ); $pos = $nl + 1;
		if ( ! empty( $h['end'] ) ) { $out['end'] = $h; break; }
		$n = (int) $h['n']; $h['data'] = substr( $data, $pos, $n ); $pos += $n + 1; $out[] = $h;
	}
	return $out;
}
$f = frames( BackupReader::build( array( array( 'p' => 'wp-content/uploads/2026/a.txt', 'o' => 0, 'l' => 100 ), array( 'p' => 'wp-content/uploads/big.bin', 'o' => 10, 'l' => 20 ), array( 'p' => 'wp-content/uploads/escape/secret.txt' ), array( 'p' => 'wp-config.php' ) ) ) );
if ( ! $f || 'hello world' !== $f[0]['data'] || empty( $f[0]['eof'] ) ) { $fail( 'whole file frame wrong' ); }
if ( 20 !== strlen( $f[1]['data'] ) || 'abcdefghij' . 'abcdefghij' !== $f[1]['data'] || ! empty( $f[1]['eof'] ) || 10000 !== $f[1]['size'] ) { $fail( 'piece frame wrong' ); }
if ( 'missing' !== $f[2]['err'] ) { $fail( 'a symlink escape was readable' ); }
if ( '<?php // config' !== $f[3]['data'] ) { $fail( 'wp-config frame wrong' ); }
if ( 4 !== $f['end']['served'] ) { $fail( 'served count wrong' ); }
$f = frames( BackupReader::build( array( array( 'p' => 'wp-content/uploads/big.bin', 'o' => 0, 'l' => 99999 ), array( 'p' => 'wp-content/uploads/2026/a.txt' ) ), 4000 ) );
if ( 4000 !== $f[0]['n'] || ! empty( $f[0]['eof'] ) ) { $fail( 'response limit not applied' ); }
if ( $f['end']['served'] > 2 ) { $fail( 'served too many' ); }

// Dumper
class PdoDb implements BackupDb {
	public $pdo; public $prefix = 'hbt_';
	public $sqls = array();
	public function rows( $sql ) { $this->sqls[] = $sql; return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
	public function prefix() { return $this->prefix; }
}
try {
	$pdo = new PDO( 'mysql:host=127.0.0.1;port=3306;dbname=testing', 'sail', 'password', array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
} catch ( Exception $e ) {
	echo "ok: backup paths, listing and file frames (database dump skipped: no test database)\n";
	exec( 'rm -rf ' . escapeshellarg( $tmp ) );
	exit( 0 );
}
$db = new PdoDb(); $db->pdo = $pdo;
$pdo->exec( 'DROP TABLE IF EXISTS hbt_posts, hbt_meta, other_table' );
$pdo->exec( "CREATE TABLE hbt_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT, title text, bin blob, n int NULL, PRIMARY KEY (ID)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
$pdo->exec( "CREATE TABLE hbt_meta (a int, b varchar(20)) ENGINE=InnoDB" );
$pdo->exec( "CREATE TABLE other_table (x int)" );
$ins = $pdo->prepare( 'INSERT INTO hbt_posts (title, bin, n) VALUES (?, ?, ?)' );
for ( $i = 1; $i <= 450; $i++ ) { $ins->execute( array( "It's \"post\" $i\nline2 \\ end ✓", $i % 7 ? "bin\0\xff$i" : null, $i % 5 ? $i : null ) ); }
for ( $i = 1; $i <= 30; $i++ ) { $pdo->exec( "INSERT INTO hbt_meta VALUES ($i, 'v$i')" ); }
$d = new BackupDumper( $db );
$tables = array_column( $d->tables(), 'name' );
sort( $tables );
if ( array( 'hbt_meta', 'hbt_posts' ) !== $tables ) { $fail( 'tables wrong: ' . json_encode( $tables ) ); }
try { $d->page( 'other_table' ); $fail( 'a table outside the prefix could be dumped' ); } catch ( InvalidArgumentException $e ) { /* good */ }
try { $d->page( 'hbt_posts; DROP TABLE hbt_posts' ); $fail( 'an injected table name was accepted' ); } catch ( InvalidArgumentException $e ) { /* good */ }
foreach ( $db->sqls as $s ) { if ( ! preg_match( '/^\s*(SELECT|SHOW)\b/i', $s ) ) { $fail( 'a non-read query was run: ' . $s ); } }

// Dump everything page by page (small pages), restore into a copy, compare.
$all = ''; foreach ( array( 'hbt_posts', 'hbt_meta' ) as $t ) {
	$cursor = null; $guard = 0;
	do { $p = $d->page( $t, $cursor, 60, 20000 ); $all .= $p['sql']; $cursor = $p['next']; $guard++; } while ( ! $p['done'] && $guard < 200 );
	if ( $guard >= 200 ) { $fail( "paging never finished for $t" ); }
}
$before = $pdo->query( 'SELECT MD5(GROUP_CONCAT(CONCAT_WS("|",ID,title,HEX(bin),IFNULL(n,"NULL")) ORDER BY ID)) FROM hbt_posts' )->fetchColumn();
$metaBefore = $pdo->query( 'SELECT MD5(GROUP_CONCAT(CONCAT_WS("|",a,b) ORDER BY a)) FROM hbt_meta' )->fetchColumn();
$pdo->exec( 'DELETE FROM hbt_posts' );
$pdo->exec( 'DROP TABLE hbt_meta' );
$pdo->exec( $all );
$after = $pdo->query( 'SELECT MD5(GROUP_CONCAT(CONCAT_WS("|",ID,title,HEX(bin),IFNULL(n,"NULL")) ORDER BY ID)) FROM hbt_posts' )->fetchColumn();
$metaAfter = $pdo->query( 'SELECT MD5(GROUP_CONCAT(CONCAT_WS("|",a,b) ORDER BY a)) FROM hbt_meta' )->fetchColumn();
if ( $before !== $after ) { $fail( 'restored posts differ from the original (keyset paging)' ); }
if ( $metaBefore !== $metaAfter ) { $fail( 'restored meta differs (offset paging, no primary key)' ); }
if ( 450 !== (int) $pdo->query( 'SELECT COUNT(*) FROM hbt_posts' )->fetchColumn() ) { $fail( 'row count differs' ); }
// A table whose primary key is binary: the cursor goes through JSON between pages (as it does to the Hub and back), which used to damage the key and make paging go round in circles.
$pdo->exec( 'DROP TABLE IF EXISTS hbt_bin' );
$pdo->exec( 'CREATE TABLE hbt_bin (k binary(16) NOT NULL, v int, PRIMARY KEY (k)) ENGINE=InnoDB' );
$insb = $pdo->prepare( 'INSERT INTO hbt_bin (k, v) VALUES (?, ?)' );
for ( $i = 1; $i <= 450; $i++ ) { $insb->execute( array( md5( "row$i", true ), $i ) ); }
$d2 = new BackupDumper( $db );
$allb = ''; $cursor = null; $guard = 0; $seen = array();
do {
	$p = $d2->page( 'hbt_bin', $cursor, 60, 20000 );
	$allb .= $p['sql'];
	$cursor = null === $p['next'] ? null : json_decode( json_encode( $p['next'] ), true ); // the trip through JSON
	if ( null !== $cursor ) { $fp = md5( serialize( $cursor ) ); if ( isset( $seen[ $fp ] ) ) { $fail( 'binary key paging went round in a circle' ); } $seen[ $fp ] = true; }
	$guard++;
} while ( ! $p['done'] && $guard < 200 );
if ( $guard >= 200 ) { $fail( 'binary key paging never finished' ); }
$beforeb = $pdo->query( 'SELECT MD5(GROUP_CONCAT(CONCAT_WS("|",HEX(k),v) ORDER BY k)) FROM hbt_bin' )->fetchColumn();
$pdo->exec( 'DROP TABLE hbt_bin' );
$pdo->exec( $allb );
$afterb = $pdo->query( 'SELECT MD5(GROUP_CONCAT(CONCAT_WS("|",HEX(k),v) ORDER BY k)) FROM hbt_bin' )->fetchColumn();
if ( $beforeb !== $afterb || 450 !== (int) $pdo->query( 'SELECT COUNT(*) FROM hbt_bin' )->fetchColumn() ) { $fail( 'a table with a binary key did not dump and restore exactly' ); }
$pdo->exec( 'DROP TABLE IF EXISTS hbt_bin' );
$pdo->exec( 'DROP TABLE IF EXISTS hbt_posts, hbt_meta, other_table' );
exec( 'rm -rf ' . escapeshellarg( $tmp ) );
echo "ok: backup paths, listing, file frames and a database dump that restores exactly\n";
