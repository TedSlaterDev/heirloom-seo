<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\HeirloomSeo\Modules\IndexNow\IndexNow;
use OrchardGrove\HeirloomSeo\Settings\Options;

/**
 * The physical {key}.txt at the site root. nginx serves *.txt statically and
 * 404s the virtual route before WordPress runs, so without a real file on disk
 * search engines cannot verify the key and reject every submission.
 */
final class IndexNowKeyFileTest extends TestCase {

	private string $root = '';

	/** @var array<string,mixed> */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();

		$this->root  = sys_get_temp_dir() . '/heirloom-indexnow-' . bin2hex( random_bytes( 6 ) );
		$this->store = [];
		mkdir( $this->root, 0777, true );

		// In-memory option store.
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->store[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->store[ $name ] );
				return true;
			}
		);

		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_is_writable' )->alias( static fn( $dir ) => is_writable( $dir ) );
		Functions\when( 'wp_generate_password' )->alias( static fn( $len = 12 ) => substr( bin2hex( random_bytes( 16 ) ), 0, (int) $len ) );
		// Route the key file into our temp root instead of ABSPATH.
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value, ...$args ) =>
				'heirloom_seo/indexnow_static_path' === $hook
					? $this->root . '/' . $args[0] . '.txt'
					: $value
		);
	}

	protected function tearDown(): void {
		foreach ( glob( $this->root . '/*' ) ?: [] as $f ) {
			@unlink( $f );
		}
		foreach ( glob( $this->root . '/.*' ) ?: [] as $f ) {
			if ( ! is_dir( $f ) ) {
				@unlink( $f );
			}
		}
		@rmdir( $this->root );
		parent::tearDown();
	}

	/** @param array<string,mixed> $indexnow */
	private function options( array $indexnow ): Options {
		$this->store['heirloom_seo'] = [ 'indexnow' => $indexnow ];
		return new Options();
	}

	private function path( string $key ): string {
		return $this->root . '/' . $key . '.txt';
	}

	public function testWritesKeyFileContainingExactlyTheKey(): void {
		$key = 'wdQgcISujjH1ZYq6PI21GXxtZgcpMa8g';
		IndexNow::sync( $this->options( [ 'enabled' => true, 'key' => $key ] ) );

		$this->assertFileExists( $this->path( $key ) );
		// Byte-exact: Bing compares file contents to the key. No BOM, no newline.
		$this->assertSame( $key, file_get_contents( $this->path( $key ) ) );
		$this->assertSame( '1', $this->store['heirloom_seo_indexnow_static'] );
		$this->assertSame( $this->path( $key ), $this->store['heirloom_seo_indexnow_static_path'] );
	}

	public function testRotatingTheKeyRemovesTheOldFile(): void {
		$old = 'oldkeyoldkeyoldkeyoldkeyoldkey11';
		$new = 'newkeynewkeynewkeynewkeynewkey22';

		IndexNow::sync( $this->options( [ 'enabled' => true, 'key' => $old ] ) );
		$this->assertFileExists( $this->path( $old ) );

		IndexNow::sync( $this->options( [ 'enabled' => true, 'key' => $new ] ) );
		$this->assertFileDoesNotExist( $this->path( $old ), 'the superseded key file must not be left behind' );
		$this->assertFileExists( $this->path( $new ) );
		$this->assertSame( $new, file_get_contents( $this->path( $new ) ) );
	}

	public function testDisablingRemovesTheFile(): void {
		$key = 'disableddisableddisableddisabl01';
		IndexNow::sync( $this->options( [ 'enabled' => true, 'key' => $key ] ) );
		$this->assertFileExists( $this->path( $key ) );

		IndexNow::sync( $this->options( [ 'enabled' => false, 'key' => $key ] ) );
		$this->assertFileDoesNotExist( $this->path( $key ) );
		$this->assertArrayNotHasKey( 'heirloom_seo_indexnow_static', $this->store );
	}

	public function testAdoptsAnExistingFileThatAlreadyHoldsTheCorrectKey(): void {
		// The common real-world case: an operator created the file by hand to work
		// around the missing-file bug. It is already correct, so adopt rather than warn.
		$key = 'adoptmeadoptmeadoptmeadoptme0001';
		file_put_contents( $this->path( $key ), $key );

		IndexNow::sync( $this->options( [ 'enabled' => true, 'key' => $key ] ) );

		$this->assertFileExists( $this->path( $key ) );
		$this->assertSame( $key, file_get_contents( $this->path( $key ) ) );
		$this->assertArrayNotHasKey( 'heirloom_seo_indexnow_static_failed', $this->store );
	}

	public function testLeavesAForeignFileAloneAndFlagsIt(): void {
		$key = 'foreignforeignforeignforeign0001';
		file_put_contents( $this->path( $key ), 'something the operator put here' );

		IndexNow::sync( $this->options( [ 'enabled' => true, 'key' => $key ] ) );

		$this->assertSame( 'something the operator put here', file_get_contents( $this->path( $key ) ) );
		$this->assertSame( 'foreign', $this->store['heirloom_seo_indexnow_static_failed'] );
	}

	public function testRejectsKeysThatCouldEscapeTheRoot(): void {
		// A key is interpolated into a filesystem path; traversal must never write outside root.
		foreach ( [ '../../etc/passwd', 'a/b', 'short', '', 'has space' ] as $bad ) {
			IndexNow::sync( $this->options( [ 'enabled' => true, 'key' => $bad ] ) );
			$this->assertArrayNotHasKey( 'heirloom_seo_indexnow_static', $this->store, "key '{$bad}' must be refused" );
		}
	}

	public function testMultisiteIsSkippedSilently(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$key = 'multisitemultisitemultisite00001';

		IndexNow::sync( $this->options( [ 'enabled' => true, 'key' => $key ] ) );

		$this->assertFileDoesNotExist( $this->path( $key ) );
		$this->assertArrayNotHasKey( 'heirloom_seo_indexnow_static_failed', $this->store, 'shared docroot is an expected fallback, not a warning' );
	}

	public function testLeavesNoTempFilesBehind(): void {
		$key = 'temptemptemptemptemptemptemp0001';
		IndexNow::sync( $this->options( [ 'enabled' => true, 'key' => $key ] ) );
		$this->assertSame( [], glob( $this->root . '/.heirloom-indexnow-*.tmp' ) ?: [] );
	}
}
