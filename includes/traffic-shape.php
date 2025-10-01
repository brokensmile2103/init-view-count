<?php
/**
 * Module: Traffic Shape Learner
 * Part of: Init View Count
 * Description: Học phân phối traffic theo giờ và thứ (EMA + prior), cung cấp shape cho expected_views().
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * ====== SETTINGS & INTERNAL KEYS ======
 */
const INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_HOUR     = 'init_plugin_suite_view_count_shape_hour_ema';
const INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_WDAY     = 'init_plugin_suite_view_count_shape_wday_ema';
const INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_TODAY    = 'init_plugin_suite_view_count_shape_today_bins';
const INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_PENDING  = 'init_plugin_suite_view_count_shape_yesterday_pending';
const INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_LOCK_GROUP   = 'init_plugin_suite_view_count_shape';
const INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_CACHE_TTL    = 2 * HOUR_IN_SECONDS;

/**
 * Helper: normalize mean = 1
 */
function init_plugin_suite_view_count_shape_normalize_mean_one(array $arr) {
    $n = count($arr);
    if ($n <= 0) return $arr;
    $m = array_sum($arr) / $n;
    if ($m <= 0) return $arr;
    foreach ($arr as $i => $v) $arr[$i] = $v / $m;
    return $arr;
}

/**
 * 1) GHI NHẬN LƯỢT VIEW THEO GIỜ (TODAY BINS) – XOAY 2 THÙNG (SITE TIME)
 *   - Log theo GIỜ SITE để khớp mốc reset.
 *   - Nếu phát hiện state.date != hôm nay → đẩy state cũ sang PENDING (yesterday) rồi khởi tạo TODAY.
 */
add_action('init_plugin_suite_view_count_after_counted', 'init_plugin_suite_view_count_shape_on_after_counted', 10, 3);

function init_plugin_suite_view_count_shape_on_after_counted($post_id, $updated, $request) {
    if ( ! init_view_count_trending_enabled() ) {
        return; // noop khi Trending tắt
    }

    if ( apply_filters('init_plugin_suite_view_count_shape_collect_enabled', true) !== true ) return;
    if ( is_admin() && apply_filters('init_plugin_suite_view_count_shape_skip_admin', true) ) return;

    $now_gmt   = current_time('timestamp', true);
    $today     = wp_date('Y-m-d', $now_gmt);
    $yesterday = wp_date('Y-m-d', $now_gmt - DAY_IN_SECONDS);

    $state = get_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_TODAY);

    // --- Rotate sang PENDING nếu state đang là NGÀY KHÁC hôm nay ---
    if ( is_array($state) && ($state['date'] ?? '') && ($state['date'] !== $today) ) {
        // Nếu đúng là "hôm qua" và có dữ liệu thì dồn sang PENDING (merge-safe)
        if ( ($state['date'] === $yesterday) && !empty($state['hour_bins']) && !empty($state['total']) ) {
            $pending = get_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_PENDING);
            if ( is_array($pending) && ($pending['date'] ?? '') === $yesterday ) {
                $bins = array_fill(0, 24, 0);
                for ($i = 0; $i < 24; $i++) {
                    $bins[$i] = (int)($pending['hour_bins'][$i] ?? 0) + (int)($state['hour_bins'][$i] ?? 0);
                }
                $pending = [
                    'date'      => $yesterday,
                    'hour_bins' => $bins,
                    'total'     => (int)$pending['total'] + (int)$state['total'],
                ];
            } else {
                $pending = [
                    'date'      => $yesterday,
                    'hour_bins' => array_map('intval', $state['hour_bins']),
                    'total'     => (int)$state['total'],
                ];
            }
            update_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_PENDING, $pending, false);
        }

        // Khởi tạo TODAY cho ngày mới (site)
        $state = [
            'date'      => $today,
            'hour_bins' => array_fill(0, 24, 0),
            'total'     => 0,
        ];
    }

    // Nếu chưa có TODAY (lần đầu/ngày mới) → tạo mới
    if ( !is_array($state) || ($state['date'] ?? '') !== $today ) {
        $state = [
            'date'      => $today,
            'hour_bins' => array_fill(0, 24, 0),
            'total'     => 0,
        ];
    }

    // Ghi nhận view theo GIỜ SITE
    $hour = (int) wp_date('G', $now_gmt);
    $state['hour_bins'][$hour] = (int)$state['hour_bins'][$hour] + 1;
    $state['total']            = (int)$state['total'] + 1;

    update_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_TODAY, $state, false);
}

