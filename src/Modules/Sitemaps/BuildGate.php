<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Sitemaps;

defined( 'ABSPATH' ) || exit;

/**
 * Caps how many sitemap files can be generated at the same time.
 *
 * Crawlers fetch sitemap pages in bursts — hundreds in the same second. Without
 * a cap, every cold page in the burst builds concurrently and together they
 * occupy every PHP worker, taking the whole site down. Each slot is a MySQL
 * named lock (GET_LOCK with a zero timeout), so a slot is freed automatically
 * if the request dies, and the cap holds across all web servers sharing the
 * database. Requests that find every slot taken are served the previous copy
 * or told to retry later instead.
 *
 * Provider pages share the numbered slots. A single-file sitemap (the index,
 * the news sitemap) gets a slot of its own, so it never waits behind a burst
 * of page builds and is built by one request at a time: at most slots + 2
 * builds run at once.
 *
 * The cap is cluster-wide, but the cached files live in each server's uploads
 * directory: on several web servers without shared uploads, a change marks
 * pages stale only on the server that handled it, and the others catch up at
 * the cache TTL.
 *
 * If the database refuses named locks, acquire() fails open: generation is
 * never blocked by the gate itself.
 */
final class BuildGate {

	private ?string $held = null;

	/**
	 * @param string $own  A dedicated slot name (e.g. 'index'), or '' for the shared numbered slots.
	 * @param int    $wait Seconds to wait for a slot (0: take it now or not at all).
	 */
	public function __construct( private string $own = '', private int $wait = 0 ) {}

	public function acquire(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return true;
		}

		$slots = '' !== $this->own ? [ $this->own ] : range( 1, max( 1, (int) apply_filters( 'heirloom_seo_sitemap_build_slots', 2 ) ) );
		foreach ( $slots as $slot ) {
			$name = self::lockName( $slot );
			$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $this->wait ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( null === $got ) {
				return true; // Named locks unavailable — fail open.
			}
			if ( '1' === (string) $got ) {
				$this->held = $name;
				return true;
			}
		}
		return false;
	}

	public function release(): void {
		global $wpdb;
		if ( null === $this->held || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->held = null;
	}

	/** Lock names are server-wide, so they carry this site's database + table prefix. */
	private static function lockName( int|string $slot ): string {
		global $wpdb;
		$site = ( isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '' ) . '|' . ( isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '' );
		return 'heirloom_sm_' . substr( md5( $site ), 0, 20 ) . '_' . $slot;
	}
}
