<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Settings;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Migration\Ajax;
use OrchardGrove\HeirloomSeo\Modules\Ai\Crawlers;
use OrchardGrove\HeirloomSeo\Modules\IndexNow\IndexNow;
use OrchardGrove\HeirloomSeo\Support\FileCache;

defined( 'ABSPATH' ) || exit;

/**
 * Tabbed settings page. The whole option is saved through options.php with a
 * single sanitize callback that merges each submitted tab over stored values,
 * so other tabs are never clobbered.
 */
final class SettingsPage implements ModuleInterface {

	private const GROUP = 'heirloom_seo_group';
	private const PAGE  = 'heirloom-seo';

	/** @var array<string,string> */
	private array $tabs;

	public function __construct( private Options $options ) {
		$this->tabs = [
			'general'  => __( 'General', 'heirloom-seo' ),
			'titles'   => __( 'Titles & Meta', 'heirloom-seo' ),
			'social'   => __( 'Social', 'heirloom-seo' ),
			'robots'   => __( 'Robots', 'heirloom-seo' ),
			'sitemaps' => __( 'Sitemaps', 'heirloom-seo' ),
			'ai'       => __( 'AI', 'heirloom-seo' ),
			'advanced' => __( 'Advanced', 'heirloom-seo' ),
			'tools'    => __( 'Tools', 'heirloom-seo' ),
		];
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenu' ] );
		add_action( 'admin_init', [ $this, 'registerSetting' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_heirloom_seo_flush_cache', [ $this, 'handleFlushCache' ] );
		add_action( 'admin_post_heirloom_seo_regen_key', [ $this, 'handleRegenKey' ] );
		add_action( 'admin_post_heirloom_seo_export_settings', [ $this, 'handleExport' ] );
		add_action( 'admin_post_heirloom_seo_import_settings', [ $this, 'handleImport' ] );
		add_action( 'admin_notices', [ $this, 'conflictNotice' ] );
		add_filter( 'plugin_action_links_' . HEIRLOOM_SEO_BASENAME, [ $this, 'actionLinks' ] );
	}

	/**
	 * Warn when another SEO plugin is active — two at once means duplicate tags.
	 */
	public function conflictNotice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$known = [
			'wordpress-seo/wp-seo.php'                    => 'Yoast SEO',
			'seo-by-rank-math/rank-math.php'              => 'Rank Math',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
			'autodescription/autodescription.php'         => 'The SEO Framework',
		];

		$active = (array) get_option( 'active_plugins', [] );
		$found  = [];
		foreach ( $known as $file => $label ) {
			if ( in_array( $file, $active, true ) ) {
				$found[] = $label;
			}
		}
		if ( ! $found ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>'
			. esc_html(
				sprintf(
					/* translators: %s: comma-separated plugin names. */
					__( 'Heirloom SEO is active alongside %s. Running two SEO plugins outputs duplicate tags — disable one.', 'heirloom-seo' ),
					implode( ', ', $found )
				)
			)
			. '</p></div>';
	}

	public function addMenu(): void {
		add_menu_page(
			__( 'Heirloom SEO', 'heirloom-seo' ),
			__( 'Heirloom SEO', 'heirloom-seo' ),
			'manage_options',
			self::PAGE,
			[ $this, 'renderPage' ],
			'dashicons-search',
			80
		);
	}

	public function registerSetting(): void {
		register_setting(
			self::GROUP,
			Options::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'heirloom-seo-admin', HEIRLOOM_SEO_URL . 'assets/admin/admin.css', [], HEIRLOOM_SEO_VERSION );
		wp_enqueue_script( 'heirloom-seo-metabox', HEIRLOOM_SEO_URL . 'assets/admin/metabox.js', [ 'jquery' ], HEIRLOOM_SEO_VERSION, true );
		wp_enqueue_script( 'heirloom-seo-migrate', HEIRLOOM_SEO_URL . 'assets/admin/migrate.js', [ 'jquery' ], HEIRLOOM_SEO_VERSION, true );
		wp_localize_script(
			'heirloom-seo-migrate',
			'hseoMigrate',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => Ajax::ACTION,
				'nonce'   => wp_create_nonce( Ajax::ACTION ),
				'i18n'    => [
					'scan'      => __( 'Scan for importable data', 'heirloom-seo' ),
					'scanning'  => __( 'Scanning…', 'heirloom-seo' ),
					'none'      => __( 'No importable data found from supported plugins.', 'heirloom-seo' ),
					'posts'     => __( 'posts', 'heirloom-seo' ),
					'import'    => __( 'Import', 'heirloom-seo' ),
					'overwrite' => __( 'Overwrite existing Heirloom values', 'heirloom-seo' ),
					'doneMsg'   => __( 'Done — imported %d posts.', 'heirloom-seo' ),
				],
			]
		);
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public function actionLinks( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'heirloom-seo' ) . '</a>' );
		return $links;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab/notice are display-only.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! isset( $this->tabs[ $current ] ) ) {
			$current = 'general';
		}

		$icons = [
			'general'  => 'admin-settings',
			'titles'   => 'editor-textcolor',
			'social'   => 'share',
			'robots'   => 'visibility',
			'sitemaps' => 'networking',
			'ai'       => 'superhero-alt',
			'advanced' => 'admin-generic',
			'tools'    => 'admin-tools',
		];
		?>
		<div class="wrap hseo-settings">
			<div class="hseo-header">
				<div class="hseo-brand">
					<span class="hseo-mark" aria-hidden="true"><span class="dashicons dashicons-palmtree"></span></span>
					<div class="hseo-brand-text">
						<h1><?php esc_html_e( 'Heirloom SEO', 'heirloom-seo' ); ?></h1>
						<p class="hseo-tagline"><?php esc_html_e( 'Lean, fast SEO essentials — by Orchard Grove Media', 'heirloom-seo' ); ?></p>
					</div>
				</div>
				<span class="hseo-version">v<?php echo esc_html( HEIRLOOM_SEO_VERSION ); ?></span>
			</div>
			<hr class="wp-header-end" />

