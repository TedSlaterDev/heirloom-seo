<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\HeirloomSeo\Modules\Ai\Crawlers;
use OrchardGrove\HeirloomSeo\Settings\Options;

final class CrawlersTest extends TestCase {

	/** @param string[] $blocked */
	private function crawlers( array $blocked ): Crawlers {
		Functions\when( 'get_option' )->justReturn( [ 'ai' => [ 'blocked_bots' => $blocked ] ] );
		return new Crawlers( new Options() );
	}

	public function testNoRulesWhenNothingBlocked(): void {
		$this->assertSame( 'BASE', $this->crawlers( [] )->append( 'BASE', true ) );
	}

	public function testAddsDisallowForBlockedBot(): void {
		$out = $this->crawlers( [ 'gptbot' ] )->append( 'BASE', true );
		$this->assertStringContainsString( 'User-agent: GPTBot', $out );
		$this->assertStringContainsString( 'Disallow: /', $out );
	}

	public function testIgnoresUnknownBotKey(): void {
		$out = $this->crawlers( [ 'not_a_real_bot' ] )->append( 'BASE', true );
		$this->assertStringNotContainsString( 'User-agent:', $out );
	}
}
