<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$thumb_html = get_the_post_thumbnail($item, 'medium', [
    'class'   => 'init-plugin-suite-view-count-thumb-img',
    'loading' => 'lazy',
    'alt'     => get_the_title($item),
]);

if (!$thumb_html) {
    // This is a static fallback image for posts without thumbnails.
    // SVG is loaded directly because it's not in the media library.
    $thumb_html = sprintf(
        '<img src="%s" alt="%s" class="init-plugin-suite-view-count-thumb-img" loading="lazy" />',
        esc_url(INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/img/thumbnail.svg'),
        esc_attr(get_the_title($item))
    );
}
?>

<div class="init-plugin-suite-view-count-item init-plugin-suite-view-count-details">
    <a class="init-plugin-suite-view-count-thumb" href="<?php the_permalink($item); ?>">
        <?php echo wp_kses_post($thumb_html); ?>
    </a>
    <div class="init-plugin-suite-view-count-meta">
        <a class="init-plugin-suite-view-count-title-link" href="<?php the_permalink($item); ?>">
            <?php echo esc_html(get_the_title($item)); ?>
        </a>
        <p class="init-plugin-suite-view-count-excerpt init-plugin-suite-view-count-max-2-line">
            <?php echo esc_html(wp_trim_words(get_the_excerpt($item), 35, '...')); ?>
        </p>
        <div class="init-plugin-suite-view-count-meta-info">
            <span class="init-plugin-suite-view-count-date">
                <span class="init-plugin-suite-view-count-icon">
                    <svg width="20" height="20" viewBox="0 0 20 20">
                        <path d="M 2,3 2,17 18,17 18,3 2,3 Z M 17,16 3,16 3,8 17,8 17,16 Z M 17,7 3,7 3,4 17,4 17,7 Z"></path>
                        <rect width="1" height="3" x="6" y="2"></rect>
                        <rect width="1" height="3" x="13" y="2"></rect>
                    </svg>
                </span>
                <?php
                $human_time = init_plugin_suite_view_count_human_time_diff(get_the_time('U', $item));
                echo esc_html($human_time ?: get_the_date('', $item));
                ?>
            </span>

            <span class="init-plugin-suite-view-count-comments init-plugin-suite-view-count-margin-small-left">
                <span class="init-plugin-suite-view-count-icon">
                    <svg width="20" height="20" viewBox="0 0 20 20">
                        <path d="M6,18.71 L6,14 L1,14 L1,1 L19,1 L19,14 L10.71,14 L6,18.71 L6,18.71 Z M2,13 L7,13 L7,16.29 L10.29,13 L18,13 L18,2 L2,2 L2,13 L2,13 Z"></path>
                    </svg>
                </span>
                <?php echo esc_html(get_comments_number($item)); ?>
            </span>

            <span class="init-plugin-suite-view-count-count init-plugin-suite-view-count-margin-small-left">
                <span class="init-plugin-suite-view-count-icon">
                    <svg width="20" height="20" viewBox="0 0 20 20">
                        <circle fill="none" stroke="currentColor" cx="10" cy="10" r="3.45"></circle>
                        <path fill="none" stroke="currentColor" d="m19.5,10c-2.4,3.66-5.26,7-9.5,7h0c-4.24,0-7.1-3.34-9.49-7C2.89,6.34,5.75,3,9.99,3h0c4.25,0,7.11,3.34,9.5,7Z"></path>
                    </svg>
                </span>
                <?php echo esc_html(init_plugin_suite_view_count_format_thousands((int) $item->init_plugin_suite_view_count)); ?>
            </span>
        </div>
    </div>
</div>
