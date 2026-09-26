<?php
/**
 * Cron reset hàng ngày (00:01 theo timezone site) + Trending Engine (chạy mỗi giờ).
 *
 * @package Init_View_Count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init_plugin_suite_view_count_reset_counts', 'init_plugin_suite_view_count_reset_counts' );
add_action( 'init_plugin_suite_view_count_cron_update_trending', 'init_plugin_suite_view_count_cron_update_trending' );

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

// === CRON: UPDATE TRENDING ===

/**
 * Kiểm tra có đang bật tính Trending hay không.
 *
 * @return bool
 */
function init_view_count_trending_enabled(): bool {
	// Mặc định 0 = KHÔNG tắt → Trending đang bật.
	return 0 === (int) get_option( 'init_plugin_suite_view_count_disable_trending', 0 );
}

/**
 * Internal helper: lấy top post ID theo 1 meta key.
 *
 * @param string   $meta_key   Meta key.
 * @param string[] $post_types Post types.
 * @param int      $limit      Số lượng tối đa.
 * @return int[]
 */
function init_plugin_suite_view_count_fetch_ids_by_key( $meta_key, $post_types, $limit ) {
	if ( empty( $meta_key ) ) {
		return array();
	}

	$q = new WP_Query(
		array(
			'post_type'              => $post_types,
			'posts_per_page'         => $limit,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'               => $meta_key,
			'orderby'                => 'meta_value_num',
			'order'                  => 'DESC',
			'post_status'            => 'publish',
			'no_found_rows'          => true,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// Xếp hạng theo lượt xem thực tế, không để sticky post chen vào danh sách ứng viên.
			'ignore_sticky_posts'    => true,
		)
	);

	return ! empty( $q->posts ) ? array_map( 'intval', $q->posts ) : array();
}

/**
 * Update Trending: multi-key fallback + rank fusion.
 *
 * @return void
 */
function init_plugin_suite_view_count_cron_update_trending() {
	if ( ! init_view_count_trending_enabled() ) {
		return;
	}

	$limit     = max( 1, (int) apply_filters( 'init_plugin_suite_view_count_trending_limit', 100 ) );
	$min_count = (int) apply_filters( 'init_plugin_suite_view_count_trending_min_count', 20 );

	$post_types = (array) get_option( 'init_plugin_suite_view_count_post_types', array( 'post' ) );
	$post_types = array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) );
	$post_types = array_values( array_diff( $post_types, array( 'attachment' ) ) );
	$post_types = apply_filters( 'init_plugin_suite_view_count_trending_post_types', $post_types );
	if ( empty( $post_types ) ) {
		$post_types = array( 'post' );
	}

	$day_key   = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_day_count', null );
	$week_key  = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_week_count', null );
	$month_key = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_month_count', null );
	$total_key = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_count', null );

	$day_ids = init_plugin_suite_view_count_fetch_ids_by_key( $day_key, $post_types, $limit );

	// Nếu đủ theo ngày thì dùng luôn để giữ hành vi cũ.
	if ( count( $day_ids ) >= $min_count ) {
		init_plugin_suite_view_count_calculate_trending( array_slice( $day_ids, 0, $limit ) );
		return;
	}

	// Chỉ query thêm khi cần fallback.
	$week_ids  = init_plugin_suite_view_count_fetch_ids_by_key( $week_key, $post_types, $limit );
	$month_ids = init_plugin_suite_view_count_fetch_ids_by_key( $month_key, $post_types, $limit );
	$total_ids = init_plugin_suite_view_count_fetch_ids_by_key( $total_key, $post_types, $limit );

	// ====== Rank-fusion fallback ======
	$day_rank   = array_flip( $day_ids );
	$week_rank  = array_flip( $week_ids );
	$month_rank = array_flip( $month_ids );
	$total_rank = array_flip( $total_ids );

	// Ứng viên: nối day → week → month → total, tránh trùng (tra cứu O(1)), tối đa $limit.
	$candidates = array();
	$seen       = array();
	foreach ( array( $day_ids, $week_ids, $month_ids, $total_ids ) as $list ) {
		foreach ( $list as $pid ) {
			if ( ! isset( $seen[ $pid ] ) ) {
				$seen[ $pid ] = true;
				$candidates[] = $pid;
				if ( count( $candidates ) >= $limit ) {
					break 2;
				}
			}
		}
	}

	$weights = wp_parse_args(
		(array) apply_filters(
			'init_plugin_suite_view_count_trending_weights',
			array(
				'day'   => 1.0,
				'week'  => 0.6,
				'month' => 0.3,
				'total' => 0.15,
			)
		),
		array(
			'day'   => 0.0,
			'week'  => 0.0,
			'month' => 0.0,
			'total' => 0.0,
		)
	);

	$day_bonus = (float) apply_filters( 'init_plugin_suite_view_count_trending_day_presence_bonus', 0.02 );
	$get_part  = function ( $r ) use ( $limit ) {
		return 1.0 - ( $r / max( 1, $limit ) );
	};

	$scores = array();
	foreach ( $candidates as $pid ) {
		$s = 0.0;
		if ( isset( $day_rank[ $pid ] ) ) {
			$s += $weights['day'] * $get_part( $day_rank[ $pid ] );
		}
		if ( isset( $week_rank[ $pid ] ) ) {
			$s += $weights['week'] * $get_part( $week_rank[ $pid ] );
		}
		if ( isset( $month_rank[ $pid ] ) ) {
			$s += $weights['month'] * $get_part( $month_rank[ $pid ] );
		}
		if ( isset( $total_rank[ $pid ] ) ) {
			$s += $weights['total'] * $get_part( $total_rank[ $pid ] );
		}
		// Bonus nếu có mặt ở day list (cộng cuối cùng, giữ đúng thứ tự phép cộng như bản cũ).
		if ( isset( $day_rank[ $pid ] ) ) {
			$s += $day_bonus;
		}
		$scores[ $pid ] = $s;
	}

	// Sort theo score desc, tie-break bằng rank ngày → rank tuần → ID mới hơn.
	usort(
		$candidates,
		function ( $a, $b ) use ( $scores, $day_rank, $week_rank ) {
			$sa = $scores[ $a ] ?? 0;
			$sb = $scores[ $b ] ?? 0;
			if ( $sa === $sb ) {
				$ra = $day_rank[ $a ] ?? PHP_INT_MAX;
				$rb = $day_rank[ $b ] ?? PHP_INT_MAX;
				if ( $ra === $rb ) {
					$wa = $week_rank[ $a ] ?? PHP_INT_MAX;
					$wb = $week_rank[ $b ] ?? PHP_INT_MAX;
					if ( $wa === $wb ) {
						return $b <=> $a; // ID mới trước.
					}
					return $wa <=> $wb;
				}
				return $ra <=> $rb;
			}
			return ( $sa < $sb ) ? 1 : -1;
		}
	);

	// Đảm bảo tối thiểu min_count (nếu vẫn thiếu, tiếp tục bơm từ các list dài hơn).
	if ( count( $candidates ) < $min_count ) {
		foreach ( array( $week_ids, $month_ids, $total_ids ) as $more ) {
			foreach ( $more as $pid ) {
				if ( ! isset( $seen[ $pid ] ) ) {
					$seen[ $pid ] = true;
					$candidates[] = $pid;
					if ( count( $candidates ) >= $min_count ) {
						break 2;
					}
				}
			}
		}
	}

	$final_ids = array_slice( $candidates, 0, max( $min_count, $limit ) );
	if ( ! empty( $final_ids ) ) {
		init_plugin_suite_view_count_calculate_trending( $final_ids );
	}
}

