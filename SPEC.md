# Heirloom SEO — Technical Spec

> **Heirloom SEO**, by Orchard Grove Media, LLC (Ted Slater).
> The name connotes craft, quality, and time-tested essentials — the opposite of the GMO-bloat mega-plugins.
> Status: spec settled, no code yet. Target: own + client sites (not a WordPress.org / commercial release for v1).
> Last updated: 2026-06-03.

## 1. Premise

A deliberately small WordPress SEO plugin that does only the high-leverage SEO work and stays out of the way. Yoast / Rank Math / AIO have grown into marketing platforms: admin assets on every screen, in-editor analysis JS, sprawling option sets, telemetry, upsell nags. Most real ranking benefit comes from a thin slice — `<head>` markup, sitemaps, structured data — none of which needs to touch frontend performance.

### Goals
- Cover the features below correctly and nothing else.
- Frontend cost ≈ zero beyond the bytes of markup we add to `<head>`.
- Sensible defaults; minimal configuration required to be useful out of the box.
- Clean enough to hand to a client site and forget.

### Non-goals (v1)
- Content analysis / "SEO score" / readability widgets.
- Keyword research, link tools, dashboards, charts.
- Telemetry, account system, upsells.
- Multisite network admin (single-site only for v1; don't break on multisite, just don't add network features).

## 2. Performance contract (the whole point)

Hard rules, not aspirations:

1. **Zero frontend CSS/JS.** Output is `<head>` markup and an LD+JSON block only. Breadcrumbs ship unstyled markup (theme styles it).
2. **One autoloaded option row** (`heirloom_seo`). Everything reads from it via a typed accessor with merged defaults — loaded once per request.
3. **Cached sitemaps and schema.** Sitemaps rendered to files under `uploads/` and invalidated on content change (debounced). Schema graph computed once per request.
4. **Admin code loads only in admin.** Settings + metabox assets never enqueue on the frontend.
5. **No external calls on frontend requests.** IndexNow and any outbound calls happen on save/cron only.
6. **No telemetry, no nags.**

Measured with Query Monitor (added queries on a singular view target: **0** beyond the one autoloaded option) and a before/after TTFB check.

## 3. Conventions

| Thing | Value |
|---|---|
| Plugin name (display) | **Heirloom SEO** |
| Author (header field) | Orchard Grove Media, LLC |
| Text domain / slug | `heirloom-seo` |
| PHP namespace | `OrchardGrove\HeirloomSeo` |
| Function prefix | `heirloom_seo_` |
| Filter/action prefix | `heirloom_seo/` (e.g. `heirloom_seo/schema/graph`) |
| Single option key | `heirloom_seo` |
| Post-meta prefix | `_heirloom_seo_` |
| Cache dir | `wp-content/uploads/heirloom-seo-cache/` |
| **Min PHP / WP** | **PHP 8.1+ / WP 6.0+** |

**No `package.json`, no Node build step.** The per-post UI is a classic PHP metabox, so the plugin ships as pure PHP. PHP 8.1 lets us use **enums** (`PageType`, `SchemaType`), `readonly` DTOs, and typed properties throughout `Context` and the schema pieces. `composer.json` exists for **dev tooling only** (PHPCS + WordPress Coding Standards) — no runtime dependencies.

## 4. Architecture

### 4.1 Layout
```
heirloom-seo/
  heirloom-seo.php           # header, constants, guard, bootstrap
  uninstall.php              # removes option + meta IFF the opt-in setting is on
  readme.txt                 # WP-style readme (good hygiene even if private)
  composer.json              # DEV ONLY: phpcs + WPCS, no runtime deps
  src/
    Plugin.php               # container; registers + boots enabled modules
    Autoloader.php           # tiny PSR-4 SPL autoloader (no Composer at runtime)
    Context.php              # per-request page context (memoized); PageType enum
    Settings/
      Options.php            # typed get() over the single option + defaults
      SettingsPage.php       # tabbed admin settings
    Support/
      Url.php  Image.php  Str.php  FileCache.php
    Modules/
      ModuleInterface.php
      Meta/        Title.php  Description.php  OpenGraph.php  TwitterCards.php  Verification.php  HeadRenderer.php
      Canonical/   Canonical.php
      Robots/      Robots.php
      Schema/      Graph.php  SchemaType.php  Pieces/{WebSite,WebPage,Organization,Person,Article,BreadcrumbList,ImageObject}.php
      Sitemaps/    Module.php  Router.php  FileCache.php  Providers/{PostType,Taxonomy,Author,News}.php  Renderer.php
      IndexNow/    IndexNow.php  KeyFile.php
      Redirects/   AttachmentRedirect.php
      Feed/        RssAttribution.php
      Breadcrumbs/ Breadcrumbs.php  Block.php
      LlmsTxt/     LlmsTxt.php
      Cleanup/     Cleanup.php
    Admin/
      Metabox.php            # classic per-post metabox (fields + nonce + save handler)
  assets/
    admin/                   # settings + metabox css/js (ADMIN ONLY)
  languages/heirloom-seo.pot
```

