<?php
/**
 * FeatherCache local functional tests (phase A):
 *   1. CSS minifier safety + font-display injection
 *   2. Full pipeline in `defer` mode  → expectations + cache file written
 *   3. Full pipeline in `delay` mode  → templates, runtime, JSON-LD intact
 *   4. HTML minifier keeps <pre>/<textarea>/<noscript> intact
 * phase B (serve-test.php) then simulates the advanced-cache hit.
 */

// phpcs:ignoreFile

require __DIR__ . '/wp-stubs.php';

define( 'FEATHERCACHE_CACHE_DIR', WP_CONTENT_DIR . '/cache/feathercache/' );
define( 'FEATHERCACHE_DIR', dirname( __DIR__ ) . '/' );
define( 'FEATHERCACHE_VERSION', '1.0.0' );

require FEATHERCACHE_DIR . 'includes/Options.php';
require FEATHERCACHE_DIR . 'includes/CacheStore.php';
require FEATHERCACHE_DIR . 'includes/CacheKey.php';
require FEATHERCACHE_DIR . 'includes/CacheRules.php';
require FEATHERCACHE_DIR . 'includes/Dropin.php';
require FEATHERCACHE_DIR . 'includes/HtmlPipeline.php';
require FEATHERCACHE_DIR . 'includes/Opt/Css.php';
require FEATHERCACHE_DIR . 'includes/Opt/Js.php';
require FEATHERCACHE_DIR . 'includes/Opt/Images.php';
require FEATHERCACHE_DIR . 'includes/Opt/A11y.php';
require FEATHERCACHE_DIR . 'includes/Warmer.php';

$fail = 0;
function check( $name, $cond ) {
	global $fail;
	if ( $cond ) {
		echo "PASS  {$name}\n";
	} else {
		$fail++;
		echo "FAIL  {$name}\n";
	}
}

// Must be set before any output so pipeline response checks behave like HTTP.
http_response_code( 200 );
define( 'WP_DEBUG', true ); // Surface FeatherCache fail-open logs.

