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
}
