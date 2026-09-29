<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests\Migration;

use Brain\Monkey\Functions;
use OrchardGrove\HeirloomSeo\Migration\Yoast;
use OrchardGrove\HeirloomSeo\Tests\TestCase;

/**
 * Yoast's robots values, per its own field definitions (inc/class-wpseo-meta.php)
 * and indexable builder (get_robots_noindex): meta-robots-noindex
 * 0 = post-type default, 1 = noindex, 2 = index; meta-robots-nofollow 0 = follow, 1 = nofollow.
 */
final class YoastTest extends TestCase {

	public function testMapsCoreFieldsAndTranslatesVariables(): void {
		$data = [
			'_yoast_wpseo_title'                => 'Title %%sep%% %%sitename%%',
			'_yoast_wpseo_metadesc'             => 'A description.',
			'_yoast_wpseo_canonical'            => 'https://x.test/c',
			'_yoast_wpseo_meta-robots-noindex'  => '1',
			'_yoast_wpseo_meta-robots-nofollow' => '1',
		];
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => $data[ $key ] ?? '' );

		$map = ( new Yoast() )->mapPost( 1 );

		$this->assertSame( 'Title %sep% %sitename%', $map['_heirloom_seo_title'] );
		$this->assertSame( 'A description.', $map['_heirloom_seo_desc'] );
		$this->assertSame( 'https://x.test/c', $map['_heirloom_seo_canonical'] );
		$this->assertTrue( $map['_heirloom_seo_noindex'] );
		$this->assertTrue( $map['_heirloom_seo_nofollow'] );
	}

	public function testNoindexValueImportsAsNoindex(): void {
		$map = $this->mapRobots( '1', '' );
		$this->assertTrue( $map['_heirloom_seo_noindex'] ?? null );
	}

	public function testExplicitIndexValueIsNotImportedAsNoindex(): void {
		// An editor chose "Yes, show in search results": importing it as noindex hides the post from Google.
		$this->assertArrayNotHasKey( '_heirloom_seo_noindex', $this->mapRobots( '2', '' ) );
	}

	public function testPostTypeDefaultIsNotImportedAsNoindex(): void {
		$this->assertArrayNotHasKey( '_heirloom_seo_noindex', $this->mapRobots( '0', '' ) );
		$this->assertArrayNotHasKey( '_heirloom_seo_noindex', $this->mapRobots( '', '' ) );
	}

	public function testFollowIsNotImportedAsNofollow(): void {
		$this->assertArrayNotHasKey( '_heirloom_seo_nofollow', $this->mapRobots( '', '0' ) );
		$this->assertTrue( $this->mapRobots( '', '1' )['_heirloom_seo_nofollow'] ?? null );
	}

	/** @return array<string,mixed> */
	private function mapRobots( string $noindex, string $nofollow ): array {
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => match ( $key ) {
				'_yoast_wpseo_meta-robots-noindex'  => $noindex,
				'_yoast_wpseo_meta-robots-nofollow' => $nofollow,
				default                             => '',
			}
		);
		return ( new Yoast() )->mapPost( 1 );
	}
}
