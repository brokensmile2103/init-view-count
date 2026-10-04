=== Init View Count – AI-Powered, Trending, REST API ===
Contributors: brokensmile.2103
Tags: post views, view counter, trending posts, REST API, shortcode
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Count post views accurately via REST API with customizable display. Lightweight, fast, and extensible. Includes shortcode with multiple layouts.

== Description ==

**Init View Count** is a fast, clean plugin to track post views without clutter. It:

- Uses REST API and JS to count real views
- Prevents duplicate counts with session/local storage
- Stores counts in meta keys like `_init_view_count`, `_init_view_day_count`, etc.
- Provides `[init_view_count]` and `[init_view_list]` shortcodes
- Includes `[init_view_ranking]` shortcode with tabbed ranking by time range
- Supports template overrides (like WooCommerce)
- Lightweight. No tracking, no admin bloat.
- Includes REST API to query most viewed posts
- Supports pagination in `[init_view_list]` via the `page` attribute
- Batch view tracking support to reduce REST requests on busy sites
- Optional strict IP-based filtering to block fake view requests posted directly to the REST endpoint
- Includes a Dashboard widget to monitor top viewed posts directly in wp-admin
- Learns site-wide traffic shape (hourly & weekday) via AI-powered smoothing
- Shapes cached and updated efficiently with minimal overhead
- Safe reset action to rebuild patterns automatically
- Fully integrated with Trending Engine v3 for uplift-based scoring
- Native Block Editor (Gutenberg) support with 3 dedicated blocks, no build step required
- Abilities API support (WordPress 6.9+) — read-only abilities for AI agents and automation tools
- Optional "Reduce view count cache refreshes" mode for busy sites running a persistent object cache (Redis, Memcached)

This plugin is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) — a collection of minimalist, fast, and developer-focused tools for WordPress.

