<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Saves the website's database for a backup, one table at a time and a page of rows at a time, as plain SQL the Hub can keep and a restore can run. Each request is stateless: the
 * Hub says where the last page ended (the last primary key value, or an offset for a table with no single-column key) and gets the next page and where that one ended. A table's
 * first page starts with the statements that make it again (DROP TABLE and CREATE TABLE). Only the website's own tables (its prefix) can be asked for, names are checked against
 * that list and quoted, and only SELECT and SHOW are ever run. Text that is not valid UTF-8 and binary columns are written as hex so nothing is lost or garbled.
 */
class BackupDumper {
	/** @var BackupDb */
	private $db;

	public function __construct( BackupDb $db ) {
		$this->db = $db;
	}

	/** The website's tables: name, estimated rows and size, and the primary key columns. @return array<int, array{name: string, rows: int, bytes: int, pk: string[]}> */
	public function tables() {
		$like = str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $this->db->prefix() ) . '%';
		$out  = array();
		foreach ( $this->db->rows( "SHOW TABLE STATUS WHERE Name LIKE '" . self::quote( $like ) . "'" ) as $r ) {
			if ( empty( $r['Engine'] ) || ! preg_match( '/^[A-Za-z0-9_$]+$/', (string) $r['Name'] ) ) { // a view, or a name we will not touch
				continue;
			}
			$pk = array();
			foreach ( $this->db->rows( 'SHOW KEYS FROM ' . self::ident( $r['Name'] ) . " WHERE Key_name = 'PRIMARY'" ) as $k ) {
				$pk[ (int) $k['Seq_in_index'] ] = (string) $k['Column_name'];
			}
			ksort( $pk );
			$out[] = array( 'name' => (string) $r['Name'], 'rows' => (int) $r['Rows'], 'bytes' => (int) $r['Data_length'] + (int) $r['Index_length'], 'pk' => array_values( $pk ) );
		}
		return $out;
	}

	/**
	 * One page of a table's dump.
	 *
	 * @param string     $table The table (must be one of tables()).
	 * @param array|null $cursor Where the last page ended: array( 'k' => last key value ) or array( 'o' => offset ); null for the start.
	 * @param int        $max_rows At most this many rows.
	 * @param int        $max_bytes Stop adding rows once the page is about this big.
	 * @return array{sql: string, rows: int, next: ?array, done: bool}
	 */
	public function page( $table, $cursor = null, $max_rows = 500, $max_bytes = 1500000 ) {
		$info = null;
		foreach ( $this->tables() as $t ) {
			if ( $t['name'] === $table ) {
				$info = $t;
				break;
			}
		}
		if ( null === $info ) {
			throw new \InvalidArgumentException( 'unknown table' );
		}
		$max_rows = max( 1, min( 2000, (int) $max_rows ) );
		$ident    = self::ident( $table );
		$cols     = $this->columns( $table );
		$first    = ( null === $cursor || array() === $cursor );
		$sql      = '';
		if ( $first ) {
			$create = $this->db->rows( 'SHOW CREATE TABLE ' . $ident );
			$text   = isset( $create[0]['Create Table'] ) ? $create[0]['Create Table'] : '';
			if ( '' === $text ) {
				throw new \RuntimeException( 'no create statement' );
			}
			$sql .= "-- WP Easy Hub backup of {$ident}\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nDROP TABLE IF EXISTS {$ident};\n{$text};\n";
		}

		// Which rows: after the last key value, or by offset when there is no single-column key.
		$single = ( 1 === count( $info['pk'] ) ) ? $info['pk'][0] : null;
		$where  = '';
		$offset = 0;
		if ( null !== $single ) {
			$order = self::ident( $single );
			if ( is_array( $cursor ) && array_key_exists( 'k', $cursor ) ) {
				$where = ' WHERE ' . $order . ' > ' . self::literal( $cursor['k'], $cols[ $single ]['kind'] );
			}
		} else {
			$order  = $info['pk'] ? implode( ', ', array_map( array( __CLASS__, 'ident' ), $info['pk'] ) ) : '1';
			$offset = is_array( $cursor ) && isset( $cursor['o'] ) ? max( 0, (int) $cursor['o'] ) : 0;
		}
		$names = array();
		foreach ( $cols as $name => $c ) {
			if ( ! $c['generated'] ) {
				$names[] = $name;
			}
		}
		$select = implode( ', ', array_map( array( __CLASS__, 'ident' ), $names ) );
		$rows   = $this->db->rows( "SELECT {$select} FROM {$ident}{$where} ORDER BY {$order} LIMIT " . ( $max_rows + 1 ) . ( null === $single ? " OFFSET {$offset}" : '' ) );
		$more   = count( $rows ) > $max_rows;
		$rows   = array_slice( $rows, 0, $max_rows );

		$batch     = array();
		$batch_len = 0;
		$sql_len   = strlen( $sql );
		$used      = 0;
		$last      = null;
		$head      = "INSERT INTO {$ident} (" . $select . ') VALUES ';
		foreach ( $rows as $row ) {
			$vals = array();
			foreach ( $names as $n ) {
				$vals[] = self::literal( array_key_exists( $n, $row ) ? $row[ $n ] : null, $cols[ $n ]['kind'] );
			}
			$line = '(' . implode( ',', $vals ) . ')';
			if ( $used > 0 && $sql_len + $batch_len + strlen( $line ) > $max_bytes ) {
				break; // the page is full: the Hub asks for the rest
			}
			$batch[]    = $line;
			$batch_len += strlen( $line ) + 2;
			$used++;
			$last = $row;
			if ( count( $batch ) >= 200 || $batch_len >= 262144 ) { // one INSERT stays a sensible size
				$text      = $head . implode( ",\n", $batch ) . ";\n";
				$sql      .= $text;
				$sql_len  += strlen( $text );
				$batch     = array();
				$batch_len = 0;
			}
		}
		if ( $batch ) {
			$sql .= $head . implode( ",\n", $batch ) . ";\n";
		}
		$all  = ( $used === count( $rows ) ) && ! $more;
		$next = null;
		if ( ! $all && $used > 0 ) {
			$next = null !== $single ? array( 'k' => $last[ $single ] ) : array( 'o' => $offset + $used );
		}
		return array( 'sql' => $sql, 'rows' => $used, 'next' => $next, 'done' => $all );
	}

	/** Column name => array( kind: number|binary|text, generated: bool ). @return array<string, array{kind: string, generated: bool}> */
	private function columns( $table ) {
		$out = array();
		foreach ( $this->db->rows( 'SHOW FULL COLUMNS FROM ' . self::ident( $table ) ) as $c ) {
			$type = strtolower( (string) $c['Type'] );
			$kind = 'text';
			if ( preg_match( '/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real|bit|year)\b/', $type ) ) {
				$kind = 'number';
			} elseif ( preg_match( '/^(tinyblob|blob|mediumblob|longblob|binary|varbinary)\b/', $type ) ) {
				$kind = 'binary';
			}
			$extra = strtolower( isset( $c['Extra'] ) ? (string) $c['Extra'] : '' );
			$out[ (string) $c['Field'] ] = array( 'kind' => $kind, 'generated' => ( false !== strpos( $extra, 'generated' ) || false !== strpos( $extra, 'virtual' ) ) );
		}
		return $out;
	}

	/** A name in backticks (the caller has already checked it is a known table or column). */
	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/** A value as a MySQL string literal (no database connection needed). */
	public static function quote( $s ) {
		return str_replace(
			array( '\\', "'", "\0", "\n", "\r", "\x1a" ),
			array( '\\\\', "\\'", '\\0', '\\n', '\\r', '\\Z' ),
			(string) $s
		);
	}

	/** A value as it goes in an INSERT: NULL, a bare number, hex for binary or invalid text, or a quoted string. */
	public static function literal( $v, $kind ) {
		if ( null === $v ) {
			return 'NULL';
		}
		$v = (string) $v;
		if ( 'number' === $kind && preg_match( '/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $v ) ) {
			return $v;
		}
		if ( 'binary' === $kind || 1 !== preg_match( '//u', $v ) ) {
			return '' === $v ? "''" : '0x' . bin2hex( $v );
		}
		return "'" . self::quote( $v ) . "'";
	}
}
