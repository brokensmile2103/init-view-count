<?php
/**
 * Trending Engine v4 (chạy mỗi giờ qua WP-Cron).
 *
 * Tách khỏi reset-schedule.php từ v2.0.6. Mọi hàm công khai của các bản trước vẫn giữ
 * nguyên tên và chữ ký (tham số mới đều có giá trị mặc định); các hàm chấm điểm không còn
 * được Engine dùng nằm trong includes/trending-legacy.php để code bên ngoài vẫn gọi được.
 *
 * @package Init_View_Count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init_plugin_suite_view_count_cron_update_trending', 'init_plugin_suite_view_count_cron_update_trending' );

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
 * @param string   $meta_key      Meta key.
 * @param string[] $post_types    Post types.
 * @param int      $limit         Số lượng tối đa.
 * @param bool     $only_positive v2.0.6+: chỉ lấy post có giá trị > 0. Với các key mà MỌI post
 *                                đều có row (VD '_init_view_day_yesterday', rollover ghi 0 cho post
 *                                không có view), bỏ các row 0 giúp MySQL không phải sắp xếp toàn bộ
 *                                post của site chỉ để lấy vài chục post đầu.
 * @return int[]
 */
function init_plugin_suite_view_count_fetch_ids_by_key( $meta_key, $post_types, $limit, $only_positive = false ) {
	if ( empty( $meta_key ) ) {
		return array();
	}

	$args = array(
		'post_type'              => $post_types,
		'posts_per_page'         => $limit,
		'post_status'            => 'publish',
		'no_found_rows'          => true,
		'fields'                 => 'ids',
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		// Xếp hạng theo lượt xem thực tế, không để sticky post chen vào danh sách ứng viên.
		'ignore_sticky_posts'    => true,
	);

	if ( $only_positive ) {
		// Clause có tên + orderby theo clause đó: chỉ 1 JOIN postmeta, sắp xếp kiểu số.
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Chạy tối đa 1 lần/giờ trong cron Trending.
		$args['meta_query'] = array(
			'ivc_views' => array(
				'key'     => $meta_key,
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			),
		);
		$args['orderby']    = array( 'ivc_views' => 'DESC' );
	} else {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Chạy tối đa 1 lần/giờ trong cron Trending.
		$args['meta_key'] = $meta_key;
		$args['orderby']  = 'meta_value_num';
		$args['order']    = 'DESC';
	}

	$q = new WP_Query( $args );

	return ! empty( $q->posts ) ? array_map( 'intval', $q->posts ) : array();
}

/**
 * Update Trending: chọn tập ứng viên rồi tính điểm.
 *
 * Từ v2.0.6, tập ứng viên gồm:
 * 1. Top post theo lượt xem hôm nay (như cũ).
 * 2. Các bài đang nằm trong danh sách Trending hiện tại — để bài đang "nguội" được chấm lại
 *    và rơi xuống tự nhiên theo số liệu mới, thay vì biến mất đột ngột chỉ vì hôm nay chưa
 *    lọt top theo lượt xem (thường gặp ngay sau reset 00:01).
 * 3. Top post theo lượt xem hôm qua (chỉ lấy post > 0) — đầu ngày lượt xem hôm nay còn quá
 *    ít để xếp hạng, nhưng tốc độ 24 giờ gần nhất của các bài này vẫn tính được chính xác.
 * Chỉ khi vẫn thiếu tối thiểu mới truy vấn thêm tuần/tháng/tổng (rank fusion như bản cũ).
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
	$yday_key  = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_day_yesterday', null );
	$week_key  = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_week_count', null );
	$month_key = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_month_count', null );
	$total_key = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_count', null );

	$day_ids = init_plugin_suite_view_count_fetch_ids_by_key( $day_key, $post_types, $limit, true );

	// --- Bổ sung ứng viên: danh sách Trending hiện tại + top hôm qua ---
	$extra_ids = array();

	$current = get_transient( 'init_plugin_suite_view_count_trending' );
	if ( is_array( $current ) ) {
		foreach ( $current as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
				$extra_ids[] = (int) $entry['id'];
			}
		}
	}

	$yday_limit = max( 1, (int) apply_filters( 'init_plugin_suite_view_count_trending_yesterday_candidates', (int) ceil( $limit / 2 ) ) );
	if ( (bool) get_option( 'init_plugin_suite_view_count_enable_day', 1 ) ) {
		$extra_ids = array_merge( $extra_ids, init_plugin_suite_view_count_fetch_ids_by_key( $yday_key, $post_types, $yday_limit, true ) );
	}

	// Ghép, bỏ trùng; post ngoài các post type đang theo dõi (VD bài cũ trong transient) bị
	// loại bởi bước kiểm tra post type trong init_plugin_suite_view_count_calculate_trending().
	$max_pool = $limit + $yday_limit;
	$seen     = array();
	$pool     = array();
	foreach ( array( $day_ids, $extra_ids ) as $list ) {
		foreach ( $list as $pid ) {
			$pid = (int) $pid;
			if ( $pid > 0 && ! isset( $seen[ $pid ] ) ) {
				$seen[ $pid ] = true;
				$pool[]       = $pid;
				if ( count( $pool ) >= $max_pool ) {
					break 2;
				}
			}
		}
	}

	// Đủ ứng viên → tính luôn (đa số trường hợp: 1–2 query nhẹ, không cần fallback).
	if ( count( $pool ) >= $min_count ) {
		init_plugin_suite_view_count_calculate_trending( $pool, $post_types );
		return;
	}

	// Chỉ query thêm khi cần fallback.
	$day_ids   = $pool;
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
				if ( count( $candidates ) >= max( $limit, count( $day_ids ) ) ) {
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

	$final_ids = array_slice( $candidates, 0, max( $min_count, $limit, count( $day_ids ) ) );
	if ( ! empty( $final_ids ) ) {
		init_plugin_suite_view_count_calculate_trending( $final_ids, $post_types );
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
// == MỐC RESET & THỜI GIAN CHUẨN HOÁ (v2.0.6) ==
// ==========================================

/**
 * Ghi lại thời điểm reset thực tế của từng bộ đếm (gọi từ cron reset hàng ngày).
 *
 * WP-Cron chỉ chạy khi có request, nên reset "00:01" thực tế có thể chạy lúc 00:20 hay
 * 02:00. Trending cần biết CHÍNH XÁC bộ đếm ngày/tuần/tháng đang tính từ lúc nào để chia
 * cho đúng khoảng thời gian; lưu mốc thật thay vì giả định nửa đêm.
 *
 * @param int  $now_gmt     Unix timestamp lúc reset.
 * @param bool $reset_day   Có reset ngày không.
 * @param bool $reset_week  Có reset tuần không.
 * @param bool $reset_month Có reset tháng không.
 * @return void
 */
