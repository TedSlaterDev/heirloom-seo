<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\HeirloomSeo\Admin\AuthorFields;
use OrchardGrove\HeirloomSeo\Cli\Commands;
use OrchardGrove\HeirloomSeo\Modules\Ai\LlmsTxt;
use OrchardGrove\HeirloomSeo\Modules\Authors\Authors;
use OrchardGrove\HeirloomSeo\Modules\Sitemaps\Sitemaps;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Settings\SettingsPage;
use OrchardGrove\HeirloomSeo\Support\FileCache;
use OrchardGrove\HeirloomSeo\Tests\Support\FakeWpdb;
use WP_Post;
use WP_Query;

require_once __DIR__ . '/Support/WpCli.php';

/**
 * Sitemaps under crawler load (wnd.com, 2026-09-30): every save_post wiped the
 * whole sitemap cache, pages were ordered newest-modified-first (so every edit
 * reshuffled every page), each page cost a filesort plus a couple of queries
 * per post, and nothing capped concurrent builds — a crawler fetching hundreds
 * of pages at once took the site down. These tests pin the replacements:
 * oldest-first pages built from one covered ID query and batched cache loads,
 * a build gate with stale/503 fallbacks, and per-page invalidation — plus the
 * fixes from the 0.7.21 review (F1–F13 and the critic's two findings), each
 * named after its finding.
 *
 * Call counts are recorded by the aliases themselves (arrays on $this), never
 * by a shared when()/expect() pair — see ImagesTest for why.
 */
final class SitemapsTest extends TestCase {

	private string $dir = '';

	private FakeWpdb $db;

	/** @var array<string,mixed> */
	private array $store = [];

	/** @var array<int,int[]> each _prime_post_caches() batch */
	private array $primed = [];

	/** @var bool[] each _prime_post_caches() batch's $update_term_cache */
	private array $primedTerms = [];

	/** @var array<int,int> post ID => featured image ID */
	private array $thumbs = [];

	/** @var array<int,string> shutdown callbacks registered */
	private array $shutdown = [];

	/** @var array<int,object> term ID => term (slug, parent) */
	private array $terms = [];

	/** @var array<int,int> post ID => excluded by heirloom_seo_sitemap_include_post */
	private array $excluded = [];

	/** @var string[] option names passed to wp_cache_delete() */
	private array $cacheDeletes = [];

	private bool $hierarchical = false;

	private bool $switched = false;

	/** _prime_post_caches() fails like a killed meta query. */
	private bool $primeFails = false;

	/** @var array<string,callable[]> hook => callbacks added with add_filter() */
	private array $filters = [];

	/** @var array<string,true> core's cached "no such option" names */
	private array $notoptions = [];

	/** @var string[] object-cache invalidations made (wp_cache_delete_multiple group, *_last_changed) */
	private array $cacheCalls = [];

	private int $blog = 1;

	/** @var array<string,true> reads that fail like a killed query: 'get_terms', 'wp_count_terms', 'get_users', 'get_term_by', 'lastmods' */
	private array $failing = [];

	/** The first _prime_post_caches() batch containing this ID fails (0: none). */
	private int $primeFailsFor = 0;

	/** @var (callable(): void)|null runs when the regenerate command sleeps between retries */
	private $onSleep = null;

	/** @var (callable(int[]): void)|null runs inside every _prime_post_caches() call */
	private $duringPrime = null;

	/** @var int[] seconds the regenerate command slept */
	private array $slept = [];