// ==========================================
// == TRẠNG THÁI LIÊN-LƯỢT-CHẠY CỦA TRENDING ==
// ==========================================

/**
 * Kho trạng thái dùng chung giữa các lần chạy Trending (EWMA, score kỳ trước, streak top),
 * lưu trong ĐÚNG 1 option không autoload.
 *
 * Bản cũ lưu mỗi giá trị của mỗi post thành 1 transient/cache riêng (last_score_{id},
 * top_streak_{id}, ewma_v_{id}) → mỗi giờ hàng trăm lượt get/set transient (mỗi lượt
 * 2–4 query trên site không có persistent object cache), và EWMA thực chất không bao
 * giờ hoạt động trên site không có persistent object cache (wp_cache mất sau mỗi request).
 * Nay: 1 lần đọc + 1 lần ghi option cho mỗi lượt chạy, EWMA hoạt động ổn định trên mọi
 * site. TTL của từng giá trị vẫn giữ nguyên như bản cũ (lưu mốc hết hạn kèm giá trị).
 *
 * @return array Tham chiếu tới kho trạng thái.
 */
function &init_plugin_suite_view_count_trending_state_store() {
	static $store = null;

	if ( null === $store ) {
		$raw   = get_option( 'init_plugin_suite_view_count_trending_state', array() );
		$store = array(
			'data'   => is_array( $raw ) ? $raw : array(),
			'dirty'  => false,
			'hooked' => false,
		);

		// Dọn giá trị đã hết hạn.
		$now = time();
		foreach ( $store['data'] as $section => $rows ) {
			if ( ! is_array( $rows ) ) {
				unset( $store['data'][ $section ] );
				$store['dirty'] = true;
				continue;
			}
			foreach ( $rows as $id => $row ) {
				if ( ! is_array( $row ) || (int) ( $row['e'] ?? 0 ) <= $now ) {
					unset( $store['data'][ $section ][ $id ] );
					$store['dirty'] = true;
				}
			}
		}
	}

	return $store;
}

/**
 * Đánh dấu kho trạng thái đã thay đổi (tự lưu khi kết thúc request nếu chưa lưu).
 *
 * @return void
 */
function init_plugin_suite_view_count_trending_state_touch() {
	$store          = &init_plugin_suite_view_count_trending_state_store();
	$store['dirty'] = true;

	if ( ! $store['hooked'] ) {
		$store['hooked'] = true;
		add_action( 'shutdown', 'init_plugin_suite_view_count_trending_state_save' );
	}
}

/**
 * Đọc 1 giá trị trạng thái.
 *
 * @param string $section Nhóm ('ewma', 'score', 'streak').
 * @param int    $post_id Post ID.
 * @return mixed|false False nếu không có hoặc đã hết hạn.
 */
function init_plugin_suite_view_count_trending_state_get( $section, $post_id ) {
	$store = &init_plugin_suite_view_count_trending_state_store();
	$row   = $store['data'][ $section ][ (int) $post_id ] ?? null;

	if ( ! is_array( $row ) || (int) ( $row['e'] ?? 0 ) <= time() ) {
		return false;
	}

	return $row['v'] ?? false;
}

/**
 * Ghi 1 giá trị trạng thái với TTL.
 *
 * @param string $section Nhóm.
 * @param int    $post_id Post ID.
 * @param mixed  $value   Giá trị.
 * @param int    $ttl     Thời gian sống (giây).
 * @return void
 */
function init_plugin_suite_view_count_trending_state_set( $section, $post_id, $value, $ttl ) {
	$store = &init_plugin_suite_view_count_trending_state_store();

	$store['data'][ $section ][ (int) $post_id ] = array(
		'v' => $value,
		'e' => time() + max( 1, (int) $ttl ),
	);

	init_plugin_suite_view_count_trending_state_touch();
}

/**
 * Xoá 1 giá trị trạng thái.
 *
 * @param string $section Nhóm.
 * @param int    $post_id Post ID.
 * @return void
 */
