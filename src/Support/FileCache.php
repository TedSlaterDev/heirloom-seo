<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny file cache under wp-content/uploads/heirloom-seo-cache/.
 *
 * Used for rendered sitemap XML. Files survive object-cache flushes and are
 * cheap to serve. Writes are atomic (temp file + rename).
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
		$path = self::path( $key );
		if ( is_readable( $path ) ) {
			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false === $contents ? null : $contents;
		}
		return null;
	}

	public static function put( string $key, string $contents ): void {
		self::ensureDir();
		$path = self::path( $key );
		$tmp  = $path . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( false !== file_put_contents( $tmp, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! @rename( $tmp, $path ) ) {
				@unlink( $tmp );
			}
		}
	}

	public static function purge(): void {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array_merge(
			glob( trailingslashit( $dir ) . '*.cache' ) ?: [],
			glob( trailingslashit( $dir ) . '*.xml' ) ?: [] // Legacy files from < 0.3.1.
		);
		foreach ( $files as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	private static function path( string $key ): string {
		$safe = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $key );
		return trailingslashit( self::dir() ) . $safe . '.cache';
	}
}
