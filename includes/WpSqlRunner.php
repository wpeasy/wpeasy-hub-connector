<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

class WpSqlRunner implements SqlRunner {
	public function exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function exec( $sql ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a statement from the Hub's own signed restore, checked by RestoreDb
		$err = (string) $wpdb->last_error;
		$wpdb->suppress_errors( false );
		return '' === $err ? true : substr( $err, 0, 300 );
	}
}
