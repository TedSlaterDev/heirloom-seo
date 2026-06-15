<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Migration;

defined( 'ABSPATH' ) || exit;

final class TheSeoFramework extends AbstractMetaSource {

	public function key(): string {
		return 'tsf';
	}

	public function label(): string {
		return 'The SEO Framework';
	}

	protected function detectKeys(): array {
		return [
			'_genesis_title',
			'_genesis_description',
			'_genesis_canonical_uri',
			'_genesis_noindex',
			'_social_image_url',
		];
	}

	public function mapPost( int $post_id ): array {
		$out = [];

		$title = $this->meta( $post_id, '_genesis_title' );
		if ( '' !== $title ) {
			$out['_heirloom_seo_title'] = $title;
		}
		$desc = $this->meta( $post_id, '_genesis_description' );
		if ( '' !== $desc ) {
			$out['_heirloom_seo_desc'] = $desc;
		}
		$canonical = $this->meta( $post_id, '_genesis_canonical_uri' );
		if ( '' !== $canonical ) {
			$out['_heirloom_seo_canonical'] = $canonical;
		}
		if ( '1' === $this->meta( $post_id, '_genesis_noindex' ) ) {
			$out['_heirloom_seo_noindex'] = true;
		}
		if ( '1' === $this->meta( $post_id, '_genesis_nofollow' ) ) {
			$out['_heirloom_seo_nofollow'] = true;
		}
		$image = $this->meta( $post_id, '_social_image_id' );
		if ( '' === $image ) {
			$image = $this->meta( $post_id, '_social_image_url' );
		}
		if ( '' !== $image ) {
			$out['_heirloom_seo_og_image'] = $image;
		}

		return $out;
	}
}