function init_plugin_suite_view_count_trending_state_delete( $section, $post_id ) {
	$store = &init_plugin_suite_view_count_trending_state_store();

	if ( isset( $store['data'][ $section ][ (int) $post_id ] ) ) {
		unset( $store['data'][ $section ][ (int) $post_id ] );
		init_plugin_suite_view_count_trending_state_touch();
	}
}

/**
 * Lưu kho trạng thái xuống DB (chỉ khi có thay đổi).
 *
 * @return void
 */
function init_plugin_suite_view_count_trending_state_save() {
	$store = &init_plugin_suite_view_count_trending_state_store();

	if ( ! $store['dirty'] ) {
		return;
	}

	update_option( 'init_plugin_suite_view_count_trending_state', $store['data'], false );
	$store['dirty'] = false;
}

// ==========================================
// == CORE: TÍNH ĐIỂM TRENDING              ==
// ==========================================

/**
 * Tính điểm trending cho danh sách post ứng viên và lưu vào transient.
 *
 * @param int[] $post_ids Post ID ứng viên.
 * @return array Danh sách trending cuối cùng.
 */
function init_plugin_suite_view_count_calculate_trending( array $post_ids ) {
	if ( ! init_view_count_trending_enabled() ) {
		return array();
	}

	$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );

	$lock_key   = 'trending_calculation_lock';
	$lock_group = 'init_ps';

	if ( ! wp_cache_add( $lock_key, time(), $lock_group, 300 ) ) {
		$cached = get_transient( 'init_plugin_suite_view_count_trending' );
		return $cached ? $cached : array();
	}

	$trending = array();

	// Unix timestamp THẬT — so sánh trực tiếp được với get_post_time('U', true).
	// (Bản cũ dùng timestamp "local" → tuổi bài bị lệch đúng bằng offset múi giờ:
	// site GMT+7 bị cộng thêm 7 giờ tuổi, làm sai freshness boost/time decay.)
	$now = time();

	$last_run = (int) get_transient( 'trending_last_calculation' );
	// $last_run > $now: giá trị "local" do bản cũ lưu (site múi giờ dương) → coi như cần tính lại.
	if ( $last_run && $last_run <= $now && ( $now - $last_run ) < 3300 ) {
		wp_cache_delete( $lock_key, $lock_group );
		$cached = get_transient( 'init_plugin_suite_view_count_trending' );
		return $cached ? $cached : array();
	}

	// Nạp sẵn post + term + meta của toàn bộ ứng viên trong vài query (tránh N+1).
	if ( function_exists( '_prime_post_caches' ) ) {
		_prime_post_caches( $post_ids, true, true );
	}
	update_meta_cache( 'post', $post_ids );

	$view_cache = array();
	$post_cache = array();

	foreach ( $post_ids as $post_id ) {
		$day_meta_key   = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_day_count', $post_id );
		$week_meta_key  = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_week_count', $post_id );
		$month_meta_key = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_month_count', $post_id );
		$total_meta_key = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_count', $post_id );

		$view_cache[ $post_id ] = array(
			'day'   => (int) get_post_meta( $post_id, $day_meta_key, true ),
			'week'  => (int) get_post_meta( $post_id, $week_meta_key, true ),
			'month' => (int) get_post_meta( $post_id, $month_meta_key, true ),
			'total' => (int) get_post_meta( $post_id, $total_meta_key, true ),
		);

		$post = get_post( $post_id );
		if ( $post ) {
			$post_cache[ $post_id ] = array(
				'timestamp' => get_post_time( 'U', true, $post ),
				'category'  => init_plugin_suite_view_count_get_cached_term_ids( $post_id, 'category' ),
				'tags'      => init_plugin_suite_view_count_get_cached_term_ids( $post_id, 'post_tag' ),
				'author_id' => (int) $post->post_author,
				'comments'  => (int) $post->comment_count,
			);
		}
	}

	$weights = wp_parse_args(
		apply_filters( 'init_plugin_suite_view_count_trending_component_weights', array() ),
		array(
			'velocity'   => 1.0,
			'engagement' => 1.0,
			'freshness'  => 1.0,
			'momentum'   => 1.0,
			'uplift'     => 1.0,  // Seasonality-aware uplift.
			'ewma'       => 1.0,  // Momentum theo EWMA.
			'fatigue'    => 1.0,  // Giảm theo exposure.
			'explore'    => 1.0,  // Tỷ lệ explore.
			'mmr'        => 1.0,  // Mức đa dạng nội dung.
		)
	);

	foreach ( $post_ids as $post_id ) {
		$views     = $view_cache[ $post_id ] ?? null;
		$post_data = $post_cache[ $post_id ] ?? null;

		if ( ! $views || ! $post_data ) {
			continue;
		}

		$post_timestamp = (int) ( $post_data['timestamp'] ?? 0 );
		if ( $post_timestamp <= 0 ) {
			continue;
		}

		$age_hours = max( 0.5, ( $now - $post_timestamp ) / 3600.0 );

		// --- Day views fallback (đầu ngày day thường = 0) ---
		$views_eff = $views;

		if ( 0 === (int) $views_eff['day'] ) {
			// Ước lượng day_views từ week với tiến độ trong ngày (0..1).
			$weekly_avg_per_day = $views['week'] > 0 ? ( $views['week'] / 7.0 ) : 0.0;
			$progress_in_day    = min( 1.0, max( 0.05, $age_hours / 24.0 ) );
			$est_day            = (int) round( $weekly_avg_per_day * $progress_in_day );

			if ( $views['month'] > 0 ) {
				$monthly_avg_per_day = $views['month'] / 30.0;
				$est_day             = max( $est_day, (int) round( 0.3 * $monthly_avg_per_day * $progress_in_day ) );
			}

			$views_eff['day'] = max( 1, $est_day );
		}

		$velocity_score     = init_plugin_suite_view_count_calculate_velocity_score( $views_eff, $age_hours );
		$time_decay         = init_plugin_suite_view_count_calculate_time_decay( $age_hours );
		$engagement_quality = init_plugin_suite_view_count_calculate_engagement_quality( $post_id, $views_eff, $post_data['comments'] );
		$freshness_boost    = init_plugin_suite_view_count_calculate_freshness_boost( $age_hours );
		$category_momentum  = init_plugin_suite_view_count_calculate_category_momentum( $post_data['category'], $post_data['tags'] );

		list( $expected_views, $uplift_mult, $uplift_raw ) = init_plugin_suite_view_count_expected_views( $views, $age_hours, $post_data['category'] );
		list( $ewma_val, $ewma_mult, $acc )                = init_plugin_suite_view_count_ewma_velocity( $post_id, $views, $age_hours );
		$anti_gaming_mult                                  = init_plugin_suite_view_count_anti_gaming_multiplier( $views );

		$base_score  = $velocity_score * $time_decay;
		$final_score = $base_score
			* pow( $engagement_quality, $weights['engagement'] )
			* pow( $freshness_boost, $weights['freshness'] )
			* pow( $category_momentum, $weights['momentum'] )
			* pow( $uplift_mult, $weights['uplift'] )
			* pow( $ewma_mult, $weights['ewma'] )
			* $anti_gaming_mult;

		// Soft cap + kẹp tăng trưởng liên run.
		$normalized_score = 10000 * ( 1 - exp( -$final_score / 5000 ) );
		$normalized_score = init_plugin_suite_view_count_cap_score_growth( $post_id, $normalized_score );

		$trending[] = array(
			'id'                => $post_id,
			'score'             => round( $normalized_score, 4 ),
			'views'             => $views['day'],
			'views_day'         => $views['day'],
			'views_week'        => $views['week'],
			'views_month'       => $views['month'],
			'views_total'       => $views['total'],
			'views_day_used'    => (int) $views_eff['day'],
			'used_day_fallback' => (int) ( 0 === $views['day'] ),
			'age_hours'         => round( $age_hours, 2 ),
			'time'              => $now,
			'author_id'         => $post_data['author_id'],
			'categories'        => $post_data['category'],
			'tags'              => $post_data['tags'],
			'components'        => array(
				'velocity'   => round( $velocity_score, 4 ),
				'time_decay' => round( $time_decay, 4 ),
				'engagement' => round( $engagement_quality, 4 ),
				'freshness'  => round( $freshness_boost, 4 ),
				'momentum'   => round( $category_momentum, 4 ),
				'uplift'     => round( $uplift_mult, 4 ),
				'uplift_raw' => round( $uplift_raw, 4 ),
				'expected'   => round( $expected_views, 2 ),
				'ewma'       => round( $ewma_mult, 4 ),
				'ewma_val'   => round( $ewma_val, 4 ),
				'acc'        => round( $acc, 4 ),
			),
		);
	}

	// Rank lần 1.
	usort(
		$trending,
		function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		}
	);

	// Mark top flags (trước khi fatigue/MMR) để tính streak.
	$top_hash = array();
	foreach ( array_slice( $trending, 0, 20 ) as $row ) {
		$top_hash[ $row['id'] ] = true;
	}

	// Exposure fatigue.
	foreach ( $trending as &$item ) {
		list( $fatigue_mult, $streak ) = init_plugin_suite_view_count_exposure_fatigue_multiplier( $item['id'], isset( $top_hash[ $item['id'] ] ) );

		$item['score']                       *= pow( $fatigue_mult, $weights['fatigue'] );
		$item['components']['fatigue']        = round( $fatigue_mult, 4 );
		$item['components']['fatigue_streak'] = (int) $streak;
	}
	unset( $item );

	// Rank lần 2 (sau fatigue).
	usort(
		$trending,
		function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		}
	);

	// MMR re-rank (đa dạng nội dung sâu).
	$mmr_out = init_plugin_suite_view_count_mmr_rerank( $trending, 0.75, 20 );

	// Explore/Exploit: bơm thử bài tiềm năng.
	$mmr_out = init_plugin_suite_view_count_maybe_explore( $mmr_out, $trending, $weights );

	// Diversity Filter quota (fill-back O(n)).
	$top_trending = init_plugin_suite_view_count_apply_diversity_filter( $mmr_out, 20 );

	set_transient( 'init_plugin_suite_view_count_trending', $top_trending, DAY_IN_SECONDS );
	set_transient( 'init_plugin_suite_view_count_trending_debug', array_slice( $trending, 0, 50 ), DAY_IN_SECONDS );
	set_transient( 'trending_last_calculation', $now, DAY_IN_SECONDS );

	// Ghi trạng thái EWMA/score/streak xuống DB đúng 1 lần.
	init_plugin_suite_view_count_trending_state_save();

	wp_cache_delete( $lock_key, $lock_group );

	return $top_trending;
}

