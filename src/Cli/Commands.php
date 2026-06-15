<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Cli;

use OrchardGrove\HeirloomSeo\Audit\Audit;
use OrchardGrove\HeirloomSeo\Migration\Importer;
use OrchardGrove\HeirloomSeo\Modules\IndexNow\IndexNow;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Support\FileCache;

defined( 'ABSPATH' ) || exit;

/**
 * Heirloom SEO WP-CLI commands. Registered only when WP-CLI is loaded.
 */
final class Commands {

	public static function register(): void {
		\WP_CLI::add_command( 'heirloom-seo', self::class );
	}

	/**
	 * Purge the sitemap / AI output cache.
	 *
	 * ## EXAMPLES
	 *     wp heirloom-seo cache purge
	 *
	 * @param string[] $args
	 */
	public function cache( array $args ): void {
		if ( ( $args[0] ?? '' ) !== 'purge' ) {
			\WP_CLI::error( 'Usage: wp heirloom-seo cache purge' );
		}
		FileCache::purge();
		\WP_CLI::success( 'Sitemap / AI cache purged.' );
	}

	/**
	 * Regenerate the sitemap (clears the cache; it rebuilds on next request).
	 *
	 * ## EXAMPLES
	 *     wp heirloom-seo sitemap regenerate
	 *
	 * @param string[] $args
	 */
	public function sitemap( array $args ): void {
		if ( ( $args[0] ?? '' ) !== 'regenerate' ) {
			\WP_CLI::error( 'Usage: wp heirloom-seo sitemap regenerate' );
		}
		FileCache::purge();
		\WP_CLI::success( 'Sitemap cache cleared — it will rebuild on the next request.' );
	}

	/**
	 * Submit a post URL to IndexNow.
	 *
	 * ## OPTIONS
	 * [--post=<id>]
	 * : The post ID to submit.
	 *
	 * ## EXAMPLES
	 *     wp heirloom-seo indexnow submit --post=123
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc
	 */
	public function indexnow( array $args, array $assoc ): void {
		if ( ( $args[0] ?? '' ) !== 'submit' ) {
			\WP_CLI::error( 'Usage: wp heirloom-seo indexnow submit --post=<id>' );
		}
		$post_id = (int) ( $assoc['post'] ?? 0 );
		if ( $post_id <= 0 ) {
			\WP_CLI::error( 'Provide --post=<id>.' );
		}
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			\WP_CLI::error( 'No permalink for that post.' );
		}
		$ok = ( new IndexNow( new Options() ) )->submitNow( [ (string) $url ] );
		if ( $ok ) {
			\WP_CLI::success( "Submitted to IndexNow: {$url}" );
		} else {
			\WP_CLI::error( 'Submit failed — is IndexNow enabled with a key set?' );
		}
	}

	/**
	 * Import per-post SEO data from another plugin.
	 *
	 * ## OPTIONS
	 * <source>
	 * : One of: yoast, rankmath, aioseo, tsf.
	 *
	 * [--overwrite]
	 * : Overwrite existing Heirloom values (default: skip).
	 *
	 * [--dry-run]
	 * : Report without writing.
	 *
	 * ## EXAMPLES
	 *     wp heirloom-seo import yoast
	 *     wp heirloom-seo import rankmath --overwrite
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc
	 */
	public function import( array $args, array $assoc ): void {
		$source = Importer::source( $args[0] ?? '' );
		if ( ! $source ) {
			\WP_CLI::error( 'Usage: wp heirloom-seo import <yoast|rankmath|aioseo|tsf> [--overwrite] [--dry-run]' );
		}

		$total = $source->total();
		if ( $total <= 0 ) {
			\WP_CLI::error( "No {$source->label()} data found." );
		}

		$overwrite = isset( $assoc['overwrite'] );
		$dry_run   = isset( $assoc['dry-run'] );
		$importer  = new Importer();
		$progress  = \WP_CLI\Utils\make_progress_bar( "Importing from {$source->label()}", $total );

		$offset   = 0;
		$limit    = 200;
		$imported = 0;
		do {
			$result    = $importer->importBatch( $source, $offset, $limit, $overwrite, $dry_run );
			$imported += $result['imported'];
			$progress->tick( $result['ids'] );
			$offset += $limit;
		} while ( $result['ids'] >= $limit );

		$progress->finish();
		$verb = $dry_run ? 'would import' : 'imported';
		\WP_CLI::success( "Heirloom SEO {$verb} data for {$imported} posts from {$source->label()}." );
	}

	/**
	 * Run the SEO health audit.
	 *
	 * ## OPTIONS
	 * [--full]
	 * : Include reachability + content checks.
	 *
	 * ## EXAMPLES
	 *     wp heirloom-seo audit
	 *     wp heirloom-seo audit --full
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc
	 */
	public function audit( array $args, array $assoc ): void {
		$audit    = new Audit( new Options() );
		$findings = $audit->configChecks();
		if ( isset( $assoc['full'] ) ) {
			$findings = array_merge( $findings, $audit->httpChecks(), $audit->contentChecks() );
		}

		$colors = [ 'ok' => '%g', 'warn' => '%y', 'error' => '%r', 'info' => '%c' ];
		foreach ( $findings as $finding ) {
			$color = $colors[ $finding['status'] ] ?? '%n';
			\WP_CLI::log( \WP_CLI::colorize( $color . str_pad( strtoupper( $finding['status'] ), 5 ) . '%n' ) . ' ' . $finding['label'] . ' — ' . $finding['detail'] );
		}
	}
}
