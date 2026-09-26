<?php
/**
 * Phase B: simulate an anonymous GET request and let the REAL
 * advanced-cache.php drop-in handle it. stdout on a hit = cached page body
 * (should equal expected.html); a miss prints "FC-MISS".
 */

// phpcs:ignoreFile

require __DIR__ . '/wp-stubs.php';

define( 'FEATHERCACHE_CACHE_DIR', WP_CONTENT_DIR . '/cache/feathercache/' );

$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['HTTP_HOST']       = 'example.test';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['HTTPS']           = 'on';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0';
$_COOKIE = array();

include dirname( __DIR__ ) . '/advanced-cache.php';

echo "FC-MISS";

