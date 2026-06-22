<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Authors;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Settings\Options;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Honors the per-author "Hide this author from search engines" toggle (the
 * heirloom_seo_noindex user meta, set on the Edit User screen) beyond the
 * archive noindex (Robots) and sitemap exclusion (Sitemaps):
 *
 *   - the visible byline + author-archive link are replaced with the site /
 *     organization name (front end + feeds; never in wp-admin, so editors still
 *     see the real author);
 *   - the author is dropped from the public REST API users endpoint, the classic
 *     enumeration vector (anonymous callers only — the block editor is unaffected).
 *
 * The Schema module reads the same flag via self::isHidden() to swap the article's
 * author from the Person to the Organization. This class is the single source of
 * truth for the meta key and the "is this author hidden?" check.
 */
final class Authors implements ModuleInterface {

	public const META = 'heirloom_seo_noindex';

	public function __construct( private Options $options ) {}

	public static function isHidden( int $user_id ): bool {
		return $user_id > 0 && (bool) get_user_meta( $user_id, self::META, true );
	}

	public function register(): void {
		if ( ! is_admin() ) {
			add_filter( 'the_author', [ $this, 'filterAuthorName' ] );
			add_filter( 'the_author_posts_link', [ $this, 'filterAuthorLink' ] );
		}
		add_filter( 'rest_user_query', [ $this, 'filterRestUserQuery' ], 10, 1 );
		add_filter( 'rest_prepare_user', [ $this, 'filterRestUser' ], 10, 2 );
	}

	/** Replace a hidden author's display name (covers the_author()/get_the_author()). */
	public function filterAuthorName( $display_name ) {
		global $authordata;
		if ( $authordata instanceof WP_User && self::isHidden( (int) $authordata->ID ) ) {
			return $this->bylineLabel();
		}
		return $display_name;
	}

	/** Replace the linked byline with plain text (drops the link to the hidden archive). */
	public function filterAuthorLink( $link ) {
		global $authordata;
		if ( $authordata instanceof WP_User && self::isHidden( (int) $authordata->ID ) ) {
			return esc_html( $this->bylineLabel() );
		}
		return $link;
	}

	/**
	 * Drop hidden authors from the REST users collection for callers who can't
	 * list users (anonymous / front end). Authenticated editors are unaffected.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function filterRestUserQuery( $args ) {
		if ( ! is_array( $args ) || current_user_can( 'list_users' ) ) {
			return $args;
		}
		$hidden = $this->hiddenIds();
		if ( $hidden ) {
			$existing        = isset( $args['exclude'] ) ? (array) $args['exclude'] : [];
			$args['exclude'] = array_merge( $existing, $hidden );
		}
		return $args;
	}

	/** Neutralize the single-user endpoint (/wp/v2/users/<id>) for a hidden author. */
	public function filterRestUser( $response, $user ) {
		if ( current_user_can( 'list_users' ) || ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		if ( $user instanceof WP_User && self::isHidden( (int) $user->ID ) ) {
			$data = $response->get_data();
			foreach ( [ 'name', 'slug', 'description', 'link', 'url', 'avatar_urls' ] as $field ) {
				if ( isset( $data[ $field ] ) ) {
					$data[ $field ] = is_array( $data[ $field ] ) ? [] : '';
				}
			}
			$response->set_data( $data );
		}
		return $response;
	}

	/** @return int[] IDs of authors flagged "hide from search". Queried once per request. */
	private function hiddenIds(): array {
		static $ids = null;
		if ( null === $ids ) {
			$ids = array_map( 'intval', get_users( [ 'meta_key' => self::META, 'meta_value' => '1', 'fields' => 'ID' ] ) );
		}
		return $ids;
	}

	private function bylineLabel(): string {
		$name = $this->options->str( 'schema.org_name' );
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}
}
