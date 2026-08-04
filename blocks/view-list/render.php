<?php
// Dynamic render cho block init-view-count/view-list.
// $attributes, $content, $block được WordPress tự inject khi dùng "render" trong block.json.
if (!defined('ABSPATH')) exit;

$number         = !empty($attributes['number']) ? absint($attributes['number']) : 10;
$post_type_slug = isset($attributes['postType']) ? sanitize_key($attributes['postType']) : 'post';

$allowed_templates = ['sidebar', 'grid', 'details', 'full'];
$template = (isset($attributes['template']) && in_array($attributes['template'], $allowed_templates, true))
    ? $attributes['template']
    : 'sidebar';

$allowed_ranges = ['total', 'day', 'week', 'month'];
$range = (isset($attributes['range']) && in_array($attributes['range'], $allowed_ranges, true))
    ? $attributes['range']
    : 'total';

$list_title    = isset($attributes['title']) ? trim((string) $attributes['title']) : '';
$term_category = isset($attributes['category']) ? sanitize_text_field($attributes['category']) : '';
$term_tag      = isset($attributes['tag']) ? sanitize_text_field($attributes['tag']) : '';
$empty_text = isset($attributes['emptyText']) ? sanitize_text_field($attributes['emptyText']) : '';
$class_name = isset($attributes['className']) ? sanitize_html_class($attributes['className']) : '';

$shortcode_atts = [
    'number'    => $number,
    'post_type' => $post_type_slug,
    'template'  => $template,
    'range'     => $range,
];

// Bỏ trống "title" thì để shortcode tự dùng giá trị mặc định của nó
// (Popular Posts), thay vì ép về chuỗi rỗng — giữ đúng hành vi vốn có.
if ('' !== $list_title) {
    $shortcode_atts['title'] = $list_title;
}
if ('' !== $term_category) {
    $shortcode_atts['category'] = $term_category;
}
if ('' !== $term_tag) {
    $shortcode_atts['tag'] = $term_tag;
}
if ('' !== $empty_text) {
    $shortcode_atts['empty'] = $empty_text;
}
if ('' !== $class_name) {
    $shortcode_atts['class'] = $class_name;
}

$shortcode_tag = '[init_view_list';
foreach ($shortcode_atts as $key => $value) {
    $shortcode_tag .= ' ' . $key . '="' . esc_attr($value) . '"';
}
$shortcode_tag .= ']';

// get_block_wrapper_attributes() đã tự escape nội bộ (dùng esc_attr trên từng
// giá trị thuộc tính), an toàn để xuất ra trực tiếp.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '<div ' . get_block_wrapper_attributes() . '>' . do_shortcode($shortcode_tag) . '</div>';
