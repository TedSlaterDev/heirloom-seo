<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests\Migration;

use Brain\Monkey\Functions;
use OrchardGrove\HeirloomSeo\Cli\Commands;
use OrchardGrove\HeirloomSeo\Migration\Ajax;
use OrchardGrove\HeirloomSeo\Migration\Yoast;
use OrchardGrove\HeirloomSeo\Plugin;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname( __DIR__ ) . '/Support/WpCli.php';

/**
 * Yoast adds its RSS "before" / "after" text to feed items (wpseo_titles
 * rssbefore / rssafter; the wpseo_rss option before Yoast 7.0). A site that
 * had cleared both switched to Heirloom and its feed, and the newsletters
 * built from it, gained "The post … appeared first on …" on every item:
 * Heirloom's attribution line is on by default. The setting is now carried
 * over on the first activation and with a Yoast import, unless Heirloom's
 * own setting was already saved.
 *
 * Yoast's site verification codes (wpseo: googleverify, msverify, baiduverify;
 * wpseo_social: pinterestverify) were not carried over either, so a search
 * console verified by Yoast's meta tag lost the tag on the switch. They now
 * fill Heirloom's empty verification fields at the same points.
 */
final class YoastSettingsTest extends TestCase {

	private const YANDEX_WARNING = 'Heirloom SEO has no setting for these Yoast site verification codes: Yandex. Without Yoast their meta tags are gone, so verify the site with those services another way (a DNS record, for example).';

	/** @var array<string,mixed> */
	private array $store = [];

	/** @var string[] option names written */
	private array $writes = [];

	private string $uploads = '';

