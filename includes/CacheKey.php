<?php
/**
 * Cache key construction (WP context).
 *
 * NOTE: the standalone advanced-cache.php drop-in contains a copy of this
 * normalization because it runs before WordPress loads. Keep both in sync.
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class CacheKey {

	/**
	 * Parameters stripped from the query string before cacheability checks.
	 * Pure tracking garbage — removing them merges duplicate URLs.
	 */
	const IGNORED_PARAMS = '/^(utm_|mtm_|fbclid|gclid|msclkid|mc_cid|mc_eid|_ga)/i';

	/**
	 * @return array{0:string,1:bool} [normalized cache key, isMobile]
	 */
	public static function build( array $sett ): array {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$parts = wp_parse_url( $uri );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}

		parse_str( isset( $parts['query'] ) ? (string) $parts['query'] : '', $params );

		$whitelist = Options::parseLines( (string) ( $sett['query_whitelist'] ?? '' ) );
		$keep      = array();
		foreach ( $whitelist as $k ) {
			if ( isset( $params[ $k ] ) ) {
				$keep[ $k ] = $params[ $k ];
			}
		}
		ksort( $keep );
		$query = $keep ? http_build_query( $keep ) : '';

		$view = 'd';
		if ( ! empty( $sett['mobile_cache'] ) && self::isMobile() ) {
			$view = 'm';
		}

		// TranslatePress may carry the visitor language in a cookie. Folding
		// its (strictly validated) value into the key keeps language views
		// from leaking across visitors; unknown values are ignored.
		$trp = '';
		if ( isset( $_COOKIE['trp_language'] ) && is_string( $_COOKIE['trp_language'] )
			&& preg_match( '/^[a-zA-Z]{2,3}(_[a-zA-Z]{2,6})?$/', $_COOKIE['trp_language'] ) ) {
			$trp = '|trp:' . strtolower( $_COOKIE['trp_language'] );
		}

		$key = $scheme . '://' . $host . $path . ( '' !== $query ? '?' . $query : '' ) . $trp;
		return array( $key, 'm' === $view );
	}

	public static function isMobile(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( '' === $ua ) {
			return false;
		}
		return (bool) preg_match( '/(mobile|android|iphone|ipod|kindle|silk|blackberry|opera mini|opera mobi|iemobile|windows phone)/i', $ua );
	}
}
