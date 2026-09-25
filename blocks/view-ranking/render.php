<?php
// Dynamic render cho block init-view-count/view-ranking.
// $attributes, $content, $block được WordPress tự inject khi dùng "render" trong block.json.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$allowed_tabs = array( 'total', 'day', 'week', 'month', 'yesterday', 'last_week', 'last_month' );

$raw_tabs      = isset( $attributes['tabs'] ) ? (string) $attributes['tabs'] : 'total,day,week,month';
$selected_tabs = array_filter( array_map( 'trim', explode( ',', $raw_tabs ) ) );
$selected_tabs = array_values( array_intersect( $selected_tabs, $allowed_tabs ) );
if ( empty( $selected_tabs ) ) {
	$selected_tabs = array( 'total', 'day', 'week', 'month' );
}

$number         = ! empty( $attributes['number'] ) ? absint( $attributes['number'] ) : 5;
$post_type_slug = isset( $attributes['postType'] ) ? sanitize_key( $attributes['postType'] ) : '';
$class_name     = isset( $attributes['className'] ) ? init_plugin_suite_view_count_sanitize_class_list( $attributes['className'] ) : '';

$shortcode_atts = array(
	'tabs'   => implode( ',', $selected_tabs ),
	'number' => $number,
);
if ( '' !== $post_type_slug ) {
	$shortcode_atts['post_type'] = $post_type_slug;
}
if ( '' !== $class_name ) {
	$shortcode_atts['class'] = $class_name;
}

// Gọi thẳng callback shortcode với mảng thuộc tính (không tự ghép chuỗi "[shortcode ...]"),
// để giá trị chứa dấu ngoặc vuông/dấu nháy (VD: tiêu đề "Top [2026]") không làm hỏng việc
// phân tích thuộc tính. Output vẫn đi qua đúng callback + filter của shortcode gốc.
$shortcode_output = init_plugin_suite_view_count_do_shortcode( 'init_view_ranking', $shortcode_atts );

// get_block_wrapper_attributes() đã tự escape nội bộ; $shortcode_output là HTML đã được
// callback shortcode escape từng phần.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '<div ' . get_block_wrapper_attributes() . '>' . $shortcode_output . '</div>';
