<?php
// Removing the plugin removes everything it stored, including the secret.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wpeasy_hub_connector' );
wp_clear_scheduled_hook( 'wpeasy_hub_connector_report' );
