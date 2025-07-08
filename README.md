# Init View Count – Minimal, Accurate, Extensible

> Clean, REST-powered post view counter for WordPress. Lightweight, developer-friendly, and highly customizable with smart tracking and template overrides.

**Counts real views. Stores in meta. Renders beautifully. Built for performance.**

[![Version](https://img.shields.io/badge/stable-v1.12-blue.svg)](https://wordpress.org/plugins/init-view-count/)
[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Made with ❤️ in HCMC](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F%20in%20HCMC-blue)

## Overview

Init View Count lets you track and display real post views — not just page loads or fake numbers.  
It uses JavaScript + REST API to count only when the user actually scrolls and stays, storing data in meta keys like `_init_view_count`.

It supports shortcodes, REST endpoints, trending detection, auto-insertion, and WooCommerce-style template overrides.  
Perfect for blogs, magazines, and content-focused WordPress sites.

![Demo](https://inithtml.com/wp-content/uploads/2025/06/Init-View-Count-Ranking-Demo.gif)

## Highlights

- Real view detection with scroll + delay logic
- Auto-insert shortcode before/after post content (configurable)
- Data stored in native post meta (no custom DB tables)
- Headless + SPA-friendly via REST-first architecture
- Multiple shortcodes for views, lists, and rankings
- Daily, weekly, monthly view tracking built-in
- Auto-calculated trending score (views/hour)
- Strict IP check to block fake REST hits
- WooCommerce-style template overrides
- Optional batch mode: store views locally, reduce REST requests
- Includes admin Dashboard widget to monitor top posts
- Zero bloat, zero jQuery, zero nonsense

## Shortcodes

### `[init_view_count]`

Displays the view count for the current post.

**Attributes:**

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

- `number`: Number of posts to show
- `range`: `total`, `day`, `week`, `month`, `trending`
- `post_type`: Specify post type (e.g., `post`, `product`)
- `template`: Choose layout style (`sidebar`, `grid`, `full`, etc.)
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

- `tabs`: Comma-separated values: `day`, `week`, `month`, `total` (default: all)
- `number`: Posts per tab
- `class`: Custom wrapper class
- `post_type`: Filter results by specific post type (e.g. post, page)

> Includes lazy loading, skeleton loaders, and JS-only display.

## REST API Endpoints

### `POST /wp-json/initvico/v1/count`

Record one or more views. Uses JavaScript + scroll + delay detection.

**Parameters:**

- `post_id`: Single ID or array of post IDs

### `GET /wp-json/initvico/v1/top`

Get most viewed posts.

**Parameters:**

- `range`: `total`, `day`, `week`, `month`, `trending`
- `post_type`: e.g., `post`, `product`
- `number`: Number of posts
- `page`: Page number (pagination)
- `fields`: `full` or `minimal`
- `tax`: Optional taxonomy slug (e.g., `category`)
- `terms`: Comma-separated term slugs or IDs
- `no_cache`: `1` to bypass transients

> Trending scores are cached hourly using transients.

## Batch View Tracking

You can enable **batch mode** in settings. When enabled:

- Views are stored in browser (localStorage/sessionStorage)
- Sent in group to the REST endpoint after N views or on unload
- Reduces REST requests significantly

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

*Can be changed via filter: `init_plugin_suite_view_count_meta_key`*

### Filters Available

#### General

- `init_plugin_suite_view_count_should_count`
- `init_plugin_suite_view_count_meta_key`
- `init_plugin_suite_view_count_after_counted`

#### REST `/top`

- `init_plugin_suite_view_count_api_top_args`
- `init_plugin_suite_view_count_api_top_item`
- `init_plugin_suite_view_count_api_top_cache_time`

#### Shortcodes

- `init_plugin_suite_view_count_query_args`
- `init_plugin_suite_view_count_empty_output`
- `init_plugin_suite_view_count_view_list_atts`

#### Auto-insert

- `init_plugin_suite_view_count_default_shortcode`
- `init_plugin_suite_view_count_auto_insert_enabled`

Full docs: [The Complete Guide to Init View Count](https://en.inithtml.com/series/the-complete-guide-to-init-view-count/)

## Installation

1. Upload to `/wp-content/plugins/init-view-count`
2. Activate under **Plugins → Installed Plugins**
3. Configure under **Settings → Init View Count**
4. Add shortcodes or consume REST API

## License

GPLv2 or later — free, open source, developer-first.

## Part of Init Plugin Suite

Init View Count is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) —  a collection of blazing-fast, no-bloat plugins made for WordPress developers who care about quality and speed.
