<?php
/**
 * Cache warmer: a low-frequency cron crawler that re-generates cache files
 * for popular URLs by requesting the site itself.
 *
 * SSRF-hardened: every URL must resolve to the site's own host with an
 * http/https scheme — anything else (incl. private/loopback targets from a
 * tampered sitemap) is rejected.
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class Warmer {

	const CRON_HOOK  = 'feathercache_warmer_tick';
	const CRON_NOW   = 'feathercache_warmer_now';
	const TIME_BUDGET = 25; // seconds per run.

	public static function boot(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( self::CRON_NOW, array( __CLASS__, 'run' ) );
	}

	public static function schedule(): void {
		$sett = Options::all();
		if ( ! empty( $sett['warmer_enabled'] ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_NOW );
	}

	public static function run( int $limit = 0 ): void {
		if ( defined( 'WP_CLI' ) && 0 === $limit ) {
			$limit = 200;
		}
		if ( 0 === $limit ) {
			$sett  = Options::all();
			$limit = (int) $sett['warmer_limit'];
		}
		if ( $limit <= 0 ) {
			return;
		}

		$start   = microtime( true );
		$visited = array();

		foreach ( self::collectUrls( $limit ) as $url ) {
			if ( microtime( true ) - $start > self::TIME_BUDGET ) {
				break;
			}
			if ( ! self::isOwnUrl( $url ) ) {
				continue;
			}
			$visited[] = $url;

			wp_remote_get(
				$url,
				array(
					'timeout'     => 8,
					'user-agent'  => 'EvolutionCache-Warmer/1.0; ' . home_url(),
					'sslverify'   => true,
					'blocking'    => true,
					'redirection' => 0, // Never follow off-site redirects.
				)
			);
		}

		update_option( 'feathercache_last_warm', time(), false );
	}

	/**
	 * Homepage + URLs from the site's own sitemap(s).
	 */
	private static function collectUrls( int $limit ): array {
		$urls = array( home_url( '/' ) );

		$candidates = array(
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
		);

		foreach ( $candidates as $indexUrl ) {
			if ( ! self::isOwnUrl( $indexUrl ) ) {
				continue;
			}
			$res = wp_remote_get( $indexUrl, array( 'timeout' => 5, 'redirection' => 0 ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				continue;
			}
			$xml = (string) wp_remote_retrieve_body( $res );
			preg_match_all( '#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m );

			$childSitemaps = array();
			$pageUrls      = array();

			foreach ( $m[1] as $loc ) {
				$loc = esc_url_raw( $loc );
				if ( '' === $loc ) {
					continue;
				}
				if ( preg_match( '#\.xml($|\?)#i', $loc ) ) {
					$childSitemaps[] = $loc;
				} else {
					$pageUrls[] = $loc;
				}
			}

			// Drill into up to 5 child sitemaps — each validated BEFORE fetching.
			foreach ( array_slice( $childSitemaps, 0, 5 ) as $child ) {
				if ( count( $pageUrls ) >= $limit * 3 ) {
					break;
				}
				if ( ! self::isOwnUrl( $child ) ) {
					continue;
				}
				$res = wp_remote_get( $child, array( 'timeout' => 5, 'redirection' => 0 ) );
				if ( is_wp_error( $res ) ) {
					continue;
				}
				preg_match_all( '#<loc>\s*([^<\s]+)\s*</loc>#i', (string) wp_remote_retrieve_body( $res ), $cm );
				foreach ( $cm[1] as $loc ) {
					$pageUrls[] = esc_url_raw( $loc );
				}
			}

			$urls = array_merge( $urls, $pageUrls );
			break;
		}

		$urls = array_values( array_unique( $urls ) );

		$sett    = Options::all();
		$excludes = Options::parseLines( (string) $sett['exclude_urls'] );
		$out     = array();
		foreach ( $urls as $url ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( ! self::isOwnUrl( $url ) ) {
				continue;
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$skip = false;
			foreach ( $excludes as $ex ) {
				if ( '' !== $ex && 0 === strpos( $path, $ex ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}
			$out[] = $url;
		}
		return $out;
	}

	/**
	 * Only the site's own http(s) URLs. Rejects loopback/private/reserved
	 * hosts, other schemes and foreign hosts.
	 */
	public static function isOwnUrl( string $url ): bool {
		$parts = wp_parse_url( $url );

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' === $host || '' === $home || $host !== $home ) {
			return false;
		}

		// Only standard web ports — blocks internal service probing.
		$port = $parts['port'] ?? null;
		if ( null !== $port && ! in_array( (int) $port, array( 80, 443 ), true ) ) {
			return false;
		}

		// Defense in depth: never allow literal IP / loopback-shaped hosts.
		if ( preg_match( '/^(localhost|127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.|\[?::1\]?$)/', $host ) ) {
			return false;
		}

		return true;
	}
}
