<?php
// Run: php tests/restore-test.php
// The restore pieces: where files may be written, piecewise files that are checked before they replace anything, the SQL splitter, and a table restore that only runs the statements a table backup contains.
$tmp = sys_get_temp_dir() . '/hubrestore-' . getmypid();
@mkdir( $tmp . '/site/wp-content/uploads', 0777, true );
@mkdir( $tmp . '/site/wp-content/plugins/wpeasy-hub-connector', 0777, true );
@mkdir( $tmp . '/outside', 0777, true );
file_put_contents( $tmp . '/site/wp-content/uploads/old.txt', 'old contents' );
file_put_contents( $tmp . '/site/wp-content/plugins/wpeasy-hub-connector/own.php', '<?php // own' );
file_put_contents( $tmp . '/outside/secret.txt', 'secret' );
symlink( $tmp . '/outside', $tmp . '/site/wp-content/uploads/escape' );
define( 'ABSPATH', $tmp . '/site/' );
define( 'WP_CONTENT_DIR', $tmp . '/site/wp-content' );
foreach ( array( 'BackupPaths', 'SqlSplitter', 'SqlRunner', 'RestoreGuard', 'RestoreFiles', 'RestoreDb' ) as $f ) { require __DIR__ . "/../includes/$f.php"; }
use WPEasy\HubConnector\RestoreGuard;
use WPEasy\HubConnector\RestoreFiles;
use WPEasy\HubConnector\RestoreDb;
use WPEasy\HubConnector\SqlSplitter;
use WPEasy\HubConnector\SqlRunner;

$fail = function ( $m ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); };

// Where a restore may write
foreach ( array( 'wp-content/uploads/new/photo.jpg', 'wp-content/uploads/old.txt', '.htaccess', 'ads.txt', '.well-known/security.txt' ) as $ok ) {
	if ( null === RestoreGuard::file( $ok ) ) { $fail( "a path that must be allowed was refused: $ok" ); }
}
foreach ( array( '', '../x', 'wp-content/../x', 'wp-content/uploads/escape/pwned.txt', 'wp-content/plugins/wpeasy-hub-connector/own.php', 'wp-content/plugins/wpeasy-hub-connector', 'wp-content/cache/c.txt', 'wp-admin/admin.php', 'index.php', '.maintenance', 'wp-content/uploads/x.hubtmp', "wp-content/a\0b", 'wp-content', 'backups/x' ) as $bad ) {
	if ( null !== RestoreGuard::file( $bad ) ) { $fail( 'a path that must be refused was allowed: ' . json_encode( $bad ) ); }
}
if ( null === RestoreGuard::file( 'wp-admin/admin.php', true ) || null === RestoreGuard::file( 'index.php', true ) ) { $fail( 'core with the flag was refused' ); }

// Files, in pieces, checked before they replace anything
$data = random_bytes( 3000 );
$sha  = hash( 'sha256', $data );
$r = RestoreFiles::apply( array( array( 'p' => 'wp-content/uploads/new/big.bin', 'o' => 0, 'data' => base64_encode( substr( $data, 0, 1000 ) ), 'total' => 3000, 'sha' => $sha, 'last' => false ) ) );
if ( ! $r['ok'] || file_exists( $tmp . '/site/wp-content/uploads/new/big.bin' ) ) { $fail( 'the first piece must not create the real file' ); }
$r = RestoreFiles::apply( array( array( 'p' => 'wp-content/uploads/new/big.bin', 'o' => 5, 'data' => base64_encode( 'zz' ), 'last' => false ) ) );
if ( $r['ok'] ) { $fail( 'a piece at the wrong offset was accepted' ); }
$r = RestoreFiles::apply( array( array( 'p' => 'wp-content/uploads/new/big.bin', 'o' => 1000, 'data' => base64_encode( substr( $data, 1000, 1000 ) ), 'last' => false ), array( 'p' => 'wp-content/uploads/new/big.bin', 'o' => 2000, 'data' => base64_encode( substr( $data, 2000 ) ), 'total' => 3000, 'sha' => $sha, 'x' => 0640, 'm' => 1700000000, 'last' => true ) ) );
if ( ! $r['ok'] || 1 !== $r['done'] ) { $fail( 'the file did not complete: ' . json_encode( $r ) ); }
$real = $tmp . '/site/wp-content/uploads/new/big.bin';
if ( file_get_contents( $real ) !== $data ) { $fail( 'the restored file differs' ); }
if ( ( fileperms( $real ) & 0777 ) !== 0640 || filemtime( $real ) !== 1700000000 ) { $fail( 'permissions or time not restored' ); }
if ( file_exists( $real . '.hubtmp' ) ) { $fail( 'a temporary file was left behind' ); }