			<?php $this->maybeNotice(); ?>

			<nav class="hseo-tabs" aria-label="<?php esc_attr_e( 'Heirloom SEO settings', 'heirloom-seo' ); ?>">
				<?php foreach ( $this->tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>"
						class="hseo-tab <?php echo $slug === $current ? 'is-active' : ''; ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>>
						<span class="dashicons dashicons-<?php echo esc_attr( $icons[ $slug ] ?? 'admin-generic' ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="hseo-layout">
				<div class="hseo-main">
					<div class="hseo-card">
						<?php $this->tabIntro( $current ); ?>
						<form method="post" action="options.php">
							<?php
							settings_fields( self::GROUP );
							echo '<table class="form-table" role="presentation">';
							$this->renderTab( $current );
							echo '</table>';
							submit_button();
							?>
						</form>
						<?php
						if ( 'tools' === $current ) {
							$this->renderToolActions();
						}
						?>
					</div>
				</div>
				<aside class="hseo-sidebar">
					<?php $this->renderSidebar(); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	private function tabIntro( string $tab ): void {
		$intros = [
			'general'  => __( 'Your site identity — the title separator, whether the site represents an organization or a person, and the logo used across titles and schema.', 'heirloom-seo' ),
			'titles'   => __( 'Templates for the title tag and meta titles. Per-post overrides always win.', 'heirloom-seo' ),
			'social'   => __( 'Open Graph and X (Twitter) cards, share-image sizes, and search-engine verification codes.', 'heirloom-seo' ),
			'robots'   => __( 'Control indexing per archive type, the robots meta directives, and additions to robots.txt.', 'heirloom-seo' ),
			'sitemaps' => __( 'The XML sitemap and Google News sitemap, served at /sitemap.xml.', 'heirloom-seo' ),
			'ai'       => __( 'Make your content legible to AI (llms.txt) and control which AI crawlers may access it. Everything here is off by default.', 'heirloom-seo' ),
			'advanced' => __( 'Attachment redirects, RSS attribution, breadcrumbs, head cleanup, and uninstall behavior.', 'heirloom-seo' ),
			'tools'    => __( 'IndexNow submission and maintenance actions like clearing the sitemap cache.', 'heirloom-seo' ),
		];
		if ( isset( $intros[ $tab ] ) ) {
			echo '<p class="hseo-intro">' . esc_html( $intros[ $tab ] ) . '</p>';
		}
	}

	private function renderSidebar(): void {
		?>
		<div class="hseo-card hseo-aside">
			<h2><?php esc_html_e( 'At a glance', 'heirloom-seo' ); ?></h2>
			<ul class="hseo-links">
				<?php if ( $this->options->bool( 'sitemaps.enabled' ) ) : ?>
					<li><a href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-networking" aria-hidden="true"></span><?php esc_html_e( 'View XML sitemap', 'heirloom-seo' ); ?></a></li>
					<?php if ( $this->options->bool( 'sitemaps.news_enabled' ) ) : ?>
						<li><a href="<?php echo esc_url( home_url( '/news-sitemap.xml' ) ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-megaphone" aria-hidden="true"></span><?php esc_html_e( 'View Google News sitemap', 'heirloom-seo' ); ?></a></li>
					<?php endif; ?>
				<?php endif; ?>
				<li><a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php esc_html_e( 'View robots.txt', 'heirloom-seo' ); ?></a></li>
				<?php if ( $this->options->bool( 'ai.llms_enabled' ) ) : ?>
					<li><a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-media-text" aria-hidden="true"></span><?php esc_html_e( 'View llms.txt', 'heirloom-seo' ); ?></a></li>
				<?php endif; ?>
			</ul>
			<p class="hseo-aside-foot">
				<?php
				/* translators: %s: version number. */
				printf( esc_html__( 'Heirloom SEO v%s', 'heirloom-seo' ), esc_html( HEIRLOOM_SEO_VERSION ) );
				?><br />
				<?php esc_html_e( 'by Orchard Grove Media, LLC', 'heirloom-seo' ); ?>
			</p>
		</div>

		<div class="hseo-card hseo-aside">
			<h2><?php esc_html_e( 'Support & feedback', 'heirloom-seo' ); ?></h2>
			<ul class="hseo-links">
				<li><a href="<?php echo esc_url( 'mailto:ted@heirloomseo.com' ); ?>"><span class="dashicons dashicons-email" aria-hidden="true"></span><?php esc_html_e( 'Email the developer', 'heirloom-seo' ); ?></a></li>
				<li><a href="https://github.com/TedSlaterDev/heirloom-seo/issues" target="_blank" rel="noopener"><span class="dashicons dashicons-sos" aria-hidden="true"></span><?php esc_html_e( 'Report a bug or request a feature', 'heirloom-seo' ); ?></a></li>
			</ul>
			<p class="hseo-aside-foot"><?php esc_html_e( 'Questions or ideas? I read every message.', 'heirloom-seo' ); ?></p>
		</div>
		<?php
	}

	private function renderTab( string $tab ): void {
		switch ( $tab ) {
			case 'general':
				$this->inputRow( __( 'Title separator', 'heirloom-seo' ), 'general.separator', 'text', '–' );
				$represents = $this->options->str( 'schema.site_represents', 'organization' );
				$this->selectRow( __( 'Site represents', 'heirloom-seo' ), 'schema.site_represents', [
					'organization'  => __( 'An organization', 'heirloom-seo' ),
					'localbusiness' => __( 'A local business', 'heirloom-seo' ),
					'person'        => __( 'A person', 'heirloom-seo' ),
				] );

				if ( 'person' === $represents ) {
					$this->userRow( __( 'Person', 'heirloom-seo' ), 'schema.person_id', __( 'The person this site represents.', 'heirloom-seo' ) );
				} else {
					$this->inputRow( __( 'Organization name', 'heirloom-seo' ), 'schema.org_name', 'text', get_bloginfo( 'name' ) );
					$this->imageRow( __( 'Organization logo', 'heirloom-seo' ), 'schema.org_logo' );
				}

				if ( 'localbusiness' === $represents ) {
					$this->help( __( 'Local business details', 'heirloom-seo' ) );
					$this->inputRow( __( 'Street address', 'heirloom-seo' ), 'schema.address_street' );
					$this->inputRow( __( 'City / locality', 'heirloom-seo' ), 'schema.address_locality' );
					$this->inputRow( __( 'Region / state', 'heirloom-seo' ), 'schema.address_region' );
					$this->inputRow( __( 'Postal code', 'heirloom-seo' ), 'schema.address_postal' );
					$this->inputRow( __( 'Country', 'heirloom-seo' ), 'schema.address_country' );
					$this->inputRow( __( 'Phone', 'heirloom-seo' ), 'schema.phone' );
					$this->inputRow( __( 'Price range', 'heirloom-seo' ), 'schema.price_range', 'text', '$$' );
				}

				$this->textareaRow( __( 'Social profile URLs', 'heirloom-seo' ), 'schema.sameas', __( 'One URL per line (schema sameAs) — used for any identity type.', 'heirloom-seo' ), 4 );
				if ( function_exists( 'wc_get_product' ) ) {
					$this->checkboxRow( __( 'WooCommerce Product schema', 'heirloom-seo' ), 'schema.woo_product', __( 'Output Product schema on product pages', 'heirloom-seo' ) );
				}
				break;

			case 'titles':
				$this->help( __( 'Variables: %title% %sitename% %tagline% %sep% %term_title% %author% %archive_title% %search% %category% %page% %excerpt%', 'heirloom-seo' ) );
				$this->inputRow( __( 'Front page', 'heirloom-seo' ), 'titles.front' );
				$this->inputRow( __( 'Posts page', 'heirloom-seo' ), 'titles.home' );
				$this->inputRow( __( 'Posts', 'heirloom-seo' ), 'titles.post' );
				$this->inputRow( __( 'Pages', 'heirloom-seo' ), 'titles.page' );
				$this->inputRow( __( 'Category / tag', 'heirloom-seo' ), 'titles.term' );
				$this->inputRow( __( 'Author', 'heirloom-seo' ), 'titles.author' );
				$this->inputRow( __( 'Archives', 'heirloom-seo' ), 'titles.archive' );
				$this->inputRow( __( 'Search', 'heirloom-seo' ), 'titles.search' );
				$this->inputRow( __( '404', 'heirloom-seo' ), 'titles.notfound' );
				break;

			case 'social':
				$this->imageRow( __( 'Default share image', 'heirloom-seo' ), 'social.default_image' );
				$this->inputRow( __( 'X / Twitter username', 'heirloom-seo' ), 'social.twitter_site', 'text', '@yoursite' );
				$this->inputRow( __( 'Facebook App ID', 'heirloom-seo' ), 'social.facebook_app' );
				$this->help( __( 'Share image sizes', 'heirloom-seo' ) );
				$this->selectRow( __( 'Open Graph image size', 'heirloom-seo' ), 'social.og_image_size', $this->imageSizeChoices( __( 'Auto — 1200×630 (managed)', 'heirloom-seo' ) ) );
				$this->selectRow( __( 'X / Twitter image size', 'heirloom-seo' ), 'social.twitter_image_size', $this->imageSizeChoices( __( 'Auto — 1600×900 (managed)', 'heirloom-seo' ) ) );
				$this->checkboxRow( __( 'Register sizes', 'heirloom-seo' ), 'social.generate_sizes', __( 'Let Heirloom register the share sizes when the theme has not', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Upscale small images', 'heirloom-seo' ), 'social.upscale_crops', __( 'Crop-upscale undersized originals to fill the share size', 'heirloom-seo' ) );
				$this->help( __( 'Search engine verification', 'heirloom-seo' ) );
				$this->inputRow( __( 'Google', 'heirloom-seo' ), 'verification.google' );
				$this->inputRow( __( 'Bing', 'heirloom-seo' ), 'verification.bing' );
				$this->inputRow( __( 'Pinterest', 'heirloom-seo' ), 'verification.pinterest' );
				$this->inputRow( __( 'Baidu', 'heirloom-seo' ), 'verification.baidu' );
				break;

			case 'robots':
				if ( file_exists( ABSPATH . 'robots.txt' ) ) {
					echo '<tr><td colspan="2"><div class="hseo-inline-notice">'
						. esc_html__( 'A physical robots.txt file exists in your site root. Web servers serve that file directly, so the additions below (and the automatic sitemap line) are ignored until you remove it. Heirloom never overwrites your file.', 'heirloom-seo' )
						. '</div></td></tr>';
				}
				$this->checkboxRow( __( 'Author archives', 'heirloom-seo' ), 'robots.noindex_author', __( 'noindex author archives', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Date archives', 'heirloom-seo' ), 'robots.noindex_date', __( 'noindex date archives', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Search results', 'heirloom-seo' ), 'robots.noindex_search', __( 'noindex search results', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Tag archives', 'heirloom-seo' ), 'robots.noindex_tag', __( 'noindex tag archives', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Paginated pages', 'heirloom-seo' ), 'robots.noindex_paginated', __( 'noindex page 2, 3, …', 'heirloom-seo' ) );
				$this->selectRow( __( 'Max image preview', 'heirloom-seo' ), 'robots.max_image_preview', [
					'none'     => 'none',
					'standard' => 'standard',
					'large'    => 'large',
				] );
				$this->inputRow( __( 'Max snippet', 'heirloom-seo' ), 'robots.max_snippet', 'number', '-1' );
				$this->inputRow( __( 'Max video preview', 'heirloom-seo' ), 'robots.max_video_preview', 'number', '-1' );
				$this->textareaRow( __( 'robots.txt additions', 'heirloom-seo' ), 'robots.robots_txt', __( 'Appended to the virtual robots.txt. The sitemap line is added automatically.', 'heirloom-seo' ) );
				break;

			case 'sitemaps':
				$this->checkboxRow( __( 'XML sitemap', 'heirloom-seo' ), 'sitemaps.enabled', __( 'Enable the sitemap at /sitemap.xml', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Google News', 'heirloom-seo' ), 'sitemaps.news_enabled', __( 'Enable /news-sitemap.xml', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Images', 'heirloom-seo' ), 'sitemaps.images', __( 'Include image entries', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Authors', 'heirloom-seo' ), 'sitemaps.authors', __( 'Include author archives', 'heirloom-seo' ) );
				$this->inputRow( __( 'URLs per page', 'heirloom-seo' ), 'sitemaps.per_page', 'number', '1000' );
				$this->help( __( 'Google News — what counts as news (drives the News sitemap and NewsArticle schema)', 'heirloom-seo' ) );
				$this->termDropdownRow( __( 'News category', 'heirloom-seo' ), 'schema.news_category', 'category', __( 'Posts in this category appear in the News sitemap.', 'heirloom-seo' ) );
				$this->termDropdownRow( __( 'News tag', 'heirloom-seo' ), 'schema.news_tag', 'post_tag', __( 'Posts with this tag appear in the News sitemap.', 'heirloom-seo' ) );
				$this->inputRow( __( 'Fallback term name', 'heirloom-seo' ), 'schema.news_term', 'text', 'News', __( 'Used only when no category or tag is selected above.', 'heirloom-seo' ) );
				break;

			case 'ai':
				$this->help( __( 'llms.txt', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Enable llms.txt', 'heirloom-seo' ), 'ai.llms_enabled', __( 'Serve /llms.txt — a curated, LLM-friendly map of your site', 'heirloom-seo' ) );
				$this->selectRow( __( 'Mode', 'heirloom-seo' ), 'ai.llms_mode', [
					'auto'   => __( 'Auto-generate from site', 'heirloom-seo' ),
					'manual' => __( 'Manual content', 'heirloom-seo' ),
				] );
				$this->textareaRow( __( 'Summary', 'heirloom-seo' ), 'ai.llms_intro', __( 'The blockquote summary (auto mode). Blank uses your site tagline.', 'heirloom-seo' ), 2 );
				$this->inputRow( __( 'Max posts', 'heirloom-seo' ), 'ai.llms_max_posts', 'number', '50' );
				$this->selectRow( __( 'Pages', 'heirloom-seo' ), 'ai.llms_pages_mode', [
					'all'      => __( 'All published pages (automatic)', 'heirloom-seo' ),
					'selected' => __( 'Only the pages I choose', 'heirloom-seo' ),
				] );
				if ( 'selected' === $this->options->str( 'ai.llms_pages_mode', 'all' ) ) {
					$this->pageDropdownRow( __( 'About page', 'heirloom-seo' ), 'ai.llms_page_about' );
					$this->pageDropdownRow( __( 'Contact page', 'heirloom-seo' ), 'ai.llms_page_contact' );
					$this->pageDropdownRow( __( 'Terms page', 'heirloom-seo' ), 'ai.llms_page_terms' );
					$this->pageDropdownRow( __( 'Privacy Policy page', 'heirloom-seo' ), 'ai.llms_page_privacy' );
					$this->pageDropdownRow( __( 'Shop page', 'heirloom-seo' ), 'ai.llms_page_shop' );
					$this->pagesMultiRow( __( 'Additional pages', 'heirloom-seo' ), 'ai.llms_pages_extra' );
				}
				$this->textareaRow( __( 'Manual content', 'heirloom-seo' ), 'ai.llms_content', __( 'Used when Mode is set to Manual.', 'heirloom-seo' ), 6 );
				$this->aiPreviewLinks();

				$this->help( __( 'AI crawler access (robots.txt)', 'heirloom-seo' ) );
				$this->renderBotCheckboxes();
				break;

			case 'advanced':
				$this->checkboxRow( __( 'Attachment redirects', 'heirloom-seo' ), 'redirects.attachments', __( 'Redirect attachment pages to the parent post or file', 'heirloom-seo' ) );
				$this->selectRow( __( 'Redirect target', 'heirloom-seo' ), 'redirects.target', [
					'parent' => __( 'Parent post', 'heirloom-seo' ),
					'file'   => __( 'The file itself', 'heirloom-seo' ),
				] );
				$this->checkboxRow( __( 'RSS attribution', 'heirloom-seo' ), 'feed.rss_attribution', __( 'Append an attribution line to feed items', 'heirloom-seo' ) );
				$this->textareaRow( __( 'RSS attribution text', 'heirloom-seo' ), 'feed.rss_text', __( 'Variables: %post_link% %site_link%', 'heirloom-seo' ), 2 );
				$this->checkboxRow( __( 'Breadcrumbs', 'heirloom-seo' ), 'breadcrumbs.enabled', __( 'Enable the breadcrumbs shortcode & template tag', 'heirloom-seo' ) );
				$this->inputRow( __( 'Breadcrumb home label', 'heirloom-seo' ), 'breadcrumbs.home_label', 'text', 'Home' );
				$this->inputRow( __( 'Breadcrumb separator', 'heirloom-seo' ), 'breadcrumbs.separator', 'text', '&raquo;' );
				$this->help( __( 'Head cleanup', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Generator tag', 'heirloom-seo' ), 'cleanup.remove_generator', __( 'Remove the WordPress generator meta', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'WLW manifest', 'heirloom-seo' ), 'cleanup.remove_wlwmanifest', __( 'Remove wlwmanifest link', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'RSD link', 'heirloom-seo' ), 'cleanup.remove_rsd', __( 'Remove RSD link', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Shortlink', 'heirloom-seo' ), 'cleanup.remove_shortlink', __( 'Remove shortlink', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'REST API link', 'heirloom-seo' ), 'cleanup.remove_rest_link', __( 'Remove REST API discovery link', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'oEmbed links', 'heirloom-seo' ), 'cleanup.remove_oembed_links', __( 'Remove oEmbed discovery links', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Uninstall', 'heirloom-seo' ), 'advanced.delete_data_on_uninstall', __( 'Delete all plugin data when the plugin is deleted', 'heirloom-seo' ) );
				$this->help( __( 'Theme compatibility', 'heirloom-seo' ) );
				$this->checkboxRow( __( 'Force document title', 'heirloom-seo' ), 'advanced.force_title', __( 'Enable only if your theme prints its own <title> (e.g. a legacy wp_title() call), causing a duplicate or wrong title. Buffers the page to force one correct title. Leave off otherwise.', 'heirloom-seo' ) );
				break;

			case 'tools':
				$this->checkboxRow( __( 'IndexNow', 'heirloom-seo' ), 'indexnow.enabled', __( 'Submit new & updated URLs to Bing/Yandex on save', 'heirloom-seo' ) );
				$key = $this->options->str( 'indexnow.key' );
				echo '<tr><th scope="row">' . esc_html__( 'IndexNow key', 'heirloom-seo' ) . '</th><td>';
				echo '' !== $key ? '<code class="hseo-key">' . esc_html( $key ) . '</code>' : '<span class="description">' . esc_html__( 'Generated automatically when enabled.', 'heirloom-seo' ) . '</span>';
				echo '</td></tr>';
				break;
		}
	}

	private function renderToolActions(): void {
		$sitemap = home_url( '/sitemap.xml' );
		$news    = home_url( '/news-sitemap.xml' );
		?>
		<hr />
		<h2><?php esc_html_e( 'Maintenance', 'heirloom-seo' ); ?></h2>
		<p>
			<a href="<?php echo esc_url( $sitemap ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $sitemap ); ?></a>
			&nbsp;·&nbsp;
			<a href="<?php echo esc_url( $news ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $news ); ?></a>
		</p>
		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'heirloom_seo_flush_cache' ); ?>
				<input type="hidden" name="action" value="heirloom_seo_flush_cache" />
				<?php submit_button( __( 'Clear sitemap cache', 'heirloom-seo' ), 'secondary', 'submit', false ); ?>
			</form>
			&nbsp;
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'heirloom_seo_regen_key' ); ?>
				<input type="hidden" name="action" value="heirloom_seo_regen_key" />
				<?php submit_button( __( 'Regenerate IndexNow key', 'heirloom-seo' ), 'secondary', 'submit', false ); ?>
			</form>
		</p>

		<hr />
		<h2><?php esc_html_e( 'Migrate from another SEO plugin', 'heirloom-seo' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Import per-post SEO data (title, description, canonical, robots, social image, schema type) from Yoast, Rank Math, All in One SEO, or The SEO Framework. Non-destructive — the source plugin\'s data is left intact.', 'heirloom-seo' ); ?></p>
		<div id="hseo-migrate">
			<p><button type="button" class="button" id="hseo-migrate-scan"><?php esc_html_e( 'Scan for importable data', 'heirloom-seo' ); ?></button></p>
			<div id="hseo-migrate-results"></div>
		</div>

		<hr />
		<h2><?php esc_html_e( 'Settings export / import', 'heirloom-seo' ); ?></h2>
		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'heirloom_seo_export' ); ?>
				<input type="hidden" name="action" value="heirloom_seo_export_settings" />
				<?php submit_button( __( 'Export settings (JSON)', 'heirloom-seo' ), 'secondary', 'submit', false ); ?>
			</form>
			&nbsp;
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="display:inline">
				<?php wp_nonce_field( 'heirloom_seo_import' ); ?>
				<input type="hidden" name="action" value="heirloom_seo_import_settings" />
				<input type="file" name="hseo_import_file" accept="application/json,.json" />
				<?php submit_button( __( 'Import settings', 'heirloom-seo' ), 'secondary', 'submit', false ); ?>
			</form>
		</p>
		<p class="description"><?php esc_html_e( 'Export excludes site-specific secrets (IndexNow key, verification codes). Import merges over current settings.', 'heirloom-seo' ); ?></p>
		<?php
	}

	// --- Save -------------------------------------------------------------

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$current = get_option( Options::OPTION, [] );
		$current = is_array( $current ) ? $current : [];
		$input   = is_array( $input ) ? $input : [];

		$clean = [];
		foreach ( $this->fieldSchema() as $path => $type ) {
			if ( ! $this->hasNested( $input, $path ) ) {
				continue;
			}
			$this->setNested( $clean, $path, $this->sanitizeValue( $this->getNested( $input, $path ), $type ) );
		}

		// AI crawler block-list — a checkbox group, validated against known bots.
		if ( isset( $input['ai']['blocked_bots'] ) && is_array( $input['ai']['blocked_bots'] ) ) {
			$picked = array_map( 'sanitize_key', $input['ai']['blocked_bots'] );
			$this->setNested( $clean, 'ai.blocked_bots', array_values( array_intersect( $picked, array_keys( Crawlers::BOTS ) ) ) );
		}

		// llms.txt additional pages — multi-select of page IDs (a hidden 0 sentinel lets you clear them all).
		if ( isset( $input['ai']['llms_pages_extra'] ) && is_array( $input['ai']['llms_pages_extra'] ) ) {
			$page_ids = array_values( array_filter( array_map( 'absint', $input['ai']['llms_pages_extra'] ) ) );
			$this->setNested( $clean, 'ai.llms_pages_extra', $page_ids );
		}

		$merged = $this->mergeDeep( $current, $clean );

		if ( ! empty( $merged['indexnow']['enabled'] ) && empty( $merged['indexnow']['key'] ) ) {
			$merged['indexnow']['key'] = IndexNow::generateKey();
		}

		update_option( 'heirloom_seo_needs_flush', '1' );
		FileCache::purge(); // Settings can change sitemap/llms output — drop stale caches.
		return $merged;
	}

	/** @return array<string,string> dotted path => type spec. */
	private function fieldSchema(): array {
		return [
			'general.separator'              => 'text',
			'titles.front'                   => 'text',
			'titles.home'                    => 'text',
			'titles.post'                    => 'text',
			'titles.page'                    => 'text',
			'titles.term'                    => 'text',
			'titles.author'                  => 'text',
			'titles.archive'                 => 'text',
			'titles.search'                  => 'text',
			'titles.notfound'                => 'text',
			'social.default_image'           => 'url',
			'social.twitter_site'            => 'text',
			'social.facebook_app'            => 'text',
			'social.og_image_size'           => 'text',
			'social.twitter_image_size'      => 'text',
			'social.generate_sizes'          => 'bool',
			'social.upscale_crops'           => 'bool',
			'verification.google'            => 'text',
			'verification.bing'              => 'text',
			'verification.pinterest'         => 'text',
			'verification.baidu'             => 'text',
			'schema.site_represents'         => 'enum:organization,localbusiness,person',
			'schema.org_name'                => 'text',
			'schema.org_logo'                => 'url',
			'schema.person_id'               => 'int',
			'schema.news_term'               => 'text',
			'schema.news_category'           => 'text',
			'schema.news_tag'                => 'text',
			'schema.address_street'          => 'text',
			'schema.address_locality'        => 'text',
			'schema.address_region'          => 'text',
			'schema.address_postal'          => 'text',
			'schema.address_country'         => 'text',
			'schema.phone'                   => 'text',
			'schema.price_range'             => 'text',
			'schema.sameas'                  => 'textarea',
			'schema.woo_product'             => 'bool',
			'robots.noindex_author'          => 'bool',
			'robots.noindex_date'            => 'bool',
			'robots.noindex_search'          => 'bool',
			'robots.noindex_tag'             => 'bool',
			'robots.noindex_paginated'       => 'bool',
			'robots.max_snippet'             => 'int',
			'robots.max_image_preview'       => 'enum:none,standard,large',
			'robots.max_video_preview'       => 'int',
			'robots.robots_txt'              => 'textarea',
			'sitemaps.enabled'               => 'bool',
			'sitemaps.news_enabled'          => 'bool',
			'sitemaps.images'                => 'bool',
			'sitemaps.authors'               => 'bool',
			'sitemaps.per_page'              => 'intrange:1:50000',
			'redirects.attachments'          => 'bool',
			'redirects.target'               => 'enum:parent,file',
			'feed.rss_attribution'           => 'bool',
			'feed.rss_text'                  => 'kses',
			'breadcrumbs.enabled'            => 'bool',
			'breadcrumbs.home_label'         => 'text',
			'breadcrumbs.separator'          => 'text',
			'ai.llms_enabled'                => 'bool',
			'ai.llms_mode'                   => 'enum:auto,manual',
			'ai.llms_intro'                  => 'textarea',
			'ai.llms_content'                => 'textarea',
			'ai.llms_max_posts'              => 'intrange:0:1000',
			'ai.llms_pages_mode'             => 'enum:all,selected',
			'ai.llms_page_about'             => 'int',
			'ai.llms_page_contact'           => 'int',
			'ai.llms_page_terms'             => 'int',
			'ai.llms_page_privacy'           => 'int',
			'ai.llms_page_shop'              => 'int',
			'cleanup.remove_generator'       => 'bool',
			'cleanup.remove_wlwmanifest'     => 'bool',
			'cleanup.remove_rsd'             => 'bool',
			'cleanup.remove_shortlink'       => 'bool',
			'cleanup.remove_rest_link'       => 'bool',
			'cleanup.remove_oembed_links'    => 'bool',
			'indexnow.enabled'               => 'bool',
			'advanced.delete_data_on_uninstall' => 'bool',
			'advanced.force_title'              => 'bool',
		];
	}

	/** @param mixed $raw */
	private function sanitizeValue( $raw, string $type ): mixed {
		if ( str_starts_with( $type, 'enum:' ) ) {
			$allowed = explode( ',', substr( $type, 5 ) );
			$value   = is_string( $raw ) ? $raw : '';
			return in_array( $value, $allowed, true ) ? $value : $allowed[0];
		}
		if ( str_starts_with( $type, 'intrange:' ) ) {
			[ , $min, $max ] = explode( ':', $type );
			return max( (int) $min, min( (int) $max, (int) $raw ) );
		}
		return match ( $type ) {
			'textarea' => sanitize_textarea_field( (string) $raw ),
			'url'      => esc_url_raw( (string) $raw ),
			'kses'     => wp_kses_post( (string) $raw ),
			'bool'     => (bool) $raw,
			'int'      => (int) $raw,
			default    => sanitize_text_field( (string) $raw ),
		};
	}

	// --- Tool action handlers --------------------------------------------

	public function handleFlushCache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}
		check_admin_referer( 'heirloom_seo_flush_cache' );
		FileCache::purge();
		$this->redirectTools( 'flushed' );
	}

	public function handleRegenKey(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}
		check_admin_referer( 'heirloom_seo_regen_key' );

		$option = get_option( Options::OPTION, [] );
		$option = is_array( $option ) ? $option : [];
		$option['indexnow']['key'] = IndexNow::generateKey();
		update_option( Options::OPTION, $option, true );
		update_option( 'heirloom_seo_needs_flush', '1' );

		$this->redirectTools( 'key' );
	}

	public function handleExport(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}
		check_admin_referer( 'heirloom_seo_export' );

		$opts = get_option( Options::OPTION, [] );
		$opts = is_array( $opts ) ? $opts : [];
		// Strip site-specific secrets.
		unset( $opts['indexnow']['key'], $opts['verification'] );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="heirloom-seo-settings.json"' );
		echo wp_json_encode( $opts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public function handleImport(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '-1' );
		}
		check_admin_referer( 'heirloom_seo_import' );

		$tmp = isset( $_FILES['hseo_import_file']['tmp_name'] ) ? wp_unslash( $_FILES['hseo_import_file']['tmp_name'] ) : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$this->redirectTools( 'import_error' );
		}
		$raw  = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			$this->redirectTools( 'import_error' );
		}

