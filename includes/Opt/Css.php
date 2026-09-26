<?php
/**
 * CSS optimization: minify local stylesheets into content-addressed cache
 * copies, inject font-display, defer non-critical stylesheets, inline
 * admin-provided critical CSS.
 *
 * @package FeatherCache
 */

namespace FeatherCache\Opt;

use FeatherCache\CacheStore;
use FeatherCache\Options;

defined( 'ABSPATH' ) || exit;

final class Css {

	public static function process( string $html, array $sett ): string {
		// 1. Admin-provided critical CSS, inlined at the top of <head>.
		if ( '' !== trim( (string) $sett['critical_css'] ) ) {
			$critical = str_replace( '</style', '<\\/style', (string) $sett['critical_css'] );
			$styleTag = '<style id="feathercache-critical">' . $critical . '</style>';

			if ( false !== stripos( $html, '<head' ) && false === stripos( $html, 'id="feathercache-critical"' ) ) {
				$html = self::insertIntoHead( $html, $styleTag );
			}
		}

		$excludes = Options::parseLines( (string) $sett['css_exclude'] );

		// 2. Per-stylesheet processing.
		$html = preg_replace_callback(
			'#<link\b[^>]*>#is',
			function ( $m ) use ( $sett, $excludes ) {
				return self::linkTag( $m[0], $sett, $excludes );
			},
			$html
		);
		if ( ! is_string( $html ) ) {
			return '';
		}

		return $html;
	}

	private static function linkTag( string $tag, array $sett, array $excludes ): string {
		if ( false === stripos( $tag, 'stylesheet' ) ) {
			return $tag;
		}
		// Skip already-async patterns (preload+onload, media=print swaps).
		if ( false !== stripos( $tag, 'rel="preload' ) || false !== stripos( $tag, "rel='preload" ) ) {
			return $tag;
		}

		$href = self::attr( $tag, 'href' );
		if ( null === $href || '' === $href ) {
			return $tag;
		}

		$hrefLower = strtolower( $href );
		$isExcluded = false;
		foreach ( $excludes as $needle ) {
			if ( '' !== $needle && false !== strpos( $hrefLower, strtolower( $needle ) ) ) {
				$isExcluded = true;
				break;
			}
		}

		$media = self::attr( $tag, 'media' );
		$isBlockingStyle = ( null === $media || '' === $media || 0 === strcasecmp( $media, 'all' ) || 0 === stripos( $media, 'screen' ) );

		// Minify + cache local files (content-addressed; mtime busts on updates).
		$newHref = $href;
		if ( ! empty( $sett['css_minify'] ) && preg_match( '#\.css(\?|$)#i', $href ) ) {
			$minified = self::minifiedAssetUrl( $href );
			if ( null !== $minified ) {
				$newHref = $minified;
			}
		}

		$out = str_replace( $href, $newHref, $tag ); // href value is unique inside the tag.

		// Defer non-critical, blocking stylesheets.
		if ( ! empty( $sett['css_defer'] ) && $isBlockingStyle && ! $isExcluded ) {
			$attrs = self::attrsExcept( $tag, array( 'rel', 'as', 'onload', 'href' ) );
			$deferred = '<link rel="preload" as="style" href="' . esc_attr( html_entity_decode( $newHref, ENT_QUOTES ) ) . '" '
				. 'onload="this.onload=null;this.rel=\'stylesheet\'"' . ( '' !== $attrs ? ' ' . $attrs : '' ) . '>';
			$noscript = '<noscript>' . str_replace( $href, $newHref, $tag ) . '</noscript>';
			return $deferred . $noscript;
		}

		return $out;
	}

	/**
	 * Minify a local CSS file and store a hashed copy. Returns null to keep
	 * the original URL on any failure (unreadable, external, write error).
	 */
	private static function minifiedAssetUrl( string $url ): ?string {
		$decoded = html_entity_decode( $url, ENT_QUOTES );
		$path    = CacheStore::urlToPath( $decoded );
		if ( null === $path ) {
			return null;
		}

		$mtime = (string) @filemtime( $path );
		if ( '' === $mtime ) {
			return null;
		}

		$raw = @file_get_contents( $path );
		if ( false === $raw || '' === $raw || strlen( $raw ) > 3 * 1024 * 1024 ) {
			return null; // Empty or >3MB — skip.
		}

		$min = self::minify( $raw );
		if ( '' === $min || strlen( $min ) >= strlen( $raw ) ) {
			$min = $raw; // Minification gained nothing — keep original bytes.
		}

		$min = self::injectFontDisplay( $min );

		$newUrl = CacheStore::putAsset( $decoded, 'css', $min, $mtime );
		return null !== $newUrl ? $newUrl : null;
	}

	/**
	 * Safe regex minifier: quoted strings are protected before collapsing,
	 * url()/data URIs never get mangled, and any regex failure returns ''.
	 */
	public static function minify( string $css ): string {
		$strings = array();

		$css = preg_replace_callback(
			'#(["\'])(?:(?!\1).)*\1#s',
			function ( $m ) use ( &$strings ) {
				$key            = "\x01" . count( $strings ) . "\x01";
				$strings[ $key ] = $m[0];
				return $key;
			},
			$css
		);
		if ( ! is_string( $css ) ) {
			return '';
		}

		$css = preg_replace( '#/\*(?!!).*?\*/#s', '', $css ); // Keep /*!...*/ license comments.
		if ( ! is_string( $css ) ) {
			return '';
		}

		$steps = array(
			'#\s+#'                => ' ',
			'#\s*([{}:;,>~+])\s*#' => '$1',
			'#;}#'                 => '}',
		);
		foreach ( $steps as $pattern => $replacement ) {
			$css = preg_replace( $pattern, $replacement, $css );
			if ( ! is_string( $css ) ) {
				return '';
			}
		}

		$css = trim( $css );
		if ( $strings ) {
			$css = strtr( $css, $strings );
		}
		return $css;
	}

	/**
	 * Add font-display:swap to @font-face blocks lacking it (web font FOIT fix).
	 */
	public static function injectFontDisplay( string $css ): string {
		$out = preg_replace_callback(
			'#@font-face\s*{[^}]*}#is',
			function ( $m ) {
				$block = $m[0];
				if ( false === stripos( $block, 'font-display' ) ) {
					$block = preg_replace( '#\}$#', 'font-display:swap;}', rtrim( $block ) );
				}
				return is_string( $block ) ? $block : $m[0];
			},
			$css
		);
		return is_string( $out ) ? $out : $css;
	}

	private static function insertIntoHead( string $html, string $snippet ): string {
		$pos = stripos( $html, '<head' );
		if ( false === $pos ) {
			return $html;
		}
		$close = strpos( $html, '>', $pos );
		if ( false === $close ) {
			return $html;
		}
		return substr( $html, 0, $close + 1 ) . $snippet . substr( $html, $close + 1 );
	}

	private static function attr( string $tag, string $name ): ?string {
		if ( preg_match( '#(?<![\w-])' . $name . '=["\']([^"\']*)["\']#i', $tag, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Rebuild a tag keeping all attributes except the listed ones.
	 */
	private static function attrsExcept( string $tag, array $without ): string {
		$attrs = '';
		if ( preg_match_all( '#(?<![\w-])([a-zA-Z-]+)=(["\'])(.*?)\2#s', $tag, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				if ( in_array( strtolower( $m[1] ), $without, true ) ) {
					continue;
				}
				$attrs .= strtolower( $m[1] ) . '="' . esc_attr( wp_specialchars_decode( $m[3], ENT_QUOTES ) ) . '" ';
			}
		}
		return trim( $attrs );
	}
}
