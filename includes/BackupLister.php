<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Lists folders for a backup, a few at a time, with no memory between requests: the Hub asks for some folders and is told what is in each (name, whether it is a folder or a
 * file, and a file's size and time). The Hub walks the tree itself, so a website with a hundred thousand files needs no list kept here and any request can be repeated. Symlinks,
 * excluded paths and names that are not valid text are left out (and counted).
 */
class BackupLister {
	/**
	 * @param string[] $paths   Folders to list, as the Hub names them ("" is the top: wp-content and the single files).
	 * @param int      $max     Stop after about this many entries (the folders not reached are handed back in "rest").
	 * @param int      $seconds Stop after about this many seconds, for the same reason.
	 * @return array{dirs: array<string, ?array>, rest: string[], skipped: int}
	 */
	public static function dirs( array $paths, $max = 20000, $seconds = 20, $core = false ) {
		$out     = array();
		$rest    = array();
		$count   = 0;
		$skipped = 0;
		$t0      = microtime( true );
		foreach ( $paths as $path ) {
			$path = trim( str_replace( '\\', '/', (string) $path ), '/' );
			if ( $count >= $max || ( microtime( true ) - $t0 ) > $seconds ) {
				$rest[] = $path;
				continue;
			}
			$list = self::one( $path, $skipped, $core );
			$out[ $path ] = $list;
			$count       += is_array( $list ) ? count( $list ) : 0;
		}
		return array( 'dirs' => $out, 'rest' => $rest, 'skipped' => $skipped );
	}

	/** The entries of one folder, or null when it is not there (or not allowed). */
	private static function one( $path, &$skipped, $core = false ) {
		if ( '' === $path ) {
			$list = array();
			foreach ( BackupPaths::roots( $core ) as $name => $abs ) {
				$list[] = is_dir( $abs ) ? array( 'n' => $name, 't' => 'd', 'p' => self::mode( $abs ) ) : array( 'n' => $name, 't' => 'f', 's' => (int) @filesize( $abs ), 'm' => (int) @filemtime( $abs ), 'p' => self::mode( $abs ) );
			}
			return $list;
		}
		$abs = BackupPaths::resolve( $path, $core );
		if ( null === $abs || ! is_dir( $abs ) ) {
			return null;
		}
		$h = @opendir( $abs );
		if ( ! $h ) {
			return null;
		}
		$list = array();
		while ( false !== ( $name = readdir( $h ) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$full = $abs . '/' . $name;
			if ( is_link( $full ) || BackupPaths::excluded( $path . '/' . $name ) ) {
				continue;
			}
			if ( ! self::valid_text( $name ) ) {
				$skipped++;
				continue;
			}
			if ( is_dir( $full ) ) {
				$list[] = array( 'n' => $name, 't' => 'd', 'p' => self::mode( $full ) );
			} elseif ( is_file( $full ) ) {
				$list[] = array( 'n' => $name, 't' => 'f', 's' => (int) @filesize( $full ), 'm' => (int) @filemtime( $full ), 'p' => self::mode( $full ) );
			}
		}
		closedir( $h );
		usort( $list, function ( $a, $b ) {
			return strcmp( $a['n'], $b['n'] );
		} );
		return $list;
	}

	/** The permission bits (like 0755), so a restore can put them back. Owner and group are not recorded: they differ from host to host and a plugin cannot set them. */
	private static function mode( $path ) {
		$perms = @fileperms( $path );
		return false === $perms ? null : ( $perms & 0777 );
	}

	private static function valid_text( $s ) {
		return 1 === preg_match( '//u', $s );
	}
}
