<?php
/**
 * Plugin Name:       WP Easy Hub Connector
 * Description:       Pairs this website with your WP Easy Hub. Reports the WordPress and PHP versions and any updates waiting, once a day.
 * Version:           0.2.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WP Easy
 * Author URI:        https://wpeasy.au
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpeasy-hub-connector
 */

namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.2';
const OPTION  = 'wpeasy_hub_connector';
const CRON    = 'wpeasy_hub_connector_report';

require_once __DIR__ . '/includes/Signature.php';
require_once __DIR__ . '/includes/Store.php';
require_once __DIR__ . '/includes/Client.php';
require_once __DIR__ . '/includes/Report.php';
require_once __DIR__ . '/includes/LoginToken.php';
require_once __DIR__ . '/includes/Login.php';
require_once __DIR__ . '/includes/Remote.php';
require_once __DIR__ . '/includes/Updater.php';
require_once __DIR__ . '/includes/Settings.php';

add_action( CRON, array( Client::class, 'send_report' ) );
Settings::init();
Updater::init();
Login::init();
Remote::init();

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( CRON ) ) {
		wp_schedule_event( time() + 300, 'daily', CRON );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( CRON );
} );

// If the plugin was updated in place without being re-activated, make sure the daily report is still scheduled.
add_action( 'init', function () {
	if ( Store::connected() && ! wp_next_scheduled( CRON ) ) {
		wp_schedule_event( time() + 300, 'daily', CRON );
	}
} );
