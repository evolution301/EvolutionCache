<?php
/**
 * Minimal WordPress stubs so the EvolutionCache test harness runs anywhere:
 *   php tests/run-tests.php
 * No database, no web server. Paths are derived from this file's location.
 *
 * @package EvolutionCache
 */

// phpcs:ignoreFile

error_reporting( E_ALL );

$fc_root = dirname( __DIR__ );                       // repo root (= plugin root)
define( 'ABSPATH', $fc_root . '/tests/tmp/wp/' );
define( 'WP_CONTENT_DIR', $fc_root . '/tests/tmp/wp/wp-content' );
@mkdir( WP_CONTENT_DIR, 0777, true );
@mkdir( ABSPATH, 0777, true );

function absint( $v ) { return abs( (int) $v ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0755, true ); }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $e = 0 ) { return true; }
function delete_transient( $k ) { return true; }

function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function home_url( $path = '' ) { return 'https://example.test' . ( $path ? '/' . ltrim( $path, '/' ) : '' ); }
function content_url( $path = '' ) { return 'https://example.test/wp-content' . ( $path ? '/' . ltrim( $path, '/' ) : '' ); }
function is_ssl() { return true; }

function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8', false ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8', false ); }
function esc_url( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8', false ); }
function esc_textarea( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8', false ); }
function wp_specialchars_decode( $v, $q = ENT_QUOTES ) { return htmlspecialchars_decode( (string) $v, $q ); }
function esc_url_raw( $v ) { return filter_var( (string) $v, FILTER_VALIDATE_URL ) ? (string) $v : ''; }

function add_action( $h, $c = null, $p = 10, $a = 1 ) {}
function add_filter( $h, $c = null, $p = 10, $a = 1 ) {}
function is_admin() { return false; }
