<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and fixes noindex flags imported by Heirloom SEO 0.7.18 and earlier,
 * whose Yoast importer read Yoast's per-post robots setting backwards (it took
 * 2 = noindex; Yoast stores 1 = noindex, 2 = index, 0 = post-type default).
 *
 * Compares each post's Yoast value with its Heirloom flag, so it needs Yoast's
 * post meta to still be in the database. It cannot tell a flag the importer
 * wrote from one an editor set by hand afterwards, which is why the CLI
 * reports first and only changes posts after confirmation.
 */
final class YoastRobotsRepair {

	public const YOAST_KEY    = '_yoast_wpseo_meta-robots-noindex';
	public const HEIRLOOM_KEY = '_heirloom_seo_noindex';

	public const ADD    = 'add_noindex';
	public const REMOVE = 'remove_noindex';

	/**
	 * What a post needs, given its Yoast value and its Heirloom flag (null when
	 * the post has no flag row). Null means it already matches Yoast.
	 */
	public static function classify( string $yoast, ?string $heirloom ): ?string {
		$flagged = null !== $heirloom && '' !== $heirloom && '0' !== $heirloom;
		if ( '1' === $yoast && ! $flagged ) {
			return self::ADD;
		}
		if ( '2' === $yoast && $flagged ) {
			return self::REMOVE;
		}
		return null;
	}

	public function hasYoastData(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off CLI check; must see the live table, not a cache.
		return null !== $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", self::YOAST_KEY )
		);
	}

	/**
	 * Every post whose Heirloom flag disagrees with its explicit Yoast setting.
	 *
	 * @return array<int,array{id:int,title:string,type:string,status:string,yoast:string,heirloom:string,action:string}>
	 */
	public function findMismatches(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off CLI scan joining two meta keys; no API does this, nothing to cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, p.post_status, y.meta_value AS yoast, h.meta_value AS heirloom
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} y ON y.post_id = p.ID AND y.meta_key = %s AND y.meta_value IN ('1', '2')
				LEFT JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s
				WHERE p.post_type <> 'revision'
				ORDER BY p.ID, y.meta_id, h.meta_id",
				self::YOAST_KEY,
				self::HEIRLOOM_KEY
			),
			ARRAY_A
		);

		$items = [];
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['ID'];
			if ( isset( $items[ $id ] ) ) {
				continue; // Duplicate meta rows: the first one is the value WordPress reads.
			}
			$heirloom = isset( $row['heirloom'] ) ? (string) $row['heirloom'] : null;
			$action   = self::classify( (string) $row['yoast'], $heirloom );
			$items[ $id ] = [
				'id'       => $id,
				'title'    => (string) $row['post_title'],
				'type'     => (string) $row['post_type'],
				'status'   => (string) $row['post_status'],
				'yoast'    => '1' === (string) $row['yoast'] ? 'noindex' : 'index',
				'heirloom' => null === $heirloom ? 'none' : $heirloom,
				'action'   => (string) $action,
			];
		}

		return array_values( array_filter( $items, static fn( array $item ): bool => '' !== $item['action'] ) );
	}

	/**
	 * @param array<int,array{id:int,action:string}> $items
	 * @param string   $only    '' (both), 'add' or 'remove'.
	 * @param int[]    $exclude Post IDs to leave alone.
	 * @return array<int,array{id:int,action:string}>
	 */
	public static function filter( array $items, string $only, array $exclude ): array {
		$want = match ( $only ) {
			'add'    => self::ADD,
			'remove' => self::REMOVE,
			default  => '',
		};
		return array_values(
			array_filter(
				$items,
				static fn( array $item ): bool => ( '' === $want || $want === $item['action'] ) && ! in_array( $item['id'], $exclude, true )
			)
		);
	}

	/**
	 * Writes the flags the way the post editor does: 1, or no row at all.
	 *
	 * @param array<int,array{id:int,action:string}> $items
	 * @return int Posts changed.
	 */
	public function apply( array $items ): int {
		$changed = 0;
		foreach ( $items as $item ) {
			if ( self::ADD === $item['action'] ) {
				update_post_meta( $item['id'], self::HEIRLOOM_KEY, 1 );
				++$changed;
			} elseif ( self::REMOVE === $item['action'] ) {
				delete_post_meta( $item['id'], self::HEIRLOOM_KEY );
				++$changed;
			}
		}
		return $changed;
	}
}
