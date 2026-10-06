<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests\Support;

/**
 * A minimal $wpdb for the sitemap tests: models the published posts of the
 * 'post' type (ID => post_date), page parents, a few postmeta rows and MySQL
 * named locks, and answers exactly the SQL shapes the sitemap module sends.
 * Every query is logged in $queries so tests can assert what ran (and what
 * did not).
 *
 * Like real wpdb, every statement first passes through $filter (the 'query'
 * filter), and a statement containing one of the $failOn fragments fails the
 * way real wpdb fails: logged in $EZSQL_ERROR (as the filtered SQL),
 * last_error set, and an empty result (null from get_var, [] from get_col).
 */
final class FakeWpdb {

	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $prefix   = 'wp_';
	public string $dbname   = 'testdb';

	public string $last_error = '';

	/** @var string[] */
	public array $queries = [];

	/** @var array<int,string> published 'post' rows: ID => post_date */
	public array $published = [];

	/** @var array<int,int> child post ID => parent post ID (any status) */
	public array $parents = [];

	/** @var array<int,int> postmeta meta_id => post_id */
	public array $metaRows = [];

	/** @var array<string,true> lock names held by other requests */
	public array $busyLocks = [];

	/** @var array<string,true> lock names held by this request */
	public array $held = [];

	public bool $locksUnsupported = false;

	/** Every GET_LOCK finds its slot taken by another request. */
	public bool $allBusy = false;

	/** @var string[] queries containing any of these fragments fail */
	public array $failOn = [];

	/** @var (callable(string): string)|null the 'query' filter */
	public $filter = null;

	/** @var int index entries (posts) each COUNT(*) had to read — its post_date range */
	public int $scanned = 0;

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		$i = 0;
		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $m ) use ( &$i, $args ): string {
				$value = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	/** @return string|null */
	public function get_var( string $sql ) {
		$sql = $this->run( $sql );
		if ( $this->fails( $sql ) ) {
			return null;
		}

		if ( preg_match( "/GET_LOCK\\('([^']+)', \\d+\\)/", $sql, $m ) ) {
			if ( $this->locksUnsupported ) {
				return null;
			}
			if ( $this->allBusy || isset( $this->busyLocks[ $m[1] ] ) || isset( $this->held[ $m[1] ] ) ) {
				return '0';
			}
			$this->held[ $m[1] ] = true;
			return '1';
		}
		if ( preg_match( "/RELEASE_LOCK\\('([^']+)'\\)/", $sql, $m ) ) {
			unset( $this->held[ $m[1] ] );
			return '1';
		}
		if ( preg_match( "/SELECT ID FROM wp_posts WHERE post_parent = (\\d+) AND post_type = '[^']*' LIMIT 1/", $sql, $m ) ) {
			$child = array_search( (int) $m[1], $this->parents, true );
			return false === $child ? null : (string) $child;
		}
		if ( str_contains( $sql, 'SELECT COUNT(*)' ) ) {
			if ( ! str_contains( $sql, "post_type = 'post'" ) ) {
				return '0';
			}
			// countBetween(): evaluate the WHERE as sent, every bound.
			if ( preg_match( "/post_date >= '([^']*)' AND post_date <= '([^']*)' AND \\( post_date > '([^']*)' OR ID >= (\\d+) \\) AND \\( post_date < '([^']*)' OR ID < (\\d+) \\)/", $sql, $m ) ) {
				return (string) $this->countWhere(
					static fn( string $d ): bool => $d >= $m[1] && $d <= $m[2],
					static fn( string $d, int $id ): bool => ( $d > $m[3] || $id >= (int) $m[4] ) && ( $d < $m[5] || $id < (int) $m[6] )
				);
			}
			if ( preg_match( "/post_date < '([^']*)'/", $sql, $m ) ) {
				return (string) $this->countWhere( static fn( string $d ): bool => $d < $m[1], static fn(): bool => true );
			}
			if ( preg_match( "/post_date = '([^']*)' AND ID < (\\d+)/", $sql, $m ) ) {
				return (string) $this->countWhere( static fn( string $d ): bool => $d === $m[1], static fn( string $d, int $id ): bool => $id < (int) $m[2] );
			}
			$this->scanned += count( $this->published );
			return (string) count( $this->published );
		}
		return null;
	}

	/** @return string[] */
	public function get_col( string $sql ): array {
		$sql = $this->run( $sql );
		if ( $this->fails( $sql ) ) {
			return [];
		}
		if ( preg_match( '/SELECT DISTINCT post_id FROM wp_postmeta WHERE meta_id IN \\(([\\d,]+)\\)/', $sql, $m ) ) {
			$ids = [];
			foreach ( explode( ',', $m[1] ) as $meta_id ) {
				if ( isset( $this->metaRows[ (int) $meta_id ] ) ) {
					$ids[ $this->metaRows[ (int) $meta_id ] ] = (string) $this->metaRows[ (int) $meta_id ];
				}
			}
			return array_values( $ids );
		}
		if ( ! str_contains( $sql, "post_type = 'post'" ) || ! preg_match( '/LIMIT (\\d+), (\\d+)/', $sql, $m ) ) {
			return [];
		}
		$rows = $this->ordered();
		return array_map( 'strval', array_slice( $rows, (int) $m[1], (int) $m[2] ) );
	}

	/**
	 * A query a plugin runs from inside a hook (pre_get_posts and the like)
	 * while a sitemap read is in progress: one hook deeper than the read's own.
	 */
	public function queryFromHook( string $hook, string $sql ): void {
		$GLOBALS['wp_current_filter'][] = $hook;
		try {
			$this->get_var( $sql );
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
	}

	/** @return int[] published IDs in sitemap order (post_date, ID). */
	public function ordered(): array {
		$ids = array_keys( $this->published );
		usort(
			$ids,
			fn( int $a, int $b ): int => [ $this->published[ $a ], $a ] <=> [ $this->published[ $b ], $b ]
		);
		return $ids;
	}

	/** @return string[] queries matching a fragment */
	public function ran( string $fragment ): array {
		return array_values( array_filter( $this->queries, static fn( string $q ): bool => str_contains( $q, $fragment ) ) );
	}

	/** Pass a statement through the 'query' filter (with 'query' on the hook stack, as core does) and log it. */
	private function run( string $sql ): string {
		if ( null !== $this->filter ) {
			$GLOBALS['wp_current_filter'][] = 'query';
			try {
				$sql = ( $this->filter )( $sql );
			} finally {
				array_pop( $GLOBALS['wp_current_filter'] );
			}
		}
		$this->queries[] = $sql;
		return $sql;
	}

	/**
	 * Count posts matching $match, charging $scanned for every post the index
	 * range ($range, on post_date alone) would read.
	 */
	private function countWhere( callable $range, callable $match ): int {
		$n = 0;
		foreach ( $this->published as $id => $date ) {
			if ( $range( $date ) ) {
				++$this->scanned;
				if ( $match( $date, $id ) ) {
					++$n;
				}
			}
		}
		return $n;
	}

	private function fails( string $sql ): bool {
		$this->last_error = '';
		foreach ( $this->failOn as $fragment ) {
			if ( str_contains( $sql, $fragment ) ) {
				$this->last_error          = 'Lost connection to server during query';
				$GLOBALS['EZSQL_ERROR'][] = [ 'query' => $sql, 'error_str' => $this->last_error ];
				return true;
			}
		}
		return false;
	}
}
