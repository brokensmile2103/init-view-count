<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Auto-insert view count shortcode into post content
 * Hooked into: the_content
 */

// Kiểm tra có nên auto chèn không
function init_plugin_suite_view_count_should_auto_insert($position) {
    if (!is_singular() || is_admin() || is_feed()) {
        return false;
    }

    $enabled_post_types = (array) get_option('init_plugin_suite_view_count_post_types', ['post']);
    $current_type = get_post_type();

    if (!in_array($current_type, $enabled_post_types, true)) {
        return false;
    }

    $selected = get_option('init_plugin_suite_view_count_auto_insert', 'none');
    if ($selected !== $position) {
        return false;
    }

    return apply_filters(
        'init_plugin_suite_view_count_auto_insert_enabled',
        true,
        $position,
        $current_type
    );
}

// Lấy shortcode mặc định (có thể override)
function init_plugin_suite_view_count_get_default_shortcode() {
    return apply_filters(
        'init_plugin_suite_view_count_default_shortcode',
        '[init_view_count icon="true" format="short"]'
    );
}

// Hook vào nội dung
add_filter('the_content', function ($content) {
    if (init_plugin_suite_view_count_should_auto_insert('before_content')) {
        return do_shortcode(init_plugin_suite_view_count_get_default_shortcode()) . "\n\n" . $content;
    }

    if (init_plugin_suite_view_count_should_auto_insert('after_content')) {
        return $content . "\n\n" . do_shortcode(init_plugin_suite_view_count_get_default_shortcode());
    }

    return $content;
}, 20);