/**
 * Lấy danh sách term ID của post trong 1 taxonomy, đọc từ object term cache
 * (đã được nạp sẵn bằng _prime_post_caches()) thay vì query riêng cho từng post.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy.
 * @return int[]
 */
function init_plugin_suite_view_count_get_cached_term_ids( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );

	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return array();
	}

	return array_values( array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ) );
}

// ===================================================
// == CÁC THÀNH PHẦN ĐIỂM                           ==
// ===================================================

/**
 * Velocity Score - Tốc độ tăng trưởng lượt xem.
 *
 * @param array $views     View day/week/month.
 * @param float $age_hours Tuổi bài (giờ).
 * @return float
 */
function init_plugin_suite_view_count_calculate_velocity_score( $views, $age_hours ) {
	$day_views   = $views['day'];
	$week_views  = $views['week'];
	$month_views = $views['month'];

	$weekly_avg  = $week_views > 0 ? $week_views / 7 : 0;
	$monthly_avg = $month_views > 0 ? $month_views / 30 : 0;

	$acceleration = 1.0;
	if ( $weekly_avg > 0 && $day_views > $weekly_avg ) {
		$acceleration += ( $day_views / $weekly_avg - 1 ) * 0.3;
	}
	if ( $monthly_avg > 0 && $weekly_avg > $monthly_avg ) {
		$acceleration += ( $weekly_avg / $monthly_avg - 1 ) * 0.2;
	}

	// Base velocity: views per hour.
	$base_velocity = $day_views / min( $age_hours, 24 );

	// Logarithmic scaling để tránh bias cho posts có views cực cao.
	$scaled_velocity = log( 1 + $base_velocity ) * 10;

	return $scaled_velocity * $acceleration;
}

