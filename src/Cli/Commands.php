<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Cli;

use OrchardGrove\HeirloomSeo\Audit\Audit;
use OrchardGrove\HeirloomSeo\Migration\Importer;
use OrchardGrove\HeirloomSeo\Migration\YoastRobotsRepair;
use OrchardGrove\HeirloomSeo\Modules\Ai\LlmsTxt;
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
	 * Find, and optionally fix, noindex flags the Yoast importer got backwards.
	 *
	 * Heirloom SEO 0.7.18 and earlier read Yoast's per-post "Allow search engines
	 * to show this post in search results?" setting backwards. This compares each
	 * post's Yoast value with its Heirloom noindex flag and lists the ones that
	 * disagree. Nothing changes unless you pass --fix.
	 *
	 * ## OPTIONS
	 *
	 * <what>
	 * : What to repair. Only `yoast-noindex` for now.
	 *
	 * [--fix]
	 * : Apply the listed changes (asks first unless --yes).
	 *
	 * [--only=<which>]
	 * : `add`: posts Yoast marked noindex that were imported as indexable. `remove`: posts Yoast marked "index" that were imported as noindex.
	 *
	 * [--exclude=<ids>]
	 * : Comma-separated post IDs to leave alone, e.g. flags an editor has since set on purpose.
	 *
	 * [--format=<format>]
	 * : table, csv, json, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *     wp heirloom-seo repair yoast-noindex
	 *     wp heirloom-seo repair yoast-noindex --format=csv > yoast-noindex.csv
	 *     wp heirloom-seo repair yoast-noindex --fix --exclude=123,456
	 *
	 * @param string[]             $args
	 * @param array<string,string> $assoc
	 */
	public function repair( array $args, array $assoc ): void {
		if ( 'yoast-noindex' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Usage: wp heirloom-seo repair yoast-noindex [--fix] [--only=add|remove] [--exclude=<ids>] [--format=<format>] [--yes]' );
		}
		$only = (string) ( $assoc['only'] ?? '' );
		if ( '' !== $only && ! in_array( $only, [ 'add', 'remove' ], true ) ) {
			\WP_CLI::error( '--only must be "add" or "remove".' );
		}

		$repair = new YoastRobotsRepair();
		if ( ! $repair->hasYoastData() ) {
			\WP_CLI::error( "No Yoast robots settings found in post meta, so there's nothing to compare against." );
		}

		$exclude = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $assoc['exclude'] ?? '' ) ) ) ) );
		$items   = YoastRobotsRepair::filter( $repair->findMismatches(), $only, $exclude );
		$format  = (string) ( $assoc['format'] ?? 'table' );
		$adds    = count( array_filter( $items, static fn( array $item ): bool => YoastRobotsRepair::ADD === $item['action'] ) );
		$removes = count( $items ) - $adds;

		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_column( $items, 'id' ) ) );
		} elseif ( $items || 'table' !== $format ) {
			\WP_CLI\Utils\format_items( $format, $items, [ 'id', 'title', 'type', 'status', 'yoast', 'heirloom', 'action' ] );
		}

		if ( ! $items ) {
			if ( 'table' === $format ) {
				\WP_CLI::success( 'Every post with an explicit Yoast setting already matches its Heirloom flag. Nothing to repair.' );
			}
			return;
		}
		if ( ! isset( $assoc['fix'] ) ) {
			if ( 'table' === $format ) {
				\WP_CLI::log( "{$adds} to add noindex, {$removes} to remove it. Nothing was changed; run again with --fix to apply." );
			}
			return;
		}

		\WP_CLI::confirm( "Add noindex to {$adds} posts and remove it from {$removes}?", $assoc );
		$changed = $repair->apply( $items );
		FileCache::purge();   // Sitemaps leave noindexed posts out.
		LlmsTxt::markDirty(); // So does /llms.txt.
		\WP_CLI::success( "Changed {$changed} posts: noindex added to {$adds}, removed from {$removes}. Sitemap cache cleared." );
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
