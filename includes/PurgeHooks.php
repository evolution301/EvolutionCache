<?php
/**
 * Cache invalidation hooks. Default strategy: purge all on content change
 * (safe and simple); smarter per-URL purging is a roadmap item.
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class PurgeHooks {

	public static function boot(): void {
		$sett = Options::all();
		if ( empty( $sett['page_cache'] ) || empty( $sett['purge_on_save'] ) ) {
			return;
		}

		add_action( 'transition_post_status', array( __CLASS__, 'onPostTransition' ), 10, 3 );
		add_action( 'comment_post', array( __CLASS__, 'onComment' ), 10, 2 );
		add_action( 'edited_term', array( __CLASS__, 'flush' ) );

		// Storefront changes.
		add_action( 'switch_theme', array( __CLASS__, 'flush' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'flush' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'flush' ) );

		// WooCommerce (hooks are no-ops when WC is absent — no load-order guard needed).
		add_action( 'woocommerce_update_product', array( __CLASS__, 'flush' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'flush' ) );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'flush' ) );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'flush' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush' ) );
	}

	public static function onPostTransition( string $newStatus, string $oldStatus, object $post ): void {
		if ( 'publish' !== $newStatus && 'publish' !== $oldStatus ) {
			return;
		}
		if ( ! isset( $post->post_type ) ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( $type && empty( $type->public ) ) {
			return;
		}
		self::flush();
	}

	/**
	 * Only approved comments invalidate the cache, throttled to 1/60s —
	 * unapproved/spam floods must not be able to nuke the whole cache.
	 */
	public static function onComment( $id, $approved ): void {
		if ( 1 !== (int) $approved && '1' !== $approved ) {
			return;
		}
		if ( get_transient( 'feathercache_comment_flush' ) ) {
			return;
		}
		set_transient( 'feathercache_comment_flush', 1, 60 );
		self::flush();
	}

	public static function flush(): void {
		CacheStore::flush();
	}
}
