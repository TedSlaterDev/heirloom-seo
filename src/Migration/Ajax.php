<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Migration;

use OrchardGrove\HeirloomSeo\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * admin-ajax endpoint that drives the migration UI: `detect` lists sources with
 * importable data; `import` processes one batch so large sites never block.
 */
final class Ajax implements ModuleInterface {

	public const ACTION = 'heirloom_seo_migrate';

	private const BATCH = 100;

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( 'detect' === $op ) {
			wp_send_json_success( [ 'sources' => Importer::available() ] );
		}

		if ( 'import' === $op ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
			$key       = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
			$offset    = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
			$overwrite = ! empty( $_POST['overwrite'] );
			// phpcs:enable

			$source = Importer::source( $key );
			if ( ! $source ) {
				wp_send_json_error( [ 'message' => 'unknown source' ], 400 );
			}

			$result = ( new Importer() )->importBatch( $source, $offset, self::BATCH, $overwrite, false );
			wp_send_json_success(
				[
					'imported' => $result['imported'],
					'offset'   => $offset + self::BATCH,
					'done'     => $result['ids'] < self::BATCH,
				]
			);
		}

		wp_send_json_error( [ 'message' => 'bad op' ], 400 );
	}
}
