<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** Runs one statement against the database. Wrapped so the restore can be tested without WordPress. */
interface SqlRunner {
	/** @return true|string true when it ran, else the database's own error text. */
	public function exec( $sql );
}
