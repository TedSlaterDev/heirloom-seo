<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny file cache under wp-content/uploads/heirloom-seo-cache/.
 *
 * Used for rendered sitemap XML. Files survive object-cache flushes and are
 * cheap to serve. Writes are atomic (temp file + rename).
 *
 * Invalidation is per key: markStale() renames `key.cache` to `key.stale`, so a
 * caller that cannot rebuild right now (see the sitemap build gate) can still
 * serve the previous copy instead of rebuilding under load. purge() is the
 * everything-rebuilds reset for settings changes and upgrades.
 *
 * The files are local to each web server unless uploads are shared: on a
 * multi-server setup without shared uploads, invalidation reaches only the
 * server that made the change, and the others catch up when the copy expires.
 */
final class FileCache {

	private const SUBDIR = 'heirloom-seo-cache';

	public static function dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::SUBDIR;
	}

	public static function ensureDir(): void {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	public static function get( string $key ): ?string {
		$contents = @file_get_contents( self::path( $key ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return false === $contents ? null : $contents;
	}

	/**
	 * Write a fresh copy. A `.stale` copy is left in place: deleting it here
	 * could delete the new copy instead, if a concurrent markStale() renamed it
	 * in between. lookup() prefers `.cache`, and markStale() overwrites `.stale`.
	 *
	 * @return bool Whether the copy was written.
	 */
	public static function put( string $key, string $contents ): bool {
		self::ensureDir();
		$path = self::path( $key );
		$tmp  = $path . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return false;
		}
		if ( @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return true;
		}
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return false;
	}

	/**
	 * Cached contents plus whether they are fresh. Fresh = a `.cache` file no
	 * older than $ttl seconds ($ttl <= 0 disables the age check). An expired
	 * `.cache` file or a `.stale` file comes back with fresh = false.
	 *
	 * @return array{0:?string,1:bool}
	 */
	public static function lookup( string $key, int $ttl ): array {
		// Silenced reads: a concurrent markStale() may rename the file between
		// any two calls here, and then the `.stale` copy is the answer.
		$path     = self::path( $key );
		$mtime    = @filemtime( $path );                      // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$contents = false === $mtime ? false : @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( false !== $contents ) {
			return [ $contents, $ttl <= 0 || ( time() - $mtime ) < $ttl ];
		}
		$contents = @file_get_contents( self::stalePath( $key ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return false === $contents ? [ null, false ] : [ $contents, false ];
	}

	/** Mark keys stale: the copy stays servable, but the next reader that can rebuild will. */
	public static function markStale( string ...$keys ): void {
		foreach ( $keys as $key ) {
			@rename( self::path( $key ), self::stalePath( $key ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/** Delete keys outright (fresh and stale copies). */
	public static function forget( string ...$keys ): void {
		foreach ( $keys as $key ) {
			@unlink( self::path( $key ) );      // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@unlink( self::stalePath( $key ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Keys (fresh or stale) that start with $prefix.
	 *
	 * @return string[]
	 */
	public static function keys( string $prefix ): array {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$safe  = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $prefix );
		$base  = trailingslashit( $dir ) . $safe;
		$files = array_merge( glob( $base . '*.cache' ) ?: [], glob( $base . '*.stale' ) ?: [] );
		$keys  = [];
		foreach ( $files as $file ) {
			$keys[ (string) preg_replace( '/\.(cache|stale)$/', '', basename( $file ) ) ] = true;
		}
		return array_keys( $keys );
	}

	/**
	 * Nothing stays fresh. Sitemap copies (index, news, sub_*) are kept as
	 * `.stale`: still servable while every build slot is busy, and what a
	 * page's rebuild compares against to tell whether it changed. Everything
	 * else is deleted.
	 */
	public static function purge(): void {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$base = trailingslashit( $dir );
		foreach ( glob( $base . '*.cache' ) ?: [] as $file ) {
			$key = basename( $file, '.cache' );
			if ( self::keepsStaleCopy( $key ) ) {
				@rename( $file, $base . $key . '.stale' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			} else {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
		}
		foreach ( glob( $base . '*.stale' ) ?: [] as $file ) {
			if ( ! self::keepsStaleCopy( basename( $file, '.stale' ) ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
		}
		foreach ( glob( $base . '*.xml' ) ?: [] as $file ) { // Legacy files from < 0.3.1.
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	private static function keepsStaleCopy( string $key ): bool {
		return 'index' === $key || 'news' === $key || str_starts_with( $key, 'sub_' );
	}

	private static function path( string $key ): string {
		return self::base( $key ) . '.cache';
	}

	private static function stalePath( string $key ): string {
		return self::base( $key ) . '.stale';
	}

	private static function base( string $key ): string {
		$safe = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $key );
		return trailingslashit( self::dir() ) . $safe;
	}
}