/**
 * 2) ROLLUP HÀNG NGÀY (hook bắn từ core reset 00:01 SITE)
 *   - Ưu tiên ăn từ PENDING (yesterday). Nếu không có PENDING, fallback vào TODAY khi TODAY=date == yesterday.
 *   - Sau khi rollup xong → reset TODAY sang ngày hiện tại.
 */
add_action('init_plugin_suite_view_count_daily_shape_rollup', 'init_plugin_suite_view_count_shape_daily_rollup', 10, 1);

function init_plugin_suite_view_count_shape_daily_rollup($context) {
    if ( ! init_view_count_trending_enabled() ) {
        return; // noop khi Trending tắt
    }

    $lock_key = 'rollup_lock';
    if ( ! wp_cache_add($lock_key, time(), INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_LOCK_GROUP, 300) ) {
        return;
    }

    // Dùng GMT TS, để wp_date áp timezone site khi format
    if (isset($context['now_gmt'])) {
        $ts_gmt = (int)$context['now_gmt'];
    } elseif (isset($context['now'])) {
        // context['now'] có thể là ts local → quy về GMT
        $ts_gmt = (int)$context['now'] - ( get_option('gmt_offset') * HOUR_IN_SECONDS );
    } else {
        $ts_gmt = current_time('timestamp', true);
    }
    $today_site = wp_date('Y-m-d', $ts_gmt);
    $yest_site  = wp_date('Y-m-d', $ts_gmt - DAY_IN_SECONDS);

    // 1) ƯU TIÊN PENDING
    $pending = get_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_PENDING);
    if ( is_array($pending) && ($pending['date'] ?? '') === $yest_site && !empty($pending['hour_bins']) ) {
        $hour_bins = array_map('intval', $pending['hour_bins']);
        $total     = max(0, (int)$pending['total']);
        delete_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_PENDING); // tiêu thụ pending
    } else {
        // 2) FALLBACK: TODAY == yesterday
        $state = get_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_TODAY);
        if ( !is_array($state) || empty($state['hour_bins']) || ($state['date'] ?? '') !== $yest_site ) {
            wp_cache_delete($lock_key, INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_LOCK_GROUP);
            return;
        }
        $hour_bins = array_map('intval', $state['hour_bins']);
        $total     = max(0, (int)$state['total']);
    }

    // Reset TODAY cho NGÀY HIỆN TẠI (site)
    update_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_TODAY, [
        'date'      => $today_site,
        'hour_bins' => array_fill(0, 24, 0),
        'total'     => 0,
    ], false);

    // Bỏ qua rollup nếu ngày quá ít traffic (tránh nhiễu)
    $min_daily = (int) apply_filters('init_plugin_suite_view_count_shape_min_daily_total', 200);
    if ( $total < $min_daily ) {
        wp_cache_delete($lock_key, INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_LOCK_GROUP);
        return;
    }

    // === Hour-of-day EMA update ===
    $alpha_hour  = (float) apply_filters('init_plugin_suite_view_count_shape_alpha_hour', 0.25);
    $kappa_hour  = (int)   apply_filters('init_plugin_suite_view_count_shape_kappa_hour', 48);
    $prior_hour  = array_fill(0, 24, 1.0 / 24.0);

    $denom      = $total + $kappa_hour;
    $share_hour = [];
    for ($h = 0; $h < 24; $h++) {
        $num = $hour_bins[$h] + $kappa_hour * $prior_hour[$h];
        $share_hour[$h] = $denom > 0 ? $num / $denom : (1.0 / 24.0);
    }

    $hour_shape = get_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_HOUR, array_fill(0, 24, 1.0));
    $avg_share  = 1.0 / 24.0;
    for ($h = 0; $h < 24; $h++) {
        $mult = max(0.2, min(5.0, $share_hour[$h] / $avg_share));
        $prev = isset($hour_shape[$h]) ? (float)$hour_shape[$h] : 1.0;
        $hour_shape[$h] = (1 - $alpha_hour) * $prev + $alpha_hour * $mult;
    }
    $hour_shape = init_plugin_suite_view_count_shape_normalize_mean_one($hour_shape);
    update_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_HOUR, $hour_shape, false);

    // === Weekday EMA update (site) ===
    $alpha_wday  = (float) apply_filters('init_plugin_suite_view_count_shape_alpha_wday', 0.20);
    $kappa_wday  = (int)   apply_filters('init_plugin_suite_view_count_shape_kappa_wday', 14);
    $wday_shape  = get_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_WDAY, array_fill(0, 7, 1.0));

    $wday_yday     = (int) wp_date('w', $ts_gmt - DAY_IN_SECONDS);
    $avg_total_est = array_sum($wday_shape) / 7.0;
    if ($avg_total_est <= 0) $avg_total_est = 1.0;

    $total_stab = ($total + $kappa_wday * 1.0) / (1.0 + $kappa_wday);
    $mult_w     = max(0.2, min(5.0, $total_stab / $avg_total_est));

    $prev_w = isset($wday_shape[$wday_yday]) ? (float)$wday_shape[$wday_yday] : 1.0;
    $wday_shape[$wday_yday] = (1 - $alpha_wday) * $prev_w + $alpha_wday * $mult_w;
    $wday_shape = init_plugin_suite_view_count_shape_normalize_mean_one($wday_shape);
    update_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_WDAY, $wday_shape, false);

    delete_transient('init_plugin_suite_view_count_site_traffic_shape_learned');
    wp_cache_delete($lock_key, INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_LOCK_GROUP);
}

