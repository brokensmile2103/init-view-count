<?php
/**
 * REST API: POST /count (ghi nhận view) và GET /top (bảng xếp hạng).
 *
 * @package Init_View_Count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			INIT_PLUGIN_SUITE_VIEW_COUNT_NAMESPACE,
			'/count',
			array(
				'methods'             => 'POST',
				'callback'            => 'init_plugin_suite_view_count_count_callback',
				'permission_callback' => 'init_plugin_suite_view_count_count_permission_callback',
			)
		);

		// /top chỉ đọc dữ liệu công khai (danh sách bài viết xem nhiều) nên không áp dụng
		// kiểm tra nonce — vẫn luôn mở, kể cả khi "Require REST nonce verification?" được bật.
		register_rest_route(
			INIT_PLUGIN_SUITE_VIEW_COUNT_NAMESPACE,
			'/top',
			array(
				'methods'             => 'GET',
				'callback'            => 'init_plugin_suite_view_count_top_callback',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * Permission callback cho /count.
 *
 * Mặc định luôn cho phép (vẫn public để hoạt động với mọi loại cache).
 * Khi admin bật option "init_plugin_suite_view_count_require_nonce", request bắt buộc
 * phải kèm header X-WP-Nonce hợp lệ (action 'wp_rest').
 *
 * @param WP_REST_Request $request Request hiện tại.
 * @return true|WP_Error
 */
