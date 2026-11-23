<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** What this website tells the Hub: versions, the active theme, and which plugins, themes and WordPress itself have an update waiting. Nothing else leaves the site. */
class Report {
	public static function collect( $refresh = false ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		if ( $refresh ) {
			// Ask WordPress to look for updates now (it keeps its own 12 hour cache otherwise), and forget what the Hub said last time about this plugin's own newest version.
			delete_site_transient( Updater::CACHE );
			// WordPress only looks again when its saved answers are gone or 12 hours old, so remove them first; otherwise a new release of anything stays invisible.
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'update_themes' );
			wp_version_check( array(), true );
			wp_update_plugins();
			wp_update_themes();
		}

		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );
		$active         = (array) get_option( 'active_plugins', array() );

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$new       = ( $plugin_updates && isset( $plugin_updates->response[ $file ]->new_version ) ) ? $plugin_updates->response[ $file ]->new_version : null;
			// WordPress keeps an offer in its update list until it next looks: right after an update it can still offer the version that is now installed. That is not an update.
			if ( null !== $new && version_compare( (string) $new, (string) $data['Version'], '<=' ) ) {
				$new = null;
			}
			$plugins[] = array(
				'id'      => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, $active, true ) || ( is_multisite() && is_plugin_active_for_network( $file ) ),
				'update'  => $new,
				'no_package' => null !== $new && Runner::no_download( isset( $plugin_updates->response[ $file ]->package ) ? $plugin_updates->response[ $file ]->package : '' ),
			);
		}

		$themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$new      = ( $theme_updates && isset( $theme_updates->response[ $slug ]['new_version'] ) ) ? $theme_updates->response[ $slug ]['new_version'] : null;
			if ( null !== $new && version_compare( (string) $new, (string) $theme->get( 'Version' ), '<=' ) ) {
				$new = null; // an offer for the version already installed (see plugins above)
			}
			$themes[] = array(
				'id'      => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => get_stylesheet() === $slug || get_template() === $slug, // the parent of a child theme in use is in use too
				'parent'  => get_template() === $slug && get_stylesheet() !== $slug,
				'update'  => $new,
				'no_package' => null !== $new && Runner::no_download( isset( $theme_updates->response[ $slug ]['package'] ) ? $theme_updates->response[ $slug ]['package'] : '' ),
			);
		}

		$core = null;
		foreach ( (array) get_core_updates() as $u ) {
			if ( isset( $u->response ) && 'upgrade' === $u->response && ! empty( $u->current ) ) {
				$core = $u->current;
				break;
			}
		}

		// The administrators, so the Hub can be told which one a one-click login is as. Names only: no emails, no passwords.
		// Read straight from the database, one row per person: a person whose role list is saved more than once (it happens after some migrations) would otherwise use up the limit with copies of themselves and push other administrators off the list.
		$admins = array();
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT u.ID, u.user_login, u.display_name, u.user_registered FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID WHERE m.meta_key = %s AND m.meta_value LIKE %s ORDER BY u.ID LIMIT 200", $wpdb->prefix . 'capabilities', '%' . $wpdb->esc_like( '"administrator"' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $u ) {
			$admins[ (int) $u->ID ] = array( 'id' => (int) $u->ID, 'login' => $u->user_login, 'name' => $u->display_name, 'registered' => $u->user_registered ? gmdate( 'c', strtotime( $u->user_registered . ' UTC' ) ) : null );
		}

		// How many people hold each role (counts only, no names), so the Hub can show who has what and notice new administrators.
		$user_roles = array();
		$counted    = count_users();
		$names      = wp_roles()->get_names();
		foreach ( (array) ( $counted['avail_roles'] ?? array() ) as $role => $n ) {
			if ( 'none' === $role || (int) $n < 1 ) {
				continue;
			}
			$user_roles[] = array( 'role' => (string) $role, 'label' => isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : (string) $role, 'count' => (int) $n );
		}

		$theme = wp_get_theme();

		return array(
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'plugin_version' => VERSION,
			'site_url'       => home_url(),
			'multisite'      => is_multisite(),
			'theme'          => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ) ),
			'core_update'    => $core,
			'plugins'        => $plugins,
			'themes'         => $themes,
			'admins'         => array_values( $admins ),
			'user_roles'     => $user_roles,
			'shield'         => Shield::status(),
			'backups'        => Backup::stats(), // keyed by ID above: a user whose role is stored twice in the database comes back twice
			'login_allowed'  => (bool) Store::get( 'allow_login', true ),
			'backup_allowed' => (bool) Store::get( 'allow_backup', true ),
			'restore_allowed' => (bool) Store::get( 'allow_restore', true ),
		);
	}
}