	private int $runtimeFlushes = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/heirloom-sm-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
		$this->db                = new FakeWpdb();
		$GLOBALS['wpdb']         = $this->db;
		$GLOBALS['EZSQL_ERROR']  = [];
		WP_Query::$built         = [];
		\WP_CLI::$messages       = [];
		$this->store             = [
			Options::OPTION        => [
				'sitemaps' => [
					'per_page' => 2,
					'images'   => true,
				],
			],
			'permalink_structure' => '/%year%/%monthnum%/%postname%/',
		];
		$this->primed       = [];
		$this->primedTerms  = [];
		$this->thumbs       = [];
		$this->shutdown     = [];
		$this->terms        = [];
		$this->excluded     = [];
		$this->cacheDeletes = [];
		$this->hierarchical = false;
		$this->switched     = false;
		$this->primeFails   = false;
		$this->filters      = [];
		$this->notoptions   = [];
		$this->cacheCalls   = [];
		$this->blog         = 1;
		$this->failing      = [];
		$this->primeFailsFor = 0;
		$this->onSleep      = null;
		$this->duringPrime  = null;
		$this->slept        = [];
		$this->runtimeFlushes = 0;
		$GLOBALS['wp_current_filter'] = [];
		$this->db->filter             = fn( string $sql ): string => $this->runFilters( 'query', $sql );
		WP_Query::$onConstruct        = null;

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( Sitemaps::LASTMOD_OPTION === $name && isset( $this->failing['lastmods'] ) ) {
					$this->failRead( "SELECT option_value FROM wp_options WHERE option_name = '{$name}' LIMIT 1" );
					return $fallback;
				}
				return $this->store[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'get_terms' )->alias( fn() => isset( $this->failing['get_terms'] ) ? $this->failRead( 'SELECT t.term_id FROM wp_terms', [] ) : [ (object) [ 'term_id' => 5 ], (object) [ 'term_id' => 6 ] ] );
		Functions\when( 'wp_count_terms' )->alias( fn() => isset( $this->failing['wp_count_terms'] ) ? $this->failRead( 'SELECT COUNT(*) FROM wp_terms', null ) : '2' );
		Functions\when( 'get_users' )->alias(
			function ( $args ) {
				if ( isset( $this->failing['get_users'] ) ) {
					return $this->failRead( 'SELECT wp_users.ID FROM wp_users', [] );
				}
				return 'ID' === ( $args['fields'] ?? '' ) ? [ '7', '8' ] : [ (object) [ 'ID' => 7 ], (object) [ 'ID' => 8 ] ];
			}
		);
		Functions\when( 'get_term_link' )->alias( static fn( $term ) => 'https://example.com/t/' . $term->term_id . '/' );
		Functions\when( 'get_author_posts_url' )->alias( static fn( $id ) => 'https://example.com/author/' . $id . '/' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_is_writable' )->alias( static fn( $path ) => is_writable( $path ) );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_flush_runtime' )->alias(
			function () {
				++$this->runtimeFlushes;
				return true;
			}
		);
		Functions\when( 'OrchardGrove\HeirloomSeo\Cli\sleep' )->alias(
			function ( $seconds ) {
				$this->slept[] = (int) $seconds;
				if ( null !== $this->onSleep ) {
					( $this->onSleep )();
				}
				return 0;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $name ) {
				$this->cacheDeletes[] = (string) $name;
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias( fn( $key, $group ) => 'notoptions' === $key && 'options' === $group ? $this->notoptions : false );
		Functions\when( 'wp_cache_set' )->alias(
			function ( $key, $value, $group ) {
				if ( 'notoptions' === $key && 'options' === $group ) {
					$this->notoptions = (array) $value;
				}
				return true;
			}
		);
		Functions\when( 'wp_cache_delete_multiple' )->alias(
			function ( $keys, $group ) {
				$this->cacheCalls[] = $group . ':' . implode( ',', $keys );
				return [];
			}
		);
		foreach ( [ 'wp_cache_set_terms_last_changed', 'wp_cache_set_posts_last_changed', 'wp_cache_set_users_last_changed' ] as $bump ) {
			Functions\when( $bump )->alias(
				function () use ( $bump ) {
					$this->cacheCalls[] = $bump;
				}
			);
		}
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) {
				$this->filters[ $hook ][] = $callback;
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( $hook, $callback ) {
				$this->filters[ $hook ] = array_values( array_filter( $this->filters[ $hook ] ?? [], static fn( $cb ): bool => $cb !== $callback ) );
				return true;
			}
		);
		Functions\when( 'has_filter' )->alias( fn( $hook ) => ! empty( $this->filters[ $hook ] ) );
		Functions\when( 'get_object_taxonomies' )->justReturn( [ 'category', 'post_tag' ] );
		Functions\when( 'get_current_blog_id' )->alias( fn() => $this->blog );
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value, ...$args ) => 'heirloom_seo_sitemap_include_post' === $hook && isset( $this->excluded[ $args[0]->ID ] ) ? false : $value
		);
		Functions\when( 'wp_upload_dir' )->alias( fn() => [ 'basedir' => $this->dir ] );
		Functions\when( 'trailingslashit' )->alias( static fn( $p ) => rtrim( (string) $p, '/' ) . '/' );
		Functions\when( 'wp_mkdir_p' )->alias( static fn( $p ) => is_dir( $p ) || mkdir( $p, 0777, true ) );
		Functions\when( 'wp_generate_password' )->alias( static fn() => bin2hex( random_bytes( 3 ) ) );
		Functions\when( 'get_post_types' )->justReturn( [ 'post' => 'post', 'attachment' => 'attachment' ] );
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'is_post_type_hierarchical' )->alias( fn() => $this->hierarchical );
		Functions\when( 'ms_is_switched' )->alias( fn() => $this->switched );
		Functions\when( 'get_taxonomies' )->justReturn( [] );
		Functions\when( 'get_term' )->alias( fn( $id ) => $this->terms[ (int) $id ] ?? null );
		Functions\when( 'get_term_by' )->alias( fn() => isset( $this->failing['get_term_by'] ) ? $this->failRead( 'SELECT t.* FROM wp_terms', false ) : false );
		Functions\when( 'esc_xml' )->returnArg( 1 );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example News' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_post' )->alias( fn( $id ) => isset( $this->db->published[ (int) $id ] ) ? $this->post( (int) $id ) : null );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_thumbnail_id' === $key ? (string) ( $this->thumbs[ (int) $id ] ?? '' ) : '' );
		Functions\when( 'get_permalink' )->alias( static fn( $post ) => 'https://example.com/p/' . $post->ID . '/' );
		Functions\when( 'get_post_modified_time' )->alias( static fn( $format, $gmt, $post ) => str_replace( ' ', 'T', $post->post_modified ) . '+00:00' );
		Functions\when( 'get_post_thumbnail_id' )->alias( fn( $post ) => $this->thumbs[ $post->ID ] ?? 0 );
		Functions\when( 'wp_get_attachment_image_url' )->alias( static fn( $id ) => 'https://example.com/img/' . $id . '.jpg' );
		Functions\when( '_prime_post_caches' )->alias(
			function ( $ids, $terms = true ) {
				$ids                 = array_values( array_map( 'intval', $ids ) );
				$this->primed[]      = $ids;
				$this->primedTerms[] = (bool) $terms;
				if ( null !== $this->duringPrime ) {
					( $this->duringPrime )( $ids );
				}
				if ( $this->primeFails || ( $this->primeFailsFor > 0 && in_array( $this->primeFailsFor, $ids, true ) ) ) {
					$this->primeFailsFor = 0; // Core's meta load, killed (once).
					$this->failRead( 'SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN (' . implode( ',', $ids ) . ')' );
				}
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) {
				if ( 'shutdown' === $hook ) {
					$this->shutdown[] = is_array( $callback ) ? (string) $callback[1] : 'closure';
				}
				$this->filters[ $hook ][] = $callback;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$this->rmdir( $this->dir );
		unset( $GLOBALS['wpdb'], $GLOBALS['EZSQL_ERROR'], $GLOBALS['wp_current_filter'], $GLOBALS['_wp_switched_stack'] );
		WP_Query::$onConstruct = null;
		parent::tearDown();
	}

	// --- Building ---------------------------------------------------------

	public function test_post_page_lists_oldest_first_from_one_covered_query_and_batched_loads(): void {
		$this->seed( 5 ); // IDs 101..105, oldest → newest.
		$this->thumbs = [ 103 => 900, 104 => 901 ];
		$t0           = gmdate( 'c' );

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 2 );

		self::assertSame( 200, $r['status'] );
		self::assertSame( 3600, $r['max_age'] );
		$id_queries = $this->db->ran( 'SELECT ID FROM' );
		self::assertCount( 1, $id_queries );
		self::assertStringContainsString( "post_type = 'post' AND post_status = 'publish' ORDER BY post_date ASC, ID ASC LIMIT 2, 2", $id_queries[0] );
		self::assertSame( [ [ 103, 104 ], [ 900, 901 ] ], $this->primed, 'posts load in one batch, their featured images in a second' );
		self::assertLessThan( strpos( $r['body'], '/p/104/' ), strpos( $r['body'], '/p/103/' ) );
		self::assertStringNotContainsString( '/p/105/', $r['body'] );
		self::assertStringContainsString( '/img/900.jpg', $r['body'] );
		self::assertTrue( $this->isFresh( 'sub_pt_post_2' ) );
		self::assertGreaterThanOrEqual( $t0, $this->lastmod( 'sub_pt_post_2' ), 'a page with no lastmod yet (first build, e.g. after the upgrade reordered pages) is advertised as changed now' );
		self::assertSame( [], $this->db->held, 'the build slot is released' );
	}

	public function test_fresh_cache_is_served_without_touching_the_database(): void {
		FileCache::put( 'sub_pt_post_1', '<cached/>' . Sitemaps::FORMAT_MARK );

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 1 );

		self::assertSame( [ 'status' => 200, 'body' => '<cached/>' . Sitemaps::FORMAT_MARK, 'max_age' => 3600 ], $r );
		self::assertSame( [], $this->db->queries );
	}

	public function test_full_gate_serves_the_previous_copy_without_building(): void {
		$this->seed( 3 );
		FileCache::put( 'sub_pt_post_1', '<previous/>' );
		FileCache::markStale( 'sub_pt_post_1' );
		$this->db->allBusy = true;

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 1 );

		self::assertSame( [ 'status' => 200, 'body' => '<previous/>', 'max_age' => 60 ], $r );
		self::assertSame( [], $this->db->ran( 'SELECT ID FROM' ), 'nothing was built' );
		self::assertSame( [], $this->db->ran( 'SELECT COUNT(*)' ) );
	}

	public function test_full_gate_with_nothing_cached_answers_503_instead_of_building(): void {
		$this->seed( 3 );
		$this->db->allBusy = true;

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 1 );

		self::assertSame( 503, $r['status'] );
		self::assertSame( [], $this->db->ran( 'SELECT ID FROM' ) );
	}

	public function test_page_past_the_end_is_404_and_not_cached(): void {
		$this->seed( 3 ); // Two pages at 2 per page.

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 5 );

		self::assertSame( 404, $r['status'] );
		self::assertSame( [ null, false ], FileCache::lookup( 'sub_pt_post_5', 0 ) );
		self::assertSame( [], $this->db->held );
		self::assertSame( [], $this->db->ran( 'SELECT COUNT(*)' ), 'F13: the empty ID page says so — no count' );
	}

	public function test_gate_fails_open_when_named_locks_are_unavailable(): void {
		$this->seed( 2 );
		$this->db->locksUnsupported = true;

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 1 );

		self::assertSame( 200, $r['status'] );
		self::assertStringContainsString( '/p/101/', $r['body'] );
	}

	public function test_expired_copy_is_rebuilt_when_a_slot_is_free(): void {
		$this->seed( 2 );
		FileCache::put( 'sub_pt_post_1', '<old/>' . Sitemaps::FORMAT_MARK );
		touch( FileCache::dir() . '/sub_pt_post_1.cache', time() - (int) ( 1.1 * 86400 ) - 60 ); // Past the TTL plus its most jitter.

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 1 );

		self::assertStringContainsString( '/p/101/', $r['body'] );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
	}

	// --- Invalidation -----------------------------------------------------

	public function test_editing_a_post_stales_only_its_page(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$post = $this->post( 103 ); // Page 2.

		$sm = $this->sitemaps();
		$sm->onPostSaved( 103, $post, true, clone $post );
		$sm->flushInvalidations();

		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
		self::assertTrue( $this->isStale( 'sub_pt_post_2' ) );
		self::assertTrue( $this->isFresh( 'sub_pt_post_3' ) );
		self::assertTrue( $this->isStale( 'index' ) );
		self::assertArrayHasKey( 'sub_pt_post_2', $this->store[ Sitemaps::LASTMOD_OPTION ] );
		self::assertArrayNotHasKey( 'sub_pt_post_1', $this->store[ Sitemaps::LASTMOD_OPTION ] );
		self::assertSame( [ 'flushInvalidations' ], $this->shutdown, 'one flush per request' );
	}

	public function test_publishing_a_new_post_stales_only_the_last_page(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		FileCache::put( 'sub_extra_1', '<home/>' );
		$this->db->published[106] = '2026-01-09 00:00:00';
		$after                    = $this->post( 106 );
		$before                   = clone $after;
		$before->post_status      = 'draft';

		$sm = $this->sitemaps();
		$sm->onPostSaved( 106, $after, true, $before );
		$sm->flushInvalidations();

		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
		self::assertTrue( $this->isFresh( 'sub_pt_post_2' ) );
		self::assertTrue( $this->isStale( 'sub_pt_post_3' ) );
		self::assertTrue( $this->isStale( 'sub_extra_1' ), 'the home entry lists the newest posts' );
	}

	public function test_backdated_publish_stales_from_its_page_onward(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$this->db->published[100] = '2025-12-31 00:00:00';

		$sm = $this->sitemaps();
		$sm->onPostSaved( 100, $this->post( 100 ), false, null );
		$sm->flushInvalidations();

		foreach ( [ 1, 2, 3 ] as $page ) {
			self::assertTrue( $this->isStale( "sub_pt_post_{$page}" ), "page {$page}" );
		}
	}

	public function test_unpublishing_stales_from_its_page_onward(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$before = $this->post( 103 );
		unset( $this->db->published[103] );
		$after              = clone $before;
		$after->post_status = 'draft';

		$sm = $this->sitemaps();
		$sm->onPostSaved( 103, $after, true, $before );
		$sm->flushInvalidations();

		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
		self::assertTrue( $this->isStale( 'sub_pt_post_2' ) );
		self::assertTrue( $this->isStale( 'sub_pt_post_3' ) );
	}

	public function test_deleting_a_published_post_stales_from_its_page_onward(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$post = $this->post( 104 );
		unset( $this->db->published[104] );

		$sm = $this->sitemaps();
		$sm->onPostDeleted( 104, $post );
		$sm->flushInvalidations();

		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
		self::assertTrue( $this->isStale( 'sub_pt_post_2' ) );
		self::assertTrue( $this->isStale( 'sub_pt_post_3' ) );
	}

	public function test_drafts_and_revisions_touch_nothing(): void {
		$this->seed( 3 );
		$this->cachePages( 2 );
		$draft              = $this->post( 101 );
		$draft->post_status = 'draft';
		$revision           = $this->post( 102 );
		$revision->post_type = 'revision';

		$sm = $this->sitemaps();
		$sm->onPostSaved( 101, $draft, true, clone $draft );
		$sm->onPostSaved( 102, $revision, false, null );

		self::assertSame( [], $this->shutdown );
		self::assertSame( [], $this->db->queries );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
		self::assertTrue( $this->isFresh( 'index' ) );
	}

	public function test_a_bulk_edit_collapses_into_one_shift_from_the_earliest_post(): void {
		$this->seed( 30 ); // 15 pages.
		$this->cachePages( 15 );

		$sm = $this->sitemaps();
		foreach ( range( 110, 130 ) as $id ) { // 21 in-place edits (over the 20 cap), earliest on page 5.
			$post = $this->post( $id );
			$sm->onPostSaved( $id, $post, true, clone $post );
		}
		$sm->flushInvalidations();

		foreach ( range( 1, 4 ) as $page ) {
			self::assertTrue( $this->isFresh( "sub_pt_post_{$page}" ), "page {$page}" );
		}
		foreach ( range( 5, 15 ) as $page ) {
			self::assertTrue( $this->isStale( "sub_pt_post_{$page}" ), "page {$page}" );
		}
		self::assertLessThanOrEqual( 3, count( $this->db->ran( 'SELECT COUNT(*)' ) ), 'one rank (2 counts) + one total, not 21 ranks' );
	}

	public function test_llms_refresh_no_longer_wipes_the_sitemap_cache(): void {
		FileCache::put( 'llms', 'body' );
		FileCache::put( 'sub_pt_post_1', '<keep/>' );

		LlmsTxt::forgetCachedBody();

		self::assertSame( [ null, false ], FileCache::lookup( 'llms', 0 ) );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
	}

	// --- F1: the main query ----------------------------------------------

	public function test_f1_sitemap_and_llms_requests_skip_the_main_query(): void {
		$sm = $this->sitemaps();
		$sm->register();
		self::assertContains( [ $sm, 'skipMainQuery' ], $this->filters['posts_pre_query'] ?? [] );

		foreach ( [ 'heirloom_sitemap' => [ $sm, 'skipMainQuery' ], 'heirloom_llms' => [ LlmsTxt::class, 'skipMainQuery' ] ] as $var => $skip ) {
			$main                    = new WP_Query();
			$main->main              = true;
			$main->found_posts       = 99;
			$main->query_vars[ $var ] = 'index';
			self::assertSame( [], $skip( null, $main ), $var );
			self::assertSame( 0, $main->found_posts, $var );
			self::assertTrue( $main->get( 'ignore_sticky_posts' ), "{$var}: nor the sticky-post fetch (round 2, nit)" );

			$secondary                    = new WP_Query();
			$secondary->query_vars[ $var ] = 'index';
			self::assertNull( $skip( null, $secondary ), "{$var}: secondary queries run" );

			$home       = new WP_Query();
			$home->main = true;
			self::assertNull( $skip( null, $home ), "{$var}: other main queries run" );
			self::assertSame( [ 7 ], $skip( [ 7 ], $main ), "{$var}: another filter's answer stands" );

			$junk                    = new WP_Query();
			$junk->main              = true;
			$junk->query_vars[ $var ] = 'bogus';
			self::assertNull( $skip( null, $junk ), "{$var}: a value nothing serves leaves the page alone (round 2, nit)" );
		}
	}

	public function test_register_wires_every_invalidation_hook(): void {
		$sm = $this->sitemaps();
		$sm->register();

		$expected = [
			'wp_after_insert_post'  => 'onPostSaved',
			'before_delete_post'    => 'onPostDeleting',
			'deleted_post'          => 'onPostDeleted',
			'added_post_meta'       => 'onPostMetaChanged',
			'updated_post_meta'     => 'onPostMetaChanged',
			'deleted_post_meta'     => 'onPostMetaChanged',
			'delete_post_meta'      => 'onPostMetaDeleting',
			'created_term'          => 'onTermChanged',
			'edited_term'           => 'onPermalinkTermChanged',
			'delete_term'           => 'onPermalinkTermChanged',
			'edit_terms'            => 'rememberTerm',
			'update_option_permalink_structure' => 'onPermalinksChanged',
			'update_option_category_base'       => 'onPermalinksChanged',
			'update_option_tag_base'            => 'onPermalinksChanged',
			'deleted_user'          => 'onUserDeleted',
			'remove_user_from_blog' => 'onUserRemoved',
		];
		foreach ( $expected as $hook => $method ) {
			self::assertContains( [ $sm, $method ], $this->filters[ $hook ] ?? [], "{$hook} → {$method}" );
		}
		self::assertContains( [ $sm, 'onTermChanged' ], $this->filters['edited_term'] );
		self::assertContains( [ $sm, 'onTermChanged' ], $this->filters['delete_term'] );
	}

	// --- F2: lastmod follows content -------------------------------------

	public function test_f2_a_rebuild_that_changes_the_page_moves_its_lastmod_past_earlier_fetches(): void {
		$this->seed( 5 );
		$this->store[ Sitemaps::LASTMOD_OPTION ] = [ 'sub_pt_post_2' => '2026-01-04T00:00:00+00:00' ];
		FileCache::put( 'sub_pt_post_2', '<served-to-a-crawler-before-a-change-it-missed/>' );
		FileCache::markStale( 'sub_pt_post_2' );
		$t0 = gmdate( 'c' );

		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );

		self::assertGreaterThanOrEqual( $t0, $this->lastmod( 'sub_pt_post_2' ) );
	}

	public function test_f2_an_identical_rebuild_or_a_rebuild_after_a_purge_keeps_the_lastmod(): void {
		$this->seed( 5 );
		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );
		$later                                                   = '2026-09-30T12:00:00+00:00'; // Later than any post_modified on the page.
		$this->store[ Sitemaps::LASTMOD_OPTION ]['sub_pt_post_2'] = $later;

		FileCache::markStale( 'sub_pt_post_2' );
		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );
		self::assertSame( $later, $this->lastmod( 'sub_pt_post_2' ), 'same bytes: no change to advertise, and an older stamp never replaces a newer one' );

		FileCache::purge();
		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );
		self::assertSame( $later, $this->lastmod( 'sub_pt_post_2' ), 'a purge alone does not re-advertise every page' );

		FileCache::forget( 'sub_pt_post_2' ); // No copy at all to compare with: can't tell, so keep.
		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );
		self::assertSame( $later, $this->lastmod( 'sub_pt_post_2' ) );
	}

	public function test_a_copy_inside_its_ttl_is_served_without_a_build(): void {
		$this->seed( 2 );
		FileCache::put( 'sub_pt_post_1', '<cached/>' . Sitemaps::FORMAT_MARK );
		touch( FileCache::dir() . '/sub_pt_post_1.cache', time() - 86400 + 60 ); // Inside the day (jitter only lengthens it).

		self::assertSame( '<cached/>' . Sitemaps::FORMAT_MARK, $this->sitemaps()->respond( 'sub', 'pt_post', 1 )['body'] );
	}

	public function test_r2_6_a_change_picked_up_by_a_rebuild_after_a_purge_is_advertised(): void {
		$this->seed( 5 );
		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );
		$this->store[ Sitemaps::LASTMOD_OPTION ]['sub_pt_post_2'] = '2026-01-05T00:00:00+00:00';
		$this->thumbs[103] = 900; // A change made without hooks (direct SQL), then Tools → Flush.
		$t0                = gmdate( 'c' );

		FileCache::purge();
		self::assertTrue( $this->isStale( 'sub_pt_post_2' ), 'the purge keeps the page as a stale copy' );
		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );

		self::assertGreaterThanOrEqual( $t0, $this->lastmod( 'sub_pt_post_2' ) );
	}

	// --- F3: news date bound ---------------------------------------------

	public function test_f3_news_queries_bound_post_date_so_the_index_range_applies(): void {
		$this->seed( 2 );
		$this->store[ Options::OPTION ]['schema'] = [ 'news_category' => 'news' ];

		$this->sitemaps()->respond( 'news', '', 0 );
		$this->sitemaps()->respond( 'index', '', 0 );

		self::assertCount( 2, WP_Query::$built, 'the news sitemap and the index both query' );
		foreach ( WP_Query::$built as $args ) {
			self::assertSame( 'post_date', $args['date_query'][0]['column'] );
			self::assertSame( [ 'column' => 'post_date_gmt', 'inclusive' => true ], array_diff_key( $args['date_query'][1], [ 'after' => 1 ] ) );
		}
	}

	/**
	 * 0.7.23: WordPress reads a phrase like "48 hours ago" in the site's time
	 * zone but compared it with post_date_gmt, so the News window was 48 hours
	 * plus or minus the site's UTC offset (52 at UTC−4, 39 at UTC+9). The
	 * cutoff is now an exact UTC moment, given as date parts, which WordPress
	 * uses as written.
	 */
	public function test_news_window_is_exactly_48_hours_in_utc_whatever_the_time_zone(): void {
		$this->seed( 2 );
		$this->store[ Options::OPTION ]['schema'] = [ 'news_category' => 'news' ];
		$this->store['timezone_string']           = 'America/New_York';
		$zone                                    = date_default_timezone_get();
		date_default_timezone_set( 'Asia/Tokyo' ); // Nothing may depend on PHP's own zone either.
		try {
			$t0 = time();
			$this->sitemaps()->respond( 'news', '', 0 );
			$t1 = time();
		} finally {
			date_default_timezone_set( $zone );
		}

		[ $loose, $exact ] = WP_Query::$built[0]['date_query'];
		$moment            = static fn( array $p ): int => gmmktime( $p['hour'], $p['minute'], $p['second'], $p['month'], $p['day'], $p['year'] );
		self::assertIsArray( $exact['after'], 'date parts, not a phrase WordPress would read in the site time zone' );
		self::assertSame( [ 'year', 'month', 'day', 'hour', 'minute', 'second' ], array_keys( $exact['after'] ) );
		self::assertGreaterThanOrEqual( $t0 - 48 * 3600, $moment( $exact['after'] ) );
		self::assertLessThanOrEqual( $t1 - 48 * 3600, $moment( $exact['after'] ) );
		self::assertSame( 14 * 3600, $moment( $exact['after'] ) - $moment( $loose['after'] ), 'the indexed post_date bound sits 14 hours earlier, covering any UTC offset' );
	}

	// --- F4 / round 2 #5, #14: lastmod map merges -----------------------

	public function test_f4_a_flush_keeps_stamps_written_by_another_request_meanwhile(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$this->store[ Sitemaps::LASTMOD_OPTION ] = [ 'sub_pt_post_1' => '2026-01-02T00:00:00+00:00' ];
		$post                                    = $this->post( 103 );
		$sm                                      = $this->sitemaps();
		$sm->onPostSaved( 103, $post, true, clone $post );

		// While this flush ranks its post, another request stamps page 3.
		$this->db->filter = function ( string $sql ): string {
			if ( str_contains( $sql, 'SELECT COUNT(*)' ) ) {
				$this->store[ Sitemaps::LASTMOD_OPTION ]['sub_pt_post_3'] = '2026-09-30T12:00:00+00:00';
			}
			return $this->runFilters( 'query', $sql );
		};
		$sm->flushInvalidations();

		self::assertSame( '2026-09-30T12:00:00+00:00', $this->lastmod( 'sub_pt_post_3' ), 'the other request\'s stamp survives' );
		self::assertArrayHasKey( 'sub_pt_post_2', $this->store[ Sitemaps::LASTMOD_OPTION ] );
		self::assertContains( Sitemaps::LASTMOD_OPTION, $this->cacheDeletes, 're-read past the object cache' );
		self::assertCount( 1, $this->db->ran( 'GET_LOCK(\'heirloom_sm_' . substr( md5( 'testdb|wp_' ), 0, 20 ) . "_lastmod', 2)" ), 'round 2 #14: merges take turns on their own lock, waiting up to 2 s' );
		self::assertSame( [], $this->db->held );
	}

	public function test_r2_5_a_failed_read_of_the_lastmod_map_writes_nothing(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$map                                     = [ '_all' => '2026-09-01T00:00:00+00:00', 'sub_pt_post_1' => '2026-09-02T00:00:00+00:00' ];
		$this->store[ Sitemaps::LASTMOD_OPTION ] = $map;
		$this->notoptions                        = [ Sitemaps::LASTMOD_OPTION => true ]; // Core cached "no such option" earlier.
		$post                                    = $this->post( 103 );

		$sm = $this->sitemaps();
		$sm->onPostSaved( 103, $post, true, clone $post );
		$this->failing['lastmods'] = true;
		$sm->flushInvalidations();

		self::assertSame( $map, $this->store[ Sitemaps::LASTMOD_OPTION ], 'not replaced by just this flush\'s stamp' );
		self::assertArrayNotHasKey( Sitemaps::LASTMOD_OPTION, $this->notoptions, 'the cached "missing" answer is dropped before the read' );
		self::assertTrue( $this->isStale( 'sub_pt_post_2' ), 'the page itself still goes stale' );
	}

	// --- F5: changes outside post saves ----------------------------------

	public function test_f5_noindex_set_outside_a_save_stales_only_that_posts_page(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );

		$sm = $this->sitemaps();
		$sm->onPostMetaChanged( 1, 103, '_heirloom_seo_noindex' );
		$sm->onPostMetaChanged( 2, 103, '_edit_lock' );
		$sm->flushInvalidations();

		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
		self::assertTrue( $this->isStale( 'sub_pt_post_2' ) );
		self::assertTrue( $this->isFresh( 'sub_pt_post_3' ) );
	}

	public function test_f5_unwatched_meta_and_thumbnails_without_images_touch_nothing(): void {
		$this->seed( 3 );
		$this->store[ Options::OPTION ]['sitemaps']['images'] = false;

		$sm = $this->sitemaps();
		$sm->onPostMetaChanged( 1, 102, '_edit_lock' );
		$sm->onPostMetaChanged( 2, 102, '_thumbnail_id' );
		$sm->onPostMetaDeleting( [ 3 ], 0, '_thumbnail_id' );

		self::assertSame( [], $this->shutdown );
		self::assertSame( [], $this->db->queries );
	}

	public function test_r2_3_deleting_a_featured_image_stales_only_the_pages_of_the_posts_using_it(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$this->db->metaRows = [ 31 => 103 ]; // Post 103's _thumbnail_id row points at the image.

		$sm = $this->sitemaps();
		$sm->onPostMetaDeleting( [ 31 ], 0, '_thumbnail_id' ); // wp_delete_attachment(): a delete across posts, rows still there.
		$sm->onPostMetaChanged( [ 31 ], 0, '_thumbnail_id' ); // …and after it.
		$sm->flushInvalidations();

		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
		self::assertTrue( $this->isStale( 'sub_pt_post_2' ) );
		self::assertTrue( $this->isFresh( 'sub_pt_post_3' ) );
		self::assertSame( [ 'sub_pt_post_2' ], array_keys( $this->store[ Sitemaps::LASTMOD_OPTION ] ) );
	}

	public function test_f5_a_meta_key_deleted_from_many_posts_stales_every_page_of_every_type(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		FileCache::put( 'sub_pt_page_1', '<pages/>' );
		Functions\when( 'get_post_types' )->justReturn( [ 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ] );

		$sm = $this->sitemaps();
		$sm->onPostMetaDeleting( range( 1, 21 ), 0, '_heirloom_seo_noindex' ); // delete_post_meta_by_key().
		$sm->flushInvalidations();

		foreach ( [ 'sub_pt_post_1', 'sub_pt_post_2', 'sub_pt_post_3', 'sub_pt_page_1' ] as $key ) {
			self::assertTrue( $this->isStale( $key ), $key );
		}
		self::assertSame( [], $this->db->ran( 'FROM wp_postmeta WHERE meta_id' ), 'no lookup for a large delete' );
	}

	public function test_f5_a_permalink_change_stales_every_sitemap_and_raises_the_floor(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		FileCache::put( 'sub_extra_1', '<home/>' );
		$t0 = gmdate( 'c' );

		$sm = $this->sitemaps();
		$sm->onPermalinksChanged();
		$sm->flushInvalidations();

		foreach ( [ 'sub_pt_post_1', 'sub_pt_post_2', 'sub_pt_post_3', 'sub_extra_1', 'index' ] as $key ) {
			self::assertTrue( $this->isStale( $key ), $key );
		}
		self::assertGreaterThanOrEqual( $t0, $this->lastmod( '_all' ) );
	}

	public function test_f5_renaming_or_moving_a_term_used_in_permalinks_stales_every_post_page(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$this->store['permalink_structure'] = '/%category%/%postname%/';
		$this->terms[7]                     = (object) [ 'slug' => 'politics', 'parent' => 0 ];

		$sm = $this->sitemaps();
		$sm->rememberTerm( 7, 'category' );
		$this->terms[7] = (object) [ 'slug' => 'politics', 'parent' => 0 ]; // Description-only edit.
		$sm->onPermalinkTermChanged( 7, 70, 'category' );
		self::assertSame( [], $this->shutdown, 'same slug and parent: no URL changed' );

		foreach ( [ [ 'world-politics', 0 ], [ 'world-politics', 3 ] ] as [ $slug, $parent ] ) { // Renamed, then moved.
			$this->cachePages( 3 );
			$sm->rememberTerm( 7, 'category' );
			$this->terms[7] = (object) [ 'slug' => $slug, 'parent' => $parent ];
			$sm->onPermalinkTermChanged( 7, 70, 'category' );
			$sm->flushInvalidations();
			foreach ( [ 1, 2, 3 ] as $page ) {
				self::assertTrue( $this->isStale( "sub_pt_post_{$page}" ), "{$slug}/{$parent}: page {$page}" );
			}
		}
	}

	public function test_f5_terms_outside_the_permalink_structure_are_not_looked_up(): void {
		$lookups = 0;
		Functions\when( 'get_term' )->alias(
			function () use ( &$lookups ) {
				++$lookups;
				return null;
			}
		);

		$sm = $this->sitemaps();
		$sm->rememberTerm( 7, 'category' ); // Structure is /%year%/%monthnum%/%postname%/.
		$sm->onPermalinkTermChanged( 7, 70, 'category' );

		self::assertSame( 0, $lookups );
		self::assertSame( [], $this->shutdown );
	}

	public function test_f5_r2_24_a_parent_with_children_stales_every_page_of_its_type(): void {
		$this->seed( 5 );
		$this->hierarchical = true;
		$this->db->parents  = [ 106 => 105 ];
		$before             = $this->post( 105 );
		$before->post_name  = 'about';

		$cases = [
			'renamed'   => static fn( WP_Post $p ) => $p->post_name = 'about-us',
			'moved'     => static fn( WP_Post $p ) => $p->post_parent = 9,
			'trashed'   => static fn( WP_Post $p ) => [ $p->post_status = 'trash', $p->post_name = 'about__trashed' ],
		];
		foreach ( $cases as $label => $change ) {
			$this->cachePages( 3 );
			$after = clone $before;
			$change( $after );
			$sm = $this->sitemaps();
			$sm->onPostSaved( 105, $after, true, $before );
			$sm->flushInvalidations();
			foreach ( [ 1, 2, 3 ] as $page ) {
				self::assertTrue( $this->isStale( "sub_pt_post_{$page}" ), "{$label}: page {$page}" );
			}
		}

		// Restored from the trash: neither copy is in the sitemap, but the children's URLs change back.
		$this->cachePages( 3 );
		$trashed              = clone $before;
		$trashed->post_status = 'trash';
		$trashed->post_name   = 'about__trashed';
		$restored             = clone $before;
		$restored->post_status = 'draft';
		$sm                   = $this->sitemaps();
		$sm->onPostSaved( 105, $restored, true, $trashed );
		$sm->flushInvalidations();
		self::assertTrue( $this->isStale( 'sub_pt_post_1' ), 'untrash' );

		// Emptying the trash moves the children up by direct SQL: caught before the delete.
		$this->cachePages( 3 );
		$sm = $this->sitemaps();
		$sm->onPostDeleting( 105, $trashed );
		$sm->flushInvalidations();
		self::assertTrue( $this->isStale( 'sub_pt_post_1' ), 'deleted for good' );
	}

	public function test_r2_24_a_childless_post_renamed_stales_only_its_own_page(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$this->hierarchical = true;
		$before             = $this->post( 103 );
		$after              = clone $before;
		$after->post_name   = 'renamed';

		$sm = $this->sitemaps();
		$sm->onPostSaved( 103, $after, true, $before );
		$sm->onPostDeleting( 104, $this->post( 104 ) );
		$sm->flushInvalidations();

		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
		self::assertTrue( $this->isStale( 'sub_pt_post_2' ) );
		self::assertTrue( $this->isFresh( 'sub_pt_post_3' ) );
	}

	public function test_f5_deleting_a_user_with_reassignment_stales_author_pages(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		FileCache::put( 'sub_author_1', '<authors/>' );

		$sm = $this->sitemaps();
		$sm->onUserDeleted( 7, null ); // Posts deleted with the user: their own hooks cover it.
		self::assertSame( [], $this->shutdown );

		$sm->onUserDeleted( 7, 8 );
		$sm->flushInvalidations();
		self::assertTrue( $this->isStale( 'sub_author_1' ) );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ), 'post URLs have no %author%' );

		$this->store['permalink_structure'] = '/%author%/%postname%/';
		FileCache::put( 'sub_pt_post_1', '<page1/>' );
		$sm->onUserDeleted( 9, 8 );
		$sm->flushInvalidations();
		self::assertTrue( $this->isStale( 'sub_pt_post_1' ) );

		FileCache::put( 'sub_author_1', '<authors/>' );
		$sm->onUserRemoved( 10, 1, 0 ); // Multisite: removed from this site, no reassignment.
		$sm->flushInvalidations();
		self::assertTrue( $this->isStale( 'sub_author_1' ), 'round 2 #28' );
	}

	public function test_r2_17_28_changes_on_another_site_stale_that_sites_sitemaps_right_away(): void {
		$this->seed( 3 );
		$post = $this->post( 102 );

		// Switched to this same site (as remove_user_from_blog() always does): it's home.
		$this->switched                 = true;
		$GLOBALS['_wp_switched_stack'] = [ 1 ];
		$sm                             = $this->sitemaps();
		$sm->onPostMetaChanged( 1, 102, '_heirloom_seo_noindex' );
		self::assertSame( [ 'flushInvalidations' ], $this->shutdown );

		// Switched to site 2: every hook marks site 2's sitemaps stale, once, and schedules no flush here.
		$this->blog     = 2;
		$this->shutdown = [];
		$this->cachePages( 2 );
		$calls = [
			fn( Sitemaps $s ) => $s->onPostSaved( 102, $post, true, clone $post ),
			fn( Sitemaps $s ) => $s->onPostDeleted( 102, $post ),
			fn( Sitemaps $s ) => $s->onPostMetaChanged( 1, 102, '_heirloom_seo_noindex' ),
			fn( Sitemaps $s ) => $s->onPostMetaDeleting( [ 1 ], 0, '_thumbnail_id' ),
			fn( Sitemaps $s ) => $s->onTermChanged( 5, 50, 'category' ),
			fn( Sitemaps $s ) => $s->onUserDeleted( 7, 8 ),
			fn( Sitemaps $s ) => $s->onUserRemoved( 7, 2, 8 ),
			fn( Sitemaps $s ) => $s->onPermalinksChanged(),
		];
		foreach ( $calls as $i => $call ) {
			$this->cachePages( 2 );
			$call( $this->sitemaps() );
			foreach ( [ 'index', 'news', 'sub_pt_post_1', 'sub_pt_post_2' ] as $key ) {
				self::assertTrue( $this->isStale( $key ), "hook {$i}: {$key}" );
			}
		}
		self::assertSame( [], $this->shutdown );

		$this->cachePages( 2 );
		$draft              = clone $post;
		$draft->post_status = 'draft';
		$this->sitemaps()->onPostSaved( 102, $draft, true, clone $draft );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ), 'a draft saved on another site touches nothing' );
	}

	// --- F6: lastmod floor -----------------------------------------------

	public function test_f6_the_floor_lifts_every_post_page_lastmod_in_the_index(): void {
		$this->seed( 3 );
		$this->store[ Sitemaps::LASTMOD_OPTION ] = [
			'sub_pt_post_1' => '2026-01-02T00:00:00+00:00',
			'sub_pt_post_2' => '2099-01-01T00:00:00+00:00',
		];
		FileCache::put( 'index', '<index/>' );
		Sitemaps::markAllPagesChanged();
		$floor = $this->lastmod( '_all' );
		self::assertTrue( $this->isStale( 'index' ), 'the index is rebuilt to show it' );

		$r = $this->sitemaps()->respond( 'index', '', 0 );

		self::assertSame( $floor, $this->indexLastmod( $r['body'], 'sitemap-pt_post-1.xml' ) );
		self::assertSame( '2099-01-01T00:00:00+00:00', $this->indexLastmod( $r['body'], 'sitemap-pt_post-2.xml' ), 'a later page stamp still wins' );
		self::assertSame( '2026-01-02T00:00:00+00:00', $this->lastmod( 'sub_pt_post_1' ), 'per-page stamps are kept' );
	}

	public function test_f6_r2_9_25_26_settings_writes_refresh_only_what_changed(): void {
		$this->seed( 3 );
		$defaults = ( new Options() )->defaults();
		$fresh    = function (): void {
			$this->cachePages( 2 );
			FileCache::put( 'llms', 'body' );
			unset( $this->store[ Sitemaps::LASTMOD_OPTION ] );
		};
		$with     = static function ( array $over ) use ( $defaults ): array {
			return array_replace_recursive( $defaults, $over );
		};

		$fresh();
		SettingsPage::onSettingsWritten( [], [ 'titles' => [ 'home' => 'Home' ] ] );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ), 'unrelated setting: cache kept' );
		self::assertTrue( $this->isFresh( 'llms' ) );

		$fresh();
		SettingsPage::onSettingsWritten( [], [ 'sitemaps' => $defaults['sitemaps'], 'schema' => [ 'news_term' => $defaults['schema']['news_term'] ] ] );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ), 'first storing the defaults is no change (round 2 #9/#26)' );
		self::assertArrayNotHasKey( Sitemaps::LASTMOD_OPTION, $this->store );

		$fresh();
		SettingsPage::onSettingsWritten( $defaults, $with( [ 'ai' => [ 'llms_intro' => 'Hello' ] ] ) );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ), 'llms.txt setting: sitemaps kept' );
		self::assertSame( [ null, false ], FileCache::lookup( 'llms', 0 ) );

		$fresh();
		SettingsPage::onSettingsWritten( $defaults, $with( [ 'sitemaps' => [ 'authors' => true ] ] ) );
		self::assertTrue( $this->isStale( 'sub_pt_post_1' ), 'a sitemap setting: every sitemap rebuilds (stale copies kept)' );
		self::assertArrayNotHasKey( Sitemaps::LASTMOD_OPTION, $this->store, "post pages' contents didn't change" );

		// 0.7.24: the News rules decide only the News sitemap and whether the index lists it.
		$news_changes = [
			[ 'schema' => [ 'news_tag' => 'breaking' ] ],
			[ 'sitemaps' => [ 'news_exclude_tags' => [ 'sponsored' ] ] ],
			[ 'sitemaps' => [ 'news_exclude_categories' => [ 'opinion' ] ] ],
			[ 'sitemaps' => [ 'news_exclude_authors' => [ 'guest-writer' ] ] ],
		];
		foreach ( $news_changes as $change ) {
			$fresh();
			SettingsPage::onSettingsWritten( $defaults, $with( $change + [ 'ai' => [ 'llms_intro' => 'Changed too' ] ] ) );
			$label = (string) json_encode( $change );
			self::assertTrue( $this->isStale( 'news' ), $label );
			self::assertTrue( $this->isStale( 'index' ), $label );
			self::assertTrue( $this->isFresh( 'sub_pt_post_1' ), "{$label}: post pages untouched" );
			self::assertSame( [ null, false ], FileCache::lookup( 'llms', 0 ), "{$label}: an llms.txt change alongside still counts" );
		}

		foreach ( [ [ 'per_page' => 3 ], [ 'images' => false ] ] as $change ) {
			$fresh();
			$t0 = gmdate( 'c' );
			SettingsPage::onSettingsWritten( $defaults, $with( [ 'sitemaps' => $change ] ) );
			self::assertTrue( $this->isStale( 'sub_pt_post_1' ), key( $change ) );
			self::assertGreaterThanOrEqual( $t0, $this->lastmod( '_all' ), key( $change ) . ' changed every page' );
		}
	}

	// --- F7: warm-up and narrower invalidation ---------------------------

	public function test_f7_regenerate_builds_every_sitemap_the_index_last_and_drops_orphans(): void {
		$this->seed( 5 ); // 3 pages.
		$this->cachePages( 3 );
		FileCache::put( 'sub_pt_post_9', '<gone/>' );
		$servable          = [];
		$this->duringPrime = function () use ( &$servable ): void {
			$servable[] = FileCache::lookup( 'sub_pt_post_3', 0 )[0];
		};

		( new Commands() )->sitemap( [ 'regenerate' ] );

		foreach ( [ 'index', 'news', 'sub_extra_1', 'sub_pt_post_1', 'sub_pt_post_2', 'sub_pt_post_3' ] as $key ) {
			self::assertTrue( $this->isFresh( $key ), $key );
		}
		self::assertSame( '<page3/>', $servable[0], 'pages waiting their turn stay servable (stale), not deleted' );
		self::assertStringContainsString( '/p/105/', (string) FileCache::get( 'sub_pt_post_3' ) );
		self::assertSame( [ null, false ], FileCache::lookup( 'sub_pt_post_9', 0 ) );
		self::assertSame( $this->lastmod( 'sub_pt_post_3' ), $this->indexLastmod( (string) FileCache::get( 'index' ), 'sitemap-pt_post-3.xml' ), 'round 2 #21: the index, built last, lists the new lastmods' );
		self::assertSame( [ 'success', 'Built 6 sitemaps.' ], end( \WP_CLI::$messages ) );
	}

	public function test_r2_4_regenerate_changes_nothing_when_a_count_keeps_failing(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$this->db->failOn = [ "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'post' AND post_status = 'publish'" ];

		try {
			( new Commands() )->sitemap( [ 'regenerate' ] );
			self::fail( 'expected an error' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'nothing was changed', $e->getMessage() );
		}
		foreach ( [ 1, 2, 3 ] as $page ) {
			self::assertTrue( $this->isFresh( "sub_pt_post_{$page}" ), "page {$page} kept" );
		}
	}

	public function test_r2_1_a_retry_after_a_failed_read_doesnt_trust_its_cached_empty_answer(): void {
		$this->seed( 5 );
		$this->primeFailsFor = 101; // Page 1's meta load fails once.

		( new Commands() )->sitemap( [ 'regenerate' ] );

		self::assertContains( 'post_meta:101,102', $this->cacheCalls, 'the failed prime\'s empty meta is forgotten' );
		self::assertSame( [ 2 ], $this->slept );
		self::assertGreaterThanOrEqual( 1 + 6, $this->runtimeFlushes, 'flushed before the retry, as well as after each sitemap' );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
	}

	public function test_r2_16_a_retry_skips_a_page_a_live_request_rebuilt_meanwhile(): void {
		$this->seed( 5 );
		$this->store['show_on_front'] = 'page'; // No extra (home) page: page 1 is the first to build.
		$site                = substr( md5( 'testdb|wp_' ), 0, 20 );
		$this->db->busyLocks = [ "heirloom_sm_{$site}_1" => true, "heirloom_sm_{$site}_2" => true ];
		$this->onSleep       = function () use ( $site ): void {
			$this->db->busyLocks = [];
			$this->sitemaps()->respond( 'sub', 'pt_post', 1 ); // A crawler's request.
			$this->db->busyLocks = [ "heirloom_sm_{$site}_1" => true, "heirloom_sm_{$site}_2" => true ];
			$this->onSleep       = fn() => $this->db->busyLocks = [];
		};

		( new Commands() )->sitemap( [ 'regenerate' ] );

		self::assertCount( 1, $this->db->ran( 'LIMIT 0, 2' ), 'page 1 built once, by the live request' );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
	}

	public function test_r2_16_regenerate_skips_a_page_a_live_request_rebuilt_before_its_turn(): void {
		$this->seed( 5 );
		$this->duringPrime = function ( array $ids ): void {
			if ( [ 101, 102 ] === $ids ) { // While the command builds page 1, a crawler fetches page 3.
				$this->duringPrime = null;
				$this->sitemaps()->respond( 'sub', 'pt_post', 3 );
			}
		};

		( new Commands() )->sitemap( [ 'regenerate' ] );

		self::assertCount( 1, $this->db->ran( 'LIMIT 4, 2' ), 'page 3 built once, by the crawler' );
		self::assertTrue( $this->isFresh( 'sub_pt_post_3' ) );
		self::assertSame( [ 'success', 'Built 6 sitemaps.' ], end( \WP_CLI::$messages ) );
	}

	public function test_critic_regenerate_reports_pages_it_could_not_write(): void {
		$this->seed( 1 );
		FileCache::ensureDir();
		chmod( FileCache::dir(), 0555 );
		try {
			( new Commands() )->sitemap( [ 'regenerate' ] );
			self::fail( 'expected an error' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( "Can't write the sitemap cache", $e->getMessage() );
		} finally {
			chmod( FileCache::dir(), 0755 );
		}

		Functions\when( 'wp_is_writable' )->justReturn( true ); // Says writable, isn't.
		chmod( FileCache::dir(), 0555 );
		try {
			( new Commands() )->sitemap( [ 'regenerate' ] );
		} finally {
			chmod( FileCache::dir(), 0755 );
		}
		self::assertSame( 'warning', \WP_CLI::$messages[ count( \WP_CLI::$messages ) - 2 ][0] );
		self::assertStringContainsString( 'sub_pt_post_1', \WP_CLI::$messages[ count( \WP_CLI::$messages ) - 2 ][1] );
		self::assertSame( [ 'success', 'Built 0 sitemaps.' ], end( \WP_CLI::$messages ) );
	}

	public function test_f7_hiding_an_author_stales_only_the_author_pages_and_index(): void {
		$this->cachePages( 2 );
		FileCache::put( 'sub_author_1', '<authors/>' );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'update_user_meta' )->justReturn( true );
		$_POST = [ 'heirloom_seo_author_fields_nonce' => 'x', Authors::META => '1' ];

		try {
			( new AuthorFields() )->save( 5 );
		} finally {
			$_POST = [];
		}

		self::assertTrue( $this->isStale( 'sub_author_1' ) );
		self::assertTrue( $this->isStale( 'index' ) );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ) );
	}

	// --- F8: own slots for the index and news ----------------------------

	public function test_f8_the_index_builds_while_page_builds_fill_the_shared_slots(): void {
		$this->seed( 3 );
		$site                = substr( md5( 'testdb|wp_' ), 0, 20 );
		$this->db->busyLocks = [ "heirloom_sm_{$site}_1" => true, "heirloom_sm_{$site}_2" => true ];

		self::assertSame( 503, $this->sitemaps()->respond( 'sub', 'pt_post', 1 )['status'] );
		self::assertSame( 200, $this->sitemaps()->respond( 'index', '', 0 )['status'] );
		self::assertSame( 200, $this->sitemaps()->respond( 'news', '', 0 )['status'] );
		self::assertTrue( $this->isFresh( 'index' ) );
	}

	// --- F9: long-running imports ----------------------------------------

	public function test_f9_after_the_collapse_further_edits_feed_the_shift(): void {
		$this->seed( 30 ); // 15 pages.
		$this->cachePages( 15 );

		$sm = $this->sitemaps();
		foreach ( range( 110, 130 ) as $id ) { // Collapses at the 21st, from page 5.
			$post = $this->post( $id );
			$sm->onPostSaved( $id, $post, true, clone $post );
		}
		$post = $this->post( 105 ); // Page 3, after the collapse.
		$sm->onPostSaved( 105, $post, true, clone $post );
		$sm->flushInvalidations();

		self::assertTrue( $this->isFresh( 'sub_pt_post_2' ) );
		foreach ( range( 3, 15 ) as $page ) {
			self::assertTrue( $this->isStale( "sub_pt_post_{$page}" ), "page {$page}" );
		}
	}

	// --- F10: ranking many edits -----------------------------------------

	public function test_f10_twenty_recent_edits_read_the_index_about_once(): void {
		$this->seedMany( 2000, 3 ); // Dates repeat: ties on (post_date, ID).
		$this->store[ Options::OPTION ]['sitemaps']['per_page'] = 100;
		$newest = array_slice( $this->db->ordered(), -20 );

		$sm = $this->sitemaps();
		foreach ( $newest as $id ) {
			$post = $this->post( $id );
			$sm->onPostSaved( $id, $post, true, clone $post );
		}
		$this->db->scanned = 0;
		$sm->flushInvalidations();

		self::assertLessThan( 2 * 2000, $this->db->scanned, 'one pass, not one near-full count per post' );
		self::assertSame( [ 'sub_pt_post_20' ], array_keys( $this->store[ Sitemaps::LASTMOD_OPTION ] ) );
	}

	public function test_f10_scattered_edits_with_ties_stamp_exactly_their_pages(): void {
		$this->seedMany( 60, 4 );
		$this->store[ Options::OPTION ]['sitemaps']['per_page'] = 7;
		$order = $this->db->ordered();
		$picks = [ 35, 0, 59, 13, 7, 48, 6, 20, 14, 55, 29, 34, 7 ]; // Out of order: page edges, ties, one post twice.

		$sm = $this->sitemaps();
		foreach ( $picks as $rank ) {
			$post = $this->post( $order[ $rank ] );
			$sm->onPostSaved( $post->ID, $post, true, clone $post );
		}
		$sm->flushInvalidations();

		$expected = array_values( array_unique( array_map( static fn( int $rank ): string => 'sub_pt_post_' . ( intdiv( $rank, 7 ) + 1 ), $picks ) ) );
		$stamped  = array_keys( $this->store[ Sitemaps::LASTMOD_OPTION ] );
		sort( $expected );
		sort( $stamped );
		self::assertSame( $expected, $stamped );
	}

	// --- Round 2: flush, index, cache-format and cost fixes ---------------

	public function test_r2_12_a_rebuild_that_raises_a_lastmod_stales_the_index(): void {
		$this->seed( 5 );
		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );
		FileCache::put( 'index', '<index/>' . Sitemaps::FORMAT_MARK );
		FileCache::put( 'sub_pt_post_2', '<served-before-a-missed-change/>' );
		FileCache::markStale( 'sub_pt_post_2' );
		$this->store[ Sitemaps::LASTMOD_OPTION ]['sub_pt_post_2'] = '2026-01-05T00:00:00+00:00';

		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );
		self::assertTrue( $this->isStale( 'index' ), 'raised: the index must show it' );

		FileCache::put( 'index', '<index/>' . Sitemaps::FORMAT_MARK );
		FileCache::markStale( 'sub_pt_post_2' );
		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );
		self::assertTrue( $this->isFresh( 'index' ), 'same bytes, same stamp: the index stands' );
	}

	public function test_r2_12_a_stamp_landing_while_the_index_builds_leaves_it_stale(): void {
		$this->seed( 3 );
		$this->store[ Options::OPTION ]['schema'] = [ 'news_category' => 'news' ];
		WP_Query::$onConstruct                   = function (): void { // Another request stamps a page mid-build.
			$this->store[ Sitemaps::LASTMOD_OPTION ]['sub_pt_post_1'] = gmdate( 'c' );
		};

		$this->sitemaps()->respond( 'index', '', 0 );

		self::assertTrue( $this->isStale( 'index' ) );
	}

	public function test_r2_13_a_failed_count_in_a_flush_places_no_page_and_prunes_nothing(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$map                                     = [ 'sub_pt_post_1' => '2026-09-01T00:00:00+00:00', 'sub_pt_post_3' => '2026-09-03T00:00:00+00:00' ];
		$this->store[ Sitemaps::LASTMOD_OPTION ] = $map;
		$this->db->failOn                        = [ 'SELECT COUNT(*)' ];

		$sm   = $this->sitemaps();
		$post = $this->post( 104 ); // Page 2, edited in place.
		$sm->onPostSaved( 104, $post, true, clone $post );
		$sm->flushInvalidations();
		foreach ( [ 1, 2, 3 ] as $page ) {
			self::assertTrue( $this->isStale( "sub_pt_post_{$page}" ), "edit: page {$page} (its page is unknown)" );
		}
		self::assertSame( $map, $this->store[ Sitemaps::LASTMOD_OPTION ], 'no guessed stamp' );

		$this->cachePages( 3 );
		$new                   = $this->post( 106 );
		$draft                 = clone $new;
		$draft->post_status    = 'draft';
		$this->db->published[106] = '2026-01-09 00:00:00';
		$sm->onPostSaved( 106, $new, true, $draft );
		$sm->flushInvalidations();
		self::assertTrue( $this->isStale( 'sub_pt_post_1' ), 'publish: everything rebuilds' );
		$pages = array_filter( $this->store[ Sitemaps::LASTMOD_OPTION ], static fn( string $key ): bool => str_starts_with( $key, 'sub_pt_' ), ARRAY_FILTER_USE_KEY );
		self::assertSame( $map, $pages, 'a failed count stamps and prunes no post page' );
	}

	public function test_r2_15_a_copy_written_by_older_code_is_never_served_as_fresh(): void {
		$this->seed( 3 );
		FileCache::put( 'sub_pt_post_1', '<urlset><!-- an old build finishing after the update --></urlset>' );

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 1 );

		self::assertStringContainsString( '/p/101/', $r['body'] );
		self::assertStringEndsWith( Sitemaps::FORMAT_MARK, (string) FileCache::get( 'sub_pt_post_1' ) );

		$site                = substr( md5( 'testdb|wp_' ), 0, 20 );
		$this->db->busyLocks = [ "heirloom_sm_{$site}_1" => true, "heirloom_sm_{$site}_2" => true ];
		FileCache::put( 'sub_pt_post_2', '<old/>' );
		self::assertSame( [ 'status' => 200, 'body' => '<old/>', 'max_age' => 60 ], $this->sitemaps()->respond( 'sub', 'pt_post', 2 ), 'still the fallback while nothing can be built' );
	}

	public function test_r2_18_put_keeps_the_stale_copy_and_lookup_prefers_the_fresh_one(): void {
		FileCache::put( 'sub_pt_post_1', '<old/>' );
		FileCache::markStale( 'sub_pt_post_1' );

		self::assertTrue( FileCache::put( 'sub_pt_post_1', '<new/>' ) );

		self::assertSame( [ '<new/>', true ], FileCache::lookup( 'sub_pt_post_1', 0 ) );
		self::assertFileExists( FileCache::dir() . '/sub_pt_post_1.stale', 'not deleted: a concurrent markStale() could have renamed the new copy there' );
		FileCache::markStale( 'sub_pt_post_1' );
		self::assertSame( [ '<new/>', false ], FileCache::lookup( 'sub_pt_post_1', 0 ), 'markStale replaces the older stale copy' );
	}

	public function test_r2_19_junk_page_numbers_are_404_and_never_wrap_onto_a_real_page(): void {
		$this->seed( 3 );
		$this->store[ Options::OPTION ]['sitemaps']['per_page'] = 1000;

		foreach ( [ PHP_INT_MAX, 18446744073709552, 9300000000000000 ] as $page ) { // Offsets past PHP_INT_MAX.
			self::assertSame( 404, $this->sitemaps()->respond( 'sub', 'pt_post', $page )['status'], (string) $page );
		}
		self::assertSame( [], $this->db->ran( 'SELECT ID FROM' ), 'no query: wpdb\'s %d would wrap the offset onto a real page' );

		self::assertSame( 404, $this->sitemaps()->respond( 'sub', 'pt_post', 9000000000000 )['status'], 'large but representable: an empty page' );
		self::assertSame( [], FileCache::keys( 'sub_' ) );
		self::assertArrayNotHasKey( Sitemaps::LASTMOD_OPTION, $this->store );
	}

	public function test_r2_22_a_publish_that_also_sets_its_image_ranks_it_once(): void {
		$this->seed( 5 );
		$this->cachePages( 3 );
		$this->db->published[106] = '2026-01-09 00:00:00';
		$new                      = $this->post( 106 );
		$draft                    = clone $new;
		$draft->post_status       = 'draft';

		$sm = $this->sitemaps();
		$sm->onPostMetaChanged( 61, 106, '_thumbnail_id' ); // meta_input / set_post_thumbnail, before wp_after_insert_post.
		$sm->onPostSaved( 106, $new, true, $draft );
		$sm->flushInvalidations();

		self::assertCount( 3, $this->db->ran( 'SELECT COUNT(*)' ), 'one rank (2 counts) + the total' );
		self::assertTrue( $this->isStale( 'sub_pt_post_3' ) );
	}

	public function test_r2_unpublishing_the_last_post_of_a_page_prunes_its_lastmod(): void {
		$this->seed( 5 ); // 3 pages.
		$this->cachePages( 3 );
		$this->store[ Sitemaps::LASTMOD_OPTION ] = [ 'sub_pt_post_3' => '2026-09-03T00:00:00+00:00' ];
		$before                                  = $this->post( 105 );
		unset( $this->db->published[105] );
		$after              = clone $before;
		$after->post_status = 'draft';

		$sm = $this->sitemaps();
		$sm->onPostSaved( 105, $after, true, $before );
		$sm->flushInvalidations();

		self::assertArrayNotHasKey( 'sub_pt_post_3', $this->store[ Sitemaps::LASTMOD_OPTION ] );
	}

	// --- F13 / round 2 #10: optional build costs -------------------------

	public function test_f13_terms_are_loaded_only_when_permalinks_or_an_include_filter_use_them(): void {
		$this->seed( 3 );

		$this->sitemaps()->respond( 'sub', 'pt_post', 1 );
		$this->store['permalink_structure'] = '/%category%/%postname%/';
		$this->sitemaps()->respond( 'sub', 'pt_post', 2 );
		$this->store['permalink_structure'] = '/%year%/%postname%/';
		add_filter( 'heirloom_seo_sitemap_include_post', static fn( $include ) => $include );
		FileCache::markStale( 'sub_pt_post_1' );
		$this->sitemaps()->respond( 'sub', 'pt_post', 1 );

		self::assertSame( [ false, true, true ], $this->primedTerms );
	}

	// --- Critic: build-time DB errors and third-party exclusion ----------

	public function test_critic_a_failed_query_keeps_the_previous_copy_and_caches_nothing(): void {
		$this->seed( 5 );
		$this->store[ Sitemaps::LASTMOD_OPTION ] = [ 'sub_pt_post_2' => '2026-01-04T00:00:00+00:00' ];
		FileCache::put( 'sub_pt_post_2', '<previous/>' );
		FileCache::markStale( 'sub_pt_post_2' );
		$this->db->failOn = [ 'SELECT ID FROM' ];

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 2 );

		self::assertSame( [ 'status' => 200, 'body' => '<previous/>', 'max_age' => 60 ], $r );
		self::assertTrue( $this->isStale( 'sub_pt_post_2' ), 'not replaced by an empty page' );
		self::assertSame( '2026-01-04T00:00:00+00:00', $this->lastmod( 'sub_pt_post_2' ) );
		self::assertSame( [], $this->db->held );

		self::assertSame( 503, $this->sitemaps()->respond( 'sub', 'pt_post', 3 )['status'], 'no copy: retry later, not a cached empty page' );
		self::assertSame( [ null, false ], FileCache::lookup( 'sub_pt_post_3', 0 ) );
	}

	public function test_critic_a_failed_meta_load_is_not_cached(): void {
		$this->seed( 5 );
		FileCache::put( 'sub_pt_post_2', '<previous/>' );
		FileCache::markStale( 'sub_pt_post_2' );
		$this->primeFails = true; // Every noindex flag would read as unset.

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 2 );

		self::assertSame( '<previous/>', $r['body'] );
		self::assertTrue( $this->isStale( 'sub_pt_post_2' ) );
		self::assertContains( 'post_meta:103,104', $this->cacheCalls, 'round 2 #1: its cached empty meta is dropped' );
	}

	public function test_r2_8_a_failed_featured_image_load_is_not_cached(): void {
		$this->seed( 5 );
		$this->thumbs        = [ 103 => 900 ];
		$this->primeFailsFor = 900;

		$r = $this->sitemaps()->respond( 'sub', 'pt_post', 2 );

		self::assertSame( 503, $r['status'] );
		self::assertContains( 'post_meta:900', $this->cacheCalls );
	}

	public function test_critic_r2_8_failed_tax_author_and_news_reads_are_not_cached(): void {
		$this->seed( 3 );
		Functions\when( 'get_taxonomies' )->justReturn( [ 'category' => 'category' ] );
		$this->store[ Options::OPTION ]['sitemaps']['authors'] = true;

		$cases = [
			'get_terms'      => [ 'sub', 'tax_category', 1, 'wp_cache_set_terms_last_changed' ],
			'wp_count_terms' => [ 'sub', 'tax_category', 1, 'wp_cache_set_terms_last_changed' ],
			'get_users'      => [ 'sub', 'author', 1, 'wp_cache_set_users_last_changed' ],
			'get_term_by'    => [ 'news', '', 0, 'wp_cache_set_terms_last_changed' ],
			'lastmods'       => [ 'index', '', 0, null ],
		];
		foreach ( $cases as $read => [ $which, $type, $page, $undo ] ) {
			$this->failing    = [ $read => true ];
			$this->cacheCalls = [];
			$r                = $this->sitemaps()->respond( $which, $type, $page );
			self::assertSame( 503, $r['status'], $read );
			if ( null !== $undo ) {
				self::assertContains( $undo, $this->cacheCalls, $read );
			}
		}

		$this->failing = [];
		self::assertSame( 200, $this->sitemaps()->respond( 'sub', 'tax_category', 1 )['status'], 'healthy: built' );
		self::assertSame( 200, $this->sitemaps()->respond( 'sub', 'author', 1 )['status'] );
	}

	public function test_critic_a_failed_count_keeps_the_previous_index(): void {
		$this->seed( 5 );
		FileCache::put( 'index', '<previous-index/>' );
		FileCache::markStale( 'index' );
		$this->db->failOn = [ 'SELECT COUNT(*)' ];

		$r = $this->sitemaps()->respond( 'index', '', 0 );

		self::assertSame( '<previous-index/>', $r['body'] );
		self::assertTrue( $this->isStale( 'index' ), 'an index missing the post sitemaps is not cached' );
	}

	public function test_critic_r2_2_a_plugins_own_failing_query_inside_a_hook_does_not_block_caching(): void {
		$this->seed( 5 );
		$this->store[ Options::OPTION ]['schema'] = [ 'news_category' => 'news' ];
		$this->db->failOn                        = [ 'wp_missing_plugin_table' ];
		WP_Query::$onConstruct                   = fn() => $this->db->queryFromHook( 'pre_get_posts', 'SELECT * FROM wp_missing_plugin_table' );
		Functions\when( 'get_permalink' )->alias(
			function ( $post ) {
				$this->db->queryFromHook( 'post_link', 'SELECT * FROM wp_missing_plugin_table' );
				return 'https://example.com/p/' . $post->ID . '/';
			}
		);

		foreach ( [ [ 'sub', 'pt_post', 2, 'sub_pt_post_2' ], [ 'news', '', 0, 'news' ], [ 'index', '', 0, 'index' ] ] as [ $which, $type, $page, $key ] ) {
			self::assertSame( 200, $this->sitemaps()->respond( $which, $type, $page )['status'], $key );
			self::assertTrue( $this->isFresh( $key ), $key );
		}
		self::assertNotEmpty( $GLOBALS['EZSQL_ERROR'], 'the plugin\'s queries did fail' );

		// The news query's own SQL failing still counts.
		WP_Query::$onConstruct = fn() => $this->failRead( 'SELECT wp_posts.* FROM wp_posts LEFT JOIN wp_term_relationships' );
		FileCache::markStale( 'news' );
		self::assertSame( 200, $this->sitemaps()->respond( 'news', '', 0 )['status'], 'the previous copy' );
		self::assertTrue( $this->isStale( 'news' ) );
		self::assertContains( 'wp_cache_set_posts_last_changed', $this->cacheCalls );
	}

	public function test_critic_a_failed_count_is_not_remembered_for_the_next_build(): void {
		$this->seed( 5 );
		$sm               = $this->sitemaps(); // The regenerate command reuses one instance.
		$this->db->failOn = [ "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'post' AND post_status = 'publish'" ];
		self::assertNull( $sm->build( 'index', '', 0 ) );

		$this->db->failOn = [];
		$built            = $sm->build( 'index', '', 0 );

		self::assertStringContainsString( 'sitemap-pt_post-3.xml', $built['body'] );
	}

	// --- 0.7.24: nginx page caches and News exclusions -------------------

	/**
	 * nginx page caches take X-Accel-Expires over Cache-Control, and one
	 * managed host's platform set 3 hours on every sitemap before
	 * Heirloom answered — so TGP's News sitemap could be 3 hours old (or
	 * empty, from one bad moment) for Googlebot. Each response now carries
	 * its own X-Accel-Expires, replacing the host's.
	 */
	public function test_every_sitemap_response_tells_nginx_how_long_to_keep_it(): void {
		$headers = static fn( int $status, int $max_age ): array => Sitemaps::responseHeaders( [ 'status' => $status, 'body' => '', 'max_age' => $max_age ] );

		foreach ( [ 300, 900, 3600, 60 ] as $max_age ) { // News, index, pages, the stale fallback.
			self::assertContains( "Cache-Control: public, max-age={$max_age}", $headers( 200, $max_age ) );
			self::assertContains( "X-Accel-Expires: {$max_age}", $headers( 200, $max_age ) );
		}
		self::assertContains( 'X-Accel-Expires: 0', $headers( 503, 0 ), 'a 503 is never kept' );
		self::assertContains( 'X-Accel-Expires: 60', $headers( 404, 0 ), 'a missing page may exist soon (the next page of posts)' );

		$this->seed( 2 );
		$r = $this->sitemaps()->respond( 'news', '', 0 );
		self::assertContains( 'X-Accel-Expires: 300', Sitemaps::responseHeaders( $r ) );
	}

	public function test_news_sitemap_leaves_out_excluded_categories_tags_and_authors(): void {
		$this->seed( 2 );
		$this->store[ Options::OPTION ]['schema']   = [ 'news_category' => 'news' ];
		$this->store[ Options::OPTION ]['sitemaps'] += [
			'news_exclude_categories' => [ 'opinion', 'sponsored-content' ],
			'news_exclude_tags'       => [ 'press-release' ],
			'news_exclude_authors'    => [ 'guest-writer', 'nobody-by-that-slug' ],
		];
		Functions\when( 'get_user_by' )->alias( static fn( $field, $slug ) => 'slug' === $field && 'guest-writer' === $slug ? (object) [ 'ID' => 42 ] : false );

		$this->sitemaps()->respond( 'news', '', 0 );
		$this->sitemaps()->respond( 'index', '', 0 );

		self::assertCount( 2, WP_Query::$built, 'the News sitemap and the index both query' );
		foreach ( WP_Query::$built as $args ) {
			self::assertSame(
				[
					'relation' => 'AND',
					[ 'relation' => 'OR', [ 'taxonomy' => 'category', 'field' => 'slug', 'terms' => 'news' ] ],
					[ 'taxonomy' => 'category', 'field' => 'slug', 'terms' => [ 'opinion', 'sponsored-content' ], 'operator' => 'NOT IN' ],
					[ 'taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => [ 'press-release' ], 'operator' => 'NOT IN' ],
				],
				$args['tax_query']
			);
			self::assertSame( [ 42 ], $args['author__not_in'], 'an author slug that matches nobody is ignored' );
		}
	}

	public function test_without_exclusions_the_news_query_is_unchanged(): void {
		$this->seed( 2 );
		$this->store[ Options::OPTION ]['schema'] = [ 'news_category' => 'news' ];

		$this->sitemaps()->respond( 'news', '', 0 );

		$args = WP_Query::$built[0];
		self::assertSame( [ 'relation' => 'OR', [ 'taxonomy' => 'category', 'field' => 'slug', 'terms' => 'news' ] ], $args['tax_query'] );
		self::assertArrayNotHasKey( 'author__not_in', $args );
	}

	public function test_a_failed_author_lookup_doesnt_cache_a_news_sitemap_listing_that_author(): void {
		$this->seed( 2 );
		$this->store[ Options::OPTION ]['schema']                               = [ 'news_category' => 'news' ];
		$this->store[ Options::OPTION ]['sitemaps']['news_exclude_authors']     = [ 'guest-writer' ];
		Functions\when( 'get_user_by' )->alias( fn() => $this->failRead( "SELECT * FROM wp_users WHERE user_nicename = 'guest-writer' LIMIT 1", false ) );
		FileCache::put( 'news', '<previous-news/>' );
		FileCache::markStale( 'news' );

		$r = $this->sitemaps()->respond( 'news', '', 0 );

		self::assertSame( '<previous-news/>', $r['body'] );
		self::assertTrue( $this->isStale( 'news' ) );
	}

	public function test_exclusion_settings_are_saved_as_slugs_and_unknown_ones_are_flagged(): void {
		Functions\when( 'sanitize_title' )->alias( static fn( $t ) => trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ) );
		$page = new SettingsPage( new Options() );

		$saved = $page->sanitize(
			[
				'sitemaps' => [
					'news_exclude_categories' => "Opinion, sponsored-content,\nOpinion",
					'news_exclude_tags'       => ' , ',
					'news_exclude_authors'    => 'Guest Writer',
				],
			]
		);
		self::assertSame( [ 'opinion', 'sponsored-content' ], $saved['sitemaps']['news_exclude_categories'] );
		self::assertSame( [], $saved['sitemaps']['news_exclude_tags'], 'emptying the field clears the list' );
		self::assertSame( [ 'guest-writer' ], $saved['sitemaps']['news_exclude_authors'] );

		// The field lists saved entries that match nothing on this site.
		$this->store[ Options::OPTION ] = $saved;
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		$row = new \ReflectionMethod( SettingsPage::class, 'slugListRow' );
		ob_start();
		$row->invoke( new SettingsPage( new Options() ), 'Exclude categories', 'sitemaps.news_exclude_categories', 'help', static fn( string $slug ): bool => 'opinion' === $slug );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'value="opinion, sponsored-content"', $html );
		self::assertStringContainsString( 'Not found on this site (ignored): sponsored-content', $html );
	}

	// --- 0.7.25: "Every post" counts as news ----------------------------

	public function test_every_post_mode_queries_all_recent_posts_minus_exclusions(): void {
		$this->seed( 2 );
		$this->store[ Options::OPTION ]['schema'] = [ 'news_scope' => 'all', 'news_category' => 'news' ]; // The category is kept but unused.

		$this->sitemaps()->respond( 'news', '', 0 );
		$this->sitemaps()->respond( 'index', '', 0 );

		self::assertCount( 2, WP_Query::$built, 'the News sitemap and the index both query' );
		foreach ( WP_Query::$built as $args ) {
			self::assertArrayNotHasKey( 'tax_query', $args, 'no category or tag restriction' );
			self::assertSame( 'post_date_gmt', $args['date_query'][1]['column'], 'still the last 48 hours' );
		}

		WP_Query::$built = [];
		FileCache::markStale( 'news' );
		$this->store[ Options::OPTION ]['sitemaps'] += [ 'news_exclude_categories' => [ 'sponsored' ], 'news_exclude_tags' => [ 'promoted' ] ];
		$this->sitemaps()->respond( 'news', '', 0 );
		self::assertSame(
			[
				'relation' => 'AND',
				[ 'taxonomy' => 'category', 'field' => 'slug', 'terms' => [ 'sponsored' ], 'operator' => 'NOT IN' ],
				[ 'taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => [ 'promoted' ], 'operator' => 'NOT IN' ],
			],
			WP_Query::$built[0]['tax_query']
		);
	}

	public function test_every_post_mode_needs_no_news_term(): void {
		$this->seed( 2 );
		$this->store[ Options::OPTION ]['schema'] = [ 'news_scope' => 'all', 'news_term' => '' ]; // No category, tag or fallback name.

		$this->sitemaps()->respond( 'news', '', 0 );

		self::assertCount( 1, WP_Query::$built, 'term mode would have returned an empty News sitemap without querying' );
	}

	public function test_every_post_mode_makes_every_post_a_news_article(): void {
		Functions\when( 'has_term' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( [] );
		$is_news = new \ReflectionMethod( \OrchardGrove\HeirloomSeo\Modules\Schema\Schema::class, 'isNews' );
		$post    = $this->post( 101 );

		$this->store[ Options::OPTION ]['schema'] = [ 'news_category' => 'news' ];
		self::assertFalse( $is_news->invoke( new \OrchardGrove\HeirloomSeo\Modules\Schema\Schema( new Options() ), $post ), 'term mode: not in the News category' );

		$this->store[ Options::OPTION ]['schema'] = [ 'news_scope' => 'all', 'news_category' => 'news' ];
		self::assertTrue( $is_news->invoke( new \OrchardGrove\HeirloomSeo\Modules\Schema\Schema( new Options() ), $post ) );
	}

	public function test_news_scope_setting_saves_refreshes_and_hides_the_term_pickers(): void {
		$page = new SettingsPage( new Options() );
		self::assertSame( 'all', $page->sanitize( [ 'schema' => [ 'news_scope' => 'all' ] ] )['schema']['news_scope'] );
		self::assertSame( 'term', $page->sanitize( [ 'schema' => [ 'news_scope' => 'bogus' ] ] )['schema']['news_scope'] );

		$this->cachePages( 1 );
		$defaults = ( new Options() )->defaults();
		SettingsPage::onSettingsWritten( $defaults, array_replace_recursive( $defaults, [ 'schema' => [ 'news_scope' => 'all' ] ] ) );
		self::assertTrue( $this->isStale( 'news' ) );
		self::assertTrue( $this->isStale( 'index' ) );
		self::assertTrue( $this->isFresh( 'sub_pt_post_1' ), 'post pages untouched' );

		foreach ( [ 'esc_attr', 'esc_html', 'esc_html__' ] as $fn ) {
			Functions\when( $fn )->returnArg( 1 );
		}
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'get_terms' )->justReturn( [] );
		Functions\when( 'term_exists' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );
		$render = function ( string $scope ): string {
			$this->store[ Options::OPTION ]['schema'] = [ 'news_scope' => $scope ];
			$tab                                      = new \ReflectionMethod( SettingsPage::class, 'renderTab' );
			ob_start();
			$tab->invoke( new SettingsPage( new Options() ), 'sitemaps' );
			return (string) ob_get_clean();
		};
		$term = $render( 'term' );
		$all  = $render( 'all' );
		self::assertStringContainsString( 'Every post', $term );
		self::assertStringContainsString( 'heirloom_seo[schema][news_category]', $term );
		self::assertStringNotContainsString( 'heirloom_seo[schema][news_category]', $all, 'the category/tag pickers apply only to the term mode' );
		self::assertStringNotContainsString( 'heirloom_seo[schema][news_term]', $all );
		self::assertStringContainsString( 'Exclude categories', $all, 'exclusions stay available' );
	}

	public function test_critic_posts_filtered_out_keep_their_slot(): void {
		$this->seed( 5 );
		$this->excluded = [ 103 => 1 ];

		$two   = $this->sitemaps()->respond( 'sub', 'pt_post', 2 )['body'];
		$three = $this->sitemaps()->respond( 'sub', 'pt_post', 3 )['body'];

		self::assertStringNotContainsString( '/p/103/', $two );
		self::assertStringContainsString( '/p/104/', $two );
		self::assertStringContainsString( '/p/105/', $three, 'later pages do not move up' );
	}

	// --- Helpers ----------------------------------------------------------

	/**
	 * A read's own statement fails: it goes through the 'query' filter (at
	 * the read's depth) and is logged in $EZSQL_ERROR, as real wpdb does.
	 *
	 * @param mixed $result what the read then returns
	 * @return mixed
	 */
	private function failRead( string $sql, $result = null ) {
		$this->db->failOn[] = $sql;
		$this->db->get_var( $sql );
		array_pop( $this->db->failOn );
		return $result;
	}

	/** Run the callbacks added for a filter, as core's apply_filters() would. */
	private function runFilters( string $hook, string $value ): string {
		foreach ( $this->filters[ $hook ] ?? [] as $callback ) {
			$value = $callback( $value );
		}
		return $value;
	}

	private function sitemaps(): Sitemaps {
		return new Sitemaps( new Options() );
	}

	private function seed( int $count ): void {
		for ( $i = 1; $i <= $count; $i++ ) {
			$this->db->published[ 100 + $i ] = sprintf( '2026-01-%02d 00:00:00', $i );
		}
	}

	/** $count posts, $perDate sharing each post_date, IDs deliberately not in date order. */
	private function seedMany( int $count, int $perDate ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$id                         = 1000 + ( ( $i * 7919 ) % $count );
			$this->db->published[ $id ] = gmdate( 'Y-m-d H:i:s', 1767225600 + intdiv( $i, $perDate ) * 3600 );
		}
	}

	private function post( int $id ): WP_Post {
		$date = $this->db->published[ $id ] ?? '2026-01-01 00:00:00';
		return new WP_Post(
			[
				'ID'            => $id,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_date'     => $date,
				'post_modified' => $date,
			]
		);
	}

	private function cachePages( int $pages ): void {
		for ( $p = 1; $p <= $pages; $p++ ) {
			FileCache::put( "sub_pt_post_{$p}", "<page{$p}/>" );
		}
		FileCache::put( 'index', '<index/>' );
		FileCache::put( 'news', '<news/>' );
	}

	private function lastmod( string $key ): string {
		return (string) ( $this->store[ Sitemaps::LASTMOD_OPTION ][ $key ] ?? '' );
	}

	private function indexLastmod( string $xml, string $loc ): string {
		return preg_match( '#' . preg_quote( $loc, '#' ) . '</loc>\s*<lastmod>([^<]+)</lastmod>#', $xml, $m ) ? $m[1] : '';
	}

	private function isFresh( string $key ): bool {
		[ $contents, $fresh ] = FileCache::lookup( $key, 0 );
		return null !== $contents && $fresh;
	}

	private function isStale( string $key ): bool {
		[ $contents, $fresh ] = FileCache::lookup( $key, 0 );
		return null !== $contents && ! $fresh;
	}

	private function rmdir( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}
