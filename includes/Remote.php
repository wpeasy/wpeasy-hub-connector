<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the Hub ask this website to do one thing now: send its report (so a person is not left waiting for the daily one). The Hub posts a signed request to
 * /?wpeasy_hub_action=1; it carries nothing the site hands back except "ok". Checked like every request: paired connection, fresh timestamp, a one-time nonce
 * and a signature made with the secret from pairing. Only the actions listed in ACTIONS exist.
 */
class Remote {
	/** What the signature is made over in place of an address (the request itself goes to the home page). */
	const PATH    = '/wpeasy-hub-action';
	const ACTIONS = array( 'report', 'update', 'remove', 'link' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 1 );
	}

	public static function maybe_handle() {
		if ( ! isset( $_GET['wpeasy_hub_action'] ) || 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		nocache_headers();
		$body = (string) file_get_contents( 'php://input' );
		if ( ! self::verified( $body ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
		$data   = json_decode( $body, true );
		$action = is_array( $data ) && isset( $data['action'] ) ? $data['action'] : '';
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			wp_send_json( array( 'ok' => false ), 400 );
		}
		if ( 'update' === $action ) {
			$kind = isset( $data['kind'] ) ? (string) $data['kind'] : '';
			$id   = isset( $data['id'] ) ? sanitize_text_field( (string) $data['id'] ) : '';
			$to   = isset( $data['to'] ) ? sanitize_text_field( (string) $data['to'] ) : '';
			// WordPress's updater (and the plugin being updated) may print notices or warnings; they must not reach the answer, which is JSON only.
			ob_start();
			try {
				$res = Runner::run( $kind, $id, $to );
			} catch ( \Throwable $e ) {
				$res = array( 'ok' => false, 'status' => 'failed', 'message' => sprintf( /* translators: %s: the error */ __( 'The update hit an unexpected error: %s', 'wpeasy-hub-connector' ), $e->getMessage() ) );
			}
			Client::send_report( true ); // the Hub's picture of this website follows what just happened
			$noise = trim( wp_strip_all_tags( (string) ob_get_clean() ) );
			if ( '' !== $noise ) {
				$res['output'] = substr( $noise, 0, 300 ); // kept for diagnosis only
			}
			wp_send_json( $res + array( 'plugin_version' => VERSION ) );
		}
		if ( 'remove' === $action ) {
			$kind = isset( $data['kind'] ) ? (string) $data['kind'] : '';
			$id   = isset( $data['id'] ) ? sanitize_text_field( (string) $data['id'] ) : '';
			ob_start(); // a plugin's uninstall routine may print: the answer is JSON only
			try {
				$res = Remover::run( $kind, $id );
			} catch ( \Throwable $e ) {
				$res = array( 'ok' => false, 'status' => 'failed', 'message' => sprintf( /* translators: %s: the error */ __( 'The removal hit an unexpected error: %s', 'wpeasy-hub-connector' ), $e->getMessage() ) );
			}
			Client::send_report( true );
			ob_end_clean();
			wp_send_json( $res + array( 'plugin_version' => VERSION ) );
		}
		if ( 'link' === $action ) {
			$pages = array();
			foreach ( ( isset( $data['pages'] ) && is_array( $data['pages'] ) ? array_slice( $data['pages'], 0, 8 ) : array() ) as $page ) {
				$pages[] = esc_url_raw( (string) $page );
			}
			ob_start();
			try {
				$res = LinkEditor::run( esc_url_raw( isset( $data['url'] ) ? (string) $data['url'] : '' ), isset( $data['op'] ) ? (string) $data['op'] : '', isset( $data['to'] ) ? (string) $data['to'] : '', $pages );
			} catch ( \Throwable $e ) {
				kses_init();
				$res = array( 'ok' => false, 'status' => 'failed', 'message' => sprintf( /* translators: %s: the error */ __( 'The change hit an unexpected error: %s', 'wpeasy-hub-connector' ), $e->getMessage() ) );
			}
			ob_end_clean();
			wp_send_json( $res + array( 'plugin_version' => VERSION ) );
		}
		$r = Client::send_report( true ); // asks WordPress for fresh update information, then sends the report to the Hub as usual
		wp_send_json( array( 'ok' => (bool) $r['ok'], 'plugin_version' => VERSION ) );
	}

	private static function verified( $body ) {
		if ( ! Store::connected() || strlen( $body ) > 4000 ) {
			return false;
		}
		$conn  = isset( $_SERVER['HTTP_X_HUB_CONNECTION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_HUB_CONNECTION'] ) ) : '';
		$ts    = isset( $_SERVER['HTTP_X_HUB_TIMESTAMP'] ) ? (int) $_SERVER['HTTP_X_HUB_TIMESTAMP'] : 0;
		$nonce = isset( $_SERVER['HTTP_X_HUB_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_HUB_NONCE'] ) ) : '';
		$sig   = isset( $_SERVER['HTTP_X_HUB_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_HUB_SIGNATURE'] ) ) : '';

		if ( ! hash_equals( (string) Store::get( 'connection' ), $conn ) || '' === $nonce || strlen( $nonce ) > 64 || abs( time() - $ts ) > 300 ) {
			return false;
		}
		if ( ! hash_equals( Signature::make( Store::get( 'secret' ), 'POST', self::PATH, $ts, $nonce, $body ), strtolower( $sig ) ) ) {
			return false;
		}
		return Login::claim_nonce( 'remote-' . $nonce ); // a request works once
	}
}
