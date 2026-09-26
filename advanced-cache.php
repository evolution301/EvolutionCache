<?php
/**
 * FeatherCache — advanced-cache.php drop-in.
 *
 * Loaded by WordPress (wp-settings.php) before plugins/themes load, whenever
 * WP_CACHE is true. Serves a previously stored static page for anonymous
 * GET/HEAD requests; in every other case it returns silently and WordPress
 * renders normally.
 *
 * Self-contained on purpose: no WordPress functions are available here.
 * Fail-open: any inconsistency → return → normal WordPress output.
 *
 * @package FeatherCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( defined( 'WP_CLI' ) || defined( 'FEATHERCACHE_SKIP_SERVE' ) ) {
	return;
}

/* ---------------------------------------------------------------------
 * Request shape
 * ------------------------------------------------------------------- */

$__fc_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
if ( 'GET' !== $__fc_method && 'HEAD' !== $__fc_method ) {
	return;
}

$__fc_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( (string) $_SERVER['HTTP_HOST'] ) ) : '';
if ( '' === $__fc_host || ! preg_match( '/^[a-z0-9.\-]+(:[0-9]+)?$/', $__fc_host ) ) {
	return;
}
$__fc_host = preg_replace( '/:[0-9]+$/', '', $__fc_host );
if ( ! is_string( $__fc_host ) || '' === $__fc_host ) {
	return;
}

$__fc_https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) )
	|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( trim( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )
	|| ( isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] );
$__fc_scheme = $__fc_https ? 'https' : 'http';

$__fc_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
if ( '' === $__fc_uri || '/' !== $__fc_uri[0] || strlen( $__fc_uri ) > 2048 ) {
	return;
}

$__fc_qpos = strpos( $__fc_uri, '?' );
$__fc_path = false === $__fc_qpos ? $__fc_uri : substr( $__fc_uri, 0, $__fc_qpos );
$__fc_query = false === $__fc_qpos ? '' : substr( $__fc_uri, $__fc_qpos + 1 );

/* ---------------------------------------------------------------------
 * Hard path exclusions
 * ------------------------------------------------------------------- */

foreach ( array( '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php', '/wp-cron.php', '/wp-sitemap', '/feed' ) as $__fc_n ) {
	if ( false !== stripos( $__fc_path, $__fc_n ) ) {
		return;
	}
}
foreach ( array( 'feed', 'sitemap', 'preview', 'download_file' ) as $__fc_n ) {
	if ( preg_match( '/(^|&)' . $__fc_n . '=/i', $__fc_query ) ) {
		return;
	}
}

// Never try to cache static-looking paths (assets are served by the server).
if ( preg_match( '#\.[a-z0-9]{1,8}$#i', $__fc_path ) && ! preg_match( '#\.(php|html?)$#i', $__fc_path ) ) {
	return;
}

/* ---------------------------------------------------------------------
 * Runtime config (written by the plugin when settings change)
 * ------------------------------------------------------------------- */

$__fc_root = WP_CONTENT_DIR . '/cache/feathercache/';
if ( ! is_dir( $__fc_root ) ) {
	return;
}

$__fc_cfg = null;
$__fc_cfg_raw = @file_get_contents( $__fc_root . 'config.json' );
if ( is_string( $__fc_cfg_raw ) && '' !== $__fc_cfg_raw ) {
	$__fc_cfg = json_decode( $__fc_cfg_raw, true );
}
if ( ! is_array( $__fc_cfg ) || empty( $__fc_cfg['v'] ) ) {
	return;
}

/* ---------------------------------------------------------------------
 * Cookies / query string / custom exclusions
 * ------------------------------------------------------------------- */

if ( ! empty( $_COOKIE ) && is_array( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $__fc_ck ) {
		if ( is_string( $__fc_ck ) && preg_match( '/^(wordpress_[0-9a-f]{32}|wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)/', $__fc_ck ) ) {
			return;
		}
	}
}

// MUST MATCH FeatherCache\CacheKey + CacheRules (WP-context copies).
$__fc_wl = ( isset( $__fc_cfg['wl'] ) && is_array( $__fc_cfg['wl'] ) ) ? $__fc_cfg['wl'] : array();

if ( '' !== $__fc_query ) {
	$__fc_params = array();
	parse_str( $__fc_query, $__fc_params );

	foreach ( array_keys( $__fc_params ) as $__fc_k ) {
		if ( is_string( $__fc_k ) && preg_match( '/^(utm_|mtm_|fbclid|gclid|msclkid|mc_cid|mc_eid|_ga)/i', $__fc_k ) ) {
			continue;
		}
		if ( ! in_array( (string) $__fc_k, $__fc_wl, true ) ) {
			return; // Non-whitelisted parameter → dynamic URL, never cached.
		}
	}

	$__fc_keep = array();
	foreach ( $__fc_wl as $__fc_k ) {
		if ( is_string( $__fc_k ) && array_key_exists( $__fc_k, $__fc_params ) ) {
			$__fc_keep[ $__fc_k ] = $__fc_params[ $__fc_k ];
		}
	}
	ksort( $__fc_keep );
	$__fc_query = $__fc_keep ? http_build_query( $__fc_keep ) : '';
}

