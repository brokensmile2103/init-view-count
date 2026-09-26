<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================================
// Abilities API (WordPress 6.9+)
// ----------------------------------------------------------------------------
// Đăng ký các Ability CHỈ ĐỌC, cho phép AI agent / công cụ automation / plugin
// khác khám phá và đọc dữ liệu view count một cách chuẩn hoá, mà không cần
// biết trước cấu trúc REST route riêng của plugin.
//
// Toàn bộ callback ở đây chỉ ĐỌC dữ liệu (không ghi/không tăng view) để tránh
// rủi ro AI agent vô tình làm sai lệch số liệu thống kê.
//
// Kể từ v2.0.0, "Requires at least" của plugin đã nâng lên 6.9 (yêu cầu của
// WordPress.org Plugin Check, do công cụ này nhận diện wp_register_ability()
// theo kiểu static-scan, không phân tích được logic function_exists() bên
// dưới) — nên về mặt thực tế, hook 'wp_abilities_api_init' luôn tồn tại trên
// mọi site chạy được plugin. Vẫn giữ function_exists() như 1 lớp phòng thủ
// bổ sung (defense-in-depth), không phải để hỗ trợ site < 6.9 nữa.
// ============================================================================

add_action( 'wp_abilities_api_categories_init', 'init_plugin_suite_view_count_register_ability_category' );
/**
 * Đăng ký category riêng cho các ability của plugin.
 *
 * @return void
 */
function init_plugin_suite_view_count_register_ability_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category(
		'init-view-count',
		array(
			'label'       => __( 'Init View Count', 'init-view-count' ),
			'description' => __( 'Read-only abilities for querying tracked post view counts and popular posts.', 'init-view-count' ),
		)
	);
}

add_action( 'wp_abilities_api_init', 'init_plugin_suite_view_count_register_abilities' );
/**
 * Đăng ký các ability đọc dữ liệu view count.
 *
 * @return void
 */
function init_plugin_suite_view_count_register_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'init-view-count/get-post-views',
		array(
			'label'               => __( 'Get Post Views', 'init-view-count' ),
			'description'         => __( 'Returns the tracked view count for a single post (total, day, week, or month). Read-only, does not increment the counter.', 'init-view-count' ),
			'category'            => 'init-view-count',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the post to look up.', 'init-view-count' ),
						'minimum'     => 1,
					),
					'field'   => array(
						'type'        => 'string',
						'description' => __( 'Which counter to read.', 'init-view-count' ),
						'enum'        => array( 'total', 'day', 'week', 'month' ),
						'default'     => 'total',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'   => array(
						'type'        => 'integer',
						'description' => __( 'The post ID that was looked up.', 'init-view-count' ),
					),
					'field'     => array(
						'type'        => 'string',
						'description' => __( 'The counter field that was returned.', 'init-view-count' ),
					),
					'views'     => array(
						'type'        => 'integer',
						'description' => __( 'Raw view count.', 'init-view-count' ),
					),
					'formatted' => array(
						'type'        => 'string',
						'description' => __( 'Locale-formatted view count (e.g. "1,234").', 'init-view-count' ),
					),
					'short'     => array(
						'type'        => 'string',
						'description' => __( 'Abbreviated view count (e.g. "1.2K").', 'init-view-count' ),
					),
				),
			),
			'execute_callback'    => 'init_plugin_suite_view_count_ability_get_post_views',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		)
	);

	wp_register_ability(
		'init-view-count/get-top-posts',
		array(
			'label'               => __( 'Get Top Viewed Posts', 'init-view-count' ),
			'description'         => __( 'Returns a ranked list of the most viewed posts for a given time range (total, day, week, month, or trending). Wraps the same data used by the [init_view_ranking] shortcode and the GET /top REST route.', 'init-view-count' ),
			'category'            => 'init-view-count',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'number'    => array(
						'type'        => 'integer',
						'description' => __( 'How many posts to return.', 'init-view-count' ),
						'minimum'     => 1,
						'maximum'     => 50,
						'default'     => 5,
					),
					'page'      => array(
						'type'        => 'integer',
						'description' => __( 'Pagination page number.', 'init-view-count' ),
						'minimum'     => 1,
						'default'     => 1,
					),
					'post_type' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Post types to include.', 'init-view-count' ),
						'default'     => array( 'post', 'page' ),
					),
					'range'     => array(
						'type'        => 'string',
						'description' => __( 'Time range to rank by.', 'init-view-count' ),
						'enum'        => array( 'total', 'day', 'week', 'month', 'yesterday', 'last_week', 'last_month', 'trending' ),
						'default'     => 'total',
					),
					'fields'    => array(
						'type'        => 'string',
						'description' => __( '"minimal" returns only id/title/link; "full" includes excerpt, thumbnail, category, etc.', 'init-view-count' ),
						'enum'        => array( 'full', 'minimal' ),
						'default'     => 'full',
					),
					'tax'       => array(
						'type'        => 'string',
						'description' => __( 'Optional taxonomy slug to filter by.', 'init-view-count' ),
					),
					'terms'     => array(
						'type'        => 'string',
						'description' => __( 'Comma-separated term slugs or IDs, used together with "tax".', 'init-view-count' ),
					),
					'no_cache'  => array(
						'type'        => 'boolean',
						'description' => __( 'Bypass the 5-minute result cache.', 'init-view-count' ),
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => __( 'Post ID.', 'init-view-count' ),
						),
						'title' => array(
							'type'        => 'string',
							'description' => __( 'Post title.', 'init-view-count' ),
						),
						'link'  => array(
							'type'        => 'string',
							'description' => __( 'Post permalink.', 'init-view-count' ),
						),
						'views' => array(
							'type'        => 'integer',
							'description' => __( 'View count for the requested range.', 'init-view-count' ),
						),
					),
				),
			),
			'execute_callback'    => 'init_plugin_suite_view_count_ability_get_top_posts',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute callback cho ability "get-post-views".
 *
 * Tự triển khai gọn (không đi qua REST /count vì route đó dùng để TĂNG view,
 * không phải để đọc) — logic map field -> meta key giống hệt shortcode
 * [init_view_count] và REST /count để đảm bảo số liệu nhất quán.
 *
 * @param array $input Input đã được validate theo input_schema.
 * @return array|WP_Error
 */
