<?php
/**
 * File-based cache storage. All paths are derived from SHA-256 hashes of
 * normalized cache keys — no user input ever reaches a filesystem path.
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class CacheStore {

	/**
	 * Create the cache root with directory-listing protection.
	 * Page dirs (d/, m/) deny access; assets/ allows it (public CSS copies);
	 * root itself just gets an index.php guard.
	 */
	public static function initDirs(): void {
		$root = self::root();

		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}

		if ( is_dir( $root ) ) {
			if ( ! file_exists( $root . 'index.php' ) ) {
				@file_put_contents( $root . 'index.php', "<?php // Silence is golden.\n" );
			}
			if ( ! is_dir( $root . 'assets' ) ) {
				wp_mkdir_p( $root . 'assets' );
			}
		}

		$deny  = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
		$allow = "<IfModule mod_authz_core.c>\nRequire all granted\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nAllow from all\n</IfModule>\n";

		foreach ( array( 'd', 'm' ) as $sub ) {
			$dir = $root . $sub;
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			if ( is_dir( $dir ) && ! file_exists( $dir . '/.htaccess' ) ) {
				@file_put_contents( $dir . '/.htaccess', $deny );
			}
		}
		if ( is_dir( $root . 'assets' ) && ! file_exists( $root . 'assets/.htaccess' ) ) {
			@file_put_contents( $root . 'assets/.htaccess', $allow );
		}
	}

	public static function root(): string {
		return FEATHERCACHE_CACHE_DIR;
	}

	/**
	 * Atomic page write: meta JSON + NUL + body in a single file, plus a
	 * gzipped body sidecar. tmp file + rename = readers never see half writes.
	 */
	public static function writePage( bool $mobile, string $key, string $html, string $contentType, int $ttl, bool $gzip ): bool {
		$dir = self::pageDir( $mobile, $key );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$meta = wp_json_encode(
			array(
				't'  => time(),
				'ct' => substr( $contentType, 0, 100 ),
				'g'  => Dropin::currentGeneration(),
			)
		);
		if ( false === $meta ) {
			return false;
		}

		$payload = $meta . "\x00" . $html;
		$base    = $dir . self::hashOf( $key );

		$ok = self::atomicWrite( $base . '.html', $payload );

		if ( $ok && $gzip && function_exists( 'gzencode' ) ) {
			$gz = gzencode( $html, 6 );
			if ( is_string( $gz ) ) {
				self::atomicWrite( $base . '.html.gz', $gz );
			}
		}

		return $ok;
	}

	public static function pagePath( bool $mobile, string $key, bool $gz ): string {
		$h = self::hashOf( $key );
		return self::pageDir( $mobile, $key ) . $h . ( $gz ? '.html.gz' : '.html' );
	}

	private static function pageDir( bool $mobile, string $key ): string {
		$h = self::hashOf( $key );
		return self::root() . ( $mobile ? 'm' : 'd' ) . '/' . substr( $h, 0, 2 ) . '/';
	}

	private static function hashOf( string $key ): string {
		return hash( 'sha256', $key );
	}

	private static function atomicWrite( string $path, string $data ): bool {
		$tmp = $path . '.' . getmypid() . '.tmp';
		if ( false === @file_put_contents( $tmp, $data, LOCK_EX ) ) {
			return false;
		}
		return @rename( $tmp, $path );
	}

	public static function flush(): void {
		self::rrmdir( self::root() . 'd' );
		self::rrmdir( self::root() . 'm' );
		self::initDirs(); // Recreate d/m with their deny .htaccess files.
		update_option( 'feathercache_last_flush', time(), false );
		if ( class_exists( Dropin::class ) ) {
			Dropin::bumpGeneration(); // Invalidate pages written before/during this flush.
		}
	}

	/**
	 * Hourly GC: remove expired page files (and stale asset copies).
	 * Best-effort; capped to keep a single run short.
	 */
	public static function gc(): void {
		$sett = Options::all();
		$ttl  = (int) $sett['cache_ttl'];
		$now  = time();

		foreach ( array( 'd', 'm' ) as $view ) {
			$dir = self::root() . $view;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$count = 0;
			try {
				$it = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
				);
				foreach ( $it as $f ) {
					if ( $count > 2000 ) {
						break; // Time-slice: finish the rest next hour.
					}
					if ( $f->isFile() && ( $now - $f->getMTime() ) > ( $ttl * 2 ) ) {
						@unlink( $f->getPathname() );
						$count++;
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore
				continue;
			}
		}

		// Asset copies are content-addressed: GC only if untouched for 30 days.
		$assets = self::root() . 'assets';
		if ( is_dir( $assets ) ) {
			try {
				$it = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $assets, \FilesystemIterator::SKIP_DOTS )
				);
				foreach ( $it as $f ) {
					if ( $f->isFile() && ( $now - $f->getMTime() ) > 2592000 ) {
						@unlink( $f->getPathname() );
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore
				// Best effort.
			}
		}
	}

	/**
	 * Recursively delete. Best effort; failures are ignored (fail-open).
	 */
	private static function rrmdir( string $dir ): void {
		if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$rootReal = realpath( self::root() );
		$dirReal  = realpath( $dir );
		// Hard containment check: never delete outside the cache root.
		if ( false === $rootReal || false === $dirReal
			|| 0 !== strpos( $dirReal . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR )
			|| $dirReal === $rootReal ) {
			return;
		}
		try {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dirReal, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $f ) {
				if ( $f->isDir() ) {
					@rmdir( $f->getPathname() );
				} else {
					@unlink( $f->getPathname() );
				}
			}
			@rmdir( $dirReal );
		} catch ( \Throwable $e ) { // phpcs:ignore
			// Ignore — cache dir issues must never break the site.
		}
	}

	/**
	 * Map a same-site asset URL to a local file path.
	 * Returns null for external URLs or anything that escapes ABSPATH.
	 */
	public static function urlToPath( string $url ): ?string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) ) {
			return null;
		}

		// Only local (same-host) assets.
		$host     = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$homeHost = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' !== $host && '' !== $homeHost && $host !== strtolower( $homeHost ) ) {
			return null;
		}

		$path = (string) $parts['path'];

		$contentDir = trim( (string) wp_parse_url( content_url(), PHP_URL_PATH ), '/' );
		if ( '' === $contentDir ) {
			$contentDir = 'wp-content';
		}

		if ( 0 === strpos( $path, '/wp-includes/' ) ) {
			$rel = ltrim( $path, '/' );
		} elseif ( '' !== $contentDir && 0 === strpos( $path, '/' . $contentDir . '/' ) ) {
			$rel = 'wp-content' . substr( $path, strlen( '/' . $contentDir ) );
		} else {
			return null;
		}

		$base = realpath( ABSPATH );
		$file = realpath( ABSPATH . $rel );

		if ( false === $base || false === $file ) {
			return null;
		}
		// Path traversal containment.
		if ( 0 !== strpos( $file, $base . DIRECTORY_SEPARATOR ) || ! is_file( $file ) ) {
			return null;
		}
		return $file;
	}

	/**
	 * Minified/processed asset copy with a content-addressed name.
	 * Returns the new URL or null on any failure (caller keeps original).
	 */
	public static function putAsset( string $sourceUrl, string $ext, string $content, string $mtime ): ?string {
		self::initDirs();

		$ext   = substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $ext ) ), 0, 8 );
		$name  = hash( 'sha256', $sourceUrl . '|' . $mtime );
		$path  = self::root() . 'assets/' . $name . '.' . $ext;

		if ( '' === $ext || ! self::atomicWrite( $path, $content ) ) {
			return null;
		}

		$url = content_url( 'cache/feathercache/assets/' . $name . '.' . $ext );
		return $url . '?v=' . substr( $name, 0, 8 );
	}

	/**
	 * Approximate cache size for the admin status panel.
	 */
	public static function stats(): array {
		$out = array( 'files' => 0, 'bytes' => 0 );
		foreach ( array( 'd', 'm', 'assets' ) as $sub ) {
			$dir = self::root() . $sub;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			try {
				$it = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
				);
				foreach ( $it as $f ) {
					if ( $f->isFile() ) {
						$out['files']++;
						$out['bytes'] += $f->getSize();
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore
				continue;
			}
		}
		return $out;
	}
}
