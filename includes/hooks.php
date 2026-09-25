<?php
/**
 * Tự động chèn shortcode view count vào nội dung bài viết.
 *
 * @package Init_View_Count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kiểm tra có nên auto chèn không.
 *
 * Chỉ chèn vào nội dung của CHÍNH bài đang xem (queried object). Bản cũ chèn vào mọi
 * lần gọi the_content trên trang singular — kể cả nội dung của bài khác (khối "bài liên
 * quan", widget hiển thị full content...) hay khi WordPress tự sinh excerpt từ content.
 *
 * @param string $position 'before_content' hoặc 'after_content'.
 * @return bool
 */
function init_plugin_suite_view_count_should_auto_insert( $position ) {
	if ( ! is_singular() || is_admin() || is_feed() ) {
		return false;
	}

	$queried_id = (int) get_queried_object_id();
	$current_id = (int) get_the_ID();
	if ( $queried_id && $current_id && $queried_id !== $current_id ) {
		return false;
	}

	if ( doing_filter( 'get_the_excerpt' ) ) {
		return false;
	}

	$enabled_post_types = (array) get_option( 'init_plugin_suite_view_count_post_types', array( 'post' ) );
	$current_type       = get_post_type();

	if ( ! in_array( $current_type, $enabled_post_types, true ) ) {
		return false;
	}

	$selected = get_option( 'init_plugin_suite_view_count_auto_insert', 'none' );
	if ( $selected !== $position ) {
		return false;
	}

	return apply_filters(
		'init_plugin_suite_view_count_auto_insert_enabled',
		true,
		$position,
		$current_type
	);
}

/**
 * Lấy shortcode mặc định (có thể override).
 *
 * @return string
 */
function init_plugin_suite_view_count_get_default_shortcode() {
	return apply_filters(
		'init_plugin_suite_view_count_default_shortcode',
		'[init_view_count icon="true" format="short"]'
	);
}

// Hook vào nội dung.
add_filter(
	'the_content',
	function ( $content ) {
		if ( init_plugin_suite_view_count_should_auto_insert( 'before_content' ) ) {
			return do_shortcode( init_plugin_suite_view_count_get_default_shortcode() ) . "\n\n" . $content;
		}

		if ( init_plugin_suite_view_count_should_auto_insert( 'after_content' ) ) {
			return $content . "\n\n" . do_shortcode( init_plugin_suite_view_count_get_default_shortcode() );
		}

		return $content;
	},
	20
);
