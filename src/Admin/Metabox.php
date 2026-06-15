<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Admin;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Modules\Schema\SchemaType;
use OrchardGrove\HeirloomSeo\Settings\Options;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Classic per-post metabox. Pure PHP + a little vanilla admin JS — no block
 * editor sidebar, no build step. Saves to individual post-meta keys.
 */
final class Metabox implements ModuleInterface {

	private const TEXT_FIELDS = [
		'_heirloom_seo_title',
		'_heirloom_seo_desc',
		'_heirloom_seo_canonical',
		'_heirloom_seo_og_image',
		'_heirloom_seo_schema_type',
	];

	private const BOOL_FIELDS = [
		'_heirloom_seo_noindex',
		'_heirloom_seo_nofollow',
		'_heirloom_seo_ai_exclude',
	];

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_action( 'init', [ $this, 'registerMeta' ] );
		add_action( 'add_meta_boxes', [ $this, 'addBox' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function registerMeta(): void {
		$schema = [
			'_heirloom_seo_title'       => 'string',
			'_heirloom_seo_desc'        => 'string',
			'_heirloom_seo_canonical'   => 'string',
			'_heirloom_seo_og_image'    => 'string',
			'_heirloom_seo_schema_type' => 'string',
			'_heirloom_seo_noindex'     => 'boolean',
			'_heirloom_seo_nofollow'    => 'boolean',
			'_heirloom_seo_ai_exclude'  => 'boolean',
		];

		foreach ( $schema as $key => $type ) {
			register_post_meta(
				'',
				$key,
				[
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => static fn( $allowed, $meta_key, $object_id ) => current_user_can( 'edit_post', (int) $object_id ),
					'sanitize_callback' => 'boolean' === $type
						? static fn( $value ) => (bool) $value
						: 'sanitize_text_field',
				]
			);
		}

		register_post_meta(
			'',
			'_heirloom_seo_jsonld',
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => static fn( $allowed, $meta_key, $object_id ) => current_user_can( 'edit_post', (int) $object_id ),
				'sanitize_callback' => static function ( $value ) {
					$decoded = json_decode( (string) $value, true );
					return is_array( $decoded ) ? (string) wp_json_encode( $decoded ) : '';
				},
			]
		);
	}

	public function addBox(): void {
		foreach ( $this->postTypes() as $post_type ) {
			add_meta_box(
				'heirloom_seo_box',
				__( 'Heirloom SEO', 'heirloom-seo' ),
				[ $this, 'render' ],
				$post_type,
				'normal',
				'high'
			);
		}
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( 'heirloom_seo_meta', 'heirloom_seo_nonce' );

		$title     = (string) get_post_meta( $post->ID, '_heirloom_seo_title', true );
		$desc      = (string) get_post_meta( $post->ID, '_heirloom_seo_desc', true );
		$canonical = (string) get_post_meta( $post->ID, '_heirloom_seo_canonical', true );
		$image     = (string) get_post_meta( $post->ID, '_heirloom_seo_og_image', true );
		$schema    = (string) get_post_meta( $post->ID, '_heirloom_seo_schema_type', true );
		$noindex    = (bool) get_post_meta( $post->ID, '_heirloom_seo_noindex', true );
		$nofollow   = (bool) get_post_meta( $post->ID, '_heirloom_seo_nofollow', true );
		$ai_exclude = (bool) get_post_meta( $post->ID, '_heirloom_seo_ai_exclude', true );
		$jsonld     = (string) get_post_meta( $post->ID, '_heirloom_seo_jsonld', true );

		$permalink = (string) get_permalink( $post );

		$featured    = (string) get_the_post_thumbnail_url( $post, 'large' );
		$default_img = $this->options->str( 'social.default_image' );
		$preview_img = '' !== $image ? $image : ( '' !== $featured ? $featured : $default_img );
		?>
		<div class="hseo-metabox">
			<div class="hseo-serp" aria-hidden="true">
				<div class="hseo-serp-media" data-featured="<?php echo esc_url( $featured ); ?>" data-default="<?php echo esc_url( $default_img ); ?>"<?php echo '' === $preview_img ? ' hidden' : ''; ?>>
					<img class="hseo-serp-img" src="<?php echo esc_url( $preview_img ); ?>" alt="" />
				</div>
				<div class="hseo-serp-title" data-fallback="<?php echo esc_attr( get_the_title( $post ) ); ?>"><?php echo esc_html( '' !== $title ? $title : get_the_title( $post ) ); ?></div>
				<div class="hseo-serp-url"><?php echo esc_html( $permalink ); ?></div>
				<div class="hseo-serp-desc"><?php echo esc_html( $desc ); ?></div>
			</div>

			<p>
				<label for="hseo-title"><strong><?php esc_html_e( 'SEO title', 'heirloom-seo' ); ?></strong></label><br />
				<input type="text" id="hseo-title" name="_heirloom_seo_title" class="widefat" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'Leave blank to use the title template', 'heirloom-seo' ); ?>" />
				<span class="description"><span class="hseo-count" data-for="hseo-title">0</span> <?php esc_html_e( 'characters', 'heirloom-seo' ); ?></span>
			</p>

			<p>
				<label for="hseo-desc"><strong><?php esc_html_e( 'Meta description', 'heirloom-seo' ); ?></strong></label><br />
				<textarea id="hseo-desc" name="_heirloom_seo_desc" class="widefat" rows="3" placeholder="<?php esc_attr_e( 'Leave blank to use the excerpt', 'heirloom-seo' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
				<span class="description"><span class="hseo-count" data-for="hseo-desc">0</span> <?php esc_html_e( 'characters', 'heirloom-seo' ); ?></span>
			</p>

			<p>
				<label for="hseo-image"><strong><?php esc_html_e( 'Social image', 'heirloom-seo' ); ?></strong></label><br />
				<input type="text" id="hseo-image" name="_heirloom_seo_og_image" class="widefat hseo-image-field" value="<?php echo esc_attr( $image ); ?>" placeholder="<?php esc_attr_e( 'Defaults to the featured image', 'heirloom-seo' ); ?>" />
				<button type="button" class="button button-small hseo-image-pick" data-target="#hseo-image"><?php esc_html_e( 'Select', 'heirloom-seo' ); ?></button>
				<button type="button" class="button button-small hseo-image-clear" data-target="#hseo-image"><?php esc_html_e( 'Clear', 'heirloom-seo' ); ?></button>
			</p>

			<details class="hseo-advanced">
				<summary><?php esc_html_e( 'Advanced', 'heirloom-seo' ); ?></summary>

				<p>
					<label for="hseo-canonical"><strong><?php esc_html_e( 'Canonical URL', 'heirloom-seo' ); ?></strong></label><br />
					<input type="url" id="hseo-canonical" name="_heirloom_seo_canonical" class="widefat" value="<?php echo esc_attr( $canonical ); ?>" placeholder="<?php echo esc_attr( $permalink ); ?>" />
				</p>

				<p>
					<label for="hseo-schema"><strong><?php esc_html_e( 'Schema type', 'heirloom-seo' ); ?></strong></label><br />
					<select id="hseo-schema" name="_heirloom_seo_schema_type">
						<option value=""><?php esc_html_e( 'Automatic', 'heirloom-seo' ); ?></option>
						<?php foreach ( SchemaType::choices() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $schema, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="hseo-checks">
					<label><input type="hidden" name="_heirloom_seo_noindex" value="0" /><input type="checkbox" name="_heirloom_seo_noindex" value="1" <?php checked( $noindex ); ?> /> <?php esc_html_e( 'Discourage search engines from indexing this (noindex)', 'heirloom-seo' ); ?></label><br />
					<label><input type="hidden" name="_heirloom_seo_nofollow" value="0" /><input type="checkbox" name="_heirloom_seo_nofollow" value="1" <?php checked( $nofollow ); ?> /> <?php esc_html_e( 'Add nofollow to links on this page', 'heirloom-seo' ); ?></label><br />
					<label><input type="hidden" name="_heirloom_seo_ai_exclude" value="0" /><input type="checkbox" name="_heirloom_seo_ai_exclude" value="1" <?php checked( $ai_exclude ); ?> /> <?php esc_html_e( 'Exclude from AI exports (llms.txt)', 'heirloom-seo' ); ?></label>
				</p>

				<p>
					<label for="hseo-jsonld"><strong><?php esc_html_e( 'Custom JSON-LD', 'heirloom-seo' ); ?></strong></label><br />
					<textarea id="hseo-jsonld" name="_heirloom_seo_jsonld" class="widefat" rows="4" placeholder="<?php esc_attr_e( 'Advanced: extra schema.org JSON-LD merged into this page&rsquo;s graph', 'heirloom-seo' ); ?>"><?php echo esc_textarea( $jsonld ); ?></textarea>
				</p>
			</details>
		</div>
		<?php
	}

	public function save( int $post_id, WP_Post $post ): void {
		if (
			! isset( $_POST['heirloom_seo_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['heirloom_seo_nonce'] ) ), 'heirloom_seo_meta' )
		) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( self::TEXT_FIELDS as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			if ( '_heirloom_seo_canonical' === $key && '' !== $value ) {
				$value = esc_url_raw( $value );
			}
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		foreach ( self::BOOL_FIELDS as $key ) {
			if ( ! empty( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, 1 );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}

		$jsonld = isset( $_POST['_heirloom_seo_jsonld'] ) ? trim( (string) wp_unslash( $_POST['_heirloom_seo_jsonld'] ) ) : '';
		if ( '' === $jsonld ) {
			delete_post_meta( $post_id, '_heirloom_seo_jsonld' );
		} else {
			$decoded = json_decode( $jsonld, true );
			if ( is_array( $decoded ) ) {
				update_post_meta( $post_id, '_heirloom_seo_jsonld', wp_json_encode( $decoded ) );
			}
		}
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'heirloom-seo-admin', HEIRLOOM_SEO_URL . 'assets/admin/admin.css', [], HEIRLOOM_SEO_VERSION );
		wp_enqueue_script( 'heirloom-seo-metabox', HEIRLOOM_SEO_URL . 'assets/admin/metabox.js', [ 'jquery' ], HEIRLOOM_SEO_VERSION, true );
	}

	/** @return string[] */
	private function postTypes(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}
}
