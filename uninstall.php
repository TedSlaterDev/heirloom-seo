<?php
/**
 * Uninstall handler. Only deletes data when the user opted in via the
 * "Delete all plugin data when the plugin is deleted" setting.
 *
 * @package OrchardGrove\HeirloomSeo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$heirloom_seo_option = get_option( 'heirloom_seo', [] );

// The generated /llms.txt is a public serving artifact, not user data — remove it
// regardless of the data-deletion opt-in (deactivation usually handled it already).
// Use the path recorded at write time so a filtered (subdirectory) docroot is honored.
$heirloom_seo_llms = get_option( 'heirloom_seo_llms_static_path' );
if ( is_string( $heirloom_seo_llms ) && '' !== $heirloom_seo_llms && is_file( $heirloom_seo_llms ) ) {
	@unlink( $heirloom_seo_llms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
}
delete_option( 'heirloom_seo_llms_static' );
delete_option( 'heirloom_seo_llms_static_path' );
delete_option( 'heirloom_seo_llms_static_failed' );
delete_option( 'heirloom_seo_llms_dirty' );

// Same for the IndexNow {key}.txt verification file — a serving artifact, not user data.
$heirloom_seo_indexnow = get_option( 'heirloom_seo_indexnow_static_path' );
if ( is_string( $heirloom_seo_indexnow ) && '' !== $heirloom_seo_indexnow && is_file( $heirloom_seo_indexnow ) ) {
	@unlink( $heirloom_seo_indexnow ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
}
delete_option( 'heirloom_seo_indexnow_static' );
delete_option( 'heirloom_seo_indexnow_static_path' );
delete_option( 'heirloom_seo_indexnow_static_failed' );

// Remembered share-image URL lookups (Images::CACHE_PREFIX, "hseo_u2i_") are a
// cache, not user data — clear them regardless of the opt-in. With a persistent
// object cache they live there instead and simply expire.
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_hseo_u2i_' ) . '%', $wpdb->esc_like( '_transient_timeout_hseo_u2i_' ) . '%' ) ); // phpcs:ignore WordPress.DB

if ( ! is_array( $heirloom_seo_option ) || empty( $heirloom_seo_option['advanced']['delete_data_on_uninstall'] ) ) {
	return;
}

delete_option( 'heirloom_seo' );
delete_option( 'heirloom_seo_needs_flush' );
delete_option( 'heirloom_seo_version' );

global $wpdb;
$heirloom_seo_like = $wpdb->esc_like( '_heirloom_seo_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $heirloom_seo_like ) ); // phpcs:ignore WordPress.DB

// Per-author "hide from search engines" flag (Edit User screen).
delete_metadata( 'user', 0, 'heirloom_seo_noindex', '', true );

$heirloom_seo_uploads = wp_upload_dir();
$heirloom_seo_dir     = trailingslashit( $heirloom_seo_uploads['basedir'] ) . 'heirloom-seo-cache';
if ( is_dir( $heirloom_seo_dir ) ) {
	foreach ( glob( $heirloom_seo_dir . '/*' ) ?: [] as $heirloom_seo_file ) {
		@unlink( $heirloom_seo_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}
	@rmdir( $heirloom_seo_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
}
