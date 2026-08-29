<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('rest_api_init', function () {
    register_rest_route(INIT_PLUGIN_SUITE_VIEW_COUNT_NAMESPACE, '/count', [
        'methods'             => 'POST',
        'callback'            => 'init_plugin_suite_view_count_count_callback',
        'permission_callback' => 'init_plugin_suite_view_count_count_permission_callback',
    ]);

    // /top chỉ đọc dữ liệu công khai (danh sách bài viết xem nhiều) nên không áp dụng
    // kiểm tra nonce — vẫn luôn mở, kể cả khi "Require REST nonce verification?" được bật.
    register_rest_route(INIT_PLUGIN_SUITE_VIEW_COUNT_NAMESPACE, '/top', [
        'methods'             => 'GET',
        'callback'            => 'init_plugin_suite_view_count_top_callback',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Permission callback cho /count.
 *
 * Mặc định luôn cho phép (như hành vi cũ, vẫn public để hoạt động với mọi loại cache).
 * Khi admin bật option "init_plugin_suite_view_count_require_nonce", request bắt buộc
 * phải kèm header X-WP-Nonce hợp lệ (action 'wp_rest') do wp_localize_script() in ra —
 * giúp chặn bớt request spam POST thẳng vào endpoint mà không load trang trước.
 *
 * Lưu ý: nonce của WordPress hết hạn sau ~12-24h, nên nếu bật option này trên site dùng
 * full-page cache thời gian sống dài, các trang cache cũ sẽ mang nonce hết hạn và bị
 * endpoint từ chối cho tới khi cache được làm mới.
 *
 * @param WP_REST_Request $request Request hiện tại.
 * @return true|WP_Error
 */
function init_plugin_suite_view_count_count_permission_callback($request) {
    if ((int) get_option('init_plugin_suite_view_count_require_nonce', 0) !== 1) {
        return true;
    }

    $nonce = $request->get_header('X-WP-Nonce');

    if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
        return new WP_Error(
            'init_view_count_invalid_nonce',
            __('Invalid or expired security token.', 'init-view-count'),
            ['status' => 403]
        );
    }

    return true;
}

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
        $allowed = apply_filters(
            'init_plugin_suite_view_count_force_post_types',
            (array) get_option('init_plugin_suite_view_count_post_types', ['post'])
        );
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

        // Đọc giá trị hiện tại qua get_post_meta() (có object cache, rẻ — KHÔNG re-query sau khi ghi).
        // Việc +1 thực tế trong DB được thực hiện atomic bằng SQL thuần ở dưới để tránh
        // tương tranh (race condition) giữa nhiều request cùng lúc; giá trị trả về cho
        // client chỉ đơn giản là "giá trị đã đọc + 1" để tiết kiệm 1 query SELECT lại.
        $meta_total = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_count', $post_id);
        $views      = (int) get_post_meta($post_id, $meta_total, true) + 1;
        init_plugin_suite_view_count_atomic_increment($post_id, $meta_total);

        $updated['total']           = $views;
        $updated['total_formatted'] = number_format_i18n($views);
        $updated['total_short']     = init_plugin_suite_view_count_format_thousands($views);

        if ((int) get_option('init_plugin_suite_view_count_enable_day', 1) !== 0) {
            $meta_day = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count', $post_id);
            $updated['day'] = (int) get_post_meta($post_id, $meta_day, true) + 1;
            init_plugin_suite_view_count_atomic_increment($post_id, $meta_day);
        }

        if ((int) get_option('init_plugin_suite_view_count_enable_week', 1) !== 0) {
            $meta_week = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_week_count', $post_id);
            $updated['week'] = (int) get_post_meta($post_id, $meta_week, true) + 1;
            init_plugin_suite_view_count_atomic_increment($post_id, $meta_week);
        }

        if ((int) get_option('init_plugin_suite_view_count_enable_month', 1) !== 0) {
            $meta_month = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_month_count', $post_id);
            $updated['month'] = (int) get_post_meta($post_id, $meta_month, true) + 1;
            init_plugin_suite_view_count_atomic_increment($post_id, $meta_month);
        }

        // Toàn bộ meta key của post này vừa được ghi bằng SQL thuần (bypass cache) →
        // chỉ cần xoá cache đúng 1 lần cho post này, thay vì mỗi key xoá 1 lần.
        init_plugin_suite_view_count_flush_meta_cache($post_id);

        do_action('init_plugin_suite_view_count_after_counted', $post_id, $updated, $request);

        $results[] = $updated;
    }

    return rest_ensure_response($results);
}

function init_plugin_suite_view_count_top_callback($request) {
    $range         = $request->get_param('range') ?: 'total';
    $number        = absint($request->get_param('number')) ?: 5;
    $page          = max(1, absint($request->get_param('page')));
    $offset        = ($page - 1) * $number;
    
    $raw_post_type = $request->get_param('post_type') ?: ['post', 'page'];
    $post_type     = apply_filters('init_plugin_suite_view_count_top_post_types', (array) $raw_post_type, $request);

    $fields        = $request->get_param('fields') === 'minimal' ? 'minimal' : 'full';
    $no_cache      = $request->get_param('no_cache') === '1';

    $tax           = sanitize_key($request->get_param('tax'));
    $terms         = $request->get_param('terms');

    if ($range === 'trending') {
        $trending = get_transient('init_plugin_suite_view_count_trending');
        if (!is_array($trending) || empty($trending)) {
            return rest_ensure_response([]);
        }

        usort($trending, fn($a, $b) => $b['score'] <=> $a['score']);

        $sliced = array_slice($trending, $offset, $number);
        $ids    = wp_list_pluck($sliced, 'id');

        if (empty($ids)) {
            return rest_ensure_response([]);
        }

        $query = new WP_Query([
            'post__in'            => $ids,
            'orderby'             => 'post__in',
            'post_type'           => $post_type,
            'post_status'         => 'publish',
            'posts_per_page'      => count($ids),
            'no_found_rows'       => true,
            // Danh sách trending đã được xếp hạng sẵn theo score; không để WordPress
            // đẩy sticky post lên đầu làm sai thứ tự đã tính toán.
            'ignore_sticky_posts' => true,
        ]);

        $trending_map = [];
        foreach ($sliced as $i => $entry) {
            $trending_map[$entry['id']] = [
                'position' => $offset + $i + 1,
                'score'    => $entry['score'],
                'views'    => $entry['views'],
            ];
        }

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
        'day'         => '_init_view_day_count',
        'week'        => '_init_view_week_count',
        'month'       => '_init_view_month_count',
        'yesterday'   => '_init_view_day_yesterday',
        'last_week'   => '_init_view_week_last',
        'last_month'  => '_init_view_month_last',
    ];

    $meta_key = isset($meta_key_map[$range]) ? $meta_key_map[$range] : '_init_view_count';
    $meta_key = apply_filters('init_plugin_suite_view_count_meta_key', $meta_key, null);

    $cache_key = 'init_plugin_suite_view_count_top_' . md5(http_build_query($request->get_query_params()));
    if (!$no_cache && ($cached = get_transient($cache_key)) !== false) {
        return rest_ensure_response($cached);
    }

    $args = [
        'post_type'           => $post_type,
        'posts_per_page'      => $number,
        'offset'              => $offset,
        'post_status'         => 'publish',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_key'            => $meta_key,
        'orderby'             => 'meta_value_num',
        'order'               => 'DESC',
        'no_found_rows'       => true,
        // Đây là bảng xếp hạng theo lượt xem, không phải danh sách bài viết thường
        // → không để WordPress tự đẩy sticky post lên đầu bất kể lượt xem thực tế.
        'ignore_sticky_posts' => true,
    ];

    if ($tax && taxonomy_exists($tax) && $terms) {
        $term_array = array_map('sanitize_title', explode(',', $terms));
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
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
