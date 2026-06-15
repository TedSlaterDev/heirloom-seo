<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Meta;

use OrchardGrove\HeirloomSeo\Context;
use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Modules\Media\Media;
use OrchardGrove\HeirloomSeo\PageType;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Support\Images;
use OrchardGrove\HeirloomSeo\Support\Url;
use WP_Post;
use WP_Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Head meta: <title>, meta description, canonical, Open Graph, Twitter cards,
 * and search-engine verification tags. A single renderer at wp_head priority 1
 * keeps ordering deterministic and avoids per-tag hook overhead.
 */
final class Meta implements ModuleInterface {

	private ?string $titleMemo = null;
	private ?string $descMemo  = null;
	/** @var array<string,array{url:string,width:int,height:int,alt:string}|null> */
	private array $imageMemo = [];

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_action( 'after_setup_theme', [ $this, 'addThemeSupport' ] );
		add_filter( 'pre_get_document_title', [ $this, 'filterTitle' ], 20 );
		add_filter( 'document_title_separator', [ $this, 'filterSeparator' ] );

		// Opt-in fallback for themes that print their own <title> (e.g. a legacy
		// wp_title() call) that our pre_get_document_title filter can't override.
		if ( $this->options->bool( 'advanced.force_title' ) ) {
			add_action( 'template_redirect', [ $this, 'startTitleBuffer' ], 1 );
		}

		// We emit our own canonical everywhere, so drop core's singular-only one.
		remove_action( 'wp_head', 'rel_canonical' );

