<?php
/**
 * Uninstall: remove the drop-in, the WP_CACHE line, options, cron events
 * and the cache directory. Runs without the plugin loaded.
 *
 * @package FeatherCache
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/* 1. Remove our advanced-cache.php drop-in (never a foreign one). */
$__fc_dropin = WP_CONTENT_DIR . '/advanced-cache.php';
if ( file_exists( $__fc_dropin ) ) {
	$__fc_c = file_get_contents( $__fc_dropin );
	if ( is_string( $__fc_c ) && false !== strpos( $__fc_c, 'Managed by FeatherCache' ) ) {
		@unlink( $__fc_dropin );
	}
	unset( $__fc_c );
}

/* 2. Remove the WP_CACHE line we added (atomically) and any key-material leftovers. */
foreach ( array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' ) as $__fc_cfg ) {
	if ( ! file_exists( $__fc_cfg ) ) {
		continue;
	}
	// Leftover temp/backup copies must never stay in the web root.
	@unlink( $__fc_cfg . '.feathercache-backup' );
	@unlink( $__fc_cfg . '.feathercache.tmp' );

	if ( ! is_writable( $__fc_cfg ) ) {
		continue;
	}
	$__fc_c = file_get_contents( $__fc_cfg );
	if ( is_string( $__fc_c ) && false !== strpos( $__fc_c, 'Added by FeatherCache' ) ) {
		$__fc_clean = preg_replace(
			"/^\s*define\(\s*'WP_CACHE',\s*true\s*\);\s*\/\/ Added by FeatherCache\r?\n/m",
			'',
			$__fc_c
		);
		if ( is_string( $__fc_clean ) && '' !== $__fc_clean && $__fc_clean !== $__fc_c ) {
			$__fc_tmp = $__fc_cfg . '.feathercache.tmp';
			if ( false !== @file_put_contents( $__fc_tmp, $__fc_clean, LOCK_EX ) ) {
				@rename( $__fc_tmp, $__fc_cfg );
				@unlink( $__fc_tmp );
			}
		}
	}
	break;
}
unset( $__fc_c, $__fc_clean, $__fc_cfg );

/* 3. Options + transients. */
delete_option( 'feathercache_settings' );
delete_option( 'feathercache_last_flush' );
delete_option( 'feathercache_last_warm' );
delete_option( 'feathercache_activated_at' );
delete_transient( 'feathercache_comment_flush' );
delete_transient( 'feathercache_install_error' );

/* 4. Cron. */
wp_clear_scheduled_hook( 'feathercache_warmer_tick' );
wp_clear_scheduled_hook( 'feathercache_warmer_now' );
wp_clear_scheduled_hook( 'feathercache_config_guard' );

/* 5. Cache directory. */
$__fc_root = WP_CONTENT_DIR . '/cache/feathercache/';
if ( is_dir( $__fc_root ) ) {
	try {
		$__fc_it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $__fc_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $__fc_it as $__fc_f ) {
			if ( $__fc_f->isDir() ) {
				@rmdir( $__fc_f->getPathname() );
			} else {
				@unlink( $__fc_f->getPathname() );
			}
		}
		@rmdir( $__fc_root );
	} catch ( Throwable $e ) { // phpcs:ignore
		// Best effort — never block uninstall.
	}
}
