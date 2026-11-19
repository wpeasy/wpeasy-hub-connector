<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Edits or removes one link in the content of this website's pages and posts when the Hub asks (a link the Hub found broken).
 * "unlink" takes the link off but keeps its words (a picture is taken out); "replace" points it at a new address. It only touches the text of posts and pages
 * (post_content): links in menus, widgets, templates or page-builder data are not changed, and the answer says so when nothing was found.
 * Every change is a normal WordPress save, so each page keeps a revision to go back to (when the website keeps revisions).
 */
class LinkEditor {
	const MAX_POSTS = 300;

	/**
	 * Finds the link in the content and changes it. Pure: no WordPress calls, so it can be tested alone.
	 *
	 * @param string $html  The content.
	 * @param string $base  The page's own address (relative links are read against it).
	 * @param string $url   The link to find (absolute).
	 * @param string $op    unlink | replace.
	 * @param string $to    The new address (replace only).
	 * @return array{0: string, 1: int} The new content and how many links were changed.
	 */
	public static function transform( $html, $base, $url, $op, $to = '' ) {
		$count  = 0;
		$target = self::norm( $url );
		$out    = preg_replace_callback(
			'#<a\b([^>]*)>(.*?)</a>#is',
			function ( $m ) use ( $base, $target, $op, $to, &$count ) {
				if ( ! preg_match( '#\bhref\s*=\s*(["\'])(.*?)\1#is', $m[1], $h ) ) {
					return $m[0];
				}
				$abs = self::resolve( html_entity_decode( $h[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base );
				if ( null === $abs || self::norm( $abs ) !== $target ) {
					return $m[0];
				}
				++$count;
				if ( 'unlink' === $op ) {
					return $m[2];
				}
				$new = $h[1] . htmlspecialchars( $to, ENT_QUOTES, 'UTF-8' ) . $h[1];
				return '<a' . str_replace( $h[0], 'href=' . $new, $m[1] ) . '>' . $m[2] . '</a>';
			},
			(string) $html
		);
		$out = null === $out ? (string) $html : $out;

		// Pictures count as links too (the Hub's checker also reports a picture that no longer loads): "unlink" takes the picture out, "replace" points it at a new address.
		$img = function ( $chunk ) use ( $base, $target, $op, $to, &$count ) {
			$found = 0;
			$new   = preg_replace_callback(
				'#<img\b[^>]*>#i',
				function ( $m ) use ( $base, $target, $op, $to, &$count, &$found ) {
					if ( ! preg_match( '#\bsrc\s*=\s*(["\'])(.*?)\1#is', $m[0], $h ) ) {
						return $m[0];
					}
					$abs = self::resolve( html_entity_decode( $h[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base );
					if ( null === $abs || self::norm( $abs ) !== $target ) {
						return $m[0];
					}
					++$count;
					++$found;
					if ( 'unlink' === $op ) {
						return '';
					}
					$tag = str_replace( $h[0], 'src=' . $h[1] . htmlspecialchars( $to, ENT_QUOTES, 'UTF-8' ) . $h[1], $m[0] );
					return preg_replace( '#\s(srcset|sizes)\s*=\s*(["\']).*?\2#is', '', $tag ); // the old sizes would still point at the old picture
				},
				$chunk
			);
			return array( null === $new ? $chunk : $new, $found );
		};
		// A WordPress picture block is taken out whole (its picture, caption and wrapper), so no empty block is left behind.
		$out = preg_replace_callback(
			'#<!-- wp:image\b.*?<!-- /wp:image -->#is',
			function ( $m ) use ( $img, $op ) {
				list( $inner, $found ) = $img( $m[0] );
				if ( ! $found ) {
					return $m[0];
				}
				return 'unlink' === $op ? '' : $inner;
			},
			$out
		);
		list( $out ) = $img( $out );

		return array( $out, $count );
	}

	/** Makes a link absolute, the way a browser would (and the Hub's crawler did). Null for things that are not web addresses worth comparing (#, mailto, tel). */
	public static function resolve( $href, $base ) {
		$href = trim( $href );
		if ( '' === $href || '#' === $href[0] ) {
			return null;
		}
		$href = preg_replace( '/#.*$/s', '', $href );
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) ) {
			return preg_match( '#^https?:#i', $href ) ? $href : null;
		}
		$p = parse_url( $base );
		if ( empty( $p['host'] ) ) {
			return null;
		}
		$origin = ( isset( $p['scheme'] ) ? $p['scheme'] : 'https' ) . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		if ( '//' === substr( $href, 0, 2 ) ) {
			return ( isset( $p['scheme'] ) ? $p['scheme'] : 'https' ) . ':' . $href;
		}
		$bpath = isset( $p['path'] ) ? $p['path'] : '/';
		if ( '/' === $href[0] ) {
			$path = $href;
		} elseif ( '?' === $href[0] ) {
			$path = $bpath . $href;
		} else {
			$path = preg_replace( '#[^/]*$#', '', $bpath ) . $href;
		}
		$q = '';
		if ( false !== strpos( $path, '?' ) ) {
			list( $path, $q ) = explode( '?', $path, 2 );
			$q                = '?' . $q;
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $parts );
			} elseif ( '.' !== $seg ) {
				$parts[] = $seg;
			}
		}
		$path = implode( '/', $parts );
		return $origin . ( '/' === ( $path[0] ?? '' ) ? '' : '/' ) . $path . $q;
	}

