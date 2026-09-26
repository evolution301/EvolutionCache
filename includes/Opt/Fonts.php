<?php
/**
 * Font optimization: preconnect resource hints for Google Fonts.
 * No font files are fetched by the plugin itself (zero outbound requests
 * by default) — localization is a roadmap item.
 *
 * @package FeatherCache
 */

namespace FeatherCache\Opt;

use FeatherCache\Options;

defined( 'ABSPATH' ) || exit;

final class Fonts_Hints {

	public static function boot(): void {
		if ( empty( Options::all()['fonts_preconnect'] ) ) {
			return;
		}
		add_filter( 'wp_resource_hints', array( __CLASS__, 'hints' ), 10, 2 );
	}

	/**
	 * @param array  $hints URLs.
	 * @param string $relation_type.
	 */
	public static function hints( array $hints, string $relation_type ): array {
		if ( 'preconnect' === $relation_type ) {
			$hints[] = 'https://fonts.googleapis.com';
			$hints[] = 'https://fonts.gstatic.com';
		}
		return $hints;
	}
}
