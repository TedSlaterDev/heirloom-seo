<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Migration;

defined( 'ABSPATH' ) || exit;

final class RankMath extends AbstractMetaSource {

	public function key(): string {
		return 'rankmath';
	}

	public function label(): string {
		return 'Rank Math';
	}

	protected function detectKeys(): array {
		return [
			'rank_math_title',
			'rank_math_description',
			'rank_math_canonical_url',
			'rank_math_robots',
			'rank_math_facebook_image',
		];
	}

	public function mapPost( int $post_id ): array {
		$out = [];

		// Rank Math already uses %var% syntax, close to Heirloom's.
		$title = $this->meta( $post_id, 'rank_math_title' );
		if ( '' !== $title ) {
			$out['_heirloom_seo_title'] = $title;
		}
		$desc = $this->meta( $post_id, 'rank_math_description' );
		if ( '' !== $desc ) {
			$out['_heirloom_seo_desc'] = $desc;
		}
		$canonical = $this->meta( $post_id, 'rank_math_canonical_url' );
		if ( '' !== $canonical ) {
			$out['_heirloom_seo_canonical'] = $canonical;
		}
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $robots ) ) {
			if ( in_array( 'noindex', $robots, true ) ) {
				$out['_heirloom_seo_noindex'] = true;
			}
			if ( in_array( 'nofollow', $robots, true ) ) {
				$out['_heirloom_seo_nofollow'] = true;
			}
		}
		$image = $this->meta( $post_id, 'rank_math_facebook_image_id' );
		if ( '' === $image ) {
			$image = $this->meta( $post_id, 'rank_math_facebook_image' );
		}
		if ( '' !== $image ) {
			$out['_heirloom_seo_og_image'] = $image;
		}
		$schema = $this->mapArticleType( $this->meta( $post_id, 'rank_math_snippet_article_type' ) );
		if ( '' !== $schema ) {
			$out['_heirloom_seo_schema_type'] = $schema;
		}

		return $out;
	}
}
