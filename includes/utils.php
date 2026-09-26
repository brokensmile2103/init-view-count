<?php
/**
 * Các hàm helper dùng chung, không gắn với route/hook cụ thể nào.
 * Được require đầu tiên (trước rest-api.php, shortcodes.php, settings-page.php...)
 * vì các file đó gọi trực tiếp những hàm ở đây.
 *
 * @package Init_View_Count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ép một số nguyên về trong khoảng [min, max]. Dùng chung cho cả lúc lưu
 * settings lẫn lúc đọc option ra để localize xuống JS, để không lặp lại logic clamp.
 *
 * @param int $value Giá trị cần ép.
 * @param int $min   Giá trị nhỏ nhất cho phép.
 * @param int $max   Giá trị lớn nhất cho phép.
 * @return int
 */
function init_plugin_suite_view_count_clamp_int( $value, $min, $max ) {
	return (int) max( $min, min( $max, (int) $value ) );
}

/**
 * Cộng dồn +1 vào một meta key dạng số bằng SQL thuần (atomic ở tầng DB),
 * thay vì đọc-rồi-ghi (get_post_meta + update_post_meta) vốn có thể mất
 * lượt view khi 2 request cùng lúc ghi đè lên nhau (race condition).
 *
 * Hàm này KHÔNG tự xoá cache post meta sau khi ghi — bên gọi cần tự invalidate
 * (xem init_plugin_suite_view_count_flush_meta_cache()) đúng 1 lần sau khi đã
 * cộng dồn XONG TẤT CẢ các meta key của 1 post.
 *
 * Giữ nguyên để tương thích ngược (code bên ngoài có thể đang gọi). Luồng đếm
 * view chính dùng init_plugin_suite_view_count_atomic_increment_many() để gộp
 * nhiều key vào 1 câu UPDATE.
 *
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key cần +1.
 * @return void
 */
function init_plugin_suite_view_count_atomic_increment( $post_id, $meta_key ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cần UPDATE trực tiếp để tăng giá trị atomic; cache được invalidate ở cấp caller.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
			$post_id,
			$meta_key
		)
	);

	if ( (int) $wpdb->rows_affected > 0 ) {
		return;
	}

	init_plugin_suite_view_count_insert_or_increment( $post_id, $meta_key );
}

/**
 * Tạo mới meta key với giá trị 1 (lượt view đầu tiên của kỳ). Nếu thua race
 * (request khác vừa insert xong) thì quay lại UPDATE +1 atomic như bình thường.
 *
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key.
 * @return void
 */
function init_plugin_suite_view_count_insert_or_increment( $post_id, $meta_key ) {
	global $wpdb;

	// $unique = true để tránh insert trùng nếu có request khác vừa insert xong.
	if ( false !== add_post_meta( $post_id, $meta_key, 1, true ) ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Xem giải thích ở init_plugin_suite_view_count_atomic_increment().
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
			$post_id,
			$meta_key
		)
	);
}

/**
 * Cộng +1 cho NHIỀU meta key của cùng 1 post chỉ bằng 1 câu UPDATE (thay vì
 * 1 câu/key như trước — luồng /count mặc định có 4 key: total/day/week/month).
 *
 * Cách làm:
 * - Dựa vào object cache của post meta (đã được nạp sẵn khi đọc giá trị hiện
 *   tại bằng get_post_meta()) để biết key nào ĐANG có row trong DB.
 * - Các key đang có row → cộng dồn trong 1 câu UPDATE ... WHERE meta_key IN (...).
 * - Các key chưa có row (lượt view đầu của kỳ) → add_post_meta() unique, nếu thua
 *   race thì fallback UPDATE atomic (y hệt hành vi cũ).
 * - Nếu số row bị ảnh hưởng ÍT HƠN số key mong đợi (cache lệch với DB, VD: row bị
 *   xoá bằng SQL mà không flush cache) → tra lại DB để tìm đúng key còn thiếu và
 *   tạo row cho key đó. Không bao giờ cộng 2 lần cho cùng 1 key.
 *
 * @param int      $post_id   Post ID.
 * @param string[] $meta_keys Danh sách meta key cần +1.
 * @return void
 */
function init_plugin_suite_view_count_atomic_increment_many( $post_id, array $meta_keys ) {
	global $wpdb;

	$post_id   = (int) $post_id;
	$meta_keys = array_values( array_unique( array_filter( array_map( 'strval', $meta_keys ), 'strlen' ) ) );

	if ( ! $post_id || empty( $meta_keys ) ) {
		return;
	}

	$existing = array();
	$missing  = array();

	foreach ( $meta_keys as $meta_key ) {
		if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
			$existing[] = $meta_key;
		} else {
			$missing[] = $meta_key;
		}
	}

	if ( ! empty( $existing ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $existing ), '%s' ) );

		// $placeholders chỉ chứa chuỗi '%s' lặp lại, mọi giá trị thật đều đi qua prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key IN ($placeholders)",
				array_merge( array( $post_id ), $existing )
			)
		);

		if ( (int) $wpdb->rows_affected < count( $existing ) ) {
			// Cache lệch với DB: tìm key thực sự đã có row (đã được +1 ở trên).
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($placeholders)",
					array_merge( array( $post_id ), $existing )
				)
			);

			$missing = array_merge( $missing, array_diff( $existing, (array) $found ) );
		}
		// phpcs:enable
	}

	foreach ( $missing as $meta_key ) {
		init_plugin_suite_view_count_insert_or_increment( $post_id, $meta_key );
	}
}