function init_plugin_suite_view_count_count_permission_callback( $request ) {
	if ( 1 !== (int) get_option( 'init_plugin_suite_view_count_require_nonce', 0 ) ) {
		return true;
	}

	$nonce = $request->get_header( 'X-WP-Nonce' );

	if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error(
			'init_view_count_invalid_nonce',
			__( 'Invalid or expired security token.', 'init-view-count' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Callback cho POST /count.
 *
 * @param WP_REST_Request $request Request hiện tại.
 * @return WP_REST_Response
 */
function init_plugin_suite_view_count_count_callback( $request ) {
	$ids      = $request->get_param( 'post_id' );
	$post_ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array( absint( $ids ) );

	// Loại ID trùng trong cùng 1 request: client chính thức không bao giờ gửi trùng,
	// nên đây chỉ chặn việc 1 request giả mạo cộng N view cho cùng 1 bài.
	$post_ids = array_values( array_unique( $post_ids ) );

	$limit    = max( 1, absint( get_option( 'init_plugin_suite_view_count_batch', 1 ) ) );
	$post_ids = array_slice( $post_ids, 0, $limit );
	$results  = array();

	// Các setting này là toàn site, không đổi theo post → đọc 1 lần ngoài vòng lặp.
	$allowed      = (array) apply_filters(
		'init_plugin_suite_view_count_force_post_types',
		(array) get_option( 'init_plugin_suite_view_count_post_types', array( 'post' ) )
	);
	$strict_ip    = (bool) get_option( 'init_plugin_suite_view_count_strict_ip_check', 0 );
	$enable_day   = 0 !== (int) get_option( 'init_plugin_suite_view_count_enable_day', 1 );
	$enable_week  = 0 !== (int) get_option( 'init_plugin_suite_view_count_enable_week', 1 );
	$enable_month = 0 !== (int) get_option( 'init_plugin_suite_view_count_enable_month', 1 );

	foreach ( $post_ids as $post_id ) {
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			$results[] = array(
				'post_id' => $post_id,
				'error'   => __( 'Invalid post ID.', 'init-view-count' ),
			);
			continue;
		}

		if ( ! in_array( get_post_type( $post_id ), $allowed, true ) ) {
			$results[] = array(
				'post_id' => $post_id,
				'error'   => __( 'Not enabled for view counting.', 'init-view-count' ),
			);
			continue;
		}

		if ( $strict_ip && init_plugin_suite_view_count_is_ip_recent( $post_id ) ) {
			$results[] = array(
				'post_id' => $post_id,
				'skipped' => true,
				'reason'  => 'ip_duplicate',
			);
			continue;
		}

		if ( ! apply_filters( 'init_plugin_suite_view_count_should_count', true, $post_id, $request ) ) {
			$results[] = array(
				'post_id' => $post_id,
				'skipped' => true,
			);
			continue;
		}

		$updated = array( 'post_id' => $post_id );
		$keys    = array();

		// Đọc giá trị hiện tại qua get_post_meta() (có object cache, rẻ — KHÔNG re-query sau khi ghi).
		// Việc +1 thực tế trong DB được thực hiện atomic bằng SQL (gộp mọi key vào 1 câu UPDATE);
		// giá trị trả về cho client là "giá trị đã đọc + 1".
		$meta_total = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_count', $post_id );
		$views      = (int) get_post_meta( $post_id, $meta_total, true ) + 1;
		$keys[]     = $meta_total;

		$updated['total']           = $views;
		$updated['total_formatted'] = number_format_i18n( $views );
		$updated['total_short']     = init_plugin_suite_view_count_format_thousands( $views );

		if ( $enable_day ) {
			$meta_day       = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_day_count', $post_id );
			$updated['day'] = (int) get_post_meta( $post_id, $meta_day, true ) + 1;
			$keys[]         = $meta_day;
		}

		if ( $enable_week ) {
			$meta_week       = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_week_count', $post_id );
			$updated['week'] = (int) get_post_meta( $post_id, $meta_week, true ) + 1;
			$keys[]          = $meta_week;
		}

		if ( $enable_month ) {
			$meta_month       = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_month_count', $post_id );
			$updated['month'] = (int) get_post_meta( $post_id, $meta_month, true ) + 1;
			$keys[]           = $meta_month;
		}

		init_plugin_suite_view_count_atomic_increment_many( $post_id, $keys );

		// Toàn bộ meta key của post này vừa được ghi bằng SQL thuần (bypass cache) →
		// chỉ cần xoá cache đúng 1 lần cho post này.
		init_plugin_suite_view_count_flush_meta_cache( $post_id );

		do_action( 'init_plugin_suite_view_count_after_counted', $post_id, $updated, $request );

		$results[] = $updated;
	}

	return rest_ensure_response( $results );
}

/**
 * Chuẩn hoá danh sách post type nhận từ request /top: chỉ giữ post type tồn tại
 * và xem được công khai (is_post_type_viewable), để endpoint public này không
 * làm lộ tiêu đề/nội dung của post type nội bộ (VD: wp_block, wp_navigation...).
 *
 * Filter 'init_plugin_suite_view_count_top_post_types' vẫn chạy SAU bước này, nên
 * developer vẫn có thể chủ động thêm post type khác nếu thật sự cần.
 *
 * @param mixed $raw Giá trị thô (string hoặc array).
 * @return string[]
 */
function init_plugin_suite_view_count_sanitize_top_post_types( $raw ) {
	if ( is_string( $raw ) ) {
		$raw = explode( ',', $raw );
	}

	$clean = array();
	foreach ( (array) $raw as $type ) {
		if ( ! is_scalar( $type ) ) {
			continue;
		}
		$type = sanitize_key( (string) $type );
		if ( '' !== $type && post_type_exists( $type ) && is_post_type_viewable( $type ) ) {
			$clean[] = $type;
		}
	}

	return array_values( array_unique( $clean ) );
}

/**
 * Danh sách trending đã xếp theo score giảm dần (đúng thứ tự GET /top?range=trending
 * hiển thị). Dùng chung cho nhánh trending lẫn việc gắn cờ trending vào các range
 * khác, để 1 bài luôn có CÙNG trending_position dù đọc từ endpoint nào.
 *
 * Trả về mảng rỗng khi Trending đang bị tắt trong Settings (không hiển thị lại
 * dữ liệu trending cũ còn sót trong transient).
 *
 * @return array[] Danh sách entry trending (mỗi entry có 'id', 'score', 'views').
 */
function init_plugin_suite_view_count_get_sorted_trending() {
	if ( ! init_view_count_trending_enabled() ) {
		return array();
	}

	$trending = get_transient( 'init_plugin_suite_view_count_trending' );
	if ( ! is_array( $trending ) || empty( $trending ) ) {
		return array();
	}

	$trending = array_values(
		array_filter(
			$trending,
			function ( $entry ) {
				return is_array( $entry ) && isset( $entry['id'] );
			}
		)
	);

	usort(
		$trending,
		function ( $a, $b ) {
			return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
		}
	);

	return $trending;
}

/**
 * Dựng phần dữ liệu "full" cho 1 post trong kết quả /top (dùng chung cho mọi range).
 *
 * @param WP_Post $post  Post.
 * @param int     $views Số view hiển thị.
 * @return array
 */
function init_plugin_suite_view_count_build_top_item_fields( $post, $views ) {
	$post_type_slug = get_post_type( $post );
	$post_type_obj  = get_post_type_object( $post_type_slug );
	$post_type_name = $post_type_obj ? $post_type_obj->labels->singular_name : $post_type_slug;

	$taxonomy      = apply_filters( 'init_plugin_suite_live_search_category_taxonomy', 'category', $post->ID );
	$category      = get_the_terms( $post->ID, $taxonomy );
	$category_name = ( $category && ! is_wp_error( $category ) ) ? $category[0]->name : '';

	$thumbnail = get_the_post_thumbnail_url( $post, 'thumbnail' );

	return array(
		'excerpt'   => get_the_excerpt( $post ),
		'views'     => (int) $views,
		'thumbnail' => $thumbnail ? $thumbnail : INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/img/thumbnail.svg',
		'post_type' => $post_type_slug,
		'type'      => $post_type_name,
		'category'  => apply_filters( 'init_plugin_suite_live_search_category', $category_name, $post->ID ),
		'date'      => get_the_date( '', $post ),
	);
}

/**
 * Callback cho GET /top.
 *
 * @param WP_REST_Request $request Request hiện tại.
 * @return WP_REST_Response
 */
function init_plugin_suite_view_count_top_callback( $request ) {
	$range = $request->get_param( 'range' );
	$range = ( is_string( $range ) && '' !== $range ) ? sanitize_key( $range ) : 'total';

	$number = absint( $request->get_param( 'number' ) );
	$number = $number ? $number : 5;

	// Giới hạn trên để 1 request public không thể bắt server dựng hàng nghìn item.
	$max_number = max( 1, (int) apply_filters( 'init_plugin_suite_view_count_api_top_max_number', 100, $request ) );
	$number     = min( $number, $max_number );

	$page   = max( 1, absint( $request->get_param( 'page' ) ) );
	$offset = ( $page - 1 ) * $number;

	$raw_post_type = $request->get_param( 'post_type' );
	if ( empty( $raw_post_type ) ) {
		$raw_post_type = array( 'post', 'page' );
	}
	$post_type = (array) apply_filters(
		'init_plugin_suite_view_count_top_post_types',
		init_plugin_suite_view_count_sanitize_top_post_types( $raw_post_type ),
		$request
	);

	if ( empty( $post_type ) ) {
		return rest_ensure_response( array() );
	}

	$fields   = 'minimal' === $request->get_param( 'fields' ) ? 'minimal' : 'full';
	$no_cache = '1' === (string) $request->get_param( 'no_cache' );

	$tax   = $request->get_param( 'tax' );
	$tax   = is_string( $tax ) ? sanitize_key( $tax ) : '';
	$terms = $request->get_param( 'terms' );
	if ( is_array( $terms ) ) {
		$terms = implode( ',', array_filter( $terms, 'is_scalar' ) );
	}
	$terms = is_scalar( $terms ) ? (string) $terms : '';

	if ( 'trending' === $range ) {
		return rest_ensure_response(
			init_plugin_suite_view_count_top_trending_items( $request, $post_type, $fields, $number, $offset )
		);
	}

	$meta_key_map = array(
		'day'        => '_init_view_day_count',
		'week'       => '_init_view_week_count',
		'month'      => '_init_view_month_count',
		'yesterday'  => '_init_view_day_yesterday',
		'last_week'  => '_init_view_week_last',
		'last_month' => '_init_view_month_last',
	);

	$meta_key = isset( $meta_key_map[ $range ] ) ? $meta_key_map[ $range ] : '_init_view_count';
	$meta_key = apply_filters( 'init_plugin_suite_view_count_meta_key', $meta_key, null );

	$cache_key = init_plugin_suite_view_count_top_cache_key( $request );
	if ( ! $no_cache ) {
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}
	}

	$args = array(
		'post_type'           => $post_type,
		'posts_per_page'      => $number,
		'offset'              => $offset,
		'post_status'         => 'publish',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_key'            => $meta_key,
		'orderby'             => 'meta_value_num',
		'order'               => 'DESC',
		'no_found_rows'       => true,
		// Đây là bảng xếp hạng theo lượt xem, không phải danh sách bài viết thường
		// → không để WordPress tự đẩy sticky post lên đầu bất kể lượt xem thực tế.
		'ignore_sticky_posts' => true,
	);

	if ( $tax && '' !== $terms && taxonomy_exists( $tax ) ) {
		$term_array = array_values( array_filter( array_map( 'sanitize_title', explode( ',', $terms ) ), 'strlen' ) );
		if ( ! empty( $term_array ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'] = array(
				array(
					'taxonomy' => $tax,
					'field'    => is_numeric( $term_array[0] ) ? 'term_id' : 'slug',
					'terms'    => $term_array,
					'operator' => 'IN',
				),
			);
		}
	}

	$args  = apply_filters( 'init_plugin_suite_view_count_api_top_args', $args, $request );
	$query = new WP_Query( $args );

	if ( 'minimal' !== $fields ) {
		// Nạp sẵn toàn bộ ảnh đại diện trong 1 lượt thay vì N query riêng lẻ.
		update_post_thumbnail_cache( $query );
	}

	$results = array();

	foreach ( $query->posts as $post ) {
		$item = array(
			'id'    => $post->ID,
			'title' => get_the_title( $post ),
			'link'  => get_permalink( $post ),
		);

		if ( 'minimal' === $fields ) {
			$results[] = $item;
			continue;
		}

		$results[] = apply_filters(
			'init_plugin_suite_view_count_api_top_item',
			array_merge(
				$item,
				init_plugin_suite_view_count_build_top_item_fields( $post, (int) get_post_meta( $post->ID, $meta_key, true ) )
			),
			$post,
			$request
		);
	}

	if ( 'minimal' !== $fields ) {
		$trending = init_plugin_suite_view_count_get_sorted_trending();
		if ( ! empty( $trending ) ) {
			$map = array();
			foreach ( $trending as $i => $entry ) {
				$map[ $entry['id'] ] = array(
					'position' => $i + 1,
					'score'    => $entry['score'] ?? null,
					'views'    => $entry['views'] ?? null,
				);
			}

			foreach ( $results as &$result_item ) {
				if ( is_array( $result_item ) && isset( $result_item['id'], $map[ $result_item['id'] ] ) ) {
					$result_item['trending']          = true;
					$result_item['trending_position'] = $map[ $result_item['id'] ]['position'];
					$result_item['trending_score']    = $map[ $result_item['id'] ]['score'];
					$result_item['trending_views']    = $map[ $result_item['id'] ]['views'];
				}
			}
			unset( $result_item );
		}
	}

	if ( ! $no_cache ) {
		$ttl = apply_filters( 'init_plugin_suite_view_count_api_top_cache_time', 5 * MINUTE_IN_SECONDS, $request );
		set_transient( $cache_key, $results, $ttl );
	}

	return rest_ensure_response( $results );
}

/**
 * Dựng danh sách item cho GET /top?range=trending.
 *
 * @param WP_REST_Request $request   Request hiện tại.
 * @param string[]        $post_type Post type đã chuẩn hoá.
 * @param string          $fields    'minimal' hoặc 'full'.
 * @param int             $number    Số item mỗi trang.
 * @param int             $offset    Vị trí bắt đầu.
 * @return array
 */
function init_plugin_suite_view_count_top_trending_items( $request, $post_type, $fields, $number, $offset ) {
	$trending = init_plugin_suite_view_count_get_sorted_trending();
	if ( empty( $trending ) ) {
		return array();
	}

	$sliced = array_slice( $trending, $offset, $number );
	$ids    = array_map( 'absint', wp_list_pluck( $sliced, 'id' ) );

	if ( empty( $ids ) ) {
		return array();
	}

	$query = new WP_Query(
		array(
			'post__in'            => $ids,
			'orderby'             => 'post__in',
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'posts_per_page'      => count( $ids ),
			'no_found_rows'       => true,
			// Danh sách trending đã được xếp hạng sẵn theo score; không để WordPress
			// đẩy sticky post lên đầu làm sai thứ tự đã tính toán.
			'ignore_sticky_posts' => true,
		)
	);

	$trending_map = array();
	foreach ( $sliced as $i => $entry ) {
		$trending_map[ $entry['id'] ] = array(
			'position' => $offset + $i + 1,
			'score'    => $entry['score'] ?? null,
			'views'    => $entry['views'] ?? null,
		);
	}

	if ( 'minimal' !== $fields ) {
		update_post_thumbnail_cache( $query );
	}

	$results = array();
	foreach ( $query->posts as $post ) {
		$base = array(
			'id'    => $post->ID,
			'title' => get_the_title( $post ),
			'link'  => get_permalink( $post ),
		);

		if ( 'minimal' === $fields ) {
			$results[] = $base;
			continue;
		}

		$entry      = $trending_map[ $post->ID ] ?? array(
			'position' => null,
			'score'    => null,
			'views'    => null,
		);
		$meta_total = apply_filters( 'init_plugin_suite_view_count_meta_key', '_init_view_count', $post->ID );

		$results[] = apply_filters(
			'init_plugin_suite_view_count_api_top_item',
			array_merge(
				$base,
				init_plugin_suite_view_count_build_top_item_fields( $post, (int) get_post_meta( $post->ID, $meta_total, true ) ),
				array(
					'trending'          => true,
					'trending_position' => $entry['position'],
					'trending_score'    => $entry['score'],
					'trending_views'    => $entry['views'],
				)
			),
			$post,
			$request
		);
	}

	return $results;
}

/**
 * Tạo cache key ổn định cho GET /top.
 *
 * Vẫn dựa trên TOÀN BỘ query param (để filter bên ngoài dựa vào param tuỳ biến,
 * VD 'lang' của plugin đa ngôn ngữ, không bị dùng chung cache sai), nhưng:
 * - sắp xếp key để ?a=1&b=2 và ?b=2&a=1 dùng chung 1 cache;
 * - bỏ param cache-buster '_' và 'no_cache' vốn không đổi kết quả, tránh sinh vô
 *   hạn transient rác trong wp_options.
 *
 * @param WP_REST_Request $request Request hiện tại.
 * @return string
 */
function init_plugin_suite_view_count_top_cache_key( $request ) {
	$params = (array) $request->get_query_params();
	unset( $params['_'], $params['no_cache'] );

	$params = init_plugin_suite_view_count_ksort_recursive( $params );

	return 'init_plugin_suite_view_count_top_' . md5( http_build_query( $params ) );
}

/**
 * Sắp xếp key của mảng (đệ quy) để tạo chuỗi ổn định.
 *
 * @param array $data Mảng cần sắp xếp.
 * @return array
 */
function init_plugin_suite_view_count_ksort_recursive( array $data ) {
	foreach ( $data as $key => $value ) {
		if ( is_array( $value ) ) {
			$data[ $key ] = init_plugin_suite_view_count_ksort_recursive( $value );
		}
	}
	ksort( $data );
	return $data;
}