/**
 * 3) CUNG CẤP SHAPE QUA FILTER
 */
add_filter('init_plugin_suite_view_count_site_traffic_shape', 'init_plugin_suite_view_count_shape_provide', 5, 3);

function init_plugin_suite_view_count_shape_provide($shape, $hour_now, $wday_now) {
    if ( ! init_view_count_trending_enabled() ) {
        // Neutral shape: mean = 1 cho cả giờ & thứ
        return [
            'hour' => array_fill(0, 24, 1.0),
            'wday' => array_fill(0, 7,  1.0),
        ];
    }

    $cache = get_transient('init_plugin_suite_view_count_site_traffic_shape_learned');
    if ($cache && is_array($cache)) {
        return $cache;
    }

    $hour_shape = get_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_HOUR, array_fill(0, 24, 1.0));
    $wday_shape = get_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_WDAY, array_fill(0, 7, 1.0));

    if (!is_array($hour_shape) || count($hour_shape) !== 24) $hour_shape = array_fill(0, 24, 1.0);
    if (!is_array($wday_shape) || count($wday_shape) !== 7)  $wday_shape = array_fill(0, 7, 1.0);

    $out = [
        'hour' => init_plugin_suite_view_count_shape_normalize_mean_one(array_map('floatval', $hour_shape)),
        'wday' => init_plugin_suite_view_count_shape_normalize_mean_one(array_map('floatval', $wday_shape)),
    ];

    set_transient('init_plugin_suite_view_count_site_traffic_shape_learned', $out, INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_CACHE_TTL);
    return $out;
}

/**
 * 4) RESET DỮ LIỆU (ADMIN ACTION)
 */
add_action('admin_post_init_plugin_suite_view_count_shape_reset', function () {
    delete_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_HOUR);
    delete_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_WDAY);
    delete_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_TODAY);
    delete_option(INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_OPT_PENDING);
    delete_transient('init_plugin_suite_view_count_site_traffic_shape_learned');
    wp_safe_redirect(admin_url('options-general.php'));
    exit;
});
