<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Removes one plugin or theme that is NOT in use when the Hub asks, using WordPress's own delete. It refuses anything in use, the Hub Connector itself, and a theme that is the
 * parent of the theme in use. The plugin or theme is backed up (the same backup the updates make) before anything is deleted, and an empty backup stops the removal.
 * Note: deleting a plugin through WordPress runs its own uninstall routine, which some plugins use to delete their data; the backup is of the files only.
 */
class Remover {
	/** @return array{ok: bool, status: string, message: string, from?: string, backup?: bool} status: removed or failed */
	public static function run( $kind, $id ) {
		@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		ignore_user_abort( true );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return self::fail( __( 'File changes are switched off on this website (DISALLOW_FILE_MODS), so nothing can be removed from the Hub.', 'wpeasy-hub-connector' ) );
		}
		if ( 'direct' !== get_filesystem_method() ) {
			return self::fail( __( 'WordPress cannot write files directly on this website (it asks for FTP details), so nothing can be removed from the Hub.', 'wpeasy-hub-connector' ) );
		}
		if ( 'plugin' === $kind ) {
			return self::plugin( $id );
		}
		if ( 'theme' === $kind ) {
			return self::theme( $id );
		}
		return self::fail( __( 'That is not something the Hub can remove.', 'wpeasy-hub-connector' ) );
	}

	private static function fail( $message ) {
		return array( 'ok' => false, 'status' => 'failed', 'message' => $message );
	}

	private static function plugin( $file ) {
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $file ] ) ) {
			return array( 'ok' => true, 'status' => 'removed', 'message' => __( 'It was already removed.', 'wpeasy-hub-connector' ) );
		}
		if ( plugin_basename( dirname( __DIR__ ) . '/wpeasy-hub-connector.php' ) === $file ) {
			return self::fail( __( 'The Hub Connector cannot be removed from the Hub: it is how the Hub talks to this website.', 'wpeasy-hub-connector' ) );
		}
		if ( is_plugin_active( $file ) || is_plugin_active_for_network( $file ) ) {
			return self::fail( __( 'It is in use on this website, so it was not removed. Only plugins that are not in use can be removed.', 'wpeasy-hub-connector' ) );
		}
		$version = $plugins[ $file ]['Version'];
		$dir     = dirname( $file );
		$source  = '.' === $dir ? WP_PLUGIN_DIR . '/' . $file : WP_PLUGIN_DIR . '/' . $dir;
		$backup  = Backup::make( 'plugin', '.' === $dir ? basename( $file, '.php' ) : $dir, $source, $version );
		if ( is_wp_error( $backup ) ) {
			return self::fail( $backup->get_error_message() );
		}
		$r = delete_plugins( array( $file ) );
		if ( is_wp_error( $r ) ) {
			return self::fail( $r->get_error_message() );
		}
		if ( true !== $r ) {
			return self::fail( __( 'WordPress could not remove it.', 'wpeasy-hub-connector' ) );
		}
		wp_clean_plugins_cache( true );
		return array( 'ok' => true, 'status' => 'removed', 'message' => '', 'from' => $version, 'backup' => true );
	}

	private static function theme( $slug ) {
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return array( 'ok' => true, 'status' => 'removed', 'message' => __( 'It was already removed.', 'wpeasy-hub-connector' ) );
		}
		$active = wp_get_theme();
		if ( $slug === $active->get_stylesheet() || $slug === $active->get_template() ) {
			return self::fail( __( 'It is in use on this website (or is the parent of the theme in use), so it was not removed.', 'wpeasy-hub-connector' ) );
		}
		$version = (string) $theme->get( 'Version' );
		$backup  = Backup::make( 'theme', $slug, get_theme_root( $slug ) . '/' . $slug, $version );
		if ( is_wp_error( $backup ) ) {
			return self::fail( $backup->get_error_message() );
		}
		$r = delete_theme( $slug );
		if ( is_wp_error( $r ) ) {
			return self::fail( $r->get_error_message() );
		}
		if ( true !== $r ) {
			return self::fail( __( 'WordPress could not remove it.', 'wpeasy-hub-connector' ) );
		}
		wp_clean_themes_cache( true );
		return array( 'ok' => true, 'status' => 'removed', 'message' => '', 'from' => $version, 'backup' => true );
	}
}
