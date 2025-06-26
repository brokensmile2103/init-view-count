<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// === Delete plugin options ===
$option_prefix = 'init_plugin_suite_view_count_';
$options = [
    'auto_insert',
    'delay',
    'scroll_percent',
    'scroll_enabled',
    'storage',
    'post_types',
    'enable_day',
    'enable_week',
    'enable_month',
    'batch',
    'strict_ip_check',
    'enable_widget',
    'disable_style',
];

foreach ($options as $opt) {
    delete_option($option_prefix . $opt);
}

// === Delete all relevant post meta ===
$meta_keys = [
    '_init_view_count',
    '_init_view_day_count',
    '_init_view_week_count',
    '_init_view_month_count',
];

foreach ($meta_keys as $meta_key) {
    delete_post_meta_by_key($meta_key);
}

// === Delete cached trending data ===
delete_transient('init_plugin_suite_view_count_trending');

// === Delete all REST-based cached top lists ===
$all_options = wp_load_alloptions();
foreach ($all_options as $key => $value) {
    if (
        strpos($key, '_transient_init_plugin_suite_view_count_top_') === 0 ||
        strpos($key, '_transient_timeout_init_plugin_suite_view_count_top_') === 0
    ) {
        delete_option($key);
        wp_cache_delete($key, 'options');
    }
}

// === Unschedule cron events ===
wp_clear_scheduled_hook('init_plugin_suite_view_count_reset_counts');
wp_clear_scheduled_hook('init_plugin_suite_view_count_cron_update_trending');
