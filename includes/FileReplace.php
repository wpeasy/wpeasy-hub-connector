<?php
namespace WPEasy\HubConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Search and replace inside FILES (generated style sheets, scripts, SVG, JSON...), the other half of moving a website: addresses that live in files, not in the database.
 * It works from a preview: fscan_start lists the candidate files, fscan_step reads them in batches and remembers which hold the text, and a run changes only files from that remembered list,
 * chosen by number (the Hub never names a path). Every file changed is first copied into a folder the web cannot read (kept 30 days) so the run can be undone, and written through a temporary
 * copy so a file is never half written. Text files only (by extension, over 5 MB skipped), never symbolic links, only inside the chosen places: uploads, the active theme, other plugins.
 * PHP files only when asked, and never this plugin's own files.
 */
class FileReplace {
	const PREVIEW   = 'wpeasy_hub_srf_';
	const RUN       = 'wpeasy_hub_srr_';
	const BUDGET    = 20;
	const MAX_BYTES = 5242880;
	const MAX_FILES = 30000;
	const MAX_MATCH = 2000;
	const KEEP      = 30 * DAY_IN_SECONDS;
	const EXT       = array( 'css', 'js', 'mjs', 'json', 'svg', 'html', 'htm', 'xml', 'txt', 'scss', 'less', 'map', 'webmanifest' );
	const SKIP_DIRS = array( 'node_modules', '.git', '.svn', 'wpeasy-hub-backups', 'wpeasy-hub-connector' );

	public static function handle( $op, $data, $o ) {
		switch ( $op ) {
			case 'fscan_start':
				return self::scan_start( $o, isset( $data['scopes'] ) ? (array) $data['scopes'] : array(), ! empty( $data['php'] ) );
			case 'fscan_step':
				return self::scan_step( isset( $data['id'] ) ? (string) $data['id'] : '', isset( $data['offset'] ) ? (int) $data['offset'] : 0 );
			case 'frun_start':
				return self::run_start( isset( $data['id'] ) ? (string) $data['id'] : '', isset( $data['ids'] ) ? (array) $data['ids'] : array() );
			case 'frun_step':
				return self::run_step( isset( $data['token'] ) ? (string) $data['token'] : '' );
			case 'fundo':
				return self::undo( isset( $data['token'] ) ? (string) $data['token'] : '' );
		}
		return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown request.' );
	}

