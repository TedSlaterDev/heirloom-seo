<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey\Functions;
use Mockery;
use OrchardGrove\HeirloomSeo\Audit\Audit;
use OrchardGrove\HeirloomSeo\Modules\Ai\LlmsTxt;
use OrchardGrove\HeirloomSeo\Modules\IndexNow\IndexNow;
use OrchardGrove\HeirloomSeo\Modules\Robots\Robots;
use OrchardGrove\HeirloomSeo\Modules\Sitemaps\Sitemaps;
use OrchardGrove\HeirloomSeo\Plugin;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Support\Visibility;
use ReflectionProperty;
use WP_Post;

/**
 * Settings → Reading → "Discourage search engines from indexing this site"
 * (blog_public = '0'). WordPress then prints noindex everywhere and turns its
 * own sitemaps off; Heirloom must not keep pointing crawlers at the site's
 * URLs through robots.txt, /llms.txt or IndexNow. (The sitemaps themselves:
 * SitemapsTest.)
 */
final class VisibilityTest extends TestCase {

	private const KEY = 'wdQgcISujjH1ZYq6PI21GXxtZgcpMa8g';

	private string $root = '';

	/** @var array<string,mixed> */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();
		self::resetSingleton();

		$this->root  = sys_get_temp_dir() . '/heirloom-visibility-' . bin2hex( random_bytes( 6 ) );
		$this->store = [
			'blog_public'   => '1',
			Options::OPTION => [
				'sitemaps' => [ 'enabled' => true ],
				'indexnow' => [ 'enabled' => true, 'key' => self::KEY ],
				'ai'       => [ 'llms_enabled' => true ],
				'robots'   => [ 'robots_txt' => "User-agent: Googlebot\nDisallow: /private/" ],
			],
		];
		mkdir( $this->root, 0777, true );

		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->store[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->store[ $name ] );
				return true;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_is_writable' )->alias( static fn( $dir ) => is_writable( $dir ) );
		Functions\when( 'wp_generate_password' )->alias( static fn( $len = 12 ) => substr( bin2hex( random_bytes( 16 ) ), 0, (int) $len ) );
		Functions\when( 'did_action' )->justReturn( 1 ); // after `init`
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'get_bloginfo' )->alias( static fn( $what = '' ) => 'name' === $what ? 'Example Site' : '' );
		// Both static files land in our temp root instead of ABSPATH.
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value, ...$args ) => match ( $hook ) {
				'heirloom_seo/llms_static_path'     => $this->root . '/llms.txt',
				'heirloom_seo/indexnow_static_path' => $this->root . '/' . $args[0] . '.txt',
				default                             => $value,
			}
		);
	}

	protected function tearDown(): void {
		foreach ( glob( $this->root . '/live/*' ) ?: [] as $f ) {
			@unlink( $f );
		}
		@rmdir( $this->root . '/live' );
		foreach ( array_merge( glob( $this->root . '/*' ) ?: [], glob( $this->root . '/.*' ) ?: [] ) as $f ) {
			if ( ! is_dir( $f ) ) {
				@unlink( $f );
			}
		}
		@rmdir( $this->root );
		self::resetSingleton();
		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		parent::tearDown();
	}

	private function discourage( bool $discouraged ): void {
		$this->store['blog_public'] = $discouraged ? '0' : '1';
	}

	private function llmsFile(): string {
		return $this->root . '/llms.txt';
	}

	private function keyFile(): string {
		return $this->root . '/' . self::KEY . '.txt';
	}

	// --- The setting ----------------------------------------------------------

	public function testReadsTheSettingTheWayCoreDoes(): void {
		$this->discourage( true );
		self::assertTrue( Visibility::discouraged() );
		$this->discourage( false );
		self::assertFalse( Visibility::discouraged() );
		unset( $this->store['blog_public'] ); // core's ! get_option( 'blog_public' ) treats a missing row as private too
		self::assertTrue( Visibility::discouraged() );
	}

	// --- robots.txt -----------------------------------------------------------

	public function testRobotsTxtLosesOnlyItsSitemapLineWhileDiscouraged(): void {
		$robots = new Robots( new Options() );
		// Core's robots.txt is the same either way since WP 5.3; it passes (bool) blog_public as $public.
		$core = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

		$closed = $robots->filterRobotsTxt( $core, false );
		self::assertStringNotContainsString( 'Sitemap:', $closed );
		self::assertStringStartsWith( $core, $closed );
		self::assertStringContainsString( "User-agent: Googlebot\nDisallow: /private/", $closed, 'the site\'s own rules stay' );

		$open = $robots->filterRobotsTxt( $core, true );
		self::assertStringContainsString( 'Sitemap: https://example.com/sitemap.xml', $open );
		self::assertStringContainsString( "User-agent: Googlebot\nDisallow: /private/", $open );
	}

	// --- Static files ---------------------------------------------------------

	public function testTickingTheBoxRemovesBothStaticFilesAndUntickingRestoresThem(): void {
		Functions\when( 'get_posts' )->justReturn( [] );
		IndexNow::sync( new Options() );
		LlmsTxt::sync( new Options() );
		self::assertFileExists( $this->keyFile() );
		self::assertFileExists( $this->llmsFile() );

		$this->discourage( true );
		Plugin::instance()->onVisibilityChanged();

		self::assertFileDoesNotExist( $this->keyFile() );
		self::assertFileDoesNotExist( $this->llmsFile() );
		self::assertArrayNotHasKey( 'heirloom_seo_indexnow_static', $this->store, 'ownership released' );
		self::assertArrayNotHasKey( 'heirloom_seo_llms_static', $this->store );

		$this->discourage( false );
		Plugin::instance()->onVisibilityChanged();

		self::assertSame( self::KEY, file_get_contents( $this->keyFile() ) );
		self::assertStringContainsString( '# Example Site', (string) file_get_contents( $this->llmsFile() ) );
	}

	public function testBeforeInitTheLlmsRebuildIsDeferredNotBuilt(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\expect( 'get_posts' )->never(); // post types aren't registered yet

		Plugin::instance()->onVisibilityChanged();

		self::assertSame( '1', $this->store['heirloom_seo_llms_dirty'] ?? null, 'flagged for the admin_init / wp-cron rebuild' );
		self::assertFileDoesNotExist( $this->llmsFile() );
		self::assertFileExists( $this->keyFile(), 'the key file needs no post types' );
	}

	public function testWhileDiscouragedSyncWritesNothingAndBuildsNothing(): void {
		$this->discourage( true );
		Functions\expect( 'get_posts' )->never();

		IndexNow::sync( new Options() );
		LlmsTxt::sync( new Options() );

		self::assertFileDoesNotExist( $this->keyFile() );
		self::assertFileDoesNotExist( $this->llmsFile() );
		self::assertArrayNotHasKey( 'heirloom_seo_llms_static_failed', $this->store, 'no "could not write" notice either' );
		self::assertArrayNotHasKey( 'heirloom_seo_indexnow_static_failed', $this->store );
	}

	public function testAFileHeirloomDidNotWriteIsLeftAloneWhileDiscouraged(): void {
		file_put_contents( $this->llmsFile(), "# Written by hand\n" );
		$this->discourage( true );

		LlmsTxt::sync( new Options() );

		self::assertSame( "# Written by hand\n", file_get_contents( $this->llmsFile() ) );
	}

	// --- A database copied from another site ----------------------------------

	/** The source site's root, beside ours: a staging copy on the same server. */
	private function sourceSiteKeyFile(): string {
		$dir = $this->root . '/live';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir );
		}
		$file = $dir . '/' . self::KEY . '.txt';
		file_put_contents( $file, self::KEY );
		return $file;
	}

	/** Files and database copied together: our root has the key file, the record points at the source. */
	private function copyFromSourceSite(): string {
		$source = $this->sourceSiteKeyFile();
		file_put_contents( $this->keyFile(), self::KEY );
		$this->store['heirloom_seo_indexnow_static']      = '1';
		$this->store['heirloom_seo_indexnow_static_path'] = $source;
		return $source;
	}

	public function testDiscouragingACopyNeverDeletesTheSourceSitesKeyFile(): void {
		$source = $this->copyFromSourceSite();
		$this->discourage( true );

		Plugin::instance()->onVisibilityChanged();

		self::assertFileExists( $source, 'the other site keeps its key file' );
		self::assertFileDoesNotExist( $this->keyFile(), 'the copy brought along goes' );
		self::assertArrayNotHasKey( 'heirloom_seo_indexnow_static', $this->store, 'the copied record is dropped' );
		self::assertArrayNotHasKey( 'heirloom_seo_indexnow_static_path', $this->store );
	}

	public function testAPublicCopyAdoptsItsOwnKeyFileAndLeavesTheSourceAlone(): void {
		$source = $this->copyFromSourceSite();

		IndexNow::sync( new Options() );

		self::assertFileExists( $source );
		self::assertSame( self::KEY, file_get_contents( $this->keyFile() ) );
		self::assertSame( $this->keyFile(), $this->store['heirloom_seo_indexnow_static_path'] );
	}

	public function testAKeyNamedFileWithOtherContentsStaysWhileDiscouraged(): void {
		file_put_contents( $this->keyFile(), 'not the key' );
		$this->discourage( true );

		IndexNow::sync( new Options() );

		self::assertSame( 'not the key', file_get_contents( $this->keyFile() ) );
	}

	// --- Visibility changed without a blog_public write -----------------------

	/** @dataProvider staticFiles */
	public function testABackEndRequestCatchesUpWhenTheSettingChangedWithoutAWrite( string $only ): void {
		Functions\when( 'get_posts' )->justReturn( [] );
		'key' === $only ? IndexNow::sync( new Options() ) : LlmsTxt::sync( new Options() );
		$file = 'key' === $only ? $this->keyFile() : $this->llmsFile();
		self::assertFileExists( $file );
		$this->discourage( true ); // SQL, a copied database or a pre_option_blog_public filter: no hook fires

		Plugin::instance()->reconcileVisibility();

		self::assertFileDoesNotExist( $file );
	}

	/** @return array<string,array{string}> */
	public static function staticFiles(): array {
		return [
			'only the IndexNow key file on record' => [ 'key' ],
			'only llms.txt on record'              => [ 'llms' ],
		];
	}

	public function testCatchingUpDoesNothingOnAPublicSiteOrWithNothingOnRecord(): void {
		$syncs = 0;
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function () use ( &$syncs ) {
				++$syncs; // only LlmsTxt::sync's discouraged branch calls it here
				return 0;
			}
		);
		$this->store['heirloom_seo_llms_static'] = '1';

		Plugin::instance()->reconcileVisibility(); // public
		unset( $this->store['heirloom_seo_llms_static'] );
		$this->discourage( true );
		Plugin::instance()->reconcileVisibility(); // discouraged, nothing on record

		self::assertSame( 0, $syncs );
	}

	public function testItsNotFoundAnswersAreCachedNoLongerThanASitemap404(): void {
		self::assertSame( Sitemaps::responseHeaders( [ 'status' => 404, 'body' => '', 'max_age' => 0 ] ), Visibility::NOT_FOUND_HEADERS );
	}

	// --- IndexNow pings -------------------------------------------------------

	public function testNothingIsSubmittedWhileIndexNowIsTurnedOffWithAKeyStillStored(): void {
		$this->store[ Options::OPTION ]['indexnow']['enabled'] = false;
		Functions\expect( 'wp_remote_post' )->never();

		self::assertFalse( ( new IndexNow( new Options() ) )->submitNow( [ 'https://example.com/hello/' ] ) );
	}

	private function queuePublish( IndexNow $indexnow ): void {
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/hello/' );
		$indexnow->onTransition( 'publish', 'draft', new WP_Post( [ 'ID' => 7 ] ) );
	}

	public function testNothingIsSubmittedWhileDiscouraged(): void {
		$this->discourage( true );
		Functions\expect( 'wp_remote_post' )->never();
		$indexnow = new IndexNow( new Options() );

		$this->queuePublish( $indexnow );
		$indexnow->flush();

		self::assertFalse( $indexnow->submitNow( [ 'https://example.com/hello/' ] ) );
	}

	public function testPublishingIsSubmittedWhenSearchEnginesAreWelcome(): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $part = -1 ) => parse_url( $url, $part ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\expect( 'wp_remote_post' )->once()->with( 'https://api.indexnow.org/indexnow', Mockery::on( static fn( $args ) => str_contains( $args['body'], 'https:\/\/example.com\/hello\/' ) ) );
		$indexnow = new IndexNow( new Options() );

		$this->queuePublish( $indexnow );
		$indexnow->flush();
	}

	// --- SEO Health -----------------------------------------------------------

	/** @return array<int,array{status:string,label:string,detail:string}> */
	private function configChecks( string $env ): array {
		Functions\when( 'wp_get_environment_type' )->justReturn( $env );
		return ( new Audit( new Options() ) )->configChecks();
	}

	/** @param array<int,array{status:string,label:string,detail:string}> $checks */
	private static function finding( array $checks, string $label ): ?array {
		foreach ( $checks as $check ) {
			if ( $label === $check['label'] ) {
				return $check;
			}
		}
		return null;
	}

	public function testAuditCallsItAnErrorOnProduction(): void {
		$this->discourage( true );
		$checks = $this->configChecks( 'production' );

		self::assertSame( 'Search engine visibility', $checks[0]['label'], 'listed first' );
		self::assertSame( 'error', $checks[0]['status'] );
		self::assertStringContainsString( 'live site', $checks[0]['detail'] );
		self::assertSame( 'info', self::finding( $checks, 'XML sitemap' )['status'] );
		self::assertStringContainsString( 'not served', self::finding( $checks, 'XML sitemap' )['detail'] );
	}

	public function testAuditCallsItExpectedElsewhere(): void {
		$this->discourage( true );
		$check = self::finding( $this->configChecks( 'staging' ), 'Search engine visibility' );

		self::assertSame( 'info', $check['status'] );
		self::assertStringContainsString( 'staging', $check['detail'] );
	}

	public function testAuditDoesNotCallIndexNowWorkingWhileNothingIsSent(): void {
		$this->discourage( true );
		$check = self::finding( $this->configChecks( 'staging' ), 'IndexNow' );

		self::assertSame( 'info', $check['status'] );
		self::assertStringContainsString( 'nothing is sent', $check['detail'] );
	}

	public function testAuditSaysNothingAboutVisibilityWhenSearchEnginesAreWelcome(): void {
		$checks = $this->configChecks( 'production' );

		self::assertNull( self::finding( $checks, 'Search engine visibility' ) );
		self::assertSame( 'ok', self::finding( $checks, 'XML sitemap' )['status'] );
		self::assertSame( 'ok', self::finding( $checks, 'IndexNow' )['status'] );
	}

	public function testAuditSkipsTheHttpChecksThatWouldOnly404WhileDiscouraged(): void {
		$this->discourage( true );
		Functions\expect( 'wp_remote_get' )->never();

		self::assertSame( [], ( new Audit( new Options() ) )->httpChecks() );
	}

	private static function resetSingleton(): void {
		( new ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null );
	}
}
