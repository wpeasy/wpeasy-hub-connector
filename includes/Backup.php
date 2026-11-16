<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * A copy of a plugin or theme folder, made just before the Hub updates it, so it can be put back if the website breaks. Kept in the uploads folder under
 * wpeasy-hub-backups (closed to the web), the newest few per item, and deleted after a while.
 */
class Backup {
	const DIR        = 'wpeasy-hub-backups';
	const KEEP       = 3;
	const KEEP_DAYS  = 60;

	/** The backups folder (made, and closed to visitors, if it is not there yet). @return string */
	public static function base() {
		$u   = wp_upload_dir();
		$dir = trailingslashit( $u['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	private static function fs() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	/**
	 * Copies a plugin or theme into the backups folder.
	 *
	 * @param string $kind   plugin or theme.
	 * @param string $slug   the folder name (or file name for a one-file plugin).
	 * @param string $source the folder (or file) to copy.
	 * @param string $version the version being backed up.
	 * @return string|\WP_Error the backup's path
	 */
	public static function make( $kind, $slug, $source, $version ) {
		$fs = self::fs();
		if ( ! $fs ) {
			return new \WP_Error( 'wpeasy_hub_fs', __( 'WordPress could not open the file system to make a backup.', 'wpeasy-hub-connector' ) );
		}
		$name = sanitize_file_name( $kind . '-' . $slug . '-' . $version . '-' . gmdate( 'Ymd-His' ) );
		$dest = self::base() . '/' . $name;
		wp_mkdir_p( $dest );
		if ( is_dir( $source ) ) {
			$r = copy_dir( $source, $dest );
		} else {
			$r = $fs->copy( $source, $dest . '/' . basename( $source ), true ) ? true : new \WP_Error( 'wpeasy_hub_copy', __( 'Could not copy the file.', 'wpeasy-hub-connector' ) );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		// A backup with nothing in it is not a backup.
		$files = glob( $dest . '/*' );
		if ( empty( $files ) ) {
			return new \WP_Error( 'wpeasy_hub_empty', __( 'The backup came out empty, so the update was not started.', 'wpeasy-hub-connector' ) );
		}
		self::prune( $kind, $slug );
		return $dest;
	}

	/** Puts a backup back where the plugin or theme was (the broken copy is removed first). @return true|\WP_Error */
	public static function restore( $backup, $target ) {
		$fs = self::fs();
		if ( ! $fs || ! is_dir( $backup ) ) {
			return new \WP_Error( 'wpeasy_hub_restore', __( 'The backup could not be found.', 'wpeasy-hub-connector' ) );
		}
		if ( file_exists( $target ) ) {
			$fs->delete( $target, true );
		}
		if ( is_dir( $target ) || ! empty( glob( $target ) ) ) {
			return new \WP_Error( 'wpeasy_hub_restore', __( 'The updated copy could not be removed.', 'wpeasy-hub-connector' ) );
		}
		// A one-file plugin was backed up as a file inside the backup folder.
		$entries = glob( $backup . '/*' );
		if ( 1 === count( $entries ) && is_file( $entries[0] ) && ! is_dir( $target ) && '.php' === substr( $target, -4 ) ) {
			return $fs->copy( $entries[0], $target, true ) ? true : new \WP_Error( 'wpeasy_hub_restore', __( 'The file could not be put back.', 'wpeasy-hub-connector' ) );
		}
		wp_mkdir_p( $target );
		return copy_dir( $backup, $target );
	}

	/** Keeps the newest few backups of one item, and nothing older than 60 days. */
	public static function prune( $kind, $slug ) {
		$fs   = self::fs();
		$mine = glob( self::base() . '/' . sanitize_file_name( $kind . '-' . $slug . '-' ) . '*', GLOB_ONLYDIR );
		if ( ! $fs || ! $mine ) {
			return;
		}
		rsort( $mine ); // names end with a timestamp: newest first
		foreach ( $mine as $i => $path ) {
			if ( $i >= self::KEEP ) {
				$fs->delete( $path, true );
			}
		}
		foreach ( (array) glob( self::base() . '/*', GLOB_ONLYDIR ) as $path ) {
			if ( filemtime( $path ) < time() - self::KEEP_DAYS * DAY_IN_SECONDS ) {
				$fs->delete( $path, true );
			}
		}
	}
}
