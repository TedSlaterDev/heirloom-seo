<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Support;

use OrchardGrove\HeirloomSeo\Context;
use OrchardGrove\HeirloomSeo\Settings\Options;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the social-share image for a request:
 *   per-post override -> featured image -> first content image -> default -> site icon.
 *
 * The source is resolved once per request (og:image, twitter:image and the
 * schema #primaryimage each ask for it, at different sizes); callers get it at a
 * specific registered size (e.g. the Facebook 1200x630 size for og:image, the X
 * 1600x900 size for twitter:image). Raw-URL sources (overrides, content images,
 * URL defaults) cannot be resized and are returned as-is.
 *
 * A source stored as an uploads URL is mapped back to its attachment with
 * attachment_url_to_postid(), an unindexed meta_value scan that costs hundreds of
 * milliseconds on a large postmeta table. The answer is remembered across
 * requests in a transient keyed by the uploads-relative path that core matches
 * against _wp_attached_file, and forgotten whenever an attachment's file path is
 * written or the attachment is deleted (see registerCacheInvalidation()). A
 * remembered attachment is also re-checked on use, so a change made while those
 * hooks weren't running (plugin inactive or rolled back) can't leave it stale.
 */
final class Images {

	/** Transient-name prefix for remembered lookups. uninstall.php matches it. */
	public const CACHE_PREFIX = 'hseo_u2i_';

	private const HIT_TTL    = 30 * DAY_IN_SECONDS;
	private const MISS_TTL   = DAY_IN_SECONDS;
	private const ERROR_TTL  = 5 * MINUTE_IN_SECONDS;
	private const MEMO_LIMIT = 64;

	/** @var array{key:string,source:array{id:int}|array{url:string}|null}|null */
	private static ?array $sourceMemo = null;

	/** @var array<string,int> Cache key => attachment ID (0 = not an attachment), this request. */
	private static array $idMemo = [];

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

	/** Forget what this request has memoized (tests, or a mid-request change to the inputs). */
	public static function flush(): void {
		self::$sourceMemo = null;
		self::$idMemo     = [];
	}

	/**
	 * Keep remembered lookups honest. A lookup's answer can only change when an
	 * attachment's file path (_wp_attached_file) is written or the attachment is
	 * deleted, so forget the path an attachment is leaving (before the write),
	 * the path it takes (after), and everything a deleted attachment answered to.
	 * The post-meta handlers return at once for every other meta key.
	 */
	public static function registerCacheInvalidation(): void {
		add_action( 'add_attachment', [ self::class, 'onAttachmentAdded' ] );
		add_action( 'delete_attachment', [ self::class, 'onAttachmentDeleted' ] );
		add_action( 'update_post_meta', [ self::class, 'onAttachedFileLeaving' ], 10, 3 );
		add_action( 'delete_post_meta', [ self::class, 'onAttachedFileLeaving' ], 10, 3 );
		add_action( 'added_post_meta', [ self::class, 'onAttachedFileArrived' ], 10, 4 );
		add_action( 'updated_post_meta', [ self::class, 'onAttachedFileArrived' ], 10, 4 );
	}

	/**
	 * A new upload can land at a path remembered as "not an attachment".
	 *
	 * @param int|string $attachment_id
	 */
	public static function onAttachmentAdded( $attachment_id ): void {
		$id = (int) $attachment_id;
		self::forget( [ get_post_meta( $id, '_wp_attached_file', true ), self::pathOf( wp_get_attachment_url( $id ) ) ] );
	}

	/**
	 * Fires before the attachment's meta is removed, so everything still resolves.
	 * A big image is served as "-scaled", but its original URL may be cached too.
	 * The file path itself is included because the URLs pass through filters
	 * (a CDN or offload plugin may rewrite them) and the path does not.
	 *
	 * @param int|string $attachment_id
	 */
	public static function onAttachmentDeleted( $attachment_id ): void {
		$id = (int) $attachment_id;
		self::forget(
			[
				get_post_meta( $id, '_wp_attached_file', true ),
				self::pathOf( wp_get_attachment_url( $id ) ),
				self::pathOf( wp_get_original_image_url( $id ) ),
			]
		);
	}

	/**
	 * Before an attachment's file path changes (a big upload re-filed as
	 * "-scaled", an image-editor save or restore, a media-replace plugin) or is
	 * removed: forget the path it is leaving, whose remembered answer is about
	 * to go stale. Runs before the write, while the old value is still readable.
	 *
	 * @param int|int[]  $meta_id   Unused.
	 * @param int|string $object_id Post ID.
	 * @param string     $meta_key  Meta key being written.
	 */
	public static function onAttachedFileLeaving( $meta_id, $object_id, $meta_key ): void {
		if ( '_wp_attached_file' === $meta_key ) {
			self::forget( [ get_post_meta( (int) $object_id, '_wp_attached_file', true ) ] );
		}
	}

	/**
	 * After the write: forget the path the attachment now has, which may have
	 * been remembered as "not an attachment".
	 *
	 * @param int        $meta_id    Unused.
	 * @param int|string $object_id  Unused.
	 * @param string     $meta_key   Meta key written.
	 * @param mixed      $meta_value The file path written.
	 */
	public static function onAttachedFileArrived( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( '_wp_attached_file' === $meta_key ) {
			self::forget( [ $meta_value ] );
		}
	}

	/**
	 * The underlying image source, memoized for the request. The key covers every
	 * input read from the arguments: the post and the default-image setting.
	 *
	 * @return array{id:int}|array{url:string}|null
	 */
	private static function source( Context $context, Options $options ): ?array {
		$post          = $context->post();
		$default_image = $options->get( 'social.default_image' );
		$key           = ( $post ? $post->ID : 0 ) . '|' . ( is_scalar( $default_image ) ? (string) $default_image : '' );

		if ( null === self::$sourceMemo || self::$sourceMemo['key'] !== $key ) {
			self::$sourceMemo = [
				'key'    => $key,
				'source' => self::resolveSource( $post, $default_image ),
			];
		}
		return self::$sourceMemo['source'];
	}

	/**
	 * @return array{id:int}|array{url:string}|null
	 */
	private static function resolveSource( ?WP_Post $post, mixed $default_image ): ?array {
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

		if ( is_numeric( $default_image ) && (int) $default_image > 0 ) {
			return [ 'id' => (int) $default_image ];
		}
		if ( is_string( $default_image ) && '' !== $default_image ) {
			$by_url = self::urlToId( $default_image );
			return $by_url ? [ 'id' => $by_url ] : [ 'url' => esc_url_raw( $default_image ) ];
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
	 * Returns 0 for external URLs (left as-is), before touching any cache.
	 *
	 * Remembered per request, then in a transient. A remembered ID is looked up
	 * afresh unless it still names an attachment filed at the path it had when
	 * remembered; both reads are ones the renderer makes for it anyway.
	 */
	private static function urlToId( string $url ): int {
		$uploads = wp_get_upload_dir();
		if ( '' === $url || empty( $uploads['baseurl'] ) || ! str_contains( $url, (string) $uploads['baseurl'] ) ) {
			return 0;
		}

		$key = self::cacheKey( self::lookupPath( $url, (string) $uploads['baseurl'] ) );
		if ( isset( self::$idMemo[ $key ] ) ) {
			return self::$idMemo[ $key ];
		}

		$cached = get_transient( $key );
		$id     = is_array( $cached ) && isset( $cached['id'] ) ? (int) $cached['id'] : null;
		if ( null !== $id && $id > 0 && ! self::stillFiled( $id, $cached['file'] ?? null ) ) {
			$id = null; // Deleted or re-filed behind our back: look it up again; set_transient() replaces the entry.
		}

		if ( null === $id ) {
			$id = self::lookUp( $url, $key );
		}

		if ( count( self::$idMemo ) >= self::MEMO_LIMIT ) {
			self::$idMemo = []; // Bounded for long-running WP-CLI loops.
		}
		self::$idMemo[ $key ] = $id;

		return $id;
	}

	/**
	 * Run the slow lookup once and remember the answer: a hit for 30 days, a miss
	 * for a day. Core's lookup reads postmeta only, so it can name a post that is
	 * not an attachment (an orphaned or stray _wp_attached_file row); that is
	 * remembered as a miss, or it would be looked up again on every request. A
	 * miss caused by a failed query is remembered for minutes, not a day. A hit
	 * records the attachment's file path for stillFiled().
	 */
	private static function lookUp( string $url, string $key ): int {
		global $wpdb;

		$id     = (int) attachment_url_to_postid( $url );
		$failed = 0 === $id && isset( $wpdb->last_error ) && '' !== $wpdb->last_error;
		if ( $id > 0 && ! self::isAttachment( $id ) ) {
			$id = 0;
		}

		if ( $id > 0 ) {
			$entry = [
				'id'   => $id,
				'file' => get_post_meta( $id, '_wp_attached_file', true ),
			];
			$ttl   = self::HIT_TTL;
		} else {
			$entry = [ 'id' => 0 ];
			$ttl   = $failed ? self::ERROR_TTL : self::MISS_TTL;
		}
		set_transient( $key, $entry, $ttl );

		return $id;
	}

	/**
	 * A remembered hit stands while the attachment exists and is still filed at
	 * the path it had when remembered. An image-editor save, a "-scaled" re-file
	 * or a media-replace plugin moves it, after which core no longer maps the old
	 * URL to it.
	 */
	private static function stillFiled( int $id, mixed $file ): bool {
		return self::isAttachment( $id ) && get_post_meta( $id, '_wp_attached_file', true ) === $file;
	}

	/**
	 * What attachment_url_to_postid() compares with _wp_attached_file: the URL with
	 * the uploads base stripped. A URL that doesn't start with the base (core never
	 * maps it to an attachment) is keyed as given, so URL forms that core answers
	 * differently never share an entry.
	 */
	private static function lookupPath( string $url, string $baseurl ): string {
		$prefix = $baseurl . '/';
		return ( '' !== $baseurl && str_starts_with( $url, $prefix ) ) ? substr( $url, strlen( $prefix ) ) : $url;
	}

	/**
	 * The lookup path for a URL from WordPress (false or empty when there is none).
	 *
	 * @param mixed $url
	 */
	private static function pathOf( $url ): ?string {
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$uploads = wp_get_upload_dir();
		return self::lookupPath( $url, empty( $uploads['baseurl'] ) ? '' : (string) $uploads['baseurl'] );
	}

	private static function cacheKey( string $path ): string {
		return self::CACHE_PREFIX . md5( $path );
	}

	private static function isAttachment( int $id ): bool {
		$post = get_post( $id );
		return $post instanceof WP_Post && 'attachment' === $post->post_type;
	}

	/**
	 * @param array<int,mixed> $paths Lookup paths; anything that isn't a non-empty string is skipped.
	 */
	private static function forget( array $paths ): void {
		$keys = [];
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && '' !== $path ) {
				$keys[ self::cacheKey( $path ) ] = true;
			}
		}
		foreach ( array_keys( $keys ) as $key ) {
			delete_transient( $key );
		}
		self::flush();
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
