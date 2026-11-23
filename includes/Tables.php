<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Database tables for the Hub's Tools > Database: how big each one is, how much of it is wasted space, how large the options that load on every page are,
 * and a safe "optimise" (OPTIMIZE TABLE) for the tables the Hub is told to. Only tables of this website's own database that start with its own prefix can be touched,
 * nothing is dropped or emptied, and a table over 1 GB is left alone (optimising copies it and can lock it for a long time).
 */
class Tables {
	const ACTIONS = array( 'tables' );
	const BUDGET  = 25;                       // seconds per request
	const MAX_OPT = 1073741824;               // data bytes: bigger tables are never optimised from here
	const CORE    = array( 'posts', 'postmeta', 'options', 'users', 'usermeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links', 'blogs', 'blogmeta', 'signups', 'site', 'sitemeta', 'registration_log' );

	public static function handle( $action, $data ) {
		$op = isset( $data['op'] ) ? (string) $data['op'] : '';
		if ( 'list' === $op ) {
			return self::listing();
		}
		if ( 'optimize' === $op ) {
			return self::optimize( isset( $data['names'] ) ? (array) $data['names'] : array() );
		}
		return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown request.' );
	}

	private static function rows() {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS table_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, DATA_FREE AS free_bytes, TABLE_COLLATION AS collation FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = %s ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC', 'BASE TABLE' ) );
	}

	private static function listing() {
		global $wpdb;
		$prefix = $wpdb->base_prefix;
		$core   = array();
		foreach ( self::CORE as $c ) {
			$core[ $prefix . $c ] = true;
		}
		$tables = array();
		$total  = 0;
		$free   = 0;
		foreach ( self::rows() as $r ) {
			$own = 0 === strpos( $r->name, $prefix );
			$bytes = (int) $r->data_bytes + (int) $r->index_bytes;
			$total += $bytes;
			$f = (int) $r->free_bytes;
			$free += $own ? $f : 0;
			$tables[] = array( 'name' => (string) $r->name, 'engine' => (string) $r->engine, 'rows' => (int) $r->table_rows, 'data' => (int) $r->data_bytes, 'index' => (int) $r->index_bytes, 'free' => $f,
				'kind' => ! $own ? 'other' : ( isset( $core[ $r->name ] ) ? 'core' : 'plugin' ), 'big' => (int) $r->data_bytes > self::MAX_OPT );
		}
		// Options that load on every page: their total size and the largest few (names only, never values).
		$auto = (array) $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on') ORDER BY bytes DESC LIMIT 25" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$auto_total = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$auto_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( 'ok' => true, 'prefix' => $prefix, 'tables' => $tables, 'total' => $total, 'free' => $free,
			'autoload' => array( 'bytes' => $auto_total, 'count' => $auto_count, 'top' => array_map( function ( $o ) { return array( 'name' => (string) $o->option_name, 'bytes' => (int) $o->bytes ); }, $auto ) ) );
	}

	private static function optimize( $names ) {
		global $wpdb;
		$prefix = $wpdb->base_prefix;
		$known  = array();
		foreach ( self::rows() as $r ) {
			$known[ $r->name ] = $r;
		}
		$until = microtime( true ) + self::BUDGET;
		$done  = array();
		$skipped = array();
		foreach ( array_slice( array_values( array_unique( array_map( 'strval', $names ) ) ), 0, 200 ) as $n ) {
			if ( ! isset( $known[ $n ] ) || 0 !== strpos( $n, $prefix ) ) {
				$skipped[] = array( 'name' => $n, 'why' => __( 'not one of this website\'s own tables', 'wpeasy-hub-connector' ) );
				continue;
			}
			if ( (int) $known[ $n ]->data_bytes > self::MAX_OPT ) {
				$skipped[] = array( 'name' => $n, 'why' => __( 'too big to optimise from here (over 1 GB)', 'wpeasy-hub-connector' ) );
				continue;
			}
			if ( microtime( true ) >= $until ) {
				break; // the rest are asked again
			}
			$before = (int) $known[ $n ]->data_bytes + (int) $known[ $n ]->index_bytes;
			$res    = $wpdb->get_results( 'OPTIMIZE TABLE `' . str_replace( '`', '``', $n ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the name is one of the database's own tables, checked above
			$msg    = '';
			$ok     = true;
			foreach ( (array) $res as $row ) {
				$type = isset( $row->Msg_type ) ? strtolower( (string) $row->Msg_type ) : '';
				if ( 'error' === $type ) {
					$ok  = false;
					$msg = (string) $row->Msg_text;
				}
			}
			$after = $wpdb->get_row( $wpdb->prepare( 'SELECT DATA_LENGTH AS d, INDEX_LENGTH AS i FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $n ) );
			$done[] = array( 'name' => $n, 'ok' => $ok, 'message' => substr( $msg, 0, 160 ), 'before' => $before, 'after' => $after ? (int) $after->d + (int) $after->i : $before );
		}
		$finished = array_column( $done, 'name' );
		$left     = array_values( array_diff( array_unique( array_map( 'strval', $names ) ), $finished, array_column( $skipped, 'name' ) ) );
		return array( 'ok' => true, 'done' => $done, 'skipped' => $skipped, 'left' => $left );
	}
}
