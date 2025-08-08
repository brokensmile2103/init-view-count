<?php
// Schedule the daily reset cron at 00:01 if not already scheduled.

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('init_plugin_suite_view_count_reset_counts', 'init_plugin_suite_view_count_reset_counts');
add_action('init_plugin_suite_view_count_cron_update_trending', 'init_plugin_suite_view_count_cron_update_trending');

add_action('init', function () {
    // Reset view counts hàng ngày lúc 00:01
    if (!wp_next_scheduled('init_plugin_suite_view_count_reset_counts')) {
        $site_timezone = wp_timezone();
        $dt = new DateTime('tomorrow 00:01', $site_timezone);
        $timestamp = $dt->getTimestamp();

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

    // Sanitize & fallback post types với validation chặt
    $post_types = (array) get_option('init_plugin_suite_view_count_post_types', ['post']);
    $post_types = array_unique(array_filter(array_map('sanitize_key', $post_types)));
    $post_types = array_diff($post_types, ['attachment']);
    $post_types = apply_filters('init_plugin_suite_view_count_trending_post_types', $post_types);
    
    if (empty($post_types)) {
        $post_types = ['post']; // Fallback safety
    }

    $query = new WP_Query([
        'post_type'      => $post_types,
        'posts_per_page' => 100,
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
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
    $lock_key = 'trending_calculation_lock';
    $lock_group = 'init_ps';
    
    // Race condition protection với lock + fixed group
    if (!wp_cache_add($lock_key, time(), $lock_group, 300)) {
        // Nếu không thể tạo lock, return cached result
        return get_transient('init_plugin_suite_view_count_trending') ?: [];
    }

    $trending = [];
    $now = current_time('timestamp');
    
    $last_run = get_transient('trending_last_calculation');
    if ($last_run && ($now - $last_run) < 3300) {
        wp_cache_delete($lock_key, $lock_group);
        return get_transient('init_plugin_suite_view_count_trending') ?: [];
    }

    $view_cache = [];
    $post_cache = [];

    foreach ($post_ids as $post_id) {
        $day_meta_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count', $post_id);
        $week_meta_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_week_count', $post_id);
        $month_meta_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_month_count', $post_id);
        $total_meta_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_count', $post_id);

        $view_cache[$post_id] = [
            'day' => (int) get_post_meta($post_id, $day_meta_key, true),
            'week' => (int) get_post_meta($post_id, $week_meta_key, true),
            'month' => (int) get_post_meta($post_id, $month_meta_key, true),
            'total' => (int) get_post_meta($post_id, $total_meta_key, true),
        ];

        $post = get_post($post_id);
        if ($post) {
            // Cache tất cả data cần thiết để tránh N+1 queries sau này
            $post_cache[$post_id] = [
                'timestamp' => get_post_time('U', true, $post_id),
                'category' => wp_get_post_categories($post_id, ['fields' => 'ids']),
                'tags' => wp_get_post_tags($post_id, ['fields' => 'ids']),
                'author_id' => (int) $post->post_author, // Cache author_id luôn
            ];
        }
    }

    // Get configurable weights với safe defaults
    $weights = wp_parse_args(
        apply_filters('init_plugin_suite_view_count_trending_component_weights', []),
        [
            'velocity' => 1.0,
            'engagement' => 1.0, 
            'freshness' => 1.0,
            'momentum' => 1.0
        ]
    );

    foreach ($post_ids as $post_id) {
        $views = $view_cache[$post_id];
        $post_data = $post_cache[$post_id] ?? null;

        if (!$post_data || $views['day'] < 1) continue;

        $post_timestamp = $post_data['timestamp'];
        if (!$post_timestamp || $post_timestamp <= 0) continue;

        $age_hours = max(0.5, ($now - $post_timestamp) / 3600);

        $velocity_score = init_plugin_suite_view_count_calculate_velocity_score($views, $age_hours);
        $time_decay = init_plugin_suite_view_count_calculate_time_decay($age_hours);
        $engagement_quality = init_plugin_suite_view_count_calculate_engagement_quality($post_id, $views);
        $freshness_boost = init_plugin_suite_view_count_calculate_freshness_boost($age_hours);
        $category_momentum = init_plugin_suite_view_count_calculate_category_momentum($post_data['category'], $post_data['tags']);

        // Apply configurable weights
        $base_score = $velocity_score * $time_decay;
        $final_score = $base_score * 
                      pow($engagement_quality, $weights['engagement']) * 
                      pow($freshness_boost, $weights['freshness']) * 
                      pow($category_momentum, $weights['momentum']);

        // Soft cap thay vì hard cap
        $normalized_score = 10000 * (1 - exp(-$final_score / 5000));

        $trending[] = [
            'id' => $post_id,
            'score' => round($normalized_score, 4),
            'views' => $views['day'],
            'views_day' => $views['day'],
            'views_week' => $views['week'],
            'views_month' => $views['month'],
            'views_total' => $views['total'],
            'age_hours' => round($age_hours, 2),
            'time' => $now,
            'author_id' => $post_data['author_id'], // Cache author_id cho diversity
            'categories' => $post_data['category'], // Cache categories cho diversity
            'components' => [
                'velocity' => round($velocity_score, 4),
                'time_decay' => round($time_decay, 4),
                'engagement' => round($engagement_quality, 4),
                'freshness' => round($freshness_boost, 4),
                'momentum' => round($category_momentum, 4),
            ]
        ];
    }

    usort($trending, fn($a, $b) => $b['score'] <=> $a['score']);
    $top_trending = init_plugin_suite_view_count_apply_diversity_filter($trending, 20);

    set_transient('init_plugin_suite_view_count_trending', $top_trending, DAY_IN_SECONDS);
    set_transient('init_plugin_suite_view_count_trending_debug', array_slice($trending, 0, 50), DAY_IN_SECONDS);
    set_transient('trending_last_calculation', $now, DAY_IN_SECONDS);

    // Clean up lock với group
    wp_cache_delete($lock_key, $lock_group);

    return $top_trending;
}

// Tính Velocity Score - Tốc độ tăng trưởng lượt xem
function init_plugin_suite_view_count_calculate_velocity_score($views, $age_hours) {
    $day_views = $views['day'];
    $week_views = $views['week'];
    $month_views = $views['month'];
    $total_views = $views['total'];
    
    // Tính tỷ lệ views trong ngày so với tuần
    $daily_ratio = $week_views > 0 ? ($day_views / $week_views) : 0;
    
    // Tính acceleration - so sánh day vs week vs month
    $weekly_avg = $week_views > 0 ? $week_views / 7 : 0;
    $monthly_avg = $month_views > 0 ? $month_views / 30 : 0;
    
    $acceleration = 1.0;
    if ($weekly_avg > 0 && $day_views > $weekly_avg) {
        $acceleration += ($day_views / $weekly_avg - 1) * 0.3;
    }
    if ($monthly_avg > 0 && $weekly_avg > $monthly_avg) {
        $acceleration += ($weekly_avg / $monthly_avg - 1) * 0.2;
    }
    
    // Base velocity: views per hour
    $base_velocity = $day_views / min($age_hours, 24);
    
    // Áp dụng logarithmic scaling để tránh bias cho posts có views cực cao
    $scaled_velocity = log(1 + $base_velocity) * 10;
    
    return $scaled_velocity * $acceleration;
}

// Time Decay - Giảm điểm theo thời gian (optimized for 1h cron)
function init_plugin_suite_view_count_calculate_time_decay($age_hours) {
    // Adjusted cho cron interval 1h - decay gentle hơn ban đầu
    if ($age_hours <= 2) {
        return 1.0; // No decay cho 2h đầu
    }
    
    // Decay factor: giảm 50% sau 36h thay vì 24h
    $decay_rate = 0.693; // ln(2)
    $half_life = 36; // hours - tăng từ 24h lên 36h
    
    return exp(-$decay_rate * ($age_hours - 2) / $half_life);
}

// Engagement Quality - Chất lượng tương tác (với smoothing)
function init_plugin_suite_view_count_calculate_engagement_quality($post_id, $views) {
    // Lấy số comment
    $comments_count = wp_count_comments($post_id)->approved ?? 0;

    // Áp dụng filter để cho phép thay đổi meta key của like và share
    $meta_keys = apply_filters('init_plugin_suite_view_count_engagement_meta_keys', [
        'likes'  => '_likes_count',
        'shares' => '_shares_count',
    ]);

    $likes_count  = (int) get_post_meta($post_id, $meta_keys['likes'], true);
    $shares_count = (int) get_post_meta($post_id, $meta_keys['shares'], true);

    // Smoothing: tránh chia cho số quá nhỏ
    $total_views = max(5, $views['day']);

    // Tính engagement rate
    $engagement_actions = $comments_count + $likes_count + $shares_count;
    $engagement_rate = $engagement_actions / $total_views;

    // Convert to multiplier (1.0 - 2.0)
    $quality_multiplier = 1 + min($engagement_rate * 10, 1.0);

    return $quality_multiplier;
}

// Freshness Boost - Boost cho content mới (optimized for 1h cron)
function init_plugin_suite_view_count_calculate_freshness_boost($age_hours) {
    // Adjusted cho cron 1h/lần - boost spread out hơn
    if ($age_hours <= 1) {
        return 1.8; // 80% boost cho content cực mới
    } elseif ($age_hours <= 3) {
        return 1.4; // 40% boost
    } elseif ($age_hours <= 6) {
        return 1.2; // 20% boost
    } elseif ($age_hours <= 12) {
        return 1.1; // 10% boost
    } elseif ($age_hours <= 24) {
        return 1.05; // 5% boost
    }
    
    return 1.0; // Không boost
}

// Category Momentum - Xu hướng theo chủ đề
function init_plugin_suite_view_count_calculate_category_momentum($categories, $tags) {
    static $hot_topics_cache = null;
    
    if ($hot_topics_cache === null) {
        // Lấy danh sách chủ đề hot trong 24h qua
        $hot_topics_cache = init_plugin_suite_view_count_get_hot_topics_last_24h();
    }
    
    $momentum_boost = 1.0;
    
    // Check categories
    foreach ($categories as $cat_id) {
        if (isset($hot_topics_cache['categories'][$cat_id])) {
            $momentum_boost *= (1 + $hot_topics_cache['categories'][$cat_id] * 0.1);
        }
    }
    
    // Check tags
    foreach ($tags as $tag_id) {
        if (isset($hot_topics_cache['tags'][$tag_id])) {
            $momentum_boost *= (1 + $hot_topics_cache['tags'][$tag_id] * 0.05);
        }
    }
    
    return min($momentum_boost, 1.5); // Cap tối đa 50%
}

// Diversity Filter - Đảm bảo đa dạng content (với fill-back O(n) optimized)
function init_plugin_suite_view_count_apply_diversity_filter($trending_posts, $limit) {
    $selected = [];
    $chosen = []; // Track selected IDs for O(1) lookup
    $category_count = [];
    $author_count = [];

    // Sử dụng cached data thay vì query lại
    $all_authors = [];
    $all_categories = [];

    foreach ($trending_posts as $post) {
        // Dùng cached author_id thay vì get_post_field
        $author_id = $post['author_id'];
        $all_authors[$author_id] = true;

        // Dùng cached categories thay vì wp_get_post_categories
        $cats = $post['categories'];
        foreach ($cats as $cat_id) {
            $all_categories[$cat_id] = true;
        }
    }

    $author_count_total = count($all_authors);
    $category_count_total = count($all_categories);

    // Giới hạn mặc định
    $max_per_author = 2;
    $max_per_category = 3;

    // Nếu số lượng author * max_per_author < limit → không nên áp dụng giới hạn
    if ($author_count_total * $max_per_author < $limit) {
        $max_per_author = $limit; // effectively unlimited
    }

    if ($category_count_total * $max_per_category < $limit) {
        $max_per_category = $limit;
    }

    // Pass 1: Apply diversity filters
    foreach ($trending_posts as $post) {
        $author_id = $post['author_id'];
        $cats = $post['categories'];

        $author_ok = ($author_count[$author_id] ?? 0) < $max_per_author;

        $category_ok = true;
        foreach ($cats as $cat_id) {
            if (($category_count[$cat_id] ?? 0) >= $max_per_category) {
                $category_ok = false;
                break;
            }
        }

        if ($author_ok && $category_ok) {
            $selected[] = $post;
            $chosen[$post['id']] = true; // Track cho pass 2

            $author_count[$author_id] = ($author_count[$author_id] ?? 0) + 1;
            foreach ($cats as $cat_id) {
                $category_count[$cat_id] = ($category_count[$cat_id] ?? 0) + 1;
            }

            if (count($selected) >= $limit) break;
        }
    }

    // Pass 2: Fill remaining slots nếu chưa đủ (O(n) optimized)
    if (count($selected) < $limit) {
        foreach ($trending_posts as $post) {
            // O(1) lookup thay vì O(n) loop
            if (isset($chosen[$post['id']])) continue;
            
            $selected[] = $post;
            if (count($selected) >= $limit) break;
        }
    }

    return $selected;
}

// Lấy hot topics trong 24h (Fixed SQL + Timezone)
function init_plugin_suite_view_count_get_hot_topics_last_24h() {
    $cached = get_transient('hot_topics_24h');
    if ($cached !== false) {
        return $cached;
    }
    
    global $wpdb;
    
    // Fixed: Dùng GMT timestamp và bỏ tt.taxonomy khỏi SELECT
    $day_meta_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count', 0);
    $gmt_24h_ago = gmdate('Y-m-d H:i:s', current_time('timestamp', 1) - DAY_IN_SECONDS);
    
    $sql = "
        SELECT p.ID,
               pm_day.meta_value as day_views,
               GROUP_CONCAT(DISTINCT tr.term_taxonomy_id) as term_ids
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_day ON p.ID = pm_day.post_id AND pm_day.meta_key = %s
        LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
            AND tt.taxonomy IN ('category', 'post_tag')
        WHERE p.post_status = 'publish'
        AND p.post_date_gmt >= %s
        AND CAST(pm_day.meta_value AS UNSIGNED) > 0
        GROUP BY p.ID
        ORDER BY CAST(pm_day.meta_value AS UNSIGNED) DESC
        LIMIT 100
    ";
    
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results($wpdb->prepare($sql, $day_meta_key, $gmt_24h_ago));
    $hot_topics = ['categories' => [], 'tags' => []];
    
    foreach ($results as $row) {
        if (!$row->term_ids) continue;
        
        $term_ids = explode(',', $row->term_ids);
        $views = (int) $row->day_views;
        
        foreach ($term_ids as $term_id) {
            $term_id = (int) $term_id;
            $term = get_term($term_id);
            
            if (!$term || is_wp_error($term)) continue;
            
            $taxonomy = $term->taxonomy;
            
            if ($taxonomy === 'category') {
                $hot_topics['categories'][$term_id] = ($hot_topics['categories'][$term_id] ?? 0) + $views;
            } elseif ($taxonomy === 'post_tag') {
                $hot_topics['tags'][$term_id] = ($hot_topics['tags'][$term_id] ?? 0) + $views;
            }
        }
    }
    
    // Normalize scores (0-1)
    $max_cat_views = max(array_values($hot_topics['categories']) ?: [1]);
    $max_tag_views = max(array_values($hot_topics['tags']) ?: [1]);
    
    foreach ($hot_topics['categories'] as $id => $views) {
        $hot_topics['categories'][$id] = $views / $max_cat_views;
    }
    
    foreach ($hot_topics['tags'] as $id => $views) {
        $hot_topics['tags'][$id] = $views / $max_tag_views;
    }
    
    set_transient('hot_topics_24h', $hot_topics, HOUR_IN_SECONDS * 2);
    return $hot_topics;
}
