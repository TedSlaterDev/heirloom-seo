<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo;

defined( 'ABSPATH' ) || exit;

/**
 * A feature module. `register()` is called once during boot for enabled
 * modules; the module hooks itself into WordPress from there.
 */
interface ModuleInterface {
	public function register(): void;
}
