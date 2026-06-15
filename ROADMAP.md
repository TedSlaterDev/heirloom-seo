# Heirloom SEO — Feature Roadmap

> Companion to [SPEC.md](SPEC.md). Plans post-0.3.1 features in priority order.
> Planning doc — lives in the repo, not shipped in the plugin.
> Last updated: 2026-06-04.

## Guardrails — every feature must pass both, or it doesn't ship

**1. Lean / no page-load impact (frontend *and* backend).**
- Admin and CLI code never loads on the frontend.
- New frontend output is off-by-default or trivial markup — and adds **no new database queries** on a normal page load.
- Schema enrichment rides the *existing* per-request `@graph` build and reads only from already-loaded options / post-meta (query-free).
- Heavy work — migration, content scans, full exports — is **batched, on-demand, or CLI**. Never on a page load.

**2. Directly SEO or social-sharing only.**
- In: titles/meta, canonical, robots, sitemaps, schema, IndexNow, Open Graph / Twitter (X) cards, and the migration + diagnostics that serve them.
- Out: everything else.

**Through-line:** of every feature below, **only schema enrichment touches the frontend at all** — and it must stay query-free. Everything else is admin, CLI, config, or an off-by-default toggle. The plugin's frontend cost does not grow.

## The line we hold (explicitly out of scope)
- **Per-type schema *builders*** — full Product/Service/Event property editors. That's the bloat Heirloom exists to replace. Use the `heirloom_seo/schema/graph` filter + an optional raw JSON-LD field instead.
- **FAQ/HowTo as a headline feature** — Google curtailed those rich results. Filterable only.
- Content analysis, "SEO score", readability, keyword research/link tools.
- Redirect manager / 404 monitor (already excluded by decision).
- Anything not SEO/social; anything that adds frontend cost without being core SEO output.

---

## Phase A — Tighten the AI surface  ·  target 0.4.0  ·  small
*Do first. Lock the door before adding rooms. All admin / off-by-default; zero frontend cost.*

Goal: make the existing AI features safe and controllable before extending them.
- Per-post **"Exclude from AI exports"** field (metabox) — override for otherwise-public posts.
- Auto-exclude password-protected + `noindex` — **shipped in 0.3.1**; the audit will confirm it.
- **One-click crawler presets:** *Block training only* · *Block AI search/referral too* · *Custom*. (Intent grouping shipped 0.3.1; this is the buttons on top — small admin JS.)
- **Preview** buttons for `/llms.txt`, `/llms-full.txt`, and the current post's `.md`.
- **Total-length cap** on `llms-full.txt` (a byte ceiling alongside the existing ≤200-item cap).

Performance: admin + export-path only. No frontend impact.

---

## Phase B — Migration importers + WP-CLI  ·  target 0.5.0  ·  large
*The strategic core — makes Heirloom adoptable on established sites, and at scale.*

Goal: one-click / one-command import of per-post SEO data from **Yoast, Rank Math, AIOSEO, The SEO Framework**.

Import at minimum: SEO title, meta description, canonical URL, noindex/nofollow, OG image, schema type (where mappable). Optional: translate site-wide title/description *templates* (`%%title%%` → `%title%`).

Design notes:
- Per-source adapters (meta-key maps + value normalizers).
- **AIOSEO is the odd one** — since v4 it stores SEO data in its own table (`wp_aioseo_posts`), not post-meta. The others are post-meta (`_yoast_wpseo_*`, `rank_math_*`, `_genesis_*`).
- Normalize differing `noindex` encodings (Yoast `0/1/2`, Rank Math serialized array, TSF `1/0`).
- **Non-destructive** (leave the source's data intact) and **idempotent** (re-runnable; skip-existing or overwrite option; `--dry-run`).
- **Batched.** The admin importer runs AJAX batches with a progress bar; large sites use the CLI.

WP-CLI (ships with this phase — it's the scale vehicle; registers only when `WP_CLI` is defined):
- `wp heirloom-seo import <yoast|rankmath|aioseo|tsf> [--dry-run] [--overwrite]`
- `wp heirloom-seo cache purge`
- `wp heirloom-seo sitemap regenerate`
- `wp heirloom-seo indexnow submit --post=<id>`
- `wp heirloom-seo audit` (headless diagnostics — Phase D)

Performance: backend / CLI, one-time. No ongoing or frontend cost.

**Positioning note:** this is the moment Heirloom expands from "my/client greenfield sites" to "replace an existing SEO plugin on any site." Bigger audience, more edge cases and support surface. A deliberate decision — recommended given the multi-client context, but worth making on purpose.

---

## Phase C — Settings export/import  ·  target 0.5.x  ·  small
*Cheap agency win; drop in alongside Phase B.*
- JSON export/import of the single option array; import reuses the existing sanitizer.
- **Exclude site-specific secrets** (IndexNow key, search-engine verification codes) from the export so nothing leaks across sites.

Performance: admin-only.

---

## Phase D — SEO Health Audit  ·  target 0.6.0  ·  medium
*Practical diagnostics — not a score, no gimmicks. Reinforces the honest-tool brand.*

Two tiers, split by cost:
- **Instant config checks** (cheap, always available): physical `robots.txt` overriding virtual rules; another SEO plugin active; site logo / schema identity missing; IndexNow key file reachable; sitemap reachable; (password/noindex AI exposure — now auto-handled, just confirmed).
- **Content checks** (expensive — behind a "Run scan" button, sampled/limited, **never synchronous on large sites**): missing meta description (= no manual desc *and* no excerpt, since we fall back to excerpt); duplicate titles (cheap proxy: SQL `GROUP BY post_title`, because rendered titles are computed, not stored).

Folds in the validation/status tools: sitemap URL count, last cache-build time, sitemap XML validation, **schema preview for the current post**, and a "rendered head tags for a URL" debugging view.

Performance: admin-only. Config checks are cheap; content scans are gated / on-demand / available headless via `wp heirloom-seo audit`. No frontend cost.

---

## Phase E — Schema enrichment  ·  target 0.7.0  ·  medium
*Extend identity; hold the line on builders.*
- **Do:** Organization/Person identity details — postal address, contact, `sameAs` social profiles; a **LocalBusiness** mode (Organization + local fields: hours, geo). All read from options → no new frontend queries.
- **Maybe (only if WooCommerce is active):** basic auto-generated Product schema from Woo data. Product pages only, opt-in. Watch scope — this is the part most likely to bloat.
- **Escape hatch, not a builder:** the shipped `heirloom_seo/schema/graph` filter + an optional per-post raw JSON-LD field for power users.
- FAQ/HowTo: filterable only.

Performance: rides the existing per-request `@graph` build; identity/LocalBusiness add fields from options (query-free). Woo Product is the only piece with per-page queries — gated to product pages when Woo is active.

---

## Version map (a guide, not a contract)
| Version | Phase |
|---|---|
| 0.4.0 | A — AI tightening |
| 0.5.0 | B — migration + WP-CLI |
| 0.5.x | C — settings export/import |
| 0.6.0 | D — audit + validation |
| 0.7.0 | E — schema enrichment |
| 1.0.0 | stabilize, docs, broad compatibility testing |

## Open decisions
1. **Positioning** — confirm the "replace an existing SEO plugin" expansion that Phase B implies.
2. **WooCommerce Product schema** — in (rich-result value) or out (scope creep)? Lean toward opt-in, Woo-active-only.
3. **Per-post raw JSON-LD escape hatch** — include for power users, or keep schema extension filter-only?
4. **Audit content-scan ceiling** — define the max posts scanned in-admin before it's CLI-only (e.g., 10k).