/**
 * Time Decay - Giảm điểm theo thời gian (half-life 36h, 2h đầu không decay).
 *
 * @param float $age_hours Tuổi bài (giờ).
 * @return float
 */
function init_plugin_suite_view_count_calculate_time_decay( $age_hours ) {
	if ( $age_hours <= 2 ) {
		return 1.0;
	}
	$decay_rate = 0.693; // Logarit tự nhiên của 2.
	$half_life  = 36;
	return exp( -$decay_rate * ( $age_hours - 2 ) / $half_life );
}

/**
 * Engagement Quality - Chất lượng tương tác (với smoothing).
 *
 * @param int      $post_id        Post ID.
 * @param array    $views          View day/week/month.
 * @param int|null $comments_count Số comment đã duyệt (null = tự tra cứu như bản cũ).
 * @return float
 */
function init_plugin_suite_view_count_calculate_engagement_quality( $post_id, $views, $comments_count = null ) {
	if ( null === $comments_count ) {
		$counts         = wp_count_comments( $post_id );
		$comments_count = isset( $counts->approved ) ? (int) $counts->approved : 0;
	}

	$meta_keys = apply_filters(
		'init_plugin_suite_view_count_engagement_meta_keys',
		array(
			'likes'  => '_likes_count',
			'shares' => '_shares_count',
		),
		$post_id
	);

	$likes_count  = ! empty( $meta_keys['likes'] ) ? (int) get_post_meta( $post_id, $meta_keys['likes'], true ) : 0;
	$shares_count = ! empty( $meta_keys['shares'] ) ? (int) get_post_meta( $post_id, $meta_keys['shares'], true ) : 0;

	$total_views        = max( 5, $views['day'] );
	$engagement_actions = (int) $comments_count + $likes_count + $shares_count;
	$engagement_rate    = $engagement_actions / $total_views;

	// Convert to multiplier (1.0 - 2.0).
	return 1 + min( $engagement_rate * 10, 1.0 );
}

/**
 * Freshness Boost - Boost cho content mới.
 *
 * @param float $age_hours Tuổi bài (giờ).
 * @return float
 */
function init_plugin_suite_view_count_calculate_freshness_boost( $age_hours ) {
	if ( $age_hours <= 1 ) {
		return 1.8;
	}
	if ( $age_hours <= 3 ) {
		return 1.4;
	}
	if ( $age_hours <= 6 ) {
		return 1.2;
	}
	if ( $age_hours <= 12 ) {
		return 1.1;
	}
	if ( $age_hours <= 24 ) {
		return 1.05;
	}
	return 1.0;
}

/**
 * Category Momentum - Xu hướng theo chủ đề.
 *
 * @param int[] $categories Category IDs.
 * @param int[] $tags       Tag IDs.
 * @return float
 */
function init_plugin_suite_view_count_calculate_category_momentum( $categories, $tags ) {
	$hot_topics = init_plugin_suite_view_count_get_hot_topics_last_24h();

	$momentum_boost = 1.0;

	foreach ( (array) $categories as $cat_id ) {
		if ( isset( $hot_topics['categories'][ $cat_id ] ) ) {
			$momentum_boost *= ( 1 + $hot_topics['categories'][ $cat_id ] * 0.1 );
		}
	}

	foreach ( (array) $tags as $tag_id ) {
		if ( isset( $hot_topics['tags'][ $tag_id ] ) ) {
			$momentum_boost *= ( 1 + $hot_topics['tags'][ $tag_id ] * 0.05 );
		}
	}

	return min( $momentum_boost, 1.5 ); // Cap tối đa 50%.
}

/**
 * Diversity Filter - Đảm bảo đa dạng content (với fill-back O(n)).
 *
 * @param array $trending_posts Danh sách đã xếp hạng.
 * @param int   $limit          Số lượng tối đa.
 * @return array
 */
function init_plugin_suite_view_count_apply_diversity_filter( $trending_posts, $limit ) {
	$selected       = array();
	$chosen         = array();
	$category_count = array();
	$author_count   = array();

	$all_authors    = array();
	$all_categories = array();

	foreach ( $trending_posts as $post ) {
		$all_authors[ $post['author_id'] ] = true;

		foreach ( ( $post['categories'] ?? array() ) as $cat_id ) {
			$all_categories[ $cat_id ] = true;
		}
	}

	$max_per_author   = 2;
	$max_per_category = 3;

	// Nếu số lượng author * max_per_author < limit → không nên áp dụng giới hạn.
	if ( count( $all_authors ) * $max_per_author < $limit ) {
		$max_per_author = $limit;
	}

	if ( count( $all_categories ) * $max_per_category < $limit ) {
		$max_per_category = $limit;
	}

	$selected_count = 0;

	// Pass 1: Apply diversity filters.
	foreach ( $trending_posts as $post ) {
		$author_id = $post['author_id'];
		$cats      = $post['categories'] ?? array();

		$author_ok = ( $author_count[ $author_id ] ?? 0 ) < $max_per_author;

		$category_ok = true;
		foreach ( $cats as $cat_id ) {
			if ( ( $category_count[ $cat_id ] ?? 0 ) >= $max_per_category ) {
				$category_ok = false;
				break;
			}
		}

		if ( $author_ok && $category_ok ) {
			$selected[]            = $post;
			$chosen[ $post['id'] ] = true;
			++$selected_count;

			$author_count[ $author_id ] = ( $author_count[ $author_id ] ?? 0 ) + 1;
			foreach ( $cats as $cat_id ) {
				$category_count[ $cat_id ] = ( $category_count[ $cat_id ] ?? 0 ) + 1;
			}

			if ( $selected_count >= $limit ) {
				break;
			}
		}
	}

	// Pass 2: Fill remaining slots nếu chưa đủ.
	if ( $selected_count < $limit ) {
		foreach ( $trending_posts as $post ) {
			if ( isset( $chosen[ $post['id'] ] ) ) {
				continue;
			}
			$selected[] = $post;
			++$selected_count;
			if ( $selected_count >= $limit ) {
				break;
			}
		}
	}

	return $selected;
}

