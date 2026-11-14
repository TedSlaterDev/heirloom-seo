<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\HeirloomSeo\Context;
use OrchardGrove\HeirloomSeo\Modules\Meta\Meta;
use OrchardGrove\HeirloomSeo\Settings\Options;
use WP_Post;

/**
 * %pagination%: "– Page 2 of 5" from page 2 on, nothing on page 1, the same as
 * Yoast SEO's %%page%%, so title formats carried over from Yoast keep their
 * paginated titles. Pages count from the post's <!--nextpage--> breaks on a
 * single post or page, and from the main query everywhere else.
 */
final class TitleTest extends TestCase {

	/** @var array<string,mixed> */
	private array $store = [];

	private ?WP_Post $queried = null;

	/** The one WordPress conditional that's true, e.g. 'is_singular'. */
	private string $is = 'is_singular';

	/** @var array<string,int> */
	private array $vars = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store   = [];
		$this->queried = null;
		$this->vars    = [];
		unset( $GLOBALS['wp_query'] );

		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->store[ $name ] ?? $fallback );
		foreach ( [ 'is_404', 'is_search', 'is_front_page', 'is_home', 'is_singular', 'is_author', 'is_post_type_archive', 'is_category', 'is_tag', 'is_tax', 'is_date', 'is_feed' ] as $fn ) {
			Functions\when( $fn )->alias( fn() => $fn === $this->is );
		}
		Functions\when( 'get_queried_object' )->alias( fn() => $this->queried );
		Functions\when( 'get_query_var' )->alias( fn( $key ) => $this->vars[ $key ] ?? 0 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->alias( static fn( $key ) => 'name' === $key ? 'Site' : 'Tagline' );
		Functions\when( 'get_the_title' )->alias( static fn( $post ) => $post->post_title );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane' );
		Functions\when( 'get_the_archive_title' )->justReturn( 'Month: <span>November 2026</span>' );
		Functions\when( 'get_search_query' )->justReturn( '' );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_query'] );
		parent::tearDown();
	}

	public function testAPostSplitIntoPagesCountsItsPages(): void {
		$this->queried = new WP_Post( [ 'post_title' => 'Hello', 'post_content' => 'a<!--nextpage-->b<!--nextpage-->c' ] );
		$GLOBALS['wp_query'] = (object) [ 'max_num_pages' => 9 ]; // Not a post's count.

		self::assertSame( 'Hello – Site', $this->title( 'post', '%title% %pagination% %sep% %sitename%' ) );

		$this->vars['page'] = 2;
		self::assertSame( 'Hello – Page 2 of 3 – Site', $this->title( 'post', '%title% %pagination% %sep% %sitename%' ) );
		self::assertSame( 'Hello 2', $this->title( 'post', '%title% %page%' ), '%page% stays the bare number' );
	}

	public function testArchivesCountTheQuerysPages(): void {
		$this->is            = 'is_date';
		$this->vars['paged'] = 3;
		$GLOBALS['wp_query'] = (object) [ 'max_num_pages' => 7 ];

		self::assertSame( 'Month: November 2026 – Page 3 of 7 – Site', $this->title( 'archive', '%archive_title% %pagination% %sep% %sitename%' ) );
	}

	public function testThePostsPageCountsItsPostsNotItsContent(): void {
		$this->is            = 'is_home';
		$this->queried       = new WP_Post( [ 'post_title' => 'News', 'post_content' => '' ] );
		$this->vars['paged'] = 2;
		$GLOBALS['wp_query'] = (object) [ 'max_num_pages' => 4 ];

		self::assertSame( 'News – Page 2 of 4 – Site', $this->title( 'home', '%title% %pagination% %sep% %sitename%' ) );
	}

	public function testALatestPostsFrontPageCountsTheQuerysPages(): void {
		$this->is            = 'is_front_page';
		$this->vars['paged'] = 2;
		$GLOBALS['wp_query'] = (object) [ 'max_num_pages' => 30 ];

		self::assertSame( 'Site – Page 2 of 30 – Tagline', $this->title( 'front', '%sitename% %pagination% %sep% %tagline%' ) );
	}

	public function testNothingWhenThePageCountIsUnknown(): void {
		$this->is            = 'is_date';
		$this->vars['paged'] = 2;

		self::assertSame( 'Month: November 2026 – Site', $this->title( 'archive', '%archive_title% %pagination% %sep% %sitename%' ) );
	}

	public function testItUsesTheSitesSeparator(): void {
		$this->queried      = new WP_Post( [ 'post_title' => 'Hello', 'post_content' => 'a<!--nextpage-->b' ] );
		$this->vars['page'] = 2;

		self::assertSame( 'Hello - Page 2 of 2 - Site', $this->title( 'post', '%title% %pagination% %sep% %sitename%', '-' ) );
	}

	public function testAFormatEndingInItLeavesNothingBehindOnPageOne(): void {
		$this->queried = new WP_Post( [ 'post_title' => 'Hello', 'post_author' => 3, 'post_content' => 'a<!--nextpage-->b' ] );

		self::assertSame( 'Hello, by Jane at Site', $this->title( 'post', '%title%, by %author% at %sitename% %pagination%' ) );

		$this->vars['page'] = 2;
		self::assertSame( 'Hello, by Jane at Site – Page 2 of 2', $this->title( 'post', '%title%, by %author% at %sitename% %pagination%' ) );
	}

	private function title( string $key, string $template, string $separator = '–' ): string {
		$this->store[ Options::OPTION ] = [
			'general' => [ 'separator' => $separator ],
			'titles'  => [ $key => $template ],
		];
		return ( new Meta( new Options() ) )->title( new Context() );
	}
}
