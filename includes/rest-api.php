<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('rest_api_init', function () {
    register_rest_route('initvico/v1', '/count', [
        'methods'             => 'POST',
        'callback'            => 'init_plugin_suite_view_count_count_callback',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('initvico/v1', '/top', [
        'methods'             => 'GET',
        'callback'            => 'init_plugin_suite_view_count_top_callback',
        'permission_callback' => '__return_true',
    ]);
});

function init_plugin_suite_view_count_count_callback($request) {
    $ids        = $request->get_param('post_id');
    $post_ids   = is_array($ids) ? array_map('absint', $ids) : [absint($ids)];
    $limit      = max(1, absint(get_option('init_plugin_suite_view_count_batch', 1)));
    $post_ids   = array_slice($post_ids, 0, $limit);
    $results    = [];

    foreach ($post_ids as $post_id) {
        if (!$post_id || get_post_status($post_id) !== 'publish') {
            $results[] = [
                'post_id' => $post_id,
                'error'   => __('Invalid post ID.', 'init-view-count'),
            ];
            continue;
        }

        $post_type = get_post_type($post_id);
        $allowed   = (array) get_option('init_plugin_suite_view_count_post_types', ['post']);
        if (!in_array($post_type, $allowed, true)) {
            $results[] = [
                'post_id' => $post_id,
                'error'   => __('Not enabled for view counting.', 'init-view-count'),
            ];
            continue;
        }

        if ( get_option('init_plugin_suite_view_count_strict_ip_check', 0) ) {
            if ( init_plugin_suite_view_count_is_ip_recent( $post_id ) ) {
                $results[] = [
                    'post_id' => $post_id,
                    'skipped' => true,
                    'reason'  => 'ip_duplicate',
                ];
                continue;
            }
        }

        if (!apply_filters('init_plugin_suite_view_count_should_count', true, $post_id, $request)) {
            $results[] = [
                'post_id' => $post_id,
                'skipped' => true,
            ];
            continue;
        }

        $updated = ['post_id' => $post_id];
        $meta_total = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_count', $post_id);
        $views = (int) get_post_meta($post_id, $meta_total, true);
        update_post_meta($post_id, $meta_total, ++$views);

        $updated['total']           = $views;
        $updated['total_formatted'] = number_format_i18n($views);
        $updated['total_short']     = init_plugin_suite_view_count_format_thousands($views);

        if (get_option('init_plugin_suite_view_count_enable_day')) {
            $meta_day = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count', $post_id);
            $views_day = (int) get_post_meta($post_id, $meta_day, true);
            update_post_meta($post_id, $meta_day, ++$views_day);
            $updated['day'] = $views_day;
        }

        if (get_option('init_plugin_suite_view_count_enable_week')) {
            $meta_week = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_week_count', $post_id);
            $views_week = (int) get_post_meta($post_id, $meta_week, true);
            update_post_meta($post_id, $meta_week, ++$views_week);
            $updated['week'] = $views_week;
        }

        if (get_option('init_plugin_suite_view_count_enable_month')) {
            $meta_month = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_month_count', $post_id);
            $views_month = (int) get_post_meta($post_id, $meta_month, true);
            update_post_meta($post_id, $meta_month, ++$views_month);
            $updated['month'] = $views_month;
        }

        do_action('init_plugin_suite_view_count_after_counted', $post_id, $updated, $request);

        $results[] = $updated;
    }

    return rest_ensure_response($results);
}

function init_plugin_suite_view_count_is_ip_recent( $post_id ) {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $ip = filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP );
    if ( ! $ip ) {
        return false;
    }

    $hash = base_convert( sprintf('%u', crc32($ip) ), 10, 36 );
    $key  = 'ivc_recent_ips_' . $post_id;

    $list = get_transient( $key );
    if ( ! is_array( $list ) ) {
        $list = [];
    }

    if ( in_array( $hash, $list, true ) ) {
        return true;
    }

    array_unshift( $list, $hash );
    if ( count( $list ) > 75 ) {
        array_pop( $list );
    }

    set_transient( $key, $list, WEEK_IN_SECONDS * 2 );

    return false;
}

function init_plugin_suite_view_count_top_callback($request) {
    $range     = $request->get_param('range') ?: 'total';
    $number    = absint($request->get_param('number')) ?: 5;
    $page      = max(1, absint($request->get_param('page')));
    $offset    = ($page - 1) * $number;
    $post_type = $request->get_param('post_type') ?: ['post', 'page'];
    $fields    = $request->get_param('fields') === 'minimal' ? 'minimal' : 'full';
    $no_cache  = $request->get_param('no_cache') === '1';

    $tax   = sanitize_key($request->get_param('tax'));
    $terms = $request->get_param('terms');

    if ($range === 'trending') {
        $trending = get_transient('init_plugin_suite_view_count_trending');
        if (!is_array($trending) || empty($trending)) {
            return rest_ensure_response([]);
        }

        // Sort theo score nếu cần
        usort($trending, fn($a, $b) => $b['score'] <=> $a['score']);

        // Lấy slice phân trang
        $sliced = array_slice($trending, $offset, $number);
        $ids    = wp_list_pluck($sliced, 'id');

        if (empty($ids)) {
            return rest_ensure_response([]);
        }

        // Lấy bài viết
        $query = new WP_Query([
            'post__in'       => $ids,
            'orderby'        => 'post__in', // vẫn cần để tránh rối thứ tự
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => count($ids),
            'no_found_rows'  => true,
        ]);

        // Dựng map trending nhanh
        $trending_map = [];
        foreach ($sliced as $i => $entry) {
            $trending_map[$entry['id']] = [
                'position' => $offset + $i + 1,
                'score'    => $entry['score'],
                'views'    => $entry['views'],
            ];
        }

        // Chuẩn hoá kết quả
        $results = [];
        foreach ($query->posts as $post) {
            $base = [
                'id'    => $post->ID,
                'title' => get_the_title($post),
                'link'  => get_permalink($post),
            ];

            if ($fields === 'minimal') {
                $results[] = $base;
                continue;
            }

            $post_type_slug = get_post_type($post);
            $post_type_obj  = get_post_type_object($post_type_slug);
            $post_type_name = $post_type_obj ? $post_type_obj->labels->singular_name : $post_type_slug;

            $taxonomy = apply_filters('init_plugin_suite_live_search_category_taxonomy', 'category', $post->ID);
            $category = get_the_terms($post->ID, $taxonomy);
            $category_name = ($category && !is_wp_error($category)) ? $category[0]->name : '';

            $entry = $trending_map[$post->ID] ?? ['position' => null, 'score' => null, 'views' => null];
            $meta_total = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_count', $post->ID);

            $results[] = apply_filters('init_plugin_suite_view_count_api_top_item', array_merge($base, [
                'excerpt'           => get_the_excerpt($post),
                'views'             => (int) get_post_meta($post->ID, $meta_total, true),
                'thumbnail'         => get_the_post_thumbnail_url($post, 'thumbnail') ?: INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/img/thumbnail.svg',
                'post_type'         => $post_type_slug,
                'type'              => $post_type_name,
                'category'          => apply_filters('init_plugin_suite_live_search_category', $category_name, $post->ID),
                'date'              => get_the_date('', $post),
                'trending'          => true,
                'trending_position' => $entry['position'],
                'trending_score'    => $entry['score'],
                'trending_views'    => $entry['views'],
            ]), $post, $request);
        }

        return rest_ensure_response($results);
    }

    $meta_key_map = [
        'day'   => '_init_view_day_count',
        'week'  => '_init_view_week_count',
        'month' => '_init_view_month_count',
    ];

    $meta_key = isset($meta_key_map[$range]) ? $meta_key_map[$range] : '_init_view_count';
    $meta_key = apply_filters('init_plugin_suite_view_count_meta_key', $meta_key, null);

    $cache_key = 'init_plugin_suite_view_count_top_' . md5(http_build_query($request->get_query_params()));
    if (!$no_cache && ($cached = get_transient($cache_key)) !== false) {
        return rest_ensure_response($cached);
    }

    $args = [
        'post_type'      => $post_type,
        'posts_per_page' => $number,
        'offset'         => $offset,
        'post_status'    => 'publish',
        'meta_key'       => $meta_key,
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ];

    if ($tax && taxonomy_exists($tax) && $terms) {
        $term_array = array_map('sanitize_title', explode(',', $terms));
        $args['tax_query'] = [[
            'taxonomy' => $tax,
            'field'    => is_numeric($term_array[0]) ? 'term_id' : 'slug',
            'terms'    => $term_array,
            'operator' => 'IN',
        ]];
    }

    $args = apply_filters('init_plugin_suite_view_count_api_top_args', $args, $request);
    $query = new WP_Query($args);
    $results = [];

    foreach ($query->posts as $post) {
        $item = [
            'id'    => $post->ID,
            'title' => get_the_title($post),
            'link'  => get_permalink($post),
        ];

        if ($fields === 'minimal') {
            $results[] = $item;
        } else {
            $post_type_slug = get_post_type($post);
            $post_type_obj  = get_post_type_object($post_type_slug);
            $post_type_name = $post_type_obj ? $post_type_obj->labels->singular_name : $post_type_slug;

            $taxonomy = apply_filters('init_plugin_suite_live_search_category_taxonomy', 'category', $post->ID);
            $category = get_the_terms($post->ID, $taxonomy);
            $category_name = ($category && !is_wp_error($category)) ? $category[0]->name : '';

            $full_item = apply_filters('init_plugin_suite_view_count_api_top_item', array_merge($item, [
                'excerpt'   => get_the_excerpt($post),
                'views'     => (int) get_post_meta($post->ID, $meta_key, true),
                'thumbnail' => get_the_post_thumbnail_url($post, 'thumbnail') ?: INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/img/thumbnail.svg',
                'post_type' => $post_type_slug,
                'type'      => $post_type_name,
                'category'  => apply_filters('init_plugin_suite_live_search_category', $category_name, $post->ID),
                'date'      => get_the_date('', $post),
            ]), $post, $request);

            $results[] = $full_item;
        }
    }

    // Append trending data if available
    if ($fields !== 'minimal') {
        $trending = get_transient('init_plugin_suite_view_count_trending');
        if (is_array($trending)) {
            $map = [];
            foreach ($trending as $i => $entry) {
                $map[$entry['id']] = [
                    'position' => $i + 1,
                    'score'    => $entry['score'],
                    'views'    => $entry['views'],
                ];
            }

            foreach ($results as &$item) {
                if (isset($map[$item['id']])) {
                    $item['trending'] = true;
                    $item['trending_position'] = $map[$item['id']]['position'];
                    $item['trending_score']    = $map[$item['id']]['score'];
                    $item['trending_views']    = $map[$item['id']]['views'];
                }
            }
            unset($item);
        }
    }

    if (!$no_cache) {
        $ttl = apply_filters('init_plugin_suite_view_count_api_top_cache_time', 5 * MINUTE_IN_SECONDS, $request);
        set_transient($cache_key, $results, $ttl);
    }

    return rest_ensure_response($results);
}
