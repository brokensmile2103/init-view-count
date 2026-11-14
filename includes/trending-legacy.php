<?php
/**
 * Các hàm chấm điểm Trending của bản 2.0.5 trở về trước.
 *
 * Từ v2.0.6 Trending Engine v4 (includes/trending.php) không còn gọi các hàm này. Chúng được
 * giữ nguyên (không đổi kết quả) để theme/plugin bên ngoài đang gọi trực tiếp không bị lỗi.
 *
 * @package Init_View_Count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ===================================================
// == CÁC THÀNH PHẦN ĐIỂM                           ==
// ===================================================

/**
 * Velocity Score - Tốc độ tăng trưởng lượt xem.
 *
 * @deprecated 2.0.6 Không còn được Trending Engine sử dụng; giữ lại để tương thích ngược.
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
 * @deprecated 2.0.6 Không còn được Trending Engine sử dụng; giữ lại để tương thích ngược.
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
 * @deprecated 2.0.6 Không còn được Trending Engine sử dụng; giữ lại để tương thích ngược.
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
 * @deprecated 2.0.6 Không còn được Trending Engine sử dụng; giữ lại để tương thích ngược.
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
 * @deprecated 2.0.6 Không còn được Trending Engine sử dụng; giữ lại để tương thích ngược.
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
 * Lấy hot topics (category/tag) trong 24h qua.
 *
 * @deprecated 2.0.6 Không còn được Trending Engine sử dụng; giữ lại để tương thích ngược.
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

/**
 * Kỳ vọng số view trong ngày (seasonality-aware) và hệ số uplift.
 *
 * @deprecated 2.0.6 Không còn được Trending Engine sử dụng; giữ lại để tương thích ngược.
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
 * @deprecated 2.0.6 Không còn được Trending Engine sử dụng; giữ lại để tương thích ngược.
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
 * @deprecated 2.0.6 Không còn được Trending Engine sử dụng; giữ lại để tương thích ngược.
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
