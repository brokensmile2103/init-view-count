<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode('init_view_list', function ($atts) {
    $atts = shortcode_atts([
        'number'          => 10,
        'post_type'       => 'post',
        'template'        => 'sidebar',
        'title'           => __('Popular Posts', 'init-view-count'),
        'class'           => '',
        'orderby'         => 'meta_value_num',
        'order'           => 'DESC',
        'range'           => 'total',
        'category'        => '',
        'tag'             => '',
        'empty'           => '',
        'page'            => 1,
    ], $atts, 'init_view_list');

    $meta_key_map = [
        'day'      => '_init_view_day_count',
        'week'     => '_init_view_week_count',
        'month'    => '_init_view_month_count',
        'trending' => '_init_view_count',
    ];

    $raw_meta_key = isset($meta_key_map[$atts['range']]) ? $meta_key_map[$atts['range']] : '_init_view_count';
    $meta_key = apply_filters('init_plugin_suite_view_count_meta_key', $raw_meta_key, null);

    $query_args = [
        'post_type'           => $atts['post_type'],
        'posts_per_page'      => absint($atts['number']),
        'offset'              => (max(1, absint($atts['page'])) - 1) * absint($atts['number']),
        'meta_key'            => $meta_key,
        'orderby'             => $atts['orderby'],
        'order'               => $atts['order'],
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
        'meta_query'          => [[
            'key'     => $meta_key,
            'compare' => 'EXISTS',
        ]],
    ];

    if (!empty($atts['category'])) {
        $query_args['category_name'] = sanitize_title($atts['category']);
    }
    if (!empty($atts['tag'])) {
        $query_args['tag'] = sanitize_title($atts['tag']);
    }

    $query_args = apply_filters('init_plugin_suite_view_count_query_args', $query_args, $atts);
    $atts = apply_filters('init_plugin_suite_view_count_view_list_atts', $atts);

    $query = new WP_Query($query_args);
    if (!$query->have_posts()) {
        $empty_output = apply_filters('init_plugin_suite_view_count_empty_output', $atts['empty'], $atts);
        return $empty_output ? '<p class="init-plugin-suite-view-count-empty">' . esc_html($empty_output) . '</p>' : '';
    }

    $template_file = 'view-list-' . sanitize_file_name($atts['template']) . '.php';
    $template = locate_template("init-view-count/$template_file") ?: INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'templates/' . $template_file;
    if (!file_exists($template)) {
        $template = INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'templates/view-list-sidebar.php';
    }

    $list_class = 'init-plugin-suite-view-count-list' . ($atts['template'] === 'grid' ? ' grid' : '');

    ob_start();
    ?>
    <div class="init-plugin-suite-view-count-list-wrapper <?php echo esc_attr($atts['class']); ?>">
        <?php if (!empty($atts['title'])) : ?>
            <h3 class="init-plugin-suite-view-count-title"><?php echo esc_html($atts['title']); ?></h3>
        <?php endif; ?>
        <div class="<?php echo esc_attr($list_class); ?>">
            <?php
            foreach ($query->posts as $item) {
                setup_postdata($item);
                $real_key = apply_filters('init_plugin_suite_view_count_meta_key', $meta_key, $item->ID);
                $item->init_plugin_suite_view_count = (int) get_post_meta($item->ID, $real_key, true);
                init_plugin_suite_view_count_render_template($template, ['item' => $item]);
            }
            ?>
        </div>
    </div>
    <?php
    wp_reset_postdata();
    return ob_get_clean();
});

function init_plugin_suite_view_count_render_template($path, $vars = []) {
    if (!file_exists($path)) return;
    extract($vars);
    include $path;
}