// Reset workspace.
foreach ( array( __DIR__ . '/tmp/cache', WP_CONTENT_DIR . '/plugins' ) as $dir ) {
	if ( is_dir( $dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
		@rmdir( $dir );
	}
}
@mkdir( WP_CONTENT_DIR . '/plugins', 0755, true );
@mkdir( ABSPATH, 0755, true );

/* ------------------------------------------------------------------ */
/* 1. CSS minifier                                                     */
/* ------------------------------------------------------------------ */

$css = '/* comment */ body { margin : 0 ; color : #333 } ' .
	'.a::after { content : "keep   spaces  ; } {" ; } ' .
	"@font-face { font-family : X ; src : url( 'x.woff2' ) ; } " .
	'@font-face { font-family : Y ; src : url(y.woff) ; font-display : block ; }';
$min = \FeatherCache\Opt\Css::minify( $css );

check( 'css: comments removed', false === strpos( $min, '/* comment */' ) );
check( 'css: whitespace collapsed', false !== strpos( $min, 'body{margin:0;color:#333}' ) );
check( 'css: quoted string preserved', false !== strpos( $min, 'keep   spaces  ; } {' ) );
check( 'css: existing font-display survives minify', false !== strpos( $min, 'font-display:block' ) );
$fd = \FeatherCache\Opt\Css::injectFontDisplay( $min );
check( 'css: font-display injected to 2nd @font-face', 2 === substr_count( $fd, 'font-display' ) );

/* ------------------------------------------------------------------ */
/* Sample page used by pipeline tests                                  */
/* ------------------------------------------------------------------ */

$home = 'https://example.test';
$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Test page</title>
<link rel="profile" href="http://gmpg.org/xfn/11">
<link rel="stylesheet" id="theme-css" href="' . $home . '/wp-content/themes/xstore/style.css?ver=1.2" media="all">
<link rel="stylesheet" id="plugin-css" href="' . $home . '/wp-content/plugins/jet/pl.css?ver=9" media="all">
<link rel="stylesheet" id="fonts-css" href="https://fonts.googleapis.com/css?family=Inter:wght@400&display=swap" media="all">
<style>body{background:#fff}</style>
<script type="application/ld+json">{"@type":"WebSite","name":"EvergoCasa"}</script>
</head>
<body class="home">
<!-- build comment should disappear -->
<noscript><img src="https://www.facebook.com/tr?id=1&ev=PageView&noscript=1" height="1" width="1" style="display:none"></noscript>
<img src="' . $home . '/wp-content/uploads/2026/08/hero.webp" alt="Sofa" width="800" height="600">
<img src="' . $home . '/wp-content/uploads/2026/08/sofa2.webp" alt="Sofa 2" width="800" height="600" srcset="a.webp 1x">
<img src="' . $home . '/wp-content/uploads/flag.png" class="trp-flag-image lzl" alt="EN">
<img src="https://analytics.example.com/pixel.gif" width="1" height="1" alt="">
<script src="' . $home . '/wp-includes/js/jquery/jquery.min.js?ver=3.7"></script>
<script src="' . $home . '/wp-content/plugins/xstore/x.js?ver=2"></script>
<a class="more-link" href="' . $home . '/insights/mattress-adhesives-voc-compliance/">READ MORE</a>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js"></script>
<script>jQuery(document).ready(function($){ $(".slider").init(); });</script>
</body>
</html>';

// Fake local assets so CSS minification has files to work on.
@mkdir( WP_CONTENT_DIR . '/themes/xstore', 0755, true );
file_put_contents( WP_CONTENT_DIR . '/themes/xstore/style.css', "/* theme */ .layout{max-width:1200px;margin:0 auto}\n.site-header{position:relative}\n" );
@mkdir( WP_CONTENT_DIR . '/plugins/jet', 0755, true );
file_put_contents( WP_CONTENT_DIR . '/plugins/jet/pl.css', ".jet{color:#000}\n" );

/* ------------------------------------------------------------------ */
/* 2. Pipeline: defer mode + page cache on                             */
/* ------------------------------------------------------------------ */

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'example.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['HTTPS']          = 'on';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0';
$_COOKIE = array();

$sett = \FeatherCache\Options::all();
$sett['js_mode'] = 'defer';
$GLOBALS['__options']['feathercache_settings'] = $sett;

$out = \FeatherCache\HtmlPipeline::process( $html );
file_put_contents( 'C:/seraph-tmp/out-defer.html', $out );

check( 'defer: JSON-LD untouched', false !== strpos( $out, '<script type="application/ld+json">{"@type":"WebSite","name":"EvergoCasa"}</script>' ) );
check( 'defer: noscript img untouched', false !== strpos( $out, '<noscript><img src="https://www.facebook.com/tr' ) );
check( 'defer: jquery got defer attr', (bool) preg_match( '#<script src="[^"]*jquery\.min\.js\?ver=3\.7" defer>#', $out ) );
check( 'defer: inline script untouched in defer mode', false !== strpos( $out, '<script>jQuery(document).ready' ) );
check( 'defer: turnstile gets defer too (third-party OK in defer mode)', (bool) preg_match( '#<script src="https://challenges\.cloudflare\.com/turnstile/v0/api\.js" defer>#', $out ) );
check( 'css: theme css minified+rewritten to cache copy', (bool) preg_match( '#href="[^"]*/cache/feathercache/assets/[a-f0-9]{64}\.css\?v=[a-f0-9]{8}"#', $out ) );
check( 'css: critical copies retained (noscript fallback)', false !== strpos( $out, '<noscript><link' ) );
check( 'css: preload deferred link present', false !== strpos( $out, 'rel="preload" as="style"' ) );
check( 'css: external google fonts css left as-is', false !== strpos( $out, 'https://fonts.googleapis.com/css?family=Inter' ) );
check( 'html: build comment removed', false === strpos( $out, 'build comment should disappear' ) );
check( 'html: <style> content kept', false !== strpos( $out, 'body{background:#fff}' ) );
check( 'img: hero got fetchpriority=high', (bool) preg_match( '#hero\.webp" alt="Sofa" width="800" height="600" fetchpriority="high"#', $out ) );
check( 'img: hero preload added to head', false !== strpos( $out, '<link rel="preload" as="image" href="' ) );
check( 'img: second img lazy (srcset)', (bool) preg_match( '#sofa2\.webp[^>]*loading="lazy"#', $out ) );
check( 'img: tracking pixel NOT lazy', (bool) preg_match( '#analytics\.example\.com/pixel\.gif" width="1" height="1" alt="">#', $out ) );
check( 'img: lzl-flagged img untouched', false !== strpos( $out, 'trp-flag-image lzl' ) );

// Cache file written?
$cacheFile = \FeatherCache\CacheStore::pagePath( false, \FeatherCache\CacheKey::build( $sett )[0], false );
check( 'cache: page stored on disk', is_file( $cacheFile ) );
check( 'cache: gz sidecar stored', is_file( $cacheFile . '.gz' ) );

// Persist the processed HTML for the serve (phase B) comparison, and write
// the runtime config the drop-in reads (normally done on activation).
file_put_contents( __DIR__ . '/expected.html', $out );
\FeatherCache\Dropin::writeConfig();

/* ------------------------------------------------------------------ */
/* 3. Pipeline: delay mode                                             */
/* ------------------------------------------------------------------ */

$sett = array_merge( \FeatherCache\Options::all(), array( 'js_mode' => 'delay', 'js_delay_timeout' => 5000, 'page_cache' => 0 ) );
$GLOBALS['__options']['feathercache_settings'] = $sett;

$out2 = \FeatherCache\HtmlPipeline::process( $html );

check( 'delay: JSON-LD untouched', false !== strpos( $out2, '<script type="application/ld+json">' ) );
check( 'delay: jquery stays plain (wp-includes excluded)', (bool) preg_match( '#<script src="[^"]*jquery\.min\.js\?ver=3\.7"></script>#', $out2 ) );
check( 'delay: xstore theme script wrapped', (bool) preg_match( '#<template data-fc-delay="1"><script src="[^"]*xstore[^>]*></script></template>#', $out2 ) );
check( 'delay: inline jquery usage wrapped too', false !== strpos( $out2, '<template data-fc-delay="2"><script>jQuery' ) );
check( 'delay: exactly 2 templates (jquery/turnstile excluded)', 2 === substr_count( $out2, '<template data-fc-delay=' ) );
check( 'delay: turnstile stays a plain script', false !== strpos( $out2, '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js"></script>' ) );
check( 'delay: runtime injected before </body>', false !== strpos( $out2, 'feathercache-delay' ) && (bool) preg_match( '#feathercache-delay.*?</script></body>#s', $out2 ) );
check( 'delay: noscript img untouched', false !== strpos( $out2, '<noscript><img src="https://www.facebook.com/tr' ) );

/* ------------------------------------------------------------------ */
/* 3b. A11y: generic READ MORE link gets aria-label                    */
/* ------------------------------------------------------------------ */

check( 'a11y: READ MORE got aria-label with slug title', (bool) preg_match( '#aria-label="Read more: Mattress Adhesives Voc Compliance"#', $out2 ) );
check( 'a11y: visible link text untouched', false !== strpos( $out2, '>READ MORE</a>' ) );

/* ------------------------------------------------------------------ */
/* 4. Minifier protections                                             */
/* ------------------------------------------------------------------ */

$frag = "<body>\n<pre>  keep\n   this  </pre>\n<textarea>a\nb</textarea>\n<!-- x --><!--[if IE]>ie<![endif]-->\n<p>hello</p>";
$mini = \FeatherCache\HtmlPipeline::minifyHtml( $frag, $sett );
check( 'minify: <pre> intact', false !== strpos( $mini, "<pre>  keep\n   this  </pre>" ) );
check( 'minify: <textarea> intact', false !== strpos( $mini, "<textarea>a\nb</textarea>" ) );
check( 'minify: plain comment removed', false === strpos( $mini, '<!-- x -->' ) );
check( 'minify: IE conditional kept', false !== strpos( $mini, '<!--[if IE]>' ) );

/* ------------------------------------------------------------------ */
/* 5. CacheRules edge cases                                            */
/* ------------------------------------------------------------------ */

$ruleSett = array_merge( \FeatherCache\Options::all(), array( 'page_cache' => 1 ) );

$_SERVER['REQUEST_URI'] = '/checkout/';
check( 'rules: /checkout excluded', ! \FeatherCache\CacheRules::isCacheableRequest( $ruleSett ) );
$_SERVER['REQUEST_URI'] = '/?add-to-cart=123';
check( 'rules: add-to-cart query dynamic', ! \FeatherCache\CacheRules::isCacheableRequest( $ruleSett ) );
$_SERVER['REQUEST_URI'] = '/?utm_source=x';
check( 'rules: utm-only query cacheable', \FeatherCache\CacheRules::isCacheableRequest( $ruleSett ) );
$_SERVER['REQUEST_URI'] = '/?p=123&lang=de';
check( 'rules: whitelisted params cacheable', \FeatherCache\CacheRules::isCacheableRequest( $ruleSett ) );
$_SERVER['REQUEST_URI'] = '/?malicious=1';
check( 'rules: unknown param dynamic', ! \FeatherCache\CacheRules::isCacheableRequest( $ruleSett ) );
$_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php';
check( 'rules: wp-admin never cached', ! \FeatherCache\CacheRules::isCacheableRequest( $ruleSett ) );
$_SERVER['REQUEST_URI'] = '/';
$_COOKIE = array( 'wordpress_logged_in_abc123' => 'x' );
check( 'rules: logged-in cookie dynamic', ! \FeatherCache\CacheRules::isCacheableRequest( $sett ) );
$_COOKIE = array();

/* 6. SSRF guard */
check( 'warmer: own url allowed', \FeatherCache\Warmer::isOwnUrl( 'https://example.test/page/' ) );
check( 'warmer: foreign host rejected', ! \FeatherCache\Warmer::isOwnUrl( 'https://evil.example.com/' ) );
check( 'warmer: file scheme rejected', ! \FeatherCache\Warmer::isOwnUrl( 'file:///etc/passwd' ) );
check( 'warmer: localhost rejected', ! \FeatherCache\Warmer::isOwnUrl( 'http://localhost/' ) );
check( 'warmer: private ip rejected', ! \FeatherCache\Warmer::isOwnUrl( 'http://127.0.0.1/' ) );

/* 6. Seraph stub takeover                                              */
/* ------------------------------------------------------------------ */

@mkdir( WP_CONTENT_DIR . '', 0755, true );
file_put_contents(
	WP_CONTENT_DIR . '/advanced-cache.php',
	"<?php /* Disabled by seraphinite-accelerator */\n"
);
check( 'seraph-stub: detected as removable', \FeatherCache\Dropin::isSeraphStub() );
check( 'seraph-stub: not treated as foreign', ! \FeatherCache\Dropin::foreignDropinExists() );
$GLOBALS['__options']['feathercache_settings'] = \FeatherCache\Options::all();
\FeatherCache\Dropin::install();
$tookOver = (string) file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' );
check( 'seraph-stub: install took over the slot', false !== strpos( $tookOver, 'Managed by FeatherCache' ) );
check( 'seraph-stub: ours after install', \FeatherCache\Dropin::isOurs() );

// A real foreign drop-in must still be refused.
file_put_contents(
	WP_CONTENT_DIR . '/advanced-cache.php',
	"<?php // Some Other Cache Plugin v9\n"
);
check( 'foreign: real foreign drop-in still refused', \FeatherCache\Dropin::foreignDropinExists() );
check( 'foreign: not mistaken for seraph stub', ! \FeatherCache\Dropin::isSeraphStub() );

// Remove the foreign drop-in so the self-heal test runs in a clean state.
unlink( WP_CONTENT_DIR . '/advanced-cache.php' );

/* 7. Self-heal: ensureRuntime restores a wiped config.json              */

$cfgPath = WP_CONTENT_DIR . '/cache/feathercache/config.json';
$GLOBALS['__options']['feathercache_settings'] = array_merge( \FeatherCache\Options::all(), array( 'page_cache' => 1 ) );
\FeatherCache\Dropin::writeConfig();
check( 'selfheal: config exists before wipe', file_exists( $cfgPath ) );
unlink( $cfgPath );
\FeatherCache\Dropin::ensureRuntime();
check( 'selfheal: ensureRuntime restored config.json', file_exists( $cfgPath ) );
$cfgNow = json_decode( (string) file_get_contents( $cfgPath ), true );
check( 'selfheal: restored config is valid', is_array( $cfgNow ) && isset( $cfgNow['v'] ) );

echo "\n" . ( $fail ? "FAILED: {$fail} assertion(s)" : 'ALL TESTS PASSED' ) . "\n";
exit( $fail ? 1 : 0 );

