<?php
// Schedule the daily reset cron at 00:01 if not already scheduled.

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('init_plugin_suite_view_count_reset_counts', 'init_plugin_suite_view_count_reset_counts');
add_action('init_plugin_suite_view_count_cron_update_trending', 'init_plugin_suite_view_count_cron_update_trending');

add_action('init', function () {
    // Reset view counts hàng ngày lúc 00:01 (theo timezone WP)
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
    // === Context thời gian ===
    $now          = current_time('timestamp');              // theo timezone site
    $day_of_week  = (int) wp_date('w', $now);               // 0 = Sunday, 1 = Monday, ...
    $day_of_month = (int) wp_date('j', $now);               // 1 = first day
    $iso_week     = (int) wp_date('W', $now);
    $year         = (int) wp_date('Y', $now);

    // Cho phép tùy chỉnh điều kiện reset tuần/tháng
    $should_reset_week  = apply_filters('init_plugin_suite_view_count_should_reset_week',  ($day_of_week === 1), $day_of_week, $now);
    $should_reset_month = apply_filters('init_plugin_suite_view_count_should_reset_month', ($day_of_month === 1), $day_of_month, $now);

    // Xác định post types (public) – robust
    $post_types = array_unique(array_filter(array_map('sanitize_key', get_post_types(['public' => true]))));
    if (empty($post_types)) {
        // fallback an toàn
        $post_types = ['post'];
    }

    $args = [
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ];

    // Bối cảnh chung bắn qua hooks
    $context = [
        'now'              => $now,
        'now_gmt'          => current_time('timestamp', true),
        'date'             => wp_date('Y-m-d H:i:s', $now),
        'day_of_week'      => $day_of_week,
        'day_of_month'     => $day_of_month,
        'iso_week'         => $iso_week,
        'year'             => $year,
        'should_reset_week'=> (bool) $should_reset_week,
        'should_reset_month'=> (bool) $should_reset_month,
        'post_types'       => $post_types,
    ];

    /**
     * 1) BEFORE RESET (toàn cục)
     * Cho phép bên ngoài chuẩn bị mọi thứ (log, backup, chuyển trạng thái…)
     */
    do_action('init_plugin_suite_view_count_before_reset_counts', $context);

    /**
     * 2) DAILY SHAPE ROLLUP: gọi TRƯỚC khi xoá day-count
     * Module ML: hook vào đây để snapshot bins hôm qua và cập nhật EMA hour/wday.
     * - Không bắt buộc cài đặt; nếu không có listener thì bỏ qua.
     * - $context giúp quyết định chế độ cập nhật (ví dụ tuần/tháng mới).
     */
    do_action('init_plugin_suite_view_count_daily_shape_rollup', $context);

    // Lấy danh sách post
    $posts = get_posts($args);
    if (empty($posts)) {
        // Vẫn bắn after hook với summary rỗng
        $summary = [
            'total_posts'      => 0,
            'reset_day'        => (bool) get_option('init_plugin_suite_view_count_enable_day'),
            'reset_week'       => (bool) (get_option('init_plugin_suite_view_count_enable_week') && $should_reset_week),
            'reset_month'      => (bool) (get_option('init_plugin_suite_view_count_enable_month') && $should_reset_month),
            'affected_posts'   => 0,
        ];
        do_action('init_plugin_suite_view_count_after_reset_counts', $summary, $context);
        return;
    }

    // Tùy chọn bật tắt (giữ nguyên logic cũ)
    $enable_day   = (bool) get_option('init_plugin_suite_view_count_enable_day');
    $enable_week  = (bool) get_option('init_plugin_suite_view_count_enable_week');
    $enable_month = (bool) get_option('init_plugin_suite_view_count_enable_month');

    $affected = 0;

    foreach ($posts as $post_id) {
        // Kế hoạch reset cho post này (đẩy ra hook pre_reset_post)
        $per_post_plan = [
            'day'   => $enable_day,
            'week'  => ($enable_week  && $should_reset_week),
            'month' => ($enable_month && $should_reset_month),
        ];

        /**
         * 3) PRE RESET (mỗi post)
         * Cho phép plugin khác log lại giá trị cũ, đếm tổng, hoặc thực hiện side-effect.
         */
        do_action('init_plugin_suite_view_count_pre_reset_post', $post_id, $per_post_plan, $context);

        if ($per_post_plan['day']) {
            $meta_day = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count', $post_id);
            delete_post_meta($post_id, $meta_day);
        }

        if ($per_post_plan['week']) {
            $meta_week = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_week_count', $post_id);
            delete_post_meta($post_id, $meta_week);
        }

        if ($per_post_plan['month']) {
            $meta_month = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_month_count', $post_id);
            delete_post_meta($post_id, $meta_month);
        }

        $affected++;
    }

    // Tóm tắt cho after hook
    $summary = [
        'total_posts'      => count($posts),
        'reset_day'        => $enable_day,
        'reset_week'       => ($enable_week  && $should_reset_week),
        'reset_month'      => ($enable_month && $should_reset_month),
        'affected_posts'   => $affected,
    ];

    /**
     * 4) AFTER RESET (toàn cục)
     * Cho phép clear cache, rebuild index, audit log, v.v.
     */
    do_action('init_plugin_suite_view_count_after_reset_counts', $summary, $context);

    // (Tùy chọn) Kích hoạt trending ngay sau reset (để danh sách phản ánh số liệu mới)
    $trigger_trending = apply_filters('init_plugin_suite_view_count_after_daily_reset_trigger_trending', true);
    if ($trigger_trending) {
        // Đặt single event ngay lập tức (safe với cron runner)
        if (!wp_next_scheduled('init_plugin_suite_view_count_cron_update_trending')) {
            wp_schedule_single_event(time() + 10, 'init_plugin_suite_view_count_cron_update_trending');
        } else {
            // Nếu đã có lịch hourly, vẫn bắn ngay để không chờ 1h
            do_action('init_plugin_suite_view_count_cron_update_trending');
        }
    }
}

// === CRON: UPDATE TRENDING ===

// Internal helper: fetch top post IDs by a specific meta key.
function init_plugin_suite_view_count_fetch_ids_by_key( $meta_key, $post_types, $limit ) {
    if ( empty( $meta_key ) ) return [];

    $q = new WP_Query([
        'post_type'      => $post_types,
        'posts_per_page' => $limit,
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_key'       => $meta_key,
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'post_status'    => 'publish',
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ]);

    return ! empty( $q->posts ) ? $q->posts : [];
}

// Update Trending: multi-key fallback + rank fusion
function init_plugin_suite_view_count_cron_update_trending() {
    // ====== Configs ======
    $limit     = (int) apply_filters('init_plugin_suite_view_count_trending_limit', 100);
    $min_count = (int) apply_filters('init_plugin_suite_view_count_trending_min_count', 20);

    // ====== Post types (sanitize kỹ) ======
    $post_types = (array) get_option('init_plugin_suite_view_count_post_types', ['post']);
    $post_types = array_unique(array_filter(array_map('sanitize_key', $post_types)));
    $post_types = array_diff($post_types, ['attachment']);
    $post_types = apply_filters('init_plugin_suite_view_count_trending_post_types', $post_types);
    if (empty($post_types)) $post_types = ['post'];

    // ====== Meta keys ======
    $day_key   = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count',   null);
    $week_key  = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_week_count',  null);
    $month_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_month_count', null);
    $total_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_count',       null);

    // ====== Pull lists ======
    $day_ids   = init_plugin_suite_view_count_fetch_ids_by_key( $day_key,   $post_types, $limit );

    // Nếu đủ theo ngày thì dùng luôn để giữ hành vi cũ
    if (count($day_ids) >= $min_count) {
        init_plugin_suite_view_count_calculate_trending( array_slice($day_ids, 0, $limit) );
        return;
    }

    // Chỉ query thêm khi cần fallback
    $week_ids  = init_plugin_suite_view_count_fetch_ids_by_key( $week_key,  $post_types, $limit );
    $month_ids = init_plugin_suite_view_count_fetch_ids_by_key( $month_key, $post_types, $limit );
    $total_ids = init_plugin_suite_view_count_fetch_ids_by_key( $total_key, $post_types, $limit );

    // ====== Rank-fusion fallback ======
    // Tạo map rank cho nhanh
    $rank = function(array $ids){ return array_flip($ids); };
    $day_rank   = $rank($day_ids);
    $week_rank  = $rank($week_ids);
    $month_rank = $rank($month_ids);
    $total_rank = $rank($total_ids);

    // Ứng viên: nối day → week → month → total, tránh trùng, tối đa $limit
    $candidates = [];
    foreach ([$day_ids, $week_ids, $month_ids, $total_ids] as $list) {
        foreach ($list as $pid) {
            if (!in_array($pid, $candidates, true)) {
                $candidates[] = $pid;
                if (count($candidates) >= $limit) break 2;
            }
        }
    }

    // Trọng số rank (ưu tiên day)
    $weights = (array) apply_filters('init_plugin_suite_view_count_trending_weights', [
        'day'   => 1.0,
        'week'  => 0.6,
        'month' => 0.3,
        'total' => 0.15,
    ]);

    $get_part = function($r) use ($limit){ return 1.0 - ($r / max(1,$limit)); };

    $scores = [];
    foreach ($candidates as $pid) {
        $s = 0.0;
        if (isset($day_rank[$pid]))   $s += $weights['day']   * $get_part($day_rank[$pid]);
        if (isset($week_rank[$pid]))  $s += $weights['week']  * $get_part($week_rank[$pid]);
        if (isset($month_rank[$pid])) $s += $weights['month'] * $get_part($month_rank[$pid]);
        if (isset($total_rank[$pid])) $s += $weights['total'] * $get_part($total_rank[$pid]);
        // bonus nếu có mặt ở day list
        if (isset($day_rank[$pid]))   $s += (float) apply_filters('init_plugin_suite_view_count_trending_day_presence_bonus', 0.02);
        $scores[$pid] = $s;
    }

    // Sort theo score desc, tie-break bằng rank ngày → rank tuần → ID mới hơn
    usort($candidates, function($a,$b) use($scores,$day_rank,$week_rank){
        $sa=$scores[$a]??0; $sb=$scores[$b]??0;
        if ($sa===$sb) {
            $ra=$day_rank[$a]??PHP_INT_MAX; $rb=$day_rank[$b]??PHP_INT_MAX;
            if ($ra===$rb) {
                $wa=$week_rank[$a]??PHP_INT_MAX; $wb=$week_rank[$b]??PHP_INT_MAX;
                if ($wa===$wb) return $b<=>$a; // id mới trước
                return $wa<=>$wb;
            }
            return $ra<=>$rb;
        }
        return ($sa<$sb)?1:-1;
    });

    // Đảm bảo tối thiểu min_count (nếu vẫn thiếu, tiếp tục bơm từ các list dài hơn)
    if (count($candidates) < $min_count) {
        foreach ([$week_ids,$month_ids,$total_ids] as $more) {
            foreach ($more as $pid) {
                if (!in_array($pid,$candidates,true)) {
                    $candidates[]=$pid;
                    if (count($candidates)>= $min_count) break 2;
                }
            }
        }
    }

    // Pass sang core calculate
    $final_ids = array_slice($candidates, 0, max($min_count, $limit));
    if (!empty($final_ids)) {
        init_plugin_suite_view_count_calculate_trending($final_ids);
    }
}

// ==========================================
// == CORE: TÍNH ĐIỂM TRENDING (BẢN NÂNG)  ==
// ==========================================

function init_plugin_suite_view_count_calculate_trending(array $post_ids) {
    $lock_key   = 'trending_calculation_lock';
    $lock_group = 'init_ps';

    // Race condition protection với lock + fixed group
    if (!wp_cache_add($lock_key, time(), $lock_group, 300)) {
        // Nếu không thể tạo lock, return cached result
        return get_transient('init_plugin_suite_view_count_trending') ?: [];
    }

    $trending  = [];
    $now       = current_time('timestamp');

    $last_run = get_transient('trending_last_calculation');
    if ($last_run && ($now - $last_run) < 3300) {
        wp_cache_delete($lock_key, $lock_group);
        return get_transient('init_plugin_suite_view_count_trending') ?: [];
    }

    // Prefetch meta cache nếu site có object cache tốt (giảm N+1)
    update_meta_cache('post', $post_ids);

    $view_cache = [];
    $post_cache = [];

    foreach ($post_ids as $post_id) {
        $day_meta_key   = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count',   $post_id);
        $week_meta_key  = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_week_count',  $post_id);
        $month_meta_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_month_count', $post_id);
        $total_meta_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_count',       $post_id);

        $view_cache[$post_id] = [
            'day'   => (int) get_post_meta($post_id, $day_meta_key, true),
            'week'  => (int) get_post_meta($post_id, $week_meta_key, true),
            'month' => (int) get_post_meta($post_id, $month_meta_key, true),
            'total' => (int) get_post_meta($post_id, $total_meta_key, true),
        ];

        $post = get_post($post_id);
        if ($post) {
            // Cache tất cả data cần thiết để tránh N+1 queries sau này
            $post_cache[$post_id] = [
                'timestamp' => get_post_time('U', true, $post_id),
                'category'  => wp_get_post_categories($post_id, ['fields' => 'ids']),
                'tags'      => wp_get_post_tags($post_id, ['fields' => 'ids']),
                'author_id' => (int) $post->post_author, // Cache author_id luôn
            ];
        }
    }

    // Get configurable weights với safe defaults (BẢN MỚI)
    $weights = wp_parse_args(
        apply_filters('init_plugin_suite_view_count_trending_component_weights', []),
        [
            'velocity'   => 1.0,
            'engagement' => 1.0,
            'freshness'  => 1.0,
            'momentum'   => 1.0,
            // NEW:
            'uplift'     => 1.0,  // seasonality-aware uplift
            'ewma'       => 1.0,  // momentum theo EWMA
            'fatigue'    => 1.0,  // giảm theo exposure
            'explore'    => 1.0,  // tỷ lệ explore
            'mmr'        => 1.0,  // mức đa dạng nội dung
        ]
    );

    foreach ($post_ids as $post_id) {
        $views     = $view_cache[$post_id] ?? null;
        $post_data = $post_cache[$post_id] ?? null;

        if (!$views || !$post_data) continue;

        $post_timestamp = (int) ($post_data['timestamp'] ?? 0);
        if ($post_timestamp <= 0) continue;

        $age_hours = max(0.5, ($now - $post_timestamp) / 3600.0);

        // --- Day views fallback (đầu ngày day thường = 0) ---
        $views_eff = $views; // bản sao an toàn

        if ((int)$views_eff['day'] === 0) {
            // Ước lượng day_views từ week với tiến độ trong ngày (0..1),
            // giúp velocity/engagement không bị 0 cứng.
            $weekly_avg_per_day = $views['week'] > 0 ? ($views['week'] / 7.0) : 0.0;
            $progress_in_day    = min(1.0, max(0.05, $age_hours / 24.0)); // ít nhất 5% để tránh 0
            $est_day            = (int) round($weekly_avg_per_day * $progress_in_day);

            // kẹp nhẹ để không over-estimate khi tuần quá thấp
            if ($views['month'] > 0) {
                $monthly_avg_per_day = $views['month'] / 30.0;
                $est_day = max($est_day, (int) round(0.3 * $monthly_avg_per_day * $progress_in_day));
            }

            $views_eff['day'] = max(1, $est_day); // tối thiểu 1 để có velocity dương
        }

        $velocity_score     = init_plugin_suite_view_count_calculate_velocity_score($views_eff, $age_hours);
        $time_decay         = init_plugin_suite_view_count_calculate_time_decay($age_hours);
        $engagement_quality = init_plugin_suite_view_count_calculate_engagement_quality($post_id, $views_eff);
        $freshness_boost    = init_plugin_suite_view_count_calculate_freshness_boost($age_hours);
        $category_momentum  = init_plugin_suite_view_count_calculate_category_momentum($post_data['category'], $post_data['tags']);

        // NEW: Uplift (seasonality-aware) + EWMA momentum + Anti-gaming
        list($expected_views, $uplift_mult, $uplift_raw) = init_plugin_suite_view_count_expected_views($views, $age_hours, $post_data['category']);
        list($ewma_val, $ewma_mult, $acc)                = init_plugin_suite_view_count_ewma_velocity($post_id, $views, $age_hours);
        $anti_gaming_mult                                = init_plugin_suite_view_count_anti_gaming_multiplier($views);

        // Apply configurable weights
        $base_score  = $velocity_score * $time_decay;
        $final_score = $base_score
                     * pow($engagement_quality, $weights['engagement'])
                     * pow($freshness_boost,    $weights['freshness'])
                     * pow($category_momentum,  $weights['momentum'])
                     * pow($uplift_mult,        $weights['uplift'])
                     * pow($ewma_mult,          $weights['ewma'])
                     * $anti_gaming_mult;

        // Soft cap thay vì hard cap + kẹp tăng trưởng liên run
        $normalized_score = 10000 * (1 - exp(-$final_score / 5000));
        $normalized_score = init_plugin_suite_view_count_cap_score_growth($post_id, $normalized_score);

        $trending[] = [
            'id'                => $post_id,
            'score'             => round($normalized_score, 4),
            'views'             => $views['day'],
            'views_day'         => $views['day'],
            'views_week'        => $views['week'],
            'views_month'       => $views['month'],
            'views_total'       => $views['total'],
            'views_day_used'    => (int)$views_eff['day'],
            'used_day_fallback' => (int)($views['day'] === 0),
            'age_hours'         => round($age_hours, 2),
            'time'              => $now,
            'author_id'         => $post_data['author_id'], // Cache author_id cho diversity
            'categories'        => $post_data['category'],  // Cache categories cho diversity
            'tags'              => $post_data['tags'],
            'components'        => [
                'velocity'    => round($velocity_score, 4),
                'time_decay'  => round($time_decay, 4),
                'engagement'  => round($engagement_quality, 4),
                'freshness'   => round($freshness_boost, 4),
                'momentum'    => round($category_momentum, 4),
                // NEW debug fields
                'uplift'      => round($uplift_mult, 4),
                'uplift_raw'  => round($uplift_raw, 4),
                'expected'    => round($expected_views, 2),
                'ewma'        => round($ewma_mult, 4),
                'ewma_val'    => round($ewma_val, 4),
                'acc'         => round($acc, 4),
            ]
        ];
    }

    // Rank lần 1
    usort($trending, fn($a, $b) => $b['score'] <=> $a['score']);

    // Mark top flags (trước khi fatigue/MMR) để tính streak
    $pre_top  = array_slice($trending, 0, 20);
    $top_hash = [];
    foreach ($pre_top as $row) { $top_hash[$row['id']] = true; }

    // Exposure fatigue
    foreach ($trending as &$item) {
        list($fatigue_mult, $streak) = init_plugin_suite_view_count_exposure_fatigue_multiplier($item['id'], isset($top_hash[$item['id']]));
        $item['score'] *= pow($fatigue_mult, $weights['fatigue']);
        $item['components']['fatigue']       = round($fatigue_mult, 4);
        $item['components']['fatigue_streak']= (int) $streak;
    }
    unset($item);

    // Rank lần 2 (sau fatigue)
    usort($trending, fn($a,$b) => $b['score'] <=> $a['score']);

    // MMR re-rank (đa dạng nội dung sâu)
    $mmr_lambda = 0.75;
    $mmr_out    = init_plugin_suite_view_count_mmr_rerank($trending, $mmr_lambda, 20);

    // Explore/Exploit: bơm thử bài tiềm năng
    $mmr_out = init_plugin_suite_view_count_maybe_explore($mmr_out, $trending, $weights);

    // Diversity Filter quota hiện có (fill-back O(n))
    $top_trending = init_plugin_suite_view_count_apply_diversity_filter($mmr_out, 20);

    set_transient('init_plugin_suite_view_count_trending', $top_trending, DAY_IN_SECONDS);
    set_transient('init_plugin_suite_view_count_trending_debug', array_slice($trending, 0, 50), DAY_IN_SECONDS);
    set_transient('trending_last_calculation', $now, DAY_IN_SECONDS);

    // Clean up lock với group
    wp_cache_delete($lock_key, $lock_group);

    /**
     * Hook debug mở rộng (nếu cần)
     * do_action('init_plugin_suite_view_count_trending_debug_row', $top_trending, $trending);
     */

    return $top_trending;
}

// ===================================================
// == CÁC THÀNH PHẦN ĐIỂM (CŨ + NÂNG)               ==
// ===================================================

// Tính Velocity Score - Tốc độ tăng trưởng lượt xem
function init_plugin_suite_view_count_calculate_velocity_score($views, $age_hours) {
    $day_views   = $views['day'];
    $week_views  = $views['week'];
    $month_views = $views['month'];

    // Tính acceleration - so sánh day vs week vs month
    $weekly_avg  = $week_views  > 0 ? $week_views  / 7  : 0;
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
    if ($age_hours <= 2) {
        return 1.0; // No decay cho 2h đầu
    }
    // Decay factor: giảm 50% sau 36h
    $decay_rate = 0.693; // ln(2)
    $half_life  = 36;    // hours
    return exp(-$decay_rate * ($age_hours - 2) / $half_life);
}

// Engagement Quality - Chất lượng tương tác (với smoothing)
function init_plugin_suite_view_count_calculate_engagement_quality($post_id, $views) {
    $comments_count = wp_count_comments($post_id)->approved ?? 0;

    $meta_keys = apply_filters('init_plugin_suite_view_count_engagement_meta_keys', [
        'likes'  => '_likes_count',
        'shares' => '_shares_count',
    ]);

    $likes_count  = (int) get_post_meta($post_id, $meta_keys['likes'], true);
    $shares_count = (int) get_post_meta($post_id, $meta_keys['shares'], true);

    $total_views = max(5, $views['day']);
    $engagement_actions = $comments_count + $likes_count + $shares_count;
    $engagement_rate    = $engagement_actions / $total_views;

    // Convert to multiplier (1.0 - 2.0)
    $quality_multiplier = 1 + min($engagement_rate * 10, 1.0);
    return $quality_multiplier;
}

// Freshness Boost - Boost cho content mới (optimized for 1h cron)
function init_plugin_suite_view_count_calculate_freshness_boost($age_hours) {
    if ($age_hours <= 1)   return 1.8;
    if ($age_hours <= 3)   return 1.4;
    if ($age_hours <= 6)   return 1.2;
    if ($age_hours <= 12)  return 1.1;
    if ($age_hours <= 24)  return 1.05;
    return 1.0;
}

// Category Momentum - Xu hướng theo chủ đề
function init_plugin_suite_view_count_calculate_category_momentum($categories, $tags) {
    static $hot_topics_cache = null;
    if ($hot_topics_cache === null) {
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
    $chosen   = []; // Track selected IDs for O(1) lookup
    $category_count = [];
    $author_count   = [];

    // Sử dụng cached data thay vì query lại
    $all_authors    = [];
    $all_categories = [];

    foreach ($trending_posts as $post) {
        $author_id = $post['author_id'];
        $all_authors[$author_id] = true;

        $cats = $post['categories'] ?? [];
        foreach ($cats as $cat_id) {
            $all_categories[$cat_id] = true;
        }
    }

    $author_count_total   = count($all_authors);
    $category_count_total = count($all_categories);

    // Giới hạn mặc định
    $max_per_author   = 2;
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
        $cats      = $post['categories'] ?? [];

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
            $chosen[$post['id']] = true;

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

    $day_meta_key = apply_filters('init_plugin_suite_view_count_meta_key', '_init_view_day_count', 0);
    $gmt_24h_ago  = gmdate('Y-m-d H:i:s', current_time('timestamp', 1) - DAY_IN_SECONDS);

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
        $views    = (int) $row->day_views;

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

// =====================================
// == NÂNG CẤP MỚI: SHAPE & UPLIFT    ==
// =====================================

function init_plugin_suite_view_count_get_site_traffic_shape() {
    $cache = get_transient('init_plugin_suite_view_count_site_traffic_shape');
    if ($cache !== false) return $cache;

    // Shape mặc định phẳng (1.0)
    $shape = [
        'hour' => array_fill(0, 24, 1.0),
        'wday' => array_fill(0, 7, 1.0),
    ];

    // Cho phép nguồn khác override bằng hook (đưa mảng 24h/7d đã chuẩn hóa hoặc thô)
    $now_gmt = current_time('timestamp', true);
    $hour    = (int) wp_date('G', $now_gmt);
    $wday    = (int) wp_date('w', $now_gmt);
    $shape   = apply_filters('init_plugin_suite_view_count_site_traffic_shape', $shape, $hour, $wday);

    // normalize mean=1
    $norm = function($arr){
        $m = array_sum($arr) / max(1, count($arr));
        if ($m <= 0) return $arr;
        foreach ($arr as $i => $v) $arr[$i] = $v / $m;
        return $arr;
    };
    $shape['hour'] = $norm($shape['hour']);
    $shape['wday'] = $norm($shape['wday']);

    set_transient('init_plugin_suite_view_count_site_traffic_shape', $shape, HOUR_IN_SECONDS * 2);
    return $shape;
}

function init_plugin_suite_view_count_expected_views($views, $age_hours, $post_categories) {
    $shape = init_plugin_suite_view_count_get_site_traffic_shape();

    $now_gmt = current_time('timestamp', true);
    $hour    = (int) wp_date('G', $now_gmt);
    $wday    = (int) wp_date('w', $now_gmt);

    $week_avg_per_day = max(0.0, $views['week'] / 7.0);
    $progress         = min(24.0, max(1.0, $age_hours)) / 24.0;

    $hot_topics = init_plugin_suite_view_count_get_hot_topics_last_24h();
    $cat_hot = 0.0;
    foreach ((array) $post_categories as $cid) {
        if (!empty($hot_topics['categories'][$cid])) {
            $cat_hot = max($cat_hot, (float) $hot_topics['categories'][$cid]); // 0..1
        }
    }

    $expected = $week_avg_per_day * $progress;
    $expected *= $shape['hour'][$hour] ?? 1.0;
    $expected *= $shape['wday'][$wday] ?? 1.0;
    $expected *= (1.0 + 0.1 * $cat_hot);

    // variance stabilization (Anscombe-ish)
    $obs    = max(0.0, (float) $views['day']);
    $uplift = (sqrt($obs + 0.375) - sqrt(max(0.0, $expected) + 0.375));

    // Scale multiplier ~ [0.8 .. 1.5]
    $mult = 1.0 + max(-0.2, min(0.5, $uplift * 0.15));
    return [$expected, $mult, $uplift];
}

// =====================================
// == NÂNG CẤP MỚI: EWMA & ANTI-GAME  ==
// =====================================

function init_plugin_suite_view_count_ewma_velocity($post_id, $views, $age_hours) {
    $v = $views['day'] / max(1.0, min($age_hours, 24.0)); // views/hour gần nhất

    $half  = 6.0; // half-life 6h
    $alpha = 1.0 - pow(0.5, 1.0 / $half);

    $key  = "ewma_v_$post_id";
    $prev = wp_cache_get($key, 'init_ps');
    if ($prev === false) $prev = $v;

    $ewma = $alpha * $v + (1 - $alpha) * $prev;
    wp_cache_set($key, $ewma, 'init_ps', HOUR_IN_SECONDS * 12);

    // acceleration (kẹp nhẹ)
    $acc = max(-0.5, min(0.5, $ewma - $prev));

    // multiplier ~ [0.8 .. 1.3]
    $mult = 1.0 + max(-0.2, min(0.3, log(1 + $ewma) * 0.15 + $acc * 0.1));
    return [$ewma, $mult, $acc];
}

function init_plugin_suite_view_count_anti_gaming_multiplier($views) {
    $day   = max(0, (int) $views['day']);
    $month = max(1, (int) $views['month']);
    $total = max(1, (int) $views['total']);

    $day_month_ratio = $day / max(1, $month / 30.0);
    $day_total_ratio = $day / max(1, $total / 90.0);

    $penalty = 1.0;
    if ($day_month_ratio > 20) $penalty *= 0.9;
    if ($day_total_ratio > 50) $penalty *= 0.85;

    return $penalty;
}

function init_plugin_suite_view_count_cap_score_growth($post_id, $score) {
    $k    = "last_score_$post_id";
    $prev = get_transient($k);
    if ($prev !== false) {
        $max_allowed = $prev * 1.8 + 10; // cho phép tăng 80% (+10 buffer)
        $score = min($score, $max_allowed);
    }
    set_transient($k, $score, HOUR_IN_SECONDS * 6);
    return $score;
}

// =====================================
// == NÂNG CẤP MỚI: FATIGUE + MMR     ==
// =====================================

function init_plugin_suite_view_count_exposure_fatigue_multiplier($post_id, $is_top_now) {
    $k = "top_streak_$post_id";
    $streak = (int) get_transient($k);
    if ($is_top_now) {
        $streak++;
        set_transient($k, $streak, HOUR_IN_SECONDS * 8);
    } else {
        delete_transient($k);
        $streak = 0;
    }
    // sau 6h đứng top, bắt đầu giảm; min 0.8
    $mult = max(0.8, 1.0 - max(0, $streak - 6) * 0.03);
    return [$mult, $streak];
}

function init_plugin_suite_view_count_similarity($a, $b) {
    // Jaccard theo tập category+tag
    $sa = array_unique(array_merge($a['categories'] ?? [], $a['tags'] ?? []));
    $sb = array_unique(array_merge($b['categories'] ?? [], $b['tags'] ?? []));
    if (empty($sa) && empty($sb)) return 0.0;
    $ia = array_intersect($sa, $sb);
    $ua = array_unique(array_merge($sa, $sb));
    return count($ia) / max(1, count($ua));
}

function init_plugin_suite_view_count_mmr_rerank(array $posts, $lambda = 0.75, $limit = 20) {
    $selected = [];
    $cands    = array_values($posts);

    while (!empty($cands) && count($selected) < $limit) {
        $best_i = 0; $best_val = -INF;
        foreach ($cands as $i => $p) {
            $rel = $p['score'];
            $div = 0.0;
            foreach ($selected as $s) {
                $div = max($div, init_plugin_suite_view_count_similarity($p, $s));
            }
            $mmr = $lambda * $rel - (1 - $lambda) * $div * $rel; // phạt tương đồng theo độ lớn rel
            if ($mmr > $best_val) { $best_val = $mmr; $best_i = $i; }
        }
        $selected[] = $cands[$best_i];
        array_splice($cands, $best_i, 1);
    }
    return $selected;
}

function init_plugin_suite_view_count_maybe_explore(array $ranked, array $pool, $weights) {
    $epsilon = min(0.25, max(0.0, 0.05 * (float) ($weights['explore'] ?? 1.0))); // 0..0.25
    // Ngẫu nhiên theo epsilon
    if ( ( wp_rand(0, 1000000) / 1000000 ) > $epsilon ) return $ranked;

    // chèn 1-2 bài "tiềm năng" (high uplift * ewma) ở vị trí 5-10
    $cands = array_slice($pool, 0, 50);
    usort($cands, function($a,$b){
        $sa = (float) (($a['components']['uplift'] ?? 1.0) * ($a['components']['ewma'] ?? 1.0));
        $sb = (float) (($b['components']['uplift'] ?? 1.0) * ($b['components']['ewma'] ?? 1.0));
        return $sb <=> $sa;
    });
    $pick = array_slice($cands, 0, 2);
    $pos  = min(count($ranked), max(5, wp_rand(5, 10)));
    array_splice($ranked, $pos, 0, $pick);

    // loại trùng + cắt limit 20
    $seen = [];
    $out  = [];
    foreach ($ranked as $p) {
        if (!isset($seen[$p['id']])) {
            $out[] = $p; $seen[$p['id']] = 1;
        }
        if (count($out) >= 20) break;
    }
    return $out;
}