GitHub repository: [https://github.com/brokensmile2103/init-view-count](https://github.com/brokensmile2103/init-view-count)

== Highlights ==

- REST-first design — no jQuery or legacy Ajax
- View tracking powered by time + scroll detection
- Realtime display with optional animated counters
- Fully theme-compatible with overrideable templates
- Developer-friendly with rich filter support
- Optional `[init_view_ranking]` shortcode for tabbed view by day/week/month/total
- Assets are only loaded when needed – perfect for performance-conscious themes
- Fully compatible with headless and SPA frameworks (REST-first + lazy)
- Supports batch mode: delay view requests and send in groups (configurable in settings)
- Includes optional Dashboard widget for quick admin overview of top viewed posts
- AI-powered Traffic Shape Learner – understands your site’s hourly & weekly rhythm
- Auto-integrated with Trending Engine v3 for seasonality-aware uplift detection
- Smart fallbacks (day → week → month → total) ensure rankings never run empty
- Ultra-light: only 1 write per increment + 1 rollup per day, cache-first design

== Installation ==

1. Upload plugin to `/wp-content/plugins/init-view-count/`
2. Activate via Plugins menu
3. Use `[init_view_count]` or `[init_view_list]` in your content
4. Customize settings in Settings → Init View Count

== Shortcodes ==

=== [init_view_count] ===  
Shows the view count of the current post, or of any post via the `id` attribute.

**Attributes:**
- `id`: Post ID to display (default: the current post)
- `field`: `total` (default), `day`, `week`, `month` – which counter to display
- `format`: `formatted` (default), `raw`, or `short` – controls number formatting
- `time`: `true` to show time diff from post date (e.g. "3 days ago")
- `icon`: `true` to display a small SVG icon before the count
- `schema`: `true` to output schema.org microdata (InteractionCounter)
- `class`: add a custom CSS class to the outer wrapper

=== [init_view_list] ===  
Show list of most viewed posts.

**Attributes:**
- `number`: Number of posts to show (default: 10)
- `page`: Show a specific page of results (default: 1)
- `post_type`: Type of post (default: post)
- `template`: `sidebar` (default), `full`, `grid`, `details` (can be overridden)
- `title`: Title above list. Set to empty (`title=""`) to hide
- `class`: Custom class added to wrapper
- `orderby`: Sort field (default: meta_value_num)
- `order`: ASC or DESC (default: DESC)
- `range`: `total`, `day`, `week`, `month`, `trending`
- `category`: Filter by category slug
- `tag`: Filter by tag slug
- `empty`: Message to show if no posts found

=== [init_view_ranking] ===  
Show tabbed ranking of most viewed posts. Uses REST API and JavaScript for dynamic loading. Optimized for SPA/headless usage.

**Attributes:**
- `tabs`: Comma-separated list of ranges. Available: `total`, `day`, `week`, `month`, `yesterday`, `last_week`, `last_month` (default: `total,day,week,month`)
- `number`: Number of posts per tab (default: 5)
- `post_type`: Comma-separated post type(s) to rank (default: `post` and `page`)
- `class`: Custom class for outer wrapper

This shortcode automatically enqueues required JS and uses skeleton loaders while fetching data.

== REST API ==

This plugin exposes two REST endpoints to interact with view counts: one for recording views and another for retrieving top posts.

**`POST /wp-json/initvico/v1/count`**  
Record one or more views. Accepts a single post ID or an array of post IDs.

**Parameters:**
- `post_id` — *(int|array)* Required. One or more post IDs to increment view count for.

This endpoint checks if the post is published, belongs to a supported post type, and applies delay/scroll config (via JavaScript). It updates total and optionally day/week/month view counters.

Note: The number of post IDs processed per request is limited based on the batch setting in plugin options.

Optional: if "Require REST nonce verification?" is enabled in plugin settings, this endpoint also requires a valid `X-WP-Nonce` header (action `wp_rest`). This is off by default — see the FAQ below before turning it on.

**`GET /wp-json/initvico/v1/top`**  
Retrieve the most viewed posts, ranked by view count.

**Parameters:**
- `range` — *(string)* `total`, `day`, `week`, `month`, `yesterday`, `last_week`, `last_month`, `trending`. Defaults to `total`.
- `post_type` — *(string)* Comma-separated post type(s) to query. Only existing, publicly viewable post types are accepted. Defaults to `post` and `page`.
- `number` — *(int)* Number of posts to return. Default: `5`, maximum `100` (filterable via `init_plugin_suite_view_count_api_top_max_number`).
- `page` — *(int)* Page number. Default: `1`.
- `fields` — *(string)* `minimal` (id, title, link) or `full` (includes excerpt, thumbnail, type, date, etc.)
- `tax` — *(string)* Optional. Taxonomy slug (e.g. `category`).
- `terms` — *(string)* Comma-separated term slugs or IDs.
- `no_cache` — *(bool)* If `1`, disables transient caching.

This endpoint supports filtering and caching, and can be extended to support custom output formats.

== Filters for Developers ==

This plugin provides multiple filters to help developers customize behavior and output in both REST API and shortcode use cases.

**`init_plugin_suite_view_count_should_count`**  
Allow or prevent counting views for a specific post.  
**Applies to:** REST `/count`  
**Params:** `bool $should_count`, `int $post_id`, `WP_REST_Request $request`

**`init_plugin_suite_view_count_meta_key`**  
Change the meta key used to read or write view counts.  
**Applies to:** REST & Shortcodes  
**Params:** `string $meta_key`, `int|null $post_id`

**`init_plugin_suite_view_count_after_counted`**  
Run custom logic after view count has been incremented.  
**Applies to:** REST `/count`  
**Params:** `int $post_id`, `array $updated`, `WP_REST_Request $request`

**`init_plugin_suite_view_count_api_top_args`**  
Customize WP_Query arguments used for `/top` endpoint.  
**Applies to:** REST `/top`  
**Params:** `array $args`, `WP_REST_Request $request`

**`init_plugin_suite_view_count_api_top_item`**  
Modify each item before it's returned in the `/top` response.  
**Applies to:** REST `/top`  
**Params:** `array $item`, `WP_Post $post`, `WP_REST_Request $request`

**`init_plugin_suite_view_count_api_top_cache_time`**  
Adjust cache time (in seconds) for `/top` results.  
**Applies to:** REST `/top`  
**Params:** `int $ttl`, `WP_REST_Request $request`

**`init_plugin_suite_view_count_api_top_max_number`**  
Maximum number of items `/top` returns per request (default `100`).  
**Applies to:** REST `/top`  
**Params:** `int $max`, `WP_REST_Request $request`

**`init_plugin_suite_view_count_ip_headers`**  
List of `$_SERVER` keys checked, in order, to detect the visitor IP for Strict IP check.  
**Applies to:** REST `/count` (Strict IP check)  
**Params:** `array $headers`

**`init_plugin_suite_view_count_meta_flush_interval`**  
Minimum number of seconds between two refreshes of a post's meta cache after counting views, when **Reduce view count cache refreshes** is enabled and a persistent object cache is active. Return `0` to refresh after every view.  
**Applies to:** REST `/count`  
**Params:** `int $interval` (already clamped to the 10–600 range of the setting)

**`init_plugin_suite_view_count_top_post_types`**  
Customize the list of post types returned by the `/top` endpoint.  
**Applies to:** REST `/top`  
**Params:** `array $post_types`, `WP_REST_Request $request`

**`init_plugin_suite_view_count_query_args`**  
Filter WP_Query args for `[init_view_list]` shortcode.  
**Applies to:** `[init_view_list]`  
**Params:** `array $args`, `array $atts`

**`init_plugin_suite_view_count_empty_output`**  
Customize the HTML output when no posts are found.  
**Applies to:** `[init_view_list]`  
**Params:** `string $output`, `array $atts`

**`init_plugin_suite_view_count_view_list_atts`**  
Modify shortcode attributes before WP_Query is run.  
**Applies to:** `[init_view_list]`  
**Params:** `array $atts`

**`init_plugin_suite_view_count_default_shortcode`**  
Customize the default shortcode used when auto-inserting view count into post content.  
**Applies to:** `[init_view_count]` auto-insert  
**Params:** `string $shortcode`

**`init_plugin_suite_view_count_auto_insert_enabled`**  
Control whether auto-insert is enabled for a given context.  
**Applies to:** `[init_view_count]` auto-insert  
**Params:** `bool $enabled`, `string $position`, `string $post_type`

**`init_plugin_suite_view_count_engagement_meta_keys`**  
Change the meta keys used to retrieve `like` and `share` counts when calculating engagement quality.  
**Applies to:** Engagement algorithm  
**Params:** `array $meta_keys` (`likes`, `shares`), `int $post_id`

**`init_plugin_suite_view_count_trending_post_types`**  
Override the list of post types used by the Trending cron calculation.  
**Applies to:** Cron Trending  
**Params:** `array $post_types`  

**`init_plugin_suite_view_count_trending_component_weights`**  
Adjust weights for Trending score components.  
**Applies to:** Trending algorithm  
**Params:** `array $weights` (`velocity`, `engagement`, `freshness`, `momentum`, `uplift`, `ewma`, `fatigue`, `explore`, `mmr`)

**`init_plugin_suite_view_count_shape_sample_rate`**  
Sample rate N used when **Sample Traffic Shape writes** is enabled: traffic shape data is recorded from 1 in N view requests, each sample weighted N times. Return `1` to record every request.  
**Applies to:** Traffic Shape Learner  
**Params:** `int $rate` (already clamped to the 2–100 range of the setting)

== Template Override ==

To customize output layout, copy any template file into your theme:

Example: `your-theme/init-view-count/view-list-grid.php`

== Frequently Asked Questions ==

= Can I customize the layout of the list? =  
Yes. Use the `template` attribute in `[init_view_list]` (e.g. `template="grid"`), and override the corresponding file in your theme like WooCommerce templates.

= Does it work with custom post types? =  
Yes. Just set `post_type="your_custom_type"` in the shortcode or REST query.

= How does it avoid duplicate views? =  
Init View Count uses both **time delay** and **scroll detection** via JavaScript, and stores viewed post IDs in either sessionStorage or localStorage (your choice).

= Is the view count updated immediately? =  
Yes, by default. When the scroll+delay conditions are met, the count is sent via REST API and saved with a single atomic database update. If you enable **Reduce view count cache refreshes**, views are still written to the database immediately, but the numbers displayed on your site may lag behind by up to the configured interval (see below).

= What meta key is used to store views? =  
By default:  
- `_init_view_count` (total)  
- `_init_view_day_count`  
- `_init_view_week_count`  
- `_init_view_month_count`  
These keys can be changed via the `init_plugin_suite_view_count_meta_key` filter.
Trending scores are calculated separately and stored in a transient.

= Can I display view counts in my template manually? =  
Yes. Use `get_post_meta($post_id, '_init_view_count', true)` or similar keys. Or use `[init_view_count]` shortcode in post content.

= Can I disable the built-in CSS? =  
Yes. There is an option in the plugin’s settings to disable the default stylesheet. You can style the output manually as needed.

= Is it compatible with caching plugins? =  
Yes. Since it uses JavaScript + REST for counting, page caching doesn't interfere. However, REST responses (`/top`) are cached using transients.

= Can I use it in block editor / Gutenberg? =  
Yes — since version 2.0.0 there are 3 native blocks (search "View Count", "Popular Posts List", "View Ranking" in the block inserter, under the "Init View Count" category), each with its own settings panel and live preview. You can still use the classic Shortcode block with `[init_view_count]` / `[init_view_list]` / `[init_view_ranking]` if you prefer.

= What is Abilities API support and do I need it? =  
Since version 2.0.0, on WordPress 6.9+ the plugin registers two **read-only** Abilities (`init-view-count/get-post-views` and `init-view-count/get-top-posts`) via the core Abilities API, so AI agents and automation tools can discover and query view-count data in a standardized way. This is entirely optional and has no effect on sites without the Abilities API (WordPress below 6.9) or on how the plugin otherwise works — no view/increment/reset ability is exposed.

= Does it track bots? =  
No. Since counting only happens after scroll and delay via JavaScript, bots like Googlebot are naturally excluded.

= Can I sort posts by views in WP_Query? =  
Yes. Use `'meta_key' => '_init_view_count'` and `'orderby' => 'meta_value_num'` in your `WP_Query` args.

= Can I reduce the number of view requests sent to the server? =
Yes. You can enable batch view tracking in the plugin settings. Instead of sending one request per view, views will be stored in the browser and sent in a group once the threshold is reached.

= Should I enable "Reduce view count cache refreshes"? =
Only if your site uses a persistent object cache (Redis, Memcached, etc.) and gets a lot of traffic. By default, each counted view clears the cached metadata of that post, so a popular post has to reload all of its metadata from the database again and again. With this option on, each post's cache is refreshed at most once per interval (60 seconds by default, 10–600 allowed). Every view is still written to the database right away and nothing is lost — only displayed numbers (shortcodes, blocks, rankings, Trending, the number shown after counting) may be slightly behind. A post that stops receiving views may keep showing a slightly lower number until it gets another view, is updated, or the daily reset runs (when daily views are enabled). Without a persistent object cache the option has no effect, and the settings page tells you so.

= Should I enable "Sample Traffic Shape writes"? =
Only on busy sites with Trending enabled. The Traffic Shape Learner (which teaches Trending your site's hourly and weekday rhythm) updates a database option on every view request. With sampling on, it records only 1 in every N requests (10 by default, 2–100 allowed) and counts each sample N times, so it writes about N times less often while the expected numbers stay the same. On low-traffic sites the learned pattern becomes noisier, so leave it off there. Post view counts are never sampled.

= Does "Strict IP check" affect performance? =
A little. It reads a transient on every view request and writes it back every time a view is counted. With a persistent object cache (Redis, Memcached) that happens in memory. Without one, transients live in the `wp_options` table, so each counted view adds up to two extra database writes. On busy sites without a persistent object cache, only enable it if you really need it.

= Should I enable "Require REST nonce verification"? =
It's optional and off by default. Enabling it makes the `/count` endpoint reject requests that don't carry a valid WordPress REST nonce, which helps block fake POST requests sent directly to the endpoint. However, WordPress nonces expire after roughly 12-24 hours. If your site uses full-page caching with a long TTL, cached pages will keep serving an old nonce and view counting will quietly stop working on those pages until the cache refreshes. Leave it off on sites with long-lived page caching, or make sure the cache is purged/refreshed regularly. The `/top` endpoint is unaffected either way, since it's read-only and public by design.

== Screenshots ==

1. Plugin settings page – configure post types, view types, delay, scroll check, and storage method.
2. Shortcode builder for [init_view_list] – generate view-based post lists with custom templates.
3. Shortcode builder for [init_view_ranking] – generate tabbed rankings for different view ranges.
4. Shortcode builder for [init_view_count] – display view count for current post with format options.
5. Frontend view – ranking display (all time), light mode interface.
6. Frontend view – ranking display (this week), dark mode interface.

== Changelog ==

= 2.0.3 – October 4, 2026 =
- New: **Reduce view count cache refreshes** option in Settings → Init View Count (off by default), with a configurable refresh interval (10–600 seconds, default 60). Until now, every counted view cleared the whole meta cache of that post (`wp_cache_delete( $post_id, 'post_meta' )`) — not just the view counters, but every meta value of the post. On sites with a persistent object cache, a popular post therefore had its cache wiped dozens of times per minute, and every page render right after had to reload all of the post's metadata from the database. With this option on, each post's meta cache is refreshed at most once per interval (a per-post lock via `wp_cache_add()`, atomic on Redis/Memcached). Views are still written to the database immediately with the same atomic `UPDATE`, so no view is ever lost or double-counted; only displayed numbers (shortcodes, blocks, rankings, Trending and the number returned after counting) may lag behind by up to the interval. A post that stops receiving views catches up on its next view, when it is updated, or at the daily reset (when daily views are enabled). The option only takes effect when a persistent object cache is active — on sites without one, cached metadata only lives for a single request, behavior stays exactly as before, and the settings page shows a notice explaining this
- New: filter `init_plugin_suite_view_count_meta_flush_interval` to adjust the refresh interval per site (return `0` to refresh after every view)
- New: **Sample Traffic Shape writes** option in Settings → Init View Count (off by default), with a configurable sample rate N (2–100, default 10). The Traffic Shape Learner used by Trending did one `get_option()` + `update_option()` on every view request — one extra database write per view, on every site. With sampling on, only 1 in N requests is recorded and each sample counts N times, so writes drop by about N times while the expected hourly bins and daily total stay the same (the daily minimum threshold and the hour/weekday EMA need no change). It also reduces concurrent requests overwriting each other's update of the same option. In a 200,000-request simulation at N = 10, writes went from 200,000 to ~20,000 and the recorded total was within 0.4% of the real one. On low-traffic sites the learned pattern becomes noisier, which the settings page explains. Post view counts are never sampled. Filterable via the new `init_plugin_suite_view_count_shape_sample_rate` filter (return `1` to record every request)
- New: helper functions `init_plugin_suite_view_count_get_meta_flush_interval()` and `init_plugin_suite_view_count_maybe_flush_meta_cache()` in `includes/utils.php`, and `init_plugin_suite_view_count_get_shape_sample_rate()` in `includes/traffic-shape.php`. `init_plugin_suite_view_count_flush_meta_cache()` is unchanged and still clears the cache unconditionally; the daily reset keeps using it, so rollover always refreshes the cache of affected posts
- Improvement: **Enable strict IP check?** now explains its performance cost in Settings: it reads a transient on every view request and writes it back on every counted view, which without a persistent object cache means up to two extra `wp_options` writes per counted view
- Improvement: uninstall also removes the four new options
- Docs: the FAQ entry "Is the view count updated immediately?" no longer mentions `update_post_meta()` (counts have been saved with a single atomic SQL update since 2.0.2); added FAQ entries for the two new options and for the performance cost of Strict IP check; documented the two new filters
- Docs: fixed outdated or incorrect information in `readme.txt` — the Block Editor and Abilities API FAQ entries said "since version 1.23" (both shipped in 2.0.0); `[init_view_count]` was described as only working inside a post loop and was missing its `id` attribute; `[init_view_list]` listed a default `number` of 5 (it is 10); `[init_view_ranking]` was missing its `post_type` attribute and the `yesterday`/`last_week`/`last_month` tabs; `GET /top` listed `post` as the default post type (it is `post` and `page`), was missing the `yesterday`/`last_week`/`last_month`/`trending` ranges and the 100-item cap; the `init_plugin_suite_view_count_trending_component_weights` filter listed only 4 of its 9 weights and was rendered on a single line
- Docs: removed changelog entries for 1.22 and earlier from `readme.txt`; the full history is available at the link below
- i18n: regenerated `init-view-count.pot` with WP-CLI; new strings translated in `init-view-count-vi.po`/`.mo`
- Code quality: all PHP files pass WordPress Coding Standards (`WordPress-Extra`) with zero errors and warnings

= 2.0.2 – September 26, 2026 =
- Security: the `init_plugin_suite_view_count_shape_reset` admin-post action (clears learned Traffic Shape data) had no capability check and no nonce, so any logged-in user (even a Subscriber), or a CSRF link, could wipe the learned data. It now requires `manage_options` and a valid nonce. A **Reset traffic shape data** button (with nonce) has been added to Settings → Init View Count. Custom links must now be built with `wp_nonce_url( admin_url( 'admin-post.php?action=init_plugin_suite_view_count_shape_reset' ), 'init_plugin_suite_view_count_shape_reset' )`
- Security: `GET /top` now only accepts post types that exist and are publicly viewable (`is_post_type_viewable()`), so the public endpoint can no longer expose titles/excerpts of internal post types such as `wp_block`. The `init_plugin_suite_view_count_top_post_types` filter still runs afterwards, so developers can explicitly add other types. `number` is capped at 100 (filterable via the new `init_plugin_suite_view_count_api_top_max_number` filter). An array passed as `terms` no longer causes a PHP fatal error
- Security: the `[init_view_ranking]` front-end script now builds items with DOM APIs (`textContent`) instead of injecting API strings into `innerHTML`, and only allows `http(s)` links/images
- Bug fix: on sites with a negative UTC offset (the Americas, etc.) the daily reset computed the day of week/month from a timestamp that had the timezone offset applied twice, so at 00:01 it thought it was still the previous day — weekly counters were reset on Tuesday instead of Monday and monthly counters on the 2nd instead of the 1st. All date math now uses real Unix timestamps with `wp_date()`. The `$now` value passed to the existing hooks/filters is unchanged
- Bug fix: the daily reset event now re-aligns itself to 00:01 site time after daylight-saving changes (WP-Cron's `daily` recurrence is a fixed 24h and used to drift to 23:01 or 01:01). Sites without DST are never touched
- Bug fix (Trending): post age was computed as "local timestamp minus GMT timestamp", adding the site's UTC offset to every post's age (e.g. +7 hours on GMT+7 sites), which skewed freshness boost and time decay. Age is now computed from real Unix timestamps. Old `trending_last_calculation` values stored in local time are handled safely on upgrade
- Bug fix (Trending): hot topics passed `term_taxonomy_id` values to `get_term()` as if they were term IDs. The two are not always equal (split/merged terms, migrated sites), so "hot" boosts could be attributed to the wrong category/tag. The query now returns term IDs and taxonomies directly
- Bug fix (Trending): the EWMA momentum component was stored in the non-persistent object cache, so on sites without Redis/Memcached it never carried over between runs and effectively did nothing. It now works on every site
- Bug fix: `[init_view_count time="true"]` showed "Posted 7 hours ago" for a just-published post on GMT+7 sites (GMT timestamp compared against a local timestamp)
- Bug fix: a single `POST /count` request containing the same post ID several times (e.g. `[5,5,5]`) incremented that post several times. Duplicate IDs in one request are now ignored
- Bug fix: `[init_view_ranking]` only initialised the first ranking block on a page; additional rankings stayed on the loading skeleton forever. Every ranking block is now initialised, and each keeps its own `post_type`
- Bug fix: the ranking script fell back to `/wp-json/` when the tracking script was not on the page (archive pages, the Dashboard widget), which broke on sites installed in a subdirectory or without pretty permalinks. The correct REST URL is now always provided
- Bug fix: the view-count tracking script stopped completely when the browser blocked `localStorage`/`sessionStorage` (Safari private mode, strict privacy settings). Storage access is now guarded with an in-page fallback
- Bug fix: block `className` values with multiple classes (e.g. `foo bar`) were merged into `foobar`; `[init_view_count class="..."]` now accepts multiple classes too
- Bug fix: block attributes containing square brackets or quotes (e.g. a list title `Top [2026]`) broke the generated shortcode. Blocks now call the shortcode callback directly with an attribute array (core `pre_do_shortcode_tag`/`do_shortcode_tag` filters still apply)
- Bug fix: auto-insert now only adds the view counter to the content of the post actually being viewed, not to other posts' content rendered on the same page, and not to excerpts generated from content (e.g. SEO meta descriptions)
- Bug fix: the front-end tracker now uses the queried post ID instead of `get_the_ID()`, so a theme/plugin that runs a secondary query before `wp_head` without resetting it can no longer make views count toward the wrong post
- Bug fix: the Shortcode Builder read the wrong localized object name, so "Copy", "Close" and "Shortcode Preview" were never translated. Copy now also works on non-HTTPS admin screens
- Bug fix: `init_plugin_suite_view_count_format_thousands()` could output `1000.0 K` (for 999,950) and `2.0 K`; it now outputs `1 M` and `2 K`
- Bug fix: turning on **Disable Trending** now also hides previously calculated trending data from `GET /top`, as the setting describes
- Performance: `POST /count` now increments all counters of a post (total/day/week/month) with a single atomic `UPDATE` instead of one query per counter — 2 queries per view instead of 5 in the common case. First-view inserts and stale-cache situations are still handled atomically
- Performance: the daily rollover now only writes rows that actually change. Posts with no views in the period whose "previous period" value is already 0 (the vast majority on large sites) are no longer deleted and re-inserted every day; existing rows are updated in place. In testing on 42 posts, a typical day went from 126 inserts + 129 deletes to 3 updates + 3 deletes. The end result in the database is identical to the previous version (verified against the old logic with randomized data)
- Performance: the hourly Trending calculation primes post, term and meta caches up front, reads comment counts from the post object, stores EWMA/score-cap/streak state in a single non-autoloaded option instead of hundreds of per-post transients, and the MMR diversity re-rank is now O(limit × n) instead of O(limit² × n) with identical output. In testing, one run went from ~590 queries to ~30
- Performance: `GET /top` primes featured-image caches in one query; its cache key ignores parameter order and cache-buster parameters (`_`, `no_cache`); `[init_view_list]` also primes featured images
- Performance: front-end scripts are loaded with the `defer` strategy; `fetch` uses `keepalive` so a view is still sent if the visitor leaves right as it qualifies
- Improvement: batch mode keeps queued IDs beyond the batch size for the next send instead of dropping them; the view number updates in every place the post's counter appears on the page
- Improvement: the View Count block uses the block context `postId` (Query Loop, FSE templates) when Post ID is 0
- Improvement: new filter `init_plugin_suite_view_count_ip_headers` to restrict which headers are trusted for Strict IP check (e.g. only `REMOTE_ADDR` on servers not behind a proxy/CDN)
- Improvement: cron events are removed on plugin deactivation; uninstall now also removes the yesterday/last week/last month meta, all plugin options and transients (including cached `/top` results, which the previous uninstall could not find), and runs on every site of a multisite network
- i18n: regenerated `init-view-count.pot` with WP-CLI; new strings translated in `init-view-count-vi.po`/`.mo`
- Code quality: all PHP files now pass WordPress Coding Standards (`WordPress-Extra`) with zero errors and warnings

= 2.0.1 – August 29, 2026 =
- Bug fix: on a site where the admin had never opened and saved Settings → Init View Count at least once, the day/week/month view counters would keep incrementing normally (that code path defaults to "enabled" when the option doesn't exist yet) but would **never be reset** by the daily cron (that code path had no default and treated the missing option as "disabled"). Reading `_init_view_day_count` directly would show it growing forever instead of rolling over into `_init_view_day_yesterday`. All reads of the day/week/month enable options now consistently default to enabled, matching the REST counting logic and the settings page's default-checked checkboxes
- Bug fix: setting **"Delay before counting"** to `0` did not actually count views immediately — it silently fell back to the default (15000ms) or, depending on the browser, sometimes did not count at all. Same issue affected **"Scroll percent required"** set to `0`. Root cause was a `value || fallback` pattern in the front-end script, which treats a valid `0` as falsy and always substitutes the default. Both options are now validated on save, enforced again at output time (so sites that already had `0` stored take effect without needing to re-save), and parsed correctly in the front-end script
- Bug fix: on pages shorter than the viewport (nothing to scroll), the scroll-percent check could never pass, because the scroll threshold was only ever evaluated inside the `scroll` event handler, and no `scroll` event fires when there is nothing to scroll. The script now also evaluates the scroll condition once immediately after the page settles, in addition to on scroll
- Change: **"Delay before counting"** is now clamped to a 100ms–600000ms (10 min) range, and **"Scroll percent required"** to a 1%–100% range. `0` is intentionally disallowed for both: a 0ms delay risks counting views before the page has actually rendered, and a 0% scroll requirement would silently disable the scroll check entirely
- Performance: the daily cron reset (`init_plugin_suite_view_count_reset_counts` — rolls today's/this week's/this month's view counts into yesterday/last-week/last-month and clears the counters, across every publicly-registered post type, no configuration needed) used to loop `get_post_meta()` + `update_post_meta()` + `delete_post_meta()` per post per counter, which on sites with many posts could mean tens of thousands of individual DB queries in a single cron run. It now performs the same rollover in batched, direct SQL (500 posts per query by default, filterable via `init_plugin_suite_view_count_reset_batch_size`), cutting query count from O(number of posts) to O(number of batches), then flushes the object cache for affected posts in one pass — using the object cache's batch-delete method when the active cache backend supports it (WordPress core does, since 6.0), and falling back to individual cache-delete calls otherwise for compatibility with third-party object cache drop-ins (Redis, Memcached, etc.) that may not implement it. Behavior is unchanged: every eligible post still ends up with a "previous period" value (0 if it had no views), preserving correct results in `GET /top?range=yesterday|last_week|last_month`; verified against the previous per-post logic across 1,000+ randomized scenarios before release
- Internal: extracted shared helper functions (`init_plugin_suite_view_count_atomic_increment()`, `init_plugin_suite_view_count_flush_meta_cache()`, IP-detection helpers, the K/M/B number formatter, and the new integer-clamping helper) out of `rest-api.php` and `shortcodes.php` into a new `includes/utils.php`, loaded first. No behavior change, purely a code-organization cleanup
- Internal: replaced `extract()` in the internal template-rendering helper with an explicit variable assignment for WPCS compliance (`WordPress.PHP.DontExtract`); the helper is only ever called with a single fixed key, so behavior is unchanged

= 2.0.0 – August 4, 2026 =
- **Breaking change: `Requires at least` is now WordPress 6.9.** This major version bump reflects two significant new features added in this release — Abilities API support and native Block Editor support (see below)
- New: **Abilities API support** (WordPress 6.9+). Registers two read-only abilities under the `init-view-count` category so AI agents and automation tools can discover and query view-count data through the standardized `wp_register_ability()` registry, without needing to know the plugin's REST routes:
  - `init-view-count/get-post-views` — returns the tracked view count for a single post (total/day/week/month)
  - `init-view-count/get-top-posts` — returns a ranked list of the most viewed posts (wraps the exact same logic as `GET /top` and the `[init_view_ranking]` shortcode, so results always match)
  - No write/destructive abilities are registered, by design — nothing can increment or reset view counts through this API
- New: **Block Editor (Gutenberg) support** with 3 dynamic blocks matching the existing shortcodes 1:1 — View Count, Popular Posts List, and View Ranking (Tabbed). Each block is a thin PHP wrapper (`render.php`, via the `render` field in `block.json`) that builds the same shortcode tag and calls `do_shortcode()`, so there is no duplicated display logic and output always matches the shortcode. The editor script is plain vanilla JavaScript (no build step, no JSX) using `ServerSideRender` for a live preview directly in the editor
- `Tested up to: 7.1`

View full changelog (all versions): [Init View Count – Changelog](https://en.inithtml.com/plugin/init-view-count/)

== License ==

This plugin is licensed under the GPLv2 or later.  
You are free to use, modify, and distribute it under the same license.
