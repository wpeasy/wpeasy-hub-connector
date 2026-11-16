<?php
// Removing the plugin removes everything it stored, including the secret.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
delete_option( 'wpeasy_hub_connector' );
wp_clear_scheduled_hook( 'wpeasy_hub_connector_report' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpeasy_hub_used_' ) . '%' ) );
