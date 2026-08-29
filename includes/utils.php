<?php
/**
 * Các hàm helper dùng chung, không gắn với route/hook cụ thể nào.
 * Được require đầu tiên (trước rest-api.php, shortcodes.php, settings-page.php...)
 * vì các file đó gọi trực tiếp những hàm ở đây.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ép một số nguyên về trong khoảng [min, max]. Dùng chung cho cả lúc lưu
 * settings lẫn lúc đọc option ra để localize xuống JS, để không lặp lại logic clamp.
 *
 * @param int $value Giá trị cần ép.
 * @param int $min   Giá trị nhỏ nhất cho phép.
 * @param int $max   Giá trị lớn nhất cho phép.
 * @return int
 */
function init_plugin_suite_view_count_clamp_int($value, $min, $max) {
    return (int) max($min, min($max, (int) $value));
}

/**
 * Cộng dồn +1 vào một meta key dạng số bằng SQL thuần (atomic ở tầng DB),
 * thay vì đọc-rồi-ghi (get_post_meta + update_post_meta) vốn có thể mất
 * lượt view khi 2 request cùng lúc ghi đè lên nhau (race condition).
 *
 * Hàm này KHÔNG tự xoá cache post meta sau khi ghi — bên gọi cần tự invalidate
 * (xem init_plugin_suite_view_count_flush_meta_cache()) đúng 1 lần sau khi đã
 * cộng dồn XONG TẤT CẢ các meta key của 1 post, để tránh xoá cache lặp lại
 * nhiều lần không cần thiết trong cùng 1 request (VD: 4 key/post).
 *
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key cần +1.
 * @return void
 */
function init_plugin_suite_view_count_atomic_increment($post_id, $meta_key) {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cần UPDATE trực tiếp để đảm bảo tăng giá trị atomic, tránh race condition khi nhiều request cùng ghi 1 post; cache được tự invalidate 1 lần ở cấp caller (xem init_plugin_suite_view_count_flush_meta_cache()).
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
            $post_id,
            $meta_key
        )
    );

    if ((int) $wpdb->rows_affected > 0) {
        return;
    }

    // Chưa có row cho meta key này (lượt view đầu tiên) → tạo mới với giá trị 1.
    // $unique = true để tránh insert trùng nếu có request khác vừa insert xong.
    $inserted = add_post_meta($post_id, $meta_key, 1, true);

    if (false === $inserted) {
        // Thua trong race lúc insert lần đầu (request khác vừa tạo row) → row đã tồn tại,
        // quay lại dùng UPDATE atomic như bình thường.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Xem giải thích ở UPDATE phía trên.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
                $post_id,
                $meta_key
            )
        );
    }
}

/**
 * Xoá cache post meta (object cache) cho 1 post sau khi các meta key của post đó
 * đã được ghi trực tiếp bằng SQL (bypass hoàn toàn get_post_meta()/update_post_meta()).
 * Gọi đúng 1 lần/post sau khi đã update xong toàn bộ key liên quan (total/day/week/month),
 * thay vì gọi lặp lại theo từng key.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function init_plugin_suite_view_count_flush_meta_cache($post_id) {
    wp_cache_delete($post_id, 'post_meta');
}

/**
 * Kiểm tra IP hiện tại có vừa xem post này gần đây không (chống đếm trùng/bot),
 * dựa trên transient lưu danh sách hash IP theo từng post.
 *
 * @param int $post_id Post ID.
 * @return bool True nếu IP này đã xem post trong thời gian gần đây.
 */
function init_plugin_suite_view_count_is_ip_recent( $post_id ) {
    $ip = init_plugin_suite_view_count_get_real_ip();

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

/**
 * Enhanced IP detection — thử lần lượt các header phổ biến của proxy/CDN
 * trước khi fallback về REMOTE_ADDR.
 *
 * @return string
 */
function init_plugin_suite_view_count_get_real_ip() {
    $ip_keys = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
        'HTTP_X_FORWARDED',          // Proxy
        'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
        'HTTP_CLIENT_IP',            // Proxy
        'HTTP_X_REAL_IP',           // Nginx proxy
        'REMOTE_ADDR'               // Standard
    ];

    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER)) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER[$key] ) );

            // Handle comma-separated IPs (X-Forwarded-For có thể có nhiều IP)
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }

            // Validate IP
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }

            // Fallback: accept private IPs too (for local dev)
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '127.0.0.1'; // Ultimate fallback
}

/**
 * Rút gọn số lớn thành dạng ngắn (1.2K, 3.4Tr...), có hỗ trợ hậu tố tiếng Việt
 * khi locale site bắt đầu bằng "vi".
 *
 * @param int $num Số cần format.
 * @return string
 */
function init_plugin_suite_view_count_format_thousands($num) {
    if ($num < 1000) {
        return (string) $num;
    }

    $locale = get_locale();
    $suffixes = str_starts_with($locale, 'vi')
        ? ['N', 'Tr', 'T', 'TT']  // Nghìn, Triệu, Tỷ, Nghìn tỷ
        : ['K', 'M', 'B', 'T'];   // Thousand, Million, Billion, Trillion

    $i = 0;
    while ($num >= 1000 && $i < count($suffixes)) {
        $num /= 1000;
        $i++;
    }

    // Tự format: luôn dùng dấu chấm cho thập phân, không dấu ngăn cách nghìn
    if ($num - floor($num) > 0) {
        $value = number_format($num, 1, '.', '');
    } else {
        $value = number_format($num, 0, '.', '');
    }

    return $value . ' ' . $suffixes[$i - 1];
}