function init_plugin_suite_view_count_record_reset_marks( $now_gmt, $reset_day, $reset_week, $reset_month ) {
	$now_gmt = (int) $now_gmt;
	$marks   = get_option( 'init_plugin_suite_view_count_reset_marks', array() );
	$marks   = is_array( $marks ) ? array_map( 'intval', $marks ) : array();

	$plan = array(
		'day'   => array( (bool) $reset_day, DAY_IN_SECONDS ),
		'week'  => array( (bool) $reset_week, WEEK_IN_SECONDS ),
		'month' => array( (bool) $reset_month, 30 * DAY_IN_SECONDS ),
	);

	foreach ( $plan as $period => $item ) {
		if ( ! $item[0] ) {
			continue;
		}
		$prev                       = $marks[ $period ] ?? 0;
		$marks[ $period . '_prev' ] = ( $prev > 0 && $prev < $now_gmt ) ? $prev : $now_gmt - $item[1];
		$marks[ $period ]           = $now_gmt;
	}

	update_option( 'init_plugin_suite_view_count_reset_marks', $marks, false );
}

/**
 * Mốc bắt đầu của các bộ đếm hiện tại và kỳ trước.
 *
 * Ưu tiên mốc reset thật đã lưu; site vừa nâng cấp (chưa có mốc) dùng lịch mặc định:
 * ngày = 00:01 hôm nay, tuần = 00:01 thứ Hai, tháng = 00:01 ngày 1 (giờ site).
 *
 * @param int $now Unix timestamp.
 * @return array<string,int> Các key: day, day_prev, week, week_prev, month, month_prev.
 */
function init_plugin_suite_view_count_get_reset_marks( $now ) {
	$now   = (int) $now;
	$tz    = wp_timezone();
	$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );

	$day = $local->setTime( 0, 1 );
	if ( $day->getTimestamp() > $now ) {
		$day = $day->modify( '-1 day' );
	}

	$week = $local->modify( 'monday this week' )->setTime( 0, 1 );
	if ( $week->getTimestamp() > $now ) {
		$week = $week->modify( '-7 days' );
	}

	$month = $local->modify( 'first day of this month' )->setTime( 0, 1 );
	if ( $month->getTimestamp() > $now ) {
		$month = $month->modify( 'first day of previous month' )->setTime( 0, 1 );
	}

	$defaults = array(
		'day'        => $day->getTimestamp(),
		'day_prev'   => $day->modify( '-1 day' )->getTimestamp(),
		'week'       => $week->getTimestamp(),
		'week_prev'  => $week->modify( '-7 days' )->getTimestamp(),
		'month'      => $month->getTimestamp(),
		'month_prev' => $month->modify( 'first day of previous month' )->setTime( 0, 1 )->getTimestamp(),
	);

	$stored = get_option( 'init_plugin_suite_view_count_reset_marks', array() );
	$stored = is_array( $stored ) ? $stored : array();
	$marks  = $defaults;

	foreach ( array( 'day', 'week', 'month' ) as $period ) {
		$cur  = (int) ( $stored[ $period ] ?? 0 );
		$prev = (int) ( $stored[ $period . '_prev' ] ?? 0 );

		// Bộ đếm chỉ được reset bởi cron reset hàng ngày (nơi ghi mốc), nên mốc đã lưu luôn
		// đúng — kể cả khi đã cũ (WP-Cron ngừng chạy vài ngày: bộ đếm vẫn đang cộng dồn từ
		// mốc đó). Chỉ bỏ qua mốc ở tương lai (đồng hồ server bị chỉnh lùi).
		if ( $cur > 0 && $cur <= $now ) {
			$marks[ $period ] = $cur;
			if ( $prev > 0 && $prev < $cur ) {
				$marks[ $period . '_prev' ] = $prev;
			}
		}
	}

	return $marks;
}

/**
 * Dựng "dòng thời gian" theo từng giờ site của 50 giờ gần nhất, với trọng số traffic của
 * từng giờ (shape giờ × shape thứ đã học), cộng dồn sẵn để tra cứu O(1).
 *
 * Đơn vị dùng trong Trending từ v2.0.6 là "giờ chuẩn hoá": 1 giờ có traffic bằng mức trung
 * bình của site = 1.0; 1 giờ 3h sáng (ít người đọc) có thể chỉ 0.3; 1 giờ cao điểm 1.8.
 * Chia lượt xem cho số giờ chuẩn hoá cho ra tốc độ KHÔNG bị lệch theo giờ trong ngày: bài
 * đăng lúc 3h sáng có 30 lượt xem không còn thua bài đăng lúc 20h có 60 lượt.
 *
 * @param int   $now   Unix timestamp.
 * @param array $shape Traffic shape ('hour' 24 phần tử, 'wday' 7 phần tử, mean = 1).
 * @return array Timeline nội bộ cho init_plugin_suite_view_count_trending_norm_hours().
 */
function init_plugin_suite_view_count_trending_timeline( $now, $shape ) {
	$now   = (int) $now;
	$hours = 50;
	$hour  = isset( $shape['hour'] ) && is_array( $shape['hour'] ) ? array_values( $shape['hour'] ) : array_fill( 0, 24, 1.0 );
	$wday  = isset( $shape['wday'] ) && is_array( $shape['wday'] ) ? array_values( $shape['wday'] ) : array_fill( 0, 7, 1.0 );

	// Đầu giờ site hiện tại (đúng cả với múi giờ lệch 30/45 phút như +05:30).
	$local  = ( new DateTime( '@' . $now ) )->setTimezone( wp_timezone() );
	$offset = (int) $local->getOffset();
	$start  = $now - ( ( $now + $offset ) % HOUR_IN_SECONDS + HOUR_IN_SECONDS ) % HOUR_IN_SECONDS;
	$s0     = $start - ( $hours - 1 ) * HOUR_IN_SECONDS;

	$weights = array();
	$cum     = array( 0.0 );

	for ( $i = 0; $i < $hours; $i++ ) {
		$local->setTimestamp( $s0 + $i * HOUR_IN_SECONDS );
		$h = (int) $local->format( 'G' );
		$w = (int) $local->format( 'w' );

		$weight        = max( 0.05, (float) ( $hour[ $h ] ?? 1.0 ) * (float) ( $wday[ $w ] ?? 1.0 ) );
		$weights[ $i ] = $weight;
		$cum[ $i + 1 ] = $cum[ $i ] + $weight;
	}

	return array(
		's0'  => $s0,
		'n'   => $hours,
		'w'   => $weights,
		'cum' => $cum,
		'now' => $now,
	);
}

