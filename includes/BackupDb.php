<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** What the database dump needs from the database: run a query and get the rows back, and say which tables belong to this website. Wrapped so the dump can be tested without WordPress. */
interface BackupDb {
	/** @return array<int, array<string, ?string>> Every row, columns by name, values as text (or null). */
	public function rows( $sql );

	/** The table prefix of this website ("wp_"). */
	public function prefix();
}
