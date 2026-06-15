<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests\Migration;

use Brain\Monkey\Functions;
use OrchardGrove\HeirloomSeo\Migration\Yoast;
use OrchardGrove\HeirloomSeo\Tests\TestCase;

final class YoastTest extends TestCase {

	public function testMapsCoreFieldsAndTranslatesVariables(): void {
		$data = [
			'_yoast_wpseo_title'                => 'Title %%sep%% %%sitename%%',
			'_yoast_wpseo_metadesc'             => 'A description.',
			'_yoast_wpseo_canonical'            => 'https://x.test/c',
			'_yoast_wpseo_meta-robots-noindex'  => '2',
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

	public function testNoindexOnlyWhenValueIsTwo(): void {
		// Yoast: 1 = index, 2 = noindex — a value of 1 must NOT set noindex.
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => '_yoast_wpseo_meta-robots-noindex' === $key ? '1' : '' );
		$map = ( new Yoast() )->mapPost( 1 );
		$this->assertArrayNotHasKey( '_heirloom_seo_noindex', $map );
	}
}
