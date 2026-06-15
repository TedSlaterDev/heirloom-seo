<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Schema;

use OrchardGrove\HeirloomSeo\Context;
use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Modules\Breadcrumbs\Breadcrumbs;
use OrchardGrove\HeirloomSeo\PageType;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Support\Images;
use OrchardGrove\HeirloomSeo\Support\Url;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Emits a single connected schema.org @graph (WebSite + Organization/Person +
 * WebPage + Article/NewsArticle + BreadcrumbList), all linked by @id.
 */
final class Schema implements ModuleInterface {

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_action( 'wp_head', [ $this, 'render' ], 99 );
	}

	public function render(): void {
		$context = Context::instance();
		if ( in_array( $context->type(), [ PageType::Feed, PageType::NotFound ], true ) ) {
			return;
		}

		$graph = $this->graph( $context );

		/**
		 * Filters the full schema.org @graph before output, so themes/plugins
		 * can add or modify pieces.
		 *
		 * @param array<int,array<string,mixed>> $graph
		 * @param Context                        $context
		 */
		$graph = (array) apply_filters( 'heirloom_seo/schema/graph', $graph, $context );
		if ( ! $graph ) {
			return;
		}

		$payload = [
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		];

		// Escaped slashes (no JSON_UNESCAPED_SLASHES) prevent a "</script>"
		// breakout from any string value.
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		echo "\n" . '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** @return array<int,array<string,mixed>> */
	private function graph( Context $context ): array {
		// Node @ids use the page's own URL (not a cross-domain canonical) so the
		// graph stays internally consistent and on-site, matching og:url.
		$canonical = Url::permalink( $context );
		if ( '' === $canonical ) {
			$canonical = home_url( '/' );
		}

		$graph              = [];
		$graph['website']   = $this->website();
		$graph['publisher'] = $this->publisher();

		$webpage = $this->webpage( $context, $canonical );

		if ( $context->isSingular() ) {
			$image = $this->primaryImage( $context, $canonical );
			if ( $image ) {
				$graph['primaryimage']         = $image;
				$webpage['primaryImageOfPage'] = [ '@id' => $image['@id'] ];
				$webpage['image']              = [ '@id' => $image['@id'] ];
			}
		}

		$breadcrumb = $this->breadcrumbList( $context, $canonical );
		if ( $breadcrumb ) {
			$graph['breadcrumb']   = $breadcrumb;
			$webpage['breadcrumb'] = [ '@id' => $breadcrumb['@id'] ];
		}

		$graph['webpage'] = $webpage;

		if ( PageType::Singular === $context->type() && ( $post = $context->post() ) ) {
			$article = $this->article( $context, $post, $canonical );
			if ( $article ) {
				$graph['article'] = $article;
				$person           = $this->authorPerson( $post );
				if ( $person ) {
					$graph['person'] = $person;
				}
			}
			$product = $this->productNode( $post, $canonical );
			if ( $product ) {
				$graph['product'] = $product;
			}
		}

		$nodes = array_values( $graph );
		foreach ( $this->customNodes( $context ) as $node ) {
			$nodes[] = $node;
		}
		return $nodes;
	}

	/** @return array<string,mixed>|null WooCommerce Product node (opt-in, Woo only). */
	private function productNode( WP_Post $post, string $canonical ): ?array {
		if ( ! $this->options->bool( 'schema.woo_product' ) || 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return null;
		}

		$data = [
			'@type' => 'Product',
			'@id'   => $canonical . '#product',
			'name'  => $product->get_name(),
			'url'   => $canonical,
		];
		$description = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $product->get_short_description() ?: $product->get_description() ) ) ) );
		if ( '' !== $description ) {
			$data['description'] = $description;
		}
		$sku = (string) $product->get_sku();
		if ( '' !== $sku ) {
			$data['sku'] = $sku;
		}
		$image_id = (int) $product->get_image_id();
		if ( $image_id ) {
			$src = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $src ) {
				$data['image'] = $src;
			}
		}
		$price = (string) $product->get_price();
		if ( '' !== $price ) {
			$data['offers'] = [
				'@type'         => 'Offer',
				'url'           => $canonical,
				'price'         => $price,
				'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
			];
		}
		if ( (int) $product->get_rating_count() > 0 ) {
			$data['aggregateRating'] = [
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) $product->get_average_rating(),
				'reviewCount' => (int) $product->get_review_count(),
			];
		}

		return $data;
	}

	/** @return array<int,array<string,mixed>> per-post raw JSON-LD nodes (escape hatch). */
	private function customNodes( Context $context ): array {
		$post = $context->post();
		if ( ! $post ) {
			return [];
		}
		$raw = get_post_meta( $post->ID, '_heirloom_seo_jsonld', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
			return array_values( array_filter( $decoded['@graph'], 'is_array' ) );
		}
		return [ $decoded ];
	}

	/** @return array<string,mixed> */
	private function website(): array {
		return [
			'@type'           => 'WebSite',
			'@id'             => home_url( '/' ) . '#website',
			'url'             => home_url( '/' ),
			'name'            => get_bloginfo( 'name' ),
			'description'     => get_bloginfo( 'description' ),
			'publisher'       => [ '@id' => home_url( '/' ) . '#organization' ],
			'inLanguage'      => get_bloginfo( 'language' ),
			'potentialAction' => [
				[
					'@type'       => 'SearchAction',
					'target'      => [
						'@type'       => 'EntryPoint',
						'urlTemplate' => home_url( '/' ) . '?s={search_term_string}',
					],
					'query-input' => 'required name=search_term_string',
				],
			],
		];
	}

	/** @return array<string,mixed> */
	private function publisher(): array {
		$org_id = home_url( '/' ) . '#organization';
		$logo   = $this->logo();

		if ( 'person' === $this->options->str( 'schema.site_represents' ) ) {
			$uid  = $this->options->int( 'schema.person_id' );
			$name = $uid ? (string) get_the_author_meta( 'display_name', $uid ) : get_bloginfo( 'name' );
			$data = [
				'@type' => [ 'Person', 'Organization' ],
				'@id'   => $org_id,
				'name'  => $name,
				'url'   => home_url( '/' ),
			];
			if ( $logo ) {
				$data['image'] = $logo;
			}
			return array_merge( $data, $this->identityExtras() );
		}

		$type = $this->orgType();
		$data = [
			'@type' => $type,
			'@id'   => $org_id,
			'name'  => $this->options->str( 'schema.org_name' ) ?: get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		];
		if ( $logo ) {
			$data['logo']  = $logo;
			$data['image'] = [ '@id' => $logo['@id'] ];
		}
		if ( 'LocalBusiness' === $type ) {
			$phone = $this->options->str( 'schema.phone' );
			if ( '' !== $phone ) {
				$data['telephone'] = $phone;
			}
			$address = $this->schemaAddress();
			if ( $address ) {
				$data['address'] = $address;
			}
			$price = $this->options->str( 'schema.price_range' );
			if ( '' !== $price ) {
				$data['priceRange'] = $price;
			}
		}
		return array_merge( $data, $this->identityExtras() );
	}

	private function orgType(): string {
		return 'localbusiness' === $this->options->str( 'schema.site_represents' ) ? 'LocalBusiness' : 'Organization';
	}

	/** sameAs URLs, shared by the Organization and Person nodes. @return array<string,mixed> */
	private function identityExtras(): array {
		$same = $this->sameAs();
		return $same ? [ 'sameAs' => $same ] : [];
	}

	/** @return string[] */
	private function sameAs(): array {
		$raw = $this->options->str( 'schema.sameas' );
		if ( '' === $raw ) {
			return [];
		}
		$urls = array_filter( array_map( 'trim', (array) preg_split( '/\r\n|\r|\n/', $raw ) ) );
		return array_values( array_map( 'esc_url_raw', $urls ) );
	}

	/** @return array<string,string> */
	private function schemaAddress(): array {
		$map  = [
			'streetAddress'   => 'schema.address_street',
			'addressLocality' => 'schema.address_locality',
			'addressRegion'   => 'schema.address_region',
			'postalCode'      => 'schema.address_postal',
			'addressCountry'  => 'schema.address_country',
		];
		$addr = [];
		foreach ( $map as $prop => $option ) {
			$value = $this->options->str( $option );
			if ( '' !== $value ) {
				$addr[ $prop ] = $value;
			}
		}
		return $addr ? array_merge( [ '@type' => 'PostalAddress' ], $addr ) : [];
	}

	/** @return array<string,mixed>|null */
	private function logo(): ?array {
		$url    = '';
		$width  = 0;
		$height = 0;

		$custom = $this->options->get( 'schema.org_logo' );
		if ( is_numeric( $custom ) && (int) $custom > 0 ) {
			$src = wp_get_attachment_image_src( (int) $custom, 'full' );
			if ( $src ) {
				[ $url, $width, $height ] = [ (string) $src[0], (int) $src[1], (int) $src[2] ];
			}
		} elseif ( is_string( $custom ) && '' !== $custom ) {
			$url = $custom;
		}

		if ( '' === $url ) {
			$icon_id = (int) get_option( 'site_icon' );
			if ( $icon_id ) {
				$src = wp_get_attachment_image_src( $icon_id, 'full' );
				if ( $src ) {
					[ $url, $width, $height ] = [ (string) $src[0], (int) $src[1], (int) $src[2] ];
				}
			}
		}

		if ( '' === $url ) {
			return null;
		}

		$node = [
			'@type'      => 'ImageObject',
			'@id'        => home_url( '/' ) . '#logo',
			'url'        => $url,
			'contentUrl' => $url,
		];
		if ( $width ) {
			$node['width'] = $width;
		}
		if ( $height ) {
			$node['height'] = $height;
		}
		return $node;
	}

	/** @return array<string,mixed> */
	private function webpage( Context $context, string $canonical ): array {
		$data = [
			'@type'      => 'WebPage',
			'@id'        => $canonical . '#webpage',
			'url'        => $canonical,
			'name'       => $this->pageName( $context ),
			'isPartOf'   => [ '@id' => home_url( '/' ) . '#website' ],
			'inLanguage' => get_bloginfo( 'language' ),
		];

		if ( $this->isSiteRoot( $context ) ) {
			$data['about'] = [ '@id' => home_url( '/' ) . '#organization' ];
		}

		$post = $context->post();
		if ( $post ) {
			$published = get_post_datetime( $post );
			$modified  = get_post_datetime( $post, 'modified' );
			if ( $published ) {
				$data['datePublished'] = $published->format( 'c' );
			}
			if ( $modified ) {
				$data['dateModified'] = $modified->format( 'c' );
			}
		}

		return $data;
	}

	/** @return array<string,mixed>|null */
	private function primaryImage( Context $context, string $canonical ): ?array {
		$image = Images::forContext( $context, $this->options );
		if ( ! $image || '' === $image['url'] ) {
			return null;
		}

		$node = [
			'@type'      => 'ImageObject',
			'@id'        => $canonical . '#primaryimage',
			'url'        => $image['url'],
			'contentUrl' => $image['url'],
		];
		if ( $image['width'] ) {
			$node['width'] = $image['width'];
		}
		if ( $image['height'] ) {
			$node['height'] = $image['height'];
		}
		if ( '' !== $image['alt'] ) {
			$node['caption'] = $image['alt'];
		}
		return $node;
	}

	/** @return array<string,mixed>|null */
	private function breadcrumbList( Context $context, string $canonical ): ?array {
		$trail = Breadcrumbs::trail( $context, $this->options );
		if ( count( $trail ) < 2 ) {
			return null;
		}

		$items = [];
		foreach ( array_values( $trail ) as $i => $crumb ) {
			$item = [
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $crumb['name'],
			];
			if ( ! empty( $crumb['url'] ) ) {
				$item['item'] = $crumb['url'];
			}
			$items[] = $item;
		}

		return [
			'@type'           => 'BreadcrumbList',
			'@id'             => $canonical . '#breadcrumb',
			'itemListElement' => $items,
		];
	}

	/** @return array<string,mixed>|null */
	private function article( Context $context, WP_Post $post, string $canonical ): ?array {
		if ( 'post' !== $post->post_type ) {
			return null;
		}

		$type     = $this->isNews( $post ) ? SchemaType::NewsArticle->value : SchemaType::Article->value;
		$override = get_post_meta( $post->ID, '_heirloom_seo_schema_type', true );
		if ( is_string( $override ) && '' !== $override ) {
			$type = $override;
		}

		$published = get_post_datetime( $post );
		$modified  = get_post_datetime( $post, 'modified' );

		$data = [
			'@type'            => $type,
			'@id'              => $canonical . '#article',
			'isPartOf'         => [ '@id' => $canonical . '#webpage' ],
			'mainEntityOfPage' => [ '@id' => $canonical . '#webpage' ],
			'headline'         => get_the_title( $post ),
			'datePublished'    => $published ? $published->format( 'c' ) : '',
			'dateModified'     => $modified ? $modified->format( 'c' ) : '',
			'author'           => [ '@id' => $this->personId( $post ) ],
			'publisher'        => [ '@id' => home_url( '/' ) . '#organization' ],
			'inLanguage'       => get_bloginfo( 'language' ),
			'wordCount'        => $this->wordCount( $post ),
		];

		$image = $this->primaryImage( $context, $canonical );
		if ( $image ) {
			$data['image'] = [ '@id' => $image['@id'] ];
		}

		$section = $this->primaryCategoryName( $post );
		if ( '' !== $section ) {
			$data['articleSection'] = $section;
		}

		$tags = get_the_terms( $post, 'post_tag' );
		if ( is_array( $tags ) && $tags ) {
			$data['keywords'] = array_values( wp_list_pluck( $tags, 'name' ) );
		}

		$excerpt = wp_strip_all_tags( get_the_excerpt( $post ) );
		if ( '' !== $excerpt ) {
			$data['description'] = $excerpt;
		}

		return array_filter( $data, static fn( $value ) => '' !== $value && [] !== $value );
	}

	/** @return array<string,mixed>|null */
	private function authorPerson( WP_Post $post ): ?array {
		$uid = (int) $post->post_author;
		if ( ! $uid ) {
			return null;
		}

		$data = [
			'@type' => 'Person',
			'@id'   => $this->personId( $post ),
			'name'  => (string) get_the_author_meta( 'display_name', $uid ),
			'url'   => get_author_posts_url( $uid ),
		];

		$description = get_the_author_meta( 'description', $uid );
		if ( is_string( $description ) && '' !== $description ) {
			$data['description'] = $description;
		}

		$avatar = get_avatar_url( $uid );
		if ( $avatar ) {
			$data['image'] = [
				'@type'      => 'ImageObject',
				'url'        => $avatar,
				'contentUrl' => $avatar,
			];
		}

		return $data;
	}

	private function isNews( WP_Post $post ): bool {
		$category = $this->options->str( 'schema.news_category' );
		$tag      = $this->options->str( 'schema.news_tag' );

		if ( '' !== $category || '' !== $tag ) {
			return ( '' !== $category && has_term( $category, 'category', $post ) )
				|| ( '' !== $tag && has_term( $tag, 'post_tag', $post ) );
		}

		// Fallback: any category/tag named after the news term.
		$name = $this->options->str( 'schema.news_term', 'News' );
		if ( '' === $name ) {
			return false;
		}
		return $this->hasTermNamed( $post, 'category', $name )
			|| $this->hasTermNamed( $post, 'post_tag', $name );
	}

	private function hasTermNamed( WP_Post $post, string $taxonomy, string $name ): bool {
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term ) {
			if ( 0 === strcasecmp( $term->name, $name ) ) {
				return true;
			}
		}
		return false;
	}

	private function personId( WP_Post $post ): string {
		return home_url( '/' ) . '#/person/' . md5( 'author-' . (int) $post->post_author );
	}

	private function pageName( Context $context ): string {
		if ( $post = $context->post() ) {
			return get_the_title( $post );
		}
		if ( $term = $context->term() ) {
			return $term->name;
		}
		if ( $user = $context->user() ) {
			return $user->display_name;
		}
		if ( $this->isSiteRoot( $context ) ) {
			return get_bloginfo( 'name' );
		}
		return wp_strip_all_tags( get_the_archive_title() );
	}

	private function primaryCategoryName( WP_Post $post ): string {
		$terms = get_the_terms( $post, 'category' );
		return ( is_array( $terms ) && isset( $terms[0] ) ) ? $terms[0]->name : '';
	}

	private function wordCount( WP_Post $post ): int {
		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		return str_word_count( $text );
	}

	private function isSiteRoot( Context $context ): bool {
		return $context->isStaticFront()
			|| in_array( $context->type(), [ PageType::Front, PageType::Home ], true );
	}
}
