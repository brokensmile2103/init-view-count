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
    $post_types = array_diff($post_types, ['attachment']);
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
        // Vẫn bắn after hook với summary rỗng.
        // LƯU Ý: luôn truyền default=1 cho 3 option enable_day/week/month — nếu admin CHƯA TỪNG
        // lưu trang Settings ít nhất 1 lần, các option này KHÔNG tồn tại trong DB, và get_option()
        // không có default sẽ trả về false, khiến reset bị coi là tắt trong khi phần đếm view
        // (rest-api.php) vẫn coi là BẬT theo default — view cứ cộng nhưng không bao giờ được reset.
        // Phải khớp default=1 với rest-api.php và với checkbox trong settings-page.php (cũng default 1).
        $summary = [
            'total_posts'      => 0,
            'reset_day'        => (bool) get_option('init_plugin_suite_view_count_enable_day', 1),
            'reset_week'       => (bool) (get_option('init_plugin_suite_view_count_enable_week', 1) && $should_reset_week),
            'reset_month'      => (bool) (get_option('init_plugin_suite_view_count_enable_month', 1) && $should_reset_month),
            'affected_posts'   => 0,
        ];
        do_action('init_plugin_suite_view_count_after_reset_counts', $summary, $context);
        return;
    }

    // Tùy chọn bật tắt — PHẢI dùng default=1, khớp với rest-api.php (chỗ +1 view) và với
    // checkbox mặc định "đã tick" trong settings-page.php. Nếu bỏ default ở đây (như bản cũ),
    // site nào chưa từng lưu Settings sẽ bị: view vẫn cộng bình thường nhưng KHÔNG BAO GIỜ reset.
    $enable_day   = (bool) get_option('init_plugin_suite_view_count_enable_day', 1);
    $enable_week  = (bool) get_option('init_plugin_suite_view_count_enable_week', 1);
    $enable_month = (bool) get_option('init_plugin_suite_view_count_enable_month', 1);

    // Kế hoạch reset áp dụng CHUNG cho mọi post trong lượt chạy này — bật/tắt day/week/month
    // là setting toàn site, không đổi theo từng post_id, nên chỉ cần tính 1 lần (bản cũ tính
    // lại y hệt giá trị này trong mỗi vòng lặp, lãng phí không cần thiết).
    $reset_week    = ($enable_week  && $should_reset_week);
    $reset_month   = ($enable_month && $should_reset_month);
    $per_post_plan = [
        'day'   => $enable_day,
        'week'  => $reset_week,
        'month' => $reset_month,
    ];

    /**
     * 3) PRE RESET (mỗi post)
     * Vẫn bắn action riêng cho TỪNG post để giữ đúng hợp đồng hook như bản cũ (bên ngoài có
     * thể log/side-effect theo từng post trước khi dữ liệu bị đổi). Bước này không chạm DB
     * (trừ khi listener bên ngoài tự làm), nên không phải nguồn gây chậm.
     */
    foreach ($posts as $post_id) {
        do_action('init_plugin_suite_view_count_pre_reset_post', $post_id, $per_post_plan, $context);
    }

    /**
     * Rollover hàng loạt bằng SQL theo batch (xem init_plugin_suite_view_count_bulk_rollover_meta())
     * thay cho việc lặp get_post_meta()/update_post_meta()/delete_post_meta() trên TỪNG post —
     * cách cũ có thể tốn 3–6 query/post/loại (day/week/month), tức hàng chục nghìn query cho
     * 1 lần chạy cron trên site nhiều bài viết. Cách mới đưa số query về mức O(số batch) thay vì
     * O(số post), trong khi vẫn tôn trọng filter 'init_plugin_suite_view_count_meta_key' theo
     * từng post và vẫn đảm bảo mọi post đều có prev-key (kể cả giá trị 0).
     */
    $touched = [];

    if ($enable_day) {
        $touched = array_merge($touched, init_plugin_suite_view_count_bulk_rollover_meta(
            $posts, '_init_view_day_count', '_init_view_day_yesterday'
        ));
    }

    if ($reset_week) {
        $touched = array_merge($touched, init_plugin_suite_view_count_bulk_rollover_meta(
            $posts, '_init_view_week_count', '_init_view_week_last'
        ));
    }

    if ($reset_month) {
        $touched = array_merge($touched, init_plugin_suite_view_count_bulk_rollover_meta(
            $posts, '_init_view_month_count', '_init_view_month_last'
        ));
    }

    // Toàn bộ thao tác trên chạy bằng SQL thuần (bypass object cache của get/update/delete_post_meta)
    // → flush cache đúng 1 lần cho tất cả post đã đụng tới.
    if (!empty($touched)) {
        $touched = array_values(array_unique(array_map('intval', $touched)));

        // wp_cache_delete_multiple() có từ WordPress 6.0, nhưng bản thân hàm đó chỉ là wrapper
        // gọi thẳng tới phương thức delete_multiple() trên object $wp_object_cache ĐANG ACTIVE.
        // Nhiều object cache drop-in bên thứ 3 (Redis Object Cache, W3 Total Cache, Memcached...)
        // dùng class cache RIÊNG của họ và có thể CHƯA implement method này — gọi thẳng có thể
        // gây Fatal Error "Call to undefined method ...::delete_multiple()", khiến toàn bộ cron
        // (kể cả khi bấm "Run Now" trong WP Crontrol) chết ngay lập tức mà không rõ nguyên nhân.
        // Kiểm tra tồn tại của method trước; nếu không có, fallback về loop wp_cache_delete()
        // — hàm nền tảng này được MỌI drop-in hỗ trợ, kể cả các bản cũ nhất.
        global $wp_object_cache;
        if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'delete_multiple' ) ) {
            wp_cache_delete_multiple( $touched, 'post_meta' );
        } else {
            foreach ( $touched as $post_id ) {
                wp_cache_delete( $post_id, 'post_meta' );
            }
        }
    }

    $affected = count($posts);

    // Tóm tắt cho after hook
    $summary = [
        'total_posts'      => count($posts),
        'reset_day'        => $enable_day,
        'reset_week'       => $reset_week,
        'reset_month'      => $reset_month,
        'affected_posts'   => $affected,
    ];

    /**
     * 4) AFTER RESET (toàn cục)
     * Cho phép clear cache, rebuild index, audit log, v.v.
     */
    do_action('init_plugin_suite_view_count_after_reset_counts', $summary, $context);

    // (Tùy chọn) Kích hoạt trending ngay sau reset (để danh sách phản ánh số liệu mới)
    $trigger_trending = apply_filters('init_plugin_suite_view_count_after_daily_reset_trigger_trending', false);
    if ($trigger_trending && init_view_count_trending_enabled()) {
        // Đặt single event ngay lập tức (safe với cron runner)
        if (!wp_next_scheduled('init_plugin_suite_view_count_cron_update_trending')) {
            wp_schedule_single_event(time() + 10, 'init_plugin_suite_view_count_cron_update_trending');
        } else {
            // Nếu đã có lịch hourly, vẫn bắn ngay để không chờ 1h
            do_action('init_plugin_suite_view_count_cron_update_trending');
        }
    }
}

