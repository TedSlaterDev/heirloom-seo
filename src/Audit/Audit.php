<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Audit;

use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Support\FileCache;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Practical SEO diagnostics — no score, no gimmicks. Cheap config checks run
 * anytime; HTTP and content checks are heavier and run on demand only.
 *
 * Each finding: [ status: ok|warn|error|info, label, detail ].
 */
final class Audit {

	public const CONTENT_LIMIT = 5000;

	public function __construct( private Options $options ) {}

	/** @return array<int,array{status:string,label:string,detail:string}> */
	public function configChecks(): array {
		$out = [];

		$conflicts = $this->conflictingPlugins();
		$out[]     = $conflicts
			? [ 'status' => 'error', 'label' => __( 'Conflicting SEO plugin', 'heirloom-seo' ), 'detail' => sprintf( /* translators: %s: plugin names. */ __( '%s is active alongside Heirloom — disable it to avoid duplicate tags.', 'heirloom-seo' ), implode( ', ', $conflicts ) ) ]
			: [ 'status' => 'ok', 'label' => __( 'No conflicting SEO plugin', 'heirloom-seo' ), 'detail' => __( 'Heirloom is the only active SEO plugin.', 'heirloom-seo' ) ];

		$out[] = file_exists( ABSPATH . 'robots.txt' )
			? [ 'status' => 'warn', 'label' => __( 'robots.txt', 'heirloom-seo' ), 'detail' => __( 'A physical robots.txt exists and overrides Heirloom’s virtual rules.', 'heirloom-seo' ) ]
			: [ 'status' => 'ok', 'label' => __( 'robots.txt', 'heirloom-seo' ), 'detail' => __( 'Heirloom manages the virtual robots.txt.', 'heirloom-seo' ) ];

		$has_logo = '' !== $this->options->str( 'schema.org_logo' ) || (int) get_option( 'site_icon' ) > 0;
		$out[]    = $has_logo
			? [ 'status' => 'ok', 'label' => __( 'Schema identity', 'heirloom-seo' ), 'detail' => __( 'A logo / site icon is set for the Organization or Person.', 'heirloom-seo' ) ]
			: [ 'status' => 'warn', 'label' => __( 'Schema identity', 'heirloom-seo' ), 'detail' => __( 'No logo or site icon — your schema identity is incomplete.', 'heirloom-seo' ) ];

		$out[] = $this->options->bool( 'sitemaps.enabled' )
			? [ 'status' => 'ok', 'label' => __( 'XML sitemap', 'heirloom-seo' ), 'detail' => sprintf( /* translators: %s: URL. */ __( 'Enabled at %s', 'heirloom-seo' ), home_url( '/sitemap.xml' ) ) ]
			: [ 'status' => 'info', 'label' => __( 'XML sitemap', 'heirloom-seo' ), 'detail' => __( 'The sitemap is disabled.', 'heirloom-seo' ) ];

		if ( $this->options->bool( 'indexnow.enabled' ) ) {
			$out[] = '' !== $this->options->str( 'indexnow.key' )
				? [ 'status' => 'ok', 'label' => __( 'IndexNow', 'heirloom-seo' ), 'detail' => __( 'Enabled with a key.', 'heirloom-seo' ) ]
				: [ 'status' => 'warn', 'label' => __( 'IndexNow', 'heirloom-seo' ), 'detail' => __( 'Enabled, but no key has been generated.', 'heirloom-seo' ) ];
		}

		$out[] = [ 'status' => 'ok', 'label' => __( 'AI export safety', 'heirloom-seo' ), 'detail' => __( 'Password-protected and noindex posts are excluded from AI exports.', 'heirloom-seo' ) ];

		return $out;
	}

	/** @return array<int,array{status:string,label:string,detail:string}> */
	public function httpChecks(): array {
		$out = [];

		if ( $this->options->bool( 'sitemaps.enabled' ) ) {
			$url      = home_url( '/sitemap.xml' );
			$response = wp_remote_get( $url, [ 'timeout' => 8 ] );
			if ( is_wp_error( $response ) ) {
				$out[] = [ 'status' => 'error', 'label' => __( 'Sitemap reachable', 'heirloom-seo' ), 'detail' => $response->get_error_message() ];
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$body = (string) wp_remote_retrieve_body( $response );
				if ( 200 !== $code ) {
					$out[] = [ 'status' => 'error', 'label' => __( 'Sitemap reachable', 'heirloom-seo' ), 'detail' => sprintf( /* translators: 1: code, 2: url. */ __( 'Returned HTTP %1$d at %2$s', 'heirloom-seo' ), $code, $url ) ];
				} elseif ( $this->isWellFormedXml( $body ) ) {
					$out[] = [ 'status' => 'ok', 'label' => __( 'Sitemap valid', 'heirloom-seo' ), 'detail' => sprintf( /* translators: %d: count. */ __( 'Well-formed XML, %d sub-sitemaps in the index.', 'heirloom-seo' ), substr_count( $body, '<sitemap>' ) ) ];
				} else {
					$out[] = [ 'status' => 'error', 'label' => __( 'Sitemap valid', 'heirloom-seo' ), 'detail' => __( 'The sitemap XML is not well-formed.', 'heirloom-seo' ) ];
				}
			}
		}

		$key = $this->options->str( 'indexnow.key' );
		if ( $this->options->bool( 'indexnow.enabled' ) && '' !== $key ) {
			$key_url  = home_url( '/' . $key . '.txt' );
			$response = wp_remote_get( $key_url, [ 'timeout' => 8 ] );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$body     = is_wp_error( $response ) ? '' : trim( (string) wp_remote_retrieve_body( $response ) );
			$out[]    = ( 200 === $code && $body === $key )
				? [ 'status' => 'ok', 'label' => __( 'IndexNow key file', 'heirloom-seo' ), 'detail' => __( 'Reachable and correct.', 'heirloom-seo' ) ]
				: [ 'status' => 'warn', 'label' => __( 'IndexNow key file', 'heirloom-seo' ), 'detail' => sprintf( /* translators: %s: url. */ __( 'Not reachable at %s (flush permalinks?).', 'heirloom-seo' ), $key_url ) ];
		}

		return $out;
	}

