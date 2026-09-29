<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use OrchardGrove\HeirloomSeo\Context;
use OrchardGrove\HeirloomSeo\Modules\Meta\Meta;
use OrchardGrove\HeirloomSeo\Modules\Schema\Schema;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Support\Images;
use WP_Post;

/**
 * Share-image resolution cost. attachment_url_to_postid() is an unindexed
 * meta_value scan (330–400 ms on wnd.com's 11.9M-row postmeta), and og:image,
 * twitter:image and the schema #primaryimage each resolve the share image, so a
 * URL-stored default image cost ~1 s on every uncached page without a featured
 * image. The source is now resolved once per request and the answer remembered
 * across requests, keyed by the uploads-relative path core matches.
 *
 * Every count claim is made with Functions\expect() inside the test itself.
 * setUp() only stubs functions no test counts: a shared when() stub silently
 * wins over a later expect() and would make "never called" pass vacuously.
 */
final class ImagesTest extends TestCase {

	private const UPLOADS  = 'https://example.com/wp-content/uploads';
	private const PATH     = '2024/07/logo-facebook.png';
	private const LOGO     = self::UPLOADS . '/' . self::PATH;
	private const EXTERNAL = 'https://cdn.example.net/share/logo.png';
	private const EDITED   = '2024/07/logo-facebook-e1727380000.png';
	private const SIZES    = [ 'heirloom_og', 'heirloom_twitter', 'full' ];

	/** @var array<string,mixed> */
	private array $store = [];

	private ?WP_Post $queried = null;

	private string $baseurl = self::UPLOADS;