add_shortcode('init_view_count', function ($atts) {
    global $post;

    $atts = shortcode_atts([
        'id'     => '',         // ID bài viết cần đếm (tùy chọn)
        'field'  => 'total',    // Loại đếm: total, day, week, month
        'format' => 'formatted',// Kiểu hiển thị: formatted, short, raw
        'time'   => 'false',    // Có hiển thị thời gian đăng không
        'icon'   => 'false',    // Có hiển thị icon con mắt không
        'schema' => 'false',    // Có chèn schema tương tác không
        'class'  => '',         // Thêm class tùy ý
    ], $atts, 'init_view_count');

    // Xác định ID: nếu có truyền qua thì dùng, không thì lấy bài hiện tại
    $id = absint($atts['id']) ?: ($post->ID ?? 0);
    if (!$id) return ''; // Không có ID thì thoát

    $published = get_post_time('U', true, $id);

    // Ánh xạ field tương ứng với meta_key
    $meta_key_map = [
        'day'   => '_init_view_day_count',
        'week'  => '_init_view_week_count',
        'month' => '_init_view_month_count',
    ];
    $raw_meta_key = isset($meta_key_map[$atts['field']]) ? $meta_key_map[$atts['field']] : '_init_view_count';
    $meta_key     = apply_filters('init_plugin_suite_view_count_meta_key', $raw_meta_key, $id);

    // Lấy số lượt xem
    $views = (int) get_post_meta($id, $meta_key, true);

    // Xử lý format hiển thị
    switch ($atts['format']) {
        case 'raw':
            $view_text = $views;
            break;
        case 'short':
            $view_text = init_plugin_suite_view_count_format_thousands($views);
            break;
        default:
            $view_text = number_format_i18n($views);
            break;
    }

    // Bắt đầu render HTML
    $wrapper_classes = ['init-plugin-suite-view-count-views'];
    if (!empty($atts['class'])) {
        $wrapper_classes[] = sanitize_html_class($atts['class']);
    }

    $output  = '<span class="' . esc_attr(implode(' ', $wrapper_classes)) . '">';

    if ($atts['icon'] === 'true') {
        $output .= '<span class="init-plugin-suite-view-count-icon" aria-hidden="true">';
        $output .= '<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><circle fill="none" stroke="currentColor" cx="10" cy="10" r="3.45"></circle><path fill="none" stroke="currentColor" d="m19.5,10c-2.4,3.66-5.26,7-9.5,7h0,0,0c-4.24,0-7.1-3.34-9.49-7C2.89,6.34,5.75,3,9.99,3h0,0,0c4.25,0,7.11,3.34,9.5,7Z"></path></svg>';
        $output .= '</span>';
    }

    $output .= '<span class="init-plugin-suite-view-count-number" data-view="' . esc_attr($views) . '" data-id="' . esc_attr($id) . '">';
    $output .= esc_html($view_text) . '</span>';

    // Hiển thị thời gian đăng
    if ($atts['time'] === 'true' && $published) {
        $diff = human_time_diff($published, current_time('timestamp'));
        $output .= ' &middot; ' . sprintf(__('Posted %s ago', 'init-view-count'), esc_html($diff));
    }

    // Schema.org cho SEO
    if ($atts['schema'] === 'true') {
        $output .= '<meta itemprop="interactionStatistic" itemscope itemtype="https://schema.org/InteractionCounter">';
        $output .= '<meta itemprop="interactionType" content="https://schema.org/ViewAction" />';
        $output .= '<meta itemprop="userInteractionCount" content="' . esc_attr($views) . '" />';
    }

    return $output . '</span>';
});