/**
 * Số "giờ chuẩn hoá" (xem init_plugin_suite_view_count_trending_timeline()) tính tới thời điểm $t.
 * Phần cũ hơn 50 giờ được tính với trọng số trung bình 1.0 (shape giờ/thứ cân bằng lại khi
 * khoảng thời gian dài nhiều ngày).
 *
 * @param array $tl Timeline.
 * @param int   $t  Unix timestamp.
 * @return float
 */
function init_plugin_suite_view_count_trending_cum_hours( $tl, $t ) {
	$t = min( (int) $t, (int) $tl['now'] );

	if ( $t <= $tl['s0'] ) {
		return ( $t - $tl['s0'] ) / HOUR_IN_SECONDS;
	}

	$i = (int) floor( ( $t - $tl['s0'] ) / HOUR_IN_SECONDS );
	if ( $i >= $tl['n'] ) {
		return $tl['cum'][ $tl['n'] ];
	}

	$frac = ( $t - ( $tl['s0'] + $i * HOUR_IN_SECONDS ) ) / HOUR_IN_SECONDS;
	return $tl['cum'][ $i ] + $frac * $tl['w'][ $i ];
}

/**
 * Số giờ chuẩn hoá trong khoảng [$t0, $t1].
 *
 * @param array $tl Timeline.
 * @param int   $t0 Bắt đầu.
 * @param int   $t1 Kết thúc.
 * @return float
 */
function init_plugin_suite_view_count_trending_norm_hours( $tl, $t0, $t1 ) {
	if ( $t1 <= $t0 ) {
		return 0.0;
	}
	return max( 0.0, init_plugin_suite_view_count_trending_cum_hours( $tl, $t1 ) - init_plugin_suite_view_count_trending_cum_hours( $tl, $t0 ) );
}

/**
 * Số bình luận đã duyệt trong N ngày gần nhất của danh sách post — 1 query duy nhất.
 *
 * @param int[] $post_ids Post ID.
 * @param int   $days     Số ngày.
 * @return array<int,int> post_id => số bình luận.
 */
function init_plugin_suite_view_count_trending_recent_comments( array $post_ids, $days ) {
	global $wpdb;

	$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
	$days     = (int) $days;
	if ( empty( $post_ids ) || $days <= 0 ) {
		return array();
	}

	$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$in    = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

	// $in chỉ chứa chuỗi giữ chỗ '%d' lặp lại; mọi giá trị đi qua $wpdb->prepare().
	// Kết quả chỉ dùng trong 1 lượt tính Trending (tối đa 1 lần/giờ) nên không cần cache.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT comment_post_ID AS pid, COUNT(*) AS c
			FROM {$wpdb->comments}
			WHERE comment_approved = '1'
				AND comment_type NOT IN ('pingback', 'trackback')
				AND comment_date_gmt >= %s
				AND comment_post_ID IN ($in)
			GROUP BY comment_post_ID",
			array_merge( array( $since ), $post_ids )
		)
	);
	// phpcs:enable

	$out = array();
	foreach ( (array) $rows as $row ) {
		$out[ (int) $row->pid ] = (int) $row->c;
	}
	return $out;
}

/**
 * Các tham số tinh chỉnh của Trending Engine v4 (v2.0.6), gom về 1 filter.
 *
 * - velocity_exponent   Số mũ áp lên lượt xem/ngày. 1.0 = tuyến tính; nhỏ hơn = nén chênh lệch
 *                       giữa bài nhiều/ít lượt xem; 0 = log(1 + x) như bản cũ.
 * - prior_hours         Số "giờ trống" cộng vào mẫu khi tính tốc độ 24 giờ, để vài lượt xem
 *                       đầu tiên của bài vừa đăng không bị phóng đại.
 * - hot_half_life       Half-life (giờ) của EWMA tốc độ "ngay lúc này".
 * - uplift_smoothing    Số lượt xem/ngày cộng vào cả tử và mẫu khi so với mức bình thường.
 * - uplift_min/max      Khoảng kẹp hệ số uplift.
 * - freshness_boost     Mức boost tối đa cho bài vừa đăng (0.25 = +25%).
 * - freshness_half_life Half-life (giờ) của boost bài mới.
 * - engagement_max      Mức cộng tối đa từ tương tác (0.5 = tối đa +50%).
 * - age_grace           Số giờ đầu không giảm điểm theo tuổi bài.
 * - age_half_life       Half-life (giờ) của hệ số tuổi bài.
 * - age_floor           Sàn của hệ số tuổi bài (1.0 = không giảm theo tuổi).
 *
 * @return array<string,float>
 */
function init_plugin_suite_view_count_trending_tuning() {
	$defaults = array(
		'velocity_exponent'   => 1.0,
		'prior_hours'         => 2.0,
		'hot_half_life'       => 3.0,
		'uplift_smoothing'    => 10.0,
		'uplift_min'          => 0.75,
		'uplift_max'          => 2.0,
		'freshness_boost'     => 0.25,
		'freshness_half_life' => 6.0,
		'engagement_max'      => 0.5,
		'age_grace'           => 2.0,
		'age_half_life'       => 72.0,
		'age_floor'           => 0.5,
	);

	/**
	 * Filter các tham số tinh chỉnh của Trending Engine.
	 *
	 * @since 2.0.6
	 *
	 * @param array $tuning Tham số (xem docblock của init_plugin_suite_view_count_trending_tuning()).
	 */
	$tuning = wp_parse_args( (array) apply_filters( 'init_plugin_suite_view_count_trending_tuning', $defaults ), $defaults );
	$tuning = array_map( 'floatval', array_intersect_key( $tuning, $defaults ) );

	$tuning['velocity_exponent']   = max( 0.0, min( 2.0, $tuning['velocity_exponent'] ) );
	$tuning['prior_hours']         = max( 0.0, $tuning['prior_hours'] );
	$tuning['hot_half_life']       = max( 0.5, $tuning['hot_half_life'] );
	$tuning['uplift_smoothing']    = max( 1.0, $tuning['uplift_smoothing'] );
	$tuning['uplift_min']          = max( 0.1, min( 1.0, $tuning['uplift_min'] ) );
	$tuning['uplift_max']          = max( 1.0, $tuning['uplift_max'] );
	$tuning['freshness_boost']     = max( 0.0, $tuning['freshness_boost'] );
	$tuning['freshness_half_life'] = max( 0.5, $tuning['freshness_half_life'] );
	$tuning['engagement_max']      = max( 0.0, $tuning['engagement_max'] );
	$tuning['age_grace']           = max( 0.0, $tuning['age_grace'] );
	$tuning['age_half_life']       = max( 1.0, $tuning['age_half_life'] );
	$tuning['age_floor']           = max( 0.0, min( 1.0, $tuning['age_floor'] ) );

	return $tuning;
}

