<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Support;

use OrchardGrove\HeirloomSeo\Context;
use OrchardGrove\HeirloomSeo\PageType;
use WP_Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical / URL resolution. Single source of truth shared by the canonical
 * tag and og:url so they never disagree.
 */
final class Url {

	/** The canonical URL for the current request, or '' if the page should not be canonicalized. */
	public static function canonical( Context $context ): string {
		$post = $context->post();
		if ( $post ) {
			$override = get_post_meta( $post->ID, '_heirloom_seo_canonical', true );
			if ( is_string( $override ) && '' !== $override ) {
				return esc_url_raw( $override );
			}
		}

		$canonical = self::baseUrl( $context );

		/**
		 * Filters the canonical URL before output. Lets integrations — e.g. a
		 * cross-site importer crediting an original source — override it. The
		 * per-post override above still takes precedence. Return '' to suppress.
		 *
		 * @param string  $canonical The computed canonical URL.
		 * @param Context $context   The current request context.
		 */
		return (string) apply_filters( 'heirloom_seo/canonical', $canonical, $context );
	}

	/**
	 * The page's own URL on this site (its permalink), independent of any
	 * canonical override or cross-domain canonical filter. Used for og:url and
	 * schema node @ids so a cross-domain canonical never drags them off-site.
	 */
	public static function permalink( Context $context ): string {
		return self::baseUrl( $context );
	}

	private static function baseUrl( Context $context ): string {
		$paged = $context->isPaged();

		switch ( $context->type() ) {
			case PageType::Front:
				return ( ! $context->isStaticFront() && $paged ) ? self::pagenum( $context ) : home_url( '/' );

			case PageType::Home:
				$page_id = (int) get_option( 'page_for_posts' );
				if ( $paged ) {
					return self::pagenum( $context );
				}
				$link = $page_id ? get_permalink( $page_id ) : home_url( '/' );
				return is_string( $link ) ? $link : home_url( '/' );

			case PageType::Singular:
				$post = $context->post();
				$link = $post ? get_permalink( $post ) : '';
				return is_string( $link ) ? $link : '';

			case PageType::Term:
				$term = $context->term();
				$link = $term ? get_term_link( $term ) : '';
				$link = ( is_wp_error( $link ) || ! is_string( $link ) ) ? '' : $link;
				return $paged ? self::pagenum( $context ) : $link;

			case PageType::Author:
				$user = $context->user();
				$link = $user ? get_author_posts_url( $user->ID ) : '';
				return $paged ? self::pagenum( $context ) : ( is_string( $link ) ? $link : '' );

			case PageType::PostTypeArchive:
				$object = $context->object();
				$name   = $object instanceof WP_Post_Type ? $object->name : '';
				$link   = $name ? get_post_type_archive_link( $name ) : '';
				$link   = is_string( $link ) ? $link : '';
				return $paged ? self::pagenum( $context ) : $link;

			case PageType::Date:
				return self::pagenum( $context );

			case PageType::Search:
				$link = get_search_link();
				return is_string( $link ) ? $link : '';

			default:
				return '';
		}
	}

	private static function pagenum( Context $context ): string {
		$link = get_pagenum_link( $context->pageNumber() );
		return is_string( $link ) ? $link : '';
	}
}
