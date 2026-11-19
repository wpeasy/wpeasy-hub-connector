<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** Cuts a block of SQL into statements at the semicolons that are not inside a quoted string or a quoted name, skipping comment lines. The dump the Hub restores is made by BackupDumper, so only that shape is needed. */
class SqlSplitter {
	/**
	 * @param string $sql One or more statements, each ending with a semicolon.
	 * @return array{statements: string[], rest: string} `rest` is what is left after the last semicolon (blank when the block was complete).
	 */
	public static function split( $sql ) {
		$out   = array();
		$len   = strlen( $sql );
		$start = 0;
		$quote = null;
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $sql[ $i ];
			if ( null !== $quote ) {
				if ( '\\' === $c && "'" === $quote ) {
					$i++;
				} elseif ( $c === $quote ) {
					if ( "'" === $quote && $i + 1 < $len && "'" === $sql[ $i + 1 ] ) {
						$i++;
					} else {
						$quote = null;
					}
				}
				continue;
			}
			if ( "'" === $c || '`' === $c || '"' === $c ) {
				$quote = $c;
			} elseif ( '-' === $c && $i + 1 < $len && '-' === $sql[ $i + 1 ] && ( 0 === $i || "\n" === $sql[ $i - 1 ] ) ) {
				$nl = strpos( $sql, "\n", $i );
				$i  = false === $nl ? $len : $nl; // a comment line is skipped to its end
			} elseif ( ';' === $c ) {
				$stmt = self::clean( substr( $sql, $start, $i - $start ) );
				if ( '' !== $stmt ) {
					$out[] = $stmt;
				}
				$start = $i + 1;
			}
		}
		$rest = self::clean( substr( $sql, $start ) );
		return array( 'statements' => $out, 'rest' => $rest );
	}

	/** Removes comment lines at the start and trims. */
	private static function clean( $stmt ) {
		$stmt = ltrim( $stmt );
		while ( 0 === strpos( $stmt, '--' ) ) {
			$nl   = strpos( $stmt, "\n" );
			$stmt = false === $nl ? '' : ltrim( substr( $stmt, $nl + 1 ) );
		}
		return rtrim( $stmt );
	}
}