		add_action( 'wp_head', [ $this, 'renderHead' ], 1 );
		add_filter( 'user_contactmethods', [ $this, 'addTwitterContactMethod' ] );
	}

	public function addThemeSupport(): void {
		add_theme_support( 'title-tag' );
	}

	/**
	 * Opt-in (advanced.force_title): when a theme prints its own <title> — most
	 * often a legacy wp_title() call in header.php — that our title filter can't
	 * reach, buffer the page and force a single, correct <title>. Off by default,
	 * so only sites that need it pay the one-pass output-buffer cost.
	 */
	public function startTitleBuffer(): void {
		if ( PageType::Feed === Context::instance()->type() ) {
			return;
		}
		ob_start( [ $this, 'rewriteTitleTag' ] );
	}

	public function rewriteTitleTag( string $html ): string {
		$title = $this->title( Context::instance() );
		if ( '' === $title || false === stripos( $html, '<title' ) ) {
			return $html;
		}

		$replacement = '<title>' . esc_html( $title ) . '</title>';
		$seen        = false;
		$result      = preg_replace_callback(
			'#<title\b[^>]*>.*?</title>#is',
			static function ( array $m ) use ( $replacement, &$seen ): string {
				if ( $seen ) {
					return ''; // Collapse any extra <title> tags into the first.
				}
				$seen = true;
				return $replacement;
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * Expose an X (Twitter) username field on user profiles so twitter:creator
	 * can be per-author. Won't clobber a label another plugin already set.
	 *
	 * @param array<string,string> $methods
	 * @return array<string,string>
	 */
	public function addTwitterContactMethod( array $methods ): array {
		if ( ! isset( $methods['twitter'] ) ) {
			$methods['twitter'] = __( 'X (Twitter) username', 'heirloom-seo' );
		}
		return $methods;
	}

	public function filterSeparator( string $sep ): string {
		$custom = $this->options->str( 'general.separator' );
		return '' !== $custom ? $custom : $sep;
	}

	public function filterTitle( string $title ): string {
		$computed = $this->title( Context::instance() );
		return '' !== $computed ? $computed : $title;
	}

	public function renderHead(): void {
		$context = Context::instance();
		if ( PageType::Feed === $context->type() ) {
			return;
		}

		$description = $this->description( $context );
		$canonical   = Url::canonical( $context );

		$lines   = [];
		$lines[] = '<!-- Heirloom SEO ' . esc_html( HEIRLOOM_SEO_VERSION ) . ' -->';
		$lines[] = $this->metaName( 'description', $description );

		if ( '' !== $canonical ) {
			$lines[] = sprintf( '<link rel="canonical" href="%s" />', esc_url( $canonical ) );
		}

		$lines = array_merge(
			$lines,
			$this->openGraph( $context, $canonical, $description ),
			$this->twitter( $context, $description )
		);

		if ( $this->isSiteRoot( $context ) ) {
			$lines = array_merge( $lines, $this->verification() );
		}

		$lines[] = '<!-- /Heirloom SEO -->';

		// Each line is individually escaped at construction time.
		echo "\n" . implode( "\n", array_filter( $lines ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function title( Context $context ): string {
		return $this->titleMemo ??= $this->computeTitle( $context );
	}

	public function description( Context $context ): string {
		return $this->descMemo ??= $this->computeDescription( $context );
	}

	private function computeTitle( Context $context ): string {
		$post = $context->post();
		if ( $post ) {
			$override = get_post_meta( $post->ID, '_heirloom_seo_title', true );
			if ( is_string( $override ) && '' !== $override ) {
				return $this->replaceVars( $override, $context );
			}
		}

		$template = match ( true ) {
			$context->isStaticFront(), PageType::Front === $context->type() => $this->options->str( 'titles.front' ),
			PageType::Home === $context->type()             => $this->options->str( 'titles.home' ),
			PageType::Singular === $context->type()         => $this->options->str( ( $post && 'page' === $post->post_type ) ? 'titles.page' : 'titles.post' ),
			PageType::Term === $context->type()             => $this->options->str( 'titles.term' ),
			PageType::Author === $context->type()           => $this->options->str( 'titles.author' ),
			PageType::PostTypeArchive === $context->type(),
			PageType::Date === $context->type()             => $this->options->str( 'titles.archive' ),
			PageType::Search === $context->type()           => $this->options->str( 'titles.search' ),
			PageType::NotFound === $context->type()         => $this->options->str( 'titles.notfound' ),
			default                                         => '',
		};

		return '' === $template ? '' : $this->replaceVars( $template, $context );
	}

	private function replaceVars( string $template, Context $context ): string {
		$post = $context->post();
		$term = $context->term();
		$sep  = $this->options->str( 'general.separator', '–' );

		$replacements = [
			'%sitename%'      => get_bloginfo( 'name' ),
			'%tagline%'       => get_bloginfo( 'description' ),
			'%sep%'           => $sep,
			'%title%'         => $post ? get_the_title( $post ) : '',
			'%term_title%'    => $term ? $term->name : '',
			'%author%'        => $this->authorName( $context ),
			'%archive_title%' => wp_strip_all_tags( $this->archiveTitle( $context ) ),
			'%search%'        => get_search_query(),
			'%category%'      => $post ? $this->primaryCategoryName( $post ) : '',
			'%page%'          => $context->isPaged() ? (string) $context->pageNumber() : '',
			'%excerpt%'       => $post ? wp_strip_all_tags( get_the_excerpt( $post ) ) : '',
		];

		$out = strtr( $template, $replacements );
		$out = trim( (string) preg_replace( '/\s+/', ' ', $out ) );

		// Trim stray separators left behind by empty variables.
		$sep_q = preg_quote( trim( $sep ), '/' );
		if ( '' !== $sep_q ) {
			$out = (string) preg_replace( "/^(?:{$sep_q}\s*)+|(?:\s*{$sep_q})+$/u", '', $out );
		}

		return trim( $out );
	}

	/**
	 * The author display name for %author%. On an author archive it's the queried
	 * user; on a singular post/page the queried object is the post, so fall back to
	 * that post's author (this is why %author% needs more than $context->user()).
	 */
	private function authorName( Context $context ): string {
		if ( $user = $context->user() ) {
			return (string) $user->display_name;
		}
		$post = $context->post();
		if ( $post && $post->post_author ) {
			return (string) get_the_author_meta( 'display_name', (int) $post->post_author );
		}
		return '';
	}

	private function computeDescription( Context $context ): string {
		$raw = '';

		if ( $post = $context->post() ) {
			$override = get_post_meta( $post->ID, '_heirloom_seo_desc', true );
			$raw      = ( is_string( $override ) && '' !== $override ) ? $override : get_the_excerpt( $post );
		} elseif ( $term = $context->term() ) {
			$raw = term_description( $term );
		} elseif ( PageType::Author === $context->type() && ( $user = $context->user() ) ) {
			$raw = get_the_author_meta( 'description', $user->ID );
		} elseif ( $this->isSiteRoot( $context ) ) {
			$raw = get_bloginfo( 'description' );
		}

		$raw = wp_strip_all_tags( strip_shortcodes( (string) $raw ) );
		$raw = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );

		return $this->truncate( $raw, 160 );
	}

	/** @return string[] */
	private function openGraph( Context $context, string $canonical, string $description ): array {
		$out   = [];
		$out[] = $this->metaProp( 'og:locale', get_locale() );
		$out[] = $this->metaProp( 'og:type', $this->ogType( $context ) );
		$out[] = $this->metaProp( 'og:title', $this->title( $context ) );
		$out[] = $this->metaProp( 'og:description', $description );
		$og_url = $this->ogUrl( $context, $canonical );
		if ( '' !== $og_url ) {
			$out[] = $this->metaProp( 'og:url', $og_url );
		}
		$out[] = $this->metaProp( 'og:site_name', get_bloginfo( 'name' ) );

		$image = $this->ogImage( $context );
		if ( $image ) {
			$out[] = $this->metaProp( 'og:image', $image['url'] );
			if ( $image['width'] > 0 ) {
				$out[] = $this->metaProp( 'og:image:width', (string) $image['width'] );
			}
			if ( $image['height'] > 0 ) {
				$out[] = $this->metaProp( 'og:image:height', (string) $image['height'] );
			}
			$mime = $this->mimeType( $image['url'] );
			if ( '' !== $mime ) {
				$out[] = $this->metaProp( 'og:image:type', $mime );
			}
			if ( '' !== $image['alt'] ) {
				$out[] = $this->metaProp( 'og:image:alt', $image['alt'] );
			}
		}

		$post = $context->post();
		if ( PageType::Singular === $context->type() && $post && 'post' === $post->post_type ) {
			$published = get_post_datetime( $post );
			$modified  = get_post_datetime( $post, 'modified' );
			if ( $published ) {
				$out[] = $this->metaProp( 'article:published_time', $published->format( 'c' ) );
			}
			if ( $modified ) {
				$out[] = $this->metaProp( 'article:modified_time', $modified->format( 'c' ) );
			}
			$author_url = get_author_posts_url( (int) $post->post_author );
			if ( is_string( $author_url ) ) {
				$out[] = $this->metaProp( 'article:author', $author_url );
			}
			$section = $this->primaryCategoryName( $post );
			if ( '' !== $section ) {
				$out[] = $this->metaProp( 'article:section', $section );
			}
			$tags = get_the_terms( $post, 'post_tag' );
			if ( is_array( $tags ) ) {
				foreach ( $tags as $tag ) {
					$out[] = $this->metaProp( 'article:tag', $tag->name );
				}
			}
		}

		$fb_app = $this->options->str( 'social.facebook_app' );
		if ( '' !== $fb_app ) {
			$out[] = $this->metaProp( 'fb:app_id', $fb_app );
		}

		return array_filter( $out );
	}

	/** @return string[] */
	private function twitter( Context $context, string $description ): array {
		$out   = [];
		$out[] = $this->metaName( 'twitter:card', 'summary_large_image' );

		$site = $this->atHandle( $this->options->str( 'social.twitter_site' ) );
		if ( '' !== $site ) {
			$out[] = $this->metaName( 'twitter:site', $site );
		}

		$creator = $this->twitterCreator( $context, $site );
		if ( '' !== $creator ) {
			$out[] = $this->metaName( 'twitter:creator', $creator );
		}

		$out[] = $this->metaName( 'twitter:title', $this->title( $context ) );
		$out[] = $this->metaName( 'twitter:description', $description );

		$image = $this->twitterImage( $context );
		if ( $image ) {
			$out[] = $this->metaName( 'twitter:image', $image['url'] );
			if ( '' !== $image['alt'] ) {
				$out[] = $this->metaName( 'twitter:image:alt', $image['alt'] );
			}
		}

		return array_filter( $out );
	}

	/** @return string[] */
	private function verification(): array {
		$map = [
			'google'    => 'google-site-verification',
			'bing'      => 'msvalidate.01',
			'pinterest' => 'p:domain_verify',
			'baidu'     => 'baidu-site-verification',
		];

		$out = [];
		foreach ( $map as $key => $name ) {
			$value = $this->options->str( "verification.{$key}" );
			if ( '' !== $value ) {
				$out[] = $this->metaName( $name, $value );
			}
		}
		return $out;
	}

	/** @return array{url:string,width:int,height:int,alt:string}|null */
	private function ogImage( Context $context ): ?array {
		return $this->imageAt( $context, Media::ogSize( $this->options ) );
	}

	/** @return array{url:string,width:int,height:int,alt:string}|null */
	private function twitterImage( Context $context ): ?array {
		return $this->imageAt( $context, Media::twitterSize( $this->options ) );
	}

	/** @return array{url:string,width:int,height:int,alt:string}|null */
	private function imageAt( Context $context, string $size ): ?array {
		if ( ! array_key_exists( $size, $this->imageMemo ) ) {
			$this->imageMemo[ $size ] = Images::forContext( $context, $this->options, $size );
		}
		return $this->imageMemo[ $size ];
	}

	private function ogType( Context $context ): string {
		$post = $context->post();
		if ( PageType::Singular === $context->type() && $post && 'post' === $post->post_type ) {
			return 'article';
		}
		if ( PageType::Author === $context->type() ) {
			return 'profile';
		}
		return 'website';
	}

	private function archiveTitle( Context $context ): string {
		if ( PageType::PostTypeArchive === $context->type() ) {
			$object = $context->object();
			return $object instanceof WP_Post_Type ? (string) $object->labels->name : '';
		}
		if ( $term = $context->term() ) {
			return $term->name;
		}
		return wp_strip_all_tags( get_the_archive_title() );
	}

	private function primaryCategoryName( WP_Post $post ): string {
		$terms = get_the_terms( $post, 'category' );
		return ( is_array( $terms ) && isset( $terms[0] ) ) ? $terms[0]->name : '';
	}

	private function isSiteRoot( Context $context ): bool {
		return $context->isStaticFront()
			|| in_array( $context->type(), [ PageType::Front, PageType::Home ], true );
	}

	private function truncate( string $text, int $limit ): string {
		if ( '' === $text || mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $limit );
		$pos = strrpos( $cut, ' ' ); // Byte-safe (space is ASCII) — avoids a multibyte function WP does not polyfill.
		if ( false !== $pos ) {
			$cut = mb_substr( $cut, 0, $pos );
		}
		return rtrim( $cut, " ,.;:–-" ) . '…';
	}

	private function atHandle( string $handle ): string {
		$handle = ltrim( trim( $handle ), '@' );
		return '' === $handle ? '' : '@' . $handle;
	}

	/**
	 * og:url stays on this site even when the canonical points off-domain
	 * (syndicated content crediting an original source). Same-site canonical
	 * overrides are still honored.
	 */
	private function ogUrl( Context $context, string $canonical ): string {
		if ( '' !== $canonical && $this->sameHost( $canonical ) ) {
			return $canonical;
		}
		return Url::permalink( $context );
	}

	private function sameHost( string $url ): bool {
		return wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * twitter:creator — the post author's X handle (from their profile) on
	 * singular content, falling back to the site handle.
	 */
	private function twitterCreator( Context $context, string $site ): string {
		$post = $context->post();
		if ( ! $post ) {
			return '';
		}
		$handle = $this->atHandle( (string) get_the_author_meta( 'twitter', (int) $post->post_author ) );
		return '' !== $handle ? $handle : $site;
	}

	private function mimeType( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = $url;
		}
		$type = wp_check_filetype( $path );
		return ! empty( $type['type'] ) ? (string) $type['type'] : '';
	}

	private function metaName( string $name, string $content ): string {
		$content = trim( $content );
		return '' === $content ? '' : sprintf( '<meta name="%s" content="%s" />', esc_attr( $name ), esc_attr( $content ) );
	}

	private function metaProp( string $property, string $content ): string {
		$content = trim( $content );
		return '' === $content ? '' : sprintf( '<meta property="%s" content="%s" />', esc_attr( $property ), esc_attr( $content ) );
	}
}
