<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Sitemaps;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Modules\Authors\Authors;
use OrchardGrove\HeirloomSeo\Settings\Options;
use OrchardGrove\HeirloomSeo\Support\FileCache;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces core sitemaps with our own at /sitemap.xml, plus a Google News
 * sitemap at /news-sitemap.xml. Rendered XML is cached to files under uploads
 * and purged on content changes.
 *
 *   /sitemap.xml                 sitemap index
 *   /sitemap-{provider}-{n}.xml  a provider page (pt_post, tax_category, ...)
 *   /news-sitemap.xml            Google News (posts < 48h in the "News" term)
 */
final class Sitemaps implements ModuleInterface {

	private const PURGE_HOOKS = [ 'save_post', 'deleted_post', 'edited_term', 'created_term', 'delete_term' ];

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_action( 'init', [ $this, 'addRewriteRules' ] );
		add_filter( 'query_vars', [ $this, 'queryVars' ] );
		add_action( 'template_redirect', [ $this, 'maybeServe' ], 0 );

		foreach ( self::PURGE_HOOKS as $hook ) {
			add_action( $hook, [ FileCache::class, 'purge' ] );
		}
	}

	public function addRewriteRules(): void {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?heirloom_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap-([^/]+?)-(\d+)\.xml$', 'index.php?heirloom_sitemap=sub&heirloom_sm_type=$matches[1]&heirloom_sm_page=$matches[2]', 'top' );
		add_rewrite_rule( '^news-sitemap\.xml$', 'index.php?heirloom_sitemap=news', 'top' );
		add_rewrite_rule( '^sitemap\.xsl$', 'index.php?heirloom_sitemap=xsl', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function queryVars( array $vars ): array {
		$vars[] = 'heirloom_sitemap';
		$vars[] = 'heirloom_sm_type';
		$vars[] = 'heirloom_sm_page';
		return $vars;
	}

	public function maybeServe(): void {
		$which = (string) get_query_var( 'heirloom_sitemap' );
		if ( '' === $which ) {
			return;
		}

		if ( 'xsl' === $which ) {
			$this->outputStylesheet();
			return;
		}

		$type = (string) preg_replace( '/[^a-z0-9_-]/i', '', (string) get_query_var( 'heirloom_sm_type' ) );
		$page = max( 1, (int) get_query_var( 'heirloom_sm_page' ) );

		$key = match ( $which ) {
			'index' => 'index',
			'news'  => 'news',
			'sub'   => "sub_{$type}_{$page}",
			default => '',
		};
		if ( '' === $key ) {
			return;
		}

		$xml = FileCache::get( $key );
		if ( null === $xml ) {
			$xml = match ( $which ) {
				'index' => $this->renderIndex(),
				'news'  => $this->buildNews(),
				'sub'   => $this->buildSub( $type, $page ),
				default => '',
			};
			if ( '' === $xml ) {
				status_header( 404 );
				exit;
			}
			FileCache::put( $key, $xml );
		}

		$this->output( $xml );
	}

	private function output( string $xml ): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- XML assembled with esc_url/esc_html.
		exit;
	}

	private function outputStylesheet(): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/xsl; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		echo $this->stylesheet(); // phpcs:ignore WordPress.Security.EscapeOutput -- static XSL markup.
		exit;
	}

	private function stylesheetPi(): string {
		return '<?xml-stylesheet type="text/xsl" href="' . esc_url( home_url( '/sitemap.xsl' ) ) . '"?>' . "\n";
	}

	// --- Index ------------------------------------------------------------

	private function renderIndex(): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= $this->stylesheetPi();
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $this->providers() as $type => $info ) {
			for ( $p = 1; $p <= $info['pages']; $p++ ) {
				$xml .= "\t<sitemap>\n\t\t<loc>" . esc_url( home_url( "/sitemap-{$type}-{$p}.xml" ) ) . "</loc>\n";
				if ( '' !== $info['lastmod'] ) {
					$xml .= "\t\t<lastmod>" . esc_xml( $info['lastmod'] ) . "</lastmod>\n";
				}
				$xml .= "\t</sitemap>\n";
			}
		}

		if ( $this->options->bool( 'sitemaps.news_enabled' ) && $this->hasRecentNews() ) {
			$xml .= "\t<sitemap>\n\t\t<loc>" . esc_url( home_url( '/news-sitemap.xml' ) ) . "</loc>\n\t\t<lastmod>" . esc_xml( gmdate( 'c' ) ) . "</lastmod>\n\t</sitemap>\n";
		}

		$xml .= '</sitemapindex>' . "\n";
		return $xml;
	}

	/** @return array<string,array{pages:int,lastmod:string}> provider key => pages + lastmod. */
	private function providers(): array {
		$list = [];

		if ( $this->extraEntries() ) {
			$list['extra'] = [ 'pages' => 1, 'lastmod' => $this->postTypeLastmod( 'post' ) ];
		}

		foreach ( $this->postTypes() as $post_type ) {
			$count = $this->postCount( $post_type );
			if ( $count > 0 ) {
				$list[ 'pt_' . $post_type ] = [
					'pages'   => (int) ceil( $count / $this->perPage() ),
					'lastmod' => $this->postTypeLastmod( $post_type ),
				];
			}
		}

		foreach ( $this->taxonomies() as $taxonomy ) {
			$count = $this->termCount( $taxonomy );
			if ( $count > 0 ) {
				$list[ 'tax_' . $taxonomy ] = [ 'pages' => (int) ceil( $count / $this->perPage() ), 'lastmod' => '' ];
			}
		}

		if ( $this->options->bool( 'sitemaps.authors' ) ) {
			$count = $this->authorCount();
			if ( $count > 0 ) {
				$list['author'] = [ 'pages' => (int) ceil( $count / $this->perPage() ), 'lastmod' => '' ];
			}
		}

		return $list;
	}

	private function postTypeLastmod( string $post_type ): string {
		$posts = get_posts(
			[
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		return $posts ? (string) get_post_modified_time( 'c', true, $posts[0] ) : '';
	}

	// --- Sub-sitemaps -----------------------------------------------------

	private function buildSub( string $type, int $page ): string {
		$providers = $this->providers();
		if ( ! isset( $providers[ $type ] ) || $page > $providers[ $type ]['pages'] ) {
			return '';
		}
		return $this->renderUrlset( $this->subEntries( $type, $page ) );
	}

	/** @return array<int,array{loc:string,lastmod:string,images?:string[]}> */
	private function subEntries( string $type, int $page ): array {
		if ( 'extra' === $type ) {
			return $this->extraEntries();
		}
		if ( str_starts_with( $type, 'pt_' ) ) {
			return $this->postEntries( substr( $type, 3 ), $page );
		}
		if ( str_starts_with( $type, 'tax_' ) ) {
			return $this->termEntries( substr( $type, 4 ), $page );
		}
		if ( 'author' === $type ) {
			return $this->authorEntries( $page );
		}
		return [];
	}

	/** @return array<int,array{loc:string,lastmod:string}> */
	private function extraEntries(): array {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			return []; // The front page is a Page already covered by the pages sitemap.
		}
		return [ [ 'loc' => home_url( '/' ), 'lastmod' => gmdate( 'c' ) ] ];
	}

	/** @return array<int,array{loc:string,lastmod:string,images?:string[]}> */
	private function postEntries( string $post_type, int $page ): array {
		$per   = $this->perPage();
		$query = new WP_Query(
			[
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => $per,
				'paged'                  => $page,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => true,
			]
		);

		$with_images = $this->options->bool( 'sitemaps.images' );
		$entries     = [];

		foreach ( $query->posts as $post ) {
			if ( get_post_meta( $post->ID, '_heirloom_seo_noindex', true ) ) {
				continue;
			}
			$loc = get_permalink( $post );
			if ( ! $loc ) {
				continue;
			}
			$entry = [
				'loc'     => (string) $loc,
				'lastmod' => (string) get_post_modified_time( 'c', true, $post ),
			];
			if ( $with_images ) {
				$images = $this->postImages( $post );
				if ( $images ) {
					$entry['images'] = $images;
				}
			}
			$entries[] = $entry;
		}

		return $entries;
	}

	/** @return array<int,array{loc:string,lastmod:string}> */
	private function termEntries( string $taxonomy, int $page ): array {
		$per   = $this->perPage();
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => $per,
				'offset'     => ( $page - 1 ) * $per,
				'orderby'    => 'id',
			]
		);

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$entries = [];
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$entries[] = [ 'loc' => (string) $link, 'lastmod' => '' ];
		}
		return $entries;
	}

	/** @return array<int,array{loc:string,lastmod:string}> */
	private function authorEntries( int $page ): array {
		$per   = $this->perPage();
		$users = get_users(
			[
				'has_published_posts' => [ 'post' ],
				'fields'              => [ 'ID' ],
				'number'              => $per,
				'offset'              => ( $page - 1 ) * $per,
				'orderby'             => 'ID',
				'meta_query'          => self::hiddenAuthorExclusion(),
			]
		);

		$entries = [];
		foreach ( $users as $user ) {
			$entries[] = [ 'loc' => (string) get_author_posts_url( (int) $user->ID ), 'lastmod' => '' ];
		}
		return $entries;
	}

	// --- Google News ------------------------------------------------------

	private function buildNews(): string {
		$tax_query = $this->newsTaxQuery();
		if ( ! $tax_query ) {
			return $this->renderNews( [] );
		}

		$query = new WP_Query(
			[
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => 1000,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => true,
				'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'date_query'             => [ [ 'after' => '48 hours ago', 'column' => 'post_date_gmt', 'inclusive' => true ] ],
			]
		);

		$items = [];
		foreach ( $query->posts as $post ) {
			if ( get_post_meta( $post->ID, '_heirloom_seo_noindex', true ) ) {
				continue;
			}
			$loc = get_permalink( $post );
			if ( ! $loc ) {
				continue;
			}
			$items[] = [
				'loc'   => (string) $loc,
				'title' => get_the_title( $post ),
				'date'  => (string) get_post_time( 'c', true, $post ),
			];
		}

		return $this->renderNews( $items );
	}

	private function hasRecentNews(): bool {
		$tax_query = $this->newsTaxQuery();
		if ( ! $tax_query ) {
			return false;
		}
		$query = new WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'date_query'     => [ [ 'after' => '48 hours ago', 'column' => 'post_date_gmt', 'inclusive' => true ] ],
			]
		);
		return ! empty( $query->posts );
	}

	/** @return array<int|string,mixed> */
	private function newsTaxQuery(): array {
		$category = $this->options->str( 'schema.news_category' );
		$tag      = $this->options->str( 'schema.news_tag' );
		$clauses  = [ 'relation' => 'OR' ];

		if ( '' !== $category || '' !== $tag ) {
			if ( '' !== $category ) {
				$clauses[] = [ 'taxonomy' => 'category', 'field' => 'slug', 'terms' => $category ];
			}
			if ( '' !== $tag ) {
				$clauses[] = [ 'taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => $tag ];
			}
			return count( $clauses ) > 1 ? $clauses : [];
		}

		// Fallback: any category/tag named after the news term.
		$name = $this->options->str( 'schema.news_term', 'News' );
		if ( '' === $name ) {
			return [];
		}
		foreach ( [ 'category', 'post_tag' ] as $taxonomy ) {
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$clauses[] = [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => (int) $term->term_id ];
			}
		}

		return count( $clauses ) > 1 ? $clauses : [];
	}

	// --- Renderers --------------------------------------------------------

	/** @param array<int,array{loc:string,lastmod:string,images?:string[]}> $entries */
	private function renderUrlset( array $entries ): string {
		$with_images = $this->options->bool( 'sitemaps.images' );

		$ns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		if ( $with_images ) {
			$ns .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
		}

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= $this->stylesheetPi();
		$xml .= "<urlset {$ns}>\n";

		foreach ( $entries as $entry ) {
			$xml .= "\t<url>\n\t\t<loc>" . esc_url( $entry['loc'] ) . "</loc>\n";
			if ( ! empty( $entry['lastmod'] ) ) {
				$xml .= "\t\t<lastmod>" . esc_xml( $entry['lastmod'] ) . "</lastmod>\n";
			}
			if ( $with_images && ! empty( $entry['images'] ) ) {
				foreach ( $entry['images'] as $image ) {
					$xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . esc_url( $image ) . "</image:loc>\n\t\t</image:image>\n";
				}
			}
			$xml .= "\t</url>\n";
		}

		$xml .= "</urlset>\n";
		return $xml;
	}

	/** @param array<int,array{loc:string,title:string,date:string}> $items */
	private function renderNews( array $items ): string {
		$publication = esc_xml( get_bloginfo( 'name' ) );
		$language    = esc_xml( $this->newsLanguage() );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= $this->stylesheetPi();
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		foreach ( $items as $item ) {
			$xml .= "\t<url>\n\t\t<loc>" . esc_url( $item['loc'] ) . "</loc>\n";
			$xml .= "\t\t<news:news>\n";
			$xml .= "\t\t\t<news:publication>\n\t\t\t\t<news:name>{$publication}</news:name>\n\t\t\t\t<news:language>{$language}</news:language>\n\t\t\t</news:publication>\n";
			$xml .= "\t\t\t<news:publication_date>" . esc_xml( $item['date'] ) . "</news:publication_date>\n";
			$xml .= "\t\t\t<news:title>" . esc_xml( $item['title'] ) . "</news:title>\n";
			$xml .= "\t\t</news:news>\n\t</url>\n";
		}

		$xml .= "</urlset>\n";
		return $xml;
	}

	/**
	 * The XSL stylesheet that renders the sitemap index and sub-sitemaps as a
	 * human-readable table in a browser. Search engines ignore it entirely.
	 */
	private function stylesheet(): string {
		return <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
	xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
	<xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>
	<xsl:template match="/">
		<html lang="en">
			<head>
				<meta charset="UTF-8"/>
				<meta name="viewport" content="width=device-width, initial-scale=1"/>
				<title>XML Sitemap</title>
				<style>
					body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1d2327;margin:0;padding:2rem 1.25rem;background:#f6f7f7;}
					.wrap{max-width:1000px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:1.5rem 1.75rem;}
					h1{font-size:1.4rem;margin:0 0 .25rem;}
					.intro{color:#646970;margin:0 0 1.25rem;font-size:.9rem;}
					.intro a,td a{color:#2271b1;}
					.count{color:#646970;font-size:.85rem;margin:0 0 1rem;}
					table{width:100%;border-collapse:collapse;font-size:.9rem;}
					th,td{text-align:left;padding:.5rem .6rem;border-bottom:1px solid #f0f0f1;vertical-align:top;}
					th{color:#646970;font-weight:600;border-bottom:2px solid #dcdcde;}
					tr:hover td{background:#f6f7f7;}
					td a{text-decoration:none;word-break:break-all;}
					td a:hover{text-decoration:underline;}
					.num{text-align:right;white-space:nowrap;color:#646970;}
					.foot{color:#8c8f94;font-size:.8rem;margin-top:1.25rem;}
				</style>
			</head>
			<body>
				<div class="wrap">
					<h1>XML Sitemap</h1>
					<p class="intro">This is an XML sitemap, meant for search engines. Generated by <a href="https://orchardgrove.com/">Heirloom SEO</a>.</p>
					<xsl:apply-templates select="s:sitemapindex"/>
					<xsl:apply-templates select="s:urlset"/>
					<p class="foot">Heirloom SEO &#8212; lean SEO for WordPress.</p>
				</div>
			</body>
		</html>
	</xsl:template>
	<xsl:template match="s:sitemapindex">
		<p class="count"><xsl:value-of select="count(s:sitemap)"/> sub-sitemaps in this index.</p>
		<table>
			<tr><th>Sitemap</th><th>Last modified</th></tr>
			<xsl:for-each select="s:sitemap">
				<tr>
					<td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td>
					<td class="num"><xsl:value-of select="s:lastmod"/></td>
				</tr>
			</xsl:for-each>
		</table>
	</xsl:template>
	<xsl:template match="s:urlset">
		<p class="count"><xsl:value-of select="count(s:url)"/> URLs in this sitemap.</p>
		<table>
			<tr><th>URL</th><th class="num">Images</th><th>Last modified</th></tr>
			<xsl:for-each select="s:url">
				<tr>
					<td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td>
					<td class="num"><xsl:value-of select="count(image:image)"/></td>
					<td class="num"><xsl:value-of select="s:lastmod"/></td>
				</tr>
			</xsl:for-each>
		</table>
	</xsl:template>
</xsl:stylesheet>
XSL;
	}

	// --- Helpers ----------------------------------------------------------

	/** @return string[] */
	private function postTypes(): array {
		$configured = $this->options->arr( 'sitemaps.post_types' );
		$all        = get_post_types( [ 'public' => true ], 'names' );
		unset( $all['attachment'] );

		$types = array_values( array_filter( $all, 'is_post_type_viewable' ) );
		if ( $configured ) {
			$types = array_values( array_intersect( $types, $configured ) );
		}
		return $types;
	}

	/** @return string[] */
	private function taxonomies(): array {
		$configured = $this->options->arr( 'sitemaps.taxonomies' );
		$all        = get_taxonomies( [ 'public' => true ], 'names' );
		unset( $all['post_format'] );

		$taxes = array_values( $all );
		if ( $configured ) {
			$taxes = array_values( array_intersect( $taxes, $configured ) );
		}
		return $taxes;
	}

	private function postCount( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		return (int) ( $counts->publish ?? 0 );
	}

	private function termCount( string $taxonomy ): int {
		$count = wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true ] );
		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	private function authorCount(): int {
		return count(
			get_users(
				[
					'has_published_posts' => [ 'post' ],
					'fields'              => 'ID',
					'meta_query'          => self::hiddenAuthorExclusion(),
				]
			)
		);
	}

	/**
	 * Authors flagged "Hide from search engines" (the heirloom_seo_noindex user
	 * meta, set on the Edit User screen) are dropped from the author sitemap.
	 *
	 * @return array<string,mixed>
	 */
	private static function hiddenAuthorExclusion(): array {
		return [
			'relation' => 'OR',
			[ 'key' => Authors::META, 'compare' => 'NOT EXISTS' ],
			[ 'key' => Authors::META, 'value' => '1', 'compare' => '!=' ],
		];
	}

	/** @return string[] */
	private function postImages( WP_Post $post ): array {
		$images = [];

		$thumb_id = get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$url = wp_get_attachment_image_url( (int) $thumb_id, 'full' );
			if ( $url ) {
				$images[] = $url;
			}
		}

		if ( preg_match_all( '/<img[^>]+src=(["\'])(.*?)\1/i', (string) $post->post_content, $matches ) ) {
			foreach ( $matches[2] as $src ) {
				$images[] = $src;
			}
		}

		return array_values( array_unique( array_filter( $images ) ) );
	}

	private function newsLanguage(): string {
		return strtolower( substr( get_locale(), 0, 2 ) );
	}

	private function perPage(): int {
		return max( 1, min( 50000, $this->options->int( 'sitemaps.per_page', 1000 ) ) );
	}
}
