<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * A copy of a plugin or theme, made just before the Hub updates or removes it, so it can be put back if the website breaks.
 *
 * Each backup is ONE ZIP FILE, never a folder of loose files: a zip is not run as code, so an old, possibly vulnerable copy can never be
 * called through the web even if the folder were open to visitors. The zips are kept outside the web folder when the host allows it (the folder
 * above the one WordPress is in); otherwise in the uploads folder under an unguessable name with deny rules. Only the newest two per item are
 * kept, nothing older than 14 days, and a daily job clears what is left (and converts or removes the loose-file backups older versions made).
 */
class Backup {
	const LEGACY_DIR = 'wpeasy-hub-backups';
	const KEEP       = 2;
	const KEEP_DAYS  = 14;

	/** True when a path is the same as, or inside, another. */
	private static function inside( $path, $root ) {
		$path = rtrim( wp_normalize_path( (string) $path ), '/' ) . '/';
		$root = rtrim( wp_normalize_path( (string) $root ), '/' ) . '/';
		return '/' !== $root && 0 === strpos( $path, $root );
	}

	/**
	 * Where the zips are kept: outside the web folder if possible, else an unguessable folder in uploads. The choice is remembered, so a job running
	 * with no web request (WP-Cron on the command line) uses the same place. The folder is made, and closed to visitors, if it is not there.
	 *
	 * @return string
	 */
	public static function base() {
		$saved = (string) Store::get( 'backup_dir' );
		if ( '' !== $saved && is_dir( $saved ) && is_writable( $saved ) ) {
			return $saved;
		}
		$dir = self::outside_dir();
		if ( null === $dir ) {
			return self::uploads_dir(); // not a web request: it cannot be told which folder is public, so decide later and remember nothing
		}
		if ( '' === $dir ) {
			$dir = self::uploads_dir();
		}
		Store::set( array( 'backup_dir' => $dir ) );
		return $dir;
	}

	/** The folder above WordPress, if it is writable and is not inside the document root (so no address can reach it). @return string|null empty when not possible, null when it cannot be told */
	private static function outside_dir() {
		$root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : false; // phpcs:ignore WordPress.Security
		if ( ! $root ) {
			return null;
		}
		$parent = realpath( dirname( untrailingslashit( wp_normalize_path( ABSPATH ) ) ) );
		if ( ! $parent || self::inside( $parent, $root ) || ! is_writable( $parent ) ) {
			return '';
		}
		$dir = $parent . '/wpeasy-hub-backups';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		return is_writable( $dir ) ? $dir : '';
	}

