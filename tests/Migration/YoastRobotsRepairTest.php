<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests\Migration;

use Brain\Monkey\Functions;
use Mockery;
use OrchardGrove\HeirloomSeo\Migration\YoastRobotsRepair as Repair;
use OrchardGrove\HeirloomSeo\Tests\TestCase;

/**
 * The repair for noindex flags imported backwards from Yoast (Heirloom SEO
 * 0.7.18 and earlier). Yoast: 1 = noindex, 2 = index, 0 = post-type default.
 */
final class YoastRobotsRepairTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		parent::tearDown();
	}

	public function testYoastNoindexWithoutAHeirloomFlagNeedsOne(): void {
		$this->assertSame( Repair::ADD, Repair::classify( '1', null ) );
		$this->assertSame( Repair::ADD, Repair::classify( '1', '' ) );
		$this->assertSame( Repair::ADD, Repair::classify( '1', '0' ) );
	}

	public function testYoastIndexWithAHeirloomFlagLosesIt(): void {
		$this->assertSame( Repair::REMOVE, Repair::classify( '2', '1' ) );
	}

	public function testPostsThatAlreadyMatchYoastAreLeftAlone(): void {
		$this->assertNull( Repair::classify( '1', '1' ) );
		$this->assertNull( Repair::classify( '2', null ) );
		$this->assertNull( Repair::classify( '2', '' ) );
		$this->assertNull( Repair::classify( '2', '0' ) );
	}

	public function testYoastDefaultIsNeverTouched(): void {
		// 0 means "follow the post type's default": nothing explicit to repair against.
		$this->assertNull( Repair::classify( '0', null ) );
		$this->assertNull( Repair::classify( '0', '1' ) );
		$this->assertNull( Repair::classify( '', '1' ) );
	}

	public function testFindsOnlyMismatchesAndReadsTheFirstRowOfDuplicates(): void {
		$GLOBALS['wpdb'] = $this->wpdb( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a $wpdb double.
			[
				[ 'ID' => '10', 'post_title' => 'Explicit index, imported as noindex', 'post_type' => 'post', 'post_status' => 'publish', 'yoast' => '2', 'heirloom' => '1' ],
				[ 'ID' => '11', 'post_title' => 'Explicit noindex, missed', 'post_type' => 'page', 'post_status' => 'draft', 'yoast' => '1', 'heirloom' => null ],
				[ 'ID' => '12', 'post_title' => 'Already right', 'post_type' => 'post', 'post_status' => 'publish', 'yoast' => '1', 'heirloom' => '1' ],
				[ 'ID' => '13', 'post_title' => 'Duplicate rows', 'post_type' => 'post', 'post_status' => 'publish', 'yoast' => '2', 'heirloom' => null ],
				[ 'ID' => '13', 'post_title' => 'Duplicate rows', 'post_type' => 'post', 'post_status' => 'publish', 'yoast' => '2', 'heirloom' => '1' ],
			]
		);

		$items = ( new Repair() )->findMismatches();

		$this->assertSame( [ 10, 11 ], array_column( $items, 'id' ) );
		$this->assertSame( [ Repair::REMOVE, Repair::ADD ], array_column( $items, 'action' ) );
		$this->assertSame( [ 'index', 'noindex' ], array_column( $items, 'yoast' ) );
		$this->assertSame( [ '1', 'none' ], array_column( $items, 'heirloom' ) );
		$sql = $GLOBALS['wpdb']->queries[0];
		$this->assertStringContainsString( "y.meta_key = '_yoast_wpseo_meta-robots-noindex' AND y.meta_value IN ('1', '2')", $sql );
		$this->assertStringContainsString( "h.meta_key = '_heirloom_seo_noindex'", $sql );
		$this->assertStringContainsString( "p.post_type <> 'revision'", $sql );
		$this->assertStringContainsString( 'ORDER BY p.ID, y.meta_id, h.meta_id', $sql );
	}

	public function testReportsWhetherYoastDataExists(): void {
		$GLOBALS['wpdb'] = $this->wpdb( [], '42' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a $wpdb double.
		$this->assertTrue( ( new Repair() )->hasYoastData() );
		$this->assertStringContainsString( "meta_key = '_yoast_wpseo_meta-robots-noindex' LIMIT 1", $GLOBALS['wpdb']->queries[0] );

		$GLOBALS['wpdb'] = $this->wpdb( [], null ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a $wpdb double.
		$this->assertFalse( ( new Repair() )->hasYoastData() );
	}

	public function testFiltersByKindAndExcludedIds(): void {
		$items = [
			[ 'id' => 1, 'action' => Repair::ADD ],
			[ 'id' => 2, 'action' => Repair::REMOVE ],
			[ 'id' => 3, 'action' => Repair::ADD ],
		];
		$this->assertSame( [ 1, 2, 3 ], array_column( Repair::filter( $items, '', [] ), 'id' ) );
		$this->assertSame( [ 1, 3 ], array_column( Repair::filter( $items, 'add', [] ), 'id' ) );
		$this->assertSame( [ 2 ], array_column( Repair::filter( $items, 'remove', [] ), 'id' ) );
		$this->assertSame( [ 1, 2 ], array_column( Repair::filter( $items, '', [ 3 ] ), 'id' ) );
	}

	public function testWritesFlagsTheWayThePostEditorDoes(): void {
		Functions\expect( 'update_post_meta' )->once()->with( 5, '_heirloom_seo_noindex', 1 )->andReturn( true );
		Functions\expect( 'delete_post_meta' )->once()->with( 7, '_heirloom_seo_noindex' )->andReturn( true );

		$changed = ( new Repair() )->apply(
			[
				[ 'id' => 5, 'action' => Repair::ADD ],
				[ 'id' => 7, 'action' => Repair::REMOVE ],
				[ 'id' => 9, 'action' => '' ],
			]
		);

		$this->assertSame( 2, $changed );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows get_results() returns.
	 * @param string|null                     $var  What get_var() returns.
	 */
	private function wpdb( array $rows, ?string $var = null ): object {
		return new class( $rows, $var ) {
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';

			/** @var string[] */
			public array $queries = [];

			/** @param array<int,array<string,mixed>> $rows */
			public function __construct( private array $rows, private ?string $var ) {}

			public function prepare( string $query, string ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			/** @return array<int,array<string,mixed>> */
			public function get_results( string $query, string $output ): array {
				$this->queries[] = $query;
				return 'ARRAY_A' === $output ? $this->rows : [];
			}

			public function get_var( string $query ): ?string {
				$this->queries[] = $query;
				return $this->var;
			}
		};
	}
}