/**
 * Lấy hot topics (category/tag) trong 24h qua.
 *
 * Bản mới lấy thẳng term_id + taxonomy ngay trong câu SQL (chỉ category/post_tag),
 * thay vì GROUP_CONCAT mọi term_taxonomy_id rồi gọi get_term() cho từng ID:
 * - Sửa lỗi: bản cũ truyền term_taxonomy_id vào get_term() như thể đó là term_id —
 *   hai giá trị này KHÔNG phải lúc nào cũng bằng nhau (site cũ đã từng tách/gộp term,
 *   site migrate...), dẫn tới cộng điểm "hot" nhầm sang term khác.
 * - Hiệu năng: bỏ hoàn toàn N lượt get_term() (mỗi lượt 1 query khi không có
 *   persistent object cache).
 *
 * @return array{categories: array<int,float>, tags: array<int,float>}
 */
function init_plugin_suite_view_count_get_hot_topics_last_24h() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}

	$cached = get_transient( 'hot_topics_24h' );
	if ( false !== $cached && is_array( $cached ) ) {
		$memo = $cached;
		return $memo;
	}

	global $wpdb;

	$day_meta_key = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_day_count', 0 );
	$gmt_24h_ago  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Kết quả được cache bằng transient 2 giờ ngay bên dưới.
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID,
				MAX(CAST(pm_day.meta_value AS UNSIGNED)) AS day_views,
				GROUP_CONCAT(DISTINCT CONCAT(IF(tt.taxonomy = 'category', 'c', 't'), tt.term_id)) AS term_refs
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_day ON p.ID = pm_day.post_id AND pm_day.meta_key = %s
			LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				AND tt.taxonomy IN ('category', 'post_tag')
			WHERE p.post_status = 'publish'
				AND p.post_date_gmt >= %s
				AND CAST(pm_day.meta_value AS UNSIGNED) > 0
			GROUP BY p.ID
			ORDER BY day_views DESC
			LIMIT 100",
			$day_meta_key,
			$gmt_24h_ago
		)
	);
	// phpcs:enable

	$hot_topics = array(
		'categories' => array(),
		'tags'       => array(),
	);

	foreach ( (array) $results as $row ) {
		if ( empty( $row->term_refs ) ) {
			continue;
		}

		$views = (int) $row->day_views;

		foreach ( explode( ',', $row->term_refs ) as $ref ) {
			$ref     = trim( $ref );
			$term_id = (int) substr( $ref, 1 );

			if ( $term_id <= 0 ) {
				continue;
			}

			if ( 'c' === $ref[0] ) {
				$hot_topics['categories'][ $term_id ] = ( $hot_topics['categories'][ $term_id ] ?? 0 ) + $views;
			} else {
				$hot_topics['tags'][ $term_id ] = ( $hot_topics['tags'][ $term_id ] ?? 0 ) + $views;
			}
		}
	}

	// Normalize scores (0-1).
	$max_cat_views = ! empty( $hot_topics['categories'] ) ? max( $hot_topics['categories'] ) : 1;
	$max_tag_views = ! empty( $hot_topics['tags'] ) ? max( $hot_topics['tags'] ) : 1;

	foreach ( $hot_topics['categories'] as $id => $views ) {
		$hot_topics['categories'][ $id ] = $views / max( 1, $max_cat_views );
	}

	foreach ( $hot_topics['tags'] as $id => $views ) {
		$hot_topics['tags'][ $id ] = $views / max( 1, $max_tag_views );
	}

	set_transient( 'hot_topics_24h', $hot_topics, HOUR_IN_SECONDS * 2 );

	$memo = $hot_topics;
	return $memo;
}

// =====================================
// == SHAPE & UPLIFT                  ==
// =====================================

/**
 * Traffic shape (giờ/thứ) đã chuẩn hoá mean = 1.
 *
 * @return array{hour: float[], wday: float[]}
 */
function init_plugin_suite_view_count_get_site_traffic_shape() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}

	$cache = get_transient( 'init_plugin_suite_view_count_site_traffic_shape' );
	if ( false !== $cache && is_array( $cache ) ) {
		$memo = $cache;
		return $memo;
	}

	$shape = array(
		'hour' => array_fill( 0, 24, 1.0 ),
		'wday' => array_fill( 0, 7, 1.0 ),
	);

	$now_gmt = time();
	$hour    = (int) wp_date( 'G', $now_gmt );
	$wday    = (int) wp_date( 'w', $now_gmt );
	$shape   = apply_filters( 'init_plugin_suite_view_count_site_traffic_shape', $shape, $hour, $wday );

	$shape['hour'] = init_plugin_suite_view_count_shape_normalize_mean_one( (array) ( $shape['hour'] ?? array_fill( 0, 24, 1.0 ) ) );
	$shape['wday'] = init_plugin_suite_view_count_shape_normalize_mean_one( (array) ( $shape['wday'] ?? array_fill( 0, 7, 1.0 ) ) );

	set_transient( 'init_plugin_suite_view_count_site_traffic_shape', $shape, HOUR_IN_SECONDS * 2 );

	$memo = $shape;
	return $memo;
}