### 4.2 Bootstrap & module pattern
- `heirloom-seo.php` defines constants, registers the autoloader, instantiates `Plugin` on `plugins_loaded`.
- Each feature implements `ModuleInterface::register()` and hooks itself. `Plugin` boots only modules enabled in `Options`; a disabled module registers nothing (zero hooks, zero cost).
- Activation: register rewrite rules + `flush_rewrite_rules()` once; create the cache dir (with an `index.html` + hardening). Deactivation: flush again. Never flush on normal requests.

### 4.3 `Context` — compute once, read everywhere
The core idea that keeps things cheap. On first access it resolves:
- `PageType` enum (`Front`, `Singular`, `Term`, `Author`, `Search`, `Date`, `NotFound`, `Feed`),
- the queried object (`WP_Post` / `WP_Term` / `WP_User`),
- the canonical URL, indexability, and the resolved title/description.

Every head module reads from `Context` instead of repeating `is_*()` checks. Memoized for the request.

### 4.4 Head output ordering
One renderer at `wp_head` priority `1` emits, in order: meta description, robots (via the `wp_robots` filter), canonical, OG, Twitter, verification — then the schema `<script>` near priority `99`. The `<title>` is handled via `pre_get_document_title` (not in this block). Core's `rel_canonical` is removed so we don't double-output.

