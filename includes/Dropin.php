<?php
/**
 * Manages the advanced-cache.php drop-in, the WP_CACHE constant and the
 * compiled runtime config consumed by the drop-in (which runs before WP).
 *
 * @package FeatherCache
 */

namespace FeatherCache;

defined( 'ABSPATH' ) || exit;

final class Dropin {

	const MARKER = 'Managed by FeatherCache';

	public static function dropinPath(): string {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	public static function pluginAdvancedPath(): string {
		return FEATHERCACHE_DIR . 'advanced-cache.php';
	}

	public static function isOurs(): bool {
		$path = self::dropinPath();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		$c = (string) file_get_contents( $path );
		return false !== strpos( $c, self::MARKER );
	}

	/**
	 * Seraphinite Accelerator leaves an inert stub ("Disabled by …") in the
	 * drop-in slot when it deactivates. It is provably not a working cache,
	 * so FeatherCache may replace it on activation.
	 */
	public static function isSeraphStub(): bool {
		$path = self::dropinPath();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		$c = (string) file_get_contents( $path );
		return false === strpos( $c, self::MARKER )
			&& false !== strpos( $c, 'Disabled by seraphinite-accelerator' );
	}

	public static function foreignDropinExists(): bool {
		$path = self::dropinPath();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		if ( self::isSeraphStub() ) {
			return false; // Inert leftover of a deactivated plugin — safe to take over.
		}
		return ! self::isOurs();
	}

	/**
	 * Install / refresh the drop-in. Refuses to overwrite a foreign drop-in.
	 */
	public static function install(): bool {
		CacheStore::initDirs();
		self::writeConfig();

		if ( self::foreignDropinExists() ) {
			set_transient( 'feathercache_install_error', 'foreign_dropin', 300 );
			return false;
		}

		$stub = "<?php\n// " . self::MARKER . ' ' . FEATHERCACHE_VERSION . ". Do not edit.\n"
			. "if ( !defined( 'FEATHERCACHE_DROPIN' ) ) {\n"
			. "\tdefine( 'FEATHERCACHE_DROPIN', '1' );\n"
			. "\t\$fc_plugin_advanced = " . var_export( self::pluginAdvancedPath(), true ) . ";\n"
			. "\tif ( is_readable( \$fc_plugin_advanced ) ) { require_once \$fc_plugin_advanced; }\n"
			. "\tunset( \$fc_plugin_advanced );\n"
			. "}\n";

		$ok = @file_put_contents( self::dropinPath(), $stub, LOCK_EX );
		if ( false === $ok ) {
			set_transient( 'feathercache_install_error', 'dropin_write', 300 );
			return false;
		}

		self::ensureWpCache();
		return true;
	}

	public static function remove(): void {
		if ( self::isOurs() ) {
			@unlink( self::dropinPath() );
		}
	}

	/**
	 * Cron guard: rebuild runtime pieces if something wiped them (only when
	 * page cache is wanted); drop the drop-in when the module is off.
	 */
	public static function ensureRuntime(): void {
		$sett = Options::all();
		if ( empty( $sett['page_cache'] ) ) {
			self::remove();
			return;
		}
		if ( ! file_exists( CacheStore::root() . 'config.json' ) || ! self::isOurs() ) {
			self::install();
		}
	}

	/**
	 * Cache generation counter. flush() bumps it; pages written before the
	 * bump are ignored by the drop-in (closes the flush race window).
	 */
	public static function bumpGeneration(): void {
		$cfgFile = CacheStore::root() . 'config.json';
		$cfg     = array();
		$raw     = @file_get_contents( $cfgFile );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$cfg = $decoded;
			}
		}
		$cfg['gen'] = ( isset( $cfg['gen'] ) ? (int) $cfg['gen'] : 0 ) + 1;
		self::atomicWrite( CacheStore::root() . 'config.json', (string) wp_json_encode( $cfg ) );
	}

	public static function currentGeneration(): int {
		static $gen = null;
		if ( null !== $gen ) {
			return $gen;
		}
		$raw = @file_get_contents( CacheStore::root() . 'config.json' );
		$gen = 0;
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && isset( $decoded['gen'] ) ) {
				$gen = (int) $decoded['gen'];
			}
		}
		return $gen;
	}

	/**
	 * Compile settings into cache/feathercache/config.json — the only file
	 * the drop-in reads at runtime. One tiny JSON read per request.
	 */
	public static function writeConfig(): void {
		CacheStore::initDirs();

		$s = Options::all();

		$excludes = array();
		foreach ( Options::parseLines( (string) $s['exclude_urls'] ) as $line ) {
			if ( '#' === substr( $line, 0, 1 ) && false === @preg_match( $line, '' ) ) {
				continue;
			}
			$excludes[] = $line;
		}

		// flush() bumps the gen counter inside this very file — preserve it,
		// otherwise the generation guard in the drop-in silently dies.
		$gen = 0;
		$raw = @file_get_contents( CacheStore::root() . 'config.json' );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && isset( $decoded['gen'] ) ) {
				$gen = (int) $decoded['gen'];
			}
		}

		$cfg = array(
			'v'      => 2,
			'ttl'    => (int) $s['cache_ttl'],
			'mobile' => (int) $s['mobile_cache'],
			'gzip'   => (int) $s['gzip'],
			'hdr'    => (int) $s['serve_header'],
			'gen'    => $gen,
			'wl'     => Options::parseLines( (string) $s['query_whitelist'] ),
			'ex'     => $excludes,
		);

		self::atomicWrite( CacheStore::root() . 'config.json', (string) wp_json_encode( $cfg ) );
	}

	/**
	 * Atomic write (tmp + rename) so concurrent readers never see half a JSON.
	 */
	private static function atomicWrite( string $path, string $data ): void {
		$tmp = $path . '.' . getmypid() . '.tmp';
		if ( false !== @file_put_contents( $tmp, $data, LOCK_EX ) ) {
			@rename( $tmp, $path );
		}
		@unlink( $tmp );
	}

	/**
	 * Ensure `WP_CACHE = true` so WP loads the drop-in. Marker-based,
	 * backup-first, only when the file is writable.
	 */
	public static function ensureWpCache(): bool {
		if ( defined( 'WP_CACHE' ) && constant( 'WP_CACHE' ) ) {
			return true;
		}

		$path = self::wpConfigPath();
		if ( null === $path ) {
			return false;
		}

		$config = file_get_contents( $path );
		if ( false === $config || '' === $config ) {
			return false;
		}

		if ( false !== strpos( $config, "'WP_CACHE'" ) || false !== strpos( $config, '"WP_CACHE"' ) ) {
			// Defined (perhaps false) elsewhere — do not fight other tooling.
			return defined( 'WP_CACHE' ) && constant( 'WP_CACHE' );
		}

		if ( ! is_writable( $path ) ) {
			return false;
		}

		$snippet = "define( 'WP_CACHE', true ); // Added by FeatherCache\n";
		$updated = false;

		if ( 0 === strpos( ltrim( $config ), '<?php' ) ) {
			$pos     = strpos( $config, '<?php' ) + 5;
			$updated = substr( $config, 0, $pos ) . "\n" . $snippet . substr( $config, $pos );
		}

		if ( false === $updated ) {
			return false;
		}

		$tmp = $path . '.feathercache.tmp';
		if ( false === @file_put_contents( $tmp, $updated, LOCK_EX ) ) {
			return false;
		}
		$ok = @rename( $tmp, $path );
		// Never leave key-material copies in the web root.
		@unlink( $tmp );
		@unlink( $path . '.feathercache-backup' );
		return $ok;
	}

	/**
	 * Remove the FeatherCache WP_CACHE line (uninstall only).
	 */
	public static function removeWpCache(): void {
		$path = self::wpConfigPath();
		if ( null === $path || ! is_writable( $path ) ) {
			return;
		}
		$config = file_get_contents( $path );
		if ( false === $config || false === strpos( $config, 'Added by FeatherCache' ) ) {
			return;
		}
		$clean = preg_replace( "/^\s*define\(\s*'WP_CACHE',\s*true\s*\);\s*\/\/ Added by FeatherCache\r?\n/m", '', $config );
		if ( is_string( $clean ) && '' !== $clean && $clean !== $config ) {
			@file_put_contents( $path, $clean, LOCK_EX );
		}
	}

	private static function wpConfigPath(): ?string {
		foreach ( array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' ) as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}
		return null;
	}
}
