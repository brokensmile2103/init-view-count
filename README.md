# Init View Count – Minimal, Accurate, Extensible

> Clean, REST-powered post view counter for WordPress. Lightweight, developer-friendly, and highly customizable with smart tracking and template overrides.

**Counts real views. Stores in meta. Renders beautifully. Built for performance.**

[![Version](https://img.shields.io/badge/stable-v1.5-blue.svg)](https://wordpress.org/plugins/init-view-count/)
[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Made with ❤️ in HCMC](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F%20in%20HCMC-blue)

## Overview

Init View Count lets you track and display real post views — not just page loads or fake numbers.  
It uses JavaScript + REST API to count only when the user actually scrolls and stays, storing data in meta keys like `_init_view_count`.

It supports shortcodes, REST endpoints, trending detection, and theme template overrides. Perfect for blogs, magazines, and content-focused WordPress sites.

![Demo](https://inithtml.com/wp-content/uploads/2025/06/Init-View-Count-Ranking-Demo.gif)

## Highlights

- Real view detection with scroll + delay logic
- Data stored in native post meta (no custom DB tables)
- Headless + SPA-friendly via REST-first architecture
- Multiple shortcodes for views, lists, and rankings
- Daily, weekly, monthly view tracking built-in
- Auto-calculated trending score (views/hour)
- WooCommerce-style template overrides
- Zero bloat, zero jQuery, zero nonsense

## What's New in v1.5

- New **Shortcode Builder UI** under Settings
- `[init_view_ranking]` shortcode for tabbed view rankings
- `range=trending` mode for viral content detection
- Extended REST API: pagination, filters, caching
- Full i18n support and translatable UI
- Built-in skeleton loader and smart asset loading

## Shortcodes

### `[init_view_count]`

Displays the view count for the current post.

**Attributes:**

- `field`: `total`, `day`, `week`, `month` (default: `total`)
- `format`: `formatted`, `raw`, `short` (default: `formatted`)
- `time`: `true` or `false` – show “Posted X ago” (default: `false`)

### `[init_view_list]`

Displays a list of the most viewed posts.

**Attributes:**

- `number`: Number of posts to show
- `range`: `total`, `day`, `week`, `month`, `trending`
- `post_type`: Specify post type (e.g., `post`, `product`)
- `template`: Choose layout style (`sidebar`, `grid`, `full`, etc.)
- `class`: Add custom CSS class
- `category`: Filter by category slug
- `tag`: Filter by tag slug
- `page`: Page number for pagination
- `empty`: Message if no posts found

### `[init_view_ranking]`

Creates a tabbed ranking layout for views.

**Attributes:**

- `tabs`: Comma-separated values: `day`, `week`, `month`, `total`
- `number`: Posts per tab
- `class`: Custom wrapper class

## REST API Endpoints

- `POST /wp-json/initvico/v1/count` – Record a view  
- `GET /wp-json/initvico/v1/top` – Get top viewed posts  

**Query Parameters (GET /top):**

- `range`: `day`, `week`, `month`, `total`, `trending`
- `post_type`: e.g., `post`, `product`
- `number`: Number of posts
- `page`: Page number
- `fields`: `full`, `id`, etc.

Trending data is cached hourly using WordPress transients.

## Template Overrides

Override plugin templates by placing files in your theme:

```bash
your-theme/init-view-count/view-list-grid.php
your-theme/init-view-count/ranking.php
```

Structure and style everything your way – just like WooCommerce.

## Installation

1. Upload to `/wp-content/plugins/init-view-count`
2. Activate under **Plugins → Installed Plugins**
3. Configure settings via **Settings → Init View Count**
4. Add shortcodes or fetch data via REST API

**Scheduled Tasks:**

- Reset view counters (daily/hourly)
- Update trending post scores

*All data will be removed upon uninstall.*

## Developer Notes

### Meta Keys Used

- `_init_view_count` – total views
- `_init_view_day_count` – daily views
- `_init_view_week_count` – weekly views
- `_init_view_month_count` – monthly views

*Filterable via* `init_plugin_suite_view_count_meta_key`

### Filters Available

- `init_plugin_suite_view_count_should_count`
- `init_plugin_suite_view_count_meta_key`
- `init_plugin_suite_view_count_after_counted`
- `init_plugin_suite_view_count_api_top_args`
- `init_plugin_suite_view_count_api_top_item`
- `init_plugin_suite_view_count_query_args`
- `init_plugin_suite_view_count_empty_output`

Full documentation: [The Complete Guide to Init View Count](https://en.inithtml.com/series/the-complete-guide-to-init-view-count/)

## License

GPLv2 or later — free, open source, developer-first.

## Part of Init Plugin Suite

Init View Count is a proud member of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) —  
A growing collection of powerful, minimalist plugins made for developers who love speed, simplicity, and clean code.