/**
 * Rollover hàng loạt (bulk) cho 1 loại đếm (day/week/month): copy giá trị hiện tại sang
 * meta "kỳ trước" (yesterday/last_week/last_month) rồi xoá giá trị hiện tại — cho TOÀN BỘ
 * danh sách post truyền vào, bằng SQL thuần theo batch thay vì lặp
 * get_post_meta()/update_post_meta()/delete_post_meta() trên từng post.
 *
 * Vẫn tôn trọng filter 'init_plugin_suite_view_count_meta_key' theo TỪNG POST (site có thể
 * override tên meta key theo post_id) bằng cách gom post thành từng nhóm theo cặp
 * (current_key, prev_key) đã resolve, rồi xử lý bulk riêng cho từng nhóm — trường hợp phổ
 * biến (không ai custom filter) sẽ chỉ có đúng 1 nhóm duy nhất.
 *
 * QUAN TRỌNG: đảm bảo MỌI post trong danh sách đều có prev-key được set tường minh (bằng
 * giá trị hiện tại, hoặc 0 nếu post đó chưa có view nào trong kỳ) — giống hệt hành vi
 * update_post_meta() luôn-upsert của bản cũ. Lý do: các truy vấn WP_Query dùng
 * 'orderby' => 'meta_value_num' theo prev-key (xem GET /top?range=yesterday|last_week|last_month
 * trong rest-api.php) sẽ LOẠI HẲN những post không có meta row đó ra khỏi kết quả, chứ không
 * tự hiểu "không có row" là 0. Nếu bỏ sót bước set-0 này, các bài không có view trong kỳ sẽ
 * biến mất khỏi bảng xếp hạng thay vì đứng cuối bảng như hành vi gốc.
 *
 * Giả định: mỗi post chỉ có tối đa 1 row cho mỗi meta_key — đúng với cách plugin luôn ghi
 * (xem init_plugin_suite_view_count_atomic_increment()); postmeta của WordPress về mặt kỹ
 * thuật cho phép nhiều row trùng key nên bước (1) luôn dọn sạch prev-key cũ trước khi copy,
 * để không bao giờ tạo ra row trùng dù dữ liệu có bị lệch chuẩn từ trước.
 *
 * @param int[]  $post_ids    Danh sách post ID cần rollover.
 * @param string $current_tpl Tên meta key hiện tại (chưa qua filter), VD '_init_view_day_count'.
 * @param string $prev_tpl    Tên meta key "kỳ trước" (chưa qua filter), VD '_init_view_day_yesterday'.
 * @return int[] Danh sách post_id đã bị đụng tới (post_status/type đã lọc sẵn từ $post_ids), để caller flush cache.
 */
