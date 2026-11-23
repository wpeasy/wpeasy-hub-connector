<?php
// Removing the plugin removes everything it stored, including the secret.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
// The zipped backups of plugins and themes the Hub updated go too (they are only useful while the connection is). The plugin itself is not loaded now, so what the backup code needs is loaded here.
if ( ! defined( 'WPEasy\\HubConnector\\OPTION' ) ) {
	define( 'WPEasy\\HubConnector\\OPTION', 'wpeasy_hub_connector' );
}
require_once __DIR__ . '/includes/Store.php';
require_once __DIR__ . '/includes/Backup.php';
\WPEasy\HubConnector\Backup::purge();
delete_option( 'wpeasy_hub_connector' );
wp_clear_scheduled_hook( 'wpeasy_hub_connector_report' );
wp_clear_scheduled_hook( 'wpeasy_hub_connector_backups' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpeasy_hub_used_' ) . '%' ) );

$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'wpeasy_hub_sr`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the journal of search and replace runs
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpeasy_hub_sr_' ) . '%' ) );
