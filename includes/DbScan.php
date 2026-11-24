<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * A read-only look at the users and roles in this website's database, for the Hub's Tools > Database scan. It changes nothing.
 * It reports facts and findings (a code, how many, and a few example people: id, login and name only, never emails or passwords).
 * Typical cause of what it finds: a website moved out of a WordPress multisite, where roles are saved per site under a different table prefix.
 */
class DbScan {
	const ACTIONS = array( 'db_scan' );
	const SAMPLE  = 20;

	public static function handle( $action, $data ) {
		if ( 'db_scan' !== $action ) {
			return array( 'ok' => false, 'code' => 'unknown' );
		}
		return self::users();
	}

	private static function person( $row ) {
		return array( 'id' => (int) $row->ID, 'login' => (string) $row->user_login, 'name' => (string) $row->display_name, 'registered' => $row->user_registered ? gmdate( 'c', strtotime( $row->user_registered . ' UTC' ) ) : null );
	}

	private static function users() {
		global $wpdb;
		$prefix  = $wpdb->prefix;
		$cap_key = $prefix . 'capabilities';
		$users   = $wpdb->users;
		$meta    = $wpdb->usermeta;
		$like    = '%' . $wpdb->esc_like( 'capabilities' );

		$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$users}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table names
		$findings   = array();
		$admin_like = '%' . $wpdb->esc_like( '"administrator"' ) . '%';

		// Every key that looks like a role list, whatever the prefix: one per site of the old network, plus this site's own.
		$groups = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, COUNT(*) AS row_count, COUNT(DISTINCT user_id) AS user_count FROM {$meta} WHERE meta_key LIKE %s GROUP BY meta_key ORDER BY user_count DESC LIMIT 40", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$own_rows = 0;
		$own_users = 0;
		$keys = array();
		foreach ( (array) $groups as $g ) {
			$keys[] = array( 'key' => (string) $g->meta_key, 'rows' => (int) $g->row_count, 'users' => (int) $g->user_count, 'own' => $g->meta_key === $cap_key );
			if ( $g->meta_key === $cap_key ) {
				$own_rows  = (int) $g->row_count;
				$own_users = (int) $g->user_count;
			}
		}

