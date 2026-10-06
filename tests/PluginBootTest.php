<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use OrchardGrove\HeirloomSeo\Plugin;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Settings\SettingsPage;
use OrchardGrove\HeirloomSeo\Support\Images;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Plugin::boot() wiring that no module test can see. The share-image cache is
 * only kept honest by hooks registered here, and uploads arrive on front-end,
 * REST, cron and WP-CLI requests as well as in wp-admin.
 */
final class PluginBootTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		self::resetSingleton();
	}

	protected function tearDown(): void {
		self::resetSingleton();
		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		parent::tearDown();
	}

	#[DataProvider( 'requests' )]
	public function testBootWiresTheImageCacheInvalidation( bool $admin ): void {
		// The installed version matches, so the upgrade branch (file and DB work) stays out.
		Functions\when( 'get_option' )->alias( static fn( $name, $fallback = false ) => 'heirloom_seo_version' === $name ? HEIRLOOM_SEO_VERSION : $fallback );
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( HEIRLOOM_SEO_BASENAME );
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		Actions\expectAdded( 'add_attachment' )->once()->with( [ Images::class, 'onAttachmentAdded' ], 10, 1 );
		Actions\expectAdded( 'delete_attachment' )->once()->with( [ Images::class, 'onAttachmentDeleted' ], 10, 1 );
		Actions\expectAdded( 'updated_post_meta' )->once()->with( [ Images::class, 'onAttachedFileArrived' ], 10, 4 );

		Plugin::instance()->boot();
	}

	/**
	 * Sitemap-affecting settings refresh the cache however the option is
	 * written — the settings page's sanitize callback exists only in wp-admin,
	 * and `wp option update` or code skip it.
	 */
	#[DataProvider( 'requests' )]
	public function testBootWiresSettingsWritesToTheSitemapCache( bool $admin ): void {
		Functions\when( 'get_option' )->alias( static fn( $name, $fallback = false ) => 'heirloom_seo_version' === $name ? HEIRLOOM_SEO_VERSION : $fallback );
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( HEIRLOOM_SEO_BASENAME );
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		Actions\expectAdded( 'update_option_' . Options::OPTION )->once()->with( [ SettingsPage::class, 'onSettingsWritten' ], 10, 2 );
		Actions\expectAdded( 'update_option_' . Options::OPTION )->atLeast()->once()->with( Mockery::type( 'array' ), 10, 1 ); // IndexNow key file, AI module.
		Actions\expectAdded( 'add_option_' . Options::OPTION )->once()->with( Mockery::type( \Closure::class ), 10, 2 );
		Actions\expectAdded( 'add_option_' . Options::OPTION )->atLeast()->once()->with( Mockery::type( 'array' ), 10, 1 );

		Plugin::instance()->boot();
	}

	/** @return array<string,array{bool}> */
	public static function requests(): array {
		return [
			'front end, REST, cron or WP-CLI' => [ false ],
			'wp-admin'                        => [ true ],
		];
	}

	private static function resetSingleton(): void {
		( new ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );
	}
}
