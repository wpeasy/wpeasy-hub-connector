<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Empties the page caches the Hub knows how to ask for, so that after updates visitors (and the Hub's picture of the home page) get the website as it is now. Each is only asked if
 * that cache is there; one that fails is left and the rest carry on. Nothing else is changed. A cache in front of the website (a host's or a content network's) may not be reached.
 */
class CacheClear {
	/** @return array{ok: bool, cleared: array<int, string>} */
	public static function run() {
		$cleared = array();
		foreach ( self::caches() as $name => $clear ) {
			try {
				if ( $clear() ) {
					$cleared[] = $name;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- one cache that will not clear must not stop the rest
			}
		}
		return array( 'ok' => true, 'cleared' => $cleared );
	}

	/** Name => a function that clears it and answers whether it was there. */
	private static function caches() {
		$call = function ( $function, ...$args ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
			$function( ...$args );
			return true;
		};
		$act = function ( $there, $hook ) {
			if ( ! $there ) {
				return false;
			}
			do_action( $hook );
			return true;
		};

		return array(
			'WordPress object cache' => function () {
				return (bool) wp_cache_flush();
			},
			'WP Rocket'        => function () use ( $call ) { return $call( 'rocket_clean_domain' ); },
			'W3 Total Cache'   => function () use ( $call ) { return $call( 'w3tc_flush_all' ); },
			'WP Super Cache'   => function () use ( $call ) { return $call( 'wp_cache_clear_cache' ); },
			'SiteGround'       => function () use ( $call ) { return $call( 'sg_cachepress_purge_cache' ); },
			'LiteSpeed Cache'  => function () use ( $act ) { return $act( defined( 'LSCWP_V' ), 'litespeed_purge_all' ); },
			'Breeze'           => function () use ( $act ) { return $act( defined( 'BREEZE_VERSION' ), 'breeze_clear_all_cache' ); },
			'Cache Enabler'    => function () use ( $act ) { return $act( class_exists( 'Cache_Enabler', false ), 'cache_enabler_clear_complete_cache' ); },
			'Nginx Helper'     => function () use ( $act ) { return $act( class_exists( 'Nginx_Helper', false ), 'rt_nginx_helper_purge_all' ); },
			'Hummingbird'      => function () use ( $act ) { return $act( class_exists( 'WP_Hummingbird', false ) || defined( 'WPHB_VERSION' ), 'wphb_clear_page_cache' ); },
			'Autoptimize'      => function () {
				if ( ! class_exists( 'autoptimizeCache', false ) ) {
					return false;
				}
				\autoptimizeCache::clearall();
				return true;
			},
			'WP Fastest Cache' => function () {
				if ( ! isset( $GLOBALS['wp_fastest_cache'] ) || ! method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
					return false;
				}
				$GLOBALS['wp_fastest_cache']->deleteCache( true );
				return true;
			},
			'FlyingPress'      => function () {
				if ( ! class_exists( 'FlyingPress\\Purge', false ) ) {
					return false;
				}
				\FlyingPress\Purge::purge_everything();
				return true;
			},
			'Comet Cache'      => function () {
				if ( ! class_exists( 'comet_cache', false ) ) {
					return false;
				}
				\comet_cache::clear();
				return true;
			},
			'WP Engine'        => function () {
				if ( ! class_exists( 'WpeCommon', false ) ) {
					return false;
				}
				if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
					\WpeCommon::purge_memcached();
				}
				if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
					\WpeCommon::purge_varnish_cache();
				}
				return true;
			},
		);
	}
}