		// Administrators, counted three ways: WordPress's own count, the rows that say administrator, and the different people behind them.
		$admin_rows  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$meta} WHERE meta_key = %s AND meta_value LIKE %s", $cap_key, $admin_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$admin_users = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$meta} WHERE meta_key = %s AND meta_value LIKE %s", $cap_key, $admin_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counted     = count_users();
		$wp_admins   = (int) ( isset( $counted['avail_roles']['administrator'] ) ? $counted['avail_roles']['administrator'] : 0 );

		// 1. The same person holding the role list more than once (their role is counted again each time).
		$dup_users = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT user_id FROM {$meta} WHERE meta_key = %s GROUP BY user_id HAVING COUNT(*) > 1) d", $cap_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $dup_users > 0 ) {
			$sample = $wpdb->get_results( $wpdb->prepare( "SELECT u.ID, u.user_login, u.display_name, u.user_registered, d.c AS copies FROM {$users} u JOIN (SELECT user_id, COUNT(*) AS c FROM {$meta} WHERE meta_key = %s GROUP BY user_id HAVING COUNT(*) > 1) d ON d.user_id = u.ID ORDER BY d.c DESC LIMIT %d", $cap_key, self::SAMPLE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$findings[] = array( 'code' => 'duplicate_roles', 'severity' => 'bad', 'count' => $dup_users, 'extra_rows' => max( 0, $own_rows - $own_users ),
				'samples' => array_map( function ( $r ) { return self::person( $r ) + array( 'copies' => (int) $r->copies ); }, (array) $sample ) );
		}

		// 2. A role list that is not a list (empty, damaged or not readable). Looked at in pieces so a huge site is not slowed.
		$bad     = 0;
		$bad_ids = array();
		$checked = 0;
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$meta} WHERE meta_key = %s ORDER BY umeta_id LIMIT 20000", $cap_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $r ) {
			++$checked;
			$v = is_string( $r->meta_value ) ? @unserialize( $r->meta_value, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( ! is_array( $v ) || array() === $v ) {
				++$bad;
				if ( count( $bad_ids ) < self::SAMPLE ) {
					$bad_ids[] = (int) $r->user_id;
				}
			}
		}
		if ( $bad > 0 ) {
			$samples = array();
			if ( $bad_ids ) {
				$ph      = implode( ',', array_fill( 0, count( $bad_ids ), '%d' ) );
				$samples = array_map( array( __CLASS__, 'person' ), (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_login, display_name, user_registered FROM {$users} WHERE ID IN ({$ph})", $bad_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}
			$findings[] = array( 'code' => 'broken_roles', 'severity' => 'bad', 'count' => $bad, 'checked' => $checked, 'samples' => $samples );
		}

		// 3. People with no role on this website at all.
		$none = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$users} u WHERE NOT EXISTS (SELECT 1 FROM {$meta} m WHERE m.user_id = u.ID AND m.meta_key = %s)", $cap_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $none > 0 ) {
			$sample     = $wpdb->get_results( $wpdb->prepare( "SELECT u.ID, u.user_login, u.display_name, u.user_registered FROM {$users} u WHERE NOT EXISTS (SELECT 1 FROM {$meta} m WHERE m.user_id = u.ID AND m.meta_key = %s) ORDER BY u.ID LIMIT %d", $cap_key, self::SAMPLE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$findings[] = array( 'code' => 'no_role', 'severity' => 'warn', 'count' => $none, 'samples' => array_map( array( __CLASS__, 'person' ), (array) $sample ) );
		}

		// 4. Roles saved under another prefix (the old network's other sites) for people who have none here.
		$foreign = array();
		$f_total = 0;
		foreach ( $keys as $k ) {
			if ( $k['own'] ) {
				continue;
			}
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.user_id) FROM {$meta} m WHERE m.meta_key = %s AND NOT EXISTS (SELECT 1 FROM {$meta} o WHERE o.user_id = m.user_id AND o.meta_key = %s)", $k['key'], $cap_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$a = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$meta} WHERE meta_key = %s AND meta_value LIKE %s", $k['key'], $admin_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$foreign[]  = array( 'key' => $k['key'], 'users' => $k['users'], 'only_there' => $n, 'administrators' => $a );
			$f_total   += $n;
		}
		if ( $foreign ) {
			$findings[] = array( 'code' => 'other_prefix_roles', 'severity' => $f_total > 0 ? 'warn' : 'info', 'count' => $f_total, 'prefixes' => $foreign );
		}

		// 5. Role information left behind for people who no longer exist.
		$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$meta} m LEFT JOIN {$users} u ON u.ID = m.user_id WHERE u.ID IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $orphans > 0 ) {
			$findings[] = array( 'code' => 'orphan_meta', 'severity' => 'info', 'count' => $orphans );
		}

		// 6. The list of what each role may do (one per prefix), and leftovers from other prefixes.
		$role_opts  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 40", '%' . $wpdb->esc_like( 'user_roles' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$own_option = $prefix . 'user_roles';
		$left_opts  = array_values( array_diff( $role_opts, array( $own_option ) ) );
		// The list above is capped, and a site moved out of a network can have hundreds of leftovers, so ask for its own option by exact name.
		$has_own = in_array( $own_option, $role_opts, true ) || (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $own_option ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $has_own ) {
			$findings[] = array( 'code' => 'roles_option_missing', 'severity' => 'bad', 'count' => 1, 'option' => $own_option );
		}
		if ( $left_opts ) {
			$findings[] = array( 'code' => 'other_prefix_options', 'severity' => 'info', 'count' => count( $left_opts ), 'options' => $left_opts );
		}

		// 7. Whole sets of tables with another prefix (the old network's other sites, or an earlier copy of this one).
		$others = array();
		$tables = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS name, (DATA_LENGTH + INDEX_LENGTH) AS size FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', '%' . $wpdb->esc_like( 'options' ) ) );
		foreach ( $tables as $t ) {
			$p = preg_replace( '/options$/', '', (string) $t->name );
			if ( '' === $p || 0 === strpos( $p, $prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $p ) ) {
				continue;
			}
			// Only where a users or posts table with the same prefix exists beside it (so it really is a WordPress set).
			$has = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)', $p . 'posts', $p . 'usermeta' ) );
			if ( $has < 1 ) {
				continue;
			}
			$size = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $p ) . '%' ) );
			$cnt  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $p ) . '%' ) );
			$others[] = array( 'prefix' => $p, 'tables' => $cnt, 'bytes' => $size );
			if ( count( $others ) >= 10 ) {
				break;
			}
		}
		if ( $others ) {
			$findings[] = array( 'code' => 'other_prefix_tables', 'severity' => 'info', 'count' => count( $others ), 'prefixes' => $others );
		}

		return array(
			'ok'       => true,
			'scanned'  => gmdate( 'c' ),
			'facts'    => array(
				'prefix'            => $prefix,
				'base_prefix'       => $wpdb->base_prefix,
				'multisite'         => is_multisite(),
				'multisite_config'  => defined( 'MULTISITE' ) && MULTISITE,
				'users'             => $total,
				'role_rows'         => $own_rows,
				'role_people'       => $own_users,
				'admin_rows'        => $admin_rows,
				'admin_people'      => $admin_users,
				'admin_wp_count'    => $wp_admins,
				'role_keys'         => $keys,
				'roles_counted'     => isset( $counted['avail_roles'] ) ? $counted['avail_roles'] : array(),
			),
			'findings' => $findings,
		);
	}
}