/**
 * Hệ số theo tuổi bài: giảm dần nhưng có "sàn" (v2.0.6).
 *
 * Bản cũ giảm một nửa sau mỗi 36 giờ, không có sàn: bài 10 ngày tuổi chỉ còn ~1% điểm, nên
 * bài cũ dù bất ngờ được đọc lại rất nhiều cũng gần như không thể vào Trending. Nay bài mới
 * vẫn được ưu tiên (mặc định: còn 71% sau 3 ngày, sàn 50%), nhưng bài cũ đang thực sự được
 * đọc nhiều trở lại vẫn lên được.
 *
 * @param float      $age_hours Tuổi bài (giờ).
 * @param array|null $tuning    Tham số (null = đọc từ init_plugin_suite_view_count_trending_tuning()).
 * @return float
 */
function init_plugin_suite_view_count_trending_age_factor( $age_hours, $tuning = null ) {
	$tuning = is_array( $tuning ) ? $tuning : init_plugin_suite_view_count_trending_tuning();
	$age    = max( 0.0, (float) $age_hours - $tuning['age_grace'] );

	return $tuning['age_floor'] + ( 1.0 - $tuning['age_floor'] ) * pow( 0.5, $age / $tuning['age_half_life'] );
}

/**
 * Boost cho bài mới đăng, giảm mượt theo thời gian (mặc định +25% lúc vừa đăng, +12.5% sau
 * 6 giờ, ~+3% sau 18 giờ). Thay cho bậc thang 1.8/1.4/1.2/... của bản cũ, vốn nhảy cóc ở các
 * mốc 1h/3h/6h làm thứ hạng đổi đột ngột giữa 2 lượt chạy.
 *
 * @param float      $age_hours Tuổi bài (giờ).
 * @param array|null $tuning    Tham số (null = đọc từ init_plugin_suite_view_count_trending_tuning()).
 * @return float
 */
function init_plugin_suite_view_count_trending_freshness( $age_hours, $tuning = null ) {
	$tuning = is_array( $tuning ) ? $tuning : init_plugin_suite_view_count_trending_tuning();

	return 1.0 + $tuning['freshness_boost'] * pow( 0.5, max( 0.0, (float) $age_hours ) / $tuning['freshness_half_life'] );
}

/**
 * Ước lượng lượt xem & giờ chuẩn hoá của 1 bài trong 1 khoảng [start, end], từ một bộ đếm
 * bao phủ khoảng [cover_start, cover_end] (giả định traffic của bài phân bố theo shape site).
 *
 * @param array $tl          Timeline.
 * @param int   $views       Giá trị bộ đếm.
 * @param int   $cover_start Bắt đầu khoảng bộ đếm bao phủ.
 * @param int   $cover_end   Kết thúc khoảng bộ đếm bao phủ.
 * @param int   $start       Bắt đầu khoảng cần ước lượng.
 * @param int   $end         Kết thúc khoảng cần ước lượng.
 * @return float Lượt xem ước lượng trong khoảng cần tính.
 */
function init_plugin_suite_view_count_trending_views_in( $tl, $views, $cover_start, $cover_end, $start, $end ) {
	$views = max( 0, (int) $views );
	if ( 0 === $views ) {
		return 0.0;
	}

	$a = max( $cover_start, $start );
	$b = min( $cover_end, $end );
	if ( $b <= $a ) {
		return 0.0;
	}
	if ( $a <= $cover_start && $b >= $cover_end ) {
		return (float) $views;
	}

	$total_hours = init_plugin_suite_view_count_trending_norm_hours( $tl, $cover_start, $cover_end );
	if ( $total_hours <= 0 ) {
		return (float) $views;
	}

	return $views * min( 1.0, init_plugin_suite_view_count_trending_norm_hours( $tl, $a, $b ) / $total_hours );
}

/**
 * Tín hiệu lượt xem của 1 bài (v2.0.6): lượt xem 24 giờ gần nhất (cửa sổ trượt), tốc độ
 * tương ứng, và mức "bình thường" (baseline) của chính bài đó trước 24 giờ này.
 *
 * Sửa các sai lệch của bản cũ:
 * - Bản cũ chia lượt xem HÔM NAY cho tuổi bài (tối đa 24h), trong khi bộ đếm ngày chỉ tính
 *   từ 00:01: lúc 9h sáng, bài cũ bị chia cho 24 giờ dù mới có 9 giờ dữ liệu → tốc độ của
 *   mọi bài cũ bị hạ ~2.7 lần so với bài mới đăng hôm nay.
 * - Bản cũ lấy trung bình ngày = lượt xem TUẦN / 7 và = THÁNG / 30, trong khi bộ đếm tuần
 *   reset mỗi thứ Hai và tháng reset mỗi ngày 1: thứ Ba, "trung bình ngày" chỉ bằng ~2/7
 *   thực tế → hầu như bài nào cũng bị coi là đang tăng đột biến.
 * - Đầu ngày (lượt xem hôm nay ≈ 0) bản cũ phải "đoán" lượt xem; nay ghép phần cuối của hôm
 *   qua vào cho đủ 24 giờ, nên xếp hạng lúc 1h sáng chính xác như lúc 23h.
 *
 * @param array $tl    Timeline.
 * @param array $marks Mốc reset (init_plugin_suite_view_count_get_reset_marks()).
 * @param array $v     Bộ đếm: day, yesterday, week, last_week, month, last_month, total
 *                     (yesterday/last_week/last_month = null nếu chưa có dữ liệu).
 * @param array $on    Bộ đếm đang bật: day, week, month (bool).
 * @param int   $t_pub Thời điểm đăng bài.
 * @param int   $now   Bây giờ.
 * @return array{views24: float, hours24: float, base: float|null, views_week: float, estimated: bool}
 */