	/** The places that may be searched: scope => list of folders. */
	private static function roots( $scopes ) {
		$u    = wp_upload_dir();
		$all  = array(
			'uploads' => array( $u['basedir'] ),
			'theme'   => array_values( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) ),
			'plugins' => array( WP_PLUGIN_DIR ),
		);
		$out = array();
		foreach ( $scopes as $s ) {
			if ( isset( $all[ $s ] ) ) {
				foreach ( $all[ $s ] as $dir ) {
					$real = realpath( $dir );
					if ( $real && is_dir( $real ) ) {
						$out[] = array( $s, wp_normalize_path( $real ) );
					}
				}
			}
		}
		return $out;
	}

	private static function inside( $path, $root ) {
		$path = wp_normalize_path( $path );
		return 0 === strpos( $path, rtrim( $root, '/' ) . '/' );
	}

	/** How a file is shown to a person: relative to wp-content (or the WordPress folder). */
	private static function display( $abs ) {
		$abs = wp_normalize_path( $abs );
		foreach ( array( wp_normalize_path( WP_CONTENT_DIR ), wp_normalize_path( untrailingslashit( ABSPATH ) ) ) as $base ) {
			if ( 0 === strpos( $abs, $base . '/' ) ) {
				return ltrim( substr( $abs, strlen( $base ) ), '/' );
			}
		}
		return basename( $abs );
	}

	private static function count( $content, $o ) {
		$n = 0;
		foreach ( SearchReplace::needles( $o ) as $nd ) {
			$n += $o['case'] ? substr_count( $content, $nd[0] ) : substr_count( strtolower( $content ), strtolower( $nd[0] ) );
		}
		return $n;
	}

	private static function scan_start( $o, $scopes, $php ) {
		$roots = self::roots( $scopes );
		if ( ! $roots ) {
			return array( 'ok' => false, 'code' => 'bad', 'message' => __( 'Choose where to look: uploads, the theme or plugins.', 'wpeasy-hub-connector' ) );
		}
		self::forget_old();
		$ext   = self::EXT;
		if ( $php ) {
			$ext[] = 'php';
		}
		$skip   = array_map( 'wp_normalize_path', array_filter( array( Backup::base() ) ) );
		$files  = array();
		$capped = false;
		foreach ( $roots as $r ) {
			list( $scope, $dir ) = $r;
			$it = new \RecursiveIteratorIterator( new \RecursiveCallbackFilterIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), function ( $cur ) use ( $skip ) {
				if ( $cur->isLink() ) {
					return false;
				}
				if ( $cur->isDir() ) {
					$p = wp_normalize_path( $cur->getPathname() );
					return ! in_array( $cur->getFilename(), self::SKIP_DIRS, true ) && ! in_array( $p, $skip, true ) && 0 !== strpos( $cur->getFilename(), 'wpeasy-hub-' );
				}
				return true;
			} ), \RecursiveIteratorIterator::LEAVES_ONLY );
			foreach ( $it as $f ) {
				if ( ! $f->isFile() || $f->isLink() ) {
					continue;
				}
				$e = strtolower( pathinfo( $f->getFilename(), PATHINFO_EXTENSION ) );
				if ( ! in_array( $e, $ext, true ) ) {
					continue;
				}
				if ( count( $files ) >= self::MAX_FILES ) {
					$capped = true;
					break 2;
				}
				$files[] = array( wp_normalize_path( $f->getPathname() ), $scope );
			}
		}
		$id = bin2hex( random_bytes( 10 ) );
		update_option( self::PREVIEW . $id, array( 'made' => time(), 'o' => $o, 'files' => $files, 'matches' => array(), 'roots' => $roots, 'big' => 0, 'unreadable' => 0, 'capped' => $capped ), false );
		return array( 'ok' => true, 'id' => $id, 'files' => count( $files ), 'capped' => $capped );
	}

	private static function scan_step( $id, $offset ) {
		if ( ! preg_match( '/^[a-f0-9]{20}$/', $id ) ) {
			return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown preview.' );
		}
		$st = get_option( self::PREVIEW . $id );
		if ( ! is_array( $st ) ) {
			return array( 'ok' => false, 'code' => 'gone', 'message' => __( 'This preview is no longer known on the website. Preview again.', 'wpeasy-hub-connector' ) );
		}
		$until = microtime( true ) + self::BUDGET;
		$i     = max( 0, $offset );
		$new   = array();
		$total = count( $st['files'] );
		for ( ; $i < $total && microtime( true ) < $until; $i++ ) {
			list( $abs, $scope ) = $st['files'][ $i ];
			$size = @filesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file may vanish while we look
			if ( false === $size ) {
				++$st['unreadable'];
				continue;
			}
			if ( $size > self::MAX_BYTES ) {
				++$st['big'];
				continue;
			}
			$content = @file_get_contents( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $content ) {
				++$st['unreadable'];
				continue;
			}
			$n = self::count( $content, $st['o'] );
			if ( $n > 0 && count( $st['matches'] ) < self::MAX_MATCH ) {
				$st['matches'][] = array( $abs, $scope, $n, (int) $size );
				$new[]           = array( 'i' => count( $st['matches'] ) - 1, 'path' => self::display( $abs ), 'scope' => $scope, 'n' => $n, 'size' => (int) $size, 'writable' => is_writable( $abs ) && is_writable( dirname( $abs ) ) );
			}
		}
		update_option( self::PREVIEW . $id, $st, false );
		return array( 'ok' => true, 'offset' => $i, 'total' => $total, 'done' => $i >= $total, 'found' => count( $st['matches'] ), 'more_than_shown' => count( $st['matches'] ) >= self::MAX_MATCH, 'new' => $new, 'big' => (int) $st['big'], 'unreadable' => (int) $st['unreadable'], 'capped' => ! empty( $st['capped'] ) );
	}

	private static function journal_dir( $token ) {
		return rtrim( Backup::base(), '/' ) . '/sr-' . $token;
	}

	private static function run_start( $id, $ids ) {
		if ( ! preg_match( '/^[a-f0-9]{20}$/', $id ) ) {
			return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown preview.' );
		}
		$st = get_option( self::PREVIEW . $id );
		if ( ! is_array( $st ) || $st['made'] < time() - HOUR_IN_SECONDS ) {
			return array( 'ok' => false, 'code' => 'gone', 'message' => __( 'The preview is too old (older than an hour) or no longer known. Preview again.', 'wpeasy-hub-connector' ) );
		}
		$items = array();
		foreach ( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, self::MAX_MATCH ) as $i ) {
			if ( isset( $st['matches'][ $i ] ) ) {
				$items[] = array( $st['matches'][ $i ][0], $st['matches'][ $i ][1] );
			}
		}
		if ( ! $items ) {
			return array( 'ok' => false, 'code' => 'bad', 'message' => __( 'Choose at least one file.', 'wpeasy-hub-connector' ) );
		}
		$token = bin2hex( random_bytes( 10 ) );
		$dir   = self::journal_dir( $token );
		if ( ! wp_mkdir_p( $dir ) || ! is_writable( $dir ) ) {
			return array( 'ok' => false, 'code' => 'journal', 'message' => __( 'Could not make a safe place to keep the original files, so nothing was changed.', 'wpeasy-hub-connector' ) );
		}
		update_option( self::RUN . $token, array( 'made' => time(), 'o' => $st['o'], 'items' => $items, 'roots' => $st['roots'], 'idx' => 0, 'changed' => 0, 'occ' => 0, 'skipped' => array(), 'n' => 0, 'done' => false ), false );
		file_put_contents( $dir . '/index.json', wp_json_encode( array() ) );
		return array( 'ok' => true, 'token' => $token, 'total' => count( $items ) );
	}

	private static function allowed( $abs, $roots ) {
		$real = realpath( $abs );
		if ( ! $real || is_link( $abs ) ) {
			return false;
		}
		foreach ( $roots as $r ) {
			if ( self::inside( $real, $r[1] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function run_step( $token ) {
		if ( ! preg_match( '/^[a-f0-9]{20}$/', $token ) ) {
			return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown run.' );
		}
		$st = get_option( self::RUN . $token );
		if ( ! is_array( $st ) ) {
			return array( 'ok' => false, 'code' => 'gone', 'message' => __( 'This run is no longer known on the website.', 'wpeasy-hub-connector' ) );
		}
		$dir   = self::journal_dir( $token );
		$index = json_decode( (string) @file_get_contents( $dir . '/index.json' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$index = is_array( $index ) ? $index : array();
		$until = microtime( true ) + self::BUDGET;
		$total = count( $st['items'] );
		while ( $st['idx'] < $total && microtime( true ) < $until && empty( $st['done'] ) ) {
			list( $abs ) = $st['items'][ $st['idx'] ];
			++$st['idx'];
			$why = self::change( $abs, $st, $dir, $index, $token );
			if ( is_string( $why ) && count( $st['skipped'] ) < 100 ) {
				$st['skipped'][] = array( 'path' => self::display( $abs ), 'why' => $why );
			}
		}
		file_put_contents( $dir . '/index.json', wp_json_encode( $index ) );
		if ( $st['idx'] >= $total ) {
			$st['done'] = true;
		}
		update_option( self::RUN . $token, $st, false );
		return array( 'ok' => true, 'token' => $token, 'done' => ! empty( $st['done'] ), 'changed' => (int) $st['changed'], 'occurrences' => (int) $st['occ'], 'left' => max( 0, $total - $st['idx'] ), 'skipped' => $st['skipped'] );
	}

	/** Changes one file. Returns true if changed, false if nothing needed changing, or a plain reason string when it was left alone. */
	private static function change( $abs, &$st, $dir, &$index, $token ) {
		if ( ! self::allowed( $abs, $st['roots'] ) ) {
			return __( 'not in the chosen places', 'wpeasy-hub-connector' );
		}
		if ( ! is_file( $abs ) || ! is_readable( $abs ) ) {
			return __( 'no longer there', 'wpeasy-hub-connector' );
		}
		if ( ! is_writable( $abs ) || ! is_writable( dirname( $abs ) ) ) {
			return __( 'the host does not let it be changed', 'wpeasy-hub-connector' );
		}
		$old = file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $old || strlen( $old ) > self::MAX_BYTES ) {
			return __( 'could not be read', 'wpeasy-hub-connector' );
		}
		$new = SearchReplace::plain( $old, $st['o'] );
		if ( $new === $old ) {
			return false;
		}
		// Keep the original first, outside the web.
		++$st['n'];
		$keep = $dir . '/' . $st['n'] . '.orig';
		if ( false === file_put_contents( $keep, $old ) || filesize( $keep ) !== strlen( $old ) ) {
			@unlink( $keep ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return __( 'the original could not be kept first', 'wpeasy-hub-connector' );
		}
		$perm = fileperms( $abs ) & 0777;
		$tmp  = $abs . '.hubtmp';
		if ( false === file_put_contents( $tmp, $new, LOCK_EX ) || filesize( $tmp ) !== strlen( $new ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $keep ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return __( 'could not be written', 'wpeasy-hub-connector' );
		}
		@chmod( $tmp, $perm ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.chmod_chmod
		if ( ! @rename( $tmp, $abs ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $keep ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return __( 'could not be replaced', 'wpeasy-hub-connector' );
		}
		clearstatcache( true, $abs );
		if ( function_exists( 'opcache_invalidate' ) && 'php' === strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) ) ) {
			@opcache_invalidate( $abs, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$index[] = array( 'p' => $abs, 'o' => $st['n'], 'm' => md5( $new ), 'perm' => $perm );
		++$st['changed'];
		$st['occ'] += self::count( $old, $st['o'] );
		return true;
	}

	/** Puts the original files back, only where the file is still the one the run wrote. The website works about 20 seconds per request; `left` says if more is waiting. */
	private static function undo( $token ) {
		if ( ! preg_match( '/^[a-f0-9]{20}$/', $token ) ) {
			return array( 'ok' => false, 'code' => 'unknown', 'message' => 'Unknown run.' );
		}
		$dir   = self::journal_dir( $token );
		$index = is_file( $dir . '/index.json' ) ? json_decode( (string) file_get_contents( $dir . '/index.json' ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$st    = get_option( self::RUN . $token );
		if ( ! is_array( $index ) || ! is_array( $st ) ) {
			return array( 'ok' => false, 'code' => 'gone', 'message' => __( 'The original files are no longer kept on the website (they are kept for 30 days), so this cannot be undone here. Restore a backup instead.', 'wpeasy-hub-connector' ) );
		}
		$until = microtime( true ) + self::BUDGET;
		$back  = 0;
		$moved = 0;
		while ( $index && microtime( true ) < $until ) {
			$e   = array_pop( $index );
			$abs = $e['p'];
			if ( self::allowed( $abs, $st['roots'] ) && is_file( $abs ) && md5( (string) file_get_contents( $abs ) ) === $e['m'] && is_writable( $abs ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$orig = $dir . '/' . (int) $e['o'] . '.orig';
				$tmp  = $abs . '.hubtmp';
				if ( is_file( $orig ) && false !== copy( $orig, $tmp ) ) {
					@chmod( $tmp, (int) $e['perm'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.chmod_chmod
					if ( @rename( $tmp, $abs ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename
						clearstatcache( true, $abs );
						++$back;
						continue;
					}
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
			++$moved; // changed since (or not writable now): left as it is
		}
		file_put_contents( $dir . '/index.json', wp_json_encode( $index ) );
		if ( ! $index ) {
			self::remove_dir( $dir );
			delete_option( self::RUN . $token );
		}
		return array( 'ok' => true, 'restored' => $back, 'changed_since' => $moved, 'left' => count( $index ) );
	}

	private static function remove_dir( $dir ) {
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			is_file( $f ) && @unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rmdir_rmdir
	}

	/** Forgets previews, runs and kept originals older than their time. */
	public static function forget_old() {
		global $wpdb;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( self::PREVIEW ) . '%', $wpdb->esc_like( self::RUN ) . '%' ) ) as $name ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$v    = get_option( $name );
			$cut  = 0 === strpos( $name, self::PREVIEW ) ? time() - DAY_IN_SECONDS : time() - self::KEEP;
			if ( ! is_array( $v ) || ( isset( $v['made'] ) && $v['made'] < $cut ) ) {
				delete_option( $name );
			}
		}
		foreach ( (array) glob( rtrim( Backup::base(), '/' ) . '/sr-*', GLOB_ONLYDIR ) as $d ) {
			if ( filemtime( $d ) < time() - self::KEEP ) {
				self::remove_dir( $d );
			}
		}
	}
}
