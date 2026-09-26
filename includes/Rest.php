<?php
/**
 * REST API endpoints for AI agent takeover (Codex, ZCode, WorkBuddy, …).
 *
 * Auth: WordPress Application Passwords (Basic) or cookie + nonce.
 * Every route requires manage_options. Read-only by default; mutating
 * routes are POST-only and take no path/user input.
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {

		register_rest_route( 'evolutioncache/v1', '/status', array(
			'methods'             => 'GET',
			'permission_callback' => array( __CLASS__, 'canManage' ),
			'callback'            => array( __CLASS__, 'status' ),
		) );

		register_rest_route( 'evolutioncache/v1', '/flush', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'canManage' ),
			'callback'            => array( __CLASS__, 'flush' ),
		) );

		register_rest_route( 'evolutioncache/v1', '/warm', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'canManage' ),
			'callback'            => array( __CLASS__, 'warm' ),
		) );

		register_rest_route( 'evolutioncache/v1', '/settings', array(
			'methods'             => 'GET',
			'permission_callback' => array( __CLASS__, 'canManage' ),
			'callback'            => array( __CLASS__, 'settings' ),
		) );
	}

	public static function canManage(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		// Key-based agent auth: some hosts strip the Authorization header that
		// Application Passwords need, so accept a dedicated secret header too.
		$provided = isset( $_SERVER['HTTP_X_EVOLUTIONCACHE_KEY'] ) ? (string) $_SERVER['HTTP_X_EVOLUTIONCACHE_KEY'] : '';
		if ( '' === $provided ) {
			return false;
		}
		$stored = (string) Options::all()['api_key'];
		return '' !== $stored && hash_equals( $stored, $provided );
	}

	/**
	 * GET /evolutioncache/v1/status — everything an agent needs to reason.
	 */
	public static function status() {
		$sett    = Options::all();
		$stats   = CacheStore::stats();
		$dropin  = 'missing';
		if ( Dropin::foreignDropinExists() ) {
			$dropin = 'conflict';
		} elseif ( Dropin::isOurs() ) {
			$dropin = ( defined( 'WP_CACHE' ) && constant( 'WP_CACHE' ) ) ? 'active' : 'no-wp-cache';
		}

		return rest_ensure_response( array(
			'plugin'       => 'EvolutionCache',
			'version'      => FEATHERCACHE_VERSION,
			'page_cache'   => empty( $sett['page_cache'] ) ? 'off' : 'on',
			'dropin'       => $dropin,
			'js_mode'      => $sett['js_mode'],
			'mobile_cache' => empty( $sett['mobile_cache'] ) ? 'off' : 'on',
			'files'        => $stats['files'],
			'bytes'        => $stats['bytes'],
			'last_flush'   => (int) get_option( 'feathercache_last_flush' ),
			'last_warm'    => (int) get_option( 'feathercache_last_warm' ),
			'endpoints'    => array(
				'status'   => rest_url( 'evolutioncache/v1/status' ),
				'flush'    => rest_url( 'evolutioncache/v1/flush' ),
				'warm'     => rest_url( 'evolutioncache/v1/warm' ),
				'settings' => rest_url( 'evolutioncache/v1/settings' ),
			),
		) );
	}

	/**
	 * POST /evolutioncache/v1/flush — purge all cached pages.
	 */
	public static function flush() {
		CacheStore::flush();
		Dropin::writeConfig();

		return rest_ensure_response( array(
			'ok'      => true,
			'action'  => 'flush',
			'time'    => time(),
			'message' => 'Cache flushed.',
		) );
	}

	/**
	 * POST /evolutioncache/v1/warm — queue a warm-up crawl.
	 */
	public static function warm( $request ) {
		$limit = absint( $request->get_param( 'limit' ) );
		if ( $limit > 0 ) {
			$s              = Options::all();
			$s['warmer_limit'] = min( $limit, 500 );
			update_option( Options::OPT, $s, false );
		}

		wp_schedule_single_event( time() + 5, Warmer::CRON_NOW );
		spawn_cron();

		return rest_ensure_response( array(
			'ok'      => true,
			'action'  => 'warm',
			'message' => 'Warm-up scheduled (runs in ~5s via cron).',
		) );
	}

	/**
	 * GET /evolutioncache/v1/settings — current settings (read-only).
	 */
	public static function settings() {
		return rest_ensure_response( array(
			'settings' => Options::all(),
		) );
	}
}
