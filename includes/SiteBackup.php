<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * The Hub backing this website up: five signed requests, each doing one small step and remembering nothing, so any of them can be repeated and a long backup is simply many short
 * requests. The Hub walks the website's folders (backup_dirs), reads the files it needs (backup_files, binary frames), and saves the database table by table (backup_tables,
 * backup_rows). Only what BackupPaths allows can be read and only SELECT and SHOW reach the database. Everything stays off unless the website's administrator leaves
 * "Let the Hub back up this website" ticked (Settings > Hub Connector).
 */
class SiteBackup {
	const ACTIONS = array( 'backup_info', 'backup_dirs', 'backup_files', 'backup_tables', 'backup_rows' );

	public static function allowed() {
		return (bool) Store::get( 'allow_backup', true );
	}

	/**
	 * @param string $action One of ACTIONS.
	 * @param array  $data   The request the Hub signed.
	 * @return array|string  An array is answered as JSON; a string is the binary frames of backup_files.
	 */
	public static function handle( $action, array $data ) {
		global $wp_version;
		switch ( $action ) {
			case 'backup_info':
				return array(
					'ok'             => true,
					'wp_version'     => isset( $wp_version ) ? $wp_version : get_bloginfo( 'version' ),
					'php_version'    => PHP_VERSION,
					'plugin_version' => VERSION,
					'multisite'      => is_multisite(),
					'prefix'         => ( new WpBackupDb() )->prefix(),
					'roots'          => array_keys( BackupPaths::roots() ),
					'max_response'   => BackupReader::MAX_RESPONSE,
				);
			case 'backup_dirs':
				$paths = array();
				foreach ( ( isset( $data['paths'] ) && is_array( $data['paths'] ) ? array_slice( $data['paths'], 0, 200 ) : array() ) as $p ) {
					$paths[] = (string) $p;
				}
				return array( 'ok' => true ) + BackupLister::dirs( $paths, 20000, 20 );
			case 'backup_files':
				$items = array();
				foreach ( ( isset( $data['items'] ) && is_array( $data['items'] ) ? array_slice( $data['items'], 0, 200 ) : array() ) as $i ) {
					if ( is_array( $i ) ) {
						$items[] = array( 'p' => isset( $i['p'] ) ? (string) $i['p'] : '', 'o' => isset( $i['o'] ) ? (int) $i['o'] : 0, 'l' => isset( $i['l'] ) ? (int) $i['l'] : 4194304 );
					}
				}
				return BackupReader::build( $items, isset( $data['max'] ) ? (int) $data['max'] : 4194304 );
			case 'backup_tables':
				$dumper = new BackupDumper( new WpBackupDb() );
				return array( 'ok' => true, 'prefix' => ( new WpBackupDb() )->prefix(), 'tables' => $dumper->tables() );
			case 'backup_rows':
				$cursor = null;
				if ( isset( $data['cursor'] ) && is_array( $data['cursor'] ) ) {
					if ( array_key_exists( 'k', $data['cursor'] ) && ( is_string( $data['cursor']['k'] ) || is_int( $data['cursor']['k'] ) || is_float( $data['cursor']['k'] ) ) ) {
						$cursor = array( 'k' => (string) $data['cursor']['k'] );
					} elseif ( isset( $data['cursor']['o'] ) ) {
						$cursor = array( 'o' => max( 0, (int) $data['cursor']['o'] ) );
					}
				}
				try {
					$page = ( new BackupDumper( new WpBackupDb() ) )->page( isset( $data['table'] ) ? (string) $data['table'] : '', $cursor, isset( $data['rows'] ) ? (int) $data['rows'] : 500, isset( $data['max'] ) ? (int) $data['max'] : 1500000 );
				} catch ( \InvalidArgumentException $e ) {
					return array( 'ok' => false, 'code' => 'unknown_table', 'message' => __( 'That table is not one of this website\'s tables.', 'wpeasy-hub-connector' ) );
				}
				return array( 'ok' => true ) + $page;
		}
		return array( 'ok' => false, 'code' => 'unknown' );
	}
}