### 4.5 Data model
- **Settings:** single autoloaded array option `heirloom_seo`, namespaced (`titles`, `social`, `schema`, `sitemaps`, `robots`, `indexnow`, `advanced`). `Options::get('titles.post', $default)` with deep-merged defaults.
- **Per-post meta** (individual keys, only what's needed): `_heirloom_seo_title`, `_heirloom_seo_desc`, `_heirloom_seo_canonical`, `_heirloom_seo_noindex`, `_heirloom_seo_nofollow`, `_heirloom_seo_og_image`, `_heirloom_seo_schema_type`. Saved by the **metabox** on `save_post` (nonce + `current_user_can('edit_post')` + sanitize). Also `register_post_meta` with sanitize callbacks so values are typed and readable over REST.
- **Cache:** sitemap XML rendered to files under `uploads/heirloom-seo-cache/`; schema fragments memoized per request. `FileCache` handles atomic write + read + purge.

## 5. Feature modules

### 5.1 Titles & meta description
- `add_theme_support('title-tag')`; take over `<title>` via `pre_get_document_title` (return computed title to short-circuit).
- **Templates** (`titles.*`) with variables: `%title%`, `%sitename%`, `%tagline%`, `%sep%` (`general.separator`), `%term_title%`, `%author%`, `%archive_title%`, `%search%`, `%category%` (the post's first category), `%page%` (the page number, from page 2 on), `%pagination%`, `%excerpt%`. One template each for the front page, the posts page, posts (every post type but pages), pages, term archives (every taxonomy), author archives, archives (date and post type archives), search and 404. Empty variables collapse, and stray separators at either end are trimmed.
- **`%pagination%` (0.7.29):** `{sep} Page N of M` (translatable) when N ≥ 2 and M ≥ 2, else nothing: what Yoast's `%%page%%` prints, so Yoast formats carry over unchanged. N is `Context::pageNumber()` (`paged`, else `page`); M is `Context::pageCount()`: on a single post or page (including a static front page) its `<!--nextpage-->` breaks + 1, otherwise (archives, the posts page, a front page of latest posts) the main query's `max_num_pages`; 0 when there is no query, so nothing prints.
- **Carried over from Yoast (0.7.29):** Heirloom's defaults differ from Yoast's (posts add "by %author%", term archives drop "Archives", `–` against Yoast's `-`, no page numbers), so a switch changed every title. `Yoast::carryOverSettings()` replaces `general.separator` and each `titles.*` value that is unset or still equal to its default (a tab save stores every field, so a stored default records no choice; an edited value stays), when Yoast's differs. Sources (`wpseo_titles`): `separator` (Yoast's `sc-*` choice → the character Yoast printed, its own list's entities decoded; its `sc-lt` prints `>` and `sc-gt` `<`); `title-post`, `title-page`, `title-tax-category` (term), `title-author-wpseo`, `title-archive-wpseo` (archive), `title-search-wpseo`, `title-404-wpseo`; the front page from `title-home-wpseo` with latest posts on the front, else front and posts page from `title-page` (Yoast titles a static front page and the posts page as pages; a page's own Yoast title comes over with the post import and wins here too). Variables map through `Yoast::TITLE_VARS` (`%%page%%` → `%pagination%`, `%%pagenumber%%` → `%page%`, `%%name%%` → `%author%`, `%%searchphrase%%` → `%search%`, `%%sitedesc%%` → `%tagline%`, `%%primary_category%%` → `%category%`, …); in the archive template only, `%%date%%` → `%archive_title%` (WordPress's "Month: November 2026" against Yoast's "November 2026"). A template still holding a `%%…%%` after that, or stored empty, is left out; `carryOverReport()` warns with the template's Titles-tab label and the variables, unless Heirloom's value was edited. It also warns when `title-tax-post_tag` differs from the category template it carried over. Not carried: other post types' and taxonomies' templates (Heirloom has one each), post type archive templates. Same runs as the RSS setting (§5.10). Posts' own Yoast titles and descriptions use the same map (plus `%%tag%%` → `%category%`).
- Per-post overrides (title + description) from meta.
- Description: override → excerpt → trimmed content, length-guarded.

### 5.2 Open Graph & Twitter
- OG: `og:type`, `og:title`, `og:description`, `og:url` (canonical), `og:site_name`, `og:locale`, `og:image` (+`:width`/`:height`/`:alt`); for posts `article:published_time`, `article:modified_time`, `article:author`, `article:section`, `article:tag`.
- Twitter: `summary_large_image`, title/description/image, optional `twitter:site`/`creator`.
- Image resolution: per-post OG image → featured image → first content image → site default. Forced-absolute URLs.
- **Share image sizes (Media module):** og:image at the Facebook size (1200×630), twitter:image at the X size (1600×900); prefers the theme's existing `facebook`/`twitter` sizes if defined, else registers its own; optional scoped crop-upscale for undersized originals.

### 5.3 Verification
- One field each for Google Search Console, Bing, Pinterest and Baidu (`verification.*`, Social tab). Output `<meta name=… content=…>` only when set, on the site root only.
- **Carried over from Yoast (0.7.28):** Yoast keeps the codes in `wpseo` (`googleverify`, `msverify`, `baiduverify`; also `yandexverify`, `ahrefsverify`) and `wpseo_social` (`pinterestverify`), as the bare code (its validation strips a pasted meta tag to its content). A switch used to drop them, and a search console verified by that meta tag re-checks it periodically and eventually drops the site's verified ownership. `Yoast::carryOverSettings()` copies each code that is `[A-Za-z0-9_-]+` once trimmed (`Yoast::verificationCodes()`; anything else is skipped rather than printed in a meta tag) into a `verification.*` field that is unset or empty; a code entered in Heirloom stays. Unlike `feed.rss_attribution`, a stored empty field is filled: saving the Social tab stores every field, so an empty one records no choice. Runs at the same points as the RSS setting (§5.10). Heirloom has no Yandex or Ahrefs field: `Yoast::carryOverReport()` warns when Yoast has either (a WP-CLI warning; a line on the import screen). `carryOverSettings()` returns the dotted paths it set (would set, on a dry run); `carryOverReport()` turns them into the sentences WP-CLI logs and the first import batch returns as `notes`, which `migrate.js` lists under the progress bar.

### 5.4 Canonical
- `remove_action('wp_head','rel_canonical')`; emit our own on every page type via `Context` (singular, archives, paginated, front). Per-post override. `og:url` always matches canonical.

### 5.5 Robots
- Hook the `wp_robots` filter (don't print our own tag). Per-post noindex/nofollow; site-level toggles for author / date / tag / search / paginated archives; `max-snippet`, `max-image-preview:large`, `max-video-preview` controls.
- Virtual **robots.txt** editor via the `robots_txt` filter (append rules; inject the sitemap line → `/sitemap.xml`, except while search engines are discouraged, §5.14).
- Optional `X-Robots-Tag` HTTP header path for non-HTML.

### 5.6 Schema (`@graph`)
- A single `<script type="application/ld+json">` with `@context` + a connected `@graph` — **not** isolated blocks.
- Pieces (each a class returning an array with a stable `@id`):
  - `WebSite` (`home#website`) + `SearchAction` (sitelinks search box),
  - `Organization` **or** `Person` (`home#organization`) — site identity / knowledge graph,
  - `WebPage` (`{url}#webpage`) → `isPartOf` WebSite, `publisher` → identity,
  - `Article` / **`NewsArticle`** (`{url}#article`) → `isPartOf` WebPage, `author` → Person, `publisher` → Organization,
  - `Person` (author), `ImageObject`, `BreadcrumbList` (`{url}#breadcrumb`).
- **News detection (shared rule):** a post is treated as news — and gets `NewsArticle` instead of `Article` — when it is in the **category "News"** or has the **tag "News"**. The matched term name is a setting (default `News`); resolved across both the `category` and `post_tag` taxonomies and unioned. The same rule drives the News sitemap (§5.7).
- FAQ/HowTo intentionally **omitted** (Google curtailed those rich results in 2023). Filterable so a site can add pieces: `heirloom_seo/schema/graph`.

### 5.7 Sitemaps
- **Replace core sitemaps** (`add_filter('wp_sitemaps_enabled','__return_false')`); serve our own at **`/sitemap.xml`** with full control + caching.
- Routes (rewrite rules + query var): `sitemap.xml` (index), `sitemap-{provider}-{n}.xml`, `news-sitemap.xml`; XSL stylesheet for a human-readable view.
- Providers: post types, taxonomies, authors (optional), **News**. Each entry carries `lastmod`; image entries via the image-sitemap namespace.
- **Google News sitemap:** posts from the last 48h (by `post_date_gmt`, an exact UTC cutoff passed as date parts; 0.7.23 — a phrase like "48 hours ago" is read in the site time zone, which skewed the window by the UTC offset) that match the News rule (§5.6 — category or tag "News"), ≤1000 URLs, `news:publication` (name + language), `news:publication_date`, `news:title`.
- **Caching:** render to files under `uploads/heirloom-seo-cache/`, serve from cache, invalidate (debounced) on `save_post` / `deleted_post` / term changes. No DB query storm per hit. (Files survive object-cache flushes and are cheap to serve.)
- No sitemap pinging (Google removed the endpoint in 2023); discovery via robots.txt + IndexNow + GSC.
- **Load safety (0.7.21):** post pages are ordered oldest-first by `(post_date, ID)` from one query covered by core's `type_status_date` index, with posts/meta/terms and featured images primed in batches. Content changes mark only the affected cache files stale (`wp_after_insert_post` / `deleted_post` / term hooks, rank → page, applied once at shutdown; >20 edits in a request collapse to one shift from the earliest). Builds pass through `BuildGate` (MySQL `GET_LOCK` slots, default 2, fail-open); a full gate serves the stale copy or a 503 with `Retry-After`. Per-page lastmods live in the `heirloom_seo_sitemap_lastmod` option. Responses send `Cache-Control` (news 300 s, index 900 s, pages 3600 s); cached files also expire after a TTL (news 15 min, others 1 day).
- **Load safety, continued (0.7.22, from the 0.7.21 review and its post-fix round):**
  - Sitemap and llms.txt requests short-circuit WordPress's main query (`posts_pre_query` returns `[]`, and sets `ignore_sticky_posts`, for the main query when our query var holds a value we serve), so no hit runs the home query's `SQL_CALC_FOUND_ROWS` or the sticky-post fetch.
  - The index and the News sitemap each have their own `BuildGate` slot (peak concurrent builds = slots + 2). TTLs vary by up to 10% per key. Page numbers whose offset would pass `PHP_INT_MAX` are 404s before any query.
  - Every sitemap ends with `Sitemaps::FORMAT_MARK`; an unmarked copy (written by older code, e.g. a build still running during the update) is never served as fresh, only as the busy-gate fallback.
  - Build reads whose empty answer would be believed (the ID list, the post/meta and featured-image primes, the counts, `get_terms`/`get_users`/`get_term_by`, the news `WP_Query`s, the index's lastmod read) go through `checked()`: a `query` filter records the statements the read issues at its own hook depth, and only an `$EZSQL_ERROR` entry for one of those fails the build — so a plugin's broken query inside `pre_get_posts`, `the_posts`, `post_link`… doesn't. A failed read's undo clears what core cached from it (empty `post_meta`/`{tax}_relationships`, query caches via `*_last_changed`); the regenerate command also flushes the runtime cache before each retry. A failed build is neither cached nor allowed to delete the previous copy; the stale copy or a 503 is served. A failed count isn't memoized.
  - `FileCache::purge()` keeps sitemap copies (index, news, sub_*) as `.stale`; `put()` leaves `.stale` in place (deleting it could delete a concurrently staled new copy) and returns whether it wrote; `lookup()` reads without warnings and falls back to `.stale`.
  - Invalidation also covers: `added/updated/deleted_post_meta` for `_heirloom_seo_noindex` and `_thumbnail_id` (only with images on; treated as in-place edits); a delete across posts (`post_id` 0, e.g. `wp_delete_attachment()`) resolved in `delete_post_meta` — while the rows exist — by meta ID (≤ 20; more counts as every page); `update_option_{permalink_structure,category_base,tag_base}` (every key stale plus a lastmod floor); `edit_terms` → `edited_term`/`delete_term` for a taxonomy whose `%tag%` is in the permalink structure, when the slug or parent changed; a slug or parent change of a post with children, whatever its status (trash, untrash), and `before_delete_post` of one (every page of that type); `deleted_user` / `remove_user_from_blog` (author pages, plus post pages under `%author%` when reassigned).
  - Multisite: while switched to *another* site (`ms_is_switched()` and the current blog isn't the one the request started on), a relevant change marks that site's sitemaps stale at once, once per request; same-site switches count as home.
  - Several in-place edits of one type are ranked in one pass (a rank, then gap counts), skipping posts the type's shift already covers; past 20 they fold into a single shift as they arrive. A count that fails during the flush places nothing: the type's cached pages go stale, with no stamps and no pruning.
  - Lastmod: a rebuilt page whose bytes differ from the previous copy, or that has no lastmod yet, is stamped now; otherwise the newest `post_modified` on it. Merges take turns on a `GET_LOCK(…_lastmod, 2)`, re-read the option past the object cache and core's `notoptions`, write nothing if that read fails, and keep the later stamp. A raised stamp marks the index stale; the flush merges before it marks pages stale; an index build that sees the map change while it rendered marks itself stale. `Sitemaps::markAllPagesChanged()` sets the `_all` floor for post pages (page size, images, post types, permalinks).
  - `wp heirloom-seo sitemap regenerate` warms every sitemap sequentially through the gate, the index last (the post-update step); it refuses an unwritable cache directory and a failing count. Settings changes are handled by `SettingsPage::onSettingsWritten()` on `add_option_`/`update_option_heirloom_seo` (wired in `Plugin::boot`, so WP-CLI and code writes count), comparing values with defaults filled in.
  - Post pages bypass `WP_Query` filters by design (raw SQL, consistent with the rank counts); `heirloom_seo_sitemap_include_post` drops a post at render while keeping its slot (terms are primed when it's hooked). Pages are offsets, so removing or re-dating an old post re-stales and re-stamps every later page — accepted trade-off. A page edited and then rebuilt is stamped twice (flush, then the content compare) — accepted.
  - The file cache is per server: without shared uploads, other web servers pick up changes at the TTL.
- **Host page caches and News exclusions (0.7.24):**
  - Every sitemap response also sends `X-Accel-Expires` equal to its `Cache-Control` max-age (`Sitemaps::responseHeaders()`; 404 → 60, 503 → 0). nginx page caches obey `X-Accel-Expires` over `Cache-Control`, and some managed hosts' platforms set 10800 on non-singular/search/archive URLs at `wp` priority 99 — before Heirloom answers at `template_redirect` — so its value is replaced, not merely contradicted.
  - `sitemaps.news_exclude_categories` / `news_exclude_tags` / `news_exclude_authors` (slug lists; settings type `slugs` turns comma/newline-separated names into `sanitize_title` slugs). `Sitemaps::newsQueryArgs()` wraps the News rule as `[ 'relation' => 'AND', rule, NOT IN categories (include_children), NOT IN tags ]` plus `author__not_in` (nicenames resolved with `get_user_by('slug')` inside `checked()`); used by both the News sitemap and `hasRecentNews()`. Unknown slugs are ignored and flagged on the settings field.
  - `SettingsPage::NEWS_SETTINGS` (the News rule + exclusions) refresh only `news` + `index` (`markStale`); other `sitemaps.*` changes still purge.
- **Yoast SEO's sitemap URLs (0.7.27):** two more `top` rules, added after Heirloom's own (WordPress tries rules in the order added, so `news-sitemap.xml`, which also fits the second, stays the News sitemap): `^sitemap_index\.xml$` and Yoast's own pattern `^([^/]+?)-sitemap(\d*)\.xml$`, both to `heirloom_sitemap=legacy` (type and page in `heirloom_sm_type` / `heirloom_sm_page`; no number = page 1). `Sitemaps::redirect()` answers 301 to `sitemap-{provider}-{n}.xml` when the type resolves the way Yoast resolves it — post type (`pt_`), then taxonomy (`tax_`), then `author` — to a provider listed here and `n` ≤ its page count (`pagesFor()`: one count query); otherwise to `/sitemap.xml` (sitemap_index.xml, attachments, a type not listed here, a page past the end, a Yoast add-on's `video-sitemap.xml` …). Rank Math uses the same names. Page contents don't match Yoast's (Yoast orders posts by modified date), only type and page number. The 301 carries `Cache-Control`/`X-Accel-Expires` = the index's max-age (900 s; 60 s when a count failed and sent it to the index) and `X-Redirect-By: Heirloom SEO`: a 301 without an expiry is kept by browsers for good, and Yoast, if a site switches back, redirects `/sitemap.xml` to `/sitemap_index.xml`. Gated like the sitemaps: the routes belong to the module (not registered while `sitemaps.enabled` is off; a settings save flushes rewrites), and while discouraged the answer is the sitemap 404. `legacy` is in `SERVED`, so the main query is skipped. New routes take effect through the version-change flush. While Yoast is still active its own sitemaps keep answering: Yoast prepends its rules to the stored `rewrite_rules` whenever they are read (`option_rewrite_rules`).
- **"Every post" News mode (0.7.25):** `schema.news_scope` = `term` (default: the News category/tag/fallback name) | `all`. With `all`, `newsQueryArgs()` drops the News rule (only the date window, plus `AND`ed `NOT IN` exclusions and `author__not_in`), and `Schema::isNews()` is true for every post (the per-post `_heirloom_seo_schema_type` override still wins). The exclusions stay sitemap-only, so an excluded promoted post keeps NewsArticle schema unless an editor sets its type. The settings tab hides the category/tag/fallback rows while `all` is saved (their stored values are kept). `news_scope` is in `NEWS_SETTINGS`.

### 5.8 IndexNow
- On `transition_post_status` (to/from `publish`) and meaningful updates of public posts, enqueue the URL; flush on shutdown/cron (debounced + batched) as a JSON POST to `https://api.indexnow.org/indexnow` (`{host, key, keyLocation, urlList}`).
- Auto-generate the API key, serve the `{key}.txt` key file, expose a "test" button. Bing/Yandex/Seznam/Naver — **not** Google.

### 5.9 Attachment redirects
- On `template_redirect`, if `is_attachment()`, 301 to the parent post (default) or the file URL (configurable). Kills thin attachment pages.
- **No redirect *manager*:** the plugin does not track changed post slugs/URLs, creates no old→new redirects, and has no 404 monitor. Attachment redirects and the 301s for Yoast SEO's sitemap URLs (§5.7) are the only redirect behavior.

### 5.10 RSS attribution
- Append a configurable attribution line ("The post X appeared first on Site") with the canonical link via `the_content_feed` / `the_excerpt_rss`.
- **Carried over from Yoast (0.7.27):** Yoast adds its RSS "before"/"after" text to feed items; a site that cleared both had no line, and `feed.rss_attribution` (default on) added one to every item and to newsletters built from the feed. `Migration\Yoast::carryOverSettings()` sets `feed.rss_attribution` = false when `Yoast::rssTextCleared()`: both texts empty or whitespace, read from `wpseo_titles` (`rssbefore` / `rssafter`, Yoast 7.0+) when it holds either key, else from `wpseo_rss` (where Yoast kept them before its 7.0 upgrade moved them and deleted that option). A text Yoast never stored has Yoast's default (no "before"; an "after" line much like ours), so it counts as set. Otherwise nothing is written and Heirloom's default stays. It never touches a `feed.rss_attribution` already stored (saved on the Advanced tab, or by an earlier carry-over). Runs on the first activation (`Options::seedDefaults()` created the option; reactivating an install leaves it alone), with the first batch of an admin Yoast import, and in `wp heirloom-seo import yoast` (which logs it; `--dry-run` reports only). Not on upgrade: existing installs keep their feed.

### 5.11 Breadcrumbs
- `Breadcrumbs::trail(Context)` → array; surfaced as a template tag `heirloom_seo_breadcrumbs()`, a shortcode, and a block. The **same** trail feeds the `BreadcrumbList` schema. Unstyled, ARIA-correct markup.

### 5.12 llms.txt (optional, on by request)
- Serve `/llms.txt` from a template + settings — an on-brand nod to AI crawlers; complements the Auto Author work.

### 5.13 Head cleanup (toggles)
- Optionally remove `wp_generator`, RSD, wlwmanifest, shortlink, adjacent-posts rel links. All opt-in.

### 5.14 Search engine visibility (0.7.26)
- Settings → Reading → "Discourage search engines from indexing this site" (`blog_public` = `'0'`). Core then prints `noindex, nofollow` on every page (Heirloom's `wp_robots` filter already keeps that) and turns its own sitemaps off (`wp_sitemaps_enabled` defaults to `blog_public`); since WP 5.3 its robots.txt is unchanged in that state, so crawlers can read the noindex. Heirloom follows core: nothing that points crawlers at the site's URLs while the site asks not to be indexed. Typical case: a staging or preview copy.
- `Support\Visibility::discouraged()` reads the option as core does (`! get_option( 'blog_public' )`). While true:
  - sitemaps: `Sitemaps::respond()` answers 404 for the index, every provider page and the News sitemap, and `Sitemaps::redirect()` gives Yoast SEO's sitemap URLs the same 404 (0.7.27) (the XSL stylesheet, which lists no URLs, is still served). The invalidation hooks keep running, so the cache is current the moment the setting is unticked;
  - `/llms.txt`: the virtual route answers 404, and `LlmsTxt::sync()` treats the site like llms.txt off — the static file Heirloom owns is removed (a foreign file is left alone), and no rebuild is queued;
  - IndexNow: `flush()` and `submitNow()` send nothing, `maybeServeKey()` answers 404, and `IndexNow::sync()` removes the owned key file — and a key file at this site's own path that holds exactly the key, though none is on record (the copy a files-and-database clone brought along; the same file `blockedReason()` would adopt);
  - both 404s carry `Visibility::NOT_FOUND_HEADERS`, the sitemap 404's headers (`Cache-Control: public, max-age=60`, `X-Accel-Expires: 60`), so a page cache or CDN keeps them a minute at most once the setting is unticked;
  - robots.txt: no `Sitemap:` line; the site's own rules (`robots.robots_txt`, AI crawler blocks) are still appended;
  - SEO Health: "Search engine visibility" leads the config checks — `error` when `wp_get_environment_type()` is `production` (its default), `info` otherwise; the XML sitemap and IndexNow checks say nothing is served or sent; the HTTP checks are skipped (those URLs 404 on purpose). The settings sidebar, Tools and the AI preview show a note instead of links that would 404. `wp heirloom-seo indexnow submit` refuses; `sitemap regenerate` warns and builds anyway.
- Ticking or unticking fires `add_option_blog_public` / `update_option_blog_public` → `Plugin::onVisibilityChanged()` (wired in `boot()` on every request type, since `wp option update` and code can write it): `IndexNow::sync()` and `LlmsTxt::sync()` remove or rewrite the two static files at once (before `init`, the llms.txt rebuild is deferred to the usual admin_init / wp-cron pass). An install upgrading while already discouraged loses both files on its first back-end request: the version-change block runs `IndexNow::sync()` and `LlmsTxt::markDirty()`, processed on `admin_init`.
- The setting can also change with no write (a database copied to a staging site, a direct SQL update, a `pre_option_blog_public` filter). `admin_init` → `Plugin::reconcileVisibility()`: while discouraged with either static file still on record (`ownsStaticFile()`), it runs `onVisibilityChanged()`. Public sites pay one autoloaded option read on back-end requests; nothing on the front end. The reverse (made public with no write) waits for the next llms.txt rebuild or settings save; until then the virtual routes answer wherever the web server passes `.txt` requests to WordPress.
- Copied databases: the key file's recorded path (`heirloom_seo_indexnow_static_path`) is unlinked only when it is where this site writes that key (`staticPath( basename )`); otherwise the record is dropped and that file left alone. Without the check, a staging copy on the same server deleted the source site's key file on a settings save, deactivation, or ticking the box there. (A path filter changed since the write is treated the same way.) `uninstall.php` applies the same check to both recorded paths. `blog_public` values other than `0`/`1` (some access plugins store `2`) count as public, as in core.

## 6. Admin UI
- **Settings:** one top-level menu "Heirloom SEO" with tabs — General, Titles & Meta, Social, Schema, Sitemaps, Robots, Advanced, Tools (IndexNow test, flush cache, key-file status, "delete data on uninstall" toggle). Settings API or a thin custom renderer; assets admin-only.
- **Per-post:** a classic PHP **metabox** (`add_meta_box`) on the editor screen — fields for title, description (with char counts + a small SERP preview rendered server-side), canonical, noindex/nofollow, OG image (media frame), schema type. Saved on `save_post` with a nonce + capability check. No block-editor sidebar, no React, **no JS build pipeline**.

## 7. Security & i18n
- Capability checks (`manage_options` for settings, `edit_post` for per-post), nonces on all forms, sanitize on input, escape on output (`esc_attr`/`esc_url`, `wp_json_encode` for LD+JSON).
- All strings translatable under `heirloom-seo`; ship a `.pot`.

## 8. Compatibility
- Detect an active Yoast / Rank Math / AIO and show a one-time admin notice (don't auto-disable — warn about double output). Graceful when a theme already emits OG/canonical: prefer ours, deduplicate where feasible.

## 9. Testing
- Unit: PHPUnit + WP test suite (or Brain Monkey) for `Context`, title/description resolution, schema graph shape, the News rule, sitemap providers.
- Validation: Google Rich Results Test + Schema Markup Validator for LD+JSON; XML-schema validate sitemaps incl. News.
- Performance: Query Monitor (assert added queries on a singular view), before/after TTFB.

## 10. Roadmap

- **v0.1 (MVP — head output):** Autoloader, `Context` (+ PageType enum), `Options`, titles/descriptions, OG/Twitter, canonical, robots, schema graph (WebSite + WebPage + Organization + Article/NewsArticle), the per-post **metabox**. Highest value, lowest risk, zero frontend cost.
- **v0.2:** Sitemaps at `/sitemap.xml` incl. News + file caching, IndexNow, attachment redirects, RSS attribution, breadcrumbs (+ BreadcrumbList).
- **v0.3:** Verification tags, head cleanup, llms.txt, Tools page, compatibility notices, Person/Organization knowledge-graph polish, `.pot`.
- **Phase 2 (later, opt-in):** Image-sitemap refinements, WP-CLI commands, multisite. (No redirect *manager* / 404 monitor — attachment redirects are included, but old→new URL redirects on slug changes are out of scope by decision.)

## 11. Decisions (resolved 2026-06-03)

1. **Name / attribution** — display name **Heirloom SEO**; LLC ("Orchard Grove Media, LLC") goes in the `Author` header field, not the name.
2. **Min versions** — **PHP 8.1+**, WP 6.0+. Use enums / readonly / typed properties.
3. **Sitemaps** — **replace** core; serve at **`/sitemap.xml`**.
4. **News detection** — post is "news" if in **category "News"** or **tag "News"** (term name configurable, default `News`); drives both `NewsArticle` schema and the News sitemap.
5. **Per-post UI** — classic **PHP metabox**; no block sidebar, no Node build step.
6. **Cache backend** — **files** under `wp-content/uploads/heirloom-seo-cache/`.
7. **Uninstall** — **opt-in** "Delete all data on uninstall" setting (default off); `uninstall.php` honors it.
8. **Redirects** — attachment redirects are **included** (§5.9). A redirect *manager* (old→new URL on slug change) and 404 monitor are **excluded** by decision. Clarified 2026-06-03.
