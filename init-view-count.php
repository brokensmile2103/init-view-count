<?php
/**
 * Plugin Name: Init View Count
 * Description: Lightweight plugin to track real post views with scroll & delay detection, smart ranking, and flexible shortcodes.
 * Plugin URI: https://inithtml.com/plugin/init-view-count/
 * Version: 2.0.2
 * Author: Init HTML
 * Author URI: https://inithtml.com/
 * Text Domain: init-view-count
 * Domain Path: /languages
 * Requires at least: 6.9
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

// === Constants ===
define( 'INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION', '2.0.2' );
define( 'INIT_PLUGIN_SUITE_VIEW_COUNT_SLUG', 'init-view-count' );
define( 'INIT_PLUGIN_SUITE_VIEW_COUNT_DIR', plugin_dir_path( __FILE__ ) );
define( 'INIT_PLUGIN_SUITE_VIEW_COUNT_URL', plugin_dir_url( __FILE__ ) );
define( 'INIT_PLUGIN_SUITE_VIEW_COUNT_NAMESPACE', 'initvico/v1' );

// Giới hạn an toàn cho "delay before counting" và "scroll percent required".
// 0ms/0% là giá trị nhạy cảm (đếm view gần như ngay lập tức, dễ dính bot/prefetch,
// hoặc vô hiệu hoá luôn tính năng scroll-check) nên luôn ép về giá trị tối thiểu hợp lý.
define( 'INIT_PLUGIN_SUITE_VIEW_COUNT_DELAY_MIN', 100 );      // 100ms
define( 'INIT_PLUGIN_SUITE_VIEW_COUNT_DELAY_MAX', 600000 );   // 10 phút
define( 'INIT_PLUGIN_SUITE_VIEW_COUNT_SCROLL_MIN', 1 );       // 1%
define( 'INIT_PLUGIN_SUITE_VIEW_COUNT_SCROLL_MAX', 100 );     // 100%

// === Include core files ===
// utils.php cần require TRƯỚC TIÊN vì rest-api.php, settings-page.php,
// shortcodes.php, abilities-api.php... đều gọi trực tiếp các hàm helper trong đó.
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/utils.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/rest-api.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/traffic-shape.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/reset-schedule.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/shortcodes.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/hooks.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/settings-page.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/abilities-api.php';
require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/blocks.php';

if ( is_admin() ) {
	require_once INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'includes/dashboard-widget.php';
}

// === Enqueue CSS & JS ===
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! get_option( 'init_plugin_suite_view_count_disable_style' ) ) {
			wp_enqueue_style(
				'init-plugin-suite-view-count-style',
				INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/css/style.css',
				array(),
				INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION
			);
		}

		if ( ! is_singular() ) {
			return;
		}

		// Dùng ID của bài đang được xem (queried object) thay vì get_the_ID(): nếu theme/plugin
		// chạy 1 WP_Query phụ trước wp_head mà quên reset, get_the_ID() trả về bài khác và
		// view sẽ bị đếm nhầm sang bài đó.
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id ) {
			$post_id = (int) get_the_ID();
		}
		if ( ! $post_id ) {
			return;
		}

		$post_type     = get_post_type( $post_id );
		$allowed_types = (array) get_option( 'init_plugin_suite_view_count_post_types', array( 'post' ) );

		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			return;
		}

		wp_enqueue_script(
			'init-plugin-suite-view-count-script',
			INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/js/script.js',
			array(),
			INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'post_id'       => $post_id,
			'restUrl'       => esc_url_raw( rest_url( INIT_PLUGIN_SUITE_VIEW_COUNT_NAMESPACE ) ),
			// Clamp lại ở đây (không chỉ lúc save) để phòng trường hợp option trong DB
			// đang mang giá trị cũ (VD: 0) từ trước khi có giới hạn này, hoặc bị sửa
			// trực tiếp qua WP-CLI/DB thay vì qua trang Settings.
			'delay'         => init_plugin_suite_view_count_clamp_int(
				get_option( 'init_plugin_suite_view_count_delay', 15000 ),
				INIT_PLUGIN_SUITE_VIEW_COUNT_DELAY_MIN,
				INIT_PLUGIN_SUITE_VIEW_COUNT_DELAY_MAX
			),
			'scrollPercent' => init_plugin_suite_view_count_clamp_int(
				get_option( 'init_plugin_suite_view_count_scroll_percent', 75 ),
				INIT_PLUGIN_SUITE_VIEW_COUNT_SCROLL_MIN,
				INIT_PLUGIN_SUITE_VIEW_COUNT_SCROLL_MAX
			),
			'scrollEnabled' => ( (int) get_option( 'init_plugin_suite_view_count_scroll_enabled', 1 ) === 1 ),
			'storage'       => get_option( 'init_plugin_suite_view_count_storage', 'session' ),
			'batch'         => max( 1, (int) get_option( 'init_plugin_suite_view_count_batch', 1 ) ),
		);

		// Chỉ in nonce ra khi admin bật "Require REST nonce verification?" trong Settings.
		// Mặc định TẮT vì nonce (wp_create_nonce('wp_rest')) hết hạn sau ~12-24h; nếu site
		// dùng full-page cache thời gian sống dài, HTML cache cũ sẽ mang theo nonce đã hết hạn
		// và request tới /count sẽ bị từ chối cho đến khi cache được làm mới.
		if ( (int) get_option( 'init_plugin_suite_view_count_require_nonce', 0 ) === 1 ) {
			$config['nonce'] = wp_create_nonce( 'wp_rest' );
		}

		$config = apply_filters( 'init_plugin_suite_view_count_localize_config', $config, $post_id );

		wp_localize_script( 'init-plugin-suite-view-count-script', 'InitViewCountSettings', $config );
	}
);

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'init_plugin_suite_view_count_add_settings_link' );

/**
 * Add a "Settings" link to the plugin row in the Plugins admin screen.
 *
 * @param array $links Action links.
 * @return array
 */
function init_plugin_suite_view_count_add_settings_link( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=init-view-count-settings' ) ) . '">' . esc_html__( 'Settings', 'init-view-count' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

register_deactivation_hook( __FILE__, 'init_plugin_suite_view_count_deactivate' );

/**
 * Gỡ các cron event khi tắt plugin (bản cũ để lại trong cron array). Khi bật lại,
 * hook 'init' trong reset-schedule.php sẽ tự lên lịch lại như lần đầu.
 *
 * @return void
 */
function init_plugin_suite_view_count_deactivate() {
	wp_clear_scheduled_hook( 'init_plugin_suite_view_count_reset_counts' );
	wp_clear_scheduled_hook( 'init_plugin_suite_view_count_cron_update_trending' );
}