	private static function norm( $u ) {
		$u = preg_replace( '/#.*$/s', '', trim( $u ) );
		return strtolower( rtrim( $u, '/' ) );
	}

	/** @return array{ok: bool, status: string, message: string, changed?: int, posts?: array} status: done, not_found, failed */
	public static function run( $url, $op, $to, array $pages ) {
		global $wpdb;
		if ( ! in_array( $op, array( 'unlink', 'replace' ), true ) || ! preg_match( '#^https?://#i', $url ) ) {
			return self::fail( __( 'That is not a change the Hub can make.', 'wpeasy-hub-connector' ) );
		}
		if ( 'replace' === $op && ! preg_match( '#^(https?://|mailto:|tel:|/)#i', $to ) ) {
			return self::fail( __( 'The new address must start with http://, https://, mailto:, tel: or /.', 'wpeasy-hub-connector' ) );
		}
		// The pages the Hub saw it on, plus any post whose text mentions the end of its address (a link written without the full address).
		$ids = array();
		foreach ( $pages as $page ) {
			$id = self::post_for( $page );
			if ( $id ) {
				$ids[ $id ] = true;
			}
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$tail = trim( basename( rtrim( $path, '/' ) ) );
		if ( '' === $tail ) {
			$tail = (string) wp_parse_url( $url, PHP_URL_HOST );
		}
		if ( '' !== $tail ) {
			$like = '%' . $wpdb->esc_like( $tail ) . '%';
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status NOT IN ('trash','auto-draft','inherit') AND post_type NOT IN ('revision','nav_menu_item','attachment') LIMIT %d", $like, self::MAX_POSTS ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( (array) $rows as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		$changed = 0;
		$posts   = array();
		kses_remove_filters(); // this runs with nobody signed in: WordPress must not "clean" the content it saves
		foreach ( array_keys( $ids ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || '' === $post->post_content ) {
				continue;
			}
			$base                = get_permalink( $post ) ?: home_url( '/' );
			list( $new, $count ) = self::transform( $post->post_content, $base, $url, $op, $to );
			if ( ! $count || $new === $post->post_content ) {
				continue;
			}
			$saved = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $new ) ), true );
			if ( is_wp_error( $saved ) ) {
				kses_init();
				return self::fail( $saved->get_error_message() );
			}
			$changed += $count;
			$posts[]  = array( 'id' => (int) $post->ID, 'title' => mb_substr( wp_strip_all_tags( get_the_title( $post ) ), 0, 120 ) );
		}
		kses_init();

		if ( ! $changed ) {
			return array( 'ok' => true, 'status' => 'not_found', 'message' => __( 'The link was not found in the text of any page or post. It may be in a menu, a widget, a template or a page builder, which the Hub cannot edit.', 'wpeasy-hub-connector' ), 'changed' => 0, 'posts' => array() );
		}
		return array( 'ok' => true, 'status' => 'done', 'message' => '', 'changed' => $changed, 'posts' => $posts );
	}

	private static function post_for( $page ) {
		$page = (string) $page;
		$home = trailingslashit( home_url() );
		if ( trailingslashit( $page ) === $home && 'page' === get_option( 'show_on_front' ) ) {
			return (int) get_option( 'page_on_front' );
		}
		return (int) url_to_postid( $page );
	}

	private static function fail( $message ) {
		return array( 'ok' => false, 'status' => 'failed', 'message' => $message );
	}
}