function init_plugin_suite_view_count_trending_view_signals( $tl, $marks, $v, $on, $t_pub, $now ) {
	$w24 = max( $t_pub, $now - DAY_IN_SECONDS );

	$views24   = 0.0;
	$cover24   = $w24;
	$estimated = false;

	if ( $on['day'] ) {
		$t_day = max( $t_pub, $marks['day'] );

		$views24 += init_plugin_suite_view_count_trending_views_in( $tl, $v['day'], $t_day, $now, $w24, $now );

		if ( $t_pub < $marks['day'] ) {
			if ( null !== $v['yesterday'] ) {
				$views24 += init_plugin_suite_view_count_trending_views_in(
					$tl,
					$v['yesterday'],
					max( $t_pub, $marks['day_prev'] ),
					$marks['day'],
					$w24,
					$now
				);
			} else {
				// Chưa có số liệu hôm qua (VD: vừa bật đếm theo ngày) → chỉ dùng phần đã biết.
				$cover24 = max( $w24, $t_day );
			}
		}
	}

	// --- Cửa sổ tuần (hiện tại + tuần trước nếu có) ---
	$views_week = 0.0;
	$hours_week = 0.0;
	if ( $on['week'] ) {
		$start_w    = max( $t_pub, null !== $v['last_week'] ? $marks['week_prev'] : $marks['week'] );
		$views_week = (float) $v['week'] + (float) ( null !== $v['last_week'] && $t_pub < $marks['week'] ? $v['last_week'] : 0 );
		$hours_week = init_plugin_suite_view_count_trending_norm_hours( $tl, $start_w, $now );
	}

	// --- Cửa sổ tháng (hiện tại + tháng trước nếu có) ---
	$views_month = 0.0;
	$hours_month = 0.0;
	if ( $on['month'] ) {
		$start_m     = max( $t_pub, null !== $v['last_month'] ? $marks['month_prev'] : $marks['month'] );
		$views_month = (float) $v['month'] + (float) ( null !== $v['last_month'] && $t_pub < $marks['month'] ? $v['last_month'] : 0 );
		$hours_month = init_plugin_suite_view_count_trending_norm_hours( $tl, $start_m, $now );
	}

	$hours24 = init_plugin_suite_view_count_trending_norm_hours( $tl, $cover24, $now );

	// Không đếm theo ngày → ước lượng 24 giờ gần nhất từ tuần/tháng/tổng.
	if ( ! $on['day'] ) {
		$estimated = true;
		$hours24   = init_plugin_suite_view_count_trending_norm_hours( $tl, $w24, $now );

		if ( $hours_week > 0 && $views_week > 0 ) {
			$views24 = $views_week * min( 1.0, $hours24 / $hours_week );
		} elseif ( $hours_month > 0 && $views_month > 0 ) {
			$views24 = $views_month * min( 1.0, $hours24 / $hours_month );
		} else {
			$life    = init_plugin_suite_view_count_trending_norm_hours( $tl, $t_pub, $now );
			$views24 = $life > 0 ? (float) $v['total'] * min( 1.0, $hours24 / $life ) : 0.0;
		}
	}

	// --- Baseline: tốc độ "bình thường" TRƯỚC 24 giờ gần nhất (cần >= 1 ngày dữ liệu) ---
	$base = null;
	foreach ( array( array( $views_week, $hours_week ), array( $views_month, $hours_month ) ) as $win ) {
		$ex_hours = $win[1] - $hours24;
		if ( $ex_hours >= 24.0 ) {
			$base = max( 0.0, $win[0] - $views24 ) / $ex_hours;
			break;
		}
	}

	return array(
		'views24'    => $views24,
		'hours24'    => $hours24,
		'base'       => $base,
		'views_week' => $views_week > 0 ? $views_week : $views24,
		'estimated'  => $estimated,
	);
}

/**
 * Tốc độ "ngay lúc này" theo EWMA của lượt xem THỰC TẾ giữa 2 lượt chạy (v2.0.6).
 *
 * Mỗi lượt chạy lưu lại tổng lượt xem của bài; lần sau lấy chênh lệch chia cho số giờ chuẩn
 * hoá đã trôi qua → số lượt xem thật trong ~1 giờ vừa rồi, không phụ thuộc mốc reset. Kết
 * quả được làm mượt EWMA (half-life mặc định 3 giờ). Bài mới vào danh sách ứng viên (chưa có
 * mốc) hoặc dữ liệu đứt quãng quá 3 giờ → khởi tạo bằng tốc độ 24 giờ.
 *
 * Bản cũ gọi là "EWMA" nhưng thực chất làm mượt (lượt xem hôm nay / tuổi bài) — giá trị này
 * đổi rất chậm và tụt về 0 mỗi lần reset, nên không phản ánh được bài đang lên hay xuống.
 *
 * @param array $tl      Timeline.
 * @param int   $post_id Post ID.
 * @param int   $total   Tổng lượt xem hiện tại.
 * @param float $rate24  Tốc độ 24 giờ (lượt / giờ chuẩn hoá).
 * @param int   $now     Bây giờ.
 * @param float $half    Half-life của EWMA (giờ).
 * @return array{hot: float, prev: float|null, delta: int}
 */
function init_plugin_suite_view_count_trending_hot_rate( $tl, $post_id, $total, $rate24, $now, $half = 3.0 ) {
	$half = max( 0.5, (float) $half );
	$snap = init_plugin_suite_view_count_trending_state_get( 'snap', $post_id );
	$prev = init_plugin_suite_view_count_trending_state_get( 'hot', $post_id );
	$prev = ( false === $prev ) ? null : (float) $prev;

	$hot   = $rate24;
	$delta = -1;

	if ( is_array( $snap ) && isset( $snap[0], $snap[1] ) && null !== $prev ) {
		$snap_total = (int) $snap[0];
		$snap_ts    = (int) $snap[1];
		$elapsed    = $now - $snap_ts;

		if ( $elapsed > 0 && $elapsed <= 3 * HOUR_IN_SECONDS && $total >= $snap_total ) {
			$delta = $total - $snap_total;
			$hours = init_plugin_suite_view_count_trending_norm_hours( $tl, $snap_ts, $now );
			$v_now = $delta / max( 0.25, $hours );
			$alpha = 1.0 - pow( 0.5, ( $elapsed / HOUR_IN_SECONDS ) / $half );
			$hot   = $alpha * $v_now + ( 1.0 - $alpha ) * $prev;
		}
	}

	init_plugin_suite_view_count_trending_state_set( 'snap', $post_id, array( (int) $total, (int) $now ), HOUR_IN_SECONDS * 6 );
	init_plugin_suite_view_count_trending_state_set( 'hot', $post_id, $hot, HOUR_IN_SECONDS * 6 );

	return array(
		'hot'   => max( 0.0, $hot ),
		'prev'  => $prev,
		'delta' => $delta,
	);
}

