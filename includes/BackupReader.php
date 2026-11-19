<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the Hub the contents of files, as a stream of frames it can read exactly: "WPEHB1" and a newline, then for each file a line of JSON (path, where in the file, how many bytes
 * follow, the file's size and time, and whether this is the end of the file), the bytes, and a newline, and at the end a line {"end":true}. Big files are asked for in pieces
 * (offset and length), and a response stops once it has sent about `max` bytes, so the Hub just asks again for what is left. Only paths BackupPaths allows can be read.
 */
class BackupReader {
	const MAGIC       = "WPEHB1\n";
	const MAX_RESPONSE = 8388608; // 8 MiB

	/**
	 * @param array<int, array{p?: string, o?: int, l?: int}> $items What to read: a path, an offset and a length.
	 * @param int                                             $max   About how many bytes of file data to send in this response.
	 */
	public static function build( array $items, $max = 4194304, $core = false ) {
		$left   = max( 1, min( (int) $max, self::MAX_RESPONSE ) );
		$out    = self::MAGIC;
		$served = 0;
		foreach ( array_slice( $items, 0, 200 ) as $item ) {
			$path   = isset( $item['p'] ) ? (string) $item['p'] : '';
			$offset = isset( $item['o'] ) ? max( 0, (int) $item['o'] ) : 0;
			$length = isset( $item['l'] ) ? max( 0, (int) $item['l'] ) : $left;
			$abs    = BackupPaths::resolve( $path, $core );
			if ( null === $abs || ! is_file( $abs ) ) {
				$out .= wp_json_encode_safe( array( 'p' => $path, 'o' => $offset, 'n' => 0, 'err' => 'missing' ) ) . "\n\n";
				$served++;
				continue;
			}
			if ( $left <= 0 && $served > 0 ) {
				break; // out of room: the Hub asks again for the rest
			}
			$size  = (int) @filesize( $abs );
			$mtime = (int) @filemtime( $abs );
			$want  = max( 0, min( $length, $size - $offset, $left ) );
			$data  = '';
			if ( $want > 0 ) {
				$h = @fopen( $abs, 'rb' );
				if ( ! $h ) {
					$out .= wp_json_encode_safe( array( 'p' => $path, 'o' => $offset, 'n' => 0, 'err' => 'unreadable' ) ) . "\n\n";
					$served++;
					continue;
				}
				fseek( $h, $offset );
				while ( strlen( $data ) < $want && ! feof( $h ) ) {
					$chunk = fread( $h, min( 1048576, $want - strlen( $data ) ) );
					if ( false === $chunk || '' === $chunk ) {
						break;
					}
					$data .= $chunk;
				}
				fclose( $h );
			}
			$n    = strlen( $data );
			$out .= wp_json_encode_safe( array( 'p' => $path, 'o' => $offset, 'n' => $n, 'size' => $size, 'mtime' => $mtime, 'eof' => ( $offset + $n >= $size ) ) ) . "\n" . $data . "\n";
			$left -= $n;
			$served++;
		}
		return $out . wp_json_encode_safe( array( 'end' => true, 'served' => $served ) ) . "\n";
	}
}

/** JSON for a frame header (WordPress's own wp_json_encode when it is there, so the tests need no WordPress). */
function wp_json_encode_safe( $data ) {
	return function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data );
}
