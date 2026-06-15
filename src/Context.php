<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo;

use WP_Post;
use WP_Post_Type;
use WP_Term;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Per-request page context. Resolves the page type and queried object once,
 * lazily, and is read by every head module instead of repeating is_*() checks.
 */
final class Context {

	private static ?self $instance = null;

	private bool $resolved = false;
	private PageType $type  = PageType::Other;
	private WP_Post|WP_Term|WP_User|WP_Post_Type|null $object = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** Reset memoized state (test helper). */
	public static function reset(): void {
		self::$instance = null;
	}

	private function resolve(): void {
		if ( $this->resolved ) {
			return;
		}
		$this->resolved = true;

		if ( is_404() ) {
			$this->type = PageType::NotFound;
		} elseif ( is_search() ) {
			$this->type = PageType::Search;
		} elseif ( is_front_page() ) {
			$this->type   = PageType::Front;
			$this->object = get_queried_object();
		} elseif ( is_home() ) {
			$this->type   = PageType::Home;
			$this->object = get_queried_object();
		} elseif ( is_singular() ) {
			$this->type   = PageType::Singular;
			$this->object = get_queried_object();
		} elseif ( is_author() ) {
			$this->type   = PageType::Author;
			$this->object = get_queried_object();
		} elseif ( is_post_type_archive() ) {
			$this->type   = PageType::PostTypeArchive;
			$this->object = get_queried_object();
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$this->type   = PageType::Term;
			$this->object = get_queried_object();
		} elseif ( is_date() ) {
			$this->type = PageType::Date;
		} elseif ( is_feed() ) {
			$this->type = PageType::Feed;
		} else {
			$this->type = PageType::Other;
		}
	}

	public function type(): PageType {
		$this->resolve();
		return $this->type;
	}

	public function object(): WP_Post|WP_Term|WP_User|WP_Post_Type|null {
		$this->resolve();
		return $this->object;
	}

	public function post(): ?WP_Post {
		return $this->object() instanceof WP_Post ? $this->object() : null;
	}

	public function term(): ?WP_Term {
		return $this->object() instanceof WP_Term ? $this->object() : null;
	}

	public function user(): ?WP_User {
		return $this->object() instanceof WP_User ? $this->object() : null;
	}

	/** True for any single post/page, including a static front page. */
	public function isSingular(): bool {
		return $this->post() instanceof WP_Post;
	}

	public function isStaticFront(): bool {
		return PageType::Front === $this->type() && $this->post() instanceof WP_Post;
	}

	/** Current pagination index (1-based), covering both paged archives and <!--nextpage-->. */
	public function pageNumber(): int {
		$paged = (int) get_query_var( 'paged' );
		if ( $paged < 1 ) {
			$paged = (int) get_query_var( 'page' );
		}
		return max( 1, $paged );
	}

	public function isPaged(): bool {
		return $this->pageNumber() > 1;
	}
}
