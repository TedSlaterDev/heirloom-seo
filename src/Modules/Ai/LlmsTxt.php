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

	private const QV          = 'heirloom_llms';
	private const PURGE_HOOKS = [ 'save_post', 'deleted_post' ];

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_action( 'init', [ $this, 'addRewrite' ] );
		add_filter( 'query_vars', [ $this, 'queryVars' ] );
		add_action( 'template_redirect', [ $this, 'maybeServe' ], 0 ); // Before redirect_canonical's trailing-slash redirect.
		foreach ( self::PURGE_HOOKS as $hook ) {
			add_action( $hook, [ FileCache::class, 'purge' ] );
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
		$title   = self::oneLine( get_the_title( $post ) );
		$url     = get_permalink( $post );
		$excerpt = self::oneLine( wp_strip_all_tags( get_the_excerpt( $post ) ) );

		$line = '- [' . $title . '](' . $url . ')';
		if ( '' !== $excerpt ) {
			$line .= ': ' . $excerpt;
		}
		return $line . "\n";
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
}