		$merged = $this->sanitize( $data );
		update_option( Options::OPTION, $merged, true );
		$this->redirectTools( 'imported' );
	}

	private function redirectTools( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				[ 'page' => self::PAGE, 'tab' => 'tools', 'hseo_notice' => $notice ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function maybeNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		if ( ! isset( $_GET['hseo_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = sanitize_key( wp_unslash( $_GET['hseo_notice'] ) );
		if ( 'import_error' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not import settings — the file was not valid JSON.', 'heirloom-seo' ) . '</p></div>';
			return;
		}
		$message = match ( $notice ) {
			'flushed'  => __( 'Sitemap cache cleared.', 'heirloom-seo' ),
			'key'      => __( 'IndexNow key regenerated.', 'heirloom-seo' ),
			'imported' => __( 'Settings imported.', 'heirloom-seo' ),
			default    => '',
		};
		if ( '' !== $message ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	// --- Field renderers --------------------------------------------------

	private function inputRow( string $label, string $path, string $type = 'text', string $placeholder = '', string $help = '' ): void {
		$id = $this->id( $path );
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%4$s" value="%5$s" class="regular-text" placeholder="%6$s" />%7$s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $this->name( $path ) ),
			esc_attr( (string) $this->options->get( $path, '' ) ),
			esc_attr( $placeholder ),
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private function textareaRow( string $label, string $path, string $help = '', int $rows = 5 ): void {
		$id = $this->id( $path );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $path ) ) . '" rows="' . absint( $rows ) . '" class="large-text code">'
			. esc_textarea( (string) $this->options->get( $path, '' ) ) . '</textarea>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** @param array<string,string> $choices */
	private function selectRow( string $label, string $path, array $choices ): void {
		$id      = $this->id( $path );
		$current = (string) $this->options->get( $path, '' );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $path ) ) . '">';
		foreach ( $choices as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	private function checkboxRow( string $label, string $path, string $help = '' ): void {
		$id   = $this->id( $path );
		$name = $this->name( $path );
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label for="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
		echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( $this->options->bool( $path ), true, false ) . ' /> ';
		echo esc_html( $help );
		echo '</label></td></tr>';
	}

	private function imageRow( string $label, string $path ): void {
		$id  = $this->id( $path );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" id="' . esc_attr( $id ) . '" class="regular-text hseo-image-field" name="' . esc_attr( $this->name( $path ) ) . '" value="' . esc_attr( (string) $this->options->get( $path, '' ) ) . '" /> ';
		echo '<button type="button" class="button hseo-image-pick" data-target="#' . esc_attr( $id ) . '">' . esc_html__( 'Select', 'heirloom-seo' ) . '</button> ';
		echo '<button type="button" class="button hseo-image-clear" data-target="#' . esc_attr( $id ) . '">' . esc_html__( 'Clear', 'heirloom-seo' ) . '</button>';
		echo '</td></tr>';
	}

	private function userRow( string $label, string $path, string $help = '' ): void {
		$id = $this->id( $path );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		wp_dropdown_users(
			[
				'name'             => $this->name( $path ),
				'id'               => $id,
				'selected'         => $this->options->int( $path ),
				'show_option_none' => __( '— Select —', 'heirloom-seo' ),
				'option_none_value' => 0,
			]
		);
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function termDropdownRow( string $label, string $path, string $taxonomy, string $help = '' ): void {
		$id      = $this->id( $path );
		$current = (string) $this->options->get( $path, '' );
		$terms   = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'number'     => 500, // Capped; the saved term is re-added below if it falls outside.
			]
		);
		$terms = is_wp_error( $terms ) ? [] : $terms;

		// Keep the saved term selectable even if it isn't in the capped list (prevents silent clearing on save).
		if ( '' !== $current && ! in_array( $current, wp_list_pluck( $terms, 'slug' ), true ) ) {
			$saved = get_term_by( 'slug', $current, $taxonomy );
			if ( $saved && ! is_wp_error( $saved ) ) {
				array_unshift( $terms, $saved );
			}
		}

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $path ) ) . '">';
		echo '<option value="">' . esc_html__( '— None —', 'heirloom-seo' ) . '</option>';
		foreach ( $terms as $term ) {
			echo '<option value="' . esc_attr( $term->slug ) . '" ' . selected( $current, $term->slug, false ) . '>' . esc_html( $term->name ) . '</option>';
		}
		echo '</select>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function pageDropdownRow( string $label, string $path ): void {
		$id = $this->id( $path );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		wp_dropdown_pages(
			[
				'name'              => $this->name( $path ),
				'id'                => $id,
				'selected'          => $this->options->int( $path ),
				'show_option_none'  => esc_html__( '— None —', 'heirloom-seo' ),
				'option_none_value' => 0,
				// Keep a saved page selectable even if it's no longer published, so re-saving doesn't silently wipe it.
				'post_status'       => 'publish,private,draft,pending,future',
			]
		);
		echo '</td></tr>';
	}

	private function pagesMultiRow( string $label, string $path ): void {
		$id       = $this->id( $path );
		$selected = array_map( 'intval', $this->options->arr( $path ) );
		$pages    = get_pages( [ 'sort_column' => 'menu_order,post_title', 'number' => 200 ] );

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="hidden" name="' . esc_attr( $this->name( $path ) ) . '[]" value="0" />';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $path ) ) . '[]" multiple size="6" class="regular-text">';
		foreach ( (array) $pages as $page ) {
			echo '<option value="' . esc_attr( (string) $page->ID ) . '" ' . selected( in_array( (int) $page->ID, $selected, true ), true, false ) . '>' . esc_html( $page->post_title ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Hold Cmd/Ctrl to choose several. Listed after the named pages above (up to 200 shown).', 'heirloom-seo' ) . '</p>';
		echo '</td></tr>';
	}

	private function help( string $text ): void {
		echo '<tr><td colspan="2"><p class="description"><strong>' . esc_html( $text ) . '</strong></p></td></tr>';
	}

	private function aiPreviewLinks(): void {
		echo '<tr><th scope="row">' . esc_html__( 'Preview', 'heirloom-seo' ) . '</th><td>';
		echo '<a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener">llms.txt</a>';
		echo '</td></tr>';
	}

	private function renderBotCheckboxes(): void {
		$blocked = $this->options->arr( 'ai.blocked_bots' );
		$name    = Options::OPTION . '[ai][blocked_bots][]';

		$groups = [
			'training' => __( 'Training crawlers — blocking opts out of AI model training (low downside).', 'heirloom-seo' ),
			'search'   => __( 'AI search & answer engines — blocking can reduce your visibility in AI search.', 'heirloom-seo' ),
			'user'     => __( 'User-triggered fetches — blocking can cut AI referral traffic when someone asks about your page.', 'heirloom-seo' ),
		];

		echo '<tr><td colspan="2">';
		echo '<p class="description">' . esc_html__( 'Checked crawlers get a Disallow rule in robots.txt. Only effective when no physical robots.txt exists.', 'heirloom-seo' ) . '</p>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="__none__" />';

		echo '<p class="hseo-bot-presets">';
		echo '<button type="button" class="button hseo-bot-preset" data-preset="training">' . esc_html__( 'Block training', 'heirloom-seo' ) . '</button> ';
		echo '<button type="button" class="button hseo-bot-preset" data-preset="search">' . esc_html__( 'Block training + AI search', 'heirloom-seo' ) . '</button> ';
		echo '<button type="button" class="button hseo-bot-preset" data-preset="all">' . esc_html__( 'Block all', 'heirloom-seo' ) . '</button> ';
		echo '<button type="button" class="button hseo-bot-preset" data-preset="none">' . esc_html__( 'Allow all', 'heirloom-seo' ) . '</button>';
		echo '</p>';

		foreach ( $groups as $type => $heading ) {
			echo '<p class="hseo-bot-group"><strong>' . esc_html( $heading ) . '</strong></p>';
			echo '<div class="hseo-bots">';
			foreach ( Crawlers::BOTS as $key => $bot ) {
				if ( ( $bot['type'] ?? 'training' ) !== $type ) {
					continue;
				}
				$id = 'hseo_bot_' . $key;
				echo '<label for="' . esc_attr( $id ) . '" class="hseo-bot">';
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $key ) . '" data-bot-type="' . esc_attr( $bot['type'] ?? 'training' ) . '" ' . checked( in_array( $key, $blocked, true ), true, false ) . ' /> ';
				echo '<strong>' . esc_html( $bot['label'] ) . '</strong><br /><span class="description">' . esc_html( $bot['note'] ) . '</span>';
				echo '</label>';
			}
			echo '</div>';
		}
		echo '</td></tr>';
	}

	/**
	 * "Auto" plus every registered image size, with dimensions, for a select.
	 *
	 * @return array<string,string>
	 */
	private function imageSizeChoices( string $auto_label ): array {
		$choices = [ 'auto' => $auto_label ];
		foreach ( wp_get_registered_image_subsizes() as $name => $info ) {
			$choices[ $name ] = sprintf(
				'%s (%d×%d%s)',
				$name,
				(int) $info['width'],
				(int) $info['height'],
				! empty( $info['crop'] ) ? ' ' . __( 'cropped', 'heirloom-seo' ) : ''
			);
		}
		return $choices;
	}

	// --- Path helpers -----------------------------------------------------

	private function name( string $path ): string {
		return Options::OPTION . '[' . implode( '][', explode( '.', $path ) ) . ']';
	}

	private function id( string $path ): string {
		return 'hseo_' . str_replace( '.', '_', $path );
	}

	/** @param array<string,mixed> $array */
	private function hasNested( array $array, string $path ): bool {
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $array ) || ! array_key_exists( $segment, $array ) ) {
				return false;
			}
			$array = $array[ $segment ];
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $array
	 * @return mixed
	 */
	private function getNested( array $array, string $path ): mixed {
		foreach ( explode( '.', $path ) as $segment ) {
			$array = $array[ $segment ];
		}
		return $array;
	}

	/**
	 * @param array<string,mixed> $array
	 * @param mixed               $value
	 */
	private function setNested( array &$array, string $path, $value ): void {
		$segments = explode( '.', $path );
		$ref      = &$array;
		foreach ( $segments as $i => $segment ) {
			if ( $i === count( $segments ) - 1 ) {
				$ref[ $segment ] = $value;
			} else {
				if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
					$ref[ $segment ] = [];
				}
				$ref = &$ref[ $segment ];
			}
		}
	}

	/**
	 * @param array<string,mixed> $base
	 * @param array<string,mixed> $over
	 * @return array<string,mixed>
	 */
	private function mergeDeep( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = $this->mergeDeep( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}
