<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Looking at the people of one role, and deleting the ones the Hub is told to (for example fake sign-ups). Built to be cautious:
 * it never deletes anyone who can edit the website or who has written anything, the list is checked again on the website at the moment of deleting,
 * and each person (their user row and all their details) is saved on this website for 30 days first so a deletion can be undone.
 * What other plugins do when a user is deleted (for example removing a donor record) is not undone.
 */
class UserTools {
	const ACTIONS = array( 'users' );
	const OPTION  = 'wpeasy_hub_usersdel_';
	const KEEP    = 30 * DAY_IN_SECONDS;
	const MAX     = 100;
	/** A person holding any of these roles is never deleted from here. */
	const PROTECTED = array( 'administrator', 'editor', 'author', 'shop_manager', 'contributor' );

	public static function handle( $action, $data ) {
		$op = isset( $data['op'] ) ? (string) $data['op'] : '';
		if ( 'list' === $op ) {
			return self::list_role( isset( $data['role'] ) ? (string) $data['role'] : '' );
		}
		if ( 'delete' === $op ) {
			return self::delete( isset( $data['ids'] ) ? (array) $data['ids'] : array() );
		}
		if ( 'undo' === $op ) {
			return self::undo( isset( $data['token'] ) ? (string) $data['token'] : '' );
		}
		return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown request.' );
	}

	/** Whether a person may be deleted from here, and if not, why (plain words). */
	private static function why_not( $uid, $roles ) {
		if ( (int) $uid === (int) get_current_user_id() && get_current_user_id() ) {
			return __( 'it is the signed-in user', 'wpeasy-hub-connector' );
		}
		foreach ( (array) $roles as $r ) {
			if ( in_array( $r, self::PROTECTED, true ) ) {
				return __( 'they can edit this website', 'wpeasy-hub-connector' );
			}
		}
		global $wpdb;
		$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_status NOT IN ('auto-draft')", (int) $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $posts > 0 ) {
			return __( 'they have written content', 'wpeasy-hub-connector' );
		}
		return null;
	}

	private static function roles_of( $uid ) {
		$u = get_userdata( (int) $uid );
		return $u ? array_values( (array) $u->roles ) : array();
	}

	private static function list_role( $role ) {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,60}$/', $role ) ) {
			return array( 'ok' => false, 'code' => 'bad', 'message' => 'Unknown role.' );
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT u.ID, u.user_login, u.display_name, u.user_email, u.user_registered FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID WHERE m.meta_key = %s AND m.meta_value LIKE %s ORDER BY u.user_registered DESC LIMIT 500", $wpdb->prefix . 'capabilities', '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$roles = self::roles_of( $r->ID );
			if ( ! in_array( $role, $roles, true ) ) {
				continue;
			}
			$why     = self::why_not( $r->ID, $roles );
			$domain  = false !== strpos( (string) $r->user_email, '@' ) ? strtolower( substr( strrchr( (string) $r->user_email, '@' ), 1 ) ) : '';
			$out[]   = array( 'id' => (int) $r->ID, 'login' => $r->user_login, 'name' => $r->display_name, 'email_domain' => $domain, 'registered' => $r->user_registered ? gmdate( 'c', strtotime( $r->user_registered . ' UTC' ) ) : null, 'roles' => $roles, 'protected' => $why );
		}
		return array( 'ok' => true, 'role' => $role, 'users' => $out, 'capped' => count( $rows ) >= 500 );
	}

	private static function delete( $ids ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids || count( $ids ) > self::MAX ) {
			return array( 'ok' => false, 'code' => 'bad', 'message' => sprintf( /* translators: %d: how many */ __( 'Choose between 1 and %d people.', 'wpeasy-hub-connector' ), self::MAX ) );
		}
		self::forget_old();
		$saved   = array();
		$skipped = array();
		foreach ( $ids as $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				$skipped[] = array( 'id' => $id, 'why' => __( 'they no longer exist', 'wpeasy-hub-connector' ) );
				continue;
			}
			$why = self::why_not( $id, self::roles_of( $id ) );
			if ( $why ) {
				$skipped[] = array( 'id' => $id, 'login' => $row['user_login'], 'why' => $why );
				continue;
			}
			$saved[ $id ] = array( 'user' => $row, 'meta' => (array) $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! $saved ) {
			return array( 'ok' => true, 'token' => '', 'deleted' => 0, 'skipped' => $skipped );
		}
		$token = bin2hex( random_bytes( 10 ) );
		if ( ! update_option( self::OPTION . $token, array( 'made' => time(), 'people' => array_values( $saved ), 'undone' => false ), false ) || ! get_option( self::OPTION . $token ) ) {
			return array( 'ok' => false, 'code' => 'save', 'message' => __( 'Could not save the people first, so nobody was deleted.', 'wpeasy-hub-connector' ) );
		}
		$deleted = 0;
		foreach ( $saved as $id => $keep ) {
			if ( wp_delete_user( (int) $id ) ) {
				++$deleted;
			} else {
				$skipped[] = array( 'id' => (int) $id, 'login' => $keep['user']['user_login'], 'why' => __( 'WordPress would not delete them', 'wpeasy-hub-connector' ) );
			}
		}
		return array( 'ok' => true, 'token' => $token, 'deleted' => $deleted, 'skipped' => $skipped );
	}

	private static function undo( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{20}$/', $token ) ) {
			return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown deletion.' );
		}
		$fix = get_option( self::OPTION . $token );
		if ( ! is_array( $fix ) || empty( $fix['people'] ) ) {
			return array( 'ok' => false, 'code' => 'gone', 'message' => __( 'The deleted people are no longer kept on the website (they are kept for 30 days), so this cannot be undone here. Restore a backup instead.', 'wpeasy-hub-connector' ) );
		}
		if ( ! empty( $fix['undone'] ) ) {
			return array( 'ok' => false, 'code' => 'undone', 'message' => __( 'This was already undone.', 'wpeasy-hub-connector' ) );
		}
		$back = 0;
		foreach ( $fix['people'] as $p ) {
			$id = (int) $p['user']['ID'];
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID = %d OR user_login = %s", $id, $p['user']['user_login'] ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				continue; // that id or name is in use again: never overwrite
			}
			if ( ! $wpdb->insert( $wpdb->users, $p['user'] ) ) {
				continue;
			}
			foreach ( (array) $p['meta'] as $m ) {
				if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE umeta_id = %d", (int) $m['umeta_id'] ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					unset( $m['umeta_id'] );
				}
				$wpdb->insert( $wpdb->usermeta, $m );
			}
			clean_user_cache( $id );
			++$back;
		}
		$fix['undone'] = true;
		update_option( self::OPTION . $token, $fix, false );
		return array( 'ok' => true, 'restored' => $back );
	}

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
