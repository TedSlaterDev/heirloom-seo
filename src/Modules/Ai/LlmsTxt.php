<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Ai;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Support\FileCache;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Serves /llms.txt — a curated, LLM-friendly map of the site. File-cached.
 */
final class LlmsTxt implements ModuleInterface {

	private const QV            = 'heirloom_llms';
	private const PURGE_HOOKS   = [ 'save_post', 'deleted_post' ];
	private const CRON_HOOK     = 'heirloom_seo_llms_rebuild';
	private const REBUILD_DELAY = 120;                              // seconds — debounce bulk edits into one rebuild.
	private const FILENAME      = 'llms.txt';
	private const OWNED_OPTION  = 'heirloom_seo_llms_static';        // '1' while we manage a physical /llms.txt.
	private const PATH_OPTION   = 'heirloom_seo_llms_static_path';   // the resolved path we wrote (so uninstall can find it).
	private const DIRTY_OPTION  = 'heirloom_seo_llms_dirty';         // '1' when a rebuild is pending.
	private const FAILED_OPTION = 'heirloom_seo_llms_static_failed'; // '' | 'foreign' | 'unwritable' — drives the admin notice.

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_action( 'init', [ $this, 'addRewrite' ] );
		add_filter( 'query_vars', [ $this, 'queryVars' ] );
		add_action( 'template_redirect', [ $this, 'maybeServe' ], 0 ); // Before redirect_canonical's trailing-slash redirect.
		foreach ( self::PURGE_HOOKS as $hook ) {
			add_action( $hook, [ FileCache::class, 'purge' ] );
			add_action( $hook, [ self::class, 'markDirty' ] );
		}
	}

	public function addRewrite(): void {
		// Optional trailing slash: trailing-slash permalink sites (and CDNs) otherwise serve only /llms.txt/.
		add_rewrite_rule( '^llms\.txt/?$', 'index.php?' . self::QV . '=index', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function queryVars( array $vars ): array {
		$vars[] = self::QV;
		return $vars;
	}

	public function maybeServe(): void {
		if ( '' === (string) get_query_var( self::QV ) ) {
			return;
		}

		$body = FileCache::get( 'llms' );
		if ( null === $body ) {
			$body = $this->buildIndex();
			FileCache::put( 'llms', $body );
		}

		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/markdown; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text markdown.
		exit;
	}

	private function buildIndex(): string {
		if ( 'manual' === $this->options->str( 'ai.llms_mode', 'auto' ) ) {
			$content = $this->options->str( 'ai.llms_content' );
			if ( '' !== $content ) {
				return $content;
			}
		}

		$md = '# ' . self::oneLine( get_bloginfo( 'name' ) ) . "\n\n";

		$intro = $this->options->str( 'ai.llms_intro' );
		if ( '' === $intro ) {
			$intro = get_bloginfo( 'description' );
		}
		if ( '' !== $intro ) {
			$md .= '> ' . self::oneLine( $intro ) . "\n\n";
		}

		$pages = $this->llmsPages();
		if ( $pages ) {
			$md .= "## Pages\n\n";
			foreach ( $pages as $page ) {
				if ( ! Export::allowed( $page ) ) {
					continue;
				}
				$md .= $this->linkLine( $page );
			}
			$md .= "\n";
		}

		$max = $this->options->int( 'ai.llms_max_posts', 50 );
		if ( $max > 0 ) {
			$posts = get_posts(
				[
					'post_type'              => 'post',
					'post_status'            => 'publish',
					'posts_per_page'         => $max,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				]
			);
			if ( $posts ) {
				$md .= "## Posts\n\n";
				foreach ( $posts as $post ) {
					if ( ! Export::allowed( $post ) ) {
						continue;
					}
					$md .= $this->linkLine( $post );
				}
				$md .= "\n";
			}
		}

		return trim( $md ) . "\n";
	}

	private function linkLine( WP_Post $post ): string {
		$title   = self::oneLine( self::decode( get_the_title( $post ) ) );
		$url     = get_permalink( $post );
		$excerpt = $this->excerpt( $post );

		$line = '- [' . $title . '](' . $url . ')';
		if ( '' !== $excerpt ) {
			$line .= ': ' . $excerpt;
		}
		return $line . "\n";
	}

	/**
	 * A concise, clean description: the hand-written excerpt when present, else a
	 * trimmed slice of the content. Built from the raw post (not get_the_excerpt)
	 * so theme/plugin filters can't inject chrome like "Read more" or print links.
	 */
	private function excerpt( WP_Post $post ): string {
		$raw = has_excerpt( $post ) ? $post->post_excerpt : (string) $post->post_content;
		$raw = self::oneLine( self::decode( wp_strip_all_tags( strip_shortcodes( $raw ) ) ) );
		return '' === $raw ? '' : wp_trim_words( $raw, 40, '…' );
	}

	/**
	 * Pages to list in llms.txt: every published page (default), or only the
	 * curated set (named slots first, then extras) when Pages mode is "selected".
	 *
	 * @return WP_Post[]
	 */
	private function llmsPages(): array {
		if ( 'selected' === $this->options->str( 'ai.llms_pages_mode', 'all' ) ) {
			$ids = $this->selectedPageIds();
			if ( ! $ids ) {
				return [];
			}
			return get_posts(
				[
					'post_type'              => 'page',
					'post_status'            => 'publish',
					'post__in'               => $ids,
					'orderby'                => 'post__in',
					'posts_per_page'         => count( $ids ),
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				]
			);
		}

		return get_posts(
			[
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => 300,
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			]
		);
	}

	/** @return int[] curated page IDs — named slots first, then extras, de-duplicated. */
	private function selectedPageIds(): array {
		$ids = [];
		foreach ( [ 'about', 'contact', 'terms', 'privacy', 'shop' ] as $slot ) {
			$id = $this->options->int( 'ai.llms_page_' . $slot );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		foreach ( $this->options->arr( 'ai.llms_pages_extra' ) as $extra ) {
			$extra = (int) $extra;
			if ( $extra > 0 ) {
				$ids[] = $extra;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private static function oneLine( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
	}

	/** Turn HTML entities (&#8217;, &amp;, &hellip;, …) into real UTF-8 characters. */
	private static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	// --- Static /llms.txt at the site root --------------------------------
	//
	// Many servers (notably nginx) serve *.txt as static files and 404 a
	// virtual /llms.txt before WordPress runs, so the rewrite above only
	// answers /llms.txt/. Writing a real file makes the standard /llms.txt
	// resolve everywhere (Yoast does the same). We fall back to the virtual
	// route when the root isn't writable, holds a foreign file, or on
	// multisite (shared root). Rebuilds are deferred to a flag processed on
	// admin_init / wp-cron, so the write never lands on a front-end request.

	/** Always-on processors (registered from Ai, even when llms.txt is off). */
	public static function registerProcessors(): void {
		add_action( self::CRON_HOOK, [ self::class, 'processIfDirty' ] );
		add_action( 'admin_init', [ self::class, 'processIfDirty' ] );
	}

	/** Flag a rebuild and nudge wp-cron; the write happens off the front end. */
	public static function markDirty(): void {
		update_option( self::DIRTY_OPTION, '1', false );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + self::REBUILD_DELAY, self::CRON_HOOK );
		}
	}

	public static function processIfDirty(): void {
		if ( ! get_option( self::DIRTY_OPTION ) ) {
			return;
		}
		delete_option( self::DIRTY_OPTION ); // clear first so a concurrent request can't double-build
		self::sync( new Options() );
	}

	/**
	 * Reconcile the physical /llms.txt with current settings: write it when
	 * llms.txt is enabled and the root is usable, else remove the one we own.
	 */
	public static function sync( Options $options ): void {
		if ( ! $options->bool( 'ai.llms_enabled' ) ) {
			self::deleteStaticFile();
			delete_option( self::FAILED_OPTION );
			delete_option( self::DIRTY_OPTION );
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		$reason = self::blockedReason();
		if ( 'ok' !== $reason ) {
			if ( 'multisite' === $reason ) {
				delete_option( self::FAILED_OPTION ); // expected fallback — no notice
			} else {
				self::deleteStaticFile();                             // don't keep serving a stale owned copy
				update_option( self::FAILED_OPTION, $reason, false ); // 'foreign' | 'unwritable' — drives the notice
			}
			return;
		}

		if ( self::writeStaticFile( ( new self( $options ) )->buildIndex() ) ) {
			delete_option( self::FAILED_OPTION );
		} else {
			self::deleteStaticFile();
			update_option( self::FAILED_OPTION, 'unwritable', false );
		}
	}

	/** Why we can't manage a static file right now: 'ok' | 'multisite' | 'foreign' | 'unwritable'. */
	private static function blockedReason(): string {
		if ( is_multisite() ) {
			return 'multisite'; // shared document root — one file can't serve every site
		}
		$path = self::staticPath();
		if ( ! get_option( self::OWNED_OPTION ) && is_file( $path ) ) {
			return 'foreign'; // a pre-existing llms.txt we didn't create — never clobber it
		}
		if ( ! wp_is_writable( dirname( $path ) ) ) {
			return 'unwritable';
		}
		return 'ok';
	}

	private static function writeStaticFile( string $body ): bool {
		$path = self::staticPath();
		$dir  = dirname( $path );
		self::sweepTempFiles( $dir ); // clear litter from any crashed prior write

		// Lead with a UTF-8 BOM so browsers render it correctly when the web server
		// sends the static file as text/plain with no charset (nginx + nosniff).
		$body = "\xEF\xBB\xBF" . $body;

		$tmp = $dir . '/.heirloom-llms-' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $body, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return false;
		}

		update_option( self::OWNED_OPTION, '1', false );
		update_option( self::PATH_OPTION, $path, false ); // recorded so uninstall can find it past the path filter
		return true;
	}

	private static function sweepTempFiles( string $dir ): void {
		foreach ( glob( $dir . '/.heirloom-llms-*.tmp' ) ?: [] as $stale ) {
			@unlink( $stale ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/** Remove the physical /llms.txt — but only the one we created, and only if it's actually gone. */
	public static function deleteStaticFile(): void {
		if ( ! get_option( self::OWNED_OPTION ) ) {
			return;
		}
		$path = self::staticPath();
		if ( ! is_file( $path ) || @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			delete_option( self::OWNED_OPTION );
			delete_option( self::PATH_OPTION );
		}
	}

	public static function onDeactivate(): void {
		self::deleteStaticFile();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::FAILED_OPTION );
		delete_option( self::DIRTY_OPTION );
	}

	private static function staticPath(): string {
		/** Filterable for installs whose document root differs from ABSPATH (e.g. WordPress in a subdirectory). */
		return (string) apply_filters( 'heirloom_seo/llms_static_path', ABSPATH . self::FILENAME );
	}

	public static function maybeAdminNotice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! str_contains( (string) $screen->id, 'heirloom-seo' ) ) {
			return;
		}
		$reason = (string) get_option( self::FAILED_OPTION );
		if ( '' === $reason ) {
			return;
		}
		$message = 'foreign' === $reason
			? __( 'An llms.txt file already exists at your site root that Heirloom SEO didn’t create, so it’s left untouched. Remove that file to let Heirloom manage and serve /llms.txt.', 'heirloom-seo' )
			: __( 'Heirloom SEO couldn’t write llms.txt to your site root, so it’s served dynamically. On some servers the dynamic version resolves only at /llms.txt/ — make the site root writable, or add a server rule, to serve the standard /llms.txt.', 'heirloom-seo' );
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}
