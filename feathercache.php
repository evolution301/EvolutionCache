<?php
/**
 * Plugin Name:       EvolutionCache
 * Description:       轻量级整页缓存与前端性能优化（页面缓存 / CSS / JS / 图片 / 字体）。Fail-open 设计：任何优化出错自动回退原样输出，绝不影响页面显示。无许可证、无遥测、零第三方依赖。
 * Version:           1.3.3
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Evolution301
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       feathercache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

define( 'FEATHERCACHE_VERSION', '1.3.3' );
define( 'FEATHERCACHE_FILE', __FILE__ );
define( 'FEATHERCACHE_DIR', __DIR__ . '/' );
define( 'FEATHERCACHE_CACHE_DIR', WP_CONTENT_DIR . '/cache/feathercache/' );

require_once FEATHERCACHE_DIR . 'includes/Options.php';
require_once FEATHERCACHE_DIR . 'includes/CacheStore.php';
require_once FEATHERCACHE_DIR . 'includes/CacheKey.php';
require_once FEATHERCACHE_DIR . 'includes/CacheRules.php';
require_once FEATHERCACHE_DIR . 'includes/Dropin.php';
require_once FEATHERCACHE_DIR . 'includes/HtmlPipeline.php';
	require_once FEATHERCACHE_DIR . 'includes/Opt/Css.php';
	require_once FEATHERCACHE_DIR . 'includes/Opt/Js.php';
	require_once FEATHERCACHE_DIR . 'includes/Opt/Images.php';
	require_once FEATHERCACHE_DIR . 'includes/Opt/A11y.php';
	require_once FEATHERCACHE_DIR . 'includes/Opt/Fonts.php';
	require_once FEATHERCACHE_DIR . 'includes/PurgeHooks.php';
	require_once FEATHERCACHE_DIR . 'includes/Warmer.php';
	require_once FEATHERCACHE_DIR . 'includes/Rest.php';
	require_once FEATHERCACHE_DIR . 'includes/Admin.php';
	require_once FEATHERCACHE_DIR . 'includes/Cli.php';

/**
 * Bootstrap. All entry hooks are registered here; every feature module
 * independently fails open (see HtmlPipeline::apply / serve-side drop-in).
 */
final class Plugin {

	/**
	 * Replace-installs leave stale OPcache bytecodes for our absolute paths,
	 * which can break wp-admin until PHP restarts. Clear it automatically:
	 * - always on activation;
	 * - once per version bump, triggered by the first request that boots
	 *   the plugin (a tiny stat-check per request).
	 */
	public static function maybeClearOpcache(): void {
		if ( ! function_exists( 'opcache_reset' ) ) {
			return;
		}
		try {
			CacheStore::initDirs();
			$marker = FEATHERCACHE_CACHE_DIR . 'assets/.opc-' . FEATHERCACHE_VERSION;
			if ( file_exists( $marker ) ) {
				return;
			}
			@opcache_reset();
			foreach ( glob( FEATHERCACHE_DIR . '*.php' ) as $file ) {
				@opcache_invalidate( $file, true );
			}
			foreach ( glob( FEATHERCACHE_DIR . 'includes/*.php' ) as $file ) {
				@opcache_invalidate( $file, true );
			}
			foreach ( glob( FEATHERCACHE_DIR . 'includes/Opt/*.php' ) as $file ) {
				@opcache_invalidate( $file, true );
			}
			@file_put_contents( $marker, (string) time() );
		} catch ( \Throwable $e ) { // phpcs:ignore
			// Fail-open: OPcache handling must never break the site.
		}
	}

	public static function boot(): void {
		// First thing on every request: clear stale OPcache once per version.
		self::maybeClearOpcache();

		register_activation_hook( FEATHERCACHE_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( FEATHERCACHE_FILE, array( __CLASS__, 'deactivate' ) );

		// Front-end output pipeline (page cache + HTML optimizations).
		add_action( 'template_redirect', array( HtmlPipeline::class, 'boot' ), 2 );

		// Cache invalidation hooks.
		PurgeHooks::boot();

		// Warmer cron.
		Warmer::boot();

		// Font resource hints.
		add_action( 'init', array( Opt\Fonts_Hints::class, 'boot' ) );

		// Self-heal runtime files on every request (two stat calls when
		// everything is fine) — platforms that wipe wp-content/cache or
		// stray deletions recover on the next request instead of waiting
		// for the hourly guard cron.
		add_action( 'init', array( Dropin::class, 'ensureRuntime' ), 1 );

		// Admin UI.
		if ( is_admin() ) {
			Admin::boot();
			add_action( 'admin_init', array( __CLASS__, 'upgrade' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybeClearOpcache' ) );
		}

		// REST API for AI agent takeover (Codex / ZCode / WorkBuddy / …).
		Rest::boot();

		// Cron guard: rebuild runtime pieces if something wiped them, GC expired files.
		if ( ! wp_next_scheduled( 'feathercache_config_guard' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'feathercache_config_guard' );
		}
		add_action( 'feathercache_config_guard', array( Dropin::class, 'ensureRuntime' ) );
		add_action( 'feathercache_config_guard', array( CacheStore::class, 'gc' ) );
	}

	public static function activate(): void {
		$s = Options::all();
		update_option( Options::OPT, $s, false );
		Options::ensureApiKey();

		CacheStore::initDirs();
		self::maybeClearOpcache(); // Fresh bytecodes for this install/update.
		Dropin::install();
		Warmer::schedule();

		update_option( 'feathercache_activated_at', time(), false );
	}

	public static function deactivate(): void {
		Warmer::unschedule();
		wp_clear_scheduled_hook( 'feathercache_config_guard' );
		Dropin::remove();
		Dropin::removeWpCache();
		CacheStore::flush();
		delete_transient( 'feathercache_install_error' );
	}

	/**
	 * One-time upgrade routine (runs in admin until the stored version
	 * matches). v1.1.0: separate mobile cache defaults to off — modern
	 * responsive themes share one cached view, which raises the hit rate.
	 */
	public static function upgrade(): void {
		$stored = get_option( 'feathercache_version' );
		if ( FEATHERCACHE_VERSION === $stored ) {
			return;
		}
		if ( ! is_string( $stored ) || version_compare( $stored, '1.1.0', '<' ) ) {
			$s                 = Options::all();
			$s['mobile_cache'] = 0;
			update_option( Options::OPT, Options::sanitize( $s ), false ); // fires writeConfig/ensureRuntime hooks.
		}
		if ( ! is_string( $stored ) || version_compare( $stored, '1.2.0', '<' ) ) {
			$s = Options::all();
			// v1.2.0: keep WP core scripts out of the delay pipeline — their
			// load-order interleave caused "wp is not defined" console errors.
			$lines = Options::parseLines( (string) $s['js_delay_exclude'] );
			if ( ! in_array( 'wp-includes', $lines, true ) ) {
				$lines[] = 'wp-includes';
				$s['js_delay_exclude'] = implode( "\n", $lines );
				update_option( Options::OPT, Options::sanitize( $s ), false );
			}
		}
		if ( ! is_string( $stored ) || version_compare( $stored, '1.3.1', '<' ) ) {
			Options::ensureApiKey(); // AI takeover secret for existing installs.
		}
		update_option( 'feathercache_version', FEATHERCACHE_VERSION, false );
	}
}

Plugin::boot();
