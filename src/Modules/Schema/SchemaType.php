<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Article schema type, used for the per-post override dropdown and for the
 * automatic Article vs NewsArticle decision.
 */
enum SchemaType: string {
	case Article     = 'Article';
	case NewsArticle = 'NewsArticle';
	case BlogPosting = 'BlogPosting';

	public function label(): string {
		return match ( $this ) {
			self::Article     => __( 'Article', 'heirloom-seo' ),
			self::NewsArticle => __( 'News article', 'heirloom-seo' ),
			self::BlogPosting => __( 'Blog posting', 'heirloom-seo' ),
		};
	}

	/** @return array<string,string> value => label, for select fields. */
	public static function choices(): array {
		$out = [];
		foreach ( self::cases() as $case ) {
			$out[ $case->value ] = $case->label();
		}
		return $out;
	}
}
