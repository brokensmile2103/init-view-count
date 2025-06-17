# Init View Count – Minimal, Accurate, Extensible

> Clean, REST-powered post view counter for WordPress. Lightweight, developer-friendly, and highly customizable with smart tracking and template overrides.

**Counts real views. Stores in meta. Renders beautifully. Built for performance.**

[![Version](https://img.shields.io/badge/stable-v1.5-blue.svg)](https://wordpress.org/plugins/init-view-count/)
[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Made with ❤️ in HCMC](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F%20in%20HCMC-blue)

## Overview

Init View Count lets you track and display real post views — not just page loads or fake numbers.  
It uses JavaScript + REST API to count only when the user actually scrolls and stays, and stores data in custom meta keys like `_init_view_count`.

With shortcode support, tabbed rankings, REST endpoints, and full template override capabilities, this plugin is perfect for blogs, magazines, content hubs, or anything in between.

## Highlights

- Real view detection — scroll + time delay logic
- Counts stored in `post_meta`, no custom tables
- REST-first: works with headless and SPA setups
- Shortcodes for view display, post lists, and tabbed rankings
- Supports daily, weekly, monthly view tracking
- Built-in trending score based on views per hour
- Template override system like WooCommerce
- Extremely lightweight: no bloat, no tracking, no jQuery

## What's New in v1.5

- New **Shortcode Builder UI** under Settings → Init View Count
- Added `[init_view_ranking]` shortcode (tabbed ranking by day/week/month/total)
- New `range=trending` mode for fastest-growing posts
- REST `/top` endpoint supports pagination, filters, and cache
- Fully translatable UI strings and i18n-ready shortcode builder
- Built-in skeleton loaders and conditional asset loading

## Shortcodes

### `[init_view_count]`

Display view count for the current post.

**Attributes:**

| Name    | Description                       | Default     |
|---------|-----------------------------------|-------------|
| `field` | `total`, `day`, `week`, `month`   | `total`     |
| `format`| `formatted`, `raw`, `short`       | `formatted` |
| `time`  | Show "Posted X ago" (true/false)  | `false`     |

### `[init_view_list]`

Show a list of most viewed posts.

**Attributes:**

| Name      | Description                        |
|-----------|------------------------------------|
| `number`  | Number of posts                    |
| `range`   | `total`, `day`, `week`, `month`, `trending` |
| `post_type` | Custom post type if needed       |
| `template` | `sidebar`, `grid`, `full`, etc. (theme overrideable) |
| `class`   | Additional CSS class               |
| `category`| Filter by category slug            |
| `tag`     | Filter by tag slug                 |
| `page`    | Pagination page number             |
| `empty`   | Message when no posts found        |

### `[init_view_ranking]`

Tabbed ranking by view count.

**Attributes:**

| Name     | Description                        |
|----------|------------------------------------|
| `tabs`   | Comma-separated: `day`, `week`, `month`, `total` |
| `number` | Posts per tab                      |
| `class`  | Custom class for outer wrapper     |

## REST API Endpoints

| Method | Endpoint                            | Description             |
|--------|-------------------------------------|-------------------------|
| POST   | `/wp-json/initvico/v1/count`        | Record a view           |
| GET    | `/wp-json/initvico/v1/top`          | Retrieve top viewed posts |

Supports filters like:  
`?range=week&post_type=post&number=5&page=1&fields=full`

Trending logic is cached using `set_transient()` and auto-updated hourly

## Template Override

You can override default templates like this:

```
your-theme/init-view-count/view-list-grid.php
your-theme/init-view-count/ranking.php
```

Use theming to fully customize the output, structure, and styles — just like WooCommerce.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/init-view-count`
2. Activate via **Plugins → Installed Plugins**
3. Configure under **Settings → Init View Count**
4. Use shortcodes or REST API to display data

This plugin automatically schedules daily and hourly cron jobs for:
- Resetting day/week/month views
- Updating trending post scores

All data is deleted if the plugin is uninstalled.

## Developer Notes

### Post Meta Keys

| Purpose           | Meta Key                     |
|-------------------|------------------------------|
| Total views       | `_init_view_count`           |
| Daily views       | `_init_view_day_count`       |
| Weekly views      | `_init_view_week_count`      |
| Monthly views     | `_init_view_month_count`     |

> Meta key names are filterable via `init_plugin_suite_view_count_meta_key`.

### Filters Available

- `init_plugin_suite_view_count_should_count`
- `init_plugin_suite_view_count_meta_key`
- `init_plugin_suite_view_count_after_counted`
- `init_plugin_suite_view_count_api_top_args`
- `init_plugin_suite_view_count_api_top_item`
- `init_plugin_suite_view_count_query_args`
- `init_plugin_suite_view_count_empty_output`

## License

GPLv2 or later — open, free, and developer-first.

## Part of Init Plugin Suite

Init View Count is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/), a growing set of minimalist, high-performance plugins made for WordPress developers who care about speed, simplicity, and clean architecture.
