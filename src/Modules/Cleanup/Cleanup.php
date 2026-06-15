<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Cleanup;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Optional removal of low-value <head> cruft. All toggles, all opt-in-ish
 * defaults that are safe for the vast majority of sites.
 */
final class Cleanup implements ModuleInterface {

	public function __construct( private Options $options ) {}

	public function register(): void {
		if ( $this->options->bool( 'cleanup.remove_generator' ) ) {
			add_filter( 'the_generator', '__return_empty_string' );
			remove_action( 'wp_head', 'wp_generator' );
		}
		if ( $this->options->bool( 'cleanup.remove_wlwmanifest' ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
		if ( $this->options->bool( 'cleanup.remove_rsd' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}
		if ( $this->options->bool( 'cleanup.remove_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
		if ( $this->options->bool( 'cleanup.remove_rest_link' ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}
		if ( $this->options->bool( 'cleanup.remove_oembed_links' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		}
	}
}
