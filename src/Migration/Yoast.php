<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Migration;

defined( 'ABSPATH' ) || exit;

final class Yoast extends AbstractMetaSource {

	public function key(): string {
		return 'yoast';
	}

	public function label(): string {
		return 'Yoast SEO';
	}

	protected function detectKeys(): array {
		return [
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_canonical',
			'_yoast_wpseo_meta-robots-noindex',
			'_yoast_wpseo_opengraph-image',
		];
	}

	public function mapPost( int $post_id ): array {
		$out = [];

		$title = $this->translateVars( $this->meta( $post_id, '_yoast_wpseo_title' ) );
		if ( '' !== $title ) {
			$out['_heirloom_seo_title'] = $title;
		}
		$desc = $this->translateVars( $this->meta( $post_id, '_yoast_wpseo_metadesc' ) );
		if ( '' !== $desc ) {
			$out['_heirloom_seo_desc'] = $desc;
		}
		$canonical = $this->meta( $post_id, '_yoast_wpseo_canonical' );
		if ( '' !== $canonical ) {
			$out['_heirloom_seo_canonical'] = $canonical;
		}
		// Yoast: 0 = default, 1 = index, 2 = noindex.
		if ( '2' === $this->meta( $post_id, '_yoast_wpseo_meta-robots-noindex' ) ) {
			$out['_heirloom_seo_noindex'] = true;
		}
		if ( '1' === $this->meta( $post_id, '_yoast_wpseo_meta-robots-nofollow' ) ) {
			$out['_heirloom_seo_nofollow'] = true;
		}
		$image = $this->meta( $post_id, '_yoast_wpseo_opengraph-image-id' );
		if ( '' === $image ) {
			$image = $this->meta( $post_id, '_yoast_wpseo_opengraph-image' );
		}
		if ( '' !== $image ) {
			$out['_heirloom_seo_og_image'] = $image;
		}
		$schema = $this->mapArticleType( $this->meta( $post_id, '_yoast_wpseo_schema_article_type' ) );
		if ( '' !== $schema ) {
			$out['_heirloom_seo_schema_type'] = $schema;
		}

		return $out;
	}

	protected function translateVars( string $value ): string {
		return strtr(
			$value,
			[
				'%%title%%'    => '%title%',
				'%%sitename%%' => '%sitename%',
				'%%sep%%'      => '%sep%',
				'%%page%%'     => '%page%',
				'%%excerpt%%'  => '%excerpt%',
				'%%category%%' => '%category%',
				'%%tag%%'      => '%category%',
				'%%sitedesc%%' => '%tagline%',
			]
		);
	}
}
