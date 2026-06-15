<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Robots;

use OrchardGrove\HeirloomSeo\Context;
use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\PageType;
use OrchardGrove\HeirloomSeo\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Robots meta directives (via the wp_robots filter) and robots.txt additions.
 */
final class Robots implements ModuleInterface {

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_filter( 'wp_robots', [ $this, 'filterRobots' ] );
		add_filter( 'robots_txt', [ $this, 'filterRobotsTxt' ], 10, 2 );
	}

	/**
	 * @param array<string,mixed> $robots
	 * @return array<string,mixed>
	 */
	public function filterRobots( array $robots ): array {
		$context = Context::instance();
		$robots  = $this->applyDirectives( $robots );

		$post = $context->post();
		if ( $post ) {
			if ( get_post_meta( $post->ID, '_heirloom_seo_noindex', true ) ) {
				$robots['noindex'] = true;
			}
			if ( get_post_meta( $post->ID, '_heirloom_seo_nofollow', true ) ) {
				$robots['nofollow'] = true;
			}
		}

		if ( $this->shouldNoindex( $context ) ) {
			$robots['noindex'] = true;
		}

		if ( ! empty( $robots['noindex'] ) ) {
			$robots['follow'] = empty( $robots['nofollow'] );
			unset( $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
		}

		if ( ! empty( $robots['nofollow'] ) ) {
			unset( $robots['follow'] );
		}

		return $robots;
	}

	/**
	 * @param array<string,mixed> $robots
	 * @return array<string,mixed>
	 */
	private function applyDirectives( array $robots ): array {
		// wp_robots() prints string values as "directive:value" and bare keys for
		// truthy non-strings, so numeric directives must be passed as strings.
		$robots['max-snippet']       = (string) $this->options->int( 'robots.max_snippet', -1 );
		$robots['max-video-preview'] = (string) $this->options->int( 'robots.max_video_preview', -1 );

		$image_preview = $this->options->str( 'robots.max_image_preview', 'large' );
		if ( in_array( $image_preview, [ 'none', 'standard', 'large' ], true ) ) {
			$robots['max-image-preview'] = $image_preview;
		}

		return $robots;
	}

	private function shouldNoindex( Context $context ): bool {
		$type = $context->type();

		if ( PageType::NotFound === $type ) {
			return true;
		}
		if ( PageType::Search === $type && $this->options->bool( 'robots.noindex_search' ) ) {
			return true;
		}
		if ( PageType::Author === $type && $this->options->bool( 'robots.noindex_author' ) ) {
			return true;
		}
		if ( PageType::Date === $type && $this->options->bool( 'robots.noindex_date' ) ) {
			return true;
		}
		if ( PageType::Term === $type ) {
			$term = $context->term();
			if ( $term && 'post_tag' === $term->taxonomy && $this->options->bool( 'robots.noindex_tag' ) ) {
				return true;
			}
		}
		if ( $context->isPaged() && $this->options->bool( 'robots.noindex_paginated' ) ) {
			return true;
		}

		return false;
	}

	public function filterRobotsTxt( string $output, bool $public ): string {
		if ( $this->options->bool( 'sitemaps.enabled' ) ) {
			$output .= "\nSitemap: " . esc_url( home_url( '/sitemap.xml' ) ) . "\n";
		}

		$custom = trim( $this->options->str( 'robots.robots_txt' ) );
		if ( '' !== $custom ) {
			$output .= "\n" . $custom . "\n";
		}

		return $output;
	}
}
