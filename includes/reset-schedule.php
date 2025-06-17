<?php
// Schedule the daily reset cron at 00:01 if not already scheduled.

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('init_plugin_suite_view_count_reset_counts', 'init_plugin_suite_view_count_reset_counts');
add_action('init_plugin_suite_view_count_cron_update_trending', 'init_plugin_suite_view_count_cron_update_trending');

add_action('init', function () {
    // Reset view counts hàng ngày lúc 00:01
    if (!wp_next_scheduled('init_plugin_suite_view_count_reset_counts')) {
        $timestamp = strtotime('tomorrow 00:01', current_time('timestamp'));
        wp_schedule_event($timestamp, 'daily', 'init_plugin_suite_view_count_reset_counts');
    }

    // Cron update trending mỗi giờ
    if (!wp_next_scheduled('init_plugin_suite_view_count_cron_update_trending')) {
        wp_schedule_event(time(), 'hourly', 'init_plugin_suite_view_count_cron_update_trending');
    }
});

// === DAILY CRON RESET ===

function init_plugin_suite_view_count_reset_counts() {
    $post_types = array_unique(array_filter(array_map('sanitize_key', get_post_types(['public' => true]))));

    $args = [
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ];

    $posts = get_posts($args);
    if (empty($posts)) return;

    $now = current_time('timestamp');
    $day_of_week = (int) wp_date('w', $now);   // 0 = Sunday, 1 = Monday, ...
    $day_of_month = (int) wp_date('j', $now);  // 1 = first day

    foreach ($posts as $post_id) {
        if (get_option('init_plugin_suite_view_count_enable_day')) {
            $meta_day = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count', $post_id);
            delete_post_meta($post_id, $meta_day);
        }

        if (get_option('init_plugin_suite_view_count_enable_week') && $day_of_week === 1) {
            $meta_week = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_week_count', $post_id);
            delete_post_meta($post_id, $meta_week);
        }

        if (get_option('init_plugin_suite_view_count_enable_month') && $day_of_month === 1) {
            $meta_month = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_month_count', $post_id);
            delete_post_meta($post_id, $meta_month);
        }
    }
}

// === CRON: UPDATE TRENDING ===

function init_plugin_suite_view_count_cron_update_trending() {
    $meta_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count', null);

    $query = new WP_Query([
        'post_type'      => get_post_types(['public' => true]),
        'posts_per_page' => 100,
        'meta_key'       => $meta_key,
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'post_status'    => 'publish',
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ]);

    if (!empty($query->posts)) {
        init_plugin_suite_view_count_calculate_trending($query->posts);
    }
}

function init_plugin_suite_view_count_calculate_trending(array $post_ids) {
    $trending = [];
    $now      = current_time('timestamp');

    foreach ($post_ids as $post_id) {
        $meta_key  = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count', $post_id);
        $views_day = (int) get_post_meta($post_id, $meta_key, true);
        if ($views_day < 1) {
            continue;
        }

        $post_timestamp = get_post_time('U', true, $post_id);
        if (!$post_timestamp || $post_timestamp <= 0) {
            continue;
        }

        $age_hours = max(1, ($now - $post_timestamp) / 3600);
        $score     = $views_day / $age_hours;

        $trending[] = [
            'id'    => $post_id,
            'score' => $score,
            'views' => $views_day,
            'time'  => $now,
        ];
    }

    usort($trending, fn($a, $b) => $b['score'] <=> $a['score']);
    $top = array_slice($trending, 0, 20);

    set_transient('init_plugin_suite_view_count_trending', $top, DAY_IN_SECONDS);
}
