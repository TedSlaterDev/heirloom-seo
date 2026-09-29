<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey\Functions;

/**
 * uninstall.php runs with the plugin unloaded, so it is executed as a script
 * against a $wpdb double that records its queries.
 */
final class UninstallTest extends TestCase {

	/** @var object{options:string,postmeta:string,queries:string[]} */
	private object $wpdb;

	/** @var array<string,mixed> */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();

		// Without this the script's own guard exit()s — silently ending the whole run.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'heirloom-seo/heirloom-seo.php' );
		}

		$this->store = [];

		$this->wpdb      = $this->recordingWpdb();
		$GLOBALS['wpdb'] = $this->wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the double the script under test reads.

		Functions\when( 'get_option' )->alias( fn( $name, $fallback = false ) => $this->store[ $name ] ?? $fallback );
		Functions\when( 'delete_option' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function testClearsRememberedImageLookupsWithoutTheDataOptIn(): void {
		// A cache, not user data: it goes even when the owner keeps their settings.
		$this->store['heirloom_seo'] = [ 'advanced' => [ 'delete_data_on_uninstall' => false ] ];

		$this->uninstall();

		$this->assertSame( [ $this->transientPurge() ], $this->wpdb->queries );
	}

	public function testClearsThemAlongsideTheDataWhenOptedIn(): void {
		$this->store['heirloom_seo'] = [ 'advanced' => [ 'delete_data_on_uninstall' => true ] ];
		Functions\when( 'delete_metadata' )->justReturn( true );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => sys_get_temp_dir() . '/heirloom-uninstall-' . bin2hex( random_bytes( 4 ) ) ] );
		Functions\when( 'trailingslashit' )->alias( static fn( $path ) => rtrim( (string) $path, '/' ) . '/' );

		$this->uninstall();

		$this->assertSame( $this->transientPurge(), $this->wpdb->queries[0] ?? null );
		$this->assertStringStartsWith( 'DELETE FROM wp_postmeta', $this->wpdb->queries[1] ?? '' );
		$this->assertCount( 2, $this->wpdb->queries );
	}

	/** Both the value rows and their timeout rows, with LIKE wildcards escaped. */
	private function transientPurge(): string {
		$like = static fn( string $prefix ): string => "'" . addcslashes( $prefix, '_%\\' ) . "%'";
		return 'DELETE FROM wp_options WHERE option_name LIKE ' . $like( '_transient_hseo_u2i_' )
			. ' OR option_name LIKE ' . $like( '_transient_timeout_hseo_u2i_' );
	}

	private function uninstall(): void {
		require dirname( __DIR__ ) . '/uninstall.php';
	}

	/** A $wpdb that records queries and implements just what uninstall.php calls. */
	private function recordingWpdb(): object {
		return new class() {
			public string $options  = 'wp_options';
			public string $postmeta = 'wp_postmeta';

			/** @var string[] */
			public array $queries = [];

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, string ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function query( string $query ): int {
				$this->queries[] = $query;
				return 0;
			}
		};
	}
}
