<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Fixes the Hub can ask for after a database scan. Only one so far: a person whose role list is saved more than once keeps one copy.
 *
 * Safety rules: the plan is worked out again on this website at the moment of the request (the Hub's earlier preview is never trusted);
 * only copies that are exactly the same as the one kept are removed (a person whose copies differ is left alone and listed); every removed row is
 * saved on this website first so the fix can be undone; nothing else in the database is touched.
 */
class DbFix {
	const ACTIONS = array( 'db_fix' );
	const KEEP    = 30 * DAY_IN_SECONDS; // how long a fix can be undone
	const OPTION  = 'wpeasy_hub_dbfix_';
	const LIMIT   = 5000;

	public static function handle( $action, $data ) {
		$op = isset( $data['op'] ) ? (string) $data['op'] : '';
		if ( 'preview' === $op ) {
			return array( 'ok' => true, 'plan' => self::summary( self::plan() ) );
		}
		if ( 'apply' === $op ) {
			return self::apply( isset( $data['expect'] ) ? (int) $data['expect'] : -1 );
		}
		if ( 'undo' === $op ) {
			return self::undo( isset( $data['token'] ) ? (string) $data['token'] : '' );
		}
		return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown request.' );
	}

	/** The role entries to remove: for each person with the role list saved more than once, the copies after the first (oldest) one, when all copies are the same. */
	private static function plan() {
		global $wpdb;
		$meta  = $wpdb->usermeta;
		$users = $wpdb->users;
		$keys  = array( $wpdb->prefix . 'capabilities', $wpdb->prefix . 'user_level' );
		$out   = array( 'people' => array(), 'remove' => array(), 'differs' => array(), 'checked' => 0 );
		foreach ( $keys as $key ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$meta} WHERE meta_key = %s AND user_id IN (SELECT user_id FROM (SELECT user_id FROM {$meta} WHERE meta_key = %s GROUP BY user_id HAVING COUNT(*) > 1) d) ORDER BY user_id, umeta_id LIMIT %d", $key, $key, self::LIMIT ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$by   = array();
			foreach ( $rows as $r ) {
				$by[ (int) $r->user_id ][] = $r;
			}
			foreach ( $by as $uid => $copies ) {
				++$out['checked'];
				$norm = array();
				foreach ( $copies as $c ) {
					$norm[] = self::normal( $c->meta_value );
				}
				$same = 1 === count( array_unique( $norm ) );
				if ( ! isset( $out['people'][ $uid ] ) ) {
					$out['people'][ $uid ] = array( 'id' => $uid, 'copies' => 0, 'remove' => 0, 'differs' => false, 'keys' => array() );
				}
				$out['people'][ $uid ]['copies']  = max( $out['people'][ $uid ]['copies'], count( $copies ) );
				$out['people'][ $uid ]['keys'][] = substr( $key, strlen( $wpdb->prefix ) );
				if ( ! $same ) {
					$out['people'][ $uid ]['differs'] = true;
					continue;
				}
				foreach ( array_slice( $copies, 1 ) as $c ) {
					$out['remove'][ (int) $c->umeta_id ] = array( 'umeta_id' => (int) $c->umeta_id, 'user_id' => (int) $c->user_id, 'meta_key' => $c->meta_key, 'meta_value' => $c->meta_value );
					++$out['people'][ $uid ]['remove'];
				}
			}
		}
		// Names for the people involved.
		if ( $out['people'] ) {
			$ids = array_keys( $out['people'] );
			$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_login, display_name FROM {$users} WHERE ID IN ({$ph})", $ids ) ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$out['people'][ (int) $u->ID ]['login'] = (string) $u->user_login;
				$out['people'][ (int) $u->ID ]['name']  = (string) $u->display_name;
			}
		}
		return $out;
	}

	/** Two copies are the same when they hold the same list of roles, whatever the order. */
	private static function normal( $value ) {
		$v = is_string( $value ) ? @unserialize( $value, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( is_array( $v ) ) {
			ksort( $v );
			return 'a:' . wp_json_encode( $v );
		}
		return 's:' . (string) $value;
	}

	private static function summary( $plan ) {
		$people = array_values( array_filter( $plan['people'], function ( $p ) { return $p['remove'] > 0 || $p['differs']; } ) );
		usort( $people, function ( $a, $b ) { return $b['remove'] <=> $a['remove']; } );
		return array(
			'rows'         => count( $plan['remove'] ),
			'people'       => count( array_filter( $plan['people'], function ( $p ) { return $p['remove'] > 0; } ) ),
			'differs'      => count( array_filter( $plan['people'], function ( $p ) { return $p['differs']; } ) ),
			'list'         => array_slice( $people, 0, 50 ),
			'capped'       => count( $plan['remove'] ) >= self::LIMIT,
		);
	}

	private static function apply( $expect ) {
		global $wpdb;
		$plan = self::plan();
		$n    = count( $plan['remove'] );
		if ( 0 === $n ) {
			return array( 'ok' => false, 'code' => 'nothing', 'message' => __( 'There is nothing to remove.', 'wpeasy-hub-connector' ) );
		}
		if ( $expect !== $n ) {
			return array( 'ok' => false, 'code' => 'changed', 'message' => __( 'The website\'s users changed since the preview. Preview again.', 'wpeasy-hub-connector' ) );
		}
		self::forget_old();
		// Save what is about to go, then remove it. If saving fails nothing is removed.
		$token = bin2hex( random_bytes( 10 ) );
		$saved = update_option( self::OPTION . $token, array( 'made' => time(), 'rows' => array_values( $plan['remove'] ), 'undone' => false ), false );
		if ( ! $saved || ! get_option( self::OPTION . $token ) ) {
			return array( 'ok' => false, 'code' => 'save', 'message' => __( 'Could not save the removed entries first, so nothing was changed.', 'wpeasy-hub-connector' ) );
		}
		$ids = array_keys( $plan['remove'] );
		$wpdb->query( 'START TRANSACTION' );
		$removed = 0;
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$ph       = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$removed += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE umeta_id IN ({$ph})", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		if ( $removed !== $n ) {
			$wpdb->query( 'ROLLBACK' );
			delete_option( self::OPTION . $token );
			return array( 'ok' => false, 'code' => 'partial', 'message' => __( 'Not every entry could be removed, so nothing was changed.', 'wpeasy-hub-connector' ) );
		}
		$wpdb->query( 'COMMIT' );
		foreach ( array_unique( wp_list_pluck( $plan['remove'], 'user_id' ) ) as $uid ) {
			clean_user_cache( (int) $uid );
		}
		$left = self::summary( self::plan() );
		return array( 'ok' => true, 'token' => $token, 'removed' => $removed, 'people' => count( array_unique( wp_list_pluck( $plan['remove'], 'user_id' ) ) ), 'left' => $left['rows'] );
	}

	private static function undo( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{20}$/', $token ) ) {
			return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown fix.' );
		}
		$fix = get_option( self::OPTION . $token );
		if ( ! is_array( $fix ) || empty( $fix['rows'] ) ) {
			return array( 'ok' => false, 'code' => 'gone', 'message' => __( 'The removed entries are no longer kept on the website (they are kept for 30 days), so this cannot be undone here. Restore a backup instead.', 'wpeasy-hub-connector' ) );
		}
		if ( ! empty( $fix['undone'] ) ) {
			return array( 'ok' => false, 'code' => 'undone', 'message' => __( 'This fix was already undone.', 'wpeasy-hub-connector' ) );
		}
		$put = 0;
		foreach ( $fix['rows'] as $r ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE umeta_id = %d", (int) $r['umeta_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $exists ) {
				continue;
			}
			$ok = $wpdb->insert( $wpdb->usermeta, array( 'umeta_id' => (int) $r['umeta_id'], 'user_id' => (int) $r['user_id'], 'meta_key' => (string) $r['meta_key'], 'meta_value' => (string) $r['meta_value'] ) );
			if ( $ok ) {
				++$put;
			}
			clean_user_cache( (int) $r['user_id'] );
		}
		$fix['undone'] = true;
		update_option( self::OPTION . $token, $fix, false );
		return array( 'ok' => true, 'restored' => $put );
	}

	/** Forget saved fixes older than the undo period. */
	private static function forget_old() {
		global $wpdb;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::OPTION ) . '%' ) ) as $name ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$v = get_option( $name );
			if ( ! is_array( $v ) || ( isset( $v['made'] ) && $v['made'] < time() - self::KEEP ) ) {
				delete_option( $name );
			}
		}
	}
}
