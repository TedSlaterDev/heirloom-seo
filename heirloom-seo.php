<?php
/**
 * Plugin Name:       Heirloom SEO
 * Plugin URI:        https://orchardgrove.com/
 * Description:       Lean, fast SEO essentials — meta tags, Open Graph, schema, sitemaps, IndexNow — without the bloat.
 * Version:           0.7.9
 * Requires PHP:      8.1
 * Requires at least: 6.0
 * Author:            Orchard Grove Media, LLC
 * Author URI:        https://orchardgrove.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       heirloom-seo
 * Domain Path:       /languages
 *
 * @package OrchardGrove\HeirloomSeo
 */

declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo;

defined( 'ABSPATH' ) || exit;

define( 'HEIRLOOM_SEO_VERSION', '0.7.9' );
define( 'HEIRLOOM_SEO_FILE', __FILE__ );
define( 'HEIRLOOM_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'HEIRLOOM_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'HEIRLOOM_SEO_BASENAME', plugin_basename( __FILE__ ) );

require_once HEIRLOOM_SEO_DIR . 'src/Autoloader.php';
Autoloader::register();

require_once HEIRLOOM_SEO_DIR . 'src/template-tags.php';

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
