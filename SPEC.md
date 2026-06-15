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
- Per-post-type **templates** with variables: `%title%`, `%sitename%`, `%sep%`, `%category%`, `%date%`, `%page%`, `%author%`, `%excerpt%`. Separate templates for singular, archives, author, search, 404, front.
- Per-post overrides (title + description) from meta.
- Description: override → excerpt → trimmed content, length-guarded.

### 5.2 Open Graph & Twitter
- OG: `og:type`, `og:title`, `og:description`, `og:url` (canonical), `og:site_name`, `og:locale`, `og:image` (+`:width`/`:height`/`:alt`); for posts `article:published_time`, `article:modified_time`, `article:author`, `article:section`, `article:tag`.
- Twitter: `summary_large_image`, title/description/image, optional `twitter:site`/`creator`.
- Image resolution: per-post OG image → featured image → first content image → site default. Forced-absolute URLs.
- **Share image sizes (Media module):** og:image at the Facebook size (1200×630), twitter:image at the X size (1600×900); prefers the theme's existing `facebook`/`twitter` sizes if defined, else registers its own; optional scoped crop-upscale for undersized originals.

### 5.3 Verification
- One field each for Google Search Console, Bing, Pinterest (+ generic extra). Output `<meta name=… content=…>` only when set.

### 5.4 Canonical
- `remove_action('wp_head','rel_canonical')`; emit our own on every page type via `Context` (singular, archives, paginated, front). Per-post override. `og:url` always matches canonical.

### 5.5 Robots
- Hook the `wp_robots` filter (don't print our own tag). Per-post noindex/nofollow; site-level toggles for author / date / tag / search / paginated archives; `max-snippet`, `max-image-preview:large`, `max-video-preview` controls.
- Virtual **robots.txt** editor via the `robots_txt` filter (append rules; always inject the sitemap line → `/sitemap.xml`).
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
- **Google News sitemap:** posts from the last 48h that match the News rule (§5.6 — category or tag "News"), ≤1000 URLs, `news:publication` (name + language), `news:publication_date`, `news:title`.
- **Caching:** render to files under `uploads/heirloom-seo-cache/`, serve from cache, invalidate (debounced) on `save_post` / `deleted_post` / term changes. No DB query storm per hit. (Files survive object-cache flushes and are cheap to serve.)
- No sitemap pinging (Google removed the endpoint in 2023); discovery via robots.txt + IndexNow + GSC.

### 5.8 IndexNow
- On `transition_post_status` (to/from `publish`) and meaningful updates of public posts, enqueue the URL; flush on shutdown/cron (debounced + batched) as a JSON POST to `https://api.indexnow.org/indexnow` (`{host, key, keyLocation, urlList}`).
- Auto-generate the API key, serve the `{key}.txt` key file, expose a "test" button. Bing/Yandex/Seznam/Naver — **not** Google.

### 5.9 Attachment redirects
- On `template_redirect`, if `is_attachment()`, 301 to the parent post (default) or the file URL (configurable). Kills thin attachment pages.
- **No redirect *manager*:** the plugin does not track changed post slugs/URLs, creates no old→new redirects, and has no 404 monitor. Attachment redirects are the only redirect behavior.

### 5.10 RSS attribution
- Append a configurable attribution line ("The post X appeared first on Site") with the canonical link via `the_content_feed` / `the_excerpt_rss`.

### 5.11 Breadcrumbs
- `Breadcrumbs::trail(Context)` → array; surfaced as a template tag `heirloom_seo_breadcrumbs()`, a shortcode, and a block. The **same** trail feeds the `BreadcrumbList` schema. Unstyled, ARIA-correct markup.

### 5.12 llms.txt (optional, on by request)
- Serve `/llms.txt` from a template + settings — an on-brand nod to AI crawlers; complements the Auto Author work.

### 5.13 Head cleanup (toggles)
- Optionally remove `wp_generator`, RSD, wlwmanifest, shortlink, adjacent-posts rel links. All opt-in.

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
