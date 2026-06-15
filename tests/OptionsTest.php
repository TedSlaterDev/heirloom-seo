<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\HeirloomSeo\Settings\Options;

final class OptionsTest extends TestCase {

	/** @param array<string,mixed> $stored */
	private function options( array $stored = [] ): Options {
		Functions\when( 'get_option' )->justReturn( $stored );
		return new Options();
	}

	public function testReturnsDefaultsWhenUnset(): void {
		$options = $this->options();
		$this->assertSame( '–', $options->str( 'general.separator' ) );
		$this->assertTrue( $options->bool( 'sitemaps.enabled' ) );
		$this->assertSame( 50, $options->int( 'ai.llms_max_posts' ) );
	}

	public function testStoredValueOverridesDefaultButSiblingsRemain(): void {
		$options = $this->options( [ 'general' => [ 'separator' => '|' ] ] );
		$this->assertSame( '|', $options->str( 'general.separator' ) );
		// Deep merge keeps untouched defaults from other branches.
		$this->assertTrue( $options->bool( 'sitemaps.enabled' ) );
	}

	public function testListFieldReplacesRatherThanIndexMerges(): void {
		$options = $this->options( [ 'ai' => [ 'blocked_bots' => [ 'gptbot', 'ccbot' ] ] ] );
		$this->assertSame( [ 'gptbot', 'ccbot' ], $options->arr( 'ai.blocked_bots' ) );
	}

	public function testMissingPathReturnsProvidedDefault(): void {
		$this->assertSame( 'fallback', $this->options()->str( 'does.not.exist', 'fallback' ) );
	}
}
