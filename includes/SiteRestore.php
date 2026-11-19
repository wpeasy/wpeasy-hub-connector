<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * The Hub putting a backup back on this website: signed requests, each doing one small step. Start (maintenance mode, own settings kept aside), folders, files in pieces, database tables as SQL,
 * WordPress core, and finish. The Hub takes a safety copy of the website first and checks the site afterwards. Switched off with "Let the Hub restore this website" (Settings > Hub Connector).
 */
class SiteRestore {
	const ACTIONS = array( 'restore_begin', 'restore_dirs', 'restore_files', 'restore_sql', 'restore_core', 'restore_finish' );

	public static function allowed() {
		return (bool) Store::get( 'allow_restore', true );
	}

	public static function handle( $action, array $data ) {
		$core = ! empty( $data['core'] );
		if ( 'restore_begin' !== $action && 'restore_finish' !== $action ) {
			RestoreState::maintenance( true ); // renew it: WordPress drops it after ten minutes without a request
		}
		switch ( $action ) {
			case 'restore_begin':
				return RestoreState::begin();
			case 'restore_dirs':
				return RestoreFiles::dirs( isset( $data['dirs'] ) && is_array( $data['dirs'] ) ? array_slice( $data['dirs'], 0, 2000 ) : array(), $core );
			case 'restore_files':
				return RestoreFiles::apply( isset( $data['items'] ) && is_array( $data['items'] ) ? array_slice( $data['items'], 0, 500 ) : array(), $core );
			case 'restore_sql':
				return RestoreDb::run( new WpSqlRunner(), isset( $data['table'] ) ? $data['table'] : '', isset( $data['sql'] ) ? $data['sql'] : '' );
			case 'restore_core':
				return RestoreCore::run( isset( $data['version'] ) ? $data['version'] : '' );
			case 'restore_finish':
				return RestoreState::finish();
		}
		return array( 'ok' => false, 'message' => 'Unknown restore step.' );
	}
}