function init_plugin_suite_view_count_ability_get_post_views( $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$field   = isset( $input['field'] ) ? sanitize_key( $input['field'] ) : 'total';

	if ( ! $post_id || get_post_status( $post_id ) !== 'publish' ) {
		return new WP_Error(
			'init_view_count_invalid_post',
			__( 'Invalid or non-published post ID.', 'init-view-count' ),
			array( 'status' => 404 )
		);
	}

	$meta_key_map = array(
		'day'   => '_init_view_day_count',
		'week'  => '_init_view_week_count',
		'month' => '_init_view_month_count',
	);

	$normalized_field = isset( $meta_key_map[ $field ] ) ? $field : 'total';
	$raw_meta_key     = $meta_key_map[ $normalized_field ] ?? '_init_view_count';
	$meta_key         = apply_filters( 'init_plugin_suite_view_count_meta_key', $raw_meta_key, $post_id );

	$views = (int) get_post_meta( $post_id, $meta_key, true );

	return array(
		'post_id'   => $post_id,
		'field'     => $normalized_field,
		'views'     => $views,
		'formatted' => number_format_i18n( $views ),
		'short'     => init_plugin_suite_view_count_format_thousands( $views ),
	);
}

/**
 * Execute callback cho ability "get-top-posts".
 *
 * Tái sử dụng NGUYÊN VẸN logic đã có ở init_plugin_suite_view_count_top_callback()
 * (bao gồm cả nhánh "trending") bằng cách dựng một WP_REST_Request giả lập rồi
 * gọi thẳng hàm đó — đảm bảo kết quả ability luôn khớp 100% với GET /top,
 * tránh hai nơi cùng chứa logic dễ lệch nhau theo thời gian.
 *
 * @param array $input Input đã được validate theo input_schema.
 * @return array|WP_Error
 */
function init_plugin_suite_view_count_ability_get_top_posts( $input ) {
	if ( ! function_exists( 'init_plugin_suite_view_count_top_callback' ) || ! class_exists( 'WP_REST_Request' ) ) {
		return new WP_Error(
			'init_view_count_unavailable',
			__( 'The view count REST layer is not available.', 'init-view-count' )
		);
	}

	$request = new WP_REST_Request( 'GET', '/' . INIT_PLUGIN_SUITE_VIEW_COUNT_NAMESPACE . '/top' );

	if ( isset( $input['number'] ) ) {
		$request->set_param( 'number', absint( $input['number'] ) );
	}
	if ( isset( $input['page'] ) ) {
		$request->set_param( 'page', absint( $input['page'] ) );
	}
	if ( isset( $input['post_type'] ) ) {
		$request->set_param( 'post_type', array_map( 'sanitize_key', (array) $input['post_type'] ) );
	}
	if ( isset( $input['range'] ) ) {
		$request->set_param( 'range', sanitize_key( $input['range'] ) );
	}
	if ( isset( $input['fields'] ) ) {
		$request->set_param( 'fields', sanitize_key( $input['fields'] ) );
	}
	if ( ! empty( $input['tax'] ) ) {
		$request->set_param( 'tax', sanitize_key( $input['tax'] ) );
	}
	if ( ! empty( $input['terms'] ) ) {
		$request->set_param( 'terms', sanitize_text_field( $input['terms'] ) );
	}
	// Route /top đọc no_cache dạng chuỗi '1' (xem init_plugin_suite_view_count_top_callback()).
	if ( ! empty( $input['no_cache'] ) ) {
		$request->set_param( 'no_cache', '1' );
	}

	$response = init_plugin_suite_view_count_top_callback( $request );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return ( $response instanceof WP_REST_Response ) ? $response->get_data() : $response;
}
