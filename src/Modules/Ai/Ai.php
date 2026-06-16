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

		// /llms.txt static-file lifecycle — registered unconditionally so disabling
		// still removes the file, and pending rebuilds are processed on admin_init /
		// wp-cron (never on a front-end request). The settings hooks only fire when
		// the option is actually saved, so the front end pays nothing.
		LlmsTxt::registerProcessors();
		add_action( 'add_option_' . Options::OPTION, [ $this, 'onSettingsSaved' ] );
		add_action( 'update_option_' . Options::OPTION, [ $this, 'onSettingsSaved' ] );
		if ( is_admin() ) {
			add_action( 'admin_notices', [ LlmsTxt::class, 'maybeAdminNotice' ] );
		}
	}

	public function onSettingsSaved(): void {
		// Settings can be written during early boot (a data migration). buildIndex()
		// needs post types, registered on `init`, so defer when we're too early.
		if ( ! did_action( 'init' ) ) {
			LlmsTxt::markDirty();
			return;
		}
		LlmsTxt::sync( new Options() );
	}
}
