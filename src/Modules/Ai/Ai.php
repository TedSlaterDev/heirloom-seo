<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Ai;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinator for the AI surface. Each sub-feature is constructed and hooked
 * only when enabled, so a fully-off AI tab costs nothing at runtime.
 */
final class Ai implements ModuleInterface {

	public function __construct( private Options $options ) {}

	public function register(): void {
		$o = $this->options;

		if ( $o->bool( 'ai.llms_enabled' ) ) {
			( new LlmsTxt( $o ) )->register();
		}
		if ( $o->arr( 'ai.blocked_bots' ) ) {
			( new Crawlers( $o ) )->register();
		}
	}
}