add_shortcode('init_view_ranking', function ($atts) {
    $atts = shortcode_atts([
        'tabs'      => 'total,day,week,month',
        'number'    => 5,
        'class'     => '',
        'post_type' => '',
    ], $atts, 'init_view_ranking');

    wp_enqueue_script(
        'init-plugin-suite-view-count-ranking',
        INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/js/ranking.js',
        [],
        INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION,
        true
    );

    wp_localize_script('init-plugin-suite-view-count-ranking', 'InitViewRankingI18n', [
        'noData'     => __('No data found.', 'init-view-count'),
        'loadError'  => __('Failed to load data.', 'init-view-count'),
        'viewsLabel' => __('views', 'init-view-count'),
        'postType'   => $atts['post_type'],
    ]);

    $tabs = array_filter(array_map('trim', explode(',', $atts['tabs'])));
    if (empty($tabs)) return '';

    $labels = [
        'total' => __('All Time', 'init-view-count'),
        'day'   => __('Today', 'init-view-count'),
        'week'  => __('This Week', 'init-view-count'),
        'month' => __('This Month', 'init-view-count'),
    ];

    // Template override
    $template = locate_template('init-view-count/ranking.php') ?: INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'templates/ranking.php';
    if (!file_exists($template)) return '';

    ob_start();
    include $template;
    return ob_get_clean();
});

function init_plugin_suite_view_count_format_thousands($num) {
    if ($num < 1000) return (string) $num;

    $locale = get_locale();
    $suffixes = str_starts_with($locale, 'vi') ? ['N', 'Tr', 'T', 'TT'] : ['K', 'M', 'B', 'T'];
    $i = 0;
    while ($num >= 1000 && $i < count($suffixes)) {
        $num /= 1000;
        $i++;
    }

    $value = ($num - floor($num) > 0)
        ? number_format($num, 1, '.', '')
        : number_format($num, 0, '.', '');

    return $value . ' ' . $suffixes[$i - 1];
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    wp_enqueue_script(
        'init-view-count-shortcode-builder',
        INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/js/init-shortcode-builder.js',
        [],
        INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION,
        true
    );

    wp_localize_script(
        'init-view-count-shortcode-builder',
        'InitViewCountShortcodeBuilder',
        [
            'i18n' => [
                'copy'                => __( 'Copy', 'init-view-count' ),
                'copied'              => __( 'Copied!', 'init-view-count' ),
                'close'               => __( 'Close', 'init-view-count' ),
                'shortcode_preview'   => __( 'Shortcode Preview', 'init-view-count' ),
                'shortcode_builder'   => __( 'Shortcode Builder', 'init-view-count' ),
                'init_view_count'     => __( 'Init View Count', 'init-view-count' ),
                'init_view_list'      => __( 'Init View List', 'init-view-count' ),
                'init_view_ranking'   => __( 'Init View Ranking', 'init-view-count' ),
                'type'                => __( 'Type', 'init-view-count' ),
                'title'               => __( 'Title', 'init-view-count' ),
                'title_default'       => __( 'Popular Posts', 'init-view-count' ),
                'number'              => __( 'Number of Posts', 'init-view-count' ),
                'template'            => __( 'Template', 'init-view-count' ),
                'range'               => __( 'View Range', 'init-view-count' ),
                'post_type'           => __( 'Post Type', 'init-view-count' ),
                'category'            => __( 'Category', 'init-view-count' ),
                'tag'                 => __( 'Tag', 'init-view-count' ),
                'orderby'             => __( 'Order By', 'init-view-count' ),
                'order'               => __( 'Order Direction', 'init-view-count' ),
                'class'               => __( 'Custom CSS class', 'init-view-count' ),
                'field'               => __( 'Field', 'init-view-count' ),
                'format'              => __( 'Format', 'init-view-count' ),
                'time'                => __( 'Show Time Diff', 'init-view-count' ),
                'tabs'                => __( 'Tabs', 'init-view-count' ),
                'icon'                => __( 'Show Icon', 'init-view-count' ),
                'schema'              => __( 'Enable Schema.org', 'init-view-count' ),
                'class'               => __( 'Custom Class', 'init-view-count' ),
            ],
        ]
    );

    wp_enqueue_script(
        'init-view-count-admin-shortcode-panel',
        INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/js/shortcodes.js',
        [ 'init-view-count-shortcode-builder' ],
        INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION,
        true
    );
} );