/**
 * Kỳ vọng số view trong ngày (seasonality-aware) và hệ số uplift.
 *
 * @param array $views           View day/week/month/total.
 * @param float $age_hours       Tuổi bài (giờ).
 * @param int[] $post_categories Category IDs.
 * @return array{0: float, 1: float, 2: float} [expected, multiplier, raw uplift]
 */
function init_plugin_suite_view_count_expected_views( $views, $age_hours, $post_categories ) {
	$shape = init_plugin_suite_view_count_get_site_traffic_shape();

	$now_gmt = time();
	$hour    = (int) wp_date( 'G', $now_gmt );
	$wday    = (int) wp_date( 'w', $now_gmt );

	$week_avg_per_day = max( 0.0, $views['week'] / 7.0 );
	$progress         = min( 24.0, max( 1.0, $age_hours ) ) / 24.0;

	$hot_topics = init_plugin_suite_view_count_get_hot_topics_last_24h();
	$cat_hot    = 0.0;
	foreach ( (array) $post_categories as $cid ) {
		if ( ! empty( $hot_topics['categories'][ $cid ] ) ) {
			$cat_hot = max( $cat_hot, (float) $hot_topics['categories'][ $cid ] );
		}
	}

	$expected  = $week_avg_per_day * $progress;
	$expected *= $shape['hour'][ $hour ] ?? 1.0;
	$expected *= $shape['wday'][ $wday ] ?? 1.0;
	$expected *= ( 1.0 + 0.1 * $cat_hot );

	// Variance stabilization (Anscombe-ish).
	$obs    = max( 0.0, (float) $views['day'] );
	$uplift = ( sqrt( $obs + 0.375 ) - sqrt( max( 0.0, $expected ) + 0.375 ) );

	// Scale multiplier ~ [0.8 .. 1.5].
	$mult = 1.0 + max( -0.2, min( 0.5, $uplift * 0.15 ) );
	return array( $expected, $mult, $uplift );
}

// =====================================
// == EWMA & ANTI-GAME                ==
// =====================================

/**
 * EWMA của vận tốc view (views/giờ), half-life 6 lượt chạy.
 *
 * @param int   $post_id   Post ID.
 * @param array $views     View day/week/month/total.
 * @param float $age_hours Tuổi bài (giờ).
 * @return array{0: float, 1: float, 2: float} [ewma, multiplier, acceleration]
 */
function init_plugin_suite_view_count_ewma_velocity( $post_id, $views, $age_hours ) {
	$v = $views['day'] / max( 1.0, min( $age_hours, 24.0 ) );

	$half  = 6.0;
	$alpha = 1.0 - pow( 0.5, 1.0 / $half );

	$prev = init_plugin_suite_view_count_trending_state_get( 'ewma', $post_id );
	$prev = ( false === $prev ) ? $v : (float) $prev;

	$ewma = $alpha * $v + ( 1 - $alpha ) * $prev;
	init_plugin_suite_view_count_trending_state_set( 'ewma', $post_id, $ewma, HOUR_IN_SECONDS * 12 );

	// Acceleration (kẹp nhẹ).
	$acc = max( -0.5, min( 0.5, $ewma - $prev ) );

	// Multiplier ~ [0.8 .. 1.3].
	$mult = 1.0 + max( -0.2, min( 0.3, log( 1 + $ewma ) * 0.15 + $acc * 0.1 ) );
	return array( $ewma, $mult, $acc );
}

/**
 * Anti-gaming: phạt nhẹ các bài tăng đột biến bất thường so với lịch sử.
 *
 * @param array $views View day/month/total.
 * @return float
 */
function init_plugin_suite_view_count_anti_gaming_multiplier( $views ) {
	$day   = max( 0, (int) $views['day'] );
	$month = max( 1, (int) $views['month'] );
	$total = max( 1, (int) $views['total'] );

	$day_month_ratio = $day / max( 1, $month / 30.0 );
	$day_total_ratio = $day / max( 1, $total / 90.0 );

	$penalty = 1.0;
	if ( $day_month_ratio > 20 ) {
		$penalty *= 0.9;
	}
	if ( $day_total_ratio > 50 ) {
		$penalty *= 0.85;
	}

	return $penalty;
}

/**
 * Kẹp tốc độ tăng score giữa 2 lượt chạy (tối đa +80% + 10).
 *
 * @param int   $post_id Post ID.
 * @param float $score   Score mới.
 * @return float
 */
function init_plugin_suite_view_count_cap_score_growth( $post_id, $score ) {
	$prev = init_plugin_suite_view_count_trending_state_get( 'score', $post_id );
	if ( false !== $prev ) {
		$max_allowed = (float) $prev * 1.8 + 10;
		$score       = min( $score, $max_allowed );
	}
	init_plugin_suite_view_count_trending_state_set( 'score', $post_id, $score, HOUR_IN_SECONDS * 6 );
	return $score;
}

// =====================================
// == FATIGUE + MMR                   ==
// =====================================

/**
 * Exposure fatigue: bài đứng top liên tục > 6 lượt bắt đầu bị giảm nhẹ (tối thiểu 0.8).
 *
 * @param int  $post_id    Post ID.
 * @param bool $is_top_now Có đang trong top 20 không.
 * @return array{0: float, 1: int} [multiplier, streak]
 */
function init_plugin_suite_view_count_exposure_fatigue_multiplier( $post_id, $is_top_now ) {
	$streak = (int) init_plugin_suite_view_count_trending_state_get( 'streak', $post_id );

	if ( $is_top_now ) {
		++$streak;
		init_plugin_suite_view_count_trending_state_set( 'streak', $post_id, $streak, HOUR_IN_SECONDS * 8 );
	} else {
		init_plugin_suite_view_count_trending_state_delete( 'streak', $post_id );
		$streak = 0;
	}

	$mult = max( 0.8, 1.0 - max( 0, $streak - 6 ) * 0.03 );
	return array( $mult, $streak );
}

