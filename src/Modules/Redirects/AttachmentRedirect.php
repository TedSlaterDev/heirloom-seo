<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Redirects;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Settings\Options;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects thin attachment pages to their parent post (default) or the file
 * itself, so they stop competing for crawl budget and rankings.
 *
 * This is the ONLY redirect behavior in the plugin — there is no redirect
 * manager (no old->new URL redirects on slug changes) and no 404 monitor.
 */
final class AttachmentRedirect implements ModuleInterface {

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybeRedirect' ] );
	}

	public function maybeRedirect(): void {
		if ( ! is_attachment() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$file = (string) wp_get_attachment_url( $post->ID );

		if ( 'file' === $this->options->str( 'redirects.target', 'parent' ) ) {
			$target = $file;
		} else {
			$parent = (int) $post->post_parent;
			$target = $parent ? (string) get_permalink( $parent ) : $file;
		}

		if ( '' === $target ) {
			$target = home_url( '/' );
		}

		if ( $target !== get_permalink( $post ) ) {
			$target_host = wp_parse_url( $target, PHP_URL_HOST );
			if ( $target_host ) {
				// The file URL is our own media, even when offloaded to a CDN —
				// allow it so wp_safe_redirect() doesn't bounce to wp-admin.
				add_filter(
					'allowed_redirect_hosts',
					static function ( array $hosts ) use ( $target_host ): array {
						$hosts[] = $target_host;
						return $hosts;
					}
				);
			}
			wp_safe_redirect( $target, 301, 'Heirloom SEO' );
			exit;
		}
	}
}
