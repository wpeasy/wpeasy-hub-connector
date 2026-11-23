<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Search and replace across this website's database, built to be careful:
 *  - scan: a dry run. Counts the rows that would change in each table and shows a few examples. Changes nothing.
 *  - run: changes only the tables the Hub names, in batches. A value that holds serialised data (WordPress and plugins store lists this way) is read as serialised data and written back with its
 *    lengths put right, so it does not break; a value that looks serialised but cannot be read is left alone and reported. Addresses written the JSON way (https:\/\/example.com) are matched too.
 *  - undo: every changed value is first saved in a journal table (kept 30 days), so a run can be put back, and only where the value has not been changed since.
 * Never touched: tables of another prefix, passwords and sign-in sessions, the Hub Connector's own settings, and (unless asked) the permanent address (guid) of posts.
 */
class SearchReplace {
	const ACTIONS = array( 'replace' );
	const BUDGET  = 20;
	const CHUNK   = 200;
	const KEEP    = 30 * DAY_IN_SECONDS;
	const STATE   = 'wpeasy_hub_sr_';
	const TEXT    = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpeasy_hub_sr';
	}

	public static function handle( $action, $data ) {
		$op = isset( $data['op'] ) ? (string) $data['op'] : '';
		$o  = self::options( $data );
		if ( is_string( $o ) ) {
			return array( 'ok' => false, 'code' => 'bad', 'message' => $o );
		}
		if ( 'scan' === $op ) {
			return self::scan( $o, isset( $data['tables'] ) ? (array) $data['tables'] : null );
		}
		if ( 'start' === $op ) {
			return self::start( $o, isset( $data['tables'] ) ? (array) $data['tables'] : array(), isset( $data['expect'] ) ? (int) $data['expect'] : -1 );
		}
		if ( 'step' === $op ) {
			return self::step( isset( $data['token'] ) ? (string) $data['token'] : '' );
		}
		if ( 'undo' === $op ) {
			return self::undo( isset( $data['token'] ) ? (string) $data['token'] : '' );
		}
		return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown request.' );
	}

	/** The search, the replacement and the switches, checked. A string is returned for a problem. */
	private static function options( $data ) {
		$op = isset( $data['op'] ) ? (string) $data['op'] : '';
		if ( in_array( $op, array( 'step', 'undo' ), true ) ) {
			return array();
		}
		$search = isset( $data['search'] ) ? (string) $data['search'] : '';
		if ( '' === $search || strlen( $search ) > 2000 ) {
			return __( 'Enter something to search for (up to 2000 characters).', 'wpeasy-hub-connector' );
		}
		$replace = isset( $data['replace'] ) ? (string) $data['replace'] : '';
		if ( strlen( $replace ) > 2000 ) {
			return __( 'The replacement is too long (up to 2000 characters).', 'wpeasy-hub-connector' );
		}
		if ( $search === $replace ) {
			return __( 'The search and the replacement are the same.', 'wpeasy-hub-connector' );
		}
		return array( 'search' => $search, 'replace' => $replace, 'case' => ! isset( $data['case'] ) || ! empty( $data['case'] ), 'json' => ! isset( $data['json'] ) || ! empty( $data['json'] ), 'guid' => ! empty( $data['guid'] ) );
	}

	/** Own tables with one primary key column and at least one text column: name => [pk, [text columns]]. */
	private static function eligible() {
		global $wpdb;
		$prefix = $wpdb->base_prefix;
		$out    = array();
		$pks    = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = %s AND TABLE_NAME LIKE %s', 'PRIMARY', $wpdb->esc_like( $prefix ) . '%' ) ) as $r ) {
			$pks[ $r->t ][] = $r->c;
		}
		$cols = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) as $r ) {
			if ( in_array( strtolower( $r->d ), self::TEXT, true ) ) {
				$cols[ $r->t ][] = $r->c;
			}
		}
		foreach ( $cols as $t => $list ) {
			if ( $t === self::table() || ! isset( $pks[ $t ] ) || 1 !== count( $pks[ $t ] ) ) {
				continue;
			}
			$list = array_values( array_diff( $list, array( $pks[ $t ][0] ), array( 'user_pass', 'user_activation_key' ) ) );
			if ( $list ) {
				$out[ $t ] = array( $pks[ $t ][0], $list );
			}
		}
		ksort( $out );
		return $out;
	}

	private static function bt( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/** The things a value is searched for: the text itself, and the JSON-escaped form of an address. */
	private static function needles( $o ) {
		$n = array( array( $o['search'], $o['replace'] ) );
		if ( $o['json'] && false !== strpos( $o['search'], '/' ) ) {
			$n[] = array( str_replace( '/', '\\/', $o['search'] ), str_replace( '/', '\\/', $o['replace'] ) );
		}
		return $n;
	}

	/** SQL conditions for one table: some column holds the search text. Also the rows that are never touched. */
	private static function where( $table, $cols, $o ) {
		global $wpdb;
		$or = array();
		foreach ( $cols as $c ) {
			if ( 'guid' === $c && 'posts' === substr( $table, -5 ) && ! $o['guid'] ) {
				continue;
			}
			foreach ( self::needles( $o ) as $n ) {
				$or[] = self::bt( $c ) . ( $o['case'] ? ' LIKE BINARY ' : ' LIKE ' ) . $wpdb->prepare( '%s', '%' . $wpdb->esc_like( $n[0] ) . '%' );
			}
		}
		if ( ! $or ) {
			return null;
		}
		$sql = '(' . implode( ' OR ', $or ) . ')';
		if ( $table === $wpdb->options ) {
			$sql .= " AND option_name NOT LIKE 'wpeasy\\_hub%'";
		}
		if ( $table === $wpdb->usermeta ) {
			$sql .= " AND meta_key <> 'session_tokens'";
		}
		return $sql;
	}

	private static function scan( $o, $only ) {
		global $wpdb;
		$tables = self::eligible();
		if ( is_array( $only ) ) {
			$tables = array_intersect_key( $tables, array_flip( array_map( 'strval', $only ) ) );
		}
		$until = microtime( true ) + self::BUDGET;
		$out   = array();
		$left  = array();
		foreach ( $tables as $t => $def ) {
			if ( microtime( true ) >= $until ) {
				$left[] = $t;
				continue;
			}
			$w = self::where( $t, $def[1], $o );
			if ( null === $w ) {
				continue;
			}
			$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::bt( $t ) . ' WHERE ' . $w ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $n < 1 ) {
				continue;
			}
			$samples = array();
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::bt( $t ) . ' WHERE ' . $w . ' LIMIT 3', ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( $def[1] as $c ) {
					foreach ( self::needles( $o ) as $nd ) {
						$pos = $o['case'] ? strpos( (string) $row[ $c ], $nd[0] ) : stripos( (string) $row[ $c ], $nd[0] );
						if ( false !== $pos ) {
							$from      = max( 0, $pos - 30 );
							$samples[] = array( 'pk' => (string) $row[ $def[0] ], 'column' => $c, 'snippet' => ( $from ? '…' : '' ) . substr( (string) $row[ $c ], $from, 90 + strlen( $nd[0] ) ) );
							break 2;
						}
					}
				}
			}
			$out[] = array( 'table' => $t, 'rows' => $n, 'samples' => $samples );
		}
		return array( 'ok' => true, 'tables' => $out, 'left' => $left, 'considered' => count( $tables ) );
	}

	/** Begins a run: checks the table list and the expected number of rows against a fresh look, then saves the plan. */
	private static function start( $o, $names, $expect ) {
		global $wpdb;
		$eligible = self::eligible();
		$names    = array_values( array_intersect( array_map( 'strval', $names ), array_keys( $eligible ) ) );
		if ( ! $names ) {
			return array( 'ok' => false, 'code' => 'bad', 'message' => __( 'Choose at least one of this website\'s tables.', 'wpeasy-hub-connector' ) );
		}
		$total = 0;
		foreach ( $names as $t ) {
			$w = self::where( $t, $eligible[ $t ][1], $o );
			if ( $w ) {
				$total += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::bt( $t ) . ' WHERE ' . $w ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		if ( $total !== $expect ) {
			return array( 'ok' => false, 'code' => 'changed', 'message' => __( 'The website changed since the preview, so the number of rows is different now. Preview again.', 'wpeasy-hub-connector' ) );
		}
		self::ensure_table();
		self::forget_old();
		$token = bin2hex( random_bytes( 10 ) );
		update_option( self::STATE . $token, array( 'made' => time(), 'o' => $o, 'tables' => $names, 'last' => null, 'rows' => 0, 'cells' => 0, 'skipped' => array(), 'done' => false ), false );
		return array( 'ok' => true, 'token' => $token, 'total' => $total );
	}

	private static function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(24) NOT NULL,
			tbl varchar(64) NOT NULL,
			pk_col varchar(64) NOT NULL,
			pk_val varchar(191) NOT NULL,
			col varchar(64) NOT NULL,
			old_value longtext,
			new_md5 char(32) NOT NULL,
			made datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY token (token)
		) {$charset};" );
	}

	private static function forget_old() {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE made < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::STATE ) . '%' ) ) as $name ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$v = get_option( $name );
			if ( ! is_array( $v ) || ( isset( $v['made'] ) && $v['made'] < time() - self::KEEP ) ) {
				delete_option( $name );
			}
		}
	}

	/** One batch of a run. Returns progress; the Hub asks again until done. */
	private static function step( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{20}$/', $token ) ) {
			return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown run.' );
		}
		$st = get_option( self::STATE . $token );
		if ( ! is_array( $st ) ) {
			return array( 'ok' => false, 'code' => 'gone', 'message' => __( 'This run is no longer known on the website.', 'wpeasy-hub-connector' ) );
		}
		if ( ! empty( $st['done'] ) ) {
			return self::report( $st, $token );
		}
		$eligible = self::eligible();
		$o        = $st['o'];
		$until    = microtime( true ) + self::BUDGET;
		$j        = self::table();
		while ( $st['tables'] && microtime( true ) < $until ) {
			$t = $st['tables'][0];
			if ( ! isset( $eligible[ $t ] ) ) {
				array_shift( $st['tables'] );
				$st['last'] = null;
				continue;
			}
			list( $pk, $cols ) = $eligible[ $t ];
			$w = self::where( $t, $cols, $o );
			if ( null === $w ) {
				array_shift( $st['tables'] );
				$st['last'] = null;
				continue;
			}
			$sql = 'SELECT * FROM ' . self::bt( $t ) . ' WHERE ' . $w . ( null !== $st['last'] ? $wpdb->prepare( ' AND ' . self::bt( $pk ) . ' > %s', $st['last'] ) : '' ) . ' ORDER BY ' . self::bt( $pk ) . ' LIMIT ' . self::CHUNK; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $rows ) {
				array_shift( $st['tables'] );
				$st['last'] = null;
				continue;
			}
			foreach ( $rows as $row ) {
				$st['last'] = (string) $row[ $pk ];
				$set        = array();
				foreach ( $cols as $c ) {
					if ( 'guid' === $c && 'posts' === substr( $t, -5 ) && ! $o['guid'] ) {
						continue;
					}
					$old = $row[ $c ];
					if ( ! is_string( $old ) || '' === $old ) {
						continue;
					}
					$new = self::value( $old, $o, $why );
					if ( false === $new ) {
						if ( count( $st['skipped'] ) < 100 ) {
							$st['skipped'][] = array( 'table' => $t, 'pk' => (string) $row[ $pk ], 'column' => $c, 'why' => $why );
						}
						continue;
					}
					if ( $new === $old ) {
						continue;
					}
					$wpdb->insert( $j, array( 'token' => $token, 'tbl' => $t, 'pk_col' => $pk, 'pk_val' => (string) $row[ $pk ], 'col' => $c, 'old_value' => $old, 'new_md5' => md5( $new ), 'made' => gmdate( 'Y-m-d H:i:s' ) ) );
					if ( ! $wpdb->insert_id ) {
						return array( 'ok' => false, 'code' => 'journal', 'message' => __( 'Could not save the old values first, so the run stopped before changing this row.', 'wpeasy-hub-connector' ) );
					}
					$set[ $c ] = $new;
				}
				if ( $set ) {
					$wpdb->update( $t, $set, array( $pk => $row[ $pk ] ) );
					++$st['rows'];
					$st['cells'] += count( $set );
				}
				if ( microtime( true ) >= $until ) {
					break 2;
				}
			}
			if ( count( $rows ) < self::CHUNK ) {
				array_shift( $st['tables'] );
				$st['last'] = null;
			}
		}
		if ( ! $st['tables'] ) {
			$st['done'] = true;
			wp_cache_flush(); // the website's cached values (options, posts) are old now
		}
		update_option( self::STATE . $token, $st, false );
		return self::report( $st, $token );
	}

	private static function report( $st, $token ) {
		return array( 'ok' => true, 'token' => $token, 'done' => ! empty( $st['done'] ), 'rows' => (int) $st['rows'], 'cells' => (int) $st['cells'], 'tables_left' => count( $st['tables'] ), 'skipped' => $st['skipped'] );
	}

	private static function undo( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{20}$/', $token ) ) {
			return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown run.' );
		}
		$j     = self::table();
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$j} WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $total ) {
			return array( 'ok' => false, 'code' => 'gone', 'message' => __( 'The old values are no longer kept on the website (they are kept for 30 days), so this cannot be undone here. Restore a backup instead.', 'wpeasy-hub-connector' ) );
		}
		$until = microtime( true ) + self::BUDGET;
		$back  = 0;
		$moved = 0;
		while ( microtime( true ) < $until ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$j} WHERE token = %s ORDER BY id LIMIT 200", $token ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $r ) {
				// Put the old value back only if the value is still the one this run wrote.
				$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::bt( $r['tbl'] ) . ' SET ' . self::bt( $r['col'] ) . ' = %s WHERE ' . self::bt( $r['pk_col'] ) . ' = %s AND MD5(' . self::bt( $r['col'] ) . ') = %s', $r['old_value'], $r['pk_val'], $r['new_md5'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$n ? ++$back : ++$moved;
				$wpdb->delete( $j, array( 'id' => (int) $r['id'] ) );
			}
		}
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$j} WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $left ) {
			delete_option( self::STATE . $token );
			wp_cache_flush();
		}
		return array( 'ok' => true, 'restored' => $back, 'changed_since' => $moved, 'left' => $left );
	}

	/** The new value for one cell, or false (with the reason in $why) when it must be left alone. */
	public static function value( $old, $o, &$why = '' ) {
		$why = '';
		if ( self::looks_serialized( $old ) ) {
			$ok  = true;
			$i   = 0;
			$out = self::walk( $old, $i, $o, $ok, false );
			if ( ! $ok || $i !== strlen( $old ) ) {
				// Cannot be read as serialised data: only replace if it holds no match at all; otherwise leave it alone and say so.
				foreach ( self::needles( $o ) as $n ) {
					if ( false !== ( $o['case'] ? strpos( $old, $n[0] ) : stripos( $old, $n[0] ) ) ) {
						$why = __( 'serialised data that could not be read safely', 'wpeasy-hub-connector' );
						return false;
					}
				}
				return $old;
			}
			return $out;
		}
		return self::plain( $old, $o );
	}

	private static function plain( $s, $o ) {
		foreach ( self::needles( $o ) as $n ) {
			$s = $o['case'] ? str_replace( $n[0], $n[1], $s ) : str_ireplace( $n[0], $n[1], $s );
		}
		return $s;
	}

	private static function looks_serialized( $s ) {
		return (bool) preg_match( '/^(?:a:\d+:\{|O:\d+:"|s:\d+:"|i:-?\d+;|b:[01];|d:[-\d.eE+]+;|N;)/', $s ) && ( '}' === substr( $s, -1 ) || ';' === substr( $s, -1 ) );
	}

	/** Reads one serialised value starting at $i and returns it written out again with the replacement made inside its strings (and their lengths put right). */
	private static function walk( $s, &$i, $o, &$ok, $is_key ) {
		if ( ! $ok || $i >= strlen( $s ) ) {
			$ok = false;
			return '';
		}
		$c = $s[ $i ];
		if ( 'N' === $c ) {
			if ( ';' !== substr( $s, $i + 1, 1 ) ) {
				$ok = false;
				return '';
			}
			$i += 2;
			return 'N;';
		}
		if ( 'b' === $c || 'i' === $c || 'd' === $c || 'r' === $c || 'R' === $c ) {
			if ( ! preg_match( '/\G[bidrR]:[-+\d.eEINFA]+;/', $s, $m, 0, $i ) ) {
				$ok = false;
				return '';
			}
			$i += strlen( $m[0] );
			return $m[0];
		}
		if ( 'E' === $c ) {
			if ( ! preg_match( '/\GE:\d+:"[^"]*";/', $s, $m, 0, $i ) ) {
				$ok = false;
				return '';
			}
			$i += strlen( $m[0] );
			return $m[0];
		}
		if ( 's' === $c ) {
			if ( ! preg_match( '/\Gs:(\d+):"/', $s, $m, 0, $i ) ) {
				$ok = false;
				return '';
			}
			$len   = (int) $m[1];
			$start = $i + strlen( $m[0] );
			if ( $start + $len + 2 > strlen( $s ) || '";' !== substr( $s, $start + $len, 2 ) ) {
				$ok = false;
				return '';
			}
			$str  = substr( $s, $start, $len );
			$i    = $start + $len + 2;
			if ( $is_key ) {
				return 's:' . $len . ':"' . $str . '";'; // array keys and property names are left as they are
			}
			$new = self::value( $str, $o, $why ); // a string may itself hold serialised data
			if ( false === $new ) {
				$ok = false;
				return '';
			}
			return 's:' . strlen( $new ) . ':"' . $new . '";';
		}
		if ( 'a' === $c ) {
			if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $i ) ) {
				$ok = false;
				return '';
			}
			$n   = (int) $m[1];
			$i  += strlen( $m[0] );
			$out = 'a:' . $n . ':{';
			for ( $k = 0; $k < $n * 2 && $ok; $k++ ) {
				$out .= self::walk( $s, $i, $o, $ok, 0 === $k % 2 );
			}
			if ( ! $ok || '}' !== substr( $s, $i, 1 ) ) {
				$ok = false;
				return '';
			}
			++$i;
			return $out . '}';
		}
		if ( 'O' === $c ) {
			if ( ! preg_match( '/\GO:(\d+):"([^"]*)":(\d+):\{/', $s, $m, 0, $i ) || strlen( $m[2] ) !== (int) $m[1] ) {
				$ok = false;
				return '';
			}
			$n   = (int) $m[3];
			$i  += strlen( $m[0] );
			$out = $m[0];
			for ( $k = 0; $k < $n * 2 && $ok; $k++ ) {
				$out .= self::walk( $s, $i, $o, $ok, 0 === $k % 2 );
			}
			if ( ! $ok || '}' !== substr( $s, $i, 1 ) ) {
				$ok = false;
				return '';
			}
			++$i;
			return $out . '}';
		}
		$ok = false; // C: (custom serialisation) and anything else: not read
		return '';
	}
}
