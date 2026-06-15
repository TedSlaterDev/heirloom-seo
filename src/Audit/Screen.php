<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Audit;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The "SEO Health" admin screen — a submenu under Heirloom SEO.
 */
final class Screen implements ModuleInterface {

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenu' ], 11 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function addMenu(): void {
		add_submenu_page(
			'heirloom-seo',
			__( 'SEO Health', 'heirloom-seo' ),
			__( 'SEO Health', 'heirloom-seo' ),
			'manage_options',
			'heirloom-seo-health',
			[ $this, 'render' ]
		);
	}

	public function assets( string $hook ): void {
		if ( false === strpos( $hook, 'heirloom-seo-health' ) ) {
			return;
		}
		wp_enqueue_style( 'heirloom-seo-admin', HEIRLOOM_SEO_URL . 'assets/admin/admin.css', [], HEIRLOOM_SEO_VERSION );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$audit = new Audit( $this->options );

		$run = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['run'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'heirloom_seo_audit' ) ) {
			$run = true;
		}

		$findings = $audit->configChecks();
		if ( $run ) {
			$findings = array_merge( $findings, $audit->httpChecks(), $audit->contentChecks() );
		}
		$status  = $audit->status();
		$run_url = wp_nonce_url( add_query_arg( [ 'page' => 'heirloom-seo-health', 'run' => '1' ], admin_url( 'admin.php' ) ), 'heirloom_seo_audit' );
		?>
		<div class="wrap hseo-settings">
			<div class="hseo-header">
				<div class="hseo-brand">
					<span class="hseo-mark" aria-hidden="true"><span class="dashicons dashicons-heart"></span></span>
					<div class="hseo-brand-text">
						<h1><?php esc_html_e( 'SEO Health', 'heirloom-seo' ); ?></h1>
						<p class="hseo-tagline"><?php esc_html_e( 'Practical diagnostics — no score, no gimmicks.', 'heirloom-seo' ); ?></p>
					</div>
				</div>
			</div>
			<hr class="wp-header-end" />

			<div class="hseo-layout">
				<div class="hseo-main">
					<div class="hseo-card">
						<ul class="hseo-audit">
							<?php foreach ( $findings as $finding ) : ?>
								<li class="hseo-audit-<?php echo esc_attr( $finding['status'] ); ?>">
									<span class="hseo-audit-dot" aria-hidden="true"></span>
									<span class="hseo-audit-body"><strong><?php echo esc_html( $finding['label'] ); ?></strong><br /><span class="description"><?php echo esc_html( $finding['detail'] ); ?></span></span>
								</li>
							<?php endforeach; ?>
						</ul>

						<?php if ( ! $run ) : ?>
							<p style="margin-top:1rem">
								<a href="<?php echo esc_url( $run_url ); ?>" class="button button-primary"><?php esc_html_e( 'Run full scan', 'heirloom-seo' ); ?></a>
								<span class="description"><?php esc_html_e( 'Adds sitemap / IndexNow reachability and a content scan of up to 5,000 posts.', 'heirloom-seo' ); ?></span>
							</p>
						<?php endif; ?>

						<p class="description" style="margin-top:1rem">
							<?php
							/* translators: %s: date/time. */
							printf( esc_html__( 'Sitemap cache last built: %s', 'heirloom-seo' ), esc_html( $status['cache_built'] ) );
							?>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
