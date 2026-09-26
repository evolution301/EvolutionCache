<?php
/**
 * Accessibility micro-fixes applied to cached HTML.
 * Generic link texts ("READ MORE", "Learn more"…) get an aria-label derived
 * from the link's own slug, so assistive tech — and PSI's "descriptive
 * link text" audit — can tell the links apart. Visible text is untouched.
 *
 * @package FeatherCache
 */

namespace FeatherCache\Opt;

defined( 'ABSPATH' ) || exit;

final class A11y {

	/** Generic link texts (lowercased, exact match after trimming). */
	const GENERIC_TEXTS = array(
		'read more', 'read the rest', 'continue reading', 'learn more',
		'more', 'more info', 'more information', 'details', 'view more',
		'click here', 'here', 'this page', 'link',
		'阅读更多', '查看更多', '了解更多', '查看详情', '详情', '更多',
	);

	public static function process( string $html, array $sett ): string {
		$out  = '';
		$last = 0;
		if ( ! preg_match_all( '#<a\b[^>]*>[\s\S]*?</a>#is', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		foreach ( $matches[0] as $hit ) {
			$tag  = $hit[0];
			$pos  = $hit[1];
			$out .= substr( $html, $last, $pos - $last );
			$out .= self::labelLink( $tag );
			$last = $pos + strlen( $tag );
		}
		$out .= substr( $html, $last );
		return $out;
	}

	private static function labelLink( string $tag ): string {
		if ( false !== stripos( $tag, 'aria-label' ) || false !== stripos( $tag, 'aria-hidden' ) ) {
			return $tag; // Already labelled (or hidden on purpose).
		}
		if ( ! preg_match( '#<a\b([^>]*)>([\s\S]*)</a>#is', $tag, $m ) ) {
			return $tag;
		}
		$attrs = $m[1];
		$text  = trim( preg_replace( '/<[^>]*>/', ' ', $m[2] ) );
		$text  = html_entity_decode( preg_replace( '/\s+/', ' ', $text ), ENT_QUOTES );

		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		if ( '' === $lower || ! in_array( $lower, self::GENERIC_TEXTS, true ) ) {
			return $tag;
		}

		$href = null;
		if ( preg_match( '#(?<![\w-])href=["\']([^"\']*)["\']#i', $attrs, $hm ) ) {
			$href = html_entity_decode( $hm[1], ENT_QUOTES );
		}
		if ( null === $href || '' === $href || 0 === stripos( $href, '#' )
			|| 0 === stripos( $href, 'javascript:' ) || 0 === stripos( $href, 'mailto:' ) ) {
			return $tag;
		}

		$title = self::titleFromSlug( $href );
		if ( '' === $title ) {
			return $tag;
		}

		$label = 'Read more: ' . $title;
		return '<a' . rtrim( $attrs ) . ' aria-label="' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8', false ) . '">' . $m[2] . '</a>';
	}

	/**
	 * "...-mattress-adhesives-voc-compliance/" → "Mattress Adhesives Voc Compliance"
	 */
	private static function titleFromSlug( string $href ): string {
		$path = (string) parse_url( $href, PHP_URL_PATH );
		$slug = basename( trim( $path, '/' ) );
		if ( '' === $slug || false !== strpos( $slug, '.' ) ) {
			return ''; // Skip files and query-only links.
		}
		$slug = preg_replace( '/^\d+-/', '', $slug ); // Leading dates/ids.
		$words = preg_replace( '/[-_]+/', ' ', $slug );
		if ( ! is_string( $words ) || '' === trim( $words ) ) {
			return '';
		}
		return function_exists( 'mb_convert_case' )
			? mb_convert_case( trim( $words ), MB_CASE_TITLE, 'UTF-8' )
			: ucwords( strtolower( trim( $words ) ) );
	}
}
