<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\IndexNow;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Settings\Options;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * IndexNow: pings Bing/Yandex/etc. (not Google) when content is published,
 * updated, or unpublished. URLs are batched per request and submitted
 * non-blocking on shutdown. Serves the {key}.txt verification file.
 */
final class IndexNow implements ModuleInterface {

	private const ENDPOINT  = 'https://api.indexnow.org/indexnow';
	private const QUERY_VAR = 'heirloom_indexnow_key';

	private const OWNED_OPTION  = 'heirloom_seo_indexnow_static';        // '1' while we manage a physical {key}.txt.
	private const PATH_OPTION   = 'heirloom_seo_indexnow_static_path';   // the resolved path we wrote (survives key rotation + uninstall).
	private const FAILED_OPTION = 'heirloom_seo_indexnow_static_failed'; // '' | 'foreign' | 'unwritable' — drives the admin notice.

	/** IndexNow keys are [a-zA-Z0-9-], 8–128 chars. Also our path-traversal guard. */
	private const KEY_PATTERN = '/^[A-Za-z0-9\-]{8,128}$/';

	/** @var array<string,true> */
	private array $queue = [];

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_action( 'init', [ $this, 'addRewrite' ] );
		add_filter( 'query_vars', [ $this, 'queryVars' ] );
		add_action( 'template_redirect', [ $this, 'maybeServeKey' ], 0 ); // Before redirect_canonical.
		add_action( 'transition_post_status', [ $this, 'onTransition' ], 10, 3 );
		add_action( 'shutdown', [ $this, 'flush' ] );
	}

	public function addRewrite(): void {
		$key = $this->key();
		if ( '' === $key ) {
			return;
		}
		add_rewrite_rule( '^' . preg_quote( $key, '/' ) . '\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function queryVars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybeServeKey(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		$key = $this->key();
		if ( '' === $key ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $key );
		exit;
	}

	public function onTransition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		// Relevant when publishing, editing a published post, or unpublishing one.
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}

		$url = get_permalink( $post );
		if ( $url ) {
			$this->queue[ $url ] = true;
		}
	}

	public function flush(): void {
		if ( ! $this->queue ) {
			return;
		}
		$urls        = array_keys( $this->queue );
		$this->queue = [];

		$key = $this->key();
		if ( '' === $key ) {
			return;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return;
		}

		$payload = [
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList'     => array_values( $urls ),
		];

		wp_remote_post(
			self::ENDPOINT,
			[
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => [ 'Content-Type' => 'application/json; charset=utf-8' ],
				'body'     => (string) wp_json_encode( $payload ),
			]
		);
	}

	/** Submit URLs to IndexNow synchronously (used by WP-CLI). */
	public function submitNow( array $urls ): bool {
		$urls = array_values( array_filter( array_map( 'strval', $urls ) ) );
		if ( ! $urls ) {
			return false;
		}
		$key = $this->key();
		if ( '' === $key ) {
			return false;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => 10,
				'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
				'body'    => (string) wp_json_encode(
					[
						'host'        => $host,
						'key'         => $key,
						'keyLocation' => home_url( '/' . $key . '.txt' ),
						'urlList'     => $urls,
					]
				),
			]
		);

		return ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 400;
	}

	private function key(): string {
		return $this->options->str( 'indexnow.key' );
	}

	public static function generateKey(): string {
		return wp_generate_password( 32, false, false );
	}

	// --- Static {key}.txt at the site root --------------------------------
	//
	// Many servers (notably nginx) serve *.txt as static files and 404 the
	// virtual key file before WordPress runs, so the rewrite above never gets
	// a chance to answer — IndexNow then rejects every submission because the
	// key can't be verified, silently. Writing a real file fixes it everywhere.
	// Same constraint LlmsTxt solves; see that module for the sibling pattern.
	//
	// Note: unlike llms.txt the FILENAME derives from the key, so a rotated key
	// must delete the file we previously owned (tracked in PATH_OPTION) rather
	// than the one the current key resolves to. And the body is the bare key —
	// no BOM, no trailing newline: Bing compares file contents to the key.

	/**
	 * Reconcile the physical {key}.txt with current settings: write it when
	 * IndexNow is enabled with a usable key and root, else remove the one we own.
	 */
	public static function sync( Options $options ): void {
		$key = $options->str( 'indexnow.key' );

		if ( ! $options->bool( 'indexnow.enabled' ) || ! preg_match( self::KEY_PATTERN, $key ) ) {
			self::deleteStaticFile();
			delete_option( self::FAILED_OPTION );
			return;
		}

		$path = self::staticPath( $key );

		// Key rotated (or the path filter moved): remove the file we owned under
		// the old name before writing the new one, so we don't litter the root.
		$owned = (string) get_option( self::PATH_OPTION, '' );
		if ( '' !== $owned && $owned !== $path ) {
			self::deleteStaticFile();
		}

		$reason = self::blockedReason( $path, $key );
		if ( 'ok' !== $reason ) {
			if ( 'multisite' === $reason ) {
				delete_option( self::FAILED_OPTION ); // expected fallback — no notice
			} else {
				self::deleteStaticFile();
				update_option( self::FAILED_OPTION, $reason, false );
			}
			return;
		}

		if ( self::writeStaticFile( $path, $key ) ) {
			delete_option( self::FAILED_OPTION );
		} else {
			self::deleteStaticFile();
			update_option( self::FAILED_OPTION, 'unwritable', false );
		}
	}

	/** Why we can't manage a static file right now: 'ok' | 'multisite' | 'foreign' | 'unwritable'. */
	private static function blockedReason( string $path, string $key ): string {
		if ( is_multisite() ) {
			return 'multisite'; // shared document root — one file can't serve every site
		}
		if ( ! get_option( self::OWNED_OPTION ) && is_file( $path ) ) {
			// A key file we didn't write. If it already contains exactly this key it is
			// correct and safe to adopt (commonly one the operator created by hand to
			// work around this very bug); anything else we leave strictly alone.
			$existing = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( ! is_string( $existing ) || trim( $existing ) !== $key ) {
				return 'foreign';
			}
		}
		if ( ! wp_is_writable( dirname( $path ) ) ) {
			return 'unwritable';
		}
		return 'ok';
	}

	private static function writeStaticFile( string $path, string $key ): bool {
		$dir = dirname( $path );
		self::sweepTempFiles( $dir ); // clear litter from any crashed prior write

		$tmp = $dir . '/.heirloom-indexnow-' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $key, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return false;
		}

		update_option( self::OWNED_OPTION, '1', false );
		update_option( self::PATH_OPTION, $path, false );
		return true;
	}

	private static function sweepTempFiles( string $dir ): void {
		foreach ( glob( $dir . '/.heirloom-indexnow-*.tmp' ) ?: [] as $stale ) {
			@unlink( $stale ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/** Remove the physical key file — but only the one we created, at the path we recorded. */
	public static function deleteStaticFile(): void {
		if ( ! get_option( self::OWNED_OPTION ) ) {
			return;
		}
		$path = (string) get_option( self::PATH_OPTION, '' );
		if ( '' === $path ) {
			delete_option( self::OWNED_OPTION ); // nothing recorded to remove
			return;
		}
		if ( ! is_file( $path ) || @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			delete_option( self::OWNED_OPTION );
			delete_option( self::PATH_OPTION );
		}
	}

	public static function onDeactivate(): void {
		self::deleteStaticFile();
		delete_option( self::FAILED_OPTION );
	}

	private static function staticPath( string $key ): string {
		/** Filterable for installs whose document root differs from ABSPATH (e.g. WordPress in a subdirectory). */
		return (string) apply_filters( 'heirloom_seo/indexnow_static_path', ABSPATH . $key . '.txt', $key );
	}

	public static function maybeAdminNotice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! str_contains( (string) $screen->id, 'heirloom-seo' ) ) {
			return;
		}
		$reason = (string) get_option( self::FAILED_OPTION );
		if ( '' === $reason ) {
			return;
		}
		$message = 'foreign' === $reason
			? __( 'A file with your IndexNow key name already exists at your site root that Heirloom SEO didn’t create, and its contents don’t match the key, so it’s left untouched. Remove it (or correct its contents) to let Heirloom manage key verification.', 'heirloom-seo' )
			: __( 'Heirloom SEO couldn’t write the IndexNow key file to your site root. Many servers serve .txt statically and never reach WordPress, so search engines may fail to verify the key and reject submissions. Make the site root writable, or create the key file manually.', 'heirloom-seo' );
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}
