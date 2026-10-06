=== Heirloom SEO ===
Contributors: orchardgrovemedia
Tags: seo, schema, sitemap, opengraph, indexnow
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.7.23
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
 
Lean, fast SEO essentials — titles, Open Graph, schema, sitemaps, IndexNow, llms.txt — with zero front-end bloat.

== Description ==

Heirloom SEO does the high-leverage SEO and social-sharing work, and nothing
else. No in-editor analysis scripts, no telemetry, no upsell nags, and **zero
CSS or JavaScript on the front end** — the only thing it adds to your pages is
the markup in the `<head>`. Built for PHP 8.1+ with no build step.

**Main features**

* Title and meta-description templates per content type (separate Posts and Pages), with per-post overrides
* Open Graph and Twitter (X) cards, with smart image fallbacks and per-network sizing (Facebook 1200×630, X 1600×900)
* Self-referencing canonical URLs on every page type, plus a filter so syndicated content can point to its source
* Robots controls (per-post and per-archive noindex, `max-image-preview`, …) and a virtual robots.txt
* A connected schema.org `@graph` — WebSite, Organization/Person (address, sameAs, optional LocalBusiness), WebPage, Article/NewsArticle, BreadcrumbList, optional WooCommerce Product, and a per-post JSON-LD escape hatch
* XML sitemap at `/sitemap.xml` (replaces core's; image entries, file-cached) plus a Google News sitemap at `/news-sitemap.xml`
* IndexNow submission to Bing, Yandex, Seznam, and Naver on publish and update
* A real `/llms.txt` for AI (curated, with hand-picked pages, served as a static file) and AI-crawler controls (block GPTBot, ClaudeBot, Google-Extended, …)
* One-click, non-destructive migration from Yoast, Rank Math, All in One SEO, and The SEO Framework — plus WP-CLI
* An SEO Health audit and JSON settings export/import — with breadcrumbs, attachment redirects, RSS attribution, and optional head cleanup

= Why Heirloom may outrun heavier SEO plugins =

The edge is speed and focus, not feature count — fast for visitors *and* editors:

* **Zero front-end CSS/JS.** Heirloom enqueues nothing on your pages, so it never touches render time or Core Web Vitals.
* **No editor bloat.** A classic PHP metabox instead of a heavy in-editor JavaScript analysis bundle.
* **Tiny database footprint.** One autoloaded option, per-post meta only when you customize a post, and no custom tables — proven on a 190,000-post site.
* **Cached, server-light output.** Sitemaps and schema are file-cached and chunked, so big sites never build giant responses on the fly.
* **You only pay for what you use.** Disabled features register no hooks; there are no telemetry calls, nags, or dashboard widgets.

Heirloom is narrower than the all-in-one suites by design — no content-analysis scoring, keyword tools, or redirect manager. It's faster because it does only the SEO essentials, which for most sites is the part that actually moves rankings and sharing.

= Support =

Questions, bug reports, and feature requests are welcome — email **ted@heirloomseo.com**, or open an issue on GitHub at https://github.com/TedSlaterDev/heirloom-seo/issues.

== Installation ==

1. Upload the `heirloom-seo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Visit **Heirloom SEO** in the admin menu to configure.

Theme authors can output breadcrumbs with `<?php heirloom_seo_breadcrumbs(); ?>`
or the `[heirloom_breadcrumbs]` shortcode.

== Frequently Asked Questions ==

= Does this work alongside Yoast or Rank Math? =

No — run only one SEO plugin at a time, or you'll get duplicate meta tags. A
notice will warn you if another is active.

= Where is the sitemap? =

At `/sitemap.xml`. It replaces the core WordPress sitemap.

= Which engines get IndexNow pings? =

Bing, Yandex, Seznam, and Naver. Google does not use IndexNow; it discovers
changes via the sitemap and Search Console.

= I imported from Yoast before version 0.7.19. Are my noindex settings right? =

Possibly not. Versions up to 0.7.18 read Yoast's per-post "show in search
results" setting backwards. As long as Yoast's data is still in your database,
`wp heirloom-seo repair yoast-noindex` lists every post whose noindex setting
disagrees with what Yoast had, and changes nothing. Add `--fix` to correct
them after a confirmation, and `--exclude=<ids>` to skip posts you've changed
on purpose since the import.

== Changelog ==

= 0.7.23 =
* Fixed: the Google News sitemap's 48-hour window was off by the site's time zone. WordPress read "48 hours ago" in the site's time zone but compared it with each post's UTC publish time, so a site on US Eastern time listed posts for about 52 hours, and a site east of UTC for less than 48 (about 39 hours in Japan). The window is now exactly 48 hours on every site. The sitemap index uses the same window when deciding whether to list the News sitemap.

= 0.7.22 =
* Improved: sitemap and llms.txt requests no longer run WordPress's main blog query first. That query counted every published post on every sitemap request, including requests answered from the cache.
* Improved: the Google News sitemap, and the index's check for recent news, now read only recent posts through an indexed date instead of every post in the News category.
* Improved: the sitemap index and the News sitemap get build slots of their own, so a burst of page builds never holds them up. A cached copy's one-day lifetime now varies by up to 10% per page, so pages built together don't all expire together.
* Changed: `wp heirloom-seo sitemap regenerate` now rebuilds every sitemap straight away, one at a time and the index last, instead of only clearing the cache. Crawlers keep getting the previous copies while it runs. It skips pages a visitor's request rebuilt meanwhile, changes nothing if a count query keeps failing, and lists any page it couldn't build or write. Run it after updating the plugin.
* Changed: clearing the sitemap cache (Tools → Flush, `wp heirloom-seo cache purge`, an update, or a change to a sitemap setting) now keeps each sitemap's previous copy for crawlers until the page is rebuilt, instead of answering them with 503s. A rebuilt page gets a new last-modified date only if its contents changed. Sitemaps written by an earlier version are always rebuilt rather than served as current.
* Fixed: a database error while a sitemap was being built could be cached as an empty or incomplete sitemap for up to a day. Now the previous copy (or a 503) is served and nothing is cached, and a retry doesn't reuse the empty answer WordPress kept from the failed query. Errors from another plugin's own queries (for example in a pre_get_posts hook) don't stop sitemaps being cached.
* Fixed: a page's last-modified date in the sitemap index now moves whenever the page's contents change, even when no post's modified date did (for example, a rebuild that picks up a change it missed), and the index is rebuilt to show it. Changes that rewrite every page (a new page size, the image or post-type settings, the permalink structure) mark every page changed. A database error can no longer wipe the stored dates.
* Fixed: changes made without saving a post now reach the sitemaps right away instead of after the one-day cache lifetime: the noindex flag or featured image set by a bulk tool, an importer or WP-CLI; deleting an image used as a featured image (only the pages of the posts that used it); permalink setting changes; renaming or deleting a category that appears in the permalink structure; changing a parent page's slug or parent, trashing or restoring it, or emptying it from the trash; deleting a user whose posts are reassigned; and, on multisite, changes made from another site of the network. `wp heirloom-seo repair yoast-noindex --fix` therefore no longer clears the whole sitemap cache.
* Changed: settings changes clear the sitemap cache only when a sitemap setting really changed, whether saved on the settings page or written by WP-CLI or code; saving the Sitemaps tab without changing anything no longer clears it. Hiding an author refreshes only the author sitemap.
* Fixed: Tools → "Regenerate IndexNow key" kept the old key.
* Fixed: a sitemap URL with an absurdly large page number could serve, and cache, a real page's contents. It now returns 404.
* Improved: bulk-editing many posts works out their sitemap pages in one pass over the index instead of one per post; terms are loaded only when the permalink structure or an include filter uses them; and building a post page no longer counts every post.
* Note: post sitemaps are paged straight from the database, so filters on WordPress's post queries (such as pre_get_posts) don't apply to them. To leave posts out, use the new `heirloom_seo_sitemap_include_post` filter; a post left out keeps its place, so the pages after it don't change. Removing or backdating an old post still moves every later post by one place, so the pages after it are rebuilt and re-advertised.

= 0.7.21 =
* Fixed sitemaps taking large sites down under crawler load. Every post save used to delete every cached sitemap file, so on a busy site the cache was almost always empty, and a crawler requesting hundreds of sitemap pages at once started hundreds of expensive builds in parallel until the server ran out of PHP workers. On a site with about 340,000 posts this produced repeated site-wide 504 errors, including in wp-admin.
* Changed: post sitemap pages now list posts oldest first, so a page keeps the same posts as the site grows. Publishing a post changes only the last page, and editing a post changes only the page it is on.
* Changed: saving, publishing, unpublishing or deleting a post now marks only the affected sitemap pages as out of date, instead of clearing the whole sitemap cache. llms.txt no longer clears the sitemap cache either.
* Changed: at most two sitemap pages are generated at the same time (filter `heirloom_seo_sitemap_build_slots`). Beyond that, a crawler gets the previous copy of the page, or a 503 with Retry-After when there is no previous copy, instead of an extra build.
* Improved: each sitemap page is now built from one indexed database query plus batched loading of its posts and featured images, instead of a sort over every post and a few queries per post.
* Improved: the sitemap index now shows a last-modified date for each page, so crawlers only need to fetch the pages that changed.
* Improved: sitemap responses now send a `Cache-Control` header (5 minutes for News, 15 for the index, 1 hour for other pages; filter `heirloom_seo_sitemap_max_age`) so a CDN can cache them. A cached copy is also rebuilt after a day even if no change was recorded (filter `heirloom_seo_sitemap_cache_ttl`), which catches changes made directly in the database.

= 0.7.20 =
* New WP-CLI command, `wp heirloom-seo repair yoast-noindex`, for sites imported from Yoast with version 0.7.18 or earlier (see 0.7.19). It compares each post's Yoast "show in search results" setting with its Heirloom noindex flag and lists the posts that disagree, without changing anything. With `--fix` (after a confirmation, or `--yes`) it adds or removes the flags to match Yoast, then clears the sitemap cache. `--only=add|remove`, `--exclude=<ids>` and `--format=table|csv|json|ids|count` narrow or export the list. It needs Yoast's post data to still be in the database, and it can't tell a flag the importer wrote from one an editor set later, so review the list first.

= 0.7.19 =
* Fixed the Yoast SEO importer reading Yoast's per-post "Allow search engines to show this post in search results?" setting backwards. Yoast stores 1 for "No" (noindex) and 2 for an explicit "Yes", and the importer had them swapped: posts an editor had explicitly set to appear in search results were imported as noindex, and posts set to noindex were imported as indexable. Posts left on Yoast's default were not affected, and the nofollow import was already correct. If you have already imported from Yoast, check posts where that setting was changed: running the import again adds the missing noindex flags, but it does not remove the wrong ones.

= 0.7.18 =
* Share-image lookups are now resolved once per request and cached, removing up to ~1 s of database time from uncached pages without a featured image on large sites. When the default share image (or a post's override) is stored as a URL, finding its media-library entry means an unindexed scan of the post-meta table; that used to run up to three times per page (og:image, twitter:image, schema) and now runs once, with the answer remembered in a transient. The cache refreshes itself whenever an image is uploaded, edited or deleted, and the share tags for media-library images are unchanged.

= 0.7.17 =
* IndexNow: the {key}.txt verification file is now written as a real file at the site root, not just served through a rewrite rule. Many servers (notably nginx) serve *.txt as static files and return 404 before WordPress ever runs, so on those hosts search engines could never verify the key and silently rejected every submission — with nothing in the logs to say why. The file is written on activation, on settings save, and on upgrade; rotating the key removes the old file; disabling IndexNow or deactivating the plugin cleans it up. A pre-existing file is never overwritten unless its contents already match the key (so a copy you created by hand is adopted rather than flagged), and an unwritable root now raises an admin notice instead of failing quietly. This mirrors how /llms.txt is already handled.

= 0.7.16 =
* Fixed a fatal TypeError on feed requests for a deleted or nonexistent author (e.g. /author/old-user/feed/): WordPress can hand back false instead of a user object there, which crashed the page context and returned a 500. Unexpected queried objects are now normalized to null, so those requests render an empty feed instead.

= 0.7.15 =
* Fixed a WordPress 6.7+ "translation loading triggered too early" notice (_load_textdomain_just_in_time, flagged by Query Monitor): the settings-page tab labels are now translated lazily at render time instead of when the plugin boots.

= 0.7.14 =
* "Hide this author from search engines" now hides the author more completely: hidden authors are removed from the article schema (the post is attributed to the site/organization instead), their byline and author-archive link are replaced with the site name across the front end and feeds, and they are dropped from the public REST API users endpoint (the classic author-enumeration vector) for anonymous requests. Editors still see real authors in the dashboard. Note: a hardcoded theme byline that doesn't use WordPress's standard author functions may need a small theme tweak.

= 0.7.13 =
* New "Heirloom SEO" section on the Edit User (author) screen with a "Hide this author from search engines" toggle — adds a noindex tag to the author's archive and removes them from the XML sitemap. Handy for keeping staff, bot, or aggregate author accounts out of search. Admins only.

= 0.7.12 =
* Breadcrumbs schema now always emits the required `item` URL for every breadcrumb — the final crumb falls back to the page's canonical URL, and any crumb without a resolvable link is dropped rather than output without `item`. Fixes Google Search Console "Missing field 'item' (in 'itemListElement')" errors that could occur when a category/term link couldn't be resolved.

= 0.7.11 =
* New leaf icon for the admin menu and the settings header (replacing the search and palm-tree icons) — a consistent brand mark now shared by the plugin, the website, and the social-share card.

= 0.7.10 =
* New ways to get help: a "Support & feedback" panel in the settings sidebar, a "Support" link on the Plugins screen, and a Support note in the readme — email the developer, or report a bug / request a feature on GitHub. Static links only; no tracking or phone-home.

= 0.7.9 =
* llms.txt polish: leads with a UTF-8 BOM so curly quotes and punctuation render correctly when a server sends the file as text/plain without a charset (nginx); titles now decode HTML entities (&#8216; → ‘); and Page/Post descriptions are built from the raw content — no "Read more", print-link, or other theme/plugin chrome — and trimmed to a concise length.

= 0.7.8 =
* llms.txt now serves at the standard /llms.txt (no trailing slash) on servers that hand .txt requests to static files (e.g. nginx) by writing a real file at the site root — the way Yoast does. It's regenerated when content changes and removed when you turn llms.txt off or delete the plugin. Falls back to the dynamic route (and shows a notice) when the site root isn't writable, already contains a non-Heirloom llms.txt, or on multisite. The file we manage is tracked so we never delete one you created.

= 0.7.7 =
* AI tab: llms.txt can now list a hand-picked set of Pages (About / Contact / Terms / Privacy / Shop named slots + additional pages) instead of all pages — posts stay automatic. Set Pages to "Only the pages I choose."
* Fixed /llms.txt 404ing on trailing-slash-permalink sites and behind CDNs: the rewrite now serves both /llms.txt and /llms.txt/ (also applied to /ai.txt and tdmrep). If a CDN cached the old 404, purge it.
* Removed the "Speculative signals" section (noai meta, TDM reservation, ai.txt) — research confirmed none are honored by any major AI crawler today; robots.txt remains the only respected control. The Signals module is gone.
* Default Posts title template is now %title% %sep% %sitename% %sep% by %author%.

= 0.7.6 =
* Titles & Meta: split the single "Posts & pages" title template into separate "Posts" and "Pages" fields. Your existing template migrates automatically into both.
* Fixed %author% not resolving on single posts/pages — it now uses the post's author (previously it only worked on author archives).

= 0.7.5 =
* Added an opt-in "Force document title" setting (Advanced tab, off by default) for themes that print their own &lt;title&gt; — e.g. a legacy wp_title() call in header.php — that the standard title filter can't override. When enabled, Heirloom buffers the page and collapses it to a single correct &lt;title&gt;.

= 0.7.4 =
* Settings → General: merged "Site represents" and "Organization type" into a single dropdown (A person / An organization / A local business). The address, phone, and price-range fields now appear only for a local business. Existing settings migrate automatically.
* Edit Post: the Heirloom SEO preview now shows the social/featured image, so it reflects the share card — not just the search snippet.
* "At a glance" box: added links to the Google News sitemap and /llms.txt (each shown when its feature is enabled).

= 0.7.3 =
* Google News: choose the news Category and/or Tag from dropdowns (stored as slugs, so settings export/import stays portable) instead of typing a term name. A post counts as news if it's in the chosen category or has the chosen tag — driving both the News sitemap and NewsArticle schema. Falls back to the named-"News" rule when neither is selected.

= 0.7.2 =
* Removed /llms-full.txt and the Markdown (.md) page versions — the heaviest, least-proven AI features. /llms.txt itself is unchanged.

= 0.7.1 =
* Fixed /llms.txt (and the other virtual endpoints) redirecting to a trailing slash — they now serve before WordPress's canonical redirect.
* Tightened the Edit Post panel: primary fields stay visible; advanced fields (canonical, schema type, robots, JSON-LD) collapse into an "Advanced" section.
* Added a PHPUnit + Brain Monkey unit-test suite (`composer test`) — runs without a WordPress install.

= 0.7.0 =
* Schema enrichment: Organization/Person identity now supports postal address, telephone, and sameAs social profiles; an optional LocalBusiness type with price range; opt-in WooCommerce Product schema (when WooCommerce is active); and a per-post "Custom JSON-LD" escape hatch merged into the page graph.

= 0.6.0 =
* SEO Health screen (no score, no gimmicks): conflicting plugin, robots.txt override, schema identity, sitemap & IndexNow reachability/validity, duplicate titles, and posts relying on auto-generated descriptions. Cheap config checks are instant; reachability + a content scan (≤5,000 posts) run on demand.
* WP-CLI: `wp heirloom-seo audit [--full]`.

= 0.5.1 =
* Settings export/import: download settings as JSON and load them on another site (Tools tab). Export omits site-specific secrets; import merges over current settings.

= 0.5.0 =
* Migration: one-click import of per-post SEO data (title, meta description, canonical, noindex/nofollow, social image, schema type) from Yoast, Rank Math, All in One SEO, and The SEO Framework. Non-destructive, idempotent, and batched for large sites — from the Tools tab or WP-CLI.
* WP-CLI: `wp heirloom-seo cache purge` · `sitemap regenerate` · `indexnow submit --post=<id>` · `import <source> [--overwrite] [--dry-run]`.

= 0.4.0 =
* AI controls tightened: per-post "Exclude from AI exports" field; one-click crawler presets (training / + AI search / all / allow all); preview links for llms.txt, llms-full.txt, and a sample .md; and a byte-size safety cap on llms-full.txt.

= 0.3.1 =
* Hardening from a code review. AI export endpoints (.md, llms.txt, llms-full.txt) now skip password-protected and noindex posts; per-post meta REST auth is object-specific (edit_post); llms-full.txt is capped (≤200 items, cached build).
* Settings saves now purge the sitemap/AI output cache; sitemap XML uses esc_xml(); the file cache is no longer .xml-suffixed.
* AI crawler controls are grouped by intent (training vs. AI-search vs. user-triggered) with visibility warnings; added the heirloom_seo/schema/graph filter.
* Media-library share images resolve back to attachment IDs so crops apply; attachment redirects allow offloaded/CDN media hosts; uninstall clears the version option; declared ext-dom/ext-mbstring; dropped non-polyfilled mb_strrpos.

= 0.3.0 =
* New AI tab: structured llms.txt + llms-full.txt, clean .md markdown versions of posts/pages, per-bot AI-crawler controls written to robots.txt, and opt-in noai / TDM-reservation / ai.txt signals.
* Moved llms.txt out of Advanced into the new AI tab.

= 0.2.3 =
* Redesigned the settings screen — branded header, tabbed cards, per-tab intros, and a quick-links sidebar.
* The Robots tab now warns when a physical robots.txt file exists (the server serves it directly, bypassing the plugin's additions).

= 0.2.2 =
* Sitemaps now ship an XSL stylesheet, so `/sitemap.xml` and the sub-sitemaps render as a readable table in the browser.
* Added `<lastmod>` to the sitemap index entries.
* Rewrite rules and the sitemap cache now refresh automatically on plugin update.

= 0.2.1 =
* Added `twitter:creator` (the author's X username, falling back to the site handle), plus an X (Twitter) username field on user profiles.
* Added `og:image:type` and `twitter:image:alt`.

= 0.2.0 =
* Per-network social images: og:image at the Facebook size (1200×630) and twitter:image at the X size (1600×900); reuses existing `facebook`/`twitter` image sizes if the theme defines them, otherwise registers its own, with a scoped crop-upscale for undersized originals.
* New `heirloom_seo/canonical` filter so other plugins can override the canonical URL (e.g. a cross-site importer crediting an original source).
* og:url and schema `@id`s now stay on this site even when the canonical points to another domain.

= 0.1.0 =
* Initial release.
