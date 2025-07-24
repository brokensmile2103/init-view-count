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
    // phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
    $thumb_html = sprintf(
        '<img src="%s" alt="%s" class="init-plugin-suite-view-count-thumb-img" loading="lazy" />',
        esc_url(INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/img/thumbnail.svg'),
        esc_attr(get_the_title($item))
    );
    // phpcs:enable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
}
?>

<div class="init-plugin-suite-view-count-item init-plugin-suite-view-count-grid-item">
    <a class="init-plugin-suite-view-count-thumb" href="<?php the_permalink($item); ?>">
        <?php echo wp_kses_post($thumb_html); ?>
    </a>
    <div class="init-plugin-suite-view-count-meta">
        <a class="init-plugin-suite-view-count-title-link" href="<?php the_permalink($item); ?>">
            <?php echo esc_html(get_the_title($item)); ?>
        </a>
        <div class="init-plugin-suite-view-count-meta-info">
            <span class="init-plugin-suite-view-count-count">
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
