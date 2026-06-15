<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessor over the single autoloaded option row (`heirloom_seo`).
 *
 * Stored values are deep-merged over defaults once and read by dotted path:
 *   $options->str( 'titles.post' );
 *   $options->bool( 'robots.noindex_search' );
 */
final class Options {

	public const OPTION = 'heirloom_seo';

	private array $data;
	private ?array $merged = null;

	public function __construct() {
		$stored     = get_option( self::OPTION, [] );
		$this->data = is_array( $stored ) ? $stored : [];
	}

	public function defaults(): array {
		$defaults = [
			'general'      => [
				'separator' => '–',
			],
			'titles'       => [
				'front'    => '%sitename% %sep% %tagline%',
				'home'     => '%sitename% %sep% %tagline%',
				'post'     => '%title% %sep% %sitename% %sep% by %author%',
				'page'     => '%title% %sep% %sitename%',
				'term'     => '%term_title% %sep% %sitename%',
				'author'   => '%author% %sep% %sitename%',
				'archive'  => '%archive_title% %sep% %sitename%',
				'search'   => 'You searched for %search% %sep% %sitename%',
				'notfound' => 'Page not found %sep% %sitename%',
			],
			'social'       => [
				'default_image'      => '',
				'twitter_site'       => '',
				'facebook_app'       => '',
				'og_image_size'      => 'auto',
				'twitter_image_size' => 'auto',
				'generate_sizes'     => true,
				'upscale_crops'      => true,
			],
			'schema'       => [
				'site_represents'  => 'organization', // 'organization' | 'localbusiness' | 'person'.
				'org_name'         => '',             // Falls back to site name.
				'org_logo'         => '',             // Falls back to site icon.
				'person_id'        => 0,
				'news_term'        => 'News', // Fallback when no category/tag slug is chosen.
				'news_category'    => '',     // Category slug.
				'news_tag'         => '',     // Tag slug.
				'address_street'   => '',
				'address_locality' => '',
				'address_region'   => '',
				'address_postal'   => '',
				'address_country'  => '',
				'phone'            => '',
				'price_range'      => '',
				'sameas'           => '',
				'woo_product'      => false,
			],
			'robots'       => [
				'noindex_author'    => false,
				'noindex_date'      => true,
				'noindex_search'    => true,
				'noindex_tag'       => false,
				'noindex_paginated' => false,
				'max_snippet'       => -1,
				'max_image_preview' => 'large',
				'max_video_preview' => -1,
				'robots_txt'        => '',
			],
			'sitemaps'     => [
				'enabled'      => true,
				'post_types'   => [], // Empty = all public.
				'taxonomies'   => [], // Empty = all public.
				'authors'      => false,
				'images'       => true,
				'news_enabled' => true,
				'per_page'     => 1000,
			],
			'indexnow'     => [
				'enabled' => false,
				'key'     => '',
			],
			'verification' => [
				'google'    => '',
				'bing'      => '',
				'pinterest' => '',
				'baidu'     => '',
			],
			'redirects'    => [
				'attachments' => true,
				'target'      => 'parent', // 'parent' | 'file'.
			],
			'feed'         => [
				'rss_attribution' => true,
				'rss_text'        => 'The post %post_link% appeared first on %site_link%.',
			],
			'breadcrumbs'  => [
				'enabled'    => true,
				'home_label' => 'Home',
				'separator'  => '&raquo;',
			],
			'ai'           => [
				'llms_enabled'    => false,
				'llms_mode'       => 'auto', // 'auto' | 'manual'.
				'llms_intro'      => '',
				'llms_content'    => '',
				'llms_max_posts'  => 50,
				'llms_pages_mode' => 'all', // 'all' | 'selected'.
				'llms_page_about'   => 0,
				'llms_page_contact' => 0,
				'llms_page_terms'   => 0,
				'llms_page_privacy' => 0,
				'llms_page_shop'    => 0,
				'llms_pages_extra'  => [], // Additional page IDs.
				'blocked_bots'    => [],
			],
			'cleanup'      => [
				'remove_generator'    => true,
				'remove_wlwmanifest'  => true,
				'remove_rsd'          => true,
				'remove_shortlink'    => false,
				'remove_rest_link'    => false,
				'remove_oembed_links' => false,
			],
			'advanced'     => [
				'delete_data_on_uninstall' => false,
				'force_title'              => false,
			],
		];

		return apply_filters( 'heirloom_seo/default_options', $defaults );
	}

	public function get( string $path, mixed $default = null ): mixed {
		$value = $this->merged();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
			} else {
				return $default;
			}
		}
		return $value;
	}

	public function bool( string $path ): bool {
		return (bool) $this->get( $path, false );
	}

	public function str( string $path, string $default = '' ): string {
		$value = $this->get( $path, $default );
		return is_scalar( $value ) ? (string) $value : $default;
	}

	public function int( string $path, int $default = 0 ): int {
		return (int) $this->get( $path, $default );
	}

	public function arr( string $path ): array {
		$value = $this->get( $path, [] );
		return is_array( $value ) ? $value : [];
	}

	public function all(): array {
		return $this->merged();
	}

	public function raw(): array {
		return $this->data;
	}

	public function save( array $data ): void {
		$this->data   = $data;
		$this->merged = null;
		update_option( self::OPTION, $data, true );
	}

	public function seedDefaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, [], '', 'yes' );
		}
	}

	private function merged(): array {
		return $this->merged ??= self::deepMerge( $this->defaults(), $this->data );
	}

	private static function deepMerge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			if (
				is_array( $value )
				&& isset( $base[ $key ] )
				&& is_array( $base[ $key ] )
				&& self::isAssoc( $base[ $key ] )
			) {
				$base[ $key ] = self::deepMerge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	private static function isAssoc( array $array ): bool {
		if ( [] === $array ) {
			return true;
		}
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}
}
