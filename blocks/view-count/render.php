<?php
// Dynamic render cho block init-view-count/view-count.
// $attributes, $content, $block được WordPress tự inject khi dùng "render" trong block.json.
if (!defined('ABSPATH')) exit;

$target_post_id = !empty($attributes['postId']) ? absint($attributes['postId']) : 0;

$allowed_fields = ['total', 'day', 'week', 'month'];
$view_field = (isset($attributes['field']) && in_array($attributes['field'], $allowed_fields, true))
    ? $attributes['field']
    : 'total';

$allowed_formats = ['formatted', 'short', 'raw'];
$view_format = (isset($attributes['format']) && in_array($attributes['format'], $allowed_formats, true))
    ? $attributes['format']
    : 'formatted';

$show_time   = !empty($attributes['showTime']);
$show_icon   = !empty($attributes['showIcon']);
$show_schema = !empty($attributes['showSchema']);
$class_name  = isset($attributes['className']) ? sanitize_html_class($attributes['className']) : '';

$shortcode_atts = [
    'id'     => $target_post_id,
    'field'  => $view_field,
    'format' => $view_format,
    'time'   => $show_time ? 'true' : 'false',
    'icon'   => $show_icon ? 'true' : 'false',
    'schema' => $show_schema ? 'true' : 'false',
];
if ('' !== $class_name) {
    $shortcode_atts['class'] = $class_name;
}

$shortcode_tag = '[init_view_count';
foreach ($shortcode_atts as $key => $value) {
    $shortcode_tag .= ' ' . $key . '="' . esc_attr($value) . '"';
}
$shortcode_tag .= ']';

// get_block_wrapper_attributes() đã tự escape nội bộ (dùng esc_attr trên từng
// giá trị thuộc tính), an toàn để xuất ra trực tiếp.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '<div ' . get_block_wrapper_attributes() . '>' . do_shortcode($shortcode_tag) . '</div>';
