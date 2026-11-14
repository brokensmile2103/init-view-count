<?php
/**
 * Cron reset hàng ngày (00:01 theo timezone site) + lên lịch Trending Engine (chạy mỗi giờ).
 *
 * Từ v2.0.6, Trending Engine nằm trong includes/trending.php.
 *
 * @package Init_View_Count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init_plugin_suite_view_count_reset_counts', 'init_plugin_suite_view_count_reset_counts' );

add_action(
	'init',
	function () {
		// Reset view counts hàng ngày lúc 00:01 (theo timezone WP).
		if ( ! wp_next_scheduled( 'init_plugin_suite_view_count_reset_counts' ) ) {
			$dt = new DateTime( 'tomorrow 00:01', wp_timezone() );
			wp_schedule_event( $dt->getTimestamp(), 'daily', 'init_plugin_suite_view_count_reset_counts' );
		}

		// Cron update trending mỗi giờ.
		if ( ! wp_next_scheduled( 'init_plugin_suite_view_count_cron_update_trending' ) ) {
			wp_schedule_event( time(), 'hourly', 'init_plugin_suite_view_count_cron_update_trending' );
		}
	}
);

/**
 * Timestamp "local" kiểu cũ (= current_time('timestamp')): Unix timestamp cộng offset
 * timezone của site. Chỉ dùng để giữ nguyên giá trị truyền qua các hook/filter công khai
 * đã có từ trước; mọi phép tính ngày/giờ nội bộ đều dùng Unix timestamp thật (time()).
 *
 * @param int $timestamp Unix timestamp thật.
 * @return int
 */
function init_plugin_suite_view_count_local_timestamp( $timestamp ) {
	$timestamp = (int) $timestamp;
	return $timestamp + (int) wp_timezone()->getOffset( new DateTime( '@' . $timestamp ) );
}

/**
 * Căn lại lịch reset về đúng 00:01 giờ site.
 *
 * Lịch 'daily' của WP-Cron chạy theo chu kỳ cố định 24h tính bằng Unix timestamp,
 * nên sau mỗi lần đổi giờ mùa hè/mùa đông (DST) mốc chạy bị trôi thành 23:01 hoặc
 * 01:01 giờ site — khiến ngày/tuần/tháng bị reset lệch 1 giờ, và nếu trôi sang ngày
 * hôm trước thì cả phép kiểm tra "thứ Hai"/"ngày 1" cũng sai. Hàm này được gọi ở cuối
 * mỗi lần reset: nếu lần chạy kế tiếp lệch khỏi 00:01 giờ site từ 30 phút trở lên thì
 * xếp lịch lại cho khớp. Site không có DST (VD: Asia/Ho_Chi_Minh) không bao giờ bị đụng tới.
 *
 * @return void
 */
function init_plugin_suite_view_count_realign_reset_schedule() {
	$hook = 'init_plugin_suite_view_count_reset_counts';
	$next = wp_next_scheduled( $hook );

	if ( ! $next ) {
		return;
	}

	$tz     = wp_timezone();
	$local  = ( new DateTime( '@' . $next ) )->setTimezone( $tz );
	$target = new DateTime( $local->format( 'Y-m-d' ) . ' 00:01', $tz );

	// Chọn mốc 00:01 gần nhất với lần chạy kế tiếp (23:01 → 00:01 hôm sau, 01:01 → 00:01 cùng ngày).
	if ( (int) $local->format( 'G' ) >= 12 ) {
		$target->modify( '+1 day' );
	}

	$target_ts = $target->getTimestamp();

	if ( abs( $next - $target_ts ) < 30 * MINUTE_IN_SECONDS || $target_ts <= time() + HOUR_IN_SECONDS ) {
		return;
	}

	wp_unschedule_event( $next, $hook );
	wp_schedule_event( $target_ts, 'daily', $hook );
}

// === DAILY CRON RESET ===

/**
 * Cron reset hàng ngày: rollover day → yesterday, week → last_week (thứ Hai),
 * month → last_month (ngày 1).
 *
 * @return void
 */
