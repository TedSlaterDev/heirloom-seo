<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Reading → "Discourage search engines from indexing this site".
 *
 * While it is ticked, WordPress prints noindex on every page and switches its
 * own sitemaps off. Heirloom follows suit with everything that would point a
 * crawler at the site's URLs: the XML sitemaps, /llms.txt, IndexNow pings and
 * the robots.txt Sitemap line (see SPEC.md "Search engine visibility").
 */
final class Visibility {

	/**
	 * Headers sent with the 404s answered while discouraged (/llms.txt, the
	 * IndexNow key): the same as a sitemap 404, so a page cache or CDN keeps it
	 * a minute at most and unticking the setting shows quickly. X-Accel-Expires
	 * replaces any value a host set earlier for a file-like URL.
	 */
	public const NOT_FOUND_HEADERS = [
		'Cache-Control: public, max-age=60',
		'X-Accel-Expires: 60',
	];

	/**
	 * True while search engines are discouraged. Read the way core reads it
	 * (wp_robots_noindex(), wp_sitemaps_enabled): any falsy stored value counts.
	 */
	public static function discouraged(): bool {
		return ! get_option( 'blog_public' );
	}
}
