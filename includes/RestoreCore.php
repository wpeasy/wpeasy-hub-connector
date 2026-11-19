<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** Reinstalls WordPress core at a given version with WordPress's own core upgrader (it downloads the release from wordpress.org and replaces wp-admin, wp-includes and the loose core files; content is untouched). */
class RestoreCore {
	/** @return array{ok: bool, message: string, version?: string} */
	public static function run( $version ) {
		$version = (string) $version;
		if ( 1 !== preg_match( '/^\d{1,2}\.\d{1,2}(\.\d{1,2})?$/', $version ) ) {
			return array( 'ok' => false, 'message' => 'That is not a WordPress version number.' );
		}
		global $wp_version;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$url   = 'https://downloads.wordpress.org/release/wordpress-' . $version . '.zip';
		$offer = (object) array(
			'response'      => $version === $wp_version ? 'reinstall' : 'upgrade',
			'download'      => $url,
			'locale'        => 'en_US',
			'packages'      => (object) array( 'full' => $url, 'no_content' => '', 'new_bundled' => '', 'partial' => '', 'rollback' => '' ),
			'current'       => $version,
			'version'       => $version,
			'php_version'   => PHP_VERSION,
			'mysql_version' => '5.0',
			'new_bundled'   => $wp_version,
			'partial_version' => '',
		);
		$upgrader = new \Core_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $offer, array( 'pre_check_md5' => false, 'attempt_rollback' => false, 'do_rollback' => false ) );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'message' => substr( $result->get_error_message(), 0, 300 ) );
		}
		return array( 'ok' => true, 'message' => '', 'version' => (string) $result );
	}
}
