<?php
// Dynamic render cho block init-view-count/view-ranking.
// $attributes, $content, $block được WordPress tự inject khi dùng "render" trong block.json.
if (!defined('ABSPATH')) exit;

$allowed_tabs = ['total', 'day', 'week', 'month', 'yesterday', 'last_week', 'last_month'];

$raw_tabs      = isset($attributes['tabs']) ? (string) $attributes['tabs'] : 'total,day,week,month';
$selected_tabs = array_filter(array_map('trim', explode(',', $raw_tabs)));
$selected_tabs = array_values(array_intersect($selected_tabs, $allowed_tabs));
if (empty($selected_tabs)) {
    $selected_tabs = ['total', 'day', 'week', 'month'];
}

$number         = !empty($attributes['number']) ? absint($attributes['number']) : 5;
$post_type_slug = isset($attributes['postType']) ? sanitize_key($attributes['postType']) : '';
$class_name     = isset($attributes['className']) ? sanitize_html_class($attributes['className']) : '';

$shortcode_atts = [
    'tabs'   => implode(',', $selected_tabs),
    'number' => $number,
];
if ('' !== $post_type_slug) {
    $shortcode_atts['post_type'] = $post_type_slug;
}
if ('' !== $class_name) {
    $shortcode_atts['class'] = $class_name;
}

$shortcode_tag = '[init_view_ranking';
foreach ($shortcode_atts as $key => $value) {
    $shortcode_tag .= ' ' . $key . '="' . esc_attr($value) . '"';
}
$shortcode_tag .= ']';

// get_block_wrapper_attributes() đã tự escape nội bộ (dùng esc_attr trên từng
// giá trị thuộc tính), an toàn để xuất ra trực tiếp.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '<div ' . get_block_wrapper_attributes() . '>' . do_shortcode($shortcode_tag) . '</div>';
