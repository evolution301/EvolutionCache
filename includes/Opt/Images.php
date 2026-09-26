<?php
/**
 * Image optimization using native browser features only:
 *  - loading="lazy" + decoding="async" on below-the-fold images
 *  - fetchpriority="high" on the LCP candidate + <link rel=preload>
 * Tracking pixels, noscript fallbacks and opt-out images are untouched.
 *
 * @package FeatherCache
 */

namespace FeatherCache\Opt;

defined( 'ABSPATH' ) || exit;

final class Images {

	public static function process( string $html, array $sett ): string {
		$lcpUrl   = null;
		$lcpFound = false;

		// Pass 1: find the LCP candidate (first real content image, no srcset
		// duplication risk, not a tracking pixel). Loop past ineligible imgs.
		$offset = 0;
		$bodyPos = stripos( $html, '<body' );
		if ( false !== $bodyPos ) {
			$offset = $bodyPos;
		}

		if ( preg_match_all( '#<img\b[^>]*>#is', $html, $mAll, PREG_OFFSET_CAPTURE, $offset ) ) {
			foreach ( $mAll[0] as $hit ) {
				$tag   = $hit[0];
				$tagPos = $hit[1];

				$src   = self::attr( $tag, 'src' );
				$width = self::attr( $tag, 'width' );

				$isTrackable = false;
				if ( null !== $width && '' !== $width && (int) $width <= 2 ) {
					$isTrackable = true;
				}
				if ( null !== $src && 0 === strpos( strtolower( $src ), 'data:' ) ) {
					$isTrackable = true;
				}
				if ( preg_match( '#(style=["\'][^"\']*display\s*:\s*none)#i', $tag ) ) {
					$isTrackable = true;
				}
				if ( preg_match( '#\bloading\s*=\s*["\']?lazy#i', $tag ) ) {
					$isTrackable = true; // Already lazy — never the LCP.
				}

				if ( ! $isTrackable && null !== $src ) {
					$lcpUrl    = $src;
					$lcpFound  = true;
					self::$lcpTagPos    = $tagPos;
					self::$lcpHasSrcset = false !== stripos( $tag, 'srcset=' );
					break;
				}
			}
		}

		// Pass 2: rewrite all <img> tags.
		$out = '';
		$last = 0;

		preg_match_all( '#<img\b[^>]*>#is', $html, $matches, PREG_OFFSET_CAPTURE );
		if ( is_array( $matches[0] ) ) {
			foreach ( $matches[0] as $hit ) {
				$tag  = $hit[0];
				$pos  = $hit[1];

				$out .= substr( $html, $last, $pos - $last );

				if ( $lcpFound && $pos === self::$lcpTagPos ) {
					$out .= self::lcpTag( $tag, $lcpUrl, $sett );
				} else {
					$out .= self::lazyTag( $tag, $sett );
				}

				$last = $pos + strlen( $tag );
			}
		}
		$out .= substr( $html, $last );
		$html = $out;

		// Pass 3: LCP preload hint in <head> (only when srcset-less, so the
		// preloaded URL is exactly what the browser fetches — no double download).
		if ( $lcpFound && ! empty( $sett['img_lcp_preload'] ) && null !== $lcpUrl
			&& empty( self::$lcpHasSrcset )
			&& false === stripos( $html, 'rel="preload" as="image"' ) ) {
			$preload = '<link rel="preload" as="image" href="' . esc_url( $lcpUrl ) . '" fetchpriority="high">';
			$pos = stripos( $html, '</head>' );
			if ( false !== $pos ) {
				// substr_replace: a preg replacement would eat "$1"-style URLs.
				$html = substr_replace( $html, $preload, $pos, 0 );
			}
		}

		// Pass 4: oversized local images get a compressed 800px-wide webp copy
		// (upload pipelines often ship 853px+ files for ~700px display slots).
		if ( ! empty( $sett['img_resize'] ) ) {
			$html = self::resizeOversized( $html );
		}

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Rewrite <img src> to an 800px-wide q80 webp copy for local uploads
	 * images that are at least 800px wide and have no srcset of their own.
	 * The resized copy is generated once and reused for every later page.
	 */
	private static function resizeOversized( string $html ): string {
		$out  = '';
		$last = 0;
		if ( ! preg_match_all( '#<img\b[^>]*>#is', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		foreach ( $matches[0] as $hit ) {
			$tag = $hit[0];
			$pos = $hit[1];
			$out .= substr( $html, $last, $pos - $last );
			if ( $pos === self::$lcpTagPos ) {
				// Never touch the LCP image — its preload URL must stay in sync.
				$out .= $tag;
				$last = $pos + strlen( $tag );
				continue;
			}
			$out .= self::resizedTag( $tag );
			$last = $pos + strlen( $tag );
		}
		$out .= substr( $html, $last );
		return $out;
	}

	private static function resizedTag( string $tag ): string {
		if ( false !== stripos( $tag, 'srcset=' ) || false !== stripos( $tag, 'data-fc-resized' ) ) {
			return $tag;
		}
		$src = self::attr( $tag, 'src' );
		if ( null === $src || '' === $src || 0 === stripos( $src, 'data:' ) ) {
			return $tag;
		}
		$r = self::resizedVariant( $src );
		if ( null === $r ) {
			return $tag;
		}
		$new = str_replace( $src, $r['url'], $tag );
		if ( preg_match( '#\swidth=["\']#i', $new ) !== 1 ) {
			$new = rtrim( rtrim( $new, '>' ) ) . ' width="' . (int) $r['w'] . '">';
		}
		return rtrim( rtrim( $new, '>' ) ) . ' data-fc-resized="1">';
	}

	/**
	 * Create (once) and return the 800w webp copy of a local uploads image.
	 */
	private static function resizedVariant( string $src ): ?array {
		$decoded = html_entity_decode( $src, ENT_QUOTES );
		$path    = \FeatherCache\CacheStore::urlToPath( $decoded );
		if ( null === $path || ! function_exists( 'wp_get_image_editor' ) ) {
			return null;
		}

		$hash = hash( 'sha256', $decoded . '|' . (string) @filesize( $path ) );
		$dir  = \FeatherCache\CacheStore::root() . 'assets/';
		$file = $dir . 'img-' . substr( $hash, 0, 24 ) . '-800.webp';

		if ( file_exists( $file ) ) {
			return array( 'url' => content_url( 'cache/feathercache/assets/' . basename( $file ) ), 'w' => 800 );
		}

		$size = @getimagesize( $path );
		if ( ! $size || $size[0] < 800 ) {
			return null; // Not oversized — keep the original.
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return null;
		}
		$editor->resize( 800, null, false );
		$editor->set_quality( 80 );
		$saved = $editor->save( $file );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return null;
		}
		// Pointless if the "optimized" copy is not actually smaller.
		if ( @filesize( $saved['path'] ) >= @filesize( $path ) ) {
			@unlink( $saved['path'] );
			return null;
		}
		return array( 'url' => content_url( 'cache/feathercache/assets/' . basename( $saved['path'] ) ), 'w' => 800 );
	}

	/** @var int|false */
	private static $lcpTagPos = false;

	/** @var bool */
	private static $lcpHasSrcset = false;

	private static function lcpTag( string $tag, string $src, array $sett ): string {
		if ( false === stripos( $tag, 'fetchpriority' ) ) {
			$tag = rtrim( rtrim( $tag, '>' ) ) . ' fetchpriority="high" decoding="async">';
		} elseif ( false === stripos( $tag, 'decoding=' ) ) {
			$tag = rtrim( rtrim( $tag, '>' ) ) . ' decoding="async">';
		}
		// Preload of srcset images would double-download — handled centrally
		// in process() only for srcset-less images (the common LCP case).
		return $tag;
	}

	private static function lazyTag( string $tag, array $sett ): string {
		if ( empty( $sett['img_lazy'] ) ) {
			return $tag;
		}

		// Already lazy or opted out.
		if ( preg_match( '#\bloading\s*=#i', $tag ) ) {
			return $tag;
		}
		if ( preg_match( '#(skip-lazy|no-lazy|data-lzl|lazyload|data-noptim)#i', $tag ) ) {
			return $tag;
		}

		$width  = self::attr( $tag, 'width' );
		if ( null !== $width && '' !== $width && (int) $width <= 2 ) {
			return $tag; // Tracking pixel — must fire immediately.
		}
		if ( preg_match( '#(style=["\'][^"\']*display\s*:\s*none)#i', $tag ) ) {
			return $tag;
		}
		$src = self::attr( $tag, 'src' );
		if ( null === $src || '' === $src ) {
			return $tag;
		}

		return rtrim( rtrim( $tag, '>' ) ) . ' loading="lazy" decoding="async">';
	}

	private static function attr( string $tag, string $name ): ?string {
		if ( preg_match( '#(?<![\w-])' . $name . '=["\']([^"\']*)["\']#i', $tag, $m ) ) {
			return $m[1];
		}
		return null;
	}
}
