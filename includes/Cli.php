<?php
/**
 * WP-CLI commands:
 *   wp feathercache flush|warm|status|config
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class Cli {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'evolutioncache', array( __CLASS__, 'dispatch' ) );
		\WP_CLI::add_command( 'feathercache', array( __CLASS__, 'dispatch' ) ); // Legacy alias.
	}

	/**
	 * Manage FeatherCache.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : flush | warm | status | config
	 *
	 * ## EXAMPLES
	 *
	 *     wp feathercache flush
	 *     wp feathercache warm --limit=100
	 *
	 * @param array $args       Positional args.
	 * @param array $assocArgs  Flags.
	 */
	public static function dispatch( array $args, array $assocArgs = array() ): void {
		$action = isset( $args[0] ) ? sanitize_key( $args[0] ) : 'status';

		switch ( $action ) {
			case 'flush':
				CacheStore::flush();
				Dropin::writeConfig();
				\WP_CLI::success( 'Cache flushed.' );
				break;

			case 'warm':
				$limit = isset( $assocArgs['limit'] ) ? absint( $assocArgs['limit'] ) : 0;
				Warmer::run( $limit );
				\WP_CLI::success( 'Warm-up finished.' );
				break;

			case 'config':
				Dropin::writeConfig();
				\WP_CLI::success( 'Runtime config rewritten.' );
				break;

			case 'status':
			default:
				$s     = Options::all();
				$stats = CacheStore::stats();
				\WP_CLI::line( 'page_cache:   ' . ( ! empty( $s['page_cache'] ) ? 'on' : 'off' ) );
				\WP_CLI::line( 'dropin:       ' . ( Dropin::isOurs() ? 'ok' : ( Dropin::foreignDropinExists() ? 'CONFLICT (foreign advanced-cache.php)' : 'missing' ) ) );
				\WP_CLI::line( 'WP_CACHE:     ' . ( defined( 'WP_CACHE' ) && constant( 'WP_CACHE' ) ? 'true' : 'false' ) );
				\WP_CLI::line( 'files:        ' . $stats['files'] . ' (' . size_format( $stats['bytes'] ) . ')' );
				\WP_CLI::line( 'last_flush:   ' . ( get_option( 'feathercache_last_flush' ) ? date_i18n( 'Y-m-d H:i:s', (int) get_option( 'feathercache_last_flush' ) ) : 'never' ) );
				\WP_CLI::line( 'last_warm:    ' . ( get_option( 'feathercache_last_warm' ) ? date_i18n( 'Y-m-d H:i:s', (int) get_option( 'feathercache_last_warm' ) ) : 'never' ) );
				break;
		}
	}
}

Cli::register();
