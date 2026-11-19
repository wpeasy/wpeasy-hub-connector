<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Puts one database table back from the SQL the Hub made for it. Only the statements a backup of that one table contains are run (the SET lines, DROP TABLE IF EXISTS, CREATE TABLE and INSERT INTO
 * for exactly that table); anything else stops the restore. The Hub sends the SQL in blocks of whole statements.
 */
class RestoreDb {
	/** @return array{ok: bool, executed: int, message?: string} */
	public static function run( SqlRunner $db, $table, $sql ) {
		if ( ! is_string( $table ) || 1 !== preg_match( '/^[A-Za-z0-9_$]{1,128}$/', $table ) ) {
			return array( 'ok' => false, 'executed' => 0, 'message' => 'That is not a table name.' );
		}
		$parts = SqlSplitter::split( (string) $sql );
		if ( '' !== $parts['rest'] ) {
			return array( 'ok' => false, 'executed' => 0, 'message' => 'The block of SQL did not end with a complete statement.' );
		}
		$t     = preg_quote( $table, '/' );
		$allow = array(
			'/^SET NAMES utf8mb4$/',
			'/^SET FOREIGN_KEY_CHECKS=[01]$/',
			"/^SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'$/",
			"/^DROP TABLE IF EXISTS `{$t}`$/",
			"/^CREATE TABLE `{$t}` \\(/",
			"/^INSERT INTO `{$t}` /",
		);
		foreach ( $parts['statements'] as $stmt ) { // checked all before any runs, so a bad block changes nothing
			$ok = false;
			foreach ( $allow as $re ) {
				if ( 1 === preg_match( $re, $stmt ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				return array( 'ok' => false, 'executed' => 0, 'message' => 'A statement in the SQL is not one a table backup contains: ' . substr( $stmt, 0, 60 ) );
			}
		}
		$n = 0;
		foreach ( $parts['statements'] as $stmt ) {
			$r = $db->exec( $stmt );
			if ( true !== $r ) {
				return array( 'ok' => false, 'executed' => $n, 'message' => $r );
			}
			$n++;
		}
		return array( 'ok' => true, 'executed' => $n );
	}
}
