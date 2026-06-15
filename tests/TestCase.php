<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case: sets up Brain Monkey and stubs the WP functions used widely.
 */
abstract class TestCase extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( wp_strip_all_tags_fallback( (string) $s ) ) );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}

/** Plain strip_tags helper (kept out of the class so Brain Monkey can alias to it). */
function wp_strip_all_tags_fallback( string $value ): string {
	return strip_tags( $value );
}
