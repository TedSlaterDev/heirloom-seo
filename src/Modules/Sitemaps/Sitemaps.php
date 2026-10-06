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
 * sitemap at /news-sitemap.xml. Rendered XML is cached to files under uploads.
 *
 *   /sitemap.xml                 sitemap index
 *   /sitemap-{provider}-{n}.xml  a provider page (pt_post, tax_category, ...)
 *   /news-sitemap.xml            Google News (posts < 48h in the "News" term)
 *
 * Built to survive crawler bursts on large sites (hundreds of pages requested
 * in the same second):
 * - Sitemap requests skip WordPress's main query, which would otherwise run
 *   the blog home query (SQL_CALC_FOUND_ROWS over every post) on every hit.
 * - Post pages list posts oldest-first (post_date, ID), so a page's contents
 *   stay put: publishing appends to the last page, and an edit changes only
 *   the page holding that post. The ID query is covered by core's
 *   type_status_date index, and the posts, their meta and their featured
 *   images are loaded in batches rather than one query per post.
 * - Content changes mark only the affected cached pages stale (worked out once
 *   per request, at shutdown); nothing wipes the whole cache on save_post.
 * - At most a couple of pages are generated at once (BuildGate). Requests past
 *   that get the previous copy, or a 503 with Retry-After when there is none.
 *   A build that hits a database error is not cached.
 * - Each page's lastmod in the index is tracked individually, so crawlers only
 *   need to refetch the pages that actually changed.
 *
 * Pages are offsets into that order, so removing or re-dating an old post
 * moves every later post by one and marks every later page changed. That is
 * the trade-off for pages that need no bookkeeping beyond their number.
 */
final class Sitemaps implements ModuleInterface {

	/** Per-page lastmod bookkeeping: cache key (sub_{type}_{page}) => ISO 8601 UTC, plus FLOOR_KEY. */
	public const LASTMOD_OPTION = 'heirloom_seo_sitemap_lastmod';

	/** In the lastmod map: a floor for every post page, set when a change rewrote all of them. */
	private const FLOOR_KEY = '_all';

	/**
	 * Ends every sitemap this version writes. A copy without it was written by
	 * older code (a build still running when the plugin was updated, say), so
	 * it is never served as fresh — only as a fallback while nothing can be
	 * built. Change it whenever pages are filled differently.
	 */
	public const FORMAT_MARK = "<!-- heirloom-seo sitemap format 2 -->\n";

	/** heirloom_sitemap values this module answers. */
	private const SERVED = [ 'index', 'sub', 'news', 'xsl' ];

	/** Above this many edited posts of a type in one request, invalidate from the earliest one onward instead of page by page. */
	private const MAX_TOUCHED = 20;

	/** Sorts before every post: a shift from here covers every page of the type. */
	private const FIRST = [ '0000-00-00 00:00:00', 0 ];

	/** Post meta that changes what a post page lists: whether the post is on it, and its image. */
	private const WATCHED_META = [ '_heirloom_seo_noindex', '_thumbnail_id' ];


	/** @var array<string,array{0:string,1:int}> provider type => earliest (post_date, ID) whose position changed. */
	private array $shiftFrom = [];

	/** @var array<string,array<int,array{0:string,1:int}>> provider type => post ID => (post_date, ID) of posts changed in place. */
	private array $touched = [];

	/** @var array<string,true> provider types whose in-place changes passed MAX_TOUCHED and now feed $shiftFrom. */
	private array $collapsed = [];

	/** @var array<string,true> taxonomies whose term pages changed. */
	private array $staleTax = [];

	/** @var array<int,array{0:string,1:int}> term ID => (slug, parent) before an edit, for terms in the permalink structure. */
	private array $termsBefore = [];

	private bool $membershipChanged = false;

	/** Permalink settings changed: every URL in every sitemap may have. */
	private bool $allChanged = false;

	private bool $flushScheduled = false;

	/** @var string[]|null */
	private ?array $postTypesMemo = null;

	/** @var array<string,int> */
	private array $countMemo = [];

	/** A read whose empty answer would be believed (IDs, counts, the news query) failed during the current build. */
	private bool $readFailed = false;

	/** @var array<string,string>|null the lastmod map the index being built was rendered from. */
	private ?array $indexMap = null;

	/** @var array<int,true> other sites of a multisite network whose sitemaps this request marked stale. */
	private array $staledSites = [];

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_action( 'init', [ $this, 'addRewriteRules' ] );
		add_filter( 'query_vars', [ $this, 'queryVars' ] );
		add_filter( 'posts_pre_query', [ $this, 'skipMainQuery' ], 10, 2 );
		add_action( 'template_redirect', [ $this, 'maybeServe' ], 0 );

