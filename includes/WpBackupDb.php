<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** The website's own database, through WordPress ($wpdb). Read only: the dump only ever sends SELECT and SHOW. */
class WpBackupDb implements BackupDb {
	public function rows( $sql ) {
		global $wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the dump builds its own quoted statements from names it has checked against the table list
		if ( null === $rows && $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error );
		}
		return is_array( $rows ) ? $rows : array();
	}

	public function prefix() {
		global $wpdb;
		return $wpdb->base_prefix;
	}
}
