<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_dashboard_setup', function() {
    if ( ! current_user_can( 'edit_posts' ) ) return;

    $enabled = get_option( 'init_plugin_suite_view_count_enable_widget', 1 );
    if ( ! $enabled ) return;

    wp_add_dashboard_widget(
        'init_view_count_widget',
        __( 'Init View Count', 'init-view-count' ),
        'init_plugin_suite_view_count_render_dashboard_widget'
    );
} );

function init_plugin_suite_view_count_render_dashboard_widget() {
    echo do_shortcode( '[init_view_ranking tabs="total,day,week,month,last_month" number="10"]' );
}

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'index.php' ) return;

    $enabled = get_option( 'init_plugin_suite_view_count_enable_widget', 1 );
    if ( ! $enabled ) return;

    wp_enqueue_style(
        'init-plugin-suite-view-count-admin-style',
        INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/css/admin-style.css',
        [],
        INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION
    );
} );
