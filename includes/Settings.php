<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/** Settings > Hub Connector: pair with the Hub, send a report now, or forget the pairing. Administrators only; every action is nonce-checked. */
class Settings {
	const PAGE = 'wpeasy-hub-connector';

	/** The address the box starts with. WP Easy's own Hub; another agency's Hub is set once with add_filter( 'wpeasy_hub_connector_default_hub_url', ... ) or by typing it. */
	const DEFAULT_HUB_URL = 'https://hub.wpeasy.au';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wpeasy_hub_pair', array( __CLASS__, 'handle_pair' ) );
		add_action( 'admin_post_wpeasy_hub_report', array( __CLASS__, 'handle_report' ) );
		add_action( 'admin_post_wpeasy_hub_forget', array( __CLASS__, 'handle_forget' ) );
		add_action( 'admin_post_wpeasy_hub_options', array( __CLASS__, 'handle_options' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/wpeasy-hub-connector.php' ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'wpeasy-hub-connector' ) . '</a>' );
			return $links;
		} );
	}

	public static function menu() {
		add_options_page( __( 'Hub Connector', 'wpeasy-hub-connector' ), __( 'Hub Connector', 'wpeasy-hub-connector' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wpeasy-hub-connector' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function back( $ok, $message ) {
		set_transient( 'wpeasy_hub_notice_' . get_current_user_id(), array( 'ok' => $ok, 'message' => $message ), 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	public static function handle_pair() {
		self::guard( 'wpeasy_hub_pair' );
		$hub  = isset( $_POST['hub_url'] ) ? sanitize_text_field( wp_unslash( $_POST['hub_url'] ) ) : '';
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		// Keep the Hub address in the box if the attempt fails, so only the code needs typing again.
		$clean = Client::clean_hub_url( $hub );
		if ( '' !== $clean ) {
			Store::set( array( 'hub_url' => $clean ) );
		}
		$r    = Client::pair( $hub, $code );
		self::back( $r['ok'], $r['message'] );
	}

	public static function handle_report() {
		self::guard( 'wpeasy_hub_report' );
		$r = Client::send_report( true );
		self::back( $r['ok'], $r['message'] );
	}

	public static function handle_options() {
		self::guard( 'wpeasy_hub_options' );
		Store::set( array( 'allow_login' => ! empty( $_POST['allow_login'] ), 'allow_backup' => ! empty( $_POST['allow_backup'] ), 'allow_restore' => ! empty( $_POST['allow_restore'] ) ) );
		self::back( true, __( 'Saved.', 'wpeasy-hub-connector' ) );
	}

	public static function handle_forget() {
		self::guard( 'wpeasy_hub_forget' );
		Store::forget();
		self::back( true, __( 'Disconnected from this side. Also press Disconnect on the website in the Hub so it stops expecting reports.', 'wpeasy-hub-connector' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( 'wpeasy_hub_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'wpeasy_hub_notice_' . get_current_user_id() );
		}
		$connected = Store::connected();
		$last      = Store::get( 'last_report', array() );
		echo '<div class="wrap"><h1>' . esc_html__( 'WP Easy Hub Connector', 'wpeasy-hub-connector' ) . '</h1>';
		if ( $notice ) {
			printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', $notice['ok'] ? 'notice-success' : 'notice-error', esc_html( $notice['message'] ) );
		}

		if ( $connected ) {
			echo '<p><strong>' . esc_html__( 'Connected', 'wpeasy-hub-connector' ) . '</strong> ' . esc_html__( 'to', 'wpeasy-hub-connector' ) . ' <code>' . esc_html( Store::get( 'hub_url' ) ) . '</code></p>';
			if ( ! empty( $last['time'] ) ) {
				$when = sprintf( /* translators: %s: how long ago */ __( '%s ago', 'wpeasy-hub-connector' ), human_time_diff( (int) $last['time'] ) );
				echo '<p>' . esc_html__( 'Last report:', 'wpeasy-hub-connector' ) . ' ' . esc_html( $when ) . ' &mdash; ' . ( ! empty( $last['ok'] ) ? esc_html__( 'sent', 'wpeasy-hub-connector' ) : esc_html__( 'failed:', 'wpeasy-hub-connector' ) . ' ' . esc_html( $last['message'] ) ) . '</p>';
			}
			echo '<p>' . esc_html__( 'This website reports its WordPress and PHP versions and any updates waiting, once a day. Nothing else is sent.', 'wpeasy-hub-connector' ) . '</p>';
			echo '<h2>' . esc_html__( 'Automatic updates', 'wpeasy-hub-connector' ) . '</h2>';
			echo '<p>' . ( AutoUpdates::held() ? esc_html__( 'Switched off by your Hub: updates are installed on its schedule. You can still update by hand.', 'wpeasy-hub-connector' ) : esc_html__( 'Left to WordPress and your own settings. Your Hub can switch them off and install updates on its schedule.', 'wpeasy-hub-connector' ) ) . '</p>';
			echo '<h2>' . esc_html__( 'One-click login', 'wpeasy-hub-connector' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpeasy_hub_options">';
			wp_nonce_field( 'wpeasy_hub_options' );
			echo '<p><label><input type="checkbox" name="allow_login" value="1"' . checked( (bool) Store::get( 'allow_login', true ), true, false ) . '> ' . esc_html__( 'Let the Hub log in to this website as one of its administrators (chosen in the Hub). Note: this signs in directly, so it skips any two-factor step this website has.', 'wpeasy-hub-connector' ) . '</label></p>';
			echo '<h2>' . esc_html__( 'Backups', 'wpeasy-hub-connector' ) . '</h2>';
			echo '<p><label><input type="checkbox" name="allow_backup" value="1"' . checked( (bool) Store::get( 'allow_backup', true ), true, false ) . '> ' . esc_html__( 'Let the Hub back up this website: its files in wp-content (uploads, plugins, themes) and its database. The Hub asks for them a little at a time and keeps them locked with your agency\'s backup passphrase. Nothing else is sent, and nothing is sent unless the Hub asks.', 'wpeasy-hub-connector' ) . '</label></p>';
			echo '<p><label><input type="checkbox" name="allow_restore" value="1"' . checked( (bool) Store::get( 'allow_restore', true ), true, false ) . '> ' . esc_html__( 'Let the Hub restore a backup onto this website. It takes a safety copy first, puts the site in maintenance mode while it works, and never deletes anything or replaces this plugin.', 'wpeasy-hub-connector' ) . '</label></p>';
			submit_button( __( 'Save', 'wpeasy-hub-connector' ), 'secondary', 'submit', false );
			echo '</form><hr>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
			echo '<input type="hidden" name="action" value="wpeasy_hub_report">';
			wp_nonce_field( 'wpeasy_hub_report' );
			submit_button( __( 'Send a report now', 'wpeasy-hub-connector' ), 'secondary', 'submit', false );
			echo '</form>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block" onsubmit="return confirm(\'' . esc_js( __( 'Disconnect this website from the Hub?', 'wpeasy-hub-connector' ) ) . '\');">';
			echo '<input type="hidden" name="action" value="wpeasy_hub_forget">';
			wp_nonce_field( 'wpeasy_hub_forget' );
			submit_button( __( 'Disconnect', 'wpeasy-hub-connector' ), 'delete', 'submit', false );
			echo '</form>';
		} else {
			echo '<p>' . esc_html__( 'In the Hub, open this website, choose Connect the plugin, and copy the pairing code it shows. Then enter the Hub address and the code here.', 'wpeasy-hub-connector' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wpeasy_hub_pair">';
			wp_nonce_field( 'wpeasy_hub_pair' );
			echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="wpeasy-hub-url">' . esc_html__( 'Hub address', 'wpeasy-hub-connector' ) . '</label></th>';
			echo '<td><input id="wpeasy-hub-url" name="hub_url" type="url" class="regular-text" placeholder="https://hub.example.com" value="' . esc_attr( Store::get( 'hub_url', apply_filters( 'wpeasy_hub_connector_default_hub_url', self::DEFAULT_HUB_URL ) ) ) . '" required></td></tr>';
			echo '<tr><th scope="row"><label for="wpeasy-hub-code">' . esc_html__( 'Pairing code', 'wpeasy-hub-connector' ) . '</label></th>';
			echo '<td><input id="wpeasy-hub-code" name="code" type="text" class="regular-text code" placeholder="ABCD-EFGH-JKLM" autocomplete="off" required></td></tr></table>';
			submit_button( __( 'Connect', 'wpeasy-hub-connector' ) );
			echo '</form>';
		}
		echo '</div>';
	}
}
