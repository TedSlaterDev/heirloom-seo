<?php
/**
 * PHPUnit bootstrap. Tests use Brain Monkey to stub WordPress functions, so no
 * WordPress install is required — run with `composer install && composer test`.
 *
 * @package OrchardGrove\HeirloomSeo
 */

declare( strict_types=1 );

// Plugin source files guard on ABSPATH; define it so they load under PHPUnit.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HEIRLOOM_SEO_VERSION' ) ) {
	define( 'HEIRLOOM_SEO_VERSION', 'test' );
}
if ( ! defined( 'HEIRLOOM_SEO_BASENAME' ) ) {
	define( 'HEIRLOOM_SEO_BASENAME', 'heirloom-seo/heirloom-seo.php' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Minimal stub for the one WordPress class used in type hints.
if ( ! class_exists( 'WP_Post' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Post {
		public int $ID            = 0;
		public string $post_status   = 'publish';
		public string $post_type     = 'post';
		public string $post_password = '';
		public string $post_title    = '';
		public string $post_content  = '';
		public string $post_excerpt  = '';
		public int $post_author    = 0;
		public int $post_parent    = 0;

		/** @param array<string,mixed> $props */
		public function __construct( array $props = [] ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