function init_plugin_suite_view_count_reset_counts() {
	// === Context thời gian ===
	// Mọi phép tính ngày/giờ dùng Unix timestamp THẬT; wp_date() tự áp timezone site.
	// (Bản cũ truyền timestamp "local" vào wp_date() → bị cộng offset 2 lần: với site
	// có múi giờ âm (châu Mỹ...) lúc 00:01 sẽ bị tính thành ngày hôm trước, khiến
	// reset tuần rơi vào thứ Ba và reset tháng rơi vào ngày 2.)
	$now_gmt      = time();
	$now          = init_plugin_suite_view_count_local_timestamp( $now_gmt ); // Giữ giá trị cũ cho hook/filter.
	$day_of_week  = (int) wp_date( 'w', $now_gmt );          // Chủ nhật là 0, thứ Hai là 1.
	$day_of_month = (int) wp_date( 'j', $now_gmt );          // Ngày đầu tháng là 1.
	$iso_week     = (int) wp_date( 'W', $now_gmt );
	$year         = (int) wp_date( 'Y', $now_gmt );

	// Cho phép tùy chỉnh điều kiện reset tuần/tháng.
	$should_reset_week  = apply_filters( 'init_plugin_suite_view_count_should_reset_week', ( 1 === $day_of_week ), $day_of_week, $now );
	$should_reset_month = apply_filters( 'init_plugin_suite_view_count_should_reset_month', ( 1 === $day_of_month ), $day_of_month, $now );

	// Xác định post types (public).
	$post_types = array_unique( array_filter( array_map( 'sanitize_key', get_post_types( array( 'public' => true ) ) ) ) );
	$post_types = array_values( array_diff( $post_types, array( 'attachment' ) ) );
	if ( empty( $post_types ) ) {
		$post_types = array( 'post' );
	}

	$args = array(
		'post_type'              => $post_types,
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	// Bối cảnh chung bắn qua hooks.
	$context = array(
		'now'                => $now,
		'now_gmt'            => $now_gmt,
		'date'               => wp_date( 'Y-m-d H:i:s', $now_gmt ),
		'day_of_week'        => $day_of_week,
		'day_of_month'       => $day_of_month,
		'iso_week'           => $iso_week,
		'year'               => $year,
		'should_reset_week'  => (bool) $should_reset_week,
		'should_reset_month' => (bool) $should_reset_month,
		'post_types'         => $post_types,
	);

	/**
	 * 0) Ghi hết lượt xem đang đệm trong object cache (nếu có) vào DB TRƯỚC khi rollover,
	 * để lượt xem của kỳ cũ không bị cộng nhầm sang kỳ mới. Gọi cả khi chế độ bộ đệm đã tắt
	 * để ghi nốt phần còn sót; không có persistent object cache thì hàm trả về ngay.
	 */
	init_plugin_suite_view_count_buffer_flush(
		array(
			'wait'       => 15,
			'time_limit' => 120,
			'context'    => 'reset',
		)
	);

	/**
	 * 1) BEFORE RESET (toàn cục).
	 */
	do_action( 'init_plugin_suite_view_count_before_reset_counts', $context );

	/**
	 * 2) DAILY SHAPE ROLLUP: gọi TRƯỚC khi xoá day-count.
	 */
	do_action( 'init_plugin_suite_view_count_daily_shape_rollup', $context );

	// Tùy chọn bật tắt — PHẢI dùng default=1, khớp với rest-api.php (chỗ +1 view) và với
	// checkbox mặc định "đã tick" trong settings-page.php.
	$enable_day   = (bool) get_option( 'init_plugin_suite_view_count_enable_day', 1 );
	$enable_week  = (bool) get_option( 'init_plugin_suite_view_count_enable_week', 1 );
	$enable_month = (bool) get_option( 'init_plugin_suite_view_count_enable_month', 1 );

	$reset_week  = ( $enable_week && $should_reset_week );
	$reset_month = ( $enable_month && $should_reset_month );

	$posts = get_posts( $args );

	// v2.0.6+: lưu mốc reset thật của từng bộ đếm cho Trending Engine (WP-Cron có thể chạy
	// trễ so với 00:01, nên không thể giả định bộ đếm luôn bắt đầu đúng nửa đêm).
	init_plugin_suite_view_count_record_reset_marks( $now_gmt, $enable_day, $reset_week, $reset_month );

	if ( empty( $posts ) ) {
		$summary = array(
			'total_posts'    => 0,
			'reset_day'      => $enable_day,
			'reset_week'     => $reset_week,
			'reset_month'    => $reset_month,
			'affected_posts' => 0,
		);
		do_action( 'init_plugin_suite_view_count_after_reset_counts', $summary, $context );
		init_plugin_suite_view_count_realign_reset_schedule();
		return;
	}

	$per_post_plan = array(
		'day'   => $enable_day,
		'week'  => $reset_week,
		'month' => $reset_month,
	);

	/**
	 * 3) PRE RESET (mỗi post) — giữ đúng hợp đồng hook như bản cũ.
	 */
	foreach ( $posts as $post_id ) {
		do_action( 'init_plugin_suite_view_count_pre_reset_post', $post_id, $per_post_plan, $context );
	}

	/**
	 * Rollover hàng loạt bằng SQL theo batch (xem init_plugin_suite_view_count_bulk_rollover_meta()).
	 * Chỉ những post thực sự thay đổi mới bị ghi vào DB.
	 */
	$touched = array();

	if ( $enable_day ) {
		$touched = array_merge(
			$touched,
			init_plugin_suite_view_count_bulk_rollover_meta( $posts, '_init_view_day_count', '_init_view_day_yesterday' )
		);
	}

	if ( $reset_week ) {
		$touched = array_merge(
			$touched,
			init_plugin_suite_view_count_bulk_rollover_meta( $posts, '_init_view_week_count', '_init_view_week_last' )
		);
	}

	if ( $reset_month ) {
		$touched = array_merge(
			$touched,
			init_plugin_suite_view_count_bulk_rollover_meta( $posts, '_init_view_month_count', '_init_view_month_last' )
		);
	}

	// Toàn bộ thao tác trên chạy bằng SQL thuần → flush cache đúng 1 lần cho các post đã đụng tới.
	init_plugin_suite_view_count_flush_meta_cache_many( $touched );

	$summary = array(
		'total_posts'    => count( $posts ),
		'reset_day'      => $enable_day,
		'reset_week'     => $reset_week,
		'reset_month'    => $reset_month,
		'affected_posts' => count( $posts ),
	);

	/**
	 * 4) AFTER RESET (toàn cục).
	 */
	do_action( 'init_plugin_suite_view_count_after_reset_counts', $summary, $context );

	// (Tùy chọn) Kích hoạt trending ngay sau reset.
	$trigger_trending = apply_filters( 'init_plugin_suite_view_count_after_daily_reset_trigger_trending', false );
	if ( $trigger_trending && init_view_count_trending_enabled() ) {
		if ( ! wp_next_scheduled( 'init_plugin_suite_view_count_cron_update_trending' ) ) {
			wp_schedule_single_event( time() + 10, 'init_plugin_suite_view_count_cron_update_trending' );
		} else {
			do_action( 'init_plugin_suite_view_count_cron_update_trending' );
		}
	}

	init_plugin_suite_view_count_realign_reset_schedule();
}

/**
 * Rollover hàng loạt cho 1 loại đếm (day/week/month): đặt meta "kỳ trước"
 * (yesterday/last_week/last_month) = giá trị hiện tại (hoặc 0 nếu kỳ này không có view),
 * rồi xoá giá trị hiện tại — cho TOÀN BỘ danh sách post, bằng SQL theo batch.
 *
 * Kết quả cuối cùng GIỐNG HỆT bản cũ (mọi post đều có đúng 1 row prev-key, giá trị = kỳ
 * hiện tại hoặc 0; row current-key bị xoá), nhưng thuật toán mới chỉ GHI những gì thực
 * sự thay đổi:
 * - Post không có view trong kỳ và prev-key đã = 0 → không ghi gì (đây là đa số post
 *   trên site lớn). Bản cũ DELETE + INSERT lại toàn bộ prev-key mỗi ngày cho MỌI post,
 *   tức hàng chục nghìn row bị xoá/tạo lại, làm phình AUTO_INCREMENT của meta_id,
 *   binlog và phải flush object cache của mọi post.
 * - Post đã có đúng 1 row prev-key → UPDATE tại chỗ (giữ nguyên meta_id).
 * - Post chưa có prev-key → INSERT; post có prev-key bị trùng (dữ liệu lệch chuẩn) →
 *   dọn sạch rồi INSERT lại đúng 1 row.
 *
 * Vẫn tôn trọng filter 'init_plugin_suite_view_count_meta_key' theo TỪNG POST bằng cách
 * gom post theo cặp (current_key, prev_key) đã resolve.
 *
 * @param int[]  $post_ids    Danh sách post ID cần rollover.
 * @param string $current_tpl Tên meta key hiện tại (chưa qua filter), VD '_init_view_day_count'.
 * @param string $prev_tpl    Tên meta key "kỳ trước" (chưa qua filter), VD '_init_view_day_yesterday'.
 * @return int[] Danh sách post_id đã bị thay đổi trong DB, để caller flush cache.
 */
function init_plugin_suite_view_count_bulk_rollover_meta( array $post_ids, $current_tpl, $prev_tpl ) {
	if ( empty( $post_ids ) ) {
		return array();
	}

	$groups = array();
	foreach ( $post_ids as $post_id ) {
		$post_id     = (int) $post_id;
		$current_key = (string) apply_filters( 'init_plugin_suite_view_count_meta_key', $current_tpl, $post_id );
		$prev_key    = (string) apply_filters( 'init_plugin_suite_view_count_meta_key', $prev_tpl, $post_id );

		// Cấu hình lỗi (2 key trùng nhau) → bỏ qua để không tự xoá mất dữ liệu.
		if ( '' === $current_key || '' === $prev_key || $current_key === $prev_key ) {
			continue;
		}

		$group_key = $current_key . '|' . $prev_key;

		if ( ! isset( $groups[ $group_key ] ) ) {
			$groups[ $group_key ] = array(
				'current_key' => $current_key,
				'prev_key'    => $prev_key,
				'ids'         => array(),
			);
		}

		$groups[ $group_key ]['ids'][] = $post_id;
	}

	$touched    = array();
	$batch_size = max( 1, (int) apply_filters( 'init_plugin_suite_view_count_reset_batch_size', 500 ) );

	foreach ( $groups as $group ) {
		foreach ( array_chunk( $group['ids'], $batch_size ) as $chunk ) {
			$touched = array_merge(
				$touched,
				init_plugin_suite_view_count_rollover_chunk( $chunk, $group['current_key'], $group['prev_key'] )
			);
		}
	}

	return $touched;
}

/**
 * Rollover cho 1 lô post (xem init_plugin_suite_view_count_bulk_rollover_meta()).
 *
 * Ghi chú chung cho các câu SQL bên dưới: $in, $cases, $values chỉ chứa chuỗi giữ chỗ
 * ('%d', '%s', 'WHEN %d THEN %s', '(%d, %s, %s)') lặp lại theo số phần tử — không hề
 * chèn giá trị/input vào SQL text; mọi giá trị thật đều đi qua $wpdb->prepare().
 * PHPCS không phân tích tĩnh được nội dung các biến này nên báo nhầm các sniff
 * PreparedSQL/PreparedSQLPlaceholders — tắt có chủ đích trong phạm vi hàm này.
 *
 * @param int[]  $chunk       Post ID trong lô.
 * @param string $current_key Meta key kỳ hiện tại (đã resolve).
 * @param string $prev_key    Meta key kỳ trước (đã resolve).
 * @return int[] Post ID đã bị thay đổi.
 */
function init_plugin_suite_view_count_rollover_chunk( array $chunk, $current_key, $prev_key ) {
	global $wpdb;

	$chunk = array_values( array_unique( array_map( 'intval', $chunk ) ) );
	if ( empty( $chunk ) ) {
		return array();
	}

	$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	// 1) Giá trị kỳ hiện tại (lấy row đầu tiên theo meta_id — đúng như get_post_meta($id, $key, true)).
	$current_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($in) ORDER BY meta_id ASC",
			array_merge( array( $current_key ), $chunk )
		)
	);

	$current = array();
	foreach ( (array) $current_rows as $row ) {
		$pid = (int) $row->post_id;
		if ( ! isset( $current[ $pid ] ) ) {
			$current[ $pid ] = (string) $row->meta_value;
		}
	}

	// 2) Trạng thái prev-key hiện có.
	$prev_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($in) ORDER BY meta_id ASC",
			array_merge( array( $prev_key ), $chunk )
		)
	);

	$prev_count = array();
	$prev_value = array();
	foreach ( (array) $prev_rows as $row ) {
		$pid                = (int) $row->post_id;
		$prev_count[ $pid ] = ( $prev_count[ $pid ] ?? 0 ) + 1;
		if ( ! isset( $prev_value[ $pid ] ) ) {
			$prev_value[ $pid ] = (string) $row->meta_value;
		}
	}

	// 3) Lập kế hoạch ghi tối thiểu.
	$to_update  = array(); // post_id => value (đang có đúng 1 row prev-key).
	$to_insert  = array(); // post_id => value (chưa có row prev-key).
	$to_replace = array(); // post_id (có >1 row prev-key → dọn sạch rồi insert lại).

	foreach ( $chunk as $pid ) {
		$target = isset( $current[ $pid ] ) ? $current[ $pid ] : '0';
		$count  = $prev_count[ $pid ] ?? 0;

		if ( 1 === $count ) {
			if ( $prev_value[ $pid ] !== $target ) {
				$to_update[ $pid ] = $target;
			}
		} elseif ( 0 === $count ) {
			$to_insert[ $pid ] = $target;
		} else {
			$to_replace[]      = $pid;
			$to_insert[ $pid ] = $target;
		}
	}

	if ( ! empty( $to_replace ) ) {
		$in_replace = implode( ',', array_fill( 0, count( $to_replace ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($in_replace)",
				array_merge( array( $prev_key ), $to_replace )
			)
		);
	}

	if ( ! empty( $to_update ) ) {
		$cases     = implode( ' ', array_fill( 0, count( $to_update ), 'WHEN %d THEN %s' ) );
		$in_update = implode( ',', array_fill( 0, count( $to_update ), '%d' ) );
		$args      = array();
		foreach ( $to_update as $pid => $value ) {
			$args[] = $pid;
			$args[] = $value;
		}
		$args[] = $prev_key;
		$args   = array_merge( $args, array_keys( $to_update ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = CASE post_id $cases END WHERE meta_key = %s AND post_id IN ($in_update)",
				$args
			)
		);
	}

	if ( ! empty( $to_insert ) ) {
		$values = implode( ',', array_fill( 0, count( $to_insert ), '(%d, %s, %s)' ) );
		$args   = array();
		foreach ( $to_insert as $pid => $value ) {
			$args[] = $pid;
			$args[] = $prev_key;
			$args[] = $value;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES $values",
				$args
			)
		);
	}

	// 4) Xoá giá trị kỳ hiện tại — chỉ cho post đang có row.
	if ( ! empty( $current ) ) {
		$current_ids = array_keys( $current );
		$in_current  = implode( ',', array_fill( 0, count( $current_ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($in_current)",
				array_merge( array( $current_key ), $current_ids )
			)
		);
	}

	// phpcs:enable

	return array_values(
		array_unique(
			array_merge(
				array_keys( $current ),
				array_keys( $to_update ),
				array_keys( $to_insert )
			)
		)
	);
}
