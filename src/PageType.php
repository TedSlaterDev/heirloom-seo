<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo;

defined( 'ABSPATH' ) || exit;

/**
 * The kind of page being requested, resolved once per request by Context.
 */
enum PageType {
	case Front;            // Site front page (static page or posts index).
	case Home;             // Blog posts index when a static front page is set.
	case Singular;         // Any single post, page, or custom post type.
	case Term;             // Category, tag, or custom taxonomy archive.
	case Author;           // Author archive.
	case PostTypeArchive;  // Custom post type archive.
	case Date;             // Date-based archive.
	case Search;           // Search results.
	case NotFound;         // 404.
	case Feed;             // RSS/Atom feed.
	case Other;            // Anything else (embeds, etc.).
}
