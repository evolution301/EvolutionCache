<?php
/**
 * JS optimization. Three modes:
 *   off   — untouched
 *   defer — add `defer` to blocking external scripts (execution order kept)
 *   delay — wrap scripts into <template data-fc-delay> and inject them on
 *           first user interaction or after a timeout (Seraphinite-style)
 *
 * JSON-LD, noscript content and admin-excluded scripts are never touched.
 *
 * @package FeatherCache
 */

namespace FeatherCache\Opt;

use FeatherCache\Options;

defined( 'ABSPATH' ) || exit;

final class Js {

	public static function process( string $html, array $sett ): string {
		$mode = (string) $sett['js_mode'];
		if ( 'off' === $mode ) {
			return $html;
		}

		$delayExcludes = array_map( 'strtolower', Options::parseLines( (string) $sett['js_delay_exclude'] ) );
		$timeout       = (int) $sett['js_delay_timeout'];

		// Protect <noscript> regions from any script handling.
		$noscripts = array();
		$html      = preg_replace_callback(
			'#<noscript\b[^>]*>.*?</noscript>#is',
			function ( $m ) use ( &$noscripts ) {
				$key              = "\x02FCN" . count( $noscripts ) . "\x02";
				$noscripts[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		if ( ! is_string( $html ) ) {
			return '';
		}

		$delayed = 0;

		$html = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( $mode, $delayExcludes, &$delayed ) {
				return self::scriptTag( $m[1], $m[2], $mode, $delayExcludes, $delayed );
			},
			$html
		);
		if ( ! is_string( $html ) ) {
			return '';
		}

		// Runtime for delay mode.
		if ( 'delay' === $mode && $delayed > 0 && false !== stripos( $html, '</body' ) ) {
			$runtime = '<script id="feathercache-delay">!function(){"use strict";'
				. 'var d=0,e=function(){if(d)return;d=1;'
				. 'var t=document.querySelectorAll("template[data-fc-delay]");'
				. 'for(var i=0;i<t.length;i++){var n=document.createElement("div");'
				. 'n.appendChild(t[i].content.cloneNode(!0));t[i].replaceWith(n)}},'
				. 'evs=["pointerdown","keydown","wheel","touchstart","scroll","mousemove"];'
				. 'for(var j=0;j<evs.length;j++)window.addEventListener(evs[j],e,{once:!0,passive:!0});'
				. 'setTimeout(e,' . max( 0, $timeout ) . ')}();</script>';
			$pos = stripos( $html, '</body' );
			$html = substr( $html, 0, $pos ) . $runtime . substr( $html, $pos );
		}

		// Restore <noscript> regions.
		if ( $noscripts ) {
			$html = strtr( $html, $noscripts );
		}

		return $html;
	}

	private static function scriptTag( string $attrs, string $body, string $mode, array $delayExcludes, int &$delayed ): string {
		$type = null;
		if ( preg_match( '#\btype=["\']([^"\']*)["\']#i', $attrs, $m ) ) {
			$type = strtolower( trim( $m[1] ) );
		}

		// Leave data blocks (JSON-LD etc.) and template blocks untouched — SEO/structure.
		if ( null !== $type && ! in_array( $type, array( '', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript' ), true ) ) {
			return '<script' . $attrs . '>' . $body . '</script>';
		}

		$hasSrc = preg_match( '#\bsrc=["\']([^"\']*)["\']#i', $attrs, $srcM ) ? $srcM[1] : null;

		if ( 'defer' === $mode ) {
			// External, blocking, not module (module defers natively).
			if ( null !== $hasSrc
				&& null === $type
				&& ! preg_match( '#\b(async|defer)#i', $attrs ) ) {
				return '<script' . rtrim( $attrs ) . ' defer>' . $body . '</script>';
			}
			return '<script' . $attrs . '>' . $body . '</script>';
		}

		// delay mode.
		$tag = '<script' . $attrs . '>' . $body . '</script>';

		$exclude = false;
		if ( null !== $hasSrc ) {
			$srcLower = strtolower( html_entity_decode( $hasSrc, ENT_QUOTES ) );
			foreach ( $delayExcludes as $needle ) {
				if ( '' !== $needle && false !== strpos( $srcLower, $needle ) ) {
					$exclude = true;
					break;
				}
			}
		}
		// Never delay scripts explicitly marked as required.
		if ( preg_match( '#data-feathercache-keep#i', $attrs ) ) {
			$exclude = true;
		}

		if ( $exclude || ( null === $hasSrc && '' === trim( $body ) ) ) {
			return $tag;
		}

		$delayed++;
		return '<template data-fc-delay="' . $delayed . '">' . $tag . '</template>';
	}
}