/**
 * Độ tương đồng Jaccard theo tập category + tag (giữ nguyên để tương thích ngược).
 *
 * @param array $a Item A.
 * @param array $b Item B.
 * @return float
 */
function init_plugin_suite_view_count_similarity( $a, $b ) {
	return init_plugin_suite_view_count_jaccard_sets(
		init_plugin_suite_view_count_term_set( $a ),
		init_plugin_suite_view_count_term_set( $b )
	);
}

/**
 * Tập term (category + tag) của 1 item, dạng mảng key để giao/hợp O(n).
 *
 * @param array $item Item trending.
 * @return array<int|string, int>
 */
function init_plugin_suite_view_count_term_set( $item ) {
	$set = array();
	foreach ( array_merge( (array) ( $item['categories'] ?? array() ), (array) ( $item['tags'] ?? array() ) ) as $term ) {
		if ( is_int( $term ) || is_string( $term ) ) {
			$set[ $term ] = 1;
		}
	}
	return $set;
}

/**
 * Jaccard giữa 2 tập dạng key.
 *
 * @param array $sa Tập A.
 * @param array $sb Tập B.
 * @return float
 */
function init_plugin_suite_view_count_jaccard_sets( array $sa, array $sb ) {
	if ( empty( $sa ) && empty( $sb ) ) {
		return 0.0;
	}
	$inter = count( array_intersect_key( $sa, $sb ) );
	$union = count( $sa ) + count( $sb ) - $inter;
	return $inter / max( 1, $union );
}

/**
 * MMR re-rank (Maximal Marginal Relevance) để đa dạng nội dung.
 *
 * Cho ra KẾT QUẢ Y HỆT bản cũ, nhưng:
 * - tập term của mỗi bài chỉ dựng 1 lần (bản cũ dựng lại array_merge/array_unique
 *   trong mỗi lần so sánh);
 * - độ tương đồng lớn nhất với tập đã chọn được cập nhật tăng dần sau mỗi lần chọn,
 *   thay vì tính lại với TOÀN BỘ tập đã chọn ở mỗi vòng.
 * Độ phức tạp giảm từ O(limit² × n) xuống O(limit × n) phép so sánh.
 *
 * @param array $posts  Danh sách đã xếp hạng.
 * @param float $lambda Trọng số relevance (0..1).
 * @param int   $limit  Số lượng cần chọn.
 * @return array
 */
function init_plugin_suite_view_count_mmr_rerank( array $posts, $lambda = 0.75, $limit = 20 ) {
	$cands = array_values( $posts );
	$n     = count( $cands );

	if ( 0 === $n || $limit <= 0 ) {
		return array();
	}

	$sets      = array();
	$max_sim   = array();
	$remaining = array();

	foreach ( $cands as $i => $p ) {
		$sets[ $i ]      = init_plugin_suite_view_count_term_set( $p );
		$max_sim[ $i ]   = 0.0;
		$remaining[ $i ] = true;
	}

	$selected       = array();
	$selected_count = 0;

	while ( ! empty( $remaining ) && $selected_count < $limit ) {
		$best_i   = null;
		$best_val = -INF;

		foreach ( $remaining as $i => $unused ) {
			$rel = $cands[ $i ]['score'];
			$mmr = $lambda * $rel - ( 1 - $lambda ) * $max_sim[ $i ] * $rel; // Phạt tương đồng theo độ lớn rel.
			if ( $mmr > $best_val ) {
				$best_val = $mmr;
				$best_i   = $i;
			}
		}

		if ( null === $best_i ) {
			$best_i = array_key_first( $remaining );
		}

		$selected[] = $cands[ $best_i ];
		++$selected_count;
		unset( $remaining[ $best_i ] );

		foreach ( $remaining as $i => $unused ) {
			$sim = init_plugin_suite_view_count_jaccard_sets( $sets[ $i ], $sets[ $best_i ] );
			if ( $sim > $max_sim[ $i ] ) {
				$max_sim[ $i ] = $sim;
			}
		}
	}

	return $selected;
}

/**
 * Explore/Exploit: thỉnh thoảng (xác suất epsilon) chèn 1–2 bài "tiềm năng" vào vị trí 5–10.
 *
 * @param array $ranked  Danh sách đã xếp hạng.
 * @param array $pool    Toàn bộ ứng viên.
 * @param array $weights Trọng số (dùng 'explore').
 * @return array
 */
function init_plugin_suite_view_count_maybe_explore( array $ranked, array $pool, $weights ) {
	$epsilon = min( 0.25, max( 0.0, 0.05 * (float) ( $weights['explore'] ?? 1.0 ) ) );

	if ( ( wp_rand( 0, 1000000 ) / 1000000 ) > $epsilon ) {
		return $ranked;
	}

	$cands = array_slice( $pool, 0, 50 );
	usort(
		$cands,
		function ( $a, $b ) {
			$sa = (float) ( ( $a['components']['uplift'] ?? 1.0 ) * ( $a['components']['ewma'] ?? 1.0 ) );
			$sb = (float) ( ( $b['components']['uplift'] ?? 1.0 ) * ( $b['components']['ewma'] ?? 1.0 ) );
			return $sb <=> $sa;
		}
	);
	$pick = array_slice( $cands, 0, 2 );
	$pos  = min( count( $ranked ), max( 5, wp_rand( 5, 10 ) ) );
	array_splice( $ranked, $pos, 0, $pick );

	// Loại trùng + cắt limit 20.
	$seen = array();
	$out  = array();
	foreach ( $ranked as $p ) {
		if ( ! isset( $seen[ $p['id'] ] ) ) {
			$out[]            = $p;
			$seen[ $p['id'] ] = 1;
		}
		if ( count( $out ) >= 20 ) {
			break;
		}
	}
	return $out;
}
