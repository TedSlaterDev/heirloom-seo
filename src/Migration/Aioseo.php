<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * All in One SEO v4 stores per-post data in its own table (`{prefix}aioseo_posts`),
 * not postmeta — so this source talks to that table directly. Defensive about
 * column names, which vary across AIOSEO versions.
 */
final class Aioseo implements SourceInterface {

	public function key(): string {
		return 'aioseo';
	}

	public function label(): string {
		return 'All in One SEO';
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aioseo_posts';
	}

	private function tableExists(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table() ) );
	}

	public function total(): int {
		if ( ! $this->tableExists() ) {
			return 0;
		}
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
	}

	public function postIds( int $offset, int $limit ): array {
		if ( ! $this->tableExists() ) {
			return [];
		}
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM `$table` ORDER BY post_id ASC LIMIT %d OFFSET %d", $limit, $offset ) );
		return array_map( 'intval', $ids );
	}

	public function mapPost( int $post_id ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE post_id = %d", $post_id ), ARRAY_A );
		if ( ! $row ) {
			return [];
		}

		$out = [];

		$title = $this->translate( (string) ( $row['title'] ?? '' ) );
		if ( '' !== $title ) {
			$out['_heirloom_seo_title'] = $title;
		}
		$desc = $this->translate( (string) ( $row['description'] ?? '' ) );
		if ( '' !== $desc ) {
			$out['_heirloom_seo_desc'] = $desc;
		}
		$canonical = (string) ( $row['canonical_url'] ?? '' );
		if ( '' !== $canonical ) {
			$out['_heirloom_seo_canonical'] = $canonical;
		}
		// Only import robots when the post overrides the global defaults.
		if ( empty( $row['robots_default'] ) ) {
			if ( ! empty( $row['robots_noindex'] ) ) {
				$out['_heirloom_seo_noindex'] = true;
			}
			if ( ! empty( $row['robots_nofollow'] ) ) {
				$out['_heirloom_seo_nofollow'] = true;
			}
		}
		$image = (string) ( $row['og_image_custom_url'] ?? '' );
		if ( '' !== $image ) {
			$out['_heirloom_seo_og_image'] = $image;
		}
		// AIOSEO v4 stores schema as a JSON graph — too lossy to map to a single
		// article type, so schema is intentionally not imported here.

		return $out;
	}

	private function translate( string $value ): string {
		return strtr(
			$value,
			[
				'#post_title'   => '%title%',
				'#site_title'   => '%sitename%',
				'#separator_sa' => '%sep%',
				'#tagline'      => '%tagline%',
				'#page_number'  => '%page%',
			]
		);
	}
}