// a wrong fingerprint never replaces the good file
$r = RestoreFiles::apply( array( array( 'p' => 'wp-content/uploads/old.txt', 'o' => 0, 'data' => base64_encode( 'new contents' ), 'total' => 12, 'sha' => str_repeat( '0', 64 ), 'last' => true ) ) );
if ( $r['ok'] || 'old contents' !== file_get_contents( $tmp . '/site/wp-content/uploads/old.txt' ) ) { $fail( 'a file with a wrong fingerprint replaced the good one' ); }
$r = RestoreFiles::apply( array( array( 'p' => 'wp-content/uploads/old.txt', 'o' => 0, 'data' => base64_encode( 'new contents' ), 'total' => 12, 'sha' => hash( 'sha256', 'new contents' ), 'last' => true ) ) );
if ( ! $r['ok'] || 'new contents' !== file_get_contents( $tmp . '/site/wp-content/uploads/old.txt' ) ) { $fail( 'a good file did not replace the old one' ); }
$r = RestoreFiles::apply( array( array( 'p' => 'wp-content/plugins/wpeasy-hub-connector/own.php', 'o' => 0, 'data' => '', 'total' => 0, 'last' => true ) ) );
if ( $r['ok'] || '<?php // own' !== file_get_contents( $tmp . '/site/wp-content/plugins/wpeasy-hub-connector/own.php' ) ) { $fail( 'the plugin\'s own file could be replaced' ); }
RestoreFiles::dirs( array( array( 'p' => 'wp-content/uploads/empty-dir', 'x' => 0750 ), array( 'p' => 'wp-admin/evil' ) ) );
if ( ! is_dir( $tmp . '/site/wp-content/uploads/empty-dir' ) || ( fileperms( $tmp . '/site/wp-content/uploads/empty-dir' ) & 0777 ) !== 0750 || is_dir( $tmp . '/site/wp-admin' ) ) { $fail( 'folders: made wrong' ); }

// SQL splitting respects quotes
$sql = "-- WP Easy Hub backup of `t`\nSET NAMES utf8mb4;\nINSERT INTO `t` VALUES ('a;b'),('it''s; ok'),('line1\nline2; still'),('back\\\\slash\\';x');\n-- a comment\nDROP TABLE IF EXISTS `t`;\n";
$p = SqlSplitter::split( $sql );
if ( 3 !== count( $p['statements'] ) || '' !== $p['rest'] ) { $fail( 'split wrong: ' . json_encode( $p ) ); }
if ( 0 !== strpos( $p['statements'][1], "INSERT INTO `t` VALUES ('a;b')" ) || substr_count( $p['statements'][1], 'still' ) !== 1 ) { $fail( 'a semicolon inside a string split a statement' ); }
$p = SqlSplitter::split( "INSERT INTO `t` VALUES (1);\nINSERT INTO `t` VALUES (2" );
if ( 1 !== count( $p['statements'] ) || '' === $p['rest'] ) { $fail( 'an unfinished block was not noticed' ); }

