<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\HeirloomSeo\Modules\Ai\Export;
use WP_Post;

final class ExportTest extends TestCase {

	/** @param array<string,mixed> $props */
	private function post( array $props = [] ): WP_Post {
		return new WP_Post( array_merge( [ 'ID' => 1, 'post_status' => 'publish', 'post_type' => 'post', 'post_password' => '' ], $props ) );
	}

	public function testAllowsPublishedPublicPost(): void {
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertTrue( Export::allowed( $this->post() ) );
	}

	public function testBlocksPasswordProtected(): void {
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertFalse( Export::allowed( $this->post( [ 'post_password' => 'secret' ] ) ) );
	}

	public function testBlocksDraft(): void {
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertFalse( Export::allowed( $this->post( [ 'post_status' => 'draft' ] ) ) );
	}

	public function testBlocksNoindex(): void {
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => '_heirloom_seo_noindex' === $key ? '1' : '' );
		$this->assertFalse( Export::allowed( $this->post() ) );
	}

	public function testBlocksAiExcluded(): void {
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => '_heirloom_seo_ai_exclude' === $key ? '1' : '' );
		$this->assertFalse( Export::allowed( $this->post() ) );
	}
}
