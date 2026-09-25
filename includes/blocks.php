<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================================
// Block Editor integration
// ----------------------------------------------------------------------------
// 3 block tương ứng 1-1 với 3 shortcode đã có: [init_view_count],
// [init_view_list], [init_view_ranking]. Mỗi block dùng "render" trong
// block.json (PHP, từ WP 6.1+) trỏ tới file render.php — file này chỉ build
// lại chuỗi shortcode tương ứng rồi gọi do_shortcode(), nên KHÔNG có logic
// hiển thị nào bị lặp lại/lệch so với shortcode gốc.
//
// Phần JS (assets/js/blocks-editor.js) là vanilla JS thuần, không build step,
// dùng ServerSideRender để preview ngay trong Block Editor.
// ============================================================================

add_filter( 'block_categories_all', 'init_plugin_suite_view_count_block_category', 10, 2 );
/**
 * Thêm 1 category riêng trong block inserter cho gọn, thay vì rơi vào "Widgets".
 *
 * @param array                   $categories     Danh sách category hiện có.
 * @param WP_Block_Editor_Context $editor_context Context hiện tại của editor (không dùng tới,
 *                                                nhưng bắt buộc phải khai báo theo đúng chữ ký
 *                                                mà hook 'block_categories_all' truyền vào).
 * @return array
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
function init_plugin_suite_view_count_block_category( $categories, $editor_context ) {
	return array_merge(
		array(
			array(
				'slug'  => 'init-view-count',
				'title' => __( 'Init View Count', 'init-view-count' ),
				'icon'  => 'chart-line',
			),
		),
		$categories
	);
}

add_action( 'init', 'init_plugin_suite_view_count_register_style_handle', 5 );
/**
 * Đăng ký (không enqueue) handle CSS front-end, để block.json của cả 3 block
 * có thể tham chiếu qua "style" — WordPress sẽ tự enqueue đúng lúc, đúng chỗ
 * (cả trong Block Editor lẫn ngoài front-end) khi block thực sự được dùng.
 *
 * Ưu tiên chạy trước (priority 5) hàm đăng ký block bên dưới.
 *
 * @return void
 */
function init_plugin_suite_view_count_register_style_handle() {
	if ( get_option( 'init_plugin_suite_view_count_disable_style' ) ) {
		return;
	}

	wp_register_style(
		'init-plugin-suite-view-count-style',
		INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/css/style.css',
		array(),
		INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION
	);
}

add_action( 'init', 'init_plugin_suite_view_count_register_blocks', 10 );
/**
 * Đăng ký script cho Block Editor và 3 block type.
 *
 * @return void
 */
function init_plugin_suite_view_count_register_blocks() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	wp_register_script(
		'init-view-count-blocks-editor',
		INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/js/blocks-editor.js',
		array(
			'wp-blocks',
			'wp-element',
			'wp-block-editor',
			'wp-components',
			'wp-i18n',
			'wp-server-side-render',
		),
		INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION,
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations(
			'init-view-count-blocks-editor',
			'init-view-count',
			INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'languages'
		);
	}

	register_block_type( INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'blocks/view-count' );
	register_block_type( INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'blocks/view-list' );
	register_block_type( INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'blocks/view-ranking' );
}
