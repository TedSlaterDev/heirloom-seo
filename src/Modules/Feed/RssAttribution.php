<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Feed;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Appends an attribution line to feed items, linking the post and the site —
 * helps with canonicalization when content is scraped from the RSS feed.
 */
final class RssAttribution implements ModuleInterface {

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_filter( 'the_content_feed', [ $this, 'append' ], 20 );
		add_filter( 'the_excerpt_rss', [ $this, 'append' ], 20 );
	}

	public function append( mixed $content ): string {
		$content = (string) $content;

		if ( ! is_feed() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post ) {
			return $content;
		}

		$template = $this->options->str( 'feed.rss_text' );
		if ( '' === $template ) {
			return $content;
		}

		$post_link = '<a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
		$site_link = '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>';

		$line = strtr( $template, [ '%post_link%' => $post_link, '%site_link%' => $site_link ] );

		return $content . "\n<p>" . $line . "</p>\n";
	}
}
