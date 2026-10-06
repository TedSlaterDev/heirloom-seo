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
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
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
		public string $post_date     = '';
		public string $post_modified = '';
		public string $post_name     = '';
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

// Minimal WP_Query: records its args instead of querying; tests flip $main.
if ( ! class_exists( 'WP_Query' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Query {
		/** @var array<int,array<string,mixed>> args of every WP_Query built with args */
		public static array $built = [];

		/** @var array<int,mixed> */
		public array $posts = [];
		public int $found_posts   = 0;
		public int $max_num_pages = 0;
		public bool $main         = false;

		/** @var array<string,mixed> */
		public array $query_vars = [];

		/** @var (callable(): void)|null runs inside each constructor that has args — the query's own SQL and hooks */
		public static $onConstruct = null;

		/** @param array<string,mixed>|string $query */
		public function __construct( $query = '' ) {
			if ( is_array( $query ) && $query ) {
				self::$built[]    = $query;
				$this->query_vars = $query;
				if ( null !== self::$onConstruct ) {
					( self::$onConstruct )();
				}
			}
		}

		/** @param mixed $fallback */
		public function get( string $key, $fallback = '' ): mixed {
			return $this->query_vars[ $key ] ?? $fallback;
		}

		/** @param mixed $value */
		public function set( string $key, $value ): void {
			$this->query_vars[ $key ] = $value;
		}

		public function is_main_query(): bool {
			return $this->main;
		}
	}
}