	/** @return array<int,array{status:string,label:string,detail:string}> */
	public function contentChecks(): array {
		$out = [];

		$dupes = $this->duplicateTitles();
		$out[] = $dupes
			? [ 'status' => 'warn', 'label' => __( 'Duplicate titles', 'heirloom-seo' ), 'detail' => sprintf( /* translators: 1: count, 2: example. */ __( '%1$d titles are shared by more than one post (e.g. “%2$s”).', 'heirloom-seo' ), count( $dupes ), $dupes[0] ) ]
			: [ 'status' => 'ok', 'label' => __( 'Duplicate titles', 'heirloom-seo' ), 'detail' => __( 'No duplicate post/page titles found.', 'heirloom-seo' ) ];

		$missing = $this->missingDescriptions();
		$out[]   = $missing['count'] > 0
			? [ 'status' => 'warn', 'label' => __( 'Deliberate descriptions', 'heirloom-seo' ), 'detail' => sprintf( /* translators: 1: count, 2: scanned. */ __( '%1$d of the %2$d most-recent posts/pages have no description or excerpt (they fall back to an auto-generated one).', 'heirloom-seo' ), $missing['count'], $missing['scanned'] ) ]
			: [ 'status' => 'ok', 'label' => __( 'Deliberate descriptions', 'heirloom-seo' ), 'detail' => __( 'Every scanned post has a description or excerpt.', 'heirloom-seo' ) ];

		return $out;
	}

	/** @return array{cache_built:string} */
	public function status(): array {
		$ts = $this->lastCacheBuild();
		return [
			'cache_built' => $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : __( 'never (built on demand)', 'heirloom-seo' ),
		];
	}

	/** @return string[] */
	private function conflictingPlugins(): array {
		$known  = [
			'wordpress-seo/wp-seo.php'                    => 'Yoast SEO',
			'seo-by-rank-math/rank-math.php'              => 'Rank Math',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
			'autodescription/autodescription.php'         => 'The SEO Framework',
			'wp-seopress/seopress.php'                    => 'SEOPress',
		];
		$active = (array) get_option( 'active_plugins', [] );
		$found  = [];
		foreach ( $known as $file => $label ) {
			if ( in_array( $file, $active, true ) ) {
				$found[] = $label;
			}
		}
		return $found;
	}

	/** @return string[] up to 20 duplicated titles. */
	private function duplicateTitles(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( "SELECT post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') AND post_title <> '' GROUP BY post_title HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC LIMIT 20" );
		return array_map( 'strval', $rows );
	}

	/** @return array{count:int,scanned:int} */
	private function missingDescriptions(): array {
		$query = new WP_Query(
			[
				'post_type'              => [ 'post', 'page' ],
				'post_status'            => 'publish',
				'posts_per_page'         => self::CONTENT_LIMIT,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => true,
			]
		);

		$count   = 0;
		$scanned = 0;
		foreach ( $query->posts as $post ) {
			++$scanned;
			$manual = get_post_meta( $post->ID, '_heirloom_seo_desc', true );
			if ( is_string( $manual ) && '' !== $manual ) {
				continue;
			}
			if ( '' !== trim( (string) $post->post_excerpt ) ) {
				continue;
			}
			++$count;
		}

		return [ 'count' => $count, 'scanned' => $scanned ];
	}

	private function lastCacheBuild(): int {
		$dir = FileCache::dir();
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$latest = 0;
		foreach ( glob( trailingslashit( $dir ) . '*.cache' ) ?: [] as $file ) {
			$latest = max( $latest, (int) filemtime( $file ) );
		}
		return $latest;
	}

	private function isWellFormedXml( string $xml ): bool {
		if ( '' === trim( $xml ) || ! class_exists( '\DOMDocument' ) ) {
			return false;
		}
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$ok = $doc->loadXML( $xml );
		libxml_clear_errors();
		return (bool) $ok;
	}
}
