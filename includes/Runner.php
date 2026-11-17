<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Updates one thing (a plugin, a theme, or WordPress itself) when the Hub asks, using WordPress's own updater. A plugin or theme is backed up first; afterwards
 * the website is checked, and if it is broken the backup is put back, all inside this one request (so it still works when the updated code breaks the site).
 * WordPress itself cannot be put back, so it is only checked and reported.
 */
class Runner {
	/** @return array{ok: bool, status: string, message: string, from?: string, to?: string, backup?: bool} status: updated, reverted, failed */
	public static function run( $kind, $id, $wanted ) {
		@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		ignore_user_abort( true );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return self::fail( __( 'File changes are switched off on this website (DISALLOW_FILE_MODS), so it cannot be updated from the Hub.', 'wpeasy-hub-connector' ) );
		}
		if ( 'direct' !== get_filesystem_method() ) {
			return self::fail( __( 'WordPress cannot write files directly on this website (it asks for FTP details), so it cannot be updated from the Hub.', 'wpeasy-hub-connector' ) );
		}
		if ( 'plugin' === $kind ) {
			return self::plugin( $id, $wanted );
		}
		if ( 'theme' === $kind ) {
			return self::theme( $id, $wanted );
		}
		if ( 'core' === $kind ) {
			return self::core( $wanted );
		}
		return self::fail( __( 'That is not something the Hub can update.', 'wpeasy-hub-connector' ) );
	}

	private static function fail( $message, $extra = array() ) {
		return array_merge( array( 'ok' => false, 'status' => 'failed', 'message' => $message ), $extra );
	}

	/** Why an upgrade did not work, in words, or null when it did. (WordPress returns true, an array, false or a WP_Error; the skin keeps its own error in ->result.) */
	private static function why_not( $result, $skin ) {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		if ( isset( $skin->result ) && is_wp_error( $skin->result ) ) {
			return $skin->result->get_error_message();
		}
		if ( ! $result ) {
			return __( 'WordPress could not update it.', 'wpeasy-hub-connector' );
		}
		return null;
	}

	/** True when WordPress has no real download address for an update: a premium plugin or theme whose licence or subscription has lapsed (or was never connected) offers a placeholder instead. */
	public static function no_download( $package ) {
		return ! is_string( $package ) || ! preg_match( '#^https?://#i', $package );
	}

	private static function no_download_message() {
		return __( 'The update cannot be downloaded: this is a premium plugin or theme whose licence or subscription is not active (WordPress has no download address for it). Renew or connect it with its seller, then try again. Nothing was changed.', 'wpeasy-hub-connector' );
	}

	private static function skin() {
		return new \Automatic_Upgrader_Skin();
	}

	private static function plugin( $file, $wanted ) {
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $file ] ) ) {
			return self::fail( __( 'That plugin is not on this website any more.', 'wpeasy-hub-connector' ) );
		}
		wp_update_plugins();
		$offers = get_site_transient( 'update_plugins' );
		if ( empty( $offers->response[ $file ] ) ) {
			return self::fail( __( 'No update is offered for this plugin any more (it may have been updated already).', 'wpeasy-hub-connector' ) );
		}
		if ( self::no_download( isset( $offers->response[ $file ]->package ) ? $offers->response[ $file ]->package : '' ) ) {
			return self::fail( self::no_download_message() ); // before anything is touched: no backup, nothing to put back
		}
		$from   = $plugins[ $file ]['Version'];
		$active = is_plugin_active( $file );
		$net    = is_multisite() && is_plugin_active_for_network( $file );
		$dir    = dirname( $file );
		$source = '.' === $dir ? WP_PLUGIN_DIR . '/' . $file : WP_PLUGIN_DIR . '/' . $dir;

		$backup = Backup::make( 'plugin', '.' === $dir ? basename( $file, '.php' ) : $dir, $source, $from );
		if ( is_wp_error( $backup ) ) {
			return self::fail( $backup->get_error_message() );
		}

		// Anything that goes wrong from here (an error from WordPress, a PHP fatal in the updated code, a failed check) puts the backup back.
		try {
			$skin   = self::skin();
			$result = ( new \Plugin_Upgrader( $skin ) )->upgrade( $file, array( 'clear_update_cache' => true ) );
			wp_clean_plugins_cache( true );
			$why = self::why_not( $result, $skin );
			if ( null !== $why ) {
				return self::put_back( 'plugin', $backup, $source, $file, $active, $net, $why, $from );
			}
			if ( $active && ! is_plugin_active( $file ) ) {
				activate_plugin( $file, '', $net, true );
			}
			$health = Health::check();
			if ( ! $health['ok'] ) {
				return self::put_back( 'plugin', $backup, $source, $file, $active, $net, $health['message'], $from );
			}
		} catch ( \Throwable $e ) {
			return self::put_back( 'plugin', $backup, $source, $file, $active, $net, $e->getMessage(), $from );
		}
		$now = get_plugins();
		return array( 'ok' => true, 'status' => 'updated', 'message' => '', 'from' => $from, 'to' => isset( $now[ $file ] ) ? $now[ $file ]['Version'] : $wanted, 'backup' => true );
	}

	private static function theme( $slug, $wanted ) {
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return self::fail( __( 'That theme is not on this website any more.', 'wpeasy-hub-connector' ) );
		}
		wp_update_themes();
		$offers = get_site_transient( 'update_themes' );
		if ( empty( $offers->response[ $slug ] ) ) {
			return self::fail( __( 'No update is offered for this theme any more (it may have been updated already).', 'wpeasy-hub-connector' ) );
		}
		if ( self::no_download( isset( $offers->response[ $slug ]['package'] ) ? $offers->response[ $slug ]['package'] : '' ) ) {
			return self::fail( self::no_download_message() );
		}
		$from   = (string) $theme->get( 'Version' );
		$source = get_theme_root( $slug ) . '/' . $slug;
		$backup = Backup::make( 'theme', $slug, $source, $from );
		if ( is_wp_error( $backup ) ) {
			return self::fail( $backup->get_error_message() );
		}

		try {
			$skin   = self::skin();
			$result = ( new \Theme_Upgrader( $skin ) )->upgrade( $slug, array( 'clear_update_cache' => true ) );
			wp_clean_themes_cache( true );
			$why = self::why_not( $result, $skin );
			if ( null !== $why ) {
				return self::put_back( 'theme', $backup, $source, null, false, false, $why, $from );
			}
			$health = Health::check();
			if ( ! $health['ok'] ) {
				return self::put_back( 'theme', $backup, $source, null, false, false, $health['message'], $from );
			}
		} catch ( \Throwable $e ) {
			return self::put_back( 'theme', $backup, $source, null, false, false, $e->getMessage(), $from );
		}
		return array( 'ok' => true, 'status' => 'updated', 'message' => '', 'from' => $from, 'to' => (string) wp_get_theme( $slug )->get( 'Version' ), 'backup' => true );
	}

	/** WordPress itself: no backup is possible (it is the whole site), so this only updates, checks and reports. */
	private static function core( $wanted ) {
		global $wp_version;
		$from = $wp_version;
		wp_version_check( array(), true );
		$offer = null;
		foreach ( (array) get_core_updates() as $u ) {
			if ( isset( $u->response ) && 'upgrade' === $u->response ) {
				$offer = $u;
				break;
			}
		}
		if ( ! $offer ) {
			return self::fail( __( 'No WordPress update is offered any more (it may have been updated already).', 'wpeasy-hub-connector' ) );
		}
		$result = ( new \Core_Upgrader( self::skin() ) )->upgrade( $offer );
		if ( is_wp_error( $result ) ) {
			return self::fail( $result->get_error_message(), array( 'from' => $from ) );
		}
		$health = Health::check();
		if ( ! $health['ok'] ) {
			return self::fail( $health['message'] . ' ' . __( 'WordPress itself cannot be put back automatically: check the website now.', 'wpeasy-hub-connector' ), array( 'from' => $from, 'to' => is_string( $result ) ? $result : $wanted ) );
		}
		return array( 'ok' => true, 'status' => 'updated', 'message' => '', 'from' => $from, 'to' => is_string( $result ) ? $result : $wanted, 'backup' => false );
	}

	/** The update broke it (or failed half way): put the backup back, check again, and say plainly what happened. */
	private static function put_back( $kind, $backup, $source, $file, $active, $net, $reason, $from ) {
		$restored = Backup::restore( $backup, $source );
		if ( 'plugin' === $kind ) {
			wp_clean_plugins_cache( true );
			if ( $active && $file && ! is_plugin_active( $file ) ) {
				activate_plugin( $file, '', $net, true );
			}
		} else {
			wp_clean_themes_cache( true );
		}
		if ( is_wp_error( $restored ) ) {
			return self::fail( $reason . ' ' . sprintf( /* translators: %s: why */ __( 'Putting the old version back failed (%s): check the website now. The backup is in wp-content/uploads/wpeasy-hub-backups.', 'wpeasy-hub-connector' ), $restored->get_error_message() ), array( 'from' => $from, 'backup' => true ) );
		}
		$health = Health::check();
		if ( ! $health['ok'] ) {
			return self::fail( $reason . ' ' . __( 'The old version was put back, but the website still has a problem: check it now.', 'wpeasy-hub-connector' ), array( 'from' => $from, 'backup' => true ) );
		}
		return array( 'ok' => false, 'status' => 'reverted', 'message' => $reason, 'from' => $from, 'to' => $from, 'backup' => true );
	}
}
