<?php
/**
 * Dọn dữ liệu khi xoá plugin (Plugins → Delete).
 *
 * @package Init_View_Count
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Xoá toàn bộ dữ liệu của plugin trên site hiện tại.
 *
 * @return void
 */
function init_plugin_suite_view_count_uninstall_site() {
	global $wpdb;

	// === Options ===
	$options = array(
		'init_plugin_suite_view_count_auto_insert',
		'init_plugin_suite_view_count_delay',
		'init_plugin_suite_view_count_scroll_percent',
		'init_plugin_suite_view_count_scroll_enabled',
		'init_plugin_suite_view_count_storage',
		'init_plugin_suite_view_count_post_types',
		'init_plugin_suite_view_count_enable_day',
		'init_plugin_suite_view_count_enable_week',
		'init_plugin_suite_view_count_enable_month',
		'init_plugin_suite_view_count_batch',
		'init_plugin_suite_view_count_strict_ip_check',
		'init_plugin_suite_view_count_require_nonce',
		'init_plugin_suite_view_count_enable_widget',
		'init_plugin_suite_view_count_disable_style',
		'init_plugin_suite_view_count_disable_trending',
		'init_plugin_suite_view_count_trending_state',
		'init_plugin_suite_view_count_shape_hour_ema',
		'init_plugin_suite_view_count_shape_wday_ema',
		'init_plugin_suite_view_count_shape_today_bins',
		'init_plugin_suite_view_count_shape_yesterday_pending',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// === Post meta ===
	$meta_keys = array(
		'_init_view_count',
		'_init_view_day_count',
		'_init_view_week_count',
		'_init_view_month_count',
		'_init_view_day_yesterday',
		'_init_view_week_last',
		'_init_view_month_last',
	);

	foreach ( $meta_keys as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	// === Transients cố định ===
	$transients = array(
		'init_plugin_suite_view_count_trending',
		'init_plugin_suite_view_count_trending_debug',
		'init_plugin_suite_view_count_site_traffic_shape',
		'init_plugin_suite_view_count_site_traffic_shape_learned',
		'trending_last_calculation',
		'hot_topics_24h',
	);

	foreach ( $transients as $transient ) {
		delete_transient( $transient );
	}

	// === Transients động (cache /top, danh sách IP gần đây) ===
	// Không xoá theo tiền tố chung chung như 'last_score_'/'top_streak_' (bản cũ dùng) vì có thể
	// trùng tên transient của plugin khác; các transient đó đều có hạn và sẽ tự hết hạn.
	// Transient có thời hạn KHÔNG nằm trong alloptions (autoload = no), nên phải tìm bằng LIKE
	// (bản cũ duyệt wp_load_alloptions() nên không bao giờ xoá được các cache này).
	$prefixes = array(
		'init_plugin_suite_view_count_top_',
		'ivc_recent_ips_',
	);

	foreach ( $prefixes as $prefix ) {
		foreach ( array( '_transient_', '_transient_timeout_' ) as $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dọn dẹp 1 lần khi gỡ plugin, cache được xoá ngay sau đó.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $type . $prefix ) . '%'
				)
			);
		}
	}

	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	// === Cron ===
	wp_clear_scheduled_hook( 'init_plugin_suite_view_count_reset_counts' );
	wp_clear_scheduled_hook( 'init_plugin_suite_view_count_cron_update_trending' );
}

if ( is_multisite() ) {
	$init_view_count_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $init_view_count_site_ids as $init_view_count_site_id ) {
		switch_to_blog( (int) $init_view_count_site_id );
		init_plugin_suite_view_count_uninstall_site();
		restore_current_blog();
	}
} else {
	init_plugin_suite_view_count_uninstall_site();
}
