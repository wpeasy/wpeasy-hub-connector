<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Housekeeping the Hub can ask for: count what is only clutter, then remove the kinds it is told to. Only clutter is touched:
 * old revisions, auto-drafts never saved, things already in the trash, spam, expired temporary data and details left behind by things already deleted.
 * Posts and comments are removed with WordPress's own delete (so counts, hooks and attached details are right). Work is done in batches that stop
 * after about 20 seconds; the answer says how many are left and the Hub asks again. Removed content is NOT kept: the Hub tells people to take a backup first.
 */
class Cleanup {
	const ACTIONS = array( 'cleanup' );
	const BUDGET  = 20; // seconds per request
	const BATCH   = 500;

	/** Every kind: a label the Hub shows is on the Hub side; here only the code. */
	const KINDS = array( 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'orphan_postmeta', 'orphan_commentmeta', 'orphan_termmeta' );

	public static function handle( $action, $data ) {
		$op = isset( $data['op'] ) ? (string) $data['op'] : '';
		if ( 'scan' === $op ) {
			return array( 'ok' => true, 'kinds' => self::scan() );
		}
		if ( 'run' === $op ) {
			$kinds = array_values( array_intersect( self::KINDS, isset( $data['kinds'] ) ? (array) $data['kinds'] : array() ) );
			if ( ! $kinds ) {
				return array( 'ok' => false, 'code' => 'bad', 'message' => __( 'Choose what to clean.', 'wpeasy-hub-connector' ) );
			}
			return self::run( $kinds );
		}
		return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown request.' );
	}

