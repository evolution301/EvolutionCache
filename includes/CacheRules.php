<?php
/**
 * Cacheability rules (request side runs in full WP context;
 * response side checks status/headers inside the output buffer callback).
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class CacheRules {

	/**
	 * Presence of any of these cookies disables the page cache for the
	 * request (logged-in users, commenters, carts, post passwords).
	 */
	const COOKIE_SKIP = '/^(wordpress_[0-9a-f]{32}|wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)/';

	public static function isCacheableRequest( array $sett ): bool {
		if ( empty( $sett['page_cache'] ) ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) || defined( 'DOING_CRON' ) || defined( 'DOING_AJAX' )
			|| defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'WP_IMPORTING' ) ) {
			return false;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( '' === $uri || '/' !== $uri[0] ) {
			return false;
		}

		$parts = wp_parse_url( $uri );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';

		// Never touch the admin area, login, REST, feeds, sitemaps, cron.
		foreach ( array( '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php', '/wp-cron.php', '/wp-sitemap', '/feed' ) as $needle ) {
			if ( false !== stripos( $path, $needle ) ) {
				return false;
			}
		}
		foreach ( array( 'feed', 'sitemap', 'preview', 'download_file' ) as $needle ) {
			if ( 0 === stripos( $query, $needle . '=' ) || preg_match( '/(^|&)' . $needle . '=/i', $query ) ) {
				return false;
			}
		}

		// Static-looking paths are never page-cached.
		if ( preg_match( '#\.[a-z0-9]{1,8}$#i', $path ) && ! preg_match( '#\.(php|html?)$#i', $path ) ) {
			return false;
		}

		// User cookies → dynamic.
		if ( ! empty( $_COOKIE ) && is_array( $_COOKIE ) ) {
			foreach ( array_keys( $_COOKIE ) as $cookie ) {
				if ( is_string( $cookie ) && preg_match( self::COOKIE_SKIP, $cookie ) ) {
					return false;
				}
			}
		}

		// Query string policy: drop tracking params; anything not whitelisted
		// makes the URL dynamic (uncached) — the safe default.
		parse_str( $query, $params );
		foreach ( array_keys( $params ) as $k ) {
			if ( is_string( $k ) && preg_match( CacheKey::IGNORED_PARAMS, $k ) ) {
				continue;
			}
			if ( ! in_array( (string) $k, Options::parseLines( (string) ( $sett['query_whitelist'] ?? '' ) ), true ) ) {
				return false;
			}
		}

		// User-defined exclusions: "#...#" is a regex, otherwise prefix match.
		foreach ( Options::parseLines( (string) ( $sett['exclude_urls'] ?? '' ) ) as $ex ) {
			if ( '' === $ex ) {
				continue;
			}
			if ( '#' === substr( $ex, 0, 1 ) ) {
				if ( @preg_match( $ex, $path ) ) { // phpcs:ignore
					return false;
				}
			} elseif ( 0 === strpos( $path, $ex ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Decide whether the rendered response may be stored.
	 */
	public static function isCacheableResponse( int $status ): bool {
		if ( 200 !== $status ) {
			return false;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		foreach ( headers_list() as $header ) {
			$lower = strtolower( $header );

			if ( 0 === strpos( $lower, 'content-type:' ) && false === strpos( $lower, 'text/html' ) ) {
				return false;
			}

			if ( 0 === strpos( $lower, 'set-cookie:' ) ) {
				$pair  = trim( substr( $header, 11 ) );
				$eq    = strpos( $pair, '=' );
				$name  = false === $eq ? $pair : substr( $pair, 0, $eq );
				// WooCommerce/TranslatePress set marketing cookies and some
				// plugins open PHP sessions (e.g. CF7 lead tracking) on normal
				// page loads without affecting output — safe to cache through.
				if ( ! preg_match( '/^(trp_language|wp_woocommerce_|woocommerce_|wordpress_test_cookie|phpsessid)/i', $name ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
