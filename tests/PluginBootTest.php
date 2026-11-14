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

	/**
	 * Settings → Reading saves blog_public in wp-admin, but `wp option update`
	 * and code can write it on any request; either way the static /llms.txt and
	 * IndexNow key file must follow it.
	 */
	#[DataProvider( 'requests' )]
	public function testBootWiresTheSearchEngineVisibilitySetting( bool $admin ): void {
		Functions\when( 'get_option' )->alias( static fn( $name, $fallback = false ) => 'heirloom_seo_version' === $name ? HEIRLOOM_SEO_VERSION : $fallback );
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( HEIRLOOM_SEO_BASENAME );
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$plugin = Plugin::instance();
		Actions\expectAdded( 'update_option_blog_public' )->once()->with( [ $plugin, 'onVisibilityChanged' ] );
		Actions\expectAdded( 'add_option_blog_public' )->once()->with( [ $plugin, 'onVisibilityChanged' ] );

		$plugin->boot();

		// Changes made without a write (SQL, a copied database, a filter) are caught up on back-end requests.
		self::assertNotFalse( has_action( 'admin_init', [ $plugin, 'reconcileVisibility' ] ) );
	}

	/**
	 * The redirects for Yoast SEO's sitemap URLs are routes of the sitemap
	 * module itself, so the sitemap setting turns them off along with the
	 * sitemaps (a settings save flushes rewrite rules).
	 */
	#[DataProvider( 'sitemapSetting' )]
	public function testYoastSitemapRedirectsAreRoutedOnlyWhileSitemapsAreOn( bool $enabled ): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $fallback = false ) => match ( $name ) {
				'heirloom_seo_version' => HEIRLOOM_SEO_VERSION,
				Options::OPTION        => [ 'sitemaps' => [ 'enabled' => $enabled ] ],
				default                => $fallback,
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'add_shortcode' )->justReturn( true );

		Plugin::instance()->boot();

		self::assertSame( $enabled, false !== has_action( 'init', 'OrchardGrove\HeirloomSeo\Modules\Sitemaps\Sitemaps->addRewriteRules()' ) );
	}

	/** @return array<string,array{bool}> */
	public static function sitemapSetting(): array {
		return [
			'sitemaps on'  => [ true ],
			'sitemaps off' => [ false ],
		];
	}

	/** A new version (the one adding the Yoast sitemap routes, say) flushes rewrite rules on the next back-end request. */
	public function testAnUpgradeFlushesRewriteRules(): void {
		$store = [ 'heirloom_seo_version' => '0.0.1' ];
		Functions\when( 'get_option' )->alias( static function ( $name, $fallback = false ) use ( &$store ) {
			return $store[ $name ] ?? $fallback;
		} );
		Functions\when( 'update_option' )->alias( static function ( $name, $value ) use ( &$store ) {
			$store[ $name ] = $value;
			return true;
		} );
		Functions\when( 'delete_option' )->alias( static function ( $name ) use ( &$store ) {
			unset( $store[ $name ] );
			return true;
		} );
		$flushed = 0;
		Functions\when( 'flush_rewrite_rules' )->alias( static function () use ( &$flushed ) {
			++$flushed;
		} );
		$uploads = sys_get_temp_dir() . '/heirloom-boot-' . bin2hex( random_bytes( 4 ) );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => $uploads ] );
		Functions\when( 'trailingslashit' )->alias( static fn( $p ) => rtrim( (string) $p, '/' ) . '/' );
		Functions\when( 'wp_next_scheduled' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( HEIRLOOM_SEO_BASENAME );
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$plugin = Plugin::instance();
		$plugin->boot();
		self::assertSame( HEIRLOOM_SEO_VERSION, $store['heirloom_seo_version'] );
		self::assertSame( 99, has_action( 'init', [ $plugin, 'maybeFlushRewrites' ] ), 'after the modules add their rules on init' );

		$plugin->maybeFlushRewrites();
		$plugin->maybeFlushRewrites();
		self::assertSame( 1, $flushed, 'once' );
		@rmdir( $uploads ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
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