	private static function counts() {
		global $wpdb;
		$p = $wpdb->posts;
		$c = $wpdb->comments;
		$o = $wpdb->options;
		$now = time();
		$q = function ( $sql, $args = array() ) use ( $wpdb ) {
			return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		};
		return array(
			'revisions'          => $q( "SELECT COUNT(*) FROM {$p} WHERE post_type = 'revision'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'auto_drafts'        => $q( "SELECT COUNT(*) FROM {$p} WHERE post_status = 'auto-draft' AND post_modified < %s", array( gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'trashed_posts'      => $q( "SELECT COUNT(*) FROM {$p} WHERE post_status = 'trash'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'spam_comments'      => $q( "SELECT COUNT(*) FROM {$c} WHERE comment_approved = 'spam'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'trashed_comments'   => $q( "SELECT COUNT(*) FROM {$c} WHERE comment_approved = 'trash'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'expired_transients' => $q( "SELECT COUNT(*) FROM {$o} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d", array( $wpdb->esc_like( '_transient_timeout_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_' ) . '%', $now ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'orphan_postmeta'    => $q( "SELECT COUNT(*) FROM {$wpdb->postmeta} m LEFT JOIN {$p} x ON x.ID = m.post_id WHERE x.ID IS NULL" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'orphan_commentmeta' => $q( "SELECT COUNT(*) FROM {$wpdb->commentmeta} m LEFT JOIN {$c} x ON x.comment_ID = m.comment_id WHERE x.comment_ID IS NULL" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'orphan_termmeta'    => $q( "SELECT COUNT(*) FROM {$wpdb->termmeta} m LEFT JOIN {$wpdb->terms} x ON x.term_id = m.term_id WHERE x.term_id IS NULL" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	private static function scan() {
		$n   = self::counts();
		$out = array();
		foreach ( self::KINDS as $k ) {
			$out[] = array( 'kind' => $k, 'count' => (int) $n[ $k ] );
		}
		return $out;
	}

	private static function ids( $sql, $args = array() ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $args ? $wpdb->prepare( $sql, $args ) : $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function delete_ids( $table, $col, $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return 0;
		}
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$col} IN ({$ph})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/** One batch of one kind. Returns how many were removed. */
	private static function batch( $kind ) {
		global $wpdb;
		$p = $wpdb->posts;
		$c = $wpdb->comments;
		$o = $wpdb->options;
		$n = 0;
		switch ( $kind ) {
			case 'revisions':
			case 'auto_drafts':
			case 'trashed_posts':
				if ( 'revisions' === $kind ) {
					$ids = self::ids( "SELECT ID FROM {$p} WHERE post_type = 'revision' ORDER BY ID LIMIT %d", array( self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				} elseif ( 'auto_drafts' === $kind ) {
					$ids = self::ids( "SELECT ID FROM {$p} WHERE post_status = 'auto-draft' AND post_modified < %s ORDER BY ID LIMIT %d", array( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ), self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				} else {
					$ids = self::ids( "SELECT ID FROM {$p} WHERE post_status = 'trash' ORDER BY ID LIMIT %d", array( self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				foreach ( $ids as $id ) {
					if ( wp_delete_post( $id, true ) ) {
						++$n;
					}
				}
				break;
			case 'spam_comments':
			case 'trashed_comments':
				$ids = self::ids( "SELECT comment_ID FROM {$c} WHERE comment_approved = %s ORDER BY comment_ID LIMIT %d", array( 'spam_comments' === $kind ? 'spam' : 'trash', self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $ids as $id ) {
					if ( wp_delete_comment( $id, true ) ) {
						++$n;
					}
				}
				break;
			case 'expired_transients':
				$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$o} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d LIMIT %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_' ) . '%', time(), self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $names as $timeout ) {
					$value = preg_replace( '/^(_site)?_transient_timeout_/', '$1_transient_', $timeout );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$o} WHERE option_name IN (%s, %s)", $timeout, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					++$n;
				}
				break;
			case 'orphan_postmeta':
				$n = self::delete_ids( $wpdb->postmeta, 'meta_id', self::ids( "SELECT m.meta_id FROM {$wpdb->postmeta} m LEFT JOIN {$p} x ON x.ID = m.post_id WHERE x.ID IS NULL LIMIT %d", array( self::BATCH * 10 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				break;
			case 'orphan_commentmeta':
				$n = self::delete_ids( $wpdb->commentmeta, 'meta_id', self::ids( "SELECT m.meta_id FROM {$wpdb->commentmeta} m LEFT JOIN {$c} x ON x.comment_ID = m.comment_id WHERE x.comment_ID IS NULL LIMIT %d", array( self::BATCH * 10 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				break;
			case 'orphan_termmeta':
				$n = self::delete_ids( $wpdb->termmeta, 'meta_id', self::ids( "SELECT m.meta_id FROM {$wpdb->termmeta} m LEFT JOIN {$wpdb->terms} x ON x.term_id = m.term_id WHERE x.term_id IS NULL LIMIT %d", array( self::BATCH * 10 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				break;
		}
		return $n;
	}

	private static function run( $kinds ) {
		$until   = microtime( true ) + self::BUDGET;
		$deleted = array_fill_keys( $kinds, 0 );
		$stalled = array();
		// Posts first (removing them leaves details behind that the orphan kinds then sweep), one batch per kind in turn so no kind starves the others.
		usort( $kinds, function ( $a, $b ) { return array_search( $a, self::KINDS, true ) <=> array_search( $b, self::KINDS, true ); } );
		$active = $kinds;
		while ( $active && microtime( true ) < $until ) {
			foreach ( $active as $i => $k ) {
				$n = self::batch( $k );
				$deleted[ $k ] += $n;
				if ( 0 === $n ) {
					unset( $active[ $i ] );
				}
				if ( microtime( true ) >= $until ) {
					break;
				}
			}
		}
		$left = self::counts();
		$rows = array();
		foreach ( $kinds as $k ) {
			$rows[] = array( 'kind' => $k, 'deleted' => (int) $deleted[ $k ], 'left' => (int) $left[ $k ] );
		}
		return array( 'ok' => true, 'kinds' => $rows, 'done' => ! array_filter( $rows, function ( $r ) { return $r['left'] > 0 && $r['deleted'] > 0; } ) );
	}
}
