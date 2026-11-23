<?php
/**
 * Plugin Name:       WP Easy Hub Connector
 * Description:       Pairs this website with your WP Easy Hub. Reports the WordPress and PHP versions and any updates waiting, once a day.
 * Version:           0.9.1
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

const VERSION = '0.9.1';
const OPTION  = 'wpeasy_hub_connector';
const CRON    = 'wpeasy_hub_connector_report';
const BACKUPS = 'wpeasy_hub_connector_backups';

require_once __DIR__ . '/includes/Signature.php';
require_once __DIR__ . '/includes/Store.php';
require_once __DIR__ . '/includes/Client.php';
require_once __DIR__ . '/includes/Report.php';
require_once __DIR__ . '/includes/LoginToken.php';
require_once __DIR__ . '/includes/Login.php';
require_once __DIR__ . '/includes/Backup.php';
require_once __DIR__ . '/includes/Health.php';
require_once __DIR__ . '/includes/Runner.php';
require_once __DIR__ . '/includes/Remover.php';
require_once __DIR__ . '/includes/DbScan.php';
require_once __DIR__ . '/includes/DbFix.php';
require_once __DIR__ . '/includes/LinkEditor.php';
require_once __DIR__ . '/includes/BackupPaths.php';
require_once __DIR__ . '/includes/BackupLister.php';
require_once __DIR__ . '/includes/BackupReader.php';
require_once __DIR__ . '/includes/BackupDb.php';
require_once __DIR__ . '/includes/WpBackupDb.php';
require_once __DIR__ . '/includes/BackupDumper.php';
require_once __DIR__ . '/includes/SiteBackup.php';
require_once __DIR__ . '/includes/SqlSplitter.php';
require_once __DIR__ . '/includes/SqlRunner.php';
require_once __DIR__ . '/includes/WpSqlRunner.php';
require_once __DIR__ . '/includes/RestoreGuard.php';
require_once __DIR__ . '/includes/RestoreFiles.php';
require_once __DIR__ . '/includes/RestoreDb.php';
require_once __DIR__ . '/includes/RestoreState.php';
require_once __DIR__ . '/includes/RestoreCore.php';
require_once __DIR__ . '/includes/SiteRestore.php';
require_once __DIR__ . '/includes/Remote.php';
add_action( 'plugins_loaded', array( 'WPEasy\\HubConnector\\RestoreState', 'guard' ), 0 );
require_once __DIR__ . '/includes/Updater.php';
require_once __DIR__ . '/includes/Settings.php';

add_action( CRON, array( Client::class, 'send_report' ) );
add_action( BACKUPS, array( Backup::class, 'maintain' ) );
Settings::init();
Updater::init();
Login::init();
Remote::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/Cli.php';
	\WP_CLI::add_command( 'wpeasy-hub', Cli::class );
}

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( CRON ) ) {
		wp_schedule_event( time() + 300, 'daily', CRON );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( CRON );
	wp_clear_scheduled_hook( BACKUPS );
} );

// If the plugin was updated in place without being re-activated, make sure the daily report is still scheduled.
add_action( 'init', function () {
	if ( Store::connected() && ! wp_next_scheduled( CRON ) ) {
		wp_schedule_event( time() + 300, 'daily', CRON );
	}
	// Backups: a daily clean-up, and one straight after this version arrives (it turns the loose-file backups older versions left in uploads into zips, or removes them).
	if ( Store::connected() && ! wp_next_scheduled( BACKUPS ) ) {
		wp_schedule_event( time() + 120, 'daily', BACKUPS );
	}
	if ( Store::connected() && Store::get( 'backups_version' ) !== VERSION && ! wp_next_scheduled( BACKUPS . '_now' ) ) {
		wp_schedule_single_event( time() + 20, BACKUPS . '_now' );
	}
	// Just updated (or never reported this version): tell the Hub soon, so it learns the new version without waiting for the daily report.
	if ( Store::connected() && Store::get( 'reported_version' ) !== VERSION && ! wp_next_scheduled( CRON . '_now' ) ) {
		wp_schedule_single_event( time() + 15, CRON . '_now' );
	}
} );
add_action( CRON . '_now', array( Client::class, 'send_report' ) );
add_action( BACKUPS . '_now', function () {
	Backup::maintain();
	Store::set( array( 'backups_version' => VERSION ) );
} );