	/** The fallback inside uploads: an unguessable folder name, and deny rules for the common web servers. */
	private static function uploads_dir() {
		$token = (string) Store::get( 'backup_token' );
		if ( '' === $token ) {
			$token = strtolower( wp_generate_password( 16, false, false ) );
			Store::set( array( 'backup_token' => $token ) );
		}
		$u   = wp_upload_dir();
		$dir = trailingslashit( $u['basedir'] ) . self::LEGACY_DIR . '-' . $token;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/web.config', "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/** Whether the zips are kept outside the web folder. */
	public static function is_outside() {
		$u = wp_upload_dir();
		return ! self::inside( self::base(), $u['basedir'] );
	}

	private static function fs() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	/** Zips a folder (its contents at the top of the zip) or one file. @return true|\WP_Error */
	private static function zip( $source, $zip_path ) {
		$source = untrailingslashit( $source );
		if ( class_exists( '\ZipArchive' ) ) {
			$z = new \ZipArchive();
			if ( true !== $z->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return new \WP_Error( 'wpeasy_hub_zip', __( 'The backup file could not be made.', 'wpeasy-hub-connector' ) );
			}
			if ( is_dir( $source ) ) {
				$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
				foreach ( $it as $file ) {
					if ( $file->isLink() ) {
						continue; // never follow a link out of the folder
					}
					$rel = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( wp_normalize_path( $source ) ) ), '/' );
					if ( $file->isDir() ) {
						$z->addEmptyDir( $rel );
					} else {
						$z->addFile( $file->getPathname(), $rel );
					}
				}
			} else {
				$z->addFile( $source, basename( $source ) );
			}
			return $z->close() ? true : new \WP_Error( 'wpeasy_hub_zip', __( 'The backup file could not be written.', 'wpeasy-hub-connector' ) );
		}
		// No zip extension on this server: WordPress carries its own (slower) zip class.
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$z = new \PclZip( $zip_path );
		$r = $z->create( $source, PCLZIP_OPT_REMOVE_PATH, is_dir( $source ) ? $source : dirname( $source ) );
		return 0 === $r ? new \WP_Error( 'wpeasy_hub_zip', __( 'The backup file could not be made.', 'wpeasy-hub-connector' ) ) : true;
	}

	/** The names inside a zip. @return string[] */
	private static function names( $zip_path ) {
		if ( class_exists( '\ZipArchive' ) ) {
			$z = new \ZipArchive();
			if ( true !== $z->open( $zip_path ) ) {
				return array();
			}
			$n = array();
			for ( $i = 0; $i < $z->numFiles; $i++ ) {
				$n[] = $z->getNameIndex( $i );
			}
			$z->close();
			return $n;
		}
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$list = ( new \PclZip( $zip_path ) )->listContent();
		return is_array( $list ) ? wp_list_pluck( $list, 'filename' ) : array();
	}

	/**
	 * Zips a plugin or theme into the backups folder.
	 *
	 * @param string $kind    plugin or theme.
	 * @param string $slug    the folder name (or file name for a one-file plugin).
	 * @param string $source  the folder (or file) to copy.
	 * @param string $version the version being backed up.
	 * @return string|\WP_Error the zip file's path
	 */
	public static function make( $kind, $slug, $source, $version ) {
		$dir = self::base();
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new \WP_Error( 'wpeasy_hub_fs', __( 'WordPress could not open a folder to keep the backup in.', 'wpeasy-hub-connector' ) );
		}
		$name = sanitize_file_name( $kind . '-' . $slug . '-' . $version . '-' . gmdate( 'Ymd-His' ) ) . '.zip';
		$dest = $dir . '/' . $name;
		$r    = self::zip( $source, $dest );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		// A backup with nothing in it is not a backup.
		if ( ! is_file( $dest ) || filesize( $dest ) < 100 || ! self::names( $dest ) ) {
			wp_delete_file( $dest );
			return new \WP_Error( 'wpeasy_hub_empty', __( 'The backup came out empty, so the update was not started.', 'wpeasy-hub-connector' ) );
		}
		self::prune( $kind, $slug );
		return $dest;
	}

	/** Puts a backup back where the plugin or theme was (the broken copy is removed first). @return true|\WP_Error */
	public static function restore( $backup, $target ) {
		$fs = self::fs();
		if ( ! $fs || ! is_file( $backup ) ) {
			return new \WP_Error( 'wpeasy_hub_restore', __( 'The backup could not be found.', 'wpeasy-hub-connector' ) );
		}
		if ( file_exists( $target ) ) {
			$fs->delete( $target, true );
		}
		if ( is_dir( $target ) || ! empty( glob( $target ) ) ) {
			return new \WP_Error( 'wpeasy_hub_restore', __( 'The updated copy could not be removed.', 'wpeasy-hub-connector' ) );
		}
		$names = self::names( $backup );
		// A one-file plugin was backed up as that single file.
		if ( 1 === count( $names ) && false === strpos( $names[0], '/' ) && '.php' === substr( $target, -4 ) ) {
			$tmp = trailingslashit( get_temp_dir() ) . 'wpeasy-hub-restore-' . wp_generate_password( 8, false );
			wp_mkdir_p( $tmp );
			$r = unzip_file( $backup, $tmp );
			if ( is_wp_error( $r ) ) {
				$fs->delete( $tmp, true );
				return $r;
			}
			$ok = $fs->copy( $tmp . '/' . $names[0], $target, true );
			$fs->delete( $tmp, true );
			return $ok ? true : new \WP_Error( 'wpeasy_hub_restore', __( 'The file could not be put back.', 'wpeasy-hub-connector' ) );
		}
		wp_mkdir_p( $target );
		return unzip_file( $backup, $target );
	}

	/** Keeps the newest few backups of one item, and nothing older than the limit. */
	public static function prune( $kind, $slug ) {
		$prefix = sanitize_file_name( $kind . '-' . $slug ) . '-'; // (sanitize_file_name trims a trailing dash, so it is added after)
		$mine   = array();
		foreach ( (array) glob( self::base() . '/*.zip' ) as $path ) {
			$base = basename( $path );
			if ( 0 === strpos( $base, $prefix ) && preg_match( '/^[0-9v]/', substr( $base, strlen( $prefix ) ) ) ) {
				$mine[] = $path;
			}
		}
		rsort( $mine ); // names end with a timestamp: newest first
		foreach ( $mine as $i => $path ) {
			if ( $i >= self::KEEP ) {
				wp_delete_file( $path );
			}
		}
		self::sweep();
	}

	/** Deletes every backup zip older than the limit, whatever it was a backup of (so a removed plugin's copies go too). */
	public static function sweep() {
		foreach ( (array) glob( self::base() . '/*.zip' ) as $path ) {
			if ( filemtime( $path ) < time() - self::KEEP_DAYS * DAY_IN_SECONDS ) {
				wp_delete_file( $path );
			}
		}
	}

	/** The loose-file backup folders older versions made in uploads: recent ones become zips in the safe place, the rest are deleted, and the old folder goes. */
	public static function migrate_legacy() {
		$u   = wp_upload_dir();
		$old = trailingslashit( $u['basedir'] ) . self::LEGACY_DIR;
		if ( ! is_dir( $old ) ) {
			return 0;
		}
		$fs = self::fs();
		$n  = 0;
		foreach ( (array) glob( $old . '/*', GLOB_ONLYDIR ) as $folder ) {
			if ( filemtime( $folder ) >= time() - self::KEEP_DAYS * DAY_IN_SECONDS && is_dir( self::base() ) ) {
				$zip = self::base() . '/' . basename( $folder ) . '.zip';
				if ( ! is_file( $zip ) && true === self::zip( $folder, $zip ) ) {
					touch( $zip, filemtime( $folder ) );
				}
			}
			if ( $fs ) {
				$fs->delete( $folder, true );
			}
			$n++;
		}
		if ( $fs ) {
			$fs->delete( $old, true ); // the folder and the deny files in it: nothing loose is left behind
		}
		return $n;
	}

	/** Keeps only the newest few backups of every item (not only the one being backed up now). */
	public static function prune_all() {
		$groups = array();
		foreach ( (array) glob( self::base() . '/*.zip' ) as $path ) {
			$item              = preg_replace( '/-[^-]+-\d{8}-\d{6}\.zip$/', '', basename( $path ) );
			$groups[ $item ][] = $path;
		}
		foreach ( $groups as $paths ) {
			rsort( $paths ); // the names end with a timestamp: newest first
			foreach ( array_slice( $paths, self::KEEP ) as $path ) {
				wp_delete_file( $path );
			}
		}
	}

	/** The daily job (and the first run after an update): convert old loose backups, then clear what is past its time or beyond the newest few. */
	public static function maintain() {
		self::migrate_legacy();
		self::prune_all();
		self::sweep();
	}

	/** What is kept, for the report: how many backups, how big, and where (the Hub shows it, and any loose files still left in uploads). */
	public static function stats() {
		$files = (array) glob( self::base() . '/*.zip' );
		$bytes = 0;
		foreach ( $files as $f ) {
			$bytes += (int) filesize( $f );
		}
		$u = wp_upload_dir();
		return array(
			'count'   => count( $files ),
			'bytes'   => $bytes,
			'outside' => self::is_outside(),
			'loose'   => is_dir( trailingslashit( $u['basedir'] ) . self::LEGACY_DIR ),
		);
	}

	/** Deletes every backup and the folders for them (when the plugin is removed). */
	public static function purge() {
		$fs = self::fs();
		if ( ! $fs ) {
			return;
		}
		$u = wp_upload_dir();
		foreach ( array( (string) Store::get( 'backup_dir' ), trailingslashit( $u['basedir'] ) . self::LEGACY_DIR ) as $dir ) {
			if ( '' !== $dir && is_dir( $dir ) && false !== strpos( basename( $dir ), 'wpeasy-hub-backups' ) ) {
				$fs->delete( $dir, true );
			}
		}
	}
}
