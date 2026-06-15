<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Runs migration in batches. Non-destructive (never deletes source data) and
 * idempotent (skip-existing unless overwrite). The batch model keeps it safe
 * on very large sites — the admin runs it over AJAX, the CLI over a loop.
 */
final class Importer {

	/** @return SourceInterface[] */
	public static function sources(): array {
		return [ new Yoast(), new RankMath(), new Aioseo(), new TheSeoFramework() ];
	}

	public static function source( string $key ): ?SourceInterface {
		foreach ( self::sources() as $source ) {
			if ( $source->key() === $key ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * Sources that actually have data to import.
	 *
	 * @return array<int,array{key:string,label:string,total:int}>
	 */
	public static function available(): array {
		$out = [];
		foreach ( self::sources() as $source ) {
			$total = $source->total();
			if ( $total > 0 ) {
				$out[] = [
					'key'   => $source->key(),
					'label' => $source->label(),
					'total' => $total,
				];
			}
		}
		return $out;
	}

	/**
	 * Import one batch.
	 *
	 * @return array{ids:int,imported:int} IDs seen in the batch, and posts touched.
	 */
	public function importBatch( SourceInterface $source, int $offset, int $limit, bool $overwrite, bool $dry_run ): array {
		$ids      = $source->postIds( $offset, $limit );
		$imported = 0;

		foreach ( $ids as $post_id ) {
			$map = $source->mapPost( $post_id );
			if ( ! $map ) {
				continue;
			}

			$touched = false;
			foreach ( $map as $meta_key => $value ) {
				if ( ! $overwrite && '' !== (string) get_post_meta( $post_id, $meta_key, true ) ) {
					continue;
				}
				if ( $dry_run ) {
					$touched = true;
					continue;
				}
				if ( is_bool( $value ) ) {
					if ( $value ) {
						update_post_meta( $post_id, $meta_key, 1 );
						$touched = true;
					}
				} else {
					$clean = self::sanitize( $meta_key, (string) $value );
					if ( '' !== $clean ) {
						update_post_meta( $post_id, $meta_key, $clean );
						$touched = true;
					}
				}
			}
			if ( $touched ) {
				$imported++;
			}
		}

		return [ 'ids' => count( $ids ), 'imported' => $imported ];
	}

	private static function sanitize( string $meta_key, string $value ): string {
		if ( '_heirloom_seo_canonical' === $meta_key ) {
			return esc_url_raw( $value );
		}
		return sanitize_text_field( $value );
	}
}