/**
 * Tính điểm trending cho danh sách post ứng viên và lưu vào transient.
 *
 * Trending Engine v4 (v2.0.6). Điểm của mỗi bài =
 *   10 × log(1 + lượt xem/ngày "ngay lúc này")    — mức độ được đọc (velocity)
 *      × uplift so với mức bình thường của bài     — đang nóng lên hay chỉ đều đều
 *      × engagement (bình luận 7 ngày, like/share)
 *      × freshness × hệ số tuổi bài (có sàn)
 *      × momentum chủ đề (category/tag đang nóng)
 *      × anti-gaming
 * rồi qua soft-cap, kẹp tăng trưởng giữa 2 lượt, exposure fatigue, MMR và lọc đa dạng
 * như bản cũ. Mọi tốc độ tính theo "giờ chuẩn hoá" (xem
 * init_plugin_suite_view_count_trending_timeline()) nên không lệch theo giờ trong ngày.
 *
 * @param int[]    $post_ids   Post ID ứng viên.
 * @param string[] $post_types v2.0.6+: chỉ chấm các post thuộc những post type này (null = không lọc).
 * @return array Danh sách trending cuối cùng.
 */
function init_plugin_suite_view_count_calculate_trending( array $post_ids, $post_types = null ) {
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

	// Unix timestamp THẬT — so sánh trực tiếp được với get_post_time('U', true).
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

	$weights = wp_parse_args(
		apply_filters( 'init_plugin_suite_view_count_trending_component_weights', array() ),
		array(
			'velocity'   => 1.0,
			'engagement' => 1.0,
			'freshness'  => 1.0,
			'momentum'   => 1.0,
			'uplift'     => 1.0,  // Mức tăng so với bình thường của chính bài.
			'ewma'       => 1.0,  // Tỷ trọng tốc độ "ngay lúc này" (EWMA) so với tốc độ 24 giờ.
			'fatigue'    => 1.0,  // Giảm theo exposure.
			'explore'    => 0.0,  // Tỷ lệ explore (v2.0.6: mặc định tắt, đặt 1.0 để có lại 5% như cũ).
			'mmr'        => 1.0,  // Mức đa dạng nội dung.
		)
	);

	$on = array(
		'day'   => (bool) get_option( 'init_plugin_suite_view_count_enable_day', 1 ),
		'week'  => (bool) get_option( 'init_plugin_suite_view_count_enable_week', 1 ),
		'month' => (bool) get_option( 'init_plugin_suite_view_count_enable_month', 1 ),
	);

	$shape       = init_plugin_suite_view_count_get_site_traffic_shape();
	$tl          = init_plugin_suite_view_count_trending_timeline( $now, $shape );
	$marks       = init_plugin_suite_view_count_get_reset_marks( $now );
	$tuning      = init_plugin_suite_view_count_trending_tuning();
	$prior_hours = $tuning['prior_hours'];
	$uplift_k    = $tuning['uplift_smoothing'];
	$hot_share   = max( 0.0, min( 1.0, 0.65 * (float) $weights['ewma'] ) );
	$vel_exp     = $tuning['velocity_exponent'];

	$comment_days    = (int) apply_filters( 'init_plugin_suite_view_count_trending_recent_comments_days', 7 );
	$recent_comments = init_plugin_suite_view_count_trending_recent_comments( $post_ids, $comment_days );
	$allowed_types   = is_array( $post_types ) && ! empty( $post_types ) ? array_flip( $post_types ) : null;

	// ===== Lượt 1: tín hiệu riêng của từng bài =====
	$rows       = array();
	$topic_heat = array(
		'c' => array(),
		't' => array(),
	);

	foreach ( $post_ids as $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			continue;
		}
		if ( null !== $allowed_types && ! isset( $allowed_types[ $post->post_type ] ) ) {
			continue;
		}

		$t_pub = (int) get_post_time( 'U', true, $post );
		if ( $t_pub <= 0 ) {
			continue;
		}
		$t_pub = min( $t_pub, $now - 60 );

		$meta = array();
		foreach (
			array(
				'day'        => '_init_view_day_count',
				'yesterday'  => '_init_view_day_yesterday',
				'week'       => '_init_view_week_count',
				'last_week'  => '_init_view_week_last',
				'month'      => '_init_view_month_count',
				'last_month' => '_init_view_month_last',
				'total'      => '_init_view_count',
			) as $field => $tpl
		) {
			$key = apply_filters( 'init_plugin_suite_view_count_meta_key', $tpl, $post_id );
			$raw = $key ? get_post_meta( $post_id, $key, true ) : '';
			// '' = chưa có row (VD: chưa từng rollover) → "chưa biết", khác với 0.
			$meta[ $field ] = ( '' === $raw || null === $raw ) ? null : max( 0, (int) $raw );
		}
		foreach ( array( 'day', 'week', 'month', 'total' ) as $field ) {
			$meta[ $field ] = (int) $meta[ $field ];
		}

		$age_hours = max( 0.0, ( $now - $t_pub ) / 3600.0 );
		$signals   = init_plugin_suite_view_count_trending_view_signals( $tl, $marks, $meta, $on, $t_pub, $now );

		// Tốc độ 24 giờ có làm mượt (prior: thêm vài "giờ trống") để vài lượt xem đầu tiên
		// của 1 bài vừa đăng không bị phóng đại thành tốc độ rất lớn.
		$rate24 = $signals['views24'] / ( $signals['hours24'] + $prior_hours );
		$hot    = init_plugin_suite_view_count_trending_hot_rate( $tl, $post_id, $meta['total'], $rate24, $now, $tuning['hot_half_life'] );
		$rate   = $hot_share * $hot['hot'] + ( 1.0 - $hot_share ) * $rate24;
		$daily  = $rate * 24.0; // Lượt xem / ngày (ngày có traffic trung bình).

		// --- Uplift so với mức bình thường của chính bài (Bayesian smoothing $uplift_k) ---
		$ratio  = null;
		$uplift = 1.0;
		if ( null !== $signals['base'] ) {
			$ratio  = ( $daily + $uplift_k ) / ( $signals['base'] * 24.0 + $uplift_k );
			$uplift = max( $tuning['uplift_min'], min( $tuning['uplift_max'], pow( $ratio, 0.4 ) ) );
		}

		// --- Engagement: bình luận gần đây so với lượt xem gần đây; like/share so với tổng ---
		$meta_keys = apply_filters(
			'init_plugin_suite_view_count_engagement_meta_keys',
			array(
				'likes'  => '_likes_count',
				'shares' => '_shares_count',
			),
			$post_id
		);
		$likes     = ! empty( $meta_keys['likes'] ) ? max( 0, (int) get_post_meta( $post_id, $meta_keys['likes'], true ) ) : 0;
		$shares    = ! empty( $meta_keys['shares'] ) ? max( 0, (int) get_post_meta( $post_id, $meta_keys['shares'], true ) ) : 0;
		$comments  = $comment_days > 0 ? (int) ( $recent_comments[ $post_id ] ?? 0 ) : (int) $post->comment_count;
		$comm_base = $comment_days > 0 ? $signals['views_week'] : (float) $meta['total'];

		$eng_rate   = $comments / ( $comm_base + 20.0 ) + ( $likes + $shares ) / ( $meta['total'] + 50.0 );
		$engagement = 1.0 + min( $tuning['engagement_max'], $eng_rate * 10.0 );

		// --- Anti-gaming: tăng đột biến bất thường mà không có tương tác nào ---
		$anti = 1.0;
		if ( null !== $signals['base'] ) {
			$spike = $daily / max( 1.0, $signals['base'] * 24.0 );
			if ( $spike > 20 ) {
				$anti *= 0.9;
			}
			if ( $spike > 50 && 0 === $comments + $likes + $shares ) {
				$anti *= 0.85;
			}
		}

		$categories = init_plugin_suite_view_count_get_cached_term_ids( $post_id, 'category' );
		$tags       = init_plugin_suite_view_count_get_cached_term_ids( $post_id, 'post_tag' );

		foreach ( $categories as $cid ) {
			$topic_heat['c'][ $cid ] = ( $topic_heat['c'][ $cid ] ?? 0.0 ) + $daily;
		}
		foreach ( $tags as $tid ) {
			$topic_heat['t'][ $tid ] = ( $topic_heat['t'][ $tid ] ?? 0.0 ) + $daily;
		}

		$rows[] = array(
			'id'         => $post_id,
			'post'       => $post,
			'meta'       => $meta,
			'age_hours'  => $age_hours,
			'signals'    => $signals,
			'rate24'     => $rate24,
			'hot'        => $hot,
			'rate'       => $rate,
			'daily'      => $daily,
			'ratio'      => $ratio,
			'uplift'     => $uplift,
			'engagement' => $engagement,
			'actions'    => $comments + $likes + $shares,
			'anti'       => $anti,
			'categories' => $categories,
			'tags'       => $tags,
		);
	}

	// ===== Momentum chủ đề: tính ngay từ tập ứng viên (không cần query riêng) =====
	// Bản cũ chạy 1 câu SQL JOIN 4 bảng mỗi 2 giờ, và chỉ xét bài ĐĂNG trong 24 giờ qua (bài
	// cũ đang được đọc nhiều không làm chủ đề "nóng"). Nay: độ nóng của 1 chủ đề = tổng tốc độ
	// đọc của các bài khác cùng chủ đề (trừ chính bài đang xét, tránh tự cộng điểm cho mình).
	$max_heat = array(
		'c' => ! empty( $topic_heat['c'] ) ? max( $topic_heat['c'] ) : 0.0,
		't' => ! empty( $topic_heat['t'] ) ? max( $topic_heat['t'] ) : 0.0,
	);

	$trending = array();

	foreach ( $rows as $row ) {
		$momentum = 1.0;
		foreach ( array(
			'c' => array( $row['categories'], 0.1 ),
			't' => array( $row['tags'], 0.05 ),
		) as $type => $item ) {
			if ( $max_heat[ $type ] <= 0 ) {
				continue;
			}
			foreach ( $item[0] as $term_id ) {
				$heat      = max( 0.0, ( $topic_heat[ $type ][ $term_id ] ?? 0.0 ) - $row['daily'] ) / $max_heat[ $type ];
				$momentum *= ( 1.0 + $heat * $item[1] );
			}
		}
		$momentum = min( $momentum, 1.5 ); // Cap tối đa 50%.

		$velocity  = $vel_exp > 0 ? pow( $row['daily'], $vel_exp ) : log( 1.0 + $row['daily'] );
		$age_mult  = init_plugin_suite_view_count_trending_age_factor( $row['age_hours'], $tuning );
		$freshness = init_plugin_suite_view_count_trending_freshness( $row['age_hours'], $tuning );

		$final_score = 10.0
			* pow( $velocity, max( 0.0, (float) $weights['velocity'] ) )
			* pow( $row['uplift'], $weights['uplift'] )
			* pow( $row['engagement'], $weights['engagement'] )
			* pow( $freshness, $weights['freshness'] )
			* pow( $momentum, $weights['momentum'] )
			* $age_mult
			* $row['anti'];

		// Kẹp tăng trưởng liên run — trên điểm THÔ (từ v2.0.6, section 'raw' riêng để không lẫn
		// với điểm đã chuẩn hoá do bản cũ lưu).
		$final_score = init_plugin_suite_view_count_cap_score_growth( $row['id'], $final_score, 'raw' );

		$meta     = $row['meta'];
		$signals  = $row['signals'];
		$hot      = $row['hot'];
		$expected = null !== $signals['base'] ? $signals['base'] * $signals['hours24'] : 0.0;

		$trending[] = array(
			'id'                => $row['id'],
			'score'             => init_plugin_suite_view_count_trending_display_score( $final_score ),
			'raw_score'         => $final_score,
			'views'             => $meta['day'],
			'views_day'         => $meta['day'],
			'views_week'        => $meta['week'],
			'views_month'       => $meta['month'],
			'views_total'       => $meta['total'],
			'views_day_used'    => (int) round( $signals['views24'] ),
			'views_24h'         => (int) round( $signals['views24'] ),
			'used_day_fallback' => (int) $signals['estimated'],
			'age_hours'         => round( $row['age_hours'], 2 ),
			'time'              => $now,
			'author_id'         => (int) $row['post']->post_author,
			'categories'        => $row['categories'],
			'tags'              => $row['tags'],
			'components'        => array(
				'velocity'     => round( $velocity, 4 ),
				'time_decay'   => round( $age_mult, 4 ),
				'engagement'   => round( $row['engagement'], 4 ),
				'freshness'    => round( $freshness, 4 ),
				'momentum'     => round( $momentum, 4 ),
				'uplift'       => round( $row['uplift'], 4 ),
				'uplift_raw'   => round( null !== $row['ratio'] ? $row['ratio'] : 1.0, 4 ),
				'expected'     => round( $expected, 2 ),
				'ewma'         => round( $row['rate24'] > 0 ? $hot['hot'] / $row['rate24'] : 1.0, 4 ),
				'ewma_val'     => round( $hot['hot'], 4 ),
				'acc'          => round( null !== $hot['prev'] ? $hot['hot'] - $hot['prev'] : 0.0, 4 ),
				'rate_24h'     => round( $row['rate24'], 4 ),
				'rate'         => round( $row['rate'], 4 ),
				'daily_rate'   => round( $row['daily'], 2 ),
				'baseline'     => null !== $signals['base'] ? round( $signals['base'], 4 ) : null,
				'views_delta'  => (int) $hot['delta'],
				'anti_gaming'  => round( $row['anti'], 4 ),
				'interactions' => (int) $row['actions'],
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

		$item['raw_score']                   *= pow( $fatigue_mult, $weights['fatigue'] );
		$item['score']                        = init_plugin_suite_view_count_trending_display_score( $item['raw_score'] );
		$item['components']['fatigue']        = round( $fatigue_mult, 4 );
		$item['components']['fatigue_streak'] = (int) $streak;
	}
	unset( $item );

	// Rank lần 2 (sau fatigue).
	usort(
		$trending,
		function ( $a, $b ) {
			return $b['raw_score'] <=> $a['raw_score'];
		}
	);

	// MMR re-rank (đa dạng nội dung). Trọng số 'mmr' (mặc định 1.0 → lambda 0.75 như cũ).
	// MMR chạy trên điểm THÔ: điểm hiển thị là thang log, phạt tương đồng trên thang log sẽ
	// mạnh hơn nhiều so với ý định (bài đọc gấp 5 lần vẫn có thể bị đẩy xuống).
	$lambda   = max( 0.5, min( 1.0, 1.0 - 0.25 * (float) $weights['mmr'] ) );
	$mmr_pool = array();
	foreach ( $trending as $item ) {
		$item['score'] = $item['raw_score'];
		$mmr_pool[]    = $item;
	}
	$mmr_out = init_plugin_suite_view_count_mmr_rerank( $mmr_pool, $lambda, 20 );
	foreach ( $mmr_out as &$item ) {
		$item['score'] = init_plugin_suite_view_count_trending_display_score( $item['raw_score'] );
	}
	unset( $item );

	// Explore/Exploit: bơm thử bài tiềm năng (mặc định tắt từ v2.0.6).
	$mmr_out = init_plugin_suite_view_count_maybe_explore( $mmr_out, $trending, $weights );

	// Diversity Filter quota (fill-back O(n)).
	$top_trending = init_plugin_suite_view_count_apply_diversity_filter( $mmr_out, 20 );

	// v2.0.6: transient công khai chỉ giữ các trường mà nơi đọc cần (REST /top, block,
	// shortcode đọc nó ở MỖI lượt xem trang có hiển thị Trending). Bản cũ lưu kèm toàn bộ
	// components/categories/tags của 20 bài (~5 lần dung lượng) chỉ để unserialize rồi bỏ đi.
	// Bản đầy đủ vẫn có trong transient 'init_plugin_suite_view_count_trending_debug'.
	$public_keys  = array_flip( array( 'id', 'score', 'views', 'views_day', 'views_week', 'views_month', 'views_total', 'views_24h', 'age_hours', 'time' ) );
	$top_trending = array_map(
		function ( $item ) use ( $public_keys ) {
			return array_intersect_key( $item, $public_keys );
		},
		$top_trending
	);

	set_transient( 'init_plugin_suite_view_count_trending', $top_trending, DAY_IN_SECONDS );
	set_transient(
		'init_plugin_suite_view_count_trending_debug',
		array_map(
			function ( $item ) {
				unset( $item['raw_score'] );
				return $item;
			},
			array_slice( $trending, 0, 50 )
		),
		DAY_IN_SECONDS
	);
	set_transient( 'trending_last_calculation', $now, DAY_IN_SECONDS );

	// Ghi trạng thái (tốc độ, mốc lượt xem, score, streak) xuống DB đúng 1 lần.
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
 * Kẹp tốc độ tăng score giữa 2 lượt chạy (tối đa +80% + 10).
 *
 * @param int    $post_id Post ID.
 * @param float  $score   Score mới.
 * @param string $section v2.0.6+: nhóm trạng thái lưu score kỳ trước (mặc định 'score' như cũ).
 * @return float
 */
function init_plugin_suite_view_count_cap_score_growth( $post_id, $score, $section = 'score' ) {
	$prev = init_plugin_suite_view_count_trending_state_get( $section, $post_id );
	if ( false !== $prev ) {
		$max_allowed = (float) $prev * 1.8 + 10;
		$score       = min( $score, $max_allowed );
	}
	init_plugin_suite_view_count_trending_state_set( $section, $post_id, $score, HOUR_IN_SECONDS * 6 );
	return $score;
}

/**
 * Điểm hiển thị (trending_score) từ điểm thô: 1000 × log10(1 + raw), làm tròn 4 chữ số.
 *
 * Từ v2.0.6 điểm thô tỉ lệ tuyến tính với lượt xem, nên có thể rất lớn trên site nhiều
 * traffic. Bản cũ nén bằng 10000 × (1 − e^(−x/5000)), vốn bão hoà ở 10000 khi điểm lớn (các
 * bài top hoà điểm nhau, mất thứ tự). Thang log luôn tăng ngặt, giữ đúng thứ tự, và vẫn nằm
 * trong khoảng quen thuộc 0–10000 (≈ 3000 cho ~100 lượt xem/ngày, ≈ 5000 cho ~10.000).
 *
 * @param float $raw Điểm thô.
 * @return float
 */
function init_plugin_suite_view_count_trending_display_score( $raw ) {
	return round( 1000 * log10( 1.0 + max( 0.0, (float) $raw ) ), 4 );
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
 * Từ v2.0.6 mặc định TẮT (trọng số 'explore' = 0): chèn ngẫu nhiên làm danh sách đổi thứ tự
 * không theo số liệu và kém ổn định giữa các lượt chạy. Đặt 'explore' = 1.0 qua filter
 * 'init_plugin_suite_view_count_trending_component_weights' để có lại 5% như bản cũ.
 *
 * @param array $ranked  Danh sách đã xếp hạng.
 * @param array $pool    Toàn bộ ứng viên.
 * @param array $weights Trọng số (dùng 'explore').
 * @return array
 */
function init_plugin_suite_view_count_maybe_explore( array $ranked, array $pool, $weights ) {
	$epsilon = min( 0.25, max( 0.0, 0.05 * (float) ( $weights['explore'] ?? 1.0 ) ) );

	// Tắt hẳn khi epsilon = 0 (mặc định từ v2.0.6), kể cả khi wp_rand() trả về đúng 0.
	if ( $epsilon <= 0 ) {
		return $ranked;
	}

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
