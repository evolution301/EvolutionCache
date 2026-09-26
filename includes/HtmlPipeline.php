<?php
/**
 * Output pipeline: buffers the rendered page once per cache miss, applies
 * optimization modules (each individually fail-open) and stores the result.
 * Cache hits never reach WordPress at all (see advanced-cache.php).
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class HtmlPipeline {

	/** @var array<string,bool> */
	private static $logged = array();

	public static function boot(): void {
		$sett = Options::all();

		$cssActive = ! empty( $sett['css_minify'] ) || ! empty( $sett['css_defer'] ) || '' !== trim( (string) $sett['critical_css'] );
		$jsActive  = 'off' !== $sett['js_mode'];
		$imgActive = ! empty( $sett['img_lazy'] ) || ! empty( $sett['img_lcp_preload'] );

		if ( empty( $sett['page_cache'] ) && ! $cssActive && ! $jsActive && ! $imgActive && empty( $sett['minify_html'] ) ) {
			return; // Every module disabled — do not buffer at all.
		}

		if ( ! CacheRules::isCacheableRequest( $sett ) ) {
			return;
		}

		ob_start( array( __CLASS__, 'process' ) );
	}

	/**
	 * ob_start callback. Runs once per page render.
	 */
	public static function process( string $html ): string {
		try {
			$sett   = Options::all();

			// http_response_code() returns false in CLI / after output — PHP
			// defaults to 200, so normalize false/0 → 200.
			$rc     = function_exists( 'http_response_code' ) ? http_response_code() : 200;
			$status = ( is_int( $rc ) && $rc > 0 ) ? $rc : 200;

			// Absurdly large pages are left untouched (CPU/memory guard).
			if ( strlen( $html ) < 500 || strlen( $html ) > 4 * 1024 * 1024 ) {
				return $html;
			}
			if ( false === stripos( $html, '<html' ) ) {
				return $html;
			}

			$cacheable = CacheRules::isCacheableResponse( $status );

			// Optimization modules — each one independently fail-open.
			$cssActive = ! empty( $sett['css_minify'] ) || ! empty( $sett['css_defer'] ) || '' !== trim( (string) $sett['critical_css'] );
			$jsActive  = 'off' !== $sett['js_mode'];
			$imgActive = ! empty( $sett['img_lazy'] ) || ! empty( $sett['img_lcp_preload'] );

			$out = self::apply( array( Opt\Css::class, 'process' ), $html, $sett, $cssActive );
			$out = self::apply( array( Opt\Js::class, 'process' ), $out, $sett, $jsActive );
			$out = self::apply( array( Opt\Images::class, 'process' ), $out, $sett, $imgActive );
			$out = self::apply( array( Opt\A11y::class, 'process' ), $out, $sett, true );
			$out = self::apply( array( __CLASS__, 'minifyHtml' ), $out, $sett, ! empty( $sett['minify_html'] ) );

			if ( $cacheable && ! empty( $sett['page_cache'] ) ) {
				self::storePage( $out, $sett );
			}

			return $out;
		} catch ( \Throwable $e ) {
			self::log( 'pipeline: ' . $e->getMessage() );
			return $html; // Fail-open: serve the original HTML untouched.
		}
	}

	private static function apply( callable $fn, string $html, array $sett, bool $enabled ): string {
		if ( ! $enabled ) {
			return $html;
		}
		try {
			$result = call_user_func( $fn, $html, $sett );
			return ( is_string( $result ) && '' !== $result ) ? $result : $html;
		} catch ( \Throwable $e ) {
			self::log( 'module failed: ' . $e->getMessage() );
			return $html;
		}
	}

	private static function storePage( string $html, array $sett ): void {
		list( $key, $mobile ) = CacheKey::build( $sett );

		$contentType = 'text/html; charset=UTF-8';
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				$contentType = trim( substr( $header, 13 ) );
				break;
			}
		}

		CacheStore::writePage(
			$mobile,
			$key,
			$html,
			$contentType,
			(int) $sett['cache_ttl'],
			! empty( $sett['gzip'] )
		);
	}

	/**
	 * Conservative HTML minification: strips comments (keeps MSO conditionals)
	 * and collapses redundant whitespace runs. Never touches the content of
	 * <pre>, <textarea>, <script>, <style> or <noscript>.
	 */
	public static function minifyHtml( string $html, array $sett ): string {
		$tokens = array();

		$protected = preg_replace_callback(
			'#<(pre|textarea|script|style|noscript)\b[^>]*>.*?</\1>#is',
			function ( $m ) use ( &$tokens ) {
				$key            = "\x02FC" . count( $tokens ) . "\x02";
				$tokens[ $key ] = $m[0];
				return $key;
			},
			$html
		);

		if ( ! is_string( $protected ) ) {
			return $html; // Regex failure — original HTML out.
		}

		// Remove comments except IE/Outlook conditionals (possessive + avoids
		// quadratic backtracking on unterminated comments).
		$minified = preg_replace( '/<!--(?!\s*(?:\[if [^\]]+]|<!\[endif|<\/?\[))(?:(?!-->).)*+-->/s', '', $protected );
		if ( is_string( $minified ) ) {
			$minified = preg_replace( '/[ \t]*\r?\n[ \t]*/', "\n", $minified );
		}
		if ( is_string( $minified ) ) {
			$minified = preg_replace( '/\n{2,}/', "\n", $minified );
		}
		if ( ! is_string( $minified ) ) {
			return $html;
		}

		if ( $tokens ) {
			$minified = strtr( $minified, $tokens );
		}

		return $minified;
	}

	public static function log( string $message ): void {
		if ( isset( self::$logged[ $message ] ) ) {
			return; // One line per message per request.
		}
		self::$logged[ $message ] = true;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[FeatherCache] ' . $message ); // phpcs:ignore
		}
	}
}