		add_action( 'wp_after_insert_post', [ $this, 'onPostSaved' ], 10, 4 );
		add_action( 'before_delete_post', [ $this, 'onPostDeleting' ], 10, 2 );
		add_action( 'deleted_post', [ $this, 'onPostDeleted' ], 10, 2 );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ $this, 'onPostMetaChanged' ], 10, 3 );
		}
		add_action( 'delete_post_meta', [ $this, 'onPostMetaDeleting' ], 10, 3 );
		foreach ( [ 'created_term', 'edited_term', 'delete_term' ] as $hook ) {
			add_action( $hook, [ $this, 'onTermChanged' ], 10, 3 );
		}
		add_action( 'edit_terms', [ $this, 'rememberTerm' ], 10, 2 );
		foreach ( [ 'edited_term', 'delete_term' ] as $hook ) {
			add_action( $hook, [ $this, 'onPermalinkTermChanged' ], 10, 3 );
		}
		foreach ( [ 'permalink_structure', 'category_base', 'tag_base' ] as $option ) {
			add_action( "update_option_{$option}", [ $this, 'onPermalinksChanged' ] );
		}
		add_action( 'deleted_user', [ $this, 'onUserDeleted' ], 10, 2 );
		add_action( 'remove_user_from_blog', [ $this, 'onUserRemoved' ], 10, 3 );
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

	/**
	 * Sitemaps are served at template_redirect, after WordPress has run the main
	 * query — for a sitemap URL that's the blog home query, with
	 * SQL_CALC_FOUND_ROWS over every published post, on every request (cache
	 * hits included). Hooked to posts_pre_query: answer it with nothing.
	 *
	 * @param mixed $posts
	 * @param mixed $query
	 * @return mixed
	 */
	public function skipMainQuery( $posts, $query ) {
		if ( null === $posts && $query instanceof WP_Query && $query->is_main_query()
			&& in_array( (string) $query->get( 'heirloom_sitemap' ), self::SERVED, true ) ) {
			$query->set( 'ignore_sticky_posts', true ); // The sticky-post fetch runs even without the main SQL.
			$query->found_posts   = 0;
			$query->max_num_pages = 0;
			return [];
		}
		return $posts;
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

		$response = $this->respond( $which, $type, $page );
		if ( null !== $response ) {
			$this->emit( $response );
		}
	}

	/**
	 * Resolve a sitemap request to a response: the cached copy when fresh,
	 * otherwise a rebuild inside the build gate, otherwise the previous copy
	 * (or a 503) when the rebuild can't happen now.
	 *
	 * @internal Public for tests; maybeServe() is the entry point.
	 * @return array{status:int,body:string,max_age:int,cached?:bool}|null null for an unknown sitemap.
	 */
	public function respond( string $which, string $type, int $page ): ?array {
		$key = self::key( $which, $type, $page );
		if ( '' === $key ) {
			return null;
		}

		[ $cached, $fresh ] = FileCache::lookup( $key, $this->ttl( $which, $key ) );
		if ( $fresh && self::isCurrent( $cached ) ) {
			return [ 'status' => 200, 'body' => $cached, 'max_age' => $this->maxAge( $which ) ];
		}

		$built = $this->build( $which, $type, $page );
		if ( null !== $built ) {
			return $built;
		}
		if ( null !== $cached ) {
			return [ 'status' => 200, 'body' => $cached, 'max_age' => 60 ];
		}
		return [ 'status' => 503, 'body' => "Sitemap is being generated. Please retry shortly.\n", 'max_age' => 0 ];
	}

	/**
	 * Generate one sitemap inside the build gate and cache it.
	 *
	 * @internal Public for the regenerate command.
	 * @return array{status:int,body:string,max_age:int,cached?:bool}|null null when it can't be built now:
	 *         every build slot is taken, or one of its reads failed (a failed
	 *         read looks like an empty one, so caching it could publish an empty
	 *         page, or 404 a good one).
	 */
	public function build( string $which, string $type, int $page ): ?array {
		$key = self::key( $which, $type, $page );
		if ( '' === $key ) {
			return null;
		}

		$gate = new BuildGate( 'sub' === $which ? '' : $which );
		if ( ! $gate->acquire() ) {
			return null;
		}

		$entries          = null;
		$this->readFailed = false;
		try {
			if ( 'sub' === $which ) {
				$entries = $this->subEntries( $type, $page );
				$xml     = null === $entries ? '' : $this->renderUrlset( $entries );
			} else {
				$xml = 'index' === $which ? $this->renderIndex() : $this->buildNews();
			}
		} finally {
			$gate->release();
		}

		if ( $this->readFailed ) {
			return null;
		}
		if ( '' === $xml ) {
			FileCache::forget( $key );
			return [ 'status' => 404, 'body' => '', 'max_age' => 0 ];
		}
		$xml .= self::FORMAT_MARK;

		if ( null !== $entries && str_starts_with( $type, 'pt_' ) ) {
			[ $previous ] = FileCache::lookup( $key, 0 );
			$cached       = FileCache::put( $key, $xml );
			if ( $this->recordLastmod( $key, $entries, null !== $previous && $previous !== $xml ) ) {
				FileCache::markStale( 'index' ); // So the index shows the new date.
			}
		} else {
			$cached = FileCache::put( $key, $xml );
			if ( 'index' === $which && self::storedLastmods() !== $this->indexMap ) {
				FileCache::markStale( 'index' ); // A stamp landed while it was being built.
			}
		}
		return [ 'status' => 200, 'body' => $xml, 'max_age' => $this->maxAge( $which ), 'cached' => $cached ];
	}

	/** Whether a cached sitemap was written by this version's code. */
	public static function isCurrent( ?string $xml ): bool {
		return null !== $xml && str_ends_with( $xml, self::FORMAT_MARK );
	}

	/**
	 * Every sitemap the site serves, the index last, so it is built after the
	 * pages whose lastmods it lists.
	 *
	 * @internal For the regenerate command.
	 * @return array<string,array{0:string,1:string,2:int}>|null cache key => build() arguments;
	 *         null when a count failed (it would read as "no pages").
	 */
	public function all(): ?array {
		$this->readFailed = false;
		$list             = [];
		foreach ( $this->providers() as $type => $pages ) {
			for ( $p = 1; $p <= $pages; $p++ ) {
				$list[ "sub_{$type}_{$p}" ] = [ 'sub', $type, $p ];
			}
		}
		if ( $this->options->bool( 'sitemaps.news_enabled' ) ) {
			$list['news'] = [ 'news', '', 0 ];
		}
		$list['index'] = [ 'index', '', 0 ];
		return $this->readFailed ? null : $list;
	}

	private static function key( string $which, string $type, int $page ): string {
		return match ( $which ) {
			'index' => 'index',
			'news'  => 'news',
			'sub'   => "sub_{$type}_{$page}",
			default => '',
		};
	}

	/**
	 * Run a read whose empty result the build would take at face value, noting
	 * whether one of ITS queries failed: wpdb logs every failed query in
	 * $EZSQL_ERROR, and a 'query' filter records which statements the read
	 * issued itself. Queries a plugin runs from inside a hook the read fires
	 * (pre_get_posts, the_posts, a permalink filter …) run one hook deeper and
	 * don't count, so a plugin's own broken query can't keep sitemaps uncached.
	 *
	 * A failed read has often left its empty answer in the object cache (core
	 * caches empty meta, term relationships and query results), where a retry
	 * would find it without an error: $undo clears that.
	 *
	 * @param callable(): mixed      $read
	 * @param callable(mixed): void|null $undo Given the read's result.
	 */
	private function checked( callable $read, ?callable $undo = null ): mixed {
		$depth = count( (array) ( $GLOBALS['wp_current_filter'] ?? [] ) ) + 1; // Inside our 'query' callback.
		$own   = [];
		$spy   = static function ( $sql ) use ( &$own, $depth ) {
			if ( count( (array) ( $GLOBALS['wp_current_filter'] ?? [] ) ) <= $depth ) {
				$own[ (string) $sql ] = true;
			}
			return $sql;
		};
		$before = self::dbErrors();
		add_filter( 'query', $spy, PHP_INT_MAX );
		try {
			$result = $read();
		} finally {
			remove_filter( 'query', $spy, PHP_INT_MAX );
		}
		foreach ( array_slice( (array) ( $GLOBALS['EZSQL_ERROR'] ?? [] ), $before ) as $error ) {
			if ( is_array( $error ) && isset( $own[ (string) ( $error['query'] ?? '' ) ] ) ) {
				$this->readFailed = true;
				if ( null !== $undo ) {
					$undo( $result );
				}
				break;
			}
		}
		return $result;
	}

	private static function dbErrors(): int {
		return isset( $GLOBALS['EZSQL_ERROR'] ) && is_array( $GLOBALS['EZSQL_ERROR'] ) ? count( $GLOBALS['EZSQL_ERROR'] ) : 0;
	}

	/** @param array{status:int,body:string,max_age:int} $response */
	private function emit( array $response ): void {
		if ( ! headers_sent() ) {
			status_header( $response['status'] );
			if ( 200 === $response['status'] ) {
				header( 'Content-Type: application/xml; charset=UTF-8' );
				header( 'X-Robots-Tag: noindex, follow', true );
				header( 'Cache-Control: public, max-age=' . $response['max_age'] );
			} elseif ( 503 === $response['status'] ) {
				header( 'Content-Type: text/plain; charset=UTF-8' );
				header( 'Retry-After: 120' );
				header( 'Cache-Control: no-store' );
			}
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- XML assembled with esc_url/esc_xml; fixed plain-text otherwise.
		exit;
	}

	/**
	 * Seconds a cached file counts as fresh — a backstop for changes made without
	 * WordPress hooks (direct DB writes). Spread by up to 10% per key, so pages
	 * built together don't all expire together.
	 */
	private function ttl( string $which, string $key ): int {
		$default = 'news' === $which ? 15 * MINUTE_IN_SECONDS : DAY_IN_SECONDS;
		$ttl     = (int) apply_filters( 'heirloom_seo_sitemap_cache_ttl', $default, $which );
		return $ttl > 0 ? $ttl + crc32( $key ) % ( intdiv( $ttl, 10 ) + 1 ) : $ttl;
	}

	/** Cache-Control max-age sent with a sitemap, so a CDN may hold it. */
	private function maxAge( string $which ): int {
		$default = match ( $which ) {
			'news'  => 300,
			'index' => 900,
			default => 3600,
		};
		return max( 0, (int) apply_filters( 'heirloom_seo_sitemap_max_age', $default, $which ) );
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

		$lastmods = self::storedLastmods();
		if ( null === $lastmods ) {
			$this->readFailed = true; // An index without its lastmods isn't worth caching.
			$lastmods         = [];
		}
		$this->indexMap = $lastmods;
		$floor          = $lastmods[ self::FLOOR_KEY ] ?? '';
		foreach ( $this->providers() as $type => $pages ) {
			$is_posts = str_starts_with( $type, 'pt_' );
			for ( $p = 1; $p <= $pages; $p++ ) {
				$xml    .= "\t<sitemap>\n\t\t<loc>" . esc_url( home_url( "/sitemap-{$type}-{$p}.xml" ) ) . "</loc>\n";
				$lastmod = $lastmods[ "sub_{$type}_{$p}" ] ?? '';
				if ( $is_posts && strcmp( $floor, $lastmod ) > 0 ) {
					$lastmod = $floor;
				}
				if ( '' !== $lastmod ) {
					$xml .= "\t\t<lastmod>" . esc_xml( $lastmod ) . "</lastmod>\n";
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

	/** @return array<string,int> provider key => page count (providers with nothing to list are left out). */
	private function providers(): array {
		$keys = [ 'extra' ];
		foreach ( $this->postTypes() as $post_type ) {
			$keys[] = 'pt_' . $post_type;
		}
		foreach ( $this->taxonomies() as $taxonomy ) {
			$keys[] = 'tax_' . $taxonomy;
		}
		$keys[] = 'author';

		$list = [];
		foreach ( $keys as $key ) {
			$pages = $this->pagesFor( $key );
			if ( $pages > 0 ) {
				$list[ $key ] = $pages;
			}
		}
		return $list;
	}

	/** Page count for one provider — only that provider's count is computed. */
	private function pagesFor( string $type ): int {
		$per = $this->perPage();
		if ( 'extra' === $type ) {
			return $this->extraEntries() ? 1 : 0;
		}
		if ( str_starts_with( $type, 'pt_' ) ) {
			$post_type = substr( $type, 3 );
			return in_array( $post_type, $this->postTypes(), true ) ? (int) ceil( $this->publishedCount( $post_type ) / $per ) : 0;
		}
		if ( str_starts_with( $type, 'tax_' ) ) {
			$taxonomy = substr( $type, 4 );
			return in_array( $taxonomy, $this->taxonomies(), true ) ? (int) ceil( $this->termCount( $taxonomy ) / $per ) : 0;
		}
		if ( 'author' === $type ) {
			return $this->options->bool( 'sitemaps.authors' ) ? (int) ceil( $this->authorCount() / $per ) : 0;
		}
		return 0;
	}

	// --- Lastmod bookkeeping ----------------------------------------------

	/** @return array<string,string> */
	private static function lastmods(): array {
		$map = get_option( self::LASTMOD_OPTION, [] );
		if ( ! is_array( $map ) ) {
			return [];
		}
		return array_filter(
			$map,
			static fn( $value, $key ): bool => is_string( $key ) && is_string( $value ) && '' !== $value,
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * After building a post page, remember its lastmod: the newest post_modified
	 * on it, or now when its contents changed with no post_modified to show for
	 * it (a change caught late — a missed save, a stale copy served meanwhile)
	 * or the page has no lastmod yet (first build, e.g. after an upgrade that
	 * reordered the pages). A later stamp already on record wins.
	 *
	 * @param array<int,array{loc:string,lastmod:string,images?:string[]}> $entries
	 * @return bool Whether the stored lastmod changed.
	 */
	private function recordLastmod( string $key, array $entries, bool $changed ): bool {
		$newest = '';
		foreach ( $entries as $entry ) {
			if ( strcmp( $entry['lastmod'], $newest ) > 0 ) {
				$newest = $entry['lastmod'];
			}
		}
		$now = gmdate( 'c' );
		if ( $changed ) {
			$newest = $now;
		}
		return self::mergeLastmods( '' === $newest ? [] : [ $key => $newest ], [], $now, $key );
	}

	/**
	 * Every post page's contents changed at once (page size, images, post types,
	 * permalinks): advertise them all as modified now. The per-page stamps stay.
	 */
	public static function markAllPagesChanged(): void {
		if ( self::mergeLastmods( [ self::FLOOR_KEY => gmdate( 'c' ) ] ) ) {
			FileCache::markStale( 'index' );
		}
	}

	/**
	 * Merge stamps into the stored lastmod map (the later stamp wins) and drop
	 * pages past each type's last page.
	 *
	 * Merges take turns on a lock of their own (waiting up to 2 s; without it,
	 * they merge anyway), and each re-reads the option past the object cache,
	 * so two can't each write back a map missing the other's stamps. A failed
	 * read writes nothing: it looks like no map, and writing would replace
	 * every stamp.
	 *
	 * @param array<string,string> $stamps    key => ISO 8601 UTC
	 * @param array<string,int>    $lastPages provider type => its last page
	 * @param string               $firstStamp Stamp for $firstKey when the map has no entry for it yet.
	 * @return bool Whether the stored map changed.
	 */
	private static function mergeLastmods( array $stamps, array $lastPages = [], string $firstStamp = '', string $firstKey = '' ): bool {
		$lock = new BuildGate( 'lastmod', 2 );
		$lock->acquire();
		try {
			$map = self::storedLastmods();
			if ( null === $map ) {
				return false;
			}
			if ( '' !== $firstKey && ! isset( $map[ $firstKey ] ) ) {
				$stamps[ $firstKey ] = $firstStamp;
			}
			$changed = false;
			foreach ( $stamps as $key => $stamp ) {
				if ( ! isset( $map[ $key ] ) || strcmp( $stamp, $map[ $key ] ) > 0 ) {
					$map[ $key ] = $stamp;
					$changed     = true;
				}
			}
			foreach ( $lastPages as $type => $last ) {
				foreach ( array_keys( $map ) as $key ) {
					if ( preg_match( self::pageKeyPattern( $type ), $key, $m ) && (int) $m[1] > $last ) {
						unset( $map[ $key ] ); // The page no longer exists.
						$changed = true;
					}
				}
			}
			if ( $changed ) {
				update_option( self::LASTMOD_OPTION, $map, false );
			}
			return $changed;
		} finally {
			$lock->release();
		}
	}

	/**
	 * The stored lastmod map, read past the object cache — including core's
	 * cached "no such option", which would otherwise answer for it. Null when
	 * the read failed.
	 *
	 * @return array<string,string>|null
	 */
	private static function storedLastmods(): ?array {
		wp_cache_delete( self::LASTMOD_OPTION, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ self::LASTMOD_OPTION ] ) ) {
			unset( $notoptions[ self::LASTMOD_OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		$errors = self::dbErrors();
		$map    = self::lastmods();
		return self::dbErrors() > $errors ? null : $map;
	}

	// --- Sub-sitemaps -----------------------------------------------------

	/** @return array<int,array{loc:string,lastmod:string,images?:string[]}>|null null when there is no such page. */
	private function subEntries( string $type, int $page ): ?array {
		if ( str_starts_with( $type, 'pt_' ) ) {
			// No count needed: a page past the end has no IDs.
			$post_type = substr( $type, 3 );
			return in_array( $post_type, $this->postTypes(), true ) ? $this->postEntries( $post_type, $page ) : null;
		}
		if ( $page > $this->pagesFor( $type ) ) {
			return null;
		}
		if ( 'extra' === $type ) {
			return $this->extraEntries();
		}
		if ( str_starts_with( $type, 'tax_' ) ) {
			return $this->termEntries( substr( $type, 4 ), $page );
		}
		if ( 'author' === $type ) {
			return $this->authorEntries( $page );
		}
		return null;
	}

	/** @return array<int,array{loc:string,lastmod:string}> */
	private function extraEntries(): array {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			return []; // The front page is a Page already covered by the pages sitemap.
		}
		return [ [ 'loc' => home_url( '/' ), 'lastmod' => gmdate( 'c' ) ] ];
	}

	/** @return array<int,array{loc:string,lastmod:string,images?:string[]}>|null null when the page is past the end. */
	private function postEntries( string $post_type, int $page ): ?array {
		global $wpdb;
		$per = $this->perPage();
		if ( $page - 1 > intdiv( PHP_INT_MAX, $per ) ) {
			return null; // A junk page number: its offset would overflow (and wpdb's %d wrap it onto a real page).
		}

		// Oldest first, so pages keep their contents as the site grows. Covered
		// by core's type_status_date index — no filesort, no row reads. Raw SQL
		// (no WP_Query filters) keeps it consistent with rankOf(); to leave posts
		// out, use the heirloom_seo_sitemap_include_post filter below.
		$ids = array_map(
			'intval',
			(array) $this->checked(
				fn() => $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY post_date ASC, ID ASC LIMIT %d, %d",
						$post_type,
						( $page - 1 ) * $per,
						$per
					)
				)
			)
		);
		if ( ! $ids ) {
			return null;
		}

		$with_images = $this->options->bool( 'sitemaps.images' );

		// Batch-load posts and meta (plus terms, when permalinks or an include
		// filter use them), then the featured images, instead of a few queries
		// per post. A failed meta load would read as "no noindex flag" (or "no
		// image") for every post.
		if ( function_exists( '_prime_post_caches' ) ) {
			$terms = $this->permalinksUseTerms( $post_type ) || has_filter( 'heirloom_seo_sitemap_include_post' );
			$this->checked(
				static fn() => _prime_post_caches( $ids, $terms, true ),
				static fn() => self::unprime( $ids, $terms ? get_object_taxonomies( $post_type ) : [] )
			);
			if ( $with_images ) {
				$thumbs = [];
				foreach ( $ids as $id ) {
					$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
					if ( $thumb > 0 ) {
						$thumbs[ $thumb ] = $thumb;
					}
				}
				if ( $thumbs ) {
					$thumbs = array_values( $thumbs );
					$this->checked(
						static fn() => _prime_post_caches( $thumbs, false, true ),
						static fn() => self::unprime( $thumbs, [] )
					);
				}
			}
		}

		$entries = [];
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			if ( get_post_meta( $post->ID, '_heirloom_seo_noindex', true ) ) {
				continue;
			}
			/**
			 * Whether a published post is listed in the post sitemaps. A post left
			 * out keeps its place, so the pages after it don't change.
			 *
			 * @param bool    $include Default true.
			 * @param WP_Post $post
			 */
			if ( ! apply_filters( 'heirloom_seo_sitemap_include_post', true, $post ) ) {
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

	/**
	 * Forget what a failed prime cached: empty meta and term relationships for
	 * every ID, as if they had none.
	 *
	 * @param int[]    $ids
	 * @param string[] $taxonomies
	 */
	private static function unprime( array $ids, array $taxonomies ): void {
		wp_cache_delete_multiple( $ids, 'post_meta' );
		foreach ( $taxonomies as $taxonomy ) {
			wp_cache_delete_multiple( $ids, "{$taxonomy}_relationships" );
		}
		if ( $taxonomies ) {
			wp_cache_set_terms_last_changed();
		}
	}

	/**
	 * Whether building a post's permalink reads its terms. For posts, only when
	 * the permalink structure has a tag beyond date/name/ID/author (%category%
	 * or a plugin's %taxonomy%). Other post types have their own permastructs,
	 * so they always get their terms loaded.
	 */
	private function permalinksUseTerms( string $post_type ): bool {
		if ( 'post' !== $post_type ) {
			return true;
		}
		return 1 === preg_match(
			'/%(?!(?:year|monthnum|day|hour|minute|second|postname|post_id|author)%)[a-z0-9_-]+%/i',
			(string) get_option( 'permalink_structure' )
		);
	}

	/** @return array<int,array{loc:string,lastmod:string}> */
	private function termEntries( string $taxonomy, int $page ): array {
		$per   = $this->perPage();
		$terms = $this->checked(
			static fn() => get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => $per,
					'offset'     => ( $page - 1 ) * $per,
					'orderby'    => 'id',
				]
			),
			static fn() => wp_cache_set_terms_last_changed()
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
		$users = $this->checked(
			static fn() => get_users(
				[
					'has_published_posts' => [ 'post' ],
					'fields'              => [ 'ID' ],
					'number'              => $per,
					'offset'              => ( $page - 1 ) * $per,
					'orderby'             => 'ID',
					'meta_query'          => self::hiddenAuthorExclusion(),
				]
			),
			static fn() => self::forgetUserQueries()
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

		$query = $this->checked(
			static fn() => new WP_Query(
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
					'date_query'             => self::newsDateQuery(),
				]
			),
			static fn( $query ) => self::forgetPostQuery( $query )
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
		$query = $this->checked(
			static fn() => new WP_Query(
				[
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'date_query'     => self::newsDateQuery(),
				]
			),
			static fn( $query ) => self::forgetPostQuery( $query )
		);
		return ! empty( $query->posts );
	}

	/**
	 * Google News takes the last 48 hours, by post_date_gmt. The cutoff is a
	 * UTC moment given as date parts, which WordPress uses as written: given a
	 * phrase like "48 hours ago", it reads it in the site's time zone and so
	 * stretched or shrank the window by the site's UTC offset (52 hours at
	 * UTC−4). post_date_gmt has no index, so a looser post_date bound (62 hours
	 * covers any UTC offset) lets MySQL range-scan type_status_date instead of
	 * reading every post in the News term.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function newsDateQuery(): array {
		$utc = static function ( int $hours ): array {
			$t = time() - $hours * HOUR_IN_SECONDS;
			return [
				'year'   => (int) gmdate( 'Y', $t ),
				'month'  => (int) gmdate( 'n', $t ),
				'day'    => (int) gmdate( 'j', $t ),
				'hour'   => (int) gmdate( 'G', $t ),
				'minute' => (int) gmdate( 'i', $t ),
				'second' => (int) gmdate( 's', $t ),
			];
		};
		return [
			[ 'after' => $utc( 62 ), 'column' => 'post_date' ],
			[ 'after' => $utc( 48 ), 'column' => 'post_date_gmt', 'inclusive' => true ],
		];
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
			$term = $this->checked( static fn() => get_term_by( 'name', $name, $taxonomy ), static fn() => wp_cache_set_terms_last_changed() );
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
		if ( null !== $this->postTypesMemo ) {
			return $this->postTypesMemo;
		}
		$configured = $this->options->arr( 'sitemaps.post_types' );
		$all        = get_post_types( [ 'public' => true ], 'names' );
		unset( $all['attachment'] );

		$types = array_values( array_filter( $all, 'is_post_type_viewable' ) );
		if ( $configured ) {
			$types = array_values( array_intersect( $types, $configured ) );
		}
		$this->postTypesMemo = $types;
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

	/** Published posts of a type — the same population postEntries() pages through. */
	private function publishedCount( string $post_type ): int {
		if ( ! isset( $this->countMemo[ $post_type ] ) ) {
			global $wpdb;
			$count = $this->checked(
				fn() => $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $post_type )
				)
			);
			if ( null === $count ) {
				return 0; // Failed: not remembered, so the next build asks again.
			}
			$this->countMemo[ $post_type ] = (int) $count;
		}
		return $this->countMemo[ $post_type ];
	}

	private function termCount( string $taxonomy ): int {
		$count = $this->checked( static fn() => wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true ] ), static fn() => wp_cache_set_terms_last_changed() );
		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	private function authorCount(): int {
		return count(
			(array) $this->checked(
				static fn() => get_users(
					[
						'has_published_posts' => [ 'post' ],
						'fields'              => 'ID',
						'meta_query'          => self::hiddenAuthorExclusion(),
					]
				),
				static fn() => self::forgetUserQueries()
			)
		);
	}

	/** After a failed user query: core may have cached its empty result. */
	private static function forgetUserQueries(): void {
		if ( function_exists( 'wp_cache_set_users_last_changed' ) ) {
			wp_cache_set_users_last_changed();
		}
	}

	/**
	 * After a failed news query: core may have cached its empty result, or
	 * empty meta for the posts it found.
	 *
	 * @param mixed $query
	 */
	private static function forgetPostQuery( $query ): void {
		wp_cache_set_posts_last_changed();
		if ( $query instanceof WP_Query ) {
			$ids = array_map( static fn( $post ): int => is_object( $post ) ? (int) $post->ID : (int) $post, $query->posts );
			if ( $ids ) {
				wp_cache_delete_multiple( $ids, 'post_meta' );
			}
		}
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

	// --- Invalidation -----------------------------------------------------

	/**
	 * Hooked to wp_after_insert_post (fires once per save, after meta and terms,
	 * with the pre-save post). Records which pages the save affects; the cache
	 * files are marked stale once, at shutdown.
	 *
	 * @param mixed $post_id     Unused.
	 * @param mixed $post        The saved post.
	 * @param mixed $update      Unused.
	 * @param mixed $post_before The post before this save (null for a new post).
	 */
	public function onPostSaved( $post_id, $post, $update = false, $post_before = null ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$before = $post_before instanceof WP_Post ? $post_before : null;
		if ( self::elsewhere() ) {
			if ( 'publish' === $post->post_status || ( null !== $before && 'publish' === $before->post_status ) ) {
				$this->staleElsewhere();
			}
			return;
		}

		// A hierarchical post's slug and parent are part of its descendants'
		// URLs, whatever its own status (a trashed or restored parent too).
		if ( null !== $before && ( $before->post_name !== $post->post_name || (int) $before->post_parent !== (int) $post->post_parent )
			&& $this->hasChildren( $post ) ) {
			$this->allPostsChanged( $post->post_type );
		}

		$was = null !== $before && $this->inSitemap( $before );
		$is  = $this->inSitemap( $post );
		if ( ! $was && ! $is ) {
			return; // Drafts, revisions, autosaves, menu items, excluded types.
		}

		if ( $was && $is && $before->post_type === $post->post_type && $before->post_date === $post->post_date ) {
			$this->touch( $post );
			if ( (int) $before->post_author !== (int) $post->post_author ) {
				$this->membershipChanged = true;
			}
		} else {
			if ( $was ) {
				$this->shift( $before );
			}
			if ( $is ) {
				$this->shift( $post );
			}
			$this->membershipChanged = true;
		}
		$this->scheduleFlush();
	}

	/**
	 * Hooked to deleted_post.
	 *
	 * @param mixed $post_id Unused.
	 * @param mixed $post    The deleted post.
	 */
	public function onPostDeleted( $post_id, $post = null ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( self::elsewhere() ) {
			if ( 'publish' === $post->post_status ) {
				$this->staleElsewhere();
			}
			return;
		}
		if ( $this->inSitemap( $post ) ) {
			$this->shift( $post );
			$this->membershipChanged = true;
			$this->scheduleFlush();
		}
	}

	/**
	 * Hooked to before_delete_post: deleting a hierarchical post moves its
	 * children up a level by direct SQL, changing their URLs — and the
	 * children are only findable before it happens.
	 *
	 * @param mixed $post_id Unused.
	 * @param mixed $post    The post about to be deleted.
	 */
	public function onPostDeleting( $post_id, $post = null ): void {
		if ( $post instanceof WP_Post && ! self::elsewhere() && $this->hasChildren( $post ) ) {
			$this->allPostsChanged( $post->post_type );
		}
	}

	/**
	 * Hooked to added/updated/deleted_post_meta. The noindex flag and the
	 * featured image change what a post page lists, and bulk tools, importers
	 * and WP-CLI set them without saving the post.
	 *
	 * @param mixed $meta_id  Unused.
	 * @param mixed $post_id  0 for a delete across posts — handled before it happens, by onPostMetaDeleting().
	 * @param mixed $meta_key
	 */
	public function onPostMetaChanged( $meta_id, $post_id, $meta_key = '' ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! $this->watchesMeta( $meta_key ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( self::elsewhere() ) {
			if ( 'publish' === $post->post_status ) {
				$this->staleElsewhere();
			}
		} elseif ( $this->inSitemap( $post ) ) {
			$this->touch( $post );
			$this->scheduleFlush();
		}
	}

	/**
	 * Hooked to delete_post_meta, which fires while the rows still exist. A
	 * delete across posts (post ID 0) — such as wp_delete_attachment()
	 * clearing every _thumbnail_id that pointed at a deleted image — is
	 * resolved to the posts it touches, by meta ID. A large one, such as
	 * delete_post_meta_by_key(), counts as a change to every page.
	 *
	 * @param mixed $meta_ids
	 * @param mixed $post_id
	 * @param mixed $meta_key
	 */
	public function onPostMetaDeleting( $meta_ids, $post_id, $meta_key = '' ): void {
		if ( 0 !== (int) $post_id || ! $this->watchesMeta( $meta_key ) ) {
			return;
		}
		if ( self::elsewhere() ) {
			$this->staleElsewhere();
			return;
		}
		$meta_ids = array_values( array_filter( array_map( 'intval', (array) $meta_ids ) ) );
		if ( ! $meta_ids ) {
			return;
		}
		if ( count( $meta_ids ) > self::MAX_TOUCHED ) {
			foreach ( $this->postTypes() as $post_type ) {
				$this->allPostsChanged( $post_type );
			}
			return;
		}
		global $wpdb;
		$post_ids = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- primary-key lookups, at most MAX_TOUCHED.
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_id IN (" . implode( ',', $meta_ids ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- intval()ed IDs.
		);
		foreach ( $post_ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post instanceof WP_Post && $this->inSitemap( $post ) ) {
				$this->touch( $post );
				$this->scheduleFlush();
			}
		}
	}

	/** @param mixed $meta_key */
	private function watchesMeta( $meta_key ): bool {
		if ( ! in_array( $meta_key, self::WATCHED_META, true ) ) {
			return false;
		}
		return '_thumbnail_id' !== $meta_key || $this->options->bool( 'sitemaps.images' );
	}

	/**
	 * Hooked to created_term / edited_term / delete_term.
	 *
	 * @param mixed $term_id  Unused.
	 * @param mixed $tt_id    Unused.
	 * @param mixed $taxonomy Taxonomy slug.
	 */
	public function onTermChanged( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		$taxonomy = (string) $taxonomy;
		if ( self::elsewhere() ) {
			$this->staleElsewhere();
		} elseif ( '' !== $taxonomy && in_array( $taxonomy, $this->taxonomies(), true ) ) {
			$this->staleTax[ $taxonomy ] = true;
			$this->scheduleFlush();
		}
	}

	/**
	 * Hooked to edit_terms (before the update): remember the slug and parent of
	 * a term whose taxonomy is in the post permalink structure (%category%).
	 *
	 * @param mixed $term_id
	 * @param mixed $taxonomy
	 */
	public function rememberTerm( $term_id, $taxonomy = '' ): void {
		if ( self::elsewhere() || ! self::inPermalinks( (string) $taxonomy ) ) {
			return;
		}
		$term = get_term( (int) $term_id, (string) $taxonomy );
		if ( is_object( $term ) && isset( $term->slug ) ) {
			$this->termsBefore[ (int) $term_id ] = [ (string) $term->slug, (int) $term->parent ];
		}
	}

	/**
	 * Hooked to edited_term / delete_term: under a %category%-style permalink
	 * structure, a renamed, moved or deleted term changes its posts' URLs.
	 *
	 * @param mixed $term_id
	 * @param mixed $tt_id    Unused.
	 * @param mixed $taxonomy
	 */
	public function onPermalinkTermChanged( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		$taxonomy = (string) $taxonomy;
		if ( self::elsewhere() || ! self::inPermalinks( $taxonomy ) ) {
			return;
		}
		$before = $this->termsBefore[ (int) $term_id ] ?? null;
		unset( $this->termsBefore[ (int) $term_id ] );
		$term = get_term( (int) $term_id, $taxonomy );
		$now  = is_object( $term ) && isset( $term->slug ) ? [ (string) $term->slug, (int) $term->parent ] : null;
		if ( null === $before || $before !== $now ) {
			$this->allPostsChanged( 'post' );
		}
	}

	/**
	 * Hooked to deleted_user. Reassigned posts change author by direct SQL,
	 * with no post hooks: the author sitemap changes, and so do post URLs under
	 * an %author% permalink structure.
	 *
	 * @param mixed $user_id  Unused.
	 * @param mixed $reassign User the posts went to, or null.
	 */
	public function onUserDeleted( $user_id, $reassign = null ): void {
		if ( null !== $reassign ) {
			$this->authorsChanged( (int) $reassign > 0 );
		}
	}

	/**
	 * Hooked to remove_user_from_blog (multisite, run switched to that site):
	 * the user leaves its author sitemap, and reassigned posts change author by
	 * direct SQL.
	 *
	 * @param mixed $user_id  Unused.
	 * @param mixed $blog_id  Unused (the hook runs switched to it).
	 * @param mixed $reassign User the posts went to, or 0.
	 */
	public function onUserRemoved( $user_id, $blog_id = 0, $reassign = 0 ): void {
		$this->authorsChanged( (int) $reassign > 0 );
	}

	private function authorsChanged( bool $reassigned ): void {
		if ( self::elsewhere() ) {
			$this->staleElsewhere();
			return;
		}
		$this->membershipChanged = true;
		if ( $reassigned && self::inPermalinks( 'author' ) ) {
			$this->allPostsChanged( 'post' );
		}
		$this->scheduleFlush();
	}

	/** Hooked to update_option_{permalink_structure,category_base,tag_base}: any sitemap URL may have changed. */
	public function onPermalinksChanged(): void {
		if ( self::elsewhere() ) {
			$this->staleElsewhere();
			return;
		}
		$this->allChanged = true;
		$this->scheduleFlush();
	}

	/**
	 * Mark the cache files the recorded changes affect as stale and stamp their
	 * lastmod. Runs once per request, at shutdown.
	 *
	 * A count that fails here reads as 0, so it places nothing: the pages it
	 * would have placed are marked stale with no stamp and no pruning, and each
	 * rebuild stamps itself if its contents changed.
	 *
	 * @internal Public for the shutdown hook and tests.
	 */
	public function flushInvalidations(): void {
		$this->flushScheduled = false;
		$this->countMemo      = [];
		$now                  = gmdate( 'c' );
		$per                  = $this->perPage();
		$stamps               = [];
		$lastPages            = [];
		$stale                = [
			'index' => true,
			'news'  => true,
		];

		if ( $this->allChanged ) {
			foreach ( FileCache::keys( 'sub_' ) as $key ) {
				$stale[ $key ] = true;
			}
			$stamps[ self::FLOOR_KEY ] = $now;
		}

		foreach ( $this->touched as $type => $posts ) {
			// Posts on or after the type's shift point are on pages the shift
			// below covers (a publish that also set its image or noindex).
			$shift = $this->shiftFrom[ $type ] ?? null;
			if ( null !== $shift ) {
				$posts = array_filter( $posts, static fn( array $t ): bool => $t[0] < $shift[0] || ( $t[0] === $shift[0] && $t[1] < $shift[1] ) );
			}
			$errors = self::dbErrors();
			$ranks  = $this->ranksOf( substr( $type, 3 ), $posts );
			if ( self::dbErrors() > $errors ) {
				foreach ( $this->cachedPages( $type ) as $key ) {
					$stale[ $key ] = true;
				}
				continue;
			}
			foreach ( $ranks as $rank ) {
				$key            = "sub_{$type}_" . ( intdiv( $rank, $per ) + 1 );
				$stale[ $key ]  = true;
				$stamps[ $key ] = $now;
			}
		}

		foreach ( $this->shiftFrom as $type => $tuple ) {
			$errors = self::dbErrors();
			$from   = intdiv( $this->rankOf( substr( $type, 3 ), $tuple ), $per ) + 1;
			$last   = $this->pagesFor( $type );
			$placed = self::dbErrors() === $errors;
			foreach ( $this->cachedPages( $type ) as $page => $key ) {
				if ( $page >= $from || ! $placed ) {
					$stale[ $key ] = true;
				}
			}
			if ( $placed ) {
				for ( $page = $from; $page <= $last; $page++ ) {
					$stamps[ "sub_{$type}_{$page}" ] = $now;
				}
				$lastPages[ $type ] = $last;
			}
		}

		if ( $this->membershipChanged ) {
			foreach ( [ 'sub_extra_', 'sub_author_', 'sub_tax_' ] as $prefix ) {
				foreach ( FileCache::keys( $prefix ) as $key ) {
					$stale[ $key ] = true;
				}
			}
			$stamps['sub_extra_1'] = $now;
		}

		foreach ( array_keys( $this->staleTax ) as $taxonomy ) {
			foreach ( $this->cachedPages( 'tax_' . $taxonomy ) as $key ) {
				$stale[ $key ] = true;
			}
		}

		// Stamps first, so an index rebuilt as soon as it goes stale lists them.
		self::mergeLastmods( $stamps, $lastPages );
		FileCache::markStale( ...array_keys( $stale ) );

		$this->touched           = [];
		$this->collapsed         = [];
		$this->shiftFrom         = [];
		$this->staleTax          = [];
		$this->membershipChanged = false;
		$this->allChanged        = false;
	}

	private function scheduleFlush(): void {
		if ( ! $this->flushScheduled ) {
			$this->flushScheduled = true;
			add_action( 'shutdown', [ $this, 'flushInvalidations' ] );
		}
	}

	/**
	 * Whether this runs switched to another site of a multisite network. Its
	 * pages can't be placed from here (the shutdown flush runs back on the
	 * site the request started on), so its sitemaps are marked stale instead.
	 */
	private static function elsewhere(): bool {
		return function_exists( 'ms_is_switched' ) && ms_is_switched()
			&& get_current_blog_id() !== (int) ( $GLOBALS['_wp_switched_stack'][0] ?? 0 );
	}

	/**
	 * A change on another site of the network: mark every sitemap of that site
	 * stale, right away and once per request — the files and the uploads
	 * folder in reach are that site's. Each page's rebuild advertises a new
	 * lastmod only if its contents changed.
	 */
	private function staleElsewhere(): void {
		$site = get_current_blog_id();
		if ( ! isset( $this->staledSites[ $site ] ) ) {
			$this->staledSites[ $site ] = true;
			FileCache::markStale( 'index', 'news', ...FileCache::keys( 'sub_' ) );
		}
	}

	private static function inPermalinks( string $tag ): bool {
		return '' !== $tag && str_contains( (string) get_option( 'permalink_structure' ), '%' . $tag . '%' );
	}

	/** Whether a hierarchical post in the sitemaps has children (whose URLs carry its slug). */
	private function hasChildren( WP_Post $post ): bool {
		if ( ! in_array( $post->post_type, $this->postTypes(), true ) || ! is_post_type_hierarchical( $post->post_type ) ) {
			return false;
		}
		global $wpdb;
		return null !== $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s LIMIT 1", (int) $post->ID, $post->post_type )
		);
	}

	private function inSitemap( WP_Post $post ): bool {
		return 'publish' === $post->post_status && in_array( $post->post_type, $this->postTypes(), true );
	}

	/**
	 * A post changed in place: only its own page changes. Past MAX_TOUCHED posts
	 * of a type in one request (a bulk edit, an import), they fold into one
	 * shift from the earliest, so memory and rank queries stay flat.
	 */
	private function touch( WP_Post $post ): void {
		$type  = 'pt_' . $post->post_type;
		$tuple = [ (string) $post->post_date, (int) $post->ID ];
		if ( isset( $this->collapsed[ $type ] ) ) {
			$this->shiftTuple( $type, $tuple );
			return;
		}
		$this->touched[ $type ][ $tuple[1] ] = $tuple;
		if ( count( $this->touched[ $type ] ) > self::MAX_TOUCHED ) {
			foreach ( $this->touched[ $type ] as $earlier ) {
				$this->shiftTuple( $type, $earlier );
			}
			unset( $this->touched[ $type ] );
			$this->collapsed[ $type ] = true;
		}
	}

	/** Every page from this post's position onward changes (it arrived, left, or moved). */
	private function shift( WP_Post $post ): void {
		$this->shiftTuple( 'pt_' . $post->post_type, [ (string) $post->post_date, (int) $post->ID ] );
	}

	/** Every page of the type changes (its URLs did). */
	private function allPostsChanged( string $post_type ): void {
		if ( in_array( $post_type, $this->postTypes(), true ) ) {
			$this->shiftTuple( 'pt_' . $post_type, self::FIRST );
			$this->scheduleFlush();
		}
	}

	/** @param array{0:string,1:int} $tuple */
	private function shiftTuple( string $type, array $tuple ): void {
		$current = $this->shiftFrom[ $type ] ?? null;
		if ( null === $current || $tuple[0] < $current[0] || ( $tuple[0] === $current[0] && $tuple[1] < $current[1] ) ) {
			$this->shiftFrom[ $type ] = $tuple;
		}
	}

	/**
	 * How many published posts of the type sort before (post_date, ID) — i.e.
	 * that post's zero-based position in the sitemap. Two index range counts.
	 *
	 * @param array{0:string,1:int} $tuple
	 */
	private function rankOf( string $post_type, array $tuple ): int {
		if ( self::FIRST === $tuple ) {
			return 0;
		}
		global $wpdb;
		$before = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_date < %s", $post_type, $tuple[0] )
		);
		$ties   = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_date = %s AND ID < %d", $post_type, $tuple[0], $tuple[1] )
		);
		return $before + $ties;
	}

	/**
	 * Ranks of several posts in one pass over the index: sorted oldest first,
	 * the first costs a rankOf() and each later one only a count of the gap
	 * since the previous — instead of a near-full scan per recent post.
	 *
	 * @param array<int,array{0:string,1:int}> $tuples
	 * @return int[]
	 */
	private function ranksOf( string $post_type, array $tuples ): array {
		$tuples = array_values( $tuples );
		usort( $tuples, static fn( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );
		$ranks = [];
		$prev  = null;
		$rank  = 0;
		foreach ( $tuples as $tuple ) {
			$rank    = null === $prev ? $this->rankOf( $post_type, $tuple ) : $rank + $this->countBetween( $post_type, $prev, $tuple );
			$ranks[] = $rank;
			$prev    = $tuple;
		}
		return $ranks;
	}

	/**
	 * Published posts with $from <= (post_date, ID) < $to — an index range over
	 * the gap only.
	 *
	 * @param array{0:string,1:int} $from
	 * @param array{0:string,1:int} $to
	 */
	private function countBetween( string $post_type, array $from, array $to ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_date >= %s AND post_date <= %s AND ( post_date > %s OR ID >= %d ) AND ( post_date < %s OR ID < %d )",
				$post_type,
				$from[0],
				$to[0],
				$from[0],
				$from[1],
				$to[0],
				$to[1]
			)
		);
	}

	/** @return array<int,string> page number => cache key, for this provider's cached pages. */
	private function cachedPages( string $type ): array {
		$pages = [];
		foreach ( FileCache::keys( "sub_{$type}_" ) as $key ) {
			if ( preg_match( self::pageKeyPattern( $type ), $key, $m ) ) {
				$pages[ (int) $m[1] ] = $key;
			}
		}
		return $pages;
	}

	private static function pageKeyPattern( string $type ): string {
		return '/^sub_' . preg_quote( $type, '/' ) . '_(\d+)$/';
	}

	private function perPage(): int {
		return max( 1, min( 50000, $this->options->int( 'sitemaps.per_page', 1000 ) ) );
	}
}
