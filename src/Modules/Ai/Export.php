<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Ai;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what a post may be exposed through llms.txt. A post must be published,
 * on a viewable post type, NOT password-protected, and NOT marked noindex.
 */
final class Export {

	public static function allowed( WP_Post $post ): bool {
		$ok = 'publish' === $post->post_status
			&& is_post_type_viewable( $post->post_type )
			&& '' === (string) $post->post_password
			&& ! get_post_meta( $post->ID, '_heirloom_seo_noindex', true )
			&& ! get_post_meta( $post->ID, '_heirloom_seo_ai_exclude', true );

		/**
		 * Filters whether a post may be exposed through the AI endpoints.
		 *
		 * @param bool    $ok
		 * @param WP_Post $post
		 */
		return (bool) apply_filters( 'heirloom_seo/ai/exportable', $ok, $post );
	}
}