function init_plugin_suite_view_count_bulk_rollover_meta(array $post_ids, $current_tpl, $prev_tpl) {
    global $wpdb;

    if (empty($post_ids)) {
        return [];
    }

    // Resolve meta key thật sự cho từng post (tôn trọng filter theo post_id), gom nhóm theo
    // cặp (current_key, prev_key). apply_filters() không chạm DB nên vòng lặp này rất rẻ.
    $groups = [];
    foreach ($post_ids as $post_id) {
        $post_id     = (int) $post_id;
        $current_key = (string) apply_filters('init_plugin_suite_view_count_meta_key', $current_tpl, $post_id);
        $prev_key    = (string) apply_filters('init_plugin_suite_view_count_meta_key', $prev_tpl, $post_id);
        $group_key   = $current_key . '|' . $prev_key;

        if (!isset($groups[$group_key])) {
            $groups[$group_key] = [
                'current_key' => $current_key,
                'prev_key'    => $prev_key,
                'ids'         => [],
            ];
        }

        $groups[$group_key]['ids'][] = $post_id;
    }

    $touched    = [];
    $batch_size = apply_filters('init_plugin_suite_view_count_reset_batch_size', 500);

    foreach ($groups as $group) {
        $current_key = $group['current_key'];
        $prev_key    = $group['prev_key'];

        foreach (array_chunk($group['ids'], $batch_size) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

            // Ghi chú chung cho toàn bộ khối SQL bên dưới: $placeholders và $values_sql CHỈ
            // BAO GIỜ chứa chuỗi giữ chỗ ('%d', hoặc '(%d, %s, %d)') lặp lại theo số lượng
            // phần tử — không hề chèn trực tiếp giá trị/input người dùng vào SQL text. Toàn bộ
            // giá trị thật (meta key, post_id, meta_value) đều đi qua tham số thứ 2 của
            // $wpdb->prepare(), đúng chuẩn khuyến nghị của WordPress cho câu IN (...) động
            // (xem: https://developer.wordpress.org/reference/classes/wpdb/prepare/).
            // PHPCS không phân tích tĩnh được nội dung của các biến này nên báo nhầm các sniff
            // PreparedSQL/PreparedSQLPlaceholders — tắt có chủ đích, kèm giải thích, cho từng
            // câu query bên dưới thay vì tắt sniff toàn cục.

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            // Post nào trong lô đang CÓ view ở kỳ hiện tại (tức đang có row current-key).
            $existing_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($placeholders)",
                array_merge([$current_key], $chunk)
            )));

            // 1) Dọn sạch prev-key cũ của cả lô trước khi copy, để không bao giờ tạo row trùng
            //    (postmeta không có unique index trên post_id+meta_key).
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($placeholders)",
                array_merge([$prev_key], $chunk)
            ));
            // phpcs:enable

            // 2) Copy giá trị hiện tại → prev-key, cho các post đang có view kỳ này.
            if (!empty($existing_ids)) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                     SELECT post_id, %s, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = %s AND post_id IN ($placeholders)",
                    array_merge([$prev_key, $current_key], $chunk)
                ));
                // phpcs:enable
            }

            // 3) Với post KHÔNG có view kỳ này, set prev-key = 0 tường minh (xem giải thích
            //    "QUAN TRỌNG" ở đầu hàm — bắt buộc để post vẫn xuất hiện, với giá trị 0,
            //    trong các truy vấn orderby=meta_value_num theo prev-key).
            $missing_ids = array_values(array_diff($chunk, $existing_ids));
            if (!empty($missing_ids)) {
                $values_sql = implode(',', array_fill(0, count($missing_ids), '(%d, %s, %d)'));
                $args = [];
                foreach ($missing_ids as $missing_id) {
                    $args[] = $missing_id;
                    $args[] = $prev_key;
                    $args[] = 0;
                }
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES $values_sql",
                    $args
                ));
                // phpcs:enable
            }

            // 4) Xoá giá trị hiện tại — reset về "chưa có view" cho kỳ mới, y hệt
            //    delete_post_meta() của bản cũ.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($placeholders)",
                array_merge([$current_key], $chunk)
            ));
            // phpcs:enable

            $touched = array_merge($touched, $chunk);
        }
    }

    return $touched;
}

