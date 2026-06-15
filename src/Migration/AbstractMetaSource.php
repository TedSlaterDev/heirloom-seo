<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Shared logic for sources that store their data in postmeta (Yoast, Rank Math,
 * The SEO Framework). Detection and pagination run off the postmeta key index.
 */
abstract class AbstractMetaSource implements SourceInterface {

	/**
	 * Source meta keys whose presence signals importable data.
	 *
	 * @return string[]
	 */
	abstract protected function detectKeys(): array;

	public function total(): int {
		global $wpdb;
		$keys         = $this->detectKeys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders) AND meta_value <> ''", ...$keys ) );
	}

	public function postIds( int $offset, int $limit ): array {
		global $wpdb;
		$keys         = $this->detectKeys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$params       = array_merge( $keys, [ $limit, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders) AND meta_value <> '' ORDER BY post_id ASC LIMIT %d OFFSET %d", ...$params ) );
		return array_map( 'intval', $ids );
	}

	/** Translate the source's template variables to Heirloom syntax. */
	protected function translateVars( string $value ): string {
		return $value;
	}

	protected function meta( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );
		return is_string( $value ) ? $value : '';
	}

	protected function mapArticleType( string $type ): string {
		return in_array( $type, [ 'Article', 'NewsArticle', 'BlogPosting' ], true ) ? $type : '';
	}
}
