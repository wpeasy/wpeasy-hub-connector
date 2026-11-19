<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Puts one database table back from the SQL the Hub made for it, without ever leaving a half-built table: it is built beside the real one under a temporary name and only swapped in, in one step,
 * when the last block has arrived and everything ran. If anything fails the temporary table is removed and the real table is exactly as it was. Only the statements a backup of that one table
 * contains are run (the SET lines, DROP TABLE IF EXISTS, CREATE TABLE and INSERT INTO for exactly that table); anything else stops the restore.
 */
class RestoreDb {
	const NEW_SUFFIX = '__hubnew';
	const OLD_SUFFIX = '__hubold';

	/**
	 * @param bool $first This block starts the table (any leftover temporary table is removed first).
	 * @param bool $last  This block ends the table: swap it in.
	 * @return array{ok: bool, executed: int, message?: string, swapped?: bool}
	 */
	public static function run( SqlRunner $db, $table, $sql, $first = true, $last = true ) {
		if ( ! is_string( $table ) || 1 !== preg_match( '/^[A-Za-z0-9_$]{1,100}$/', $table ) ) {
			return array( 'ok' => false, 'executed' => 0, 'message' => 'That is not a table name.' );
		}
		$new   = $table . self::NEW_SUFFIX;
		$old   = $table . self::OLD_SUFFIX;
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
		$run = array();
		foreach ( $parts['statements'] as $stmt ) { // all checked before any runs, so a bad block changes nothing
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
			// Everything is aimed at the temporary table, never the real one, until the swap.
			$run[] = preg_replace( '/^(DROP TABLE IF EXISTS |CREATE TABLE |INSERT INTO )`' . $t . '`/', '$1`' . $new . '`', $stmt, 1 );
		}
		$n = 0;
		if ( $first ) {
			$db->exec( 'DROP TABLE IF EXISTS `' . $new . '`' );
			$db->exec( 'DROP TABLE IF EXISTS `' . $old . '`' );
		}
		foreach ( $run as $stmt ) {
			$r = $db->exec( $stmt );
			if ( true !== $r ) {
				$db->exec( 'DROP TABLE IF EXISTS `' . $new . '`' ); // the real table was never touched
				return array( 'ok' => false, 'executed' => $n, 'message' => $r );
			}
			$n++;
		}
		if ( $last ) {
			$exists = $db->exists( $table );
			$swap   = $exists ? 'RENAME TABLE `' . $table . '` TO `' . $old . '`, `' . $new . '` TO `' . $table . '`' : 'RENAME TABLE `' . $new . '` TO `' . $table . '`';
			$r      = $db->exec( $swap ); // one atomic step
			if ( true !== $r ) {
				$db->exec( 'DROP TABLE IF EXISTS `' . $new . '`' );
				return array( 'ok' => false, 'executed' => $n, 'message' => $r );
			}
			$db->exec( 'DROP TABLE IF EXISTS `' . $old . '`' );
			return array( 'ok' => true, 'executed' => $n, 'swapped' => true );
		}
		return array( 'ok' => true, 'executed' => $n, 'swapped' => false );
	}
}
