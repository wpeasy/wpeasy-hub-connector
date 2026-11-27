<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Holds back WordPress's own automatic updates (WordPress itself, plugins and themes) while the Hub says it makes this website's updates, so that none happen at a time nobody chose.
 * Updates are still looked for and reported, people can still update by hand, and updates the Hub asks for are not touched (they do not go through WordPress's automatic updater).
 *
 * The Hub says whether to hold in its answer to every report. The word goes stale: if the Hub has not confirmed it for three days (disconnected, gone, or unreachable) WordPress is
 * left to itself again, so a website is never left without updates by a Hub that is no longer looking after it. Switching the plugin off lifts the hold at once.
 */
class AutoUpdates {
	const KEY   = 'hold_auto_updates';
	const FRESH = 259200; // three days

	/** The WordPress filters that decide whether an automatic update runs. */
	const FILTERS = array( 'auto_update_core', 'auto_update_plugin', 'auto_update_theme', 'allow_dev_auto_core_updates', 'allow_minor_auto_core_updates', 'allow_major_auto_core_updates' );

	public static function init() {
		foreach ( self::FILTERS as $filter ) {
			add_filter( $filter, array( __CLASS__, 'decide' ), PHP_INT_MAX ); // last, so nothing else switches them back on
		}
		add_filter( 'send_core_update_notification_email', array( __CLASS__, 'decide' ), PHP_INT_MAX ); // no "please update" email to the site's administrator for what is held on purpose
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'notice' ) );
	}

	/** Is the hold in force now? */
	public static function held() {
		$h = Store::get( self::KEY, array() );

		return Store::connected() && is_array( $h ) && ! empty( $h['on'] ) && isset( $h['at'] ) && time() - (int) $h['at'] < self::FRESH;
	}

	/** What the Hub just said. @return bool whether that changed what is in force */
	public static function apply( $on ) {
		$before = self::held();
		Store::set( array( self::KEY => array( 'on' => (bool) $on, 'at' => time() ) ) );

		return $before !== self::held();
	}

	public static function release() {
		self::apply( false );
	}

	/** Every one of the filters: "no" while holding, otherwise whatever WordPress and other plugins had decided. */
	public static function decide( $value ) {
		return self::held() ? false : $value;
	}

	/** A word on the screens where someone would wonder why nothing updates itself. */
	public static function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'update_plugins' ) || ! in_array( str_replace( '-network', '', $screen->id ), array( 'update-core', 'plugins', 'themes' ), true ) || ! self::held() ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Automatic updates are switched off by the WP Easy Hub connected to this website: updates are installed on its schedule. You can still update by hand.', 'wpeasy-hub-connector' ) . '</p></div>';
	}
}
