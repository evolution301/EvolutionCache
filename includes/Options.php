<?php
/**
 * Settings schema, defaults, storage and sanitization.
 * Stored as one autoload=no option array. No external requests, no SQL.
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class Options {

	const OPT = 'feathercache_settings';

	/**
	 * Default settings. bool-like entries are stored as 0/1 ints.
	 */
	public static function defaults(): array {
		return array(
			// Page cache.
			'page_cache'        => 1,
			'cache_ttl'         => 3600,
			'mobile_cache'      => 0, // Responsive themes don't need a separate mobile view.
			'gzip'              => 1,
			'serve_header'      => 1,
			'query_whitelist'   => "p\npage_id\npage\npaged\nlang\ntrp-language\npost_type\ncpage",
			'exclude_urls'      => "/cart\n/checkout\n/my-account",
			// HTML.
			'minify_html'       => 1,
			// CSS.
			'css_minify'        => 1,
			'css_defer'         => 1,
			'css_exclude'       => '',
			'critical_css'      => '',
			// JS.
			'js_mode'           => 'delay', // off | defer | delay
			'js_delay_exclude'  => "cookie\nwp-includes\nturnstile\ncontact-form\ncf7\nrecaptcha\ngtag/js\nanalytics\ngtm.js",
			'js_delay_timeout'  => 4000,
			// Images.
			'img_lazy'          => 1,
			'img_lcp_preload'   => 1,
			'img_resize'        => 1,
			// Fonts.
			'fonts_preconnect'  => 1,
			// Warmer.
			'warmer_enabled'    => 1,
			'warmer_limit'      => 20,
			// Invalidation.
			'purge_on_save'     => 1,
			// AI takeover secret (generated on activation / upgrade).
			'api_key'           => '',
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPT, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Whitelist-based sanitizer. Unknown keys are dropped; every value is
	 * type-cast. Unchecked checkboxes are absent from the POST body, so a
	 * missing bool key means 0 (off), NOT the default.
	 */
	public static function sanitize( array $in ): array {
		$defaults = self::defaults();
		$out      = array();

		foreach ( $defaults as $key => $default ) {
			$val = array_key_exists( $key, $in ) ? $in[ $key ] : null;

			if ( is_int( $default ) ) {
				$out[ $key ] = ( null === $val ) ? $default : absint( $val );
			} elseif ( 'js_mode' === $key ) {
				$out[ $key ] = in_array( (string) $val, array( 'off', 'defer', 'delay' ), true ) ? (string) $val : 'defer';
			} elseif ( is_string( $default ) ) {
				$out[ $key ] = ( null === $val ) ? $default : sanitize_textarea_field( (string) $val );
			} else {
				$out[ $key ] = empty( $val ) ? 0 : 1;
			}
		}

		// Range clamps.
		$out['cache_ttl']        = min( max( $out['cache_ttl'], 300 ), 2592000 );
		$out['js_delay_timeout'] = min( max( $out['js_delay_timeout'], 0 ), 30000 );
		$out['warmer_limit']     = min( max( $out['warmer_limit'], 0 ), 500 );

		// The AI secret is never posted by the settings form — preserve/generate.
		$stored = get_option( self::OPT, array() );
		if ( is_array( $stored ) && isset( $stored['api_key'] ) && '' !== (string) $stored['api_key'] ) {
			$out['api_key'] = (string) $stored['api_key'];
		} else {
			$out['api_key'] = self::ensureApiKey();
		}

		// Validate custom regex lines in exclude_urls (#...# form). Invalid ones are dropped.
		$out['exclude_urls'] = self::validateRegexLines( (string) $out['exclude_urls'] );

		return $out;
	}

	/**
	 * Machine-to-machine secret for the REST API. Generated once, visible to
	 * admins in the panel, sent by agents as the X-EvolutionCache-Key header
	 * (some hosts strip the Authorization header that app passwords need).
	 */
	public static function ensureApiKey(): string {
		$stored = get_option( self::OPT, array() );
		if ( is_array( $stored ) && isset( $stored['api_key'] ) && '' !== (string) $stored['api_key'] ) {
			return (string) $stored['api_key'];
		}
		$key = function_exists( 'wp_generate_password' )
			? wp_generate_password( 48, false, false )
			: bin2hex( random_bytes( 24 ) );
		$s              = self::all();
		$s['api_key']   = $key;
		update_option( self::OPT, $s, false );
		return $key;
	}

	/**
	 * Split a textarea into trimmed non-empty lines.
	 */
	public static function parseLines( ?string $text ): array {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function validateRegexLines( string $text ): string {
		$ok = array();
		foreach ( self::parseLines( $text ) as $line ) {
			if ( '#' === substr( $line, 0, 1 ) ) {
				if ( false === @preg_match( $line, '' ) ) {
					continue; // Invalid PCRE — drop instead of crashing the drop-in.
				}
			}
			$ok[] = $line;
		}
		return implode( "\n", $ok );
	}
}
