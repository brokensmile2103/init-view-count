<?php
/**
 * Plugin Name: Init View Count
 * Description: Lightweight plugin to track real post views with scroll & delay detection, smart ranking, and flexible shortcodes.
 * Plugin URI: https://inithtml.com/plugin/init-view-count/
 * Version: 1.21
 * Author: Init HTML
 * Author URI: https://inithtml.com/
 * Text Domain: init-view-count
 * Domain Path: /languages
 * Requires at least: 5.9
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

// === Constants ===
define('INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION',   '1.21');
define('INIT_PLUGIN_SUITE_VIEW_COUNT_SLUG',      'init-view-count');
define('INIT_PLUGIN_SUITE_VIEW_COUNT_DIR',       plugin_dir_path(__FILE__));
define('INIT_PLUGIN_SUITE_VIEW_COUNT_URL',       plugin_dir_url(__FILE__));
define('INIT_PLUGIN_SUITE_VIEW_COUNT_NAMESPACE', 'initvico/v1');

// === Include core files ===
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/rest-api.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/traffic-shape.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/reset-schedule.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/shortcodes.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/hooks.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/settings-page.php';

if ( is_admin() ) {
    require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/dashboard-widget.php';
}

// === Enqueue CSS & JS ===
add_action('wp_enqueue_scripts', function () {
    if (!get_option('init_plugin_suite_view_count_disable_style')) {
        wp_enqueue_style(
            'init-plugin-suite-view-count-style',
            INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/css/style.css',
            [],
            INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION
        );
    }
    
    if (!is_singular()) {
        return;
    }

    $post_id = get_the_ID();
    $post_type = get_post_type($post_id);
    $allowed_types = (array) get_option('init_plugin_suite_view_count_post_types', ['post']);

    if (!in_array($post_type, $allowed_types, true)) {
        return;
    }

    wp_enqueue_script(
        'init-plugin-suite-view-count-script',
        INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/js/script.js',
        [],
        INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION,
        true
    );

    $config = [
        'post_id'       => $post_id,
        'restUrl'       => esc_url(rest_url(INIT_PLUGIN_SUITE_VIEW_COUNT_NAMESPACE)),
        'delay'         => (int) get_option('init_plugin_suite_view_count_delay', 15000),
        'scrollPercent' => (int) get_option('init_plugin_suite_view_count_scroll_percent', 75),
        'scrollEnabled' => ((int) get_option('init_plugin_suite_view_count_scroll_enabled', 1) === 1),
        'storage'       => get_option('init_plugin_suite_view_count_storage', 'session'),
        'batch'         => max(1, (int) get_option('init_plugin_suite_view_count_batch', 1)),
    ];

    // Chỉ in nonce ra khi admin bật "Require REST nonce verification?" trong Settings.
    // Mặc định TẮT vì nonce (wp_create_nonce('wp_rest')) hết hạn sau ~12-24h; nếu site
    // dùng full-page cache thời gian sống dài, HTML cache cũ sẽ mang theo nonce đã hết hạn
    // và request tới /count sẽ bị từ chối cho đến khi cache được làm mới.
    if ((int) get_option('init_plugin_suite_view_count_require_nonce', 0) === 1) {
        $config['nonce'] = wp_create_nonce('wp_rest');
    }

    $config = apply_filters('init_plugin_suite_view_count_localize_config', $config, $post_id);

    wp_localize_script('init-plugin-suite-view-count-script', 'InitViewCountSettings', $config);
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'init_plugin_suite_view_count_add_settings_link');
// Add a "Settings" link to the plugin row in the Plugins admin screen
function init_plugin_suite_view_count_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=init-view-count-settings') . '">' . __('Settings', 'init-view-count') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
