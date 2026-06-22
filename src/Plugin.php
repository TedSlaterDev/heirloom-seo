<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo;

use OrchardGrove\HeirloomSeo\Admin\AuthorFields;
use OrchardGrove\HeirloomSeo\Admin\Metabox;
use OrchardGrove\HeirloomSeo\Audit\Screen;
use OrchardGrove\HeirloomSeo\Cli\Commands;
use OrchardGrove\HeirloomSeo\Migration\Ajax;
use OrchardGrove\HeirloomSeo\Modules\Breadcrumbs\Breadcrumbs;
use OrchardGrove\HeirloomSeo\Modules\Cleanup\Cleanup;
use OrchardGrove\HeirloomSeo\Modules\Feed\RssAttribution;
use OrchardGrove\HeirloomSeo\Modules\Ai\Ai;
use OrchardGrove\HeirloomSeo\Modules\Ai\LlmsTxt;
use OrchardGrove\HeirloomSeo\Modules\Authors\Authors;
use OrchardGrove\HeirloomSeo\Modules\IndexNow\IndexNow;
use OrchardGrove\HeirloomSeo\Modules\Media\Media;
use OrchardGrove\HeirloomSeo\Modules\Meta\Meta;
use OrchardGrove\HeirloomSeo\Modules\Redirects\AttachmentRedirect;
use OrchardGrove\HeirloomSeo\Modules\Robots\Robots;
use OrchardGrove\HeirloomSeo\Modules\Schema\Schema;
use OrchardGrove\HeirloomSeo\Modules\Sitemaps\Sitemaps;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Settings\SettingsPage;
use OrchardGrove\HeirloomSeo\Support\FileCache;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container. Boots the enabled modules; a disabled module is never
 * constructed and registers no hooks.
 */
final class Plugin {

	private static ?self $instance = null;

	private Options $options;
	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->options = new Options();
	}

	public function options(): Options {
		return $this->options;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'heirloom-seo', false, dirname( HEIRLOOM_SEO_BASENAME ) . '/languages' );

		$o = $this->options;

		/** @var ModuleInterface[] $modules */
		$modules = [
			new Media( $o ),
			new Meta( $o ),
			new Robots( $o ),
			new Schema( $o ),
			new Cleanup( $o ),
		];

		if ( $o->bool( 'breadcrumbs.enabled' ) ) {
			$modules[] = new Breadcrumbs( $o );
		}
		if ( $o->bool( 'sitemaps.enabled' ) ) {
			$modules[] = new Sitemaps( $o );
		}
		if ( $o->bool( 'indexnow.enabled' ) ) {
			$modules[] = new IndexNow( $o );
		}
		if ( $o->bool( 'redirects.attachments' ) ) {
			$modules[] = new AttachmentRedirect( $o );
		}
		if ( $o->bool( 'feed.rss_attribution' ) ) {
			$modules[] = new RssAttribution( $o );
		}
		$modules[] = new Ai( $o );
		$modules[] = new Authors( $o );

		if ( is_admin() ) {
			$modules[] = new SettingsPage( $o );
			$modules[] = new Metabox( $o );
			$modules[] = new AuthorFields();
			$modules[] = new Ajax();
			$modules[] = new Screen( $o );
		}

		foreach ( $modules as $module ) {
			$module->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register();
		}

		// On version change, re-flush rewrites and clear cached sitemaps so new
		// routes and output-format changes take effect without reactivation. Gated
		// to back-end requests so none of this (DB writes, file ops) hits the front end.
		if ( ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) )
			&& get_option( 'heirloom_seo_version' ) !== HEIRLOOM_SEO_VERSION ) {
			update_option( 'heirloom_seo_version', HEIRLOOM_SEO_VERSION );
			update_option( 'heirloom_seo_needs_flush', '1' );
			FileCache::purge();
			$this->migrate();
			LlmsTxt::markDirty(); // refresh the physical /llms.txt after an update
		}

		add_action( 'init', [ $this, 'maybeFlushRewrites' ], 99 );
	}

	/**
	 * Flush rewrite rules once after activation, after all modules have
	 * registered their rules on `init`.
	 */
	public function maybeFlushRewrites(): void {
		if ( get_option( 'heirloom_seo_needs_flush' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'heirloom_seo_needs_flush' );
		}
	}

	/**
	 * One-time data migrations, run when the stored version changes. Each step
	 * is idempotent and no-ops once applied.
	 */
	private function migrate(): void {
		$opt = get_option( Options::OPTION, [] );
		if ( ! is_array( $opt ) ) {
			return;
		}
		$changed = false;

		// 0.7.4: fold the legacy schema.org_type subtype into schema.site_represents.
		if ( isset( $opt['schema'] ) && is_array( $opt['schema'] ) && array_key_exists( 'org_type', $opt['schema'] ) ) {
			if ( 'LocalBusiness' === $opt['schema']['org_type'] && 'person' !== ( $opt['schema']['site_represents'] ?? 'organization' ) ) {
				$opt['schema']['site_represents'] = 'localbusiness';
			}
			unset( $opt['schema']['org_type'] );
			$changed = true;
		}

		// 0.7.6: split the single titles.singular template into titles.post + titles.page.
		if ( isset( $opt['titles'] ) && is_array( $opt['titles'] ) && array_key_exists( 'singular', $opt['titles'] ) ) {
			foreach ( [ 'post', 'page' ] as $key ) {
				if ( ! array_key_exists( $key, $opt['titles'] ) ) {
					$opt['titles'][ $key ] = $opt['titles']['singular'];
				}
			}
			unset( $opt['titles']['singular'] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( Options::OPTION, $opt );
		}
	}

	public static function activate(): void {
		( new Options() )->seedDefaults();
		FileCache::ensureDir();
		update_option( 'heirloom_seo_needs_flush', '1' );
		LlmsTxt::markDirty();
	}

	public static function deactivate(): void {
		flush_rewrite_rules( false );
		delete_option( 'heirloom_seo_needs_flush' );
		LlmsTxt::onDeactivate();
	}
}