	protected function setUp(): void {
		parent::setUp();
		$this->store   = [ 'blog_public' => '1' ];
		$this->writes  = [];
		$this->uploads = sys_get_temp_dir() . '/heirloom-yoast-' . bin2hex( random_bytes( 4 ) );
		\WP_CLI::$messages = [];

		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->store[ $name ] ?? $fallback );
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '' ) {
				if ( array_key_exists( $name, $this->store ) ) {
					return false;
				}
				$this->store[ $name ] = $value;
				$this->writes[]       = $name;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				$this->writes[]       = $name;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->store[ $name ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		if ( is_dir( $this->uploads ) ) {
			foreach ( (array) glob( $this->uploads . '/*/*' ) as $file ) {
				unlink( (string) $file );
			}
			foreach ( (array) glob( $this->uploads . '/*' ) as $dir ) {
				rmdir( (string) $dir );
			}
			rmdir( $this->uploads );
		}
		unset( $GLOBALS['wpdb'], $_POST['op'], $_POST['source'], $_POST['offset'] );
		parent::tearDown();
	}

	public function testClearedYoastRssTextTurnsTheAttributionLineOff(): void {
		$this->store['wpseo_titles']   = [ 'title-post' => '%%title%%', 'rssbefore' => '', 'rssafter' => '' ];
		$this->store[ Options::OPTION ] = [ 'titles' => [ 'post' => '%title%' ] ];

		self::assertSame( [ 'feed.rss_attribution' ], Yoast::carryOverSettings() );

		self::assertSame( [ 'titles' => [ 'post' => '%title%' ], 'feed' => [ 'rss_attribution' => false ] ], $this->store[ Options::OPTION ], 'other stored settings are kept' );
		$options = new Options();
		self::assertFalse( $options->bool( 'feed.rss_attribution' ) );
		self::assertSame( 'The post %post_link% appeared first on %site_link%.', $options->str( 'feed.rss_text' ), 'the text keeps its default, for whoever turns the line back on' );
	}

	/** @param array<string,string> $titles */
	#[DataProvider( 'yoastAddsText' )]
	public function testYoastTextInEitherFieldKeepsHeirloomsDefault( array $titles ): void {
		$this->store['wpseo_titles'] = $titles;

		self::assertSame( [], Yoast::carryOverSettings() );
		self::assertSame( [], $this->writes );
		self::assertTrue( ( new Options() )->bool( 'feed.rss_attribution' ) );
	}

	/** @return array<string,array{array<string,string>}> */
	public static function yoastAddsText(): array {
		return [
			'Yoast default "after" line'       => [ [ 'rssbefore' => '', 'rssafter' => 'The post %%POSTLINK%% appeared first on %%BLOGLINK%%.' ] ],
			'"before" text only'               => [ [ 'rssbefore' => 'From %%BLOGLINK%%', 'rssafter' => '' ] ],
			'"after" never stored: its default' => [ [ 'rssbefore' => '' ] ],
		];
	}

	public function testWhitespaceOnlyTextCountsAsCleared(): void {
		$this->store['wpseo_titles'] = [ 'rssbefore' => "  \n", 'rssafter' => ' ' ];

		self::assertTrue( Yoast::rssTextCleared() );
	}

	public function testReadsWpseoRssWhereYoastPredatesItsMoveToWpseoTitles(): void {
		$this->store['wpseo_titles'] = [ 'title-post' => '%%title%%' ];
		$this->store['wpseo_rss']    = [ 'rssbefore' => '', 'rssafter' => '' ];
		self::assertTrue( Yoast::rssTextCleared() );

		$this->store['wpseo_titles']['rssafter'] = 'The post %%POSTLINK%% appeared first on %%BLOGLINK%%.';
		self::assertFalse( Yoast::rssTextCleared(), 'wpseo_titles is where Yoast 7.0+ reads it; a leftover wpseo_rss is ignored' );
	}

	public function testNoYoastSettingsChangeNothing(): void {
		self::assertSame( [], Yoast::carryOverSettings() );
		self::assertSame( [], $this->writes );
	}

	#[DataProvider( 'savedValues' )]
	public function testAnAttributionSettingHeirloomAlreadySavedIsNeverChanged( bool $saved ): void {
		$this->store['wpseo_titles']    = [ 'rssbefore' => '', 'rssafter' => '' ];
		$this->store[ Options::OPTION ] = [ 'feed' => [ 'rss_attribution' => $saved ] ];

		self::assertSame( [], Yoast::carryOverSettings() );
		self::assertSame( [], $this->writes );
		self::assertSame( [ 'feed' => [ 'rss_attribution' => $saved ] ], $this->store[ Options::OPTION ] );
	}

	/** @return array<string,array{bool}> */
	public static function savedValues(): array {
		return [
			'saved on'  => [ true ],
			'saved off' => [ false ],
		];
	}

	public function testDryRunReportsWithoutWriting(): void {
		$this->store['wpseo_titles'] = [ 'rssbefore' => '', 'rssafter' => '' ];

		self::assertSame( [ 'feed.rss_attribution' ], Yoast::carryOverSettings( true ) );
		self::assertSame( [], $this->writes );
	}

	public function testFirstActivationCarriesTheSettingsOver(): void {
		$this->store['wpseo_titles'] = [ 'rssbefore' => '', 'rssafter' => '' ];
		$this->store['wpseo']        = [ 'googleverify' => 'abc-123' ];
		$this->stubActivation();

		Plugin::activate();

		self::assertSame( [ 'feed' => [ 'rss_attribution' => false ], 'verification' => [ 'google' => 'abc-123' ] ], $this->store[ Options::OPTION ] );
	}

	public function testActivatingAnExistingInstallLeavesItsSettingsAlone(): void {
		$this->store['wpseo_titles']    = [ 'rssbefore' => '', 'rssafter' => '' ];
		$this->store['wpseo']           = [ 'googleverify' => 'abc-123' ];
		$this->store[ Options::OPTION ] = []; // Installed before, settings never saved.
		$this->stubActivation();

		Plugin::activate();

		self::assertSame( [], $this->store[ Options::OPTION ] );
		self::assertNotContains( Options::OPTION, $this->writes );
	}

	public function testAdminImportCarriesThemOverWithTheFirstBatchAndSaysSo(): void {
		$this->store['wpseo_titles']    = [ 'rssbefore' => '', 'rssafter' => '' ];
		$this->store['wpseo']           = [ 'googleverify' => 'abc-123', 'yandexverify' => '0f0f' ];
		$this->store[ Options::OPTION ] = [];
		$this->stubImport();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		$sent = null;
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data ) use ( &$sent ) {
				$sent = $data;
				throw new \RuntimeException( 'sent' ); // Stops like the real one (it exits).
			}
		);

		$import = static function ( string $source, int $offset ) use ( &$sent ): array {
			$_POST['op']     = 'import';
			$_POST['source'] = $source;
			$_POST['offset'] = (string) $offset;
			try {
				( new Ajax() )->handle();
			} catch ( \RuntimeException $e ) {
				self::assertSame( 'sent', $e->getMessage() );
			}
			return $sent['notes'];
		};

		self::assertSame( [], $import( 'yoast', 100 ) );
		self::assertSame( [], $import( 'rankmath', 0 ) );
		self::assertSame( [], $this->store[ Options::OPTION ], 'a later batch, or another source, carries nothing over' );

		self::assertSame(
			[
				"Turned off the RSS attribution line: Yoast's RSS before/after text is empty, so its feed had none.",
				"Carried over Yoast's site verification codes: Google.",
				self::YANDEX_WARNING,
			],
			$import( 'yoast', 0 )
		);
		self::assertSame( [ 'feed' => [ 'rss_attribution' => false ], 'verification' => [ 'google' => 'abc-123' ] ], $this->store[ Options::OPTION ] );
	}

	public function testCliImportCarriesItOverAndSaysSo(): void {
		$this->store['wpseo_titles'] = [ 'rssbefore' => '', 'rssafter' => '' ];
		$this->stubImport();

		( new Commands() )->import( [ 'yoast' ], [ 'dry-run' => '' ] );
		self::assertArrayNotHasKey( Options::OPTION, $this->store );
		self::assertContains( [ 'log', "Would turn off the RSS attribution line: Yoast's RSS before/after text is empty, so its feed had none." ], \WP_CLI::$messages );

		\WP_CLI::$messages = [];
		( new Commands() )->import( [ 'yoast' ], [] );
		self::assertSame( [ 'feed' => [ 'rss_attribution' => false ] ], $this->store[ Options::OPTION ] );
		self::assertContains( [ 'log', "Turned off the RSS attribution line: Yoast's RSS before/after text is empty, so its feed had none." ], \WP_CLI::$messages );

		\WP_CLI::$messages = [];
		( new Commands() )->import( [ 'yoast' ], [] );
		self::assertSame( [ 'success', 'Heirloom SEO imported data for 0 posts from Yoast SEO.' ], \WP_CLI::$messages[0] ?? null, 'nothing to say the second time' );
	}

	public function testCarriesOverYoastsVerificationCodes(): void {
		$this->store['wpseo']        = [ 'googleverify' => 'aBc-12_3', 'msverify' => '0A1B2C3D', 'baiduverify' => 'codeva-x1', 'yandexverify' => '' ];
		$this->store['wpseo_social'] = [ 'pinterestverify' => 'f00dfeed' ];

		self::assertSame( [ 'verification.google', 'verification.bing', 'verification.pinterest', 'verification.baidu' ], Yoast::carryOverSettings() );

		$options = new Options();
		self::assertSame( 'aBc-12_3', $options->str( 'verification.google' ) );
		self::assertSame( '0A1B2C3D', $options->str( 'verification.bing' ) );
		self::assertSame( 'f00dfeed', $options->str( 'verification.pinterest' ) );
		self::assertSame( 'codeva-x1', $options->str( 'verification.baidu' ) );
		self::assertTrue( $options->bool( 'feed.rss_attribution' ), 'no Yoast RSS settings: the feed keeps its default' );
		self::assertSame( [], Yoast::carryOverSettings(), 'a second run finds nothing to fill' );
	}

	public function testACodeEnteredInHeirloomStandsAndAnEmptyFieldIsFilled(): void {
		$this->store['wpseo'] = [ 'googleverify' => 'yoast-code', 'msverify' => 'ABCDEF' ];
		// The tab with the codes saved once, Google entered and Bing left empty.
		$this->store[ Options::OPTION ] = [ 'verification' => [ 'google' => 'heirloom-code', 'bing' => '', 'pinterest' => '', 'baidu' => '' ] ];

		self::assertSame( [ 'verification.bing' ], Yoast::carryOverSettings() );
		self::assertSame( [ 'google' => 'heirloom-code', 'bing' => 'ABCDEF', 'pinterest' => '', 'baidu' => '' ], $this->store[ Options::OPTION ]['verification'] );
	}

	public function testDryRunListsTheCodesWithoutWriting(): void {
		$this->store['wpseo'] = [ 'googleverify' => 'abc' ];

		self::assertSame( [ 'verification.google' ], Yoast::carryOverSettings( true ) );
		self::assertSame( [], $this->writes );
	}

	#[DataProvider( 'notACode' )]
	public function testOnlyACodeIsCarriedOver( mixed $stored ): void {
		$this->store['wpseo'] = [ 'googleverify' => $stored ];

		self::assertSame( [], Yoast::verificationCodes() );
		self::assertSame( [], Yoast::carryOverSettings() );
	}

	/** @return array<string,array{mixed}> */
	public static function notACode(): array {
		return [
			'empty'            => [ '' ],
			'a whole meta tag' => [ '<meta name="google-site-verification" content="abc" />' ],
			'two words'        => [ 'abc def' ],
			'not a string'     => [ [ 'abc' ] ],
		];
	}

	public function testSurroundingWhitespaceIsTrimmed(): void {
		$this->store['wpseo'] = [ 'googleverify' => " abc-123\n" ];

		self::assertSame( [ 'google' => 'abc-123' ], Yoast::verificationCodes() );
	}

	public function testReportSaysWhatChangedAndWhatCouldNotComeOver(): void {
		$changed = [ 'feed.rss_attribution', 'verification.google', 'verification.baidu' ];

		self::assertSame(
			[
				'notes'    => [
					"Turned off the RSS attribution line: Yoast's RSS before/after text is empty, so its feed had none.",
					"Carried over Yoast's site verification codes: Google, Baidu.",
				],
				'warnings' => [],
			],
			Yoast::carryOverReport( $changed )
		);
		self::assertSame(
			[
				"Would turn off the RSS attribution line: Yoast's RSS before/after text is empty, so its feed had none.",
				"Would carry over Yoast's site verification codes: Google, Baidu.",
			],
			Yoast::carryOverReport( $changed, true )['notes']
		);
		self::assertSame( [ 'notes' => [], 'warnings' => [] ], Yoast::carryOverReport( [] ) );

		$this->store['wpseo'] = [ 'yandexverify' => '0f0f', 'ahrefsverify' => 'ahrefs-site-verification_1a' ];
		self::assertSame( [ 'Heirloom SEO has no setting for these Yoast site verification codes: Yandex, Ahrefs. Without Yoast their meta tags are gone, so verify the site with those services another way (a DNS record, for example).' ], Yoast::carryOverReport( [] )['warnings'] );
	}

	public function testCliImportCarriesTheCodesOverAndWarnsAboutTheRest(): void {
		$this->store['wpseo'] = [ 'googleverify' => 'abc', 'msverify' => 'ABC123', 'yandexverify' => '0f0f' ];
		$this->stubImport();

		( new Commands() )->import( [ 'yoast' ], [] );

		self::assertSame( [ 'verification' => [ 'google' => 'abc', 'bing' => 'ABC123' ] ], $this->store[ Options::OPTION ] );
		self::assertSame(
			[
				[ 'log', "Carried over Yoast's site verification codes: Google, Bing." ],
				[ 'warning', self::YANDEX_WARNING ],
				[ 'success', 'Heirloom SEO imported data for 0 posts from Yoast SEO.' ],
			],
			\WP_CLI::$messages
		);
	}

	// Title formats and separator ----------------------------------------------

	/** Yoast's stored defaults (English), latest posts on the front page. */
	private const YOAST_TITLES = [
		'separator'           => 'sc-dash',
		'title-home-wpseo'    => '%%sitename%% %%page%% %%sep%% %%sitedesc%%',
		'title-author-wpseo'  => '%%name%%, Author at %%sitename%% %%page%% ',
		'title-archive-wpseo' => '%%date%% %%page%% %%sep%% %%sitename%%',
		'title-search-wpseo'  => 'You searched for %%searchphrase%% %%page%% %%sep%% %%sitename%%',
		'title-404-wpseo'     => 'Page not found %%sep%% %%sitename%%',
		'title-post'          => '%%title%% %%page%% %%sep%% %%sitename%%',
		'title-page'          => '%%title%% %%page%% %%sep%% %%sitename%%',
		'title-tax-category'  => '%%term_title%% Archives %%page%% %%sep%% %%sitename%%',
		'title-tax-post_tag'  => '%%term_title%% Archives %%page%% %%sep%% %%sitename%%',
		'rssbefore'           => '',
		'rssafter'            => 'The post %%POSTLINK%% appeared first on %%BLOGLINK%%.',
	];

	public function testCarriesOverYoastsTitleFormatsAndSeparator(): void {
		$this->store['wpseo_titles'] = self::YOAST_TITLES;

		self::assertSame(
			[ 'general.separator', 'titles.front', 'titles.post', 'titles.page', 'titles.term', 'titles.author', 'titles.archive', 'titles.search' ],
			Yoast::carryOverSettings(),
			'the 404 format is Heirloom\'s default already; no posts page with latest posts on the front page'
		);
		self::assertSame(
			[
				'general' => [ 'separator' => '-' ],
				'titles'  => [
					'front'   => '%sitename% %pagination% %sep% %tagline%',
					'post'    => '%title% %pagination% %sep% %sitename%',
					'page'    => '%title% %pagination% %sep% %sitename%',
					'term'    => '%term_title% Archives %pagination% %sep% %sitename%',
					'author'  => '%author%, Author at %sitename% %pagination%',
					'archive' => '%archive_title% %pagination% %sep% %sitename%',
					'search'  => 'You searched for %search% %pagination% %sep% %sitename%',
				],
			],
			$this->store[ Options::OPTION ]
		);
		self::assertSame( [], Yoast::carryOverSettings(), 'a second run finds nothing to change' );
	}

	public function testWithAStaticFrontPageYoastTitledItAndThePostsPageAsPages(): void {
		$this->store['show_on_front'] = 'page';
		$this->store['wpseo_titles']  = [ 'title-page' => '%%title%% %%sep%% %%sitename%%', 'title-home-wpseo' => 'Unused %%sitename%%' ];

		self::assertSame( [ 'titles.front', 'titles.home' ], Yoast::carryOverSettings() );
		self::assertSame(
			[ 'front' => '%title% %sep% %sitename%', 'home' => '%title% %sep% %sitename%' ],
			$this->store[ Options::OPTION ]['titles'],
			'Pages: the same as Heirloom\'s default, so not stored'
		);
	}

	public function testAnEditedFormatOrSeparatorStandsAndADefaultOneIsReplaced(): void {
		$this->store['wpseo_titles']    = self::YOAST_TITLES;
		$defaults                       = ( new Options() )->defaults();
		// Both tabs saved once: every field stored, Posts and the separator edited.
		$this->store[ Options::OPTION ] = [
			'general' => [ 'separator' => '|' ],
			'titles'  => [ 'post' => '%title% | My Site' ] + $defaults['titles'],
		];

		$changed = Yoast::carryOverSettings();

		self::assertNotContains( 'general.separator', $changed );
		self::assertNotContains( 'titles.post', $changed );
		self::assertContains( 'titles.page', $changed );
		self::assertSame( '|', $this->store[ Options::OPTION ]['general']['separator'] );
		self::assertSame( '%title% | My Site', $this->store[ Options::OPTION ]['titles']['post'] );
		self::assertSame( '%title% %pagination% %sep% %sitename%', $this->store[ Options::OPTION ]['titles']['page'] );
		self::assertSame( $defaults['titles']['home'], $this->store[ Options::OPTION ]['titles']['home'], 'kept as stored' );
	}

	public function testAFormatWithAVariableHeirloomCannotPrintIsLeftOutAndNamed(): void {
		$this->store['wpseo_titles'] = [
			'title-post'         => '%%title%% (%%currentyear%%) %%sep%% %%sitename%%',
			'title-search-wpseo' => '%%searchphrase%% %%pt_single%% %%focuskw%% %%sep%% %%sitename%%',
			'title-page'         => '%%title%% %%page%% %%sep%% %%sitename%%',
		];

		self::assertSame( [ 'titles.page' ], Yoast::carryOverSettings() );
		self::assertSame(
			[ "Heirloom SEO has no variable for some of Yoast's title formats, so these keep Heirloom SEO's own: Posts (%%currentyear%%), Search (%%pt_single%% %%focuskw%%). Edit them under Titles if they should match." ],
			Yoast::carryOverReport( [ 'titles.page' ] )['warnings']
		);

		$this->store[ Options::OPTION ]['titles']['post'] = '%title% %sep% %sitename%';
		self::assertSame(
			[ "Heirloom SEO has no variable for some of Yoast's title formats, so these keep Heirloom SEO's own: Search (%%pt_single%% %%focuskw%%). Edit them under Titles if they should match." ],
			Yoast::carryOverReport( [] )['warnings'],
			'Posts was edited in Heirloom, so nothing changes there'
		);
	}

	public function testTheDateVariableComesOverOnlyInTheArchiveFormat(): void {
		$this->store['wpseo_titles'] = [
			'title-archive-wpseo' => '%%date%% %%page%% %%sep%% %%sitename%%',
			'title-post'          => '%%title%% %%sep%% %%date%%',
		];

		self::assertSame( [ 'titles.archive' ], Yoast::carryOverSettings() );
		self::assertSame( '%archive_title% %pagination% %sep% %sitename%', $this->store[ Options::OPTION ]['titles']['archive'] );
	}

	public function testEmptyOrMissingFormatsKeepHeirloomsOwn(): void {
		$this->store['wpseo_titles'] = [ 'title-post' => '  ', 'title-page' => null, 'separator' => 'sc-unknown' ];

		self::assertSame( [], Yoast::carryOverSettings() );
	}

	#[DataProvider( 'separators' )]
	public function testEverySeparatorYoastOffers( string $choice, string $printed ): void {
		$this->store['wpseo_titles'] = [ 'separator' => $choice ];

		self::assertSame( '–' === $printed ? [] : [ 'general.separator' ], Yoast::carryOverSettings() );
		self::assertSame( $printed, ( new Options() )->str( 'general.separator' ) );
	}

	/** @return array<string,array{string,string}> What Yoast prints for each choice (its list's own entities, decoded). */
	public static function separators(): array {
		$rows = [];
		foreach ( [
			'sc-dash'   => '-',
			'sc-ndash'  => '&#8211;',
			'sc-mdash'  => '&#8212;',
			'sc-colon'  => ':',
			'sc-middot' => '&#183;',
			'sc-bull'   => '&#8226;',
			'sc-star'   => '*',
			'sc-smstar' => '&#8902;',
			'sc-pipe'   => '|',
			'sc-tilde'  => '~',
			'sc-laquo'  => '&#171;',
			'sc-raquo'  => '&#187;',
			'sc-lt'     => '&#062;',
			'sc-gt'     => '&#060;',
		] as $choice => $entity ) {
			$rows[ $choice ] = [ $choice, html_entity_decode( $entity, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ];
		}
		return $rows;
	}

	public function testATagFormatUnlikeTheCategoryOneIsFlagged(): void {
		$this->store['wpseo_titles'] = [
			'title-tax-category' => '%%term_title%% Archives %%sep%% %%sitename%%',
			'title-tax-post_tag' => 'Tagged %%term_title%% %%sep%% %%sitename%%',
		];

		$changed = Yoast::carryOverSettings();
		self::assertSame( [ 'titles.term' ], $changed );
		self::assertSame(
			[ "Heirloom SEO has one title format for category and tag archives. Yoast's category format was carried over; Yoast had a different one for tags, so tag archive titles change." ],
			Yoast::carryOverReport( $changed )['warnings']
		);
		self::assertSame( [], Yoast::carryOverReport( [] )['warnings'], 'nothing carried over, nothing to flag' );
	}

	public function testReportNamesTheSeparatorAndFormats(): void {
		$this->store['wpseo_titles'] = self::YOAST_TITLES;
		$changed                     = Yoast::carryOverSettings( true );

		self::assertSame(
			[
				"Would carry over Yoast's title separator (-).",
				"Would carry over Yoast's title formats: Front page, Posts, Pages, Category / tag, Author, Archives, Search.",
			],
			Yoast::carryOverReport( $changed, true )['notes']
		);
		self::assertSame( [], $this->writes );
	}

	public function testAPostsOwnYoastTitleUsesTheSameVariables(): void {
		$source    = new Yoast();
		$translate = ( new \ReflectionMethod( $source, 'translateVars' ) )->getClosure( $source );

		self::assertSame( '%title% %pagination% %sep% %sitename% %category% %category%', $translate( '%%title%% %%page%% %%sep%% %%sitename%% %%primary_category%% %%tag%%' ) );
	}

	/** Plugin::activate() also prepares the cache dir, llms.txt and the IndexNow key file. */
	private function stubActivation(): void {
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => $this->uploads ] );
		Functions\when( 'trailingslashit' )->alias( static fn( $p ) => rtrim( (string) $p, '/' ) . '/' );
		Functions\when( 'wp_mkdir_p' )->alias( static fn( $p ) => is_dir( $p ) || mkdir( $p, 0777, true ) );
		Functions\when( 'wp_next_scheduled' )->justReturn( true );
	}

	/** Yoast post meta on three posts; the batches themselves import nothing. */
	private function stubImport(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $postmeta = 'wp_postmeta';

			public function prepare( string $query ): string {
				return $query;
			}

			public function get_var(): string {
				return '3';
			}

			/** @return string[] */
			public function get_col(): array {
				return [];
			}
		};
	}
}
