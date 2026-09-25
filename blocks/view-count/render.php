<?php
// Dynamic render cho block init-view-count/view-count.
// $attributes, $content, $block được WordPress tự inject khi dùng "render" trong block.json.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$target_post_id = ! empty( $attributes['postId'] ) ? absint( $attributes['postId'] ) : 0;

// postId = 0 → ưu tiên bài hiện tại theo block context (Query Loop, template FSE),
// sau đó mới tới global $post như trước.
if ( ! $target_post_id && isset( $block ) && $block instanceof WP_Block && ! empty( $block->context['postId'] ) ) {
	$target_post_id = absint( $block->context['postId'] );
}

$allowed_fields = array( 'total', 'day', 'week', 'month' );
$view_field     = ( isset( $attributes['field'] ) && in_array( $attributes['field'], $allowed_fields, true ) )
	? $attributes['field']
	: 'total';

$allowed_formats = array( 'formatted', 'short', 'raw' );
$view_format     = ( isset( $attributes['format'] ) && in_array( $attributes['format'], $allowed_formats, true ) )
	? $attributes['format']
	: 'formatted';

$show_time   = ! empty( $attributes['showTime'] );
$show_icon   = ! empty( $attributes['showIcon'] );
$show_schema = ! empty( $attributes['showSchema'] );
$class_name  = isset( $attributes['className'] ) ? init_plugin_suite_view_count_sanitize_class_list( $attributes['className'] ) : '';

$shortcode_atts = array(
	'id'     => $target_post_id,
	'field'  => $view_field,
	'format' => $view_format,
	'time'   => $show_time ? 'true' : 'false',
	'icon'   => $show_icon ? 'true' : 'false',
	'schema' => $show_schema ? 'true' : 'false',
);
if ( '' !== $class_name ) {
	$shortcode_atts['class'] = $class_name;
}

// Gọi thẳng callback shortcode với mảng thuộc tính (không tự ghép chuỗi "[shortcode ...]"),
// để giá trị chứa dấu ngoặc vuông/dấu nháy (VD: tiêu đề "Top [2026]") không làm hỏng việc
// phân tích thuộc tính. Output vẫn đi qua đúng callback + filter của shortcode gốc.
$shortcode_output = init_plugin_suite_view_count_do_shortcode( 'init_view_count', $shortcode_atts );

// get_block_wrapper_attributes() đã tự escape nội bộ; $shortcode_output là HTML đã được
// callback shortcode escape từng phần.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '<div ' . get_block_wrapper_attributes() . '>' . $shortcode_output . '</div>';
