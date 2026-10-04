# Init View Count – Minimal, Accurate, Extensible

> Clean, REST-powered post view counter for WordPress. Lightweight, developer-friendly, and highly customizable with smart tracking and template overrides.

**Counts real views. Stores in meta. Renders beautifully. Built for performance.**

[![Version](https://img.shields.io/badge/stable-v2.0.3-blue.svg)](https://wordpress.org/plugins/init-view-count/)
[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Made with ❤️ in HCMC](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F%20in%20HCMC-blue)

## Overview

Init View Count lets you track and display real post views — not just page loads or fake numbers.  
It uses JavaScript + REST API to count only when the user actually scrolls and stays, storing data in meta keys like `_init_view_count`.

It supports shortcodes, native Gutenberg blocks, REST endpoints, the Abilities API, trending detection, auto-insertion, and WooCommerce-style template overrides.  
Perfect for blogs, magazines, and content-focused WordPress sites.

![Demo](https://inithtml.com/wp-content/uploads/2025/06/Init-View-Count-Ranking-Demo.gif)

> **Requires WordPress 6.9+.** As of v2.0.0, this is the minimum version — see [Changelog](#changelog) below.

## Highlights

- Real view detection with scroll + delay logic
- Native Block Editor (Gutenberg) support — no build step, no shortcodes needed
- Abilities API support for AI agents and automation tools (WordPress 6.9+)
- Auto-insert shortcode before/after post content (configurable)
- Data stored in native post meta (no custom DB tables)
- Headless + SPA-friendly via REST-first architecture
- Multiple shortcodes for views, lists, and rankings
- Daily, weekly, monthly view tracking built-in
- Auto-calculated trending score (views/hour)
- Strict IP check to block fake REST hits
- WooCommerce-style template overrides
- Optional batch mode: store views locally, reduce REST requests
- Optional performance modes for busy sites: throttled meta cache refresh and sampled Traffic Shape writes
- Includes admin Dashboard widget to monitor top posts
- Zero bloat, zero jQuery, zero nonsense

## Block Editor (Gutenberg)

Since v2.0.0, every shortcode also has a matching native block — search **"Init View Count"** in the block inserter. Each block has its own settings panel and a live preview powered by `ServerSideRender`, and renders through the exact same PHP output as its shortcode counterpart (no duplicated display logic).

- **View Count** — mirrors `[init_view_count]`. Post ID, field (total/day/week/month), format, time-since-published, icon, and Schema.org toggle.
- **Popular Posts List** — mirrors `[init_view_list]`. Number of posts, post type, template, title, view range, category/tag filter, empty-state text.
- **View Ranking (Tabbed)** — mirrors `[init_view_ranking]`. Tabs, number of posts per tab, post type.

No build tooling required — the editor script is plain vanilla JavaScript, so it ships and updates just like the rest of the plugin.

## Shortcodes

### `[init_view_count]`

Displays the view count of the current post, or of any post via the `id` attribute.

**Attributes:**

- `id`: Post ID to display (default: the current post)
- `field`: `total`, `day`, `week`, `month` (default: `total`)
- `format`: `formatted`, `raw`, `short` (default: `formatted`)
- `time`: `true` or `false` – show “Posted X ago”
- `icon`: `true` to display inline SVG icon before count
- `schema`: `true` to include [InteractionCounter](https://schema.org/InteractionCounter) microdata
- `class`: Add custom CSS class to wrapper

> This shortcode can be auto-inserted into post content (before or after) via settings.

### `[init_view_list]`

Displays a list of the most viewed posts.

**Attributes:**

- `number`: Number of posts to show (default: `10`)
- `range`: `total`, `day`, `week`, `month`, `trending`
- `post_type`: Specify post type (e.g., `post`, `product`)
- `template`: Choose layout style (`sidebar`, `grid`, `full`, `details`)
- `title`: Title above list (`title=""` to hide)
- `class`: Add custom CSS class
- `category`: Filter by category slug
- `tag`: Filter by tag slug
- `orderby`: Sort field (default: `meta_value_num`)
- `order`: `ASC` or `DESC`
- `page`: Page number for pagination
- `empty`: Message if no posts found

### `[init_view_ranking]`

Creates a tabbed ranking layout for views.

**Attributes:**

- `tabs`: Comma-separated values: `total`, `day`, `week`, `month`, `yesterday`, `last_week`, `last_month` (default: `total,day,week,month`)
- `number`: Posts per tab (default: `5`)
- `class`: Custom wrapper class
- `post_type`: Comma-separated post type(s) to rank (default: `post` and `page`)

> Includes lazy loading, skeleton loaders, and JS-only display.

## REST API Endpoints

### `POST /wp-json/initvico/v1/count`

Record one or more views. Uses JavaScript + scroll + delay detection.

**Parameters:**

- `post_id`: Single ID or array of post IDs

> Optional: when **Require REST nonce verification** is enabled in settings, requests must carry a valid `X-WP-Nonce` header. Off by default — WordPress nonces expire after ~12–24h, which breaks counting on pages served from a long-lived full-page cache.

### `GET /wp-json/initvico/v1/top`

Get most viewed posts.

**Parameters:**

- `range`: `total`, `day`, `week`, `month`, `yesterday`, `last_week`, `last_month`, `trending` (default: `total`)
- `post_type`: Comma-separated post type(s); only existing, publicly viewable types are accepted (default: `post` and `page`)
- `number`: Number of posts (default: `5`, max: `100`)
- `page`: Page number (pagination)
- `fields`: `full` or `minimal`
- `tax`: Optional taxonomy slug (e.g., `category`)
- `terms`: Comma-separated term slugs or IDs
- `no_cache`: `1` to bypass transients

> Trending scores are cached hourly using transients.

## Abilities API

Since v2.0.0, on WordPress 6.9+ the plugin registers two **read-only** abilities via [`wp_register_ability()`](https://developer.wordpress.org/apis/abilities-api/), under the `init-view-count` category. This lets AI agents and automation tools discover and query view-count data through WordPress's standardized ability registry, without needing to know the plugin's REST routes.

- **`init-view-count/get-post-views`** — returns the tracked view count for a single post (total/day/week/month).
- **`init-view-count/get-top-posts`** — returns a ranked list of the most viewed posts. Wraps the exact same logic as `GET /top` and `[init_view_ranking]`, so results always match.

No write or destructive abilities are exposed — nothing can increment or reset view counts through this API.

## Batch View Tracking

You can enable **batch mode** in settings. When enabled:

- Views are stored in browser (localStorage/sessionStorage)
- Sent in group to the REST endpoint after N views or on unload
- Reduces REST requests significantly

## Performance Options

Two optional modes for busy sites, both **off by default**. Turn them on under **Settings → Init View Count**.

### Reduce view count cache refreshes

By default, every counted view clears the whole meta cache of that post. On sites with a persistent object cache (Redis, Memcached), a popular post ends up reloading all of its metadata from the database over and over.

With this option on, each post's meta cache is refreshed **at most once per interval** (10–600 seconds, default 60).

- Views are still written to the database immediately with the same atomic `UPDATE` — nothing is lost or double-counted
- Only displayed numbers (shortcodes, blocks, rankings, Trending, the number shown after counting) may lag by up to the interval
- A post that stops receiving views catches up on its next view, when it is updated, or at the daily reset (when daily views are enabled)
- No effect on sites without a persistent object cache — the settings page tells you which case applies

### Sample Traffic Shape writes

The Traffic Shape Learner (used by Trending) updates a database option on every view request. With sampling on, it records only **1 in N requests**, each sample weighted N times (N = 2–100, default 10).

- Writes drop by about N times while expected hourly bins and daily totals stay the same
- In a 200,000-request simulation at N = 10: writes went from 200,000 to ~20,000, recorded total within 0.4% of the real value
- Post view counts are never sampled
- Recommended for busy sites only — on low-traffic sites the learned pattern becomes noisier

> **Strict IP check** also has a cost: it reads a transient on every view request and writes it back on every counted view. Without a persistent object cache, that means up to two extra `wp_options` writes per counted view.

## Auto-Insert View Count

In plugin settings, you can choose to **automatically insert** `[init_view_count]` into post content:

- Before content
- After content
- Only for supported post types

Fully optional and filterable.

## Template Overrides

Override any layout in your theme:

```bash
your-theme/init-view-count/view-list-grid.php
your-theme/init-view-count/ranking.php
```

Style it your way – just like WooCommerce templates.

## Admin Features

- Dashboard widget to see top viewed posts (uses `[init_view_ranking]`)
- Shortcode builder panels to generate and preview shortcodes
- Option to disable plugin’s default CSS
- i18n-ready with full translation support

## Developer Notes

### Meta Keys Used

- `_init_view_count` – total views
- `_init_view_day_count` – daily views
- `_init_view_week_count` – weekly views
- `_init_view_month_count` – monthly views
- `_init_view_day_yesterday` – views of the previous day
- `_init_view_week_last` – views of the previous week
- `_init_view_month_last` – views of the previous month

*Can be changed via filter: `init_plugin_suite_view_count_meta_key`*

### Filters Available

#### General

- `init_plugin_suite_view_count_should_count`
- `init_plugin_suite_view_count_meta_key`
- `init_plugin_suite_view_count_after_counted`
- `init_plugin_suite_view_count_ip_headers`
- `init_plugin_suite_view_count_meta_flush_interval`

#### REST `/top`

- `init_plugin_suite_view_count_top_post_types`
- `init_plugin_suite_view_count_api_top_args`
- `init_plugin_suite_view_count_api_top_item`
- `init_plugin_suite_view_count_api_top_cache_time`
- `init_plugin_suite_view_count_api_top_max_number`

#### Shortcodes

- `init_plugin_suite_view_count_query_args`
- `init_plugin_suite_view_count_empty_output`
- `init_plugin_suite_view_count_view_list_atts`

#### Auto-insert

- `init_plugin_suite_view_count_default_shortcode`
- `init_plugin_suite_view_count_auto_insert_enabled`

#### Trending

- `init_plugin_suite_view_count_engagement_meta_keys`
- `init_plugin_suite_view_count_trending_post_types`
- `init_plugin_suite_view_count_trending_component_weights`
- `init_plugin_suite_view_count_shape_sample_rate`

Full docs: [The Complete Guide to Init View Count](https://en.inithtml.com/series/the-complete-guide-to-init-view-count/)

## Installation

1. Upload to `/wp-content/plugins/init-view-count`
2. Activate under **Plugins → Installed Plugins**
3. Configure under **Settings → Init View Count**
4. Add shortcodes/blocks or consume the REST API / Abilities API

## Changelog

### 2.0.3

- New: **Reduce view count cache refreshes** option — throttles per-post meta cache refreshes on sites with a persistent object cache
- New: **Sample Traffic Shape writes** option — cuts Traffic Shape database writes by about N times
- New: `init_plugin_suite_view_count_meta_flush_interval` and `init_plugin_suite_view_count_shape_sample_rate` filters
- Improvement: Strict IP check now explains its performance cost in settings
- Docs: fixed outdated readme information (shortcode attributes, `/top` parameters, Trending weights)

### 2.0.0

- **Breaking change: requires WordPress 6.9+**
- New: Abilities API support and native Block Editor blocks

View full changelog (all versions): [Init View Count – Changelog](https://en.inithtml.com/plugin/init-view-count/)

## License

GPLv2 or later — free, open source, developer-first.

## Part of Init Plugin Suite

Init View Count is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) — a collection of blazing-fast, no-bloat plugins made for WordPress developers who care about quality and speed.