// === CRON: UPDATE TRENDING ===

// Kiểm tra có đang bật tính Trending hay không
function init_view_count_trending_enabled(): bool {
    // Mặc định 0 = KHÔNG tắt → Trending đang bật
    return (int) get_option('init_plugin_suite_view_count_disable_trending', 0) === 0;
}

// Internal helper: fetch top post IDs by a specific meta key.
function init_plugin_suite_view_count_fetch_ids_by_key( $meta_key, $post_types, $limit ) {
    if ( empty( $meta_key ) ) return [];

    $q = new WP_Query([
        'post_type'           => $post_types,
        'posts_per_page'      => $limit,
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_key'            => $meta_key,
        'orderby'             => 'meta_value_num',
        'order'               => 'DESC',
        'post_status'         => 'publish',
        'no_found_rows'       => true,
        'fields'              => 'ids',
        // Xếp hạng theo lượt xem thực tế cho Trending Engine, không để sticky
        // post chen vào danh sách ứng viên chỉ vì được ghim.
        'ignore_sticky_posts' => true,
    ]);

    return ! empty( $q->posts ) ? $q->posts : [];
}

// Update Trending: multi-key fallback + rank fusion
function init_plugin_suite_view_count_cron_update_trending() {
    if ( ! init_view_count_trending_enabled() ) {
        return; // noop khi tắt trending
    }

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
    if ( ! init_view_count_trending_enabled() ) {
        return [];
    }

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