/**
 * Xoá cache post meta (object cache) cho 1 post sau khi các meta key của post đó
 * đã được ghi trực tiếp bằng SQL. Gọi đúng 1 lần/post.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function init_plugin_suite_view_count_flush_meta_cache( $post_id ) {
	wp_cache_delete( $post_id, 'post_meta' );
}

/**
 * Xoá cache post meta cho nhiều post cùng lúc.
 *
 * wp_cache_delete_multiple() chỉ là wrapper gọi thẳng delete_multiple() trên object
 * cache đang active; một số drop-in bên thứ 3 chưa implement method này → kiểm tra
 * trước, fallback về loop wp_cache_delete() (mọi drop-in đều hỗ trợ).
 *
 * @param int[] $post_ids Danh sách post ID.
 * @return void
 */
function init_plugin_suite_view_count_flush_meta_cache_many( array $post_ids ) {
	global $wp_object_cache;

	$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
	if ( empty( $post_ids ) ) {
		return;
	}

	if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'delete_multiple' ) ) {
		wp_cache_delete_multiple( $post_ids, 'post_meta' );
		return;
	}

	foreach ( $post_ids as $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );
	}
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

	$hash = base_convert( sprintf( '%u', crc32( $ip ) ), 10, 36 );
	$key  = 'ivc_recent_ips_' . $post_id;

	$list = get_transient( $key );
	if ( ! is_array( $list ) ) {
		$list = array();
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
 * Danh sách header có thể thu hẹp qua filter 'init_plugin_suite_view_count_ip_headers'
 * (VD: chỉ giữ 'REMOTE_ADDR' trên server không đứng sau proxy/CDN, để client không
 * thể tự giả mạo IP bằng header X-Forwarded-For nhằm lách Strict IP check).
 *
 * @return string
 */
function init_plugin_suite_view_count_get_real_ip() {
	$ip_keys = apply_filters(
		'init_plugin_suite_view_count_ip_headers',
		array(
			'HTTP_CF_CONNECTING_IP',     // Cloudflare.
			'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy.
			'HTTP_X_FORWARDED',          // Proxy.
			'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster.
			'HTTP_CLIENT_IP',            // Proxy.
			'HTTP_X_REAL_IP',            // Nginx proxy.
			'REMOTE_ADDR',               // Standard.
		)
	);

	foreach ( (array) $ip_keys as $key ) {
		if ( ! is_string( $key ) || ! isset( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			continue;
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

		// X-Forwarded-For có thể chứa nhiều IP, IP đầu tiên là client gốc.
		if ( false !== strpos( $ip, ',' ) ) {
			$ip = trim( explode( ',', $ip )[0] );
		}

		// Ưu tiên IP public; fallback chấp nhận cả IP private (môi trường local dev).
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	return '127.0.0.1'; // Ultimate fallback.
}

/**
 * Rút gọn số lớn thành dạng ngắn (1.2 K, 3.4 Tr...), có hỗ trợ hậu tố tiếng Việt
 * khi locale site bắt đầu bằng "vi".
 *
 * Làm tròn TRƯỚC khi chọn hậu tố, để 999 950 hiển thị "1 M" thay vì "1000.0 K",
 * và bỏ phần thập phân ".0" thừa (VD: 1 999 → "2 K" thay vì "2.0 K").
 *
 * @param int $num Số cần format.
 * @return string
 */
function init_plugin_suite_view_count_format_thousands( $num ) {
	$num = (int) $num;

	if ( $num < 1000 ) {
		return (string) $num;
	}

	$suffixes = str_starts_with( get_locale(), 'vi' )
		? array( 'N', 'Tr', 'T', 'TT' )  // Nghìn, Triệu, Tỷ, Nghìn tỷ.
		: array( 'K', 'M', 'B', 'T' );   // Thousand, Million, Billion, Trillion.

	$max_index = count( $suffixes );
	$value     = (float) $num;
	$i         = 0;

	while ( $value >= 1000 && $i < $max_index ) {
		$value /= 1000;
		++$i;
	}

	$value = round( $value, 1 );

	// Làm tròn có thể đẩy giá trị lên đúng 1000 (VD: 999.96 → 1000.0) → nhảy lên bậc kế tiếp.
	if ( $value >= 1000 && $i < $max_index ) {
		$value /= 1000;
		++$i;
	}

	// Tự format: luôn dùng dấu chấm cho thập phân, không dấu ngăn cách nghìn.
	$decimals = ( $value - floor( $value ) ) > 0 ? 1 : 0;

	return number_format( $value, $decimals, '.', '' ) . ' ' . $suffixes[ $i - 1 ];
}
