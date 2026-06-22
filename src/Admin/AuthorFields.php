<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Admin;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Support\FileCache;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * A "Heirloom SEO" section on the Edit User (author) profile screen.
 *
 * One control for now: keep an author out of search. When enabled it adds a
 * noindex directive to the author's archive (honored by the Robots module) and
 * drops the author from the XML sitemap (honored by the Sitemaps module).
 * Stored as the `heirloom_seo_noindex` user meta.
 *
 * Limited to users who can edit other users (admins), so an author can't quietly
 * hide their own archive — it's an editorial/SEO decision.
 */
final class AuthorFields implements ModuleInterface {

	private const META  = 'heirloom_seo_noindex';
	private const NONCE = 'heirloom_seo_author_fields';

	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'render' ] );
		add_action( 'edit_user_profile', [ $this, 'render' ] );
		add_action( 'personal_options_update', [ $this, 'save' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save' ] );
	}

	public function render( WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$noindex = (bool) get_user_meta( $user->ID, self::META, true );
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<h2><?php esc_html_e( 'Heirloom SEO', 'heirloom-seo' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Author archive', 'heirloom-seo' ); ?></th>
				<td>
					<label for="heirloom-seo-noindex">
						<input type="hidden" name="<?php echo esc_attr( self::META ); ?>" value="0" />
						<input type="checkbox" id="heirloom-seo-noindex" name="<?php echo esc_attr( self::META ); ?>" value="1" <?php checked( $noindex ); ?> />
						<?php esc_html_e( 'Hide this author from search engines', 'heirloom-seo' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Adds a &ldquo;noindex&rdquo; robots tag to this author&rsquo;s archive page and removes them from the XML sitemap.', 'heirloom-seo' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save( int $user_id ): void {
		$nonce = isset( $_POST[ self::NONCE . '_nonce' ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$was = (bool) get_user_meta( $user_id, self::META, true );
		$now = isset( $_POST[ self::META ] ) && '1' === (string) wp_unslash( $_POST[ self::META ] );
		if ( $now === $was ) {
			return;
		}

		if ( $now ) {
			update_user_meta( $user_id, self::META, '1' );
		} else {
			delete_user_meta( $user_id, self::META );
		}

		FileCache::purge(); // the author sitemap is cached — drop it so the change takes effect immediately
	}
}
