<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_menu',
	function () {
		add_options_page(
			esc_html__( 'Init View Count Settings', 'init-view-count' ),
			esc_html__( 'Init View Count', 'init-view-count' ),
			'manage_options',
			'init-view-count-settings',
			'init_plugin_suite_view_count_render_settings_page'
		);
	}
);

function init_plugin_suite_view_count_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['init_plugin_suite_view_count_save'] ) ) {
		check_admin_referer( 'init_plugin_suite_view_count_settings_action' );

		$auto_insert_position = isset( $_POST['init_plugin_suite_view_count_auto_insert'] ) ? sanitize_text_field( wp_unslash( $_POST['init_plugin_suite_view_count_auto_insert'] ) ) : 'none';
		$allowed_positions    = array( 'none', 'before_content', 'after_content' );
		if ( ! in_array( $auto_insert_position, $allowed_positions, true ) ) {
			$auto_insert_position = 'none';
		}
		update_option( 'init_plugin_suite_view_count_auto_insert', $auto_insert_position );

		// 0ms/0% là giá trị nhạy cảm (xem giải thích ở khai báo constant trong file chính)
		// nên luôn ép về khoảng an toàn [MIN, MAX] trước khi lưu.
		$delay = isset( $_POST['init_plugin_suite_view_count_delay'] ) ? absint( $_POST['init_plugin_suite_view_count_delay'] ) : 15000;
		$delay = init_plugin_suite_view_count_clamp_int(
			$delay,
			INIT_PLUGIN_SUITE_VIEW_COUNT_DELAY_MIN,
			INIT_PLUGIN_SUITE_VIEW_COUNT_DELAY_MAX
		);

		$scroll_percent = isset( $_POST['init_plugin_suite_view_count_scroll_percent'] ) ? absint( $_POST['init_plugin_suite_view_count_scroll_percent'] ) : 75;
		$scroll_percent = init_plugin_suite_view_count_clamp_int(
			$scroll_percent,
			INIT_PLUGIN_SUITE_VIEW_COUNT_SCROLL_MIN,
			INIT_PLUGIN_SUITE_VIEW_COUNT_SCROLL_MAX
		);
		$scroll_enabled = isset( $_POST['init_plugin_suite_view_count_scroll_enabled'] ) ? 1 : 0;

		$storage = isset( $_POST['init_plugin_suite_view_count_storage'] ) ? sanitize_text_field( wp_unslash( $_POST['init_plugin_suite_view_count_storage'] ) ) : 'session';
		$storage = in_array( $storage, array( 'local', 'session' ), true ) ? $storage : 'session';

		$post_types = isset( $_POST['init_plugin_suite_view_count_post_types'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['init_plugin_suite_view_count_post_types'] ) ) ) ) )
			: array();

		$enable_day   = ! empty( $_POST['init_plugin_suite_view_count_enable_day'] ) ? 1 : 0;
		$enable_week  = ! empty( $_POST['init_plugin_suite_view_count_enable_week'] ) ? 1 : 0;
		$enable_month = ! empty( $_POST['init_plugin_suite_view_count_enable_month'] ) ? 1 : 0;

		update_option( 'init_plugin_suite_view_count_delay', $delay );
		update_option( 'init_plugin_suite_view_count_scroll_percent', $scroll_percent );
		update_option( 'init_plugin_suite_view_count_scroll_enabled', $scroll_enabled );
		update_option( 'init_plugin_suite_view_count_storage', $storage );
		update_option( 'init_plugin_suite_view_count_post_types', $post_types );
		update_option( 'init_plugin_suite_view_count_enable_day', $enable_day );
		update_option( 'init_plugin_suite_view_count_enable_week', $enable_week );
		update_option( 'init_plugin_suite_view_count_enable_month', $enable_month );

		$batch_count = max( 1, absint( $_POST['init_plugin_suite_view_count_batch'] ?? 1 ) );
		update_option( 'init_plugin_suite_view_count_batch', $batch_count );

		$optimize_meta_flush = ! empty( $_POST['init_plugin_suite_view_count_optimize_meta_flush'] ) ? 1 : 0;
		update_option( 'init_plugin_suite_view_count_optimize_meta_flush', $optimize_meta_flush );

		$meta_flush_interval = isset( $_POST['init_plugin_suite_view_count_meta_flush_interval'] )
			? absint( $_POST['init_plugin_suite_view_count_meta_flush_interval'] )
			: INIT_PLUGIN_SUITE_VIEW_COUNT_META_FLUSH_DEFAULT;
		$meta_flush_interval = init_plugin_suite_view_count_clamp_int(
			$meta_flush_interval,
			INIT_PLUGIN_SUITE_VIEW_COUNT_META_FLUSH_MIN,
			INIT_PLUGIN_SUITE_VIEW_COUNT_META_FLUSH_MAX
		);
		update_option( 'init_plugin_suite_view_count_meta_flush_interval', $meta_flush_interval );

		// === Đệm lượt xem trong object cache (v2.0.4+) ===
		$buffer_was_on   = init_plugin_suite_view_count_buffer_option_enabled();
		$buffer_writes   = ! empty( $_POST['init_plugin_suite_view_count_buffer_writes'] ) ? 1 : 0;
		$buffer_rejected = false;

		// Chỉ cho bật khi object cache vượt qua bài tự kiểm tra (add/incr/decr atomic).
		if ( 1 === $buffer_writes && ! $buffer_was_on && ! init_plugin_suite_view_count_buffer_self_test() ) {
			$buffer_writes   = 0;
			$buffer_rejected = true;
		}

		$buffer_interval         = isset( $_POST['init_plugin_suite_view_count_buffer_flush_interval'] )
			? absint( $_POST['init_plugin_suite_view_count_buffer_flush_interval'] )
			: INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_DEFAULT;
		$buffer_interval         = init_plugin_suite_view_count_clamp_int(
			$buffer_interval,
			INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MIN,
			INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MAX
		);
		$buffer_interval_changed = (int) get_option( 'init_plugin_suite_view_count_buffer_flush_interval', INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_DEFAULT ) !== $buffer_interval;

		update_option( 'init_plugin_suite_view_count_buffer_writes', $buffer_writes );
		update_option( 'init_plugin_suite_view_count_buffer_flush_interval', $buffer_interval );

		if ( $buffer_was_on && 0 === $buffer_writes ) {
			// Vừa tắt → ghi ngay phần còn đang đệm vào database.
			init_plugin_suite_view_count_buffer_flush(
				array(
					'wait'       => 10,
					'time_limit' => 60,
					'context'    => 'disable',
				)
			);
		}

		if ( 1 === $buffer_writes && ! $buffer_was_on ) {
			// Mốc bắt đầu cho cơ chế tự phục hồi khi WP-Cron không chạy.
			$buffer_state = init_plugin_suite_view_count_buffer_get_state();
			if ( 0 === $buffer_state['last_flush'] ) {
				$buffer_state['last_flush'] = time();
				init_plugin_suite_view_count_buffer_save_state( $buffer_state );
			}
		}

		$buffer_now_on = ( 1 === $buffer_writes );

		if ( $buffer_interval_changed || $buffer_now_on !== $buffer_was_on ) {
			wp_clear_scheduled_hook( INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_CRON_HOOK );
			init_plugin_suite_view_count_buffer_maybe_schedule();
		}

		$strict_ip_check = ! empty( $_POST['init_plugin_suite_view_count_strict_ip_check'] ) ? 1 : 0;
		update_option( 'init_plugin_suite_view_count_strict_ip_check', $strict_ip_check );

		$require_nonce = ! empty( $_POST['init_plugin_suite_view_count_require_nonce'] ) ? 1 : 0;
		update_option( 'init_plugin_suite_view_count_require_nonce', $require_nonce );

		$dashboard_widget_enabled = ! empty( $_POST['init_plugin_suite_view_count_enable_widget'] ) ? 1 : 0;
		update_option( 'init_plugin_suite_view_count_enable_widget', $dashboard_widget_enabled );

		$disable_style = ! empty( $_POST['init_plugin_suite_view_count_disable_style'] ) ? 1 : 0;
		update_option( 'init_plugin_suite_view_count_disable_style', $disable_style );

		$disable_trending = ! empty( $_POST['init_plugin_suite_view_count_disable_trending'] ) ? 1 : 0;
		update_option( 'init_plugin_suite_view_count_disable_trending', $disable_trending );

		$shape_sampling = ! empty( $_POST['init_plugin_suite_view_count_shape_sampling'] ) ? 1 : 0;
		update_option( 'init_plugin_suite_view_count_shape_sampling', $shape_sampling );

		$shape_sample_rate = isset( $_POST['init_plugin_suite_view_count_shape_sample_rate'] )
			? absint( $_POST['init_plugin_suite_view_count_shape_sample_rate'] )
			: INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_SAMPLE_DEFAULT;
		$shape_sample_rate = init_plugin_suite_view_count_clamp_int(
			$shape_sample_rate,
			INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_SAMPLE_MIN,
			INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_SAMPLE_MAX
		);
		update_option( 'init_plugin_suite_view_count_shape_sample_rate', $shape_sample_rate );

		// Tắt Trending → dọn luôn danh sách trending đã tính, để không còn hiển thị dữ liệu cũ.
		if ( 1 === $disable_trending ) {
			delete_transient( 'init_plugin_suite_view_count_trending' );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'init-view-count' ) . '</p></div>';

		if ( $buffer_rejected ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Buffered view writes could not be enabled: the object cache did not pass the self-test (add, increment and decrement must work and persist). Views are still written to the database directly.', 'init-view-count' ) . '</p></div>';
		}
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chỉ đọc cờ hiển thị thông báo sau redirect, không thay đổi dữ liệu.
	if ( isset( $_GET['shape_reset'] ) && '1' === $_GET['shape_reset'] ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Traffic shape data has been reset.', 'init-view-count' ) . '</p></div>';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chỉ đọc cờ hiển thị thông báo sau redirect, không thay đổi dữ liệu.
	if ( isset( $_GET['buffer_flushed'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Như trên.
		$buffer_flushed = (int) sanitize_text_field( wp_unslash( $_GET['buffer_flushed'] ) );

		if ( $buffer_flushed >= 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s: number of views written to the database */
					__( 'Buffered views written to the database: %s.', 'init-view-count' ),
					number_format_i18n( $buffer_flushed )
				)
			) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Buffered views could not be written right now (no persistent object cache, or another write is already running). Please try again in a moment.', 'init-view-count' ) . '</p></div>';
		}
	}

	$buffer_backend = init_plugin_suite_view_count_buffer_backend_status();

	$selected_post_types = (array) get_option( 'init_plugin_suite_view_count_post_types', array( 'post' ) );
	$all_post_types      = get_post_types( array( 'public' => true ), 'objects' );
	unset( $all_post_types['attachment'] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Init View Count Settings', 'init-view-count' ); ?></h1>
		<form method="post">
			<?php wp_nonce_field( 'init_plugin_suite_view_count_settings_action' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Post types to track views', 'init-view-count' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $all_post_types as $type ) : ?>
								<label>
									<input type="checkbox" name="init_plugin_suite_view_count_post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $selected_post_types, true ) ); ?> />
									<?php echo esc_html( $type->label ); ?>
								</label><br>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-insert shortcode into post content?', 'init-view-count' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="init_plugin_suite_view_count_auto_insert" value="none"
									<?php checked( get_option( 'init_plugin_suite_view_count_auto_insert', 'none' ), 'none' ); ?> />
								<?php esc_html_e( 'Do not auto-insert (manual shortcode only)', 'init-view-count' ); ?>
							</label><br>
							<label>
								<input type="radio" name="init_plugin_suite_view_count_auto_insert" value="before_content"
									<?php checked( get_option( 'init_plugin_suite_view_count_auto_insert', 'none' ), 'before_content' ); ?> />
								<?php esc_html_e( 'Insert before content', 'init-view-count' ); ?>
							</label><br>
							<label>
								<input type="radio" name="init_plugin_suite_view_count_auto_insert" value="after_content"
									<?php checked( get_option( 'init_plugin_suite_view_count_auto_insert', 'none' ), 'after_content' ); ?> />
								<?php esc_html_e( 'Insert after content', 'init-view-count' ); ?>
							</label>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Only applies to post types where view count is enabled. Developers can always insert shortcode manually.', 'init-view-count' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable daily views?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_enable_day" <?php checked( get_option( 'init_plugin_suite_view_count_enable_day', 1 ) ); ?> />
							<?php esc_html_e( 'Count and store views per day', 'init-view-count' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable weekly views?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_enable_week" <?php checked( get_option( 'init_plugin_suite_view_count_enable_week', 1 ) ); ?> />
							<?php esc_html_e( 'Track total views by week', 'init-view-count' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable monthly views?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_enable_month" <?php checked( get_option( 'init_plugin_suite_view_count_enable_month', 1 ) ); ?> />
							<?php esc_html_e( 'Track total views by month', 'init-view-count' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Delay before counting (ms)', 'init-view-count' ); ?></th>
					<td>
						<input type="number" name="init_plugin_suite_view_count_delay"
								value="<?php echo esc_attr( get_option( 'init_plugin_suite_view_count_delay', 15000 ) ); ?>"
								min="<?php echo esc_attr( INIT_PLUGIN_SUITE_VIEW_COUNT_DELAY_MIN ); ?>"
								max="<?php echo esc_attr( INIT_PLUGIN_SUITE_VIEW_COUNT_DELAY_MAX ); ?>"
								step="100" />
						<p class="description">
							<?php
							printf(
								/* translators: 1: minimum delay in ms, 2: maximum delay in ms */
								esc_html__( 'Minimum %1$dms, maximum %2$dms. 0 is not allowed to avoid counting views before the page has actually rendered.', 'init-view-count' ),
								(int) INIT_PLUGIN_SUITE_VIEW_COUNT_DELAY_MIN,
								(int) INIT_PLUGIN_SUITE_VIEW_COUNT_DELAY_MAX
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Scroll percent required', 'init-view-count' ); ?></th>
					<td>
						<input type="number" name="init_plugin_suite_view_count_scroll_percent"
								value="<?php echo esc_attr( get_option( 'init_plugin_suite_view_count_scroll_percent', 75 ) ); ?>"
								min="<?php echo esc_attr( INIT_PLUGIN_SUITE_VIEW_COUNT_SCROLL_MIN ); ?>"
								max="<?php echo esc_attr( INIT_PLUGIN_SUITE_VIEW_COUNT_SCROLL_MAX ); ?>"
								step="1" />
						<p class="description">
							<?php
							printf(
								/* translators: 1: minimum scroll percent, 2: maximum scroll percent */
								esc_html__( 'Minimum %1$d%%, maximum %2$d%%. 0 is not allowed as it would bypass the scroll check entirely.', 'init-view-count' ),
								(int) INIT_PLUGIN_SUITE_VIEW_COUNT_SCROLL_MIN,
								(int) INIT_PLUGIN_SUITE_VIEW_COUNT_SCROLL_MAX
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable scroll check?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_scroll_enabled" <?php checked( get_option( 'init_plugin_suite_view_count_scroll_enabled', 1 ), 1 ); ?> />
							<?php esc_html_e( 'Only count views after user scrolls past required percent', 'init-view-count' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Storage method', 'init-view-count' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="init_plugin_suite_view_count_storage" value="session" <?php checked( get_option( 'init_plugin_suite_view_count_storage', 'session' ), 'session' ); ?> />
								<?php esc_html_e( 'Session Storage', 'init-view-count' ); ?>
							</label><br>
							<label>
								<input type="radio" name="init_plugin_suite_view_count_storage" value="local" <?php checked( get_option( 'init_plugin_suite_view_count_storage', 'session' ), 'local' ); ?> />
								<?php esc_html_e( 'Local Storage', 'init-view-count' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Batch view tracking', 'init-view-count' ); ?></th>
					<td>
						<input type="number" name="init_plugin_suite_view_count_batch"
								value="<?php echo esc_attr( get_option( 'init_plugin_suite_view_count_batch', 1 ) ); ?>"
								min="1" />
						<p class="description">
							<?php esc_html_e( 'Number of views to collect before sending to server. Set to 1 for real-time tracking. Higher values reduce server requests but may miss some views if user leaves early.', 'init-view-count' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reduce view count cache refreshes?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_optimize_meta_flush" <?php checked( get_option( 'init_plugin_suite_view_count_optimize_meta_flush', 0 ) ); ?> />
							<?php esc_html_e( 'Refresh the cached metadata of each post at most once per interval, instead of after every single view.', 'init-view-count' ); ?>
						</label>
						<p>
							<label for="init_plugin_suite_view_count_meta_flush_interval"><?php esc_html_e( 'Refresh interval (seconds)', 'init-view-count' ); ?></label>
							<input type="number" id="init_plugin_suite_view_count_meta_flush_interval" name="init_plugin_suite_view_count_meta_flush_interval"
									class="small-text"
									value="<?php echo esc_attr( init_plugin_suite_view_count_clamp_int( get_option( 'init_plugin_suite_view_count_meta_flush_interval', INIT_PLUGIN_SUITE_VIEW_COUNT_META_FLUSH_DEFAULT ), INIT_PLUGIN_SUITE_VIEW_COUNT_META_FLUSH_MIN, INIT_PLUGIN_SUITE_VIEW_COUNT_META_FLUSH_MAX ) ); ?>"
									min="<?php echo esc_attr( INIT_PLUGIN_SUITE_VIEW_COUNT_META_FLUSH_MIN ); ?>"
									max="<?php echo esc_attr( INIT_PLUGIN_SUITE_VIEW_COUNT_META_FLUSH_MAX ); ?>"
									step="1" />
						</p>
						<p class="description">
							<?php
							printf(
								/* translators: 1: minimum interval in seconds, 2: maximum interval in seconds */
								esc_html__( 'Minimum %1$d seconds, maximum %2$d seconds.', 'init-view-count' ),
								(int) INIT_PLUGIN_SUITE_VIEW_COUNT_META_FLUSH_MIN,
								(int) INIT_PLUGIN_SUITE_VIEW_COUNT_META_FLUSH_MAX
							);
							?>
						</p>
						<p class="description">
							<?php esc_html_e( 'On busy sites, refreshing the cache after every view forces popular posts to reload all of their metadata from the database over and over. With this option on, every view is still written to the database immediately and accurately; only the cached copy is refreshed less often.', 'init-view-count' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Note:', 'init-view-count' ); ?></strong>
							<?php esc_html_e( 'Displayed view counts (shortcodes, blocks, rankings, Trending and the number shown right after a view is counted) may lag behind the real value by up to the interval above. A post that stops receiving views may keep showing a slightly lower number until it gets another view, is updated, or the daily reset runs (when daily views are enabled).', 'init-view-count' ); ?>
						</p>
						<p class="description">
							<?php if ( wp_using_ext_object_cache() ) : ?>
								<?php esc_html_e( 'A persistent object cache is active on this site, so this option takes effect when enabled.', 'init-view-count' ); ?>
							<?php else : ?>
								<strong><?php esc_html_e( 'No persistent object cache detected on this site, so this option currently has no effect.', 'init-view-count' ); ?></strong>
								<?php esc_html_e( 'It only applies when a persistent object cache (Redis, Memcached, etc.) is in use; without one, cached metadata only lives for a single request.', 'init-view-count' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Buffer view writes in the object cache?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_buffer_writes" <?php checked( get_option( 'init_plugin_suite_view_count_buffer_writes', 0 ) ); ?> />
							<?php esc_html_e( 'Collect views in the persistent object cache (Redis, Memcached, etc.) and write them to the database in batches, instead of writing to the database on every view.', 'init-view-count' ); ?>
						</label>
						<p>
							<label for="init_plugin_suite_view_count_buffer_flush_interval"><?php esc_html_e( 'Write interval (seconds)', 'init-view-count' ); ?></label>
							<input type="number" id="init_plugin_suite_view_count_buffer_flush_interval" name="init_plugin_suite_view_count_buffer_flush_interval"
									class="small-text"
									value="<?php echo esc_attr( init_plugin_suite_view_count_clamp_int( get_option( 'init_plugin_suite_view_count_buffer_flush_interval', INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_DEFAULT ), INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MIN, INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MAX ) ); ?>"
									min="<?php echo esc_attr( INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MIN ); ?>"
									max="<?php echo esc_attr( INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MAX ); ?>"
									step="1" />
						</p>
						<p class="description">
							<?php
							printf(
								/* translators: 1: minimum interval in seconds, 2: maximum interval in seconds */
								esc_html__( 'Minimum %1$d seconds, maximum %2$d seconds.', 'init-view-count' ),
								(int) INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MIN,
								(int) INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MAX
							);
							?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Recommended for very busy sites. Each view only increments a counter in the object cache; a background job then writes all collected views to the database once per interval, with a single query per counter type for up to 500 posts. Popular posts no longer lock the same database rows on every view, and their cached metadata is refreshed once per write instead of after every view. Hooks that run after a view is counted keep working exactly as before, once per view.', 'init-view-count' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Note:', 'init-view-count' ); ?></strong>
							<?php esc_html_e( 'View counts read from the database (shortcodes, blocks, rankings, Trending) may lag behind by up to the interval above; the number shown right after a view is counted stays up to date. If the object cache is restarted or flushed, views not yet written (at most about one interval) are lost. Make sure your object cache does not evict keys under memory pressure (for example Redis maxmemory-policy noeviction or volatile-lru), and that WP-Cron runs reliably (a real server cron is recommended).', 'init-view-count' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'With this option on, "Reduce view count cache refreshes" and "Sample Traffic Shape writes" are no longer needed: buffered views refresh the cache once per write, and Traffic Shape data is buffered too, without sampling.', 'init-view-count' ); ?>
						</p>
						<p class="description">
							<?php if ( 'ok' === $buffer_backend ) : ?>
								<?php esc_html_e( 'A persistent object cache is active on this site, so this option takes effect when enabled.', 'init-view-count' ); ?>
							<?php elseif ( 'unavailable' === $buffer_backend ) : ?>
								<strong><?php esc_html_e( 'The persistent object cache is currently unreachable.', 'init-view-count' ); ?></strong>
								<?php esc_html_e( 'Views are written to the database directly until it is back.', 'init-view-count' ); ?>
							<?php elseif ( 'non_atomic' === $buffer_backend ) : ?>
								<strong><?php esc_html_e( 'Your object cache drop-in does not increment counters atomically with its current configuration (Redis Object Cache with igbinary).', 'init-view-count' ); ?></strong>
								<?php esc_html_e( 'Views are written to the database directly. Turn off igbinary in your object cache configuration to use this option.', 'init-view-count' ); ?>
							<?php elseif ( 'non_persistent' === $buffer_backend ) : ?>
								<strong><?php esc_html_e( 'Your object cache is configured not to store this plugin\'s cache group persistently (ignored or non-persistent group).', 'init-view-count' ); ?></strong>
								<?php esc_html_e( 'Views are written to the database directly. Remove the group init_plugin_suite_view_count_buffer from that list to use this option.', 'init-view-count' ); ?>
							<?php else : ?>
								<strong><?php esc_html_e( 'No persistent object cache detected on this site, so this option currently has no effect.', 'init-view-count' ); ?></strong>
								<?php esc_html_e( 'Views are written to the database directly, as before.', 'init-view-count' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable strict IP check?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_strict_ip_check" <?php checked( get_option( 'init_plugin_suite_view_count_strict_ip_check', 0 ) ); ?> />
							<?php esc_html_e( 'Stores a hashed list of recent IPs per post in a transient to prevent repeated views from the same IP within a short time window.', 'init-view-count' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Adds extra protection against bots or fake requests directly posting to the tracking endpoint. Useful if you see unusual traffic not blocked by countdown or scroll check.', 'init-view-count' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Performance note:', 'init-view-count' ); ?></strong>
							<?php esc_html_e( 'This check reads the transient on every view request and writes it back every time a view is counted. Without a persistent object cache (Redis, Memcached, etc.), transients are stored in the wp_options table, so each counted view adds up to two extra database writes. On busy sites without a persistent object cache, only enable it if you really need it.', 'init-view-count' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Require REST nonce verification?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_require_nonce" <?php checked( get_option( 'init_plugin_suite_view_count_require_nonce', 0 ) ); ?> />
							<?php esc_html_e( 'Require a valid WordPress REST nonce (X-WP-Nonce) on every request to the /count endpoint.', 'init-view-count' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Blocks fake POST requests sent directly to the endpoint without loading the page first.', 'init-view-count' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Warning:', 'init-view-count' ); ?></strong>
							<?php esc_html_e( 'A WordPress nonce expires after roughly 12-24 hours. If you use full-page caching with a long lifetime, cached pages will keep serving an old, expired nonce, and view counting will silently stop working on those pages until the cache is refreshed. Leave this off on sites with long-lived page caching, or make sure the cache is purged/refreshed at least once a day.', 'init-view-count' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable dashboard widget?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_enable_widget" <?php checked( get_option( 'init_plugin_suite_view_count_enable_widget', 1 ) ); ?> />
							<?php esc_html_e( 'Display top view ranking inside WordPress Dashboard.', 'init-view-count' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Uncheck to hide the widget for all users.', 'init-view-count' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Disable plugin CSS?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_disable_style" <?php checked( get_option( 'init_plugin_suite_view_count_disable_style', 0 ) ); ?> />
							<?php esc_html_e( 'Disable built-in CSS output.', 'init-view-count' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Check this if you want to fully control the styling yourself. The plugin will not enqueue any CSS.', 'init-view-count' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Disable Trending?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
									name="init_plugin_suite_view_count_disable_trending"
									<?php checked( get_option( 'init_plugin_suite_view_count_disable_trending', 0 ) ); ?> />
							<?php esc_html_e( 'Completely turn off trending calculations and outputs.', 'init-view-count' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Check this to hard-disable trending.', 'init-view-count' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sample Traffic Shape writes?', 'init-view-count' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="init_plugin_suite_view_count_shape_sampling" <?php checked( get_option( 'init_plugin_suite_view_count_shape_sampling', 0 ) ); ?> />
							<?php esc_html_e( 'Record traffic shape data from only 1 in every N view requests (each sample counts N times), instead of on every request.', 'init-view-count' ); ?>
						</label>
						<p>
							<label for="init_plugin_suite_view_count_shape_sample_rate"><?php esc_html_e( 'Sample rate (1 in N requests)', 'init-view-count' ); ?></label>
							<input type="number" id="init_plugin_suite_view_count_shape_sample_rate" name="init_plugin_suite_view_count_shape_sample_rate"
									class="small-text"
									value="<?php echo esc_attr( init_plugin_suite_view_count_clamp_int( get_option( 'init_plugin_suite_view_count_shape_sample_rate', INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_SAMPLE_DEFAULT ), INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_SAMPLE_MIN, INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_SAMPLE_MAX ) ); ?>"
									min="<?php echo esc_attr( INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_SAMPLE_MIN ); ?>"
									max="<?php echo esc_attr( INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_SAMPLE_MAX ); ?>"
									step="1" />
						</p>
						<p class="description">
							<?php
							printf(
								/* translators: 1: minimum sample rate, 2: maximum sample rate */
								esc_html__( 'Minimum %1$d, maximum %2$d.', 'init-view-count' ),
								(int) INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_SAMPLE_MIN,
								(int) INIT_PLUGIN_SUITE_VIEW_COUNT_SHAPE_SAMPLE_MAX
							);
							?>
						</p>
						<p class="description">
							<?php esc_html_e( 'The Traffic Shape Learner (used by Trending) updates a database option on every view request. Sampling cuts these writes by about N times. On busy sites the learned hourly and weekday pattern stays essentially the same, because sampled counts are unbiased and the learner only uses relative proportions smoothed over many days.', 'init-view-count' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Note:', 'init-view-count' ); ?></strong>
							<?php esc_html_e( 'On low-traffic sites the learned pattern becomes noisier, so only enable this if your site receives a lot of views. Post view counts are never sampled and are not affected. Has no effect when Trending is disabled.', 'init-view-count' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'init-view-count' ), 'primary', 'init_plugin_suite_view_count_save' ); ?>
		</form>

		<?php
		$buffer_queue = init_plugin_suite_view_count_buffer_queue_length();
		if ( init_plugin_suite_view_count_buffer_option_enabled() || $buffer_queue > 0 ) :
			$buffer_state = init_plugin_suite_view_count_buffer_get_state();
			?>
			<h2><?php esc_html_e( 'View Buffer', 'init-view-count' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of post/counter pairs waiting to be written */
					esc_html__( 'Counters waiting to be written: %s.', 'init-view-count' ),
					esc_html( number_format_i18n( $buffer_queue ) )
				);
				?>
				<?php if ( $buffer_state['last_flush'] > 0 ) : ?>
					<?php
					printf(
						/* translators: 1: human-readable time difference (e.g. "2 mins"), 2: number of views */
						esc_html__( 'Last write to the database: %1$s ago (%2$s views).', 'init-view-count' ),
						esc_html( human_time_diff( $buffer_state['last_flush'] ) ),
						esc_html( number_format_i18n( $buffer_state['last_views'] ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'No buffered views have been written to the database yet.', 'init-view-count' ); ?>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="init_plugin_suite_view_count_buffer_flush_now" />
				<?php wp_nonce_field( 'init_plugin_suite_view_count_buffer_flush_now' ); ?>
				<?php submit_button( __( 'Write buffered views now', 'init-view-count' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Traffic Shape Learner', 'init-view-count' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Clears the learned hourly and weekday traffic patterns used by the Trending engine. The patterns will be rebuilt automatically from new views.', 'init-view-count' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php echo esc_attr__( 'Reset all learned traffic shape data?', 'init-view-count' ); ?>">
			<input type="hidden" name="action" value="init_plugin_suite_view_count_shape_reset" />
			<?php wp_nonce_field( 'init_plugin_suite_view_count_shape_reset' ); ?>
			<?php submit_button( __( 'Reset traffic shape data', 'init-view-count' ), 'secondary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Shortcode Builder', 'init-view-count' ); ?></h2>
		<div id="shortcode-builder-target" data-plugin="init-view-count"></div>
	</div>
	<?php
}
