<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Breadcrumbs;

use OrchardGrove\HeirloomSeo\Context;
use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\PageType;
use OrchardGrove\HeirloomSeo\Settings\Options;
use WP_Post;
use WP_Post_Type;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Breadcrumb trail. The same trail feeds the BreadcrumbList schema and the
 * visible markup (shortcode [heirloom_breadcrumbs] + template tag
 * heirloom_seo_breadcrumbs()). No CSS shipped — the theme styles it.
 */
final class Breadcrumbs implements ModuleInterface {

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_shortcode( 'heirloom_breadcrumbs', [ $this, 'shortcode' ] );
	}

	public function shortcode( mixed $atts = [] ): string {
		return self::render( Context::instance(), $this->options );
	}

	/**
	 * The crumb trail as an ordered list of ['name' => string, 'url' => string].
	 * The final crumb is the current page (its 'url' may be empty).
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	public static function trail( Context $context, Options $options ): array {
		$home = [
			'name' => $options->str( 'breadcrumbs.home_label', 'Home' ),
			'url'  => home_url( '/' ),
		];

		if ( $context->isStaticFront() || PageType::Front === $context->type() ) {
			return [ $home ];
		}

		$crumbs = [ $home ];
		$type   = $context->type();

		if ( PageType::Singular === $type && ( $post = $context->post() ) ) {
			$crumbs = array_merge( $crumbs, self::postCrumbs( $post ) );
		} elseif ( PageType::Home === $type ) {
			$page_id = (int) get_option( 'page_for_posts' );
			if ( $page_id ) {
				$crumbs[] = [ 'name' => get_the_title( $page_id ), 'url' => (string) get_permalink( $page_id ) ];
			}
		} elseif ( PageType::Term === $type && ( $term = $context->term() ) ) {
			$crumbs = array_merge( $crumbs, self::termCrumbs( $term ) );
		} elseif ( PageType::Author === $type && ( $user = $context->user() ) ) {
			$crumbs[] = [ 'name' => $user->display_name, 'url' => (string) get_author_posts_url( $user->ID ) ];
		} elseif ( PageType::PostTypeArchive === $type ) {
			$object = $context->object();
			if ( $object instanceof WP_Post_Type ) {
				$crumbs[] = [
					'name' => (string) $object->labels->name,
					'url'  => (string) get_post_type_archive_link( $object->name ),
				];
			}
		} elseif ( PageType::Search === $type ) {
			/* translators: %s: search query. */
			$crumbs[] = [ 'name' => sprintf( __( 'Search results for &ldquo;%s&rdquo;', 'heirloom-seo' ), get_search_query() ), 'url' => '' ];
		} elseif ( PageType::Date === $type ) {
			$crumbs[] = [ 'name' => wp_strip_all_tags( get_the_archive_title() ), 'url' => '' ];
		} elseif ( PageType::NotFound === $type ) {
			$crumbs[] = [ 'name' => __( 'Page not found', 'heirloom-seo' ), 'url' => '' ];
		}

		/** @var array<int,array{name:string,url:string}> $crumbs */
		return apply_filters( 'heirloom_seo/breadcrumbs/trail', $crumbs, $context );
	}

	public static function render( Context $context, Options $options ): string {
		$trail = self::trail( $context, $options );
		if ( count( $trail ) < 2 ) {
			return '';
		}

		$separator = $options->str( 'breadcrumbs.separator', '&raquo;' );
		$last      = count( $trail ) - 1;
		$items     = [];

		foreach ( array_values( $trail ) as $i => $crumb ) {
			$name = esc_html( $crumb['name'] );
			if ( $i === $last || '' === $crumb['url'] ) {
				$items[] = '<span class="hseo-breadcrumb-current" aria-current="page">' . $name . '</span>';
			} else {
				$items[] = '<a href="' . esc_url( $crumb['url'] ) . '">' . $name . '</a>';
			}
		}

		$glue = ' <span class="hseo-breadcrumb-sep" aria-hidden="true">' . wp_kses_post( $separator ) . '</span> ';

		return '<nav class="hseo-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'heirloom-seo' ) . '">'
			. implode( $glue, $items )
			. '</nav>';
	}

	/** @return array<int,array{name:string,url:string}> */
	private static function postCrumbs( WP_Post $post ): array {
		$out = [];

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
				$out[] = [ 'name' => get_the_title( $ancestor_id ), 'url' => (string) get_permalink( $ancestor_id ) ];
			}
		} elseif ( 'post' === $post->post_type ) {
			$terms = get_the_terms( $post, 'category' );
			if ( is_array( $terms ) && $terms ) {
				$out = array_merge( $out, self::termCrumbs( $terms[0] ) );
			}
		}

		$out[] = [ 'name' => get_the_title( $post ), 'url' => (string) get_permalink( $post ) ];
		return $out;
	}

	/** @return array<int,array{name:string,url:string}> */
	private static function termCrumbs( WP_Term $term ): array {
		$out = [];

		foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof WP_Term ) {
				$link  = get_term_link( $ancestor );
				$out[] = [ 'name' => $ancestor->name, 'url' => is_wp_error( $link ) ? '' : (string) $link ];
			}
		}

		$link  = get_term_link( $term );
		$out[] = [ 'name' => $term->name, 'url' => is_wp_error( $link ) ? '' : (string) $link ];
		return $out;
	}
}