	protected function setUp(): void {
		parent::setUp();
		Images::flush();
		unset( $GLOBALS['wpdb'] );
		$this->store   = [];
		$this->queried = null;
		$this->baseurl = self::UPLOADS;

		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->store[ $name ] ?? $fallback );
		Functions\when( 'wp_get_upload_dir' )->alias( fn() => [ 'baseurl' => $this->baseurl ] );
		foreach ( [ 'is_404', 'is_search', 'is_front_page' ] as $fn ) {
			Functions\when( $fn )->justReturn( false );
		}
		Functions\when( 'is_home' )->alias( fn() => null === $this->queried );
		Functions\when( 'is_singular' )->alias( fn() => null !== $this->queried );
		Functions\when( 'get_queried_object' )->alias( fn() => $this->queried );
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			static fn( $id, $size ) => [ self::UPLOADS . "/att-{$id}-{$size}.png", 1200, 630 ]
		);
	}

	protected function tearDown(): void {
		Images::flush();
		unset( $GLOBALS['wpdb'] );
		// parent::tearDown() verifies the Mockery expectations; count them as assertions.
		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		parent::tearDown();
	}

	// Once per request -----------------------------------------------------

	public function testUrlDefaultIsLookedUpOnceAcrossSizes(): void {
		$this->stubPostMeta();
		$this->stubAttachments( 123 );
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'attachment_url_to_postid' )->once()->with( self::LOGO )->andReturn( 123 );
		Functions\expect( 'set_transient' )->once()->andReturn( true );

		$context = $this->homeContext();
		$options = $this->defaultImage( self::LOGO );
		foreach ( self::SIZES as $size ) {
			$this->assertSame( self::UPLOADS . "/att-123-{$size}.png", Images::forContext( $context, $options, $size )['url'] );
		}
	}

	public function testSourceIsResolvedOncePerRequest(): void {
		// External override: no URL cache involved, so this isolates the source memo.
		Functions\expect( 'get_post_meta' )->once()->with( 7, '_heirloom_seo_og_image', true )->andReturn( self::EXTERNAL );
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'attachment_url_to_postid' )->never();

		$context = $this->postContext( 7 );
		$options = $this->defaultImage( self::LOGO );
		foreach ( self::SIZES as $size ) {
			$this->assertSame( self::EXTERNAL, Images::forContext( $context, $options, $size )['url'] );
		}
	}

	public function testRepeatedUrlIsLookedUpOncePerRequest(): void {
		// Two posts that both fall back to the default: different source-memo keys,
		// same URL — isolates the per-request URL memo.
		$this->stubPostMeta();
		$this->stubAttachments( 123 );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'attachment_url_to_postid' )->once()->andReturn( 123 );
		Functions\expect( 'set_transient' )->once()->andReturn( true );

		$options = $this->defaultImage( self::LOGO );
		$this->assertSame( self::UPLOADS . '/att-123-heirloom_og.png', Images::forContext( $this->postContext( 7 ), $options, 'heirloom_og' )['url'] );
		$this->assertSame( self::UPLOADS . '/att-123-heirloom_og.png', Images::forContext( $this->postContext( 8 ), $options, 'heirloom_og' )['url'] );
	}

	public function testSourceMemoFollowsTheDefaultImageSetting(): void {
		$this->stubPostMeta();
		Functions\expect( 'get_transient' )->once()->andReturn( [ 'id' => 0 ] );
		Functions\expect( 'attachment_url_to_postid' )->never();

		$context = $this->homeContext();
		$this->assertSame( self::LOGO, Images::forContext( $context, $this->defaultImage( self::LOGO ), 'heirloom_og' )['url'] );
		$this->assertSame( self::EXTERNAL, Images::forContext( $context, $this->defaultImage( self::EXTERNAL ), 'heirloom_og' )['url'] );
	}

	public function testUploadsUrlOverrideIsLookedUpOnceAndResizes(): void {
		// The spec's second root cause: a per-post override stored as an uploads URL.
		$path     = '2025/01/override.png';
		$override = self::UPLOADS . '/' . $path;
		Functions\when( 'get_post_meta' )->alias(
			static fn( $post_id, $key ) => match ( $key ) {
				'_heirloom_seo_og_image' => $override,
				'_wp_attached_file'      => $path,
				default                  => '',
			}
		);
		$this->stubAttachments( 555 );
		Functions\expect( 'get_transient' )->once()->with( self::key( $path ) )->andReturn( false );
		Functions\expect( 'attachment_url_to_postid' )->once()->with( $override )->andReturn( 555 );
		Functions\expect( 'set_transient' )
			->once()
			->with(
				self::key( $path ),
				[
					'id'   => 555,
					'file' => $path,
				],
				30 * DAY_IN_SECONDS
			)
			->andReturn( true );

		$context = $this->postContext( 7 );
		$options = $this->defaultImage( self::LOGO );
		foreach ( self::SIZES as $size ) {
			$this->assertSame( self::UPLOADS . "/att-555-{$size}.png", Images::forContext( $context, $options, $size )['url'] );
		}
	}

	public function testNumericDefaultNeverTouchesTheUrlCache(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'attachment_url_to_postid' )->never();

		$image = Images::forContext( $this->homeContext(), $this->defaultImage( '321' ) );

		$this->assertSame( self::UPLOADS . '/att-321-full.png', $image['url'] );
	}

	public function testMetaAndSchemaShareOneLookup(): void {
		// The real call path: og:image, twitter:image and the schema #primaryimage.
		Functions\when( 'has_image_size' )->alias( static fn( $name ) => in_array( $name, [ 'heirloom_og', 'heirloom_twitter' ], true ) );
		$this->stubPostMeta();
		$this->stubAttachments( 123 );
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'attachment_url_to_postid' )->once()->with( self::LOGO )->andReturn( 123 );
		Functions\expect( 'set_transient' )->once()->andReturn( true );

		$options = $this->defaultImage( self::LOGO );
		$context = $this->homeContext();
		$meta    = new Meta( $options );
		$schema  = new Schema( $options );

		$og      = ( fn( $c ) => $this->ogImage( $c ) )->call( $meta, $context );
		$twitter = ( fn( $c ) => $this->twitterImage( $c ) )->call( $meta, $context );
		$primary = ( fn( $c ) => $this->primaryImage( $c, 'https://example.com/' ) )->call( $schema, $context );

		$this->assertSame( self::UPLOADS . '/att-123-heirloom_og.png', $og['url'] );
		$this->assertSame( self::UPLOADS . '/att-123-heirloom_twitter.png', $twitter['url'] );
		$this->assertSame( self::UPLOADS . '/att-123-full.png', $primary['url'] );
	}

	// Across requests (transient) -------------------------------------------

	public function testTransientHitSkipsTheLookup(): void {
		$this->stubPostMeta( [ 123 => self::PATH ] );
		Functions\expect( 'get_transient' )->once()->with( self::key( self::PATH ) )->andReturn( self::hit( 123 ) );
		Functions\expect( 'get_post' )->once()->with( 123 )->andReturn( $this->attachment( 123 ) );
		Functions\expect( 'attachment_url_to_postid' )->never();
		Functions\expect( 'set_transient' )->never();

		$image = Images::forContext( $this->homeContext(), $this->defaultImage( self::LOGO ), 'heirloom_og' );

		$this->assertSame( self::UPLOADS . '/att-123-heirloom_og.png', $image['url'] );
		$this->assertSame( 1200, $image['width'] );
		$this->assertSame( 'Logo', $image['alt'] );
	}

	public function testRememberedMissFallsBackToTheRawUrl(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( [ 'id' => 0 ] );
		Functions\expect( 'get_post' )->never();
		Functions\expect( 'attachment_url_to_postid' )->never();
		Functions\expect( 'set_transient' )->never();

		$image = Images::forContext( $this->homeContext(), $this->defaultImage( self::LOGO ), 'heirloom_og' );

		$this->assertSame(
			[
				'url'    => self::LOGO,
				'width'  => 0,
				'height' => 0,
				'alt'    => '',
			],
			$image
		);
	}

	public function testMissIsLookedUpOnceAndRememberedFor30Days(): void {
		// An error left over from an earlier query must not shorten a hit.
		$GLOBALS['wpdb'] = (object) [ 'last_error' => 'Deadlock found when trying to get lock' ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a stale error, as wpdb reports it.
		$this->stubPostMeta( [ 123 => self::PATH ] );
		$this->stubAttachments( 123 );
		$seen = [];
		Functions\expect( 'get_transient' )->once()->andReturnUsing(
			static function ( $key ) use ( &$seen ) {
				$seen['read'] = $key;
				return false;
			}
		);
		Functions\expect( 'attachment_url_to_postid' )->once()->with( self::LOGO )->andReturn( 123 );
		Functions\expect( 'set_transient' )->once()->andReturnUsing(
			static function ( $key, $value, $ttl ) use ( &$seen ) {
				$seen['write'] = [ $key, $value, $ttl ];
				return true;
			}
		);

		Images::forContext( $this->homeContext(), $this->defaultImage( self::LOGO ), 'heirloom_og' );

		$this->assertMatchesRegularExpression( '/^hseo_u2i_[0-9a-f]{32}$/', $seen['read'] );
		$this->assertSame( [ $seen['read'], self::hit( 123 ), 30 * DAY_IN_SECONDS ], $seen['write'] );
	}

	public function testNotAnAttachmentAnswerIsRememberedForADay(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'attachment_url_to_postid' )->once()->andReturn( 0 );
		Functions\expect( 'set_transient' )->once()->with( self::key( self::PATH ), [ 'id' => 0 ], DAY_IN_SECONDS )->andReturn( true );

		$image = Images::forContext( $this->homeContext(), $this->defaultImage( self::LOGO ), 'heirloom_og' );

		$this->assertSame( self::LOGO, $image['url'] );
	}

	public function testFailedLookupIsRememberedForMinutesNotADay(): void {
		// wpdb reports a killed or timed-out query in last_error; core's lookup then
		// returns 0. Caching that for a day would pin the unresized fallback.
		$GLOBALS['wpdb'] = (object) [ 'last_error' => 'Query execution was interrupted' ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a failed query, as wpdb reports it.
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'attachment_url_to_postid' )->once()->andReturn( 0 );
		Functions\expect( 'set_transient' )->once()->with( self::key( self::PATH ), [ 'id' => 0 ], 5 * MINUTE_IN_SECONDS )->andReturn( true );

		$image = Images::forContext( $this->homeContext(), $this->defaultImage( self::LOGO ), 'heirloom_og' );

		$this->assertSame( self::LOGO, $image['url'] );
	}

	public function testLookupNamingADeletedPostIsRememberedAsAMiss(): void {
		$this->assertLookupIsRememberedAsAMiss( null );
	}

	public function testLookupNamingAnotherPostTypeIsRememberedAsAMiss(): void {
		$this->assertLookupIsRememberedAsAMiss(
			new WP_Post(
				[
					'ID'        => 555,
					'post_type' => 'post',
				]
			)
		);
	}

	public function testExternalUrlSkipsTheCacheAndTheLookup(): void {
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'attachment_url_to_postid' )->never();

		$image = Images::forContext( $this->homeContext(), $this->defaultImage( self::EXTERNAL ), 'heirloom_og' );

		$this->assertSame( self::EXTERNAL, $image['url'] );
	}

	public function testRememberedIdThatWasDeletedIsLookedUpAgain(): void {
		$this->assertStaleEntryIsReplaced( null );
	}

	public function testRememberedIdThatIsNoLongerAnAttachmentIsLookedUpAgain(): void {
		$this->assertStaleEntryIsReplaced(
			new WP_Post(
				[
					'ID'        => 123,
					'post_type' => 'post',
				]
			)
		);
	}

	public function testRememberedIdNowFiledElsewhereIsLookedUpAgain(): void {
		// Edited, re-filed or replaced while the invalidation hooks weren't running
		// (plugin inactive or rolled back): core no longer maps the old URL to it,
		// so neither may the cache.
		$this->stubPostMeta( [ 123 => self::EDITED ] );
		$this->stubAttachments( 123 );
		Functions\expect( 'get_transient' )->once()->andReturn( self::hit( 123 ) );
		Functions\expect( 'attachment_url_to_postid' )->once()->with( self::LOGO )->andReturn( 0 );
		Functions\expect( 'set_transient' )->once()->with( self::key( self::PATH ), [ 'id' => 0 ], DAY_IN_SECONDS )->andReturn( true );

		$image = Images::forContext( $this->homeContext(), $this->defaultImage( self::LOGO ), 'heirloom_og' );

		$this->assertSame( self::LOGO, $image['url'] );
	}

	public function testRememberedMissIsReadOncePerRequest(): void {
		$this->stubPostMeta();
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\expect( 'get_transient' )->once()->andReturn( [ 'id' => 0 ] );
		Functions\expect( 'attachment_url_to_postid' )->never();

		$options = $this->defaultImage( self::LOGO );
		$this->assertSame( self::LOGO, Images::forContext( $this->postContext( 7 ), $options, 'heirloom_og' )['url'] );
		$this->assertSame( self::LOGO, Images::forContext( $this->postContext( 8 ), $options, 'heirloom_og' )['url'] );
	}

	public function testRequestMemoIsBoundedForLongRuns(): void {
		// A WP-CLI loop over many posts with URL overrides must not grow the memo
		// without limit: past 64 URLs it starts over and reads the transients again.
		$reads = [];
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$reads ) {
				$reads[ $key ] = ( $reads[ $key ] ?? 0 ) + 1;
				return [ 'id' => 0 ];
			}
		);
		Functions\expect( 'attachment_url_to_postid' )->never();
		$resolve = fn( int $n ) => Images::forContext( $this->homeContext(), $this->defaultImage( self::UPLOADS . "/bulk/{$n}.png" ), 'full' );

		foreach ( range( 1, 64 ) as $n ) {
			$resolve( $n );
		}
		$resolve( 1 );
		$this->assertSame( 1, $reads[ self::key( 'bulk/1.png' ) ], 'still remembered at 64 URLs' );

		$resolve( 65 );
		$resolve( 1 );
		$this->assertSame( 2, $reads[ self::key( 'bulk/1.png' ) ], 'the 65th URL starts the memo over' );
	}

	public function testFeaturedImageNeverTouchesTheUrlLookup(): void {
		// Regression guard: posts with a thumbnail return before any URL work, even
		// with a URL default configured.
		$this->stubPostMeta();
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 99 );
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'attachment_url_to_postid' )->never();

		$context = $this->postContext( 7 );
		$options = $this->defaultImage( self::LOGO );
		foreach ( self::SIZES as $size ) {
			$this->assertSame( self::UPLOADS . "/att-99-{$size}.png", Images::forContext( $context, $options, $size )['url'] );
		}
	}

	public function testProtocolRelativeUploadsKeepUrlFormsApart(): void {
		// With a protocol-relative uploads base, core resolves "//host/…" but not
		// "https://host/…" (its scheme swap has no scheme to swap to), so the two
		// forms must not share a remembered answer.
		$this->baseurl = '//example.com/wp-content/uploads';
		$relative      = $this->baseurl . '/' . self::PATH;
		$absolute      = 'https:' . $relative;
		Functions\when( 'get_post_meta' )->alias(
			static fn( $post_id, $key ) => match ( $key ) {
				'_heirloom_seo_og_image'   => $absolute,
				'_wp_attachment_image_alt' => 'Logo',
				default                    => '',
			}
		);
		$this->stubAttachments( 123 );
		$read = [];
		Functions\expect( 'get_transient' )->twice()->andReturnUsing(
			static function ( $key ) use ( &$read ) {
				$read[] = $key;
				return false;
			}
		);
		Functions\expect( 'attachment_url_to_postid' )->once()->with( $relative )->andReturn( 123 );
		Functions\expect( 'attachment_url_to_postid' )->once()->with( $absolute )->andReturn( 0 );
		Functions\expect( 'set_transient' )->twice()->andReturn( true );

		$options = $this->defaultImage( $relative );
		$this->assertSame( self::UPLOADS . '/att-123-heirloom_og.png', Images::forContext( $this->homeContext(), $options, 'heirloom_og' )['url'] );
		$this->assertSame( $absolute, Images::forContext( $this->postContext( 7 ), $options, 'heirloom_og' )['url'] );
		$this->assertNotSame( $read[0], $read[1] );
	}

	// Invalidation ----------------------------------------------------------

	public function testDeletingAnAttachmentForgetsItsPathAndBothUrls(): void {
		// A cache-busting URL filter makes the current URL's key differ from the path's.
		$scaled = '2026/09/photo-scaled.jpg';
		$this->stubPostMeta( [ 42 => $scaled ] );
		Functions\expect( 'wp_get_attachment_url' )->once()->with( 42 )->andReturn( self::UPLOADS . '/' . $scaled . '?v=2' );
		Functions\expect( 'wp_get_original_image_url' )->once()->with( 42 )->andReturn( self::UPLOADS . '/2026/09/photo.jpg' );
		Functions\expect( 'delete_transient' )->once()->with( self::key( $scaled ) )->andReturn( true );
		Functions\expect( 'delete_transient' )->once()->with( self::key( $scaled . '?v=2' ) )->andReturn( true );
		Functions\expect( 'delete_transient' )->once()->with( self::key( '2026/09/photo.jpg' ) )->andReturn( true );

		Images::onAttachmentDeleted( 42 );
	}

	public function testDeletingAnOrdinaryImageForgetsOneKey(): void {
		// Unfiltered and never scaled, its path, URL and "original" URL are one key:
		// one delete, not three (each is a query on a site without an object cache).
		$this->stubPostMeta( [ 42 => self::PATH ] );
		Functions\when( 'wp_get_attachment_url' )->justReturn( self::LOGO );
		Functions\when( 'wp_get_original_image_url' )->justReturn( self::LOGO );
		Functions\expect( 'delete_transient' )->once()->with( self::key( self::PATH ) )->andReturn( true );

		Images::onAttachmentDeleted( 42 );
	}

	public function testInvalidationTargetsTheKeyTheLookupUsedEvenUnderAUrlFilter(): void {
		// Round trip with no knowledge of the key format, while a CDN/offload plugin
		// rewrites wp_get_attachment_url(): the entry a lookup writes is the one a
		// deletion forgets.
		$this->stubPostMeta( [ 123 => self::PATH ] );
		$this->stubAttachments( 123 );
		$seen = [ 'forgotten' => [] ];
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'attachment_url_to_postid' )->once()->andReturn( 123 );
		Functions\expect( 'set_transient' )->once()->andReturnUsing(
			static function ( $key ) use ( &$seen ) {
				$seen['cached'] = $key;
				return true;
			}
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://cdn.example.net/' . self::PATH );
		Functions\when( 'wp_get_original_image_url' )->justReturn( false );
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$seen ) {
				$seen['forgotten'][] = $key;
				return true;
			}
		);

		Images::forContext( $this->homeContext(), $this->defaultImage( self::LOGO ), 'heirloom_og' );
		Images::onAttachmentDeleted( 123 );

		$this->assertContains( $seen['cached'], $seen['forgotten'] );
	}

	public function testAddingAnAttachmentForgetsItsPathAndUrl(): void {
		$this->stubPostMeta( [ 43 => self::PATH ] );
		Functions\expect( 'wp_get_attachment_url' )->once()->with( 43 )->andReturn( self::LOGO . '?v=2' );
		Functions\expect( 'delete_transient' )->once()->with( self::key( self::PATH ) )->andReturn( true );
		Functions\expect( 'delete_transient' )->once()->with( self::key( self::PATH . '?v=2' ) )->andReturn( true );

		Images::onAttachmentAdded( 43 );
	}

	public function testRefilingForgetsThePathLeftAndThePathTaken(): void {
		// Before the write the old path is still readable; after it, the new one is
		// passed in. A big upload re-filed as "-scaled" and image-editor saves both
		// happen after add_attachment has fired.
		$this->stubPostMeta( [ 43 => self::PATH ] );
		Functions\expect( 'delete_transient' )->once()->with( self::key( self::PATH ) )->andReturn( true );
		Functions\expect( 'delete_transient' )->once()->with( self::key( self::EDITED ) )->andReturn( true );

		Images::onAttachedFileLeaving( 991, 43, '_wp_attached_file' );
		Images::onAttachedFileArrived( 991, 43, '_wp_attached_file', self::EDITED );
	}

	public function testEditingTheImageDropsTheOldUrlsRememberedAnswer(): void {
		// The image editor re-files attachment 123, after which core maps the old URL
		// to nothing and an uncached lookup falls back to the raw URL at once. The
		// cache must do the same, not keep serving the old mapping for 30 days.
		$this->stubPostMeta( [ 123 => self::PATH ] );
		$this->stubAttachments( 123 );
		$store = [];
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				unset( $store[ $key ] );
				return true;
			}
		);
		Functions\expect( 'attachment_url_to_postid' )->twice()->with( self::LOGO )->andReturn( 123, 0 );

		$context = $this->homeContext();
		$options = $this->defaultImage( self::LOGO );
		$this->assertSame( self::UPLOADS . '/att-123-heirloom_og.png', Images::forContext( $context, $options, 'heirloom_og' )['url'] );

		Images::onAttachedFileLeaving( 991, 123, '_wp_attached_file' );
		Images::onAttachedFileArrived( 991, 123, '_wp_attached_file', self::EDITED );

		$this->assertSame( self::LOGO, Images::forContext( $context, $options, 'heirloom_og' )['url'] );
	}

	public function testOtherMetaWritesAreIgnored(): void {
		Functions\expect( 'get_post_meta' )->never();
		Functions\expect( 'delete_transient' )->never();

		Images::onAttachedFileLeaving( 992, 43, '_edit_lock' );
		Images::onAttachedFileArrived( 993, 43, '_edit_last', '1' ); // a string value, so only the key check can stop it
	}

	public function testInvalidationAlsoClearsTheRequestMemo(): void {
		// A long-running process (WP-CLI) that deletes the image must not keep
		// serving the memoized ID for the rest of the run.
		$this->stubPostMeta( [ 123 => self::PATH ] );
		$this->stubAttachments( 123 );
		Functions\expect( 'get_transient' )->twice()->andReturn( false );
		Functions\expect( 'attachment_url_to_postid' )->twice()->andReturn( 123, 0 );
		Functions\expect( 'set_transient' )->twice()->andReturn( true );
		Functions\when( 'wp_get_attachment_url' )->justReturn( self::LOGO );
		Functions\when( 'wp_get_original_image_url' )->justReturn( self::LOGO );
		Functions\when( 'delete_transient' )->justReturn( true );

		$context = $this->homeContext();
		$options = $this->defaultImage( self::LOGO );
		$this->assertSame( self::UPLOADS . '/att-123-heirloom_og.png', Images::forContext( $context, $options, 'heirloom_og' )['url'] );

		Images::onAttachmentDeleted( 123 );

		$this->assertSame( self::LOGO, Images::forContext( $context, $options, 'heirloom_og' )['url'] );
	}

	public function testRegistersTheInvalidationHooks(): void {
		Actions\expectAdded( 'add_attachment' )->once()->with( [ Images::class, 'onAttachmentAdded' ], 10, 1 );
		Actions\expectAdded( 'delete_attachment' )->once()->with( [ Images::class, 'onAttachmentDeleted' ], 10, 1 );
		Actions\expectAdded( 'update_post_meta' )->once()->with( [ Images::class, 'onAttachedFileLeaving' ], 10, 3 );
		Actions\expectAdded( 'delete_post_meta' )->once()->with( [ Images::class, 'onAttachedFileLeaving' ], 10, 3 );
		Actions\expectAdded( 'added_post_meta' )->once()->with( [ Images::class, 'onAttachedFileArrived' ], 10, 4 );
		Actions\expectAdded( 'updated_post_meta' )->once()->with( [ Images::class, 'onAttachedFileArrived' ], 10, 4 );

		Images::registerCacheInvalidation();
	}

	// Helpers ---------------------------------------------------------------

	private function assertStaleEntryIsReplaced( ?WP_Post $post ): void {
		$this->stubPostMeta(
			[
				123 => self::PATH,
				456 => self::PATH,
			]
		);
		Functions\expect( 'get_transient' )->once()->andReturn( self::hit( 123 ) );
		Functions\expect( 'get_post' )->once()->with( 123 )->andReturn( $post );
		Functions\expect( 'get_post' )->once()->with( 456 )->andReturn( $this->attachment( 456 ) );
		Functions\expect( 'attachment_url_to_postid' )->once()->with( self::LOGO )->andReturn( 456 );
		Functions\expect( 'set_transient' )->once()->with( self::key( self::PATH ), self::hit( 456 ), 30 * DAY_IN_SECONDS )->andReturn( true );

		$image = Images::forContext( $this->homeContext(), $this->defaultImage( self::LOGO ), 'heirloom_og' );

		$this->assertSame( self::UPLOADS . '/att-456-heirloom_og.png', $image['url'] );
	}

	/**
	 * Core's lookup reads postmeta only, so a stray or orphaned _wp_attached_file
	 * row can name a post that is gone or isn't an attachment. Kept as a hit, it
	 * would fail the re-check and be looked up again on every request. A stale
	 * wpdb error (core's answer was not 0) must not turn it into a failure.
	 */
	private function assertLookupIsRememberedAsAMiss( ?WP_Post $post ): void {
		$GLOBALS['wpdb'] = (object) [ 'last_error' => 'Deadlock found when trying to get lock' ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a stale error, as wpdb reports it.
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'attachment_url_to_postid' )->once()->andReturn( 555 );
		Functions\expect( 'get_post' )->once()->with( 555 )->andReturn( $post );
		Functions\expect( 'set_transient' )->once()->with( self::key( self::PATH ), [ 'id' => 0 ], DAY_IN_SECONDS )->andReturn( true );

		$image = Images::forContext( $this->homeContext(), $this->defaultImage( self::LOGO ), 'heirloom_og' );

		$this->assertSame( self::LOGO, $image['url'] );
	}

	/** Mirrors Images::cacheKey(); the round-trip test checks keys without this helper. */
	private static function key( string $path ): string {
		return Images::CACHE_PREFIX . md5( $path );
	}

	/** A remembered hit on self::PATH, as lookUp() writes it. */
	private static function hit( int $id ): array {
		return [
			'id'   => $id,
			'file' => self::PATH,
		];
	}

	/**
	 * For tests that make no count claim about get_post_meta: no override, a fixed
	 * alt text, and the given attachments' file paths.
	 *
	 * @param array<int,string> $files Attachment ID => _wp_attached_file.
	 */
	private function stubPostMeta( array $files = [] ): void {
		Functions\when( 'get_post_meta' )->alias(
			static fn( $post_id, $key ) => match ( $key ) {
				'_wp_attachment_image_alt' => 'Logo',
				'_wp_attached_file'        => $files[ (int) $post_id ] ?? '',
				default                    => '',
			}
		);
	}

	/** For tests that make no count claim about get_post: these IDs are attachments. */
	private function stubAttachments( int ...$ids ): void {
		Functions\when( 'get_post' )->alias( fn( $id ) => in_array( (int) $id, $ids, true ) ? $this->attachment( (int) $id ) : null );
	}

	private function attachment( int $id ): WP_Post {
		return new WP_Post(
			[
				'ID'        => $id,
				'post_type' => 'attachment',
			]
		);
	}

	private function defaultImage( string $url ): Options {
		$this->store['heirloom_seo'] = [ 'social' => [ 'default_image' => $url ] ];
		return new Options();
	}

	/** A context with no post (home, archives, search, 404). Resolves lazily — use it right away. */
	private function homeContext(): Context {
		$this->queried = null;
		return new Context();
	}

	/** A singular context. Resolves lazily — use it before creating another. */
	private function postContext( int $id ): Context {
		$this->queried = new WP_Post( [ 'ID' => $id ] );
		return new Context();
	}
}