// A table restore runs only what a table backup contains (against a real MySQL/MariaDB if reachable)
class PdoRunner implements SqlRunner {
	public $pdo; public $ran = array();
	public function exists( $table ) { return (bool) $this->pdo->query( "SHOW TABLES LIKE '" . $table . "'" )->fetchAll(); }
	public function exec( $sql ) { try { $this->pdo->exec( $sql ); $this->ran[] = $sql; return true; } catch ( Exception $e ) { return $e->getMessage(); } }
}
try {
	$pdo = new PDO( 'mysql:host=127.0.0.1;port=3306;dbname=testing', 'sail', 'password', array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
} catch ( Exception $e ) {
	echo "ok: restore guard, files, SQL splitting (table restore skipped: no test database)\n";
	exec( 'rm -rf ' . escapeshellarg( $tmp ) );
	exit( 0 );
}
$run = new PdoRunner(); $run->pdo = $pdo;
$pdo->exec( 'DROP TABLE IF EXISTS hbr_t, hbr_other' );
$pdo->exec( 'CREATE TABLE hbr_other (id int)' ); $pdo->exec( 'INSERT INTO hbr_other VALUES (1)' );
$pdo->exec( 'CREATE TABLE hbr_t (id int primary key, v varchar(50))' ); $pdo->exec( "INSERT INTO hbr_t VALUES (99, 'changed since the backup')" );
$dump = "-- WP Easy Hub backup of `hbr_t`\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nDROP TABLE IF EXISTS `hbr_t`;\nCREATE TABLE `hbr_t` (\n  `id` int(11) NOT NULL,\n  `v` varchar(50) DEFAULT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\nINSERT INTO `hbr_t` (`id`,`v`) VALUES (1,'one; with semicolon'),(2,'it\\'s two');\n";
$r = RestoreDb::run( $run, 'hbr_t', $dump );
if ( ! $r['ok'] || 6 !== $r['executed'] || empty( $r['swapped'] ) ) { $fail( 'table restore failed: ' . json_encode( $r ) ); }
if ( $pdo->query( "SHOW TABLES LIKE 'hbr_t\\_\\_hub%'" )->fetchAll() ) { $fail( 'a temporary table was left behind' ); }
$rows = $pdo->query( 'SELECT id, v FROM hbr_t ORDER BY id' )->fetchAll( PDO::FETCH_KEY_PAIR );
if ( array( 1 => 'one; with semicolon', 2 => "it's two" ) !== $rows ) { $fail( 'restored rows wrong: ' . json_encode( $rows ) ); }
// anything else is refused before any statement runs
foreach ( array( "DROP TABLE IF EXISTS `hbr_other`;", "DROP TABLE `hbr_t`;", "INSERT INTO `hbr_other` VALUES (5);", "UPDATE `hbr_t` SET v='x';", "SET GLOBAL max_connections=1;", "SELECT 1;", "DROP TABLE IF EXISTS `hbr_t`; DROP TABLE IF EXISTS `hbr_other`;" ) as $evil ) {
	$r = RestoreDb::run( $run, 'hbr_t', "SET NAMES utf8mb4;\n$evil" );
	if ( $r['ok'] ) { $fail( 'a statement outside a table backup ran: ' . $evil ); }
}
if ( 1 !== (int) $pdo->query( 'SELECT COUNT(*) FROM hbr_other' )->fetchColumn() || 2 !== (int) $pdo->query( 'SELECT COUNT(*) FROM hbr_t' )->fetchColumn() ) { $fail( 'a refused block changed data' ); }
if ( RestoreDb::run( $run, 'hbr_t; DROP TABLE x', $dump )['ok'] ) { $fail( 'a bad table name was accepted' ); }

// a restore that fails part-way leaves the real table exactly as it was, and no temporary table
$before = $pdo->query( 'SELECT id, v FROM hbr_t ORDER BY id' )->fetchAll( PDO::FETCH_KEY_PAIR );
$broken = str_replace( "(2,'it\\'s two');", "(2,'x'),(1,'duplicate key breaks the insert');", $dump );
$r = RestoreDb::run( $run, 'hbr_t', $broken );
if ( $r['ok'] ) { $fail( 'a broken restore reported success' ); }
if ( $pdo->query( 'SELECT id, v FROM hbr_t ORDER BY id' )->fetchAll( PDO::FETCH_KEY_PAIR ) !== $before ) { $fail( 'a failed restore changed the real table' ); }
if ( $pdo->query( "SHOW TABLES LIKE 'hbr_t\\_\\_hub%'" )->fetchAll() ) { $fail( 'a failed restore left a temporary table' ); }

// a table sent in several blocks is swapped in only after the last one
$blocks = explode( "INSERT INTO", $dump );
$r1 = RestoreDb::run( $run, 'hbr_t', $blocks[0], true, false );
if ( ! $r1['ok'] || $r1['swapped'] || $pdo->query( 'SELECT id, v FROM hbr_t ORDER BY id' )->fetchAll( PDO::FETCH_KEY_PAIR ) !== $before ) { $fail( 'the first block changed the real table' ); }
$r2 = RestoreDb::run( $run, 'hbr_t', 'INSERT INTO' . $blocks[1], false, true );
if ( ! $r2['ok'] || empty( $r2['swapped'] ) || 2 !== (int) $pdo->query( 'SELECT COUNT(*) FROM hbr_t' )->fetchColumn() ) { $fail( 'the last block did not swap the table in: ' . json_encode( $r2 ) ); }

// a table that no longer exists is simply created
$pdo->exec( 'DROP TABLE hbr_t' );
if ( ! RestoreDb::run( $run, 'hbr_t', $dump )['ok'] || 2 !== (int) $pdo->query( 'SELECT COUNT(*) FROM hbr_t' )->fetchColumn() ) { $fail( 'a dropped table was not recreated' ); }
$pdo->exec( 'DROP TABLE IF EXISTS hbr_t, hbr_other' );
exec( 'rm -rf ' . escapeshellarg( $tmp ) );
echo "ok: restore guard, piecewise files, SQL splitting and a table restore that runs only a table's own statements\n";
