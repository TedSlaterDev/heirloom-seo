<?php
/**
 * Global template tags for theme authors. Loaded in the global namespace.
 *
 * @package OrchardGrove\HeirloomSeo
 */

use OrchardGrove\HeirloomSeo\Context;
use OrchardGrove\HeirloomSeo\Modules\Breadcrumbs\Breadcrumbs;
use OrchardGrove\HeirloomSeo\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'heirloom_seo_breadcrumbs' ) ) {
	/**
	 * Output (or return) the breadcrumb trail for the current request.
	 *
	 * @param bool $echo Whether to echo. Pass false to return the markup.
	 */
	function heirloom_seo_breadcrumbs( bool $echo = true ): string {
		$html = Breadcrumbs::render( Context::instance(), Plugin::instance()->options() );
		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped within render().
		}
		return $html;
	}
}
