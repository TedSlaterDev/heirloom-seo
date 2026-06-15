<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Media;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Social share image sizing.
 *
 * Resolves which registered image size to use for og:image and twitter:image,
 * preferring (in order): an explicit setting -> an existing `facebook`/`twitter`
 * size (e.g. registered in a theme's functions.php) -> the plugin's own
 * `heirloom_og` (1200x630) / `heirloom_twitter` (1600x900) -> `full`.
 *
 * Optionally registers those managed sizes when nothing suitable exists, and
 * crop-upscales undersized originals so the share sizes always hit their exact
 * dimensions — scoped to the share sizes only.
 */
final class Media implements ModuleInterface {

	public const OG_SIZE      = 'heirloom_og';
	public const TWITTER_SIZE = 'heirloom_twitter';

	private const OG_DIMS      = [ 1200, 630 ];
	private const TWITTER_DIMS = [ 1600, 900 ];

	/** @var array<int,array{0:int,1:int}>|null */
	private ?array $dimsMemo = null;

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_action( 'after_setup_theme', [ $this, 'registerSizes' ], 99 );

		if ( $this->options->bool( 'social.upscale_crops' ) ) {
			add_filter( 'image_resize_dimensions', [ $this, 'upscaleCrop' ], 10, 6 );
		}
	}

	public function registerSizes(): void {
		add_theme_support( 'post-thumbnails' );

		if ( ! $this->options->bool( 'social.generate_sizes' ) ) {
			return;
		}

		if (
			'auto' === $this->options->str( 'social.og_image_size', 'auto' )
			&& ! has_image_size( 'facebook' )
			&& ! has_image_size( self::OG_SIZE )
		) {
			add_image_size( self::OG_SIZE, self::OG_DIMS[0], self::OG_DIMS[1], true );
		}

		if (
			'auto' === $this->options->str( 'social.twitter_image_size', 'auto' )
			&& ! has_image_size( 'twitter' )
			&& ! has_image_size( self::TWITTER_SIZE )
		) {
			add_image_size( self::TWITTER_SIZE, self::TWITTER_DIMS[0], self::TWITTER_DIMS[1], true );
		}
	}

	/** The image size name to request for og:image. */
	public static function ogSize( Options $options ): string {
		$configured = $options->str( 'social.og_image_size', 'auto' );
		if ( 'auto' !== $configured && '' !== $configured && has_image_size( $configured ) ) {
			return $configured;
		}
		if ( has_image_size( 'facebook' ) ) {
			return 'facebook';
		}
		if ( has_image_size( self::OG_SIZE ) ) {
			return self::OG_SIZE;
		}
		return 'full';
	}

	/** The image size name to request for twitter:image. */
	public static function twitterSize( Options $options ): string {
		$configured = $options->str( 'social.twitter_image_size', 'auto' );
		if ( 'auto' !== $configured && '' !== $configured && has_image_size( $configured ) ) {
			return $configured;
		}
		if ( has_image_size( 'twitter' ) ) {
			return 'twitter';
		}
		if ( has_image_size( self::TWITTER_SIZE ) ) {
			return self::TWITTER_SIZE;
		}
		return 'full';
	}

	/**
	 * Crop-upscale undersized originals to the exact managed share dimensions.
	 *
	 * @param mixed     $default Passed through unchanged unless we override.
	 * @param int|false $orig_w
	 * @param int|false $orig_h
	 * @param int|false $dest_w
	 * @param int|false $dest_h
	 * @param bool      $crop
	 * @return mixed null/array per the image_resize_dimensions contract.
	 */
	public function upscaleCrop( $default, $orig_w, $orig_h, $dest_w, $dest_h, $crop ) {
		if ( ! $crop || ! $orig_w || ! $orig_h || ! $dest_w || ! $dest_h ) {
			return $default;
		}
		if ( ! $this->isManagedDim( (int) $dest_w, (int) $dest_h ) ) {
			return $default;
		}
		// Source already big enough — let WordPress crop normally (no upscale).
		if ( $orig_w >= $dest_w && $orig_h >= $dest_h ) {
			return $default;
		}

		$ratio  = max( $dest_w / $orig_w, $dest_h / $orig_h );
		$crop_w = (int) round( $dest_w / $ratio );
		$crop_h = (int) round( $dest_h / $ratio );
		$src_x  = (int) floor( ( $orig_w - $crop_w ) / 2 );
		$src_y  = (int) floor( ( $orig_h - $crop_h ) / 2 );

		return [ 0, 0, $src_x, $src_y, (int) $dest_w, (int) $dest_h, $crop_w, $crop_h ];
	}

	private function isManagedDim( int $width, int $height ): bool {
		foreach ( $this->managedDims() as [ $w, $h ] ) {
			if ( $w === $width && $h === $height ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<int,array{0:int,1:int}> */
	private function managedDims(): array {
		if ( null !== $this->dimsMemo ) {
			return $this->dimsMemo;
		}

		$dims  = [];
		$sizes = wp_get_registered_image_subsizes();
		foreach ( [ self::ogSize( $this->options ), self::twitterSize( $this->options ) ] as $name ) {
			if ( 'full' === $name ) {
				continue;
			}
			if ( isset( $sizes[ $name ]['width'], $sizes[ $name ]['height'] ) ) {
				$dims[] = [ (int) $sizes[ $name ]['width'], (int) $sizes[ $name ]['height'] ];
			}
		}

		return $this->dimsMemo = $dims;
	}
}
