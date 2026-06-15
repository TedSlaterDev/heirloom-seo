<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * A migration source — another SEO plugin whose per-post data we can import.
 */
interface SourceInterface {

	public function key(): string;

	public function label(): string;

	/** How many posts in the DB carry this source's SEO data. */
	public function total(): int;

	/**
	 * Post IDs carrying this source's data, paginated in a stable order.
	 *
	 * @return int[]
	 */
	public function postIds( int $offset, int $limit ): array;

	/**
	 * Map one post's source data to Heirloom meta. Only set keys are returned;
	 * boolean values mean "set this flag" (we never write `false`).
	 *
	 * @return array<string,string|bool>
	 */
	public function mapPost( int $post_id ): array;
}