if ( isset( $__fc_cfg['ex'] ) && is_array( $__fc_cfg['ex'] ) ) {
	foreach ( $__fc_cfg['ex'] as $__fc_ex ) {
		if ( ! is_string( $__fc_ex ) || '' === $__fc_ex ) {
			continue;
		}
		if ( 0 === strpos( $__fc_ex, '#' ) ) {
			$__fc_m = @preg_match( $__fc_ex, $__fc_path );
			if ( 1 === $__fc_m ) {
				return;
			}
		} elseif ( 0 === strpos( $__fc_path, $__fc_ex ) ) {
			return;
		}
	}
}

/* ---------------------------------------------------------------------
 * Lookup + serve
 * ------------------------------------------------------------------- */

$__fc_view = 'd';
if ( ! empty( $__fc_cfg['mobile'] )
	&& isset( $_SERVER['HTTP_USER_AGENT'] )
	&& preg_match( '/(mobile|android|iphone|ipod|kindle|silk|blackberry|opera mini|opera mobi|iemobile|windows phone)/i', (string) $_SERVER['HTTP_USER_AGENT'] ) ) {
	$__fc_view = 'm';
}

$__fc_key = $__fc_scheme . '://' . $__fc_host . $__fc_path . ( '' !== $__fc_query ? '?' . $__fc_query : '' );

// TranslatePress cookie is part of the cache key (MUST MATCH CacheKey.php);
// only strictly language-shaped values, anything else ignored.
if ( isset( $_COOKIE['trp_language'] ) && is_string( $_COOKIE['trp_language'] )
	&& preg_match( '/^[a-zA-Z]{2,3}(_[a-zA-Z]{2,6})?$/', $_COOKIE['trp_language'] ) ) {
	$__fc_key .= '|trp:' . strtolower( $_COOKIE['trp_language'] );
}

$__fc_hash = hash( 'sha256', $__fc_key );
$__fc_dir  = $__fc_root . $__fc_view . '/' . substr( $__fc_hash, 0, 2 ) . '/';
$__fc_file = $__fc_dir . $__fc_hash . '.html';

if ( ! is_readable( $__fc_file ) ) {
	return;
}

$__fc_raw = file_get_contents( $__fc_file );
if ( false === $__fc_raw || '' === $__fc_raw ) {
	return;
}

$__fc_zpos = strpos( $__fc_raw, "\x00" );
if ( false === $__fc_zpos ) {
	return;
}

$__fc_meta = json_decode( substr( $__fc_raw, 0, $__fc_zpos ), true );
if ( ! is_array( $__fc_meta ) || ! isset( $__fc_meta['t'] ) ) {
	return;
}

// Generation guard: a page stored before the last flush() is stale.
if ( isset( $__fc_cfg['gen'] ) && ( ! isset( $__fc_meta['g'] ) || (int) $__fc_meta['g'] !== (int) $__fc_cfg['gen'] ) ) {
	return;
}

$__fc_ttl = isset( $__fc_cfg['ttl'] ) ? (int) $__fc_cfg['ttl'] : 3600;
if ( time() - (int) $__fc_meta['t'] > $__fc_ttl ) {
	return; // Expired — let WordPress regenerate a fresh page.
}

$__fc_body = substr( $__fc_raw, $__fc_zpos + 1 );
if ( '' === $__fc_body ) {
	return;
}

$__fc_mtime = @filemtime( $__fc_file );

// Conditional GET.
if ( $__fc_mtime && isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
	$__fc_ims = strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
	if ( false !== $__fc_ims && $__fc_ims >= $__fc_mtime ) {
		header( 'Cache-Control: public, max-age=0, must-revalidate, s-maxage=' . $__fc_ttl );
		http_response_code( 304 );
		exit;
	}
}

header( 'Content-Type: ' . ( isset( $__fc_meta['ct'] ) && is_string( $__fc_meta['ct'] ) ? $__fc_meta['ct'] : 'text/html; charset=UTF-8' ) );
// max-age=0 + must-revalidate: browsers revalidate every visit (fast 304 via
// Last-Modified), so content edits + 清缓存 take effect immediately. CDNs may
// still cache at the edge for ttl (s-maxage).
header( 'Cache-Control: public, max-age=0, must-revalidate, s-maxage=' . $__fc_ttl );
header( 'Vary: Accept-Encoding' );
if ( ! empty( $__fc_cfg['mobile'] ) ) {
	header( 'Vary: User-Agent' );
}
if ( $__fc_mtime ) {
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $__fc_mtime ) . ' GMT' );
}
if ( ! empty( $__fc_cfg['hdr'] ) ) {
	header( 'X-FeatherCache: HIT (' . $__fc_view . ')' );
}

if ( 'HEAD' === $__fc_method ) {
	exit;
}

$__fc_gz = $__fc_dir . $__fc_hash . '.html.gz';
if ( ! empty( $__fc_cfg['gzip'] )
	&& is_readable( $__fc_gz )
	&& isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
	&& false !== stripos( (string) $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) ) {
	header( 'Content-Encoding: gzip' );
	readfile( $__fc_gz );
	exit;
}

echo $__fc_body; // phpcs:ignore WordPress.Security.EscapeOutput -- cached page produced by this site's own renderer.
exit;
