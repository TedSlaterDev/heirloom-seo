<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Support;

use OrchardGrove\HeirloomSeo\Context;
use OrchardGrove\HeirloomSeo\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the social-share image for a request:
 *   per-post override -> featured image -> first content image -> default -> site icon.
 *
 * The source is resolved once; callers ask for it at a specific registered size
 * (e.g. the Facebook 1200x630 size for og:image, the X 1600x900 size for
 * twitter:image). Raw-URL sources (overrides, content images, URL defaults)
 * cannot be resized and are returned as-is.
 */
final class Images {

	/**
	 * @return array{url:string,width:int,height:int,alt:string}|null
	 */
	public static function forContext( Context $context, Options $options, string $size = 'full' ): ?array {
		$source = self::source( $context, $options );
		if ( null === $source ) {
			return null;
		}

		if ( isset( $source['id'] ) ) {
			return self::fromAttachment( $source['id'], $size );
		}

		return [
			'url'    => $source['url'],
			'width'  => 0,
			'height' => 0,
			'alt'    => '',
		];
	}

	/**
	 * The underlying image source: an attachment ID (resizable) or a raw URL.
	 *
	 * @return array{id:int}|array{url:string}|null
	 */
	private static function source( Context $context, Options $options ): ?array {
		$post = $context->post();

		if ( $post ) {
			$override = get_post_meta( $post->ID, '_heirloom_seo_og_image', true );
			if ( is_numeric( $override ) && (int) $override > 0 ) {
				return [ 'id' => (int) $override ];
			}
			if ( is_string( $override ) && '' !== $override ) {
				$by_url = self::urlToId( $override );
				return $by_url ? [ 'id' => $by_url ] : [ 'url' => esc_url_raw( $override ) ];
			}

			$thumb_id = get_post_thumbnail_id( $post );
			if ( $thumb_id ) {
				return [ 'id' => (int) $thumb_id ];
			}

			$content_image = self::firstContentImage( (string) $post->post_content );
			if ( null !== $content_image ) {
				return [ 'url' => $content_image ];
			}
		}

		$default = $options->get( 'social.default_image' );
		if ( is_numeric( $default ) && (int) $default > 0 ) {
			return [ 'id' => (int) $default ];
		}
		if ( is_string( $default ) && '' !== $default ) {
			$by_url = self::urlToId( $default );
			return $by_url ? [ 'id' => $by_url ] : [ 'url' => esc_url_raw( $default ) ];
		}

		$icon_id = (int) get_option( 'site_icon' );
		if ( $icon_id ) {
			return [ 'id' => $icon_id ];
		}

		return null;
	}

	/**
	 * Recover an attachment ID from a local uploads URL, so a media-library
	 * selection stored as a URL still resizes to the registered share sizes.
	 * Returns 0 for external URLs (left as-is).
	 */
	private static function urlToId( string $url ): int {
		$uploads = wp_get_upload_dir();
		if ( '' === $url || empty( $uploads['baseurl'] ) || ! str_contains( $url, (string) $uploads['baseurl'] ) ) {
			return 0;
		}
		return (int) attachment_url_to_postid( $url );
	}

	/**
	 * @return array{url:string,width:int,height:int,alt:string}|null
	 */
	private static function fromAttachment( int $id, string $size ): ?array {
		$src = wp_get_attachment_image_src( $id, $size );
		if ( ! $src || empty( $src[0] ) ) {
			// Requested size not generated for this attachment — fall back to full.
			$src = wp_get_attachment_image_src( $id, 'full' );
		}
		if ( ! $src || empty( $src[0] ) ) {
			return null;
		}

		$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );

		return [
			'url'    => (string) $src[0],
			'width'  => (int) ( $src[1] ?? 0 ),
			'height' => (int) ( $src[2] ?? 0 ),
			'alt'    => is_string( $alt ) ? $alt : '',
		];
	}

	private static function firstContentImage( string $content ): ?string {
		if ( ! str_contains( $content, '<img' ) ) {
			return null;
		}
		if ( preg_match( '/<img[^>]+src=(["\'])(.*?)\1/i', $content, $matches ) ) {
			return esc_url_raw( $matches[2] );
		}
		return null;
	}
}
