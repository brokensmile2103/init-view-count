<?php
/**
 * Shortcodes: [init_view_list], [init_view_count], [init_view_ranking] + Shortcode Builder (admin).
 *
 * @package Init_View_Count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode(
	'init_view_list',
	function ( $atts ) {
		$atts = shortcode_atts(
			array(
				'number'    => 10,
				'post_type' => 'post',
				'template'  => 'sidebar',
				'title'     => __( 'Popular Posts', 'init-view-count' ),
				'class'     => '',
				'orderby'   => 'meta_value_num',
				'order'     => 'DESC',
				'range'     => 'total',
				'category'  => '',
				'tag'       => '',
				'empty'     => '',
				'page'      => 1,
			),
			$atts,
			'init_view_list'
		);

		$meta_key_map = array(
			'day'      => '_init_view_day_count',
			'week'     => '_init_view_week_count',
			'month'    => '_init_view_month_count',
			'trending' => '_init_view_count',
		);

		$raw_meta_key = isset( $meta_key_map[ $atts['range'] ] ) ? $meta_key_map[ $atts['range'] ] : '_init_view_count';
		$meta_key     = apply_filters( 'init_plugin_suite_view_count_meta_key', $raw_meta_key, null );

		$number = absint( $atts['number'] );

		$query_args = array(
			'post_type'           => $atts['post_type'],
			'posts_per_page'      => $number,
			'offset'              => ( max( 1, absint( $atts['page'] ) ) - 1 ) * $number,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'            => $meta_key,
			'orderby'             => $atts['orderby'],
			'order'               => $atts['order'],
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'          => array(
				array(
					'key'     => $meta_key,
					'compare' => 'EXISTS',
				),
			),
		);

		if ( ! empty( $atts['category'] ) ) {
			$query_args['category_name'] = sanitize_title( $atts['category'] );
		}
		if ( ! empty( $atts['tag'] ) ) {
			$query_args['tag'] = sanitize_title( $atts['tag'] );
		}

		$query_args = apply_filters( 'init_plugin_suite_view_count_query_args', $query_args, $atts );
		$atts       = apply_filters( 'init_plugin_suite_view_count_view_list_atts', $atts );

		$query = new WP_Query( $query_args );
		if ( ! $query->have_posts() ) {
			$empty_output = apply_filters( 'init_plugin_suite_view_count_empty_output', $atts['empty'], $atts );
			return $empty_output ? '<p class="init-plugin-suite-view-count-empty">' . esc_html( $empty_output ) . '</p>' : '';
		}

		$template_file = 'view-list-' . sanitize_file_name( $atts['template'] ) . '.php';
		$template      = locate_template( "init-view-count/$template_file" );
		if ( ! $template ) {
			$template = INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'templates/' . $template_file;
		}
		if ( ! file_exists( $template ) ) {
			$template = INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'templates/view-list-sidebar.php';
		}

		// Nạp sẵn ảnh đại diện của cả danh sách trong 1 lượt (template nào cũng dùng thumbnail).
		update_post_thumbnail_cache( $query );

		$list_class = 'init-plugin-suite-view-count-list' . ( 'grid' === $atts['template'] ? ' grid' : '' );

		ob_start();
		?>
		<div class="init-plugin-suite-view-count-list-wrapper <?php echo esc_attr( $atts['class'] ); ?>">
			<?php if ( ! empty( $atts['title'] ) ) : ?>
				<h3 class="init-plugin-suite-view-count-title"><?php echo esc_html( $atts['title'] ); ?></h3>
			<?php endif; ?>
			<div class="<?php echo esc_attr( $list_class ); ?>">
				<?php
				foreach ( $query->posts as $item ) {
					setup_postdata( $item );
					$real_key                           = apply_filters( 'init_plugin_suite_view_count_meta_key', $meta_key, $item->ID );
					$item->init_plugin_suite_view_count = (int) get_post_meta( $item->ID, $real_key, true );
					init_plugin_suite_view_count_render_template( $template, array( 'item' => $item ) );
				}
				?>
			</div>
		</div>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}
);

/**
 * Include 1 template view-list với biến $item.
 *
 * Không dùng extract($vars) (WPCS: WordPress.PHP.DontExtract) — chỉ có đúng 1 key cố định 'item'.
 *
 * @param string $path Đường dẫn template.
 * @param array  $vars Biến truyền vào template.
 * @return void
 */
function init_plugin_suite_view_count_render_template( $path, $vars = array() ) {
	if ( ! file_exists( $path ) ) {
		return;
	}
	$item = $vars['item'] ?? null;
	include $path;
}

/**
 * Chuẩn hoá chuỗi class (có thể gồm nhiều class cách nhau bởi khoảng trắng).
 *
 * Bản cũ gọi sanitize_html_class() trên cả chuỗi → "foo bar" bị dính thành "foobar".
 *
 * @param string $classes Chuỗi class thô.
 * @return string
 */
function init_plugin_suite_view_count_sanitize_class_list( $classes ) {
	$list = preg_split( '/\s+/', trim( (string) $classes ) );
	$list = array_filter( array_map( 'sanitize_html_class', (array) $list ) );
	return implode( ' ', array_unique( $list ) );
}

/**
 * Gọi trực tiếp callback của 1 shortcode đã đăng ký với mảng thuộc tính, thay cho việc
 * tự ghép chuỗi "[tag attr="..."]" rồi do_shortcode(). Ghép chuỗi làm hỏng giá trị có
 * chứa dấu ngoặc vuông hoặc dấu nháy (VD tiêu đề "Top [2026]" hay 'Bài "hot"').
 *
 * Vẫn tôn trọng việc theme/plugin khác thay thế shortcode (remove_shortcode/add_shortcode)
 * và 2 filter chuẩn pre_do_shortcode_tag / do_shortcode_tag của core.
 *
 * @param string $tag  Tên shortcode.
 * @param array  $atts Thuộc tính.
 * @return string
 */
function init_plugin_suite_view_count_do_shortcode( $tag, array $atts ) {
	global $shortcode_tags;

	if ( empty( $shortcode_tags[ $tag ] ) || ! is_callable( $shortcode_tags[ $tag ] ) ) {
		return '';
	}

	$atts = array_map( 'strval', $atts );

	// Mảng $m mô phỏng kết quả regex của do_shortcode_tag() cho các filter của core.
	$m = array( '', '', $tag, '', '', '', '' );

	$return = apply_filters( 'pre_do_shortcode_tag', false, $tag, $atts, $m );
	if ( false !== $return ) {
		return (string) $return;
	}

	$output = call_user_func( $shortcode_tags[ $tag ], $atts, null, $tag );

	return (string) apply_filters( 'do_shortcode_tag', $output, $tag, $atts, $m );
}

add_shortcode(
	'init_view_count',
	function ( $atts ) {
		global $post;

		$atts = shortcode_atts(
			array(
				'id'     => '',          // ID bài viết cần đếm (tùy chọn).
				'field'  => 'total',     // Loại đếm: total, day, week, month.
				'format' => 'formatted', // Kiểu hiển thị: formatted, short, raw.
				'time'   => 'false',     // Có hiển thị thời gian đăng không.
				'icon'   => 'false',     // Có hiển thị icon con mắt không.
				'schema' => 'false',     // Có chèn schema tương tác không.
				'class'  => '',          // Thêm class tùy ý.
			),
			$atts,
			'init_view_count'
		);

		// Xác định ID: nếu có truyền qua thì dùng, không thì lấy bài hiện tại.
		$id = absint( $atts['id'] );
		if ( ! $id && $post instanceof WP_Post ) {
			$id = (int) $post->ID;
		}
		if ( ! $id ) {
			return '';
		}

		$meta_key_map = array(
			'day'   => '_init_view_day_count',
			'week'  => '_init_view_week_count',
			'month' => '_init_view_month_count',
		);
		$raw_meta_key = isset( $meta_key_map[ $atts['field'] ] ) ? $meta_key_map[ $atts['field'] ] : '_init_view_count';
		$meta_key     = apply_filters( 'init_plugin_suite_view_count_meta_key', $raw_meta_key, $id );

		$views = (int) get_post_meta( $id, $meta_key, true );

		switch ( $atts['format'] ) {
			case 'raw':
				$view_text = (string) $views;
				break;
			case 'short':
				$view_text = init_plugin_suite_view_count_format_thousands( $views );
				break;
			default:
				$view_text = number_format_i18n( $views );
				break;
		}

		$wrapper_classes = array( 'init-plugin-suite-view-count-views' );
		$extra_classes   = init_plugin_suite_view_count_sanitize_class_list( $atts['class'] );
		if ( '' !== $extra_classes ) {
			$wrapper_classes[] = $extra_classes;
		}

		$output = '<span class="' . esc_attr( implode( ' ', $wrapper_classes ) ) . '">';

		if ( 'true' === $atts['icon'] ) {
			$output .= '<span class="init-plugin-suite-view-count-icon" aria-hidden="true">';
			$output .= '<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><circle fill="none" stroke="currentColor" cx="10" cy="10" r="3.45"></circle><path fill="none" stroke="currentColor" d="m19.5,10c-2.4,3.66-5.26,7-9.5,7h0,0,0c-4.24,0-7.1-3.34-9.49-7C2.89,6.34,5.75,3,9.99,3h0,0,0c4.25,0,7.11,3.34,9.5,7Z"></path></svg>';
			$output .= '</span>';
		}

		$output .= '<span class="init-plugin-suite-view-count-number" data-view="' . esc_attr( $views ) . '" data-id="' . esc_attr( $id ) . '">';
		$output .= esc_html( $view_text ) . '</span>';

		// Hiển thị thời gian đăng. So sánh 2 Unix timestamp thật (bản cũ lấy GMT trừ
		// timestamp "local" → site GMT+7 hiện "Posted 7 hours ago" cho bài vừa đăng).
		if ( 'true' === $atts['time'] ) {
			$published = get_post_time( 'U', true, $id );
			if ( $published ) {
				$diff = human_time_diff( (int) $published, time() );
				/* translators: %s is a human-readable time difference like "3 days" */
				$output .= ' &middot; ' . esc_html( sprintf( __( 'Posted %s ago', 'init-view-count' ), $diff ) );
			}
		}

		if ( 'true' === $atts['schema'] ) {
			$output .= '<meta itemprop="interactionStatistic" itemscope itemtype="https://schema.org/InteractionCounter">';
			$output .= '<meta itemprop="interactionType" content="https://schema.org/ViewAction" />';
			$output .= '<meta itemprop="userInteractionCount" content="' . esc_attr( $views ) . '" />';
		}

		return $output . '</span>';
	}
);

add_shortcode(
	'init_view_ranking',
	function ( $atts ) {
		$atts = shortcode_atts(
			array(
				'tabs'      => 'total,day,week,month',
				'number'    => 5,
				'class'     => '',
				'post_type' => '',
			),
			$atts,
			'init_view_ranking'
		);

		$atts['post_type'] = implode( ',', array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['post_type'] ) ) ) );

		wp_enqueue_script(
			'init-plugin-suite-view-count-ranking',
			INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/js/ranking.js',
			array(),
			INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Mỗi lần gọi wp_localize_script() với cùng handle sẽ NỐI THÊM 1 khai báo biến mới
		// vào trang → xoá dữ liệu cũ trước để trang có nhiều khối ranking không bị lặp.
		// (Post type của từng khối nay được truyền riêng qua data-post-type trên wrapper.)
		wp_scripts()->add_data( 'init-plugin-suite-view-count-ranking', 'data', '' );
		wp_localize_script(
			'init-plugin-suite-view-count-ranking',
			'InitViewRankingI18n',
			array(
				'noData'     => __( 'No data found.', 'init-view-count' ),
				'loadError'  => __( 'Failed to load data.', 'init-view-count' ),
				'viewsLabel' => __( 'views', 'init-view-count' ),
				'postType'   => $atts['post_type'],
				// URL REST chuẩn của site (đúng cả khi WP cài trong thư mục con hoặc tắt pretty permalink).
				'restUrl'    => esc_url_raw( rest_url( INIT_PLUGIN_SUITE_VIEW_COUNT_NAMESPACE ) ),
			)
		);

		$tabs = array_filter( array_map( 'trim', explode( ',', $atts['tabs'] ) ) );
		if ( empty( $tabs ) ) {
			return '';
		}

		$labels = array(
			'total'      => __( 'All Time', 'init-view-count' ),
			'day'        => __( 'Today', 'init-view-count' ),
			'week'       => __( 'This Week', 'init-view-count' ),
			'month'      => __( 'This Month', 'init-view-count' ),
			'yesterday'  => __( 'Yesterday', 'init-view-count' ),
			'last_week'  => __( 'Last Week', 'init-view-count' ),
			'last_month' => __( 'Last Month', 'init-view-count' ),
		);

		// Template override.
		$template = locate_template( 'init-view-count/ranking.php' );
		if ( ! $template ) {
			$template = INIT_PLUGIN_SUITE_VIEW_COUNT_DIR . 'templates/ranking.php';
		}
		if ( ! file_exists( $template ) ) {
			return '';
		}

		ob_start();
		include $template;
		return ob_get_clean();
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'settings_page_init-view-count-settings' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'init-view-count-shortcode-builder',
			INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/js/init-shortcode-builder.js',
			array(),
			INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION,
			true
		);

		wp_localize_script(
			'init-view-count-shortcode-builder',
			'InitViewCountShortcodeBuilder',
			array(
				'i18n' => array(
					'copy'              => __( 'Copy', 'init-view-count' ),
					'copied'            => __( 'Copied!', 'init-view-count' ),
					'close'             => __( 'Close', 'init-view-count' ),
					'shortcode_preview' => __( 'Shortcode Preview', 'init-view-count' ),
					'shortcode_builder' => __( 'Shortcode Builder', 'init-view-count' ),
					'init_view_count'   => __( 'Init View Count', 'init-view-count' ),
					'init_view_list'    => __( 'Init View List', 'init-view-count' ),
					'init_view_ranking' => __( 'Init View Ranking', 'init-view-count' ),
					'type'              => __( 'Type', 'init-view-count' ),
					'title'             => __( 'Title', 'init-view-count' ),
					'title_default'     => __( 'Popular Posts', 'init-view-count' ),
					'number'            => __( 'Number of Posts', 'init-view-count' ),
					'template'          => __( 'Template', 'init-view-count' ),
					'range'             => __( 'View Range', 'init-view-count' ),
					'post_type'         => __( 'Post Type', 'init-view-count' ),
					'post_id'           => __( 'Post ID (optional)', 'init-view-count' ),
					'category'          => __( 'Category', 'init-view-count' ),
					'tag'               => __( 'Tag', 'init-view-count' ),
					'orderby'           => __( 'Order By', 'init-view-count' ),
					'order'             => __( 'Order Direction', 'init-view-count' ),
					'field'             => __( 'Field', 'init-view-count' ),
					'format'            => __( 'Format', 'init-view-count' ),
					'time'              => __( 'Show Time Diff', 'init-view-count' ),
					'tabs'              => __( 'Tabs', 'init-view-count' ),
					'icon'              => __( 'Show Icon', 'init-view-count' ),
					'schema'            => __( 'Enable Schema.org', 'init-view-count' ),
					'class'             => __( 'Custom Class', 'init-view-count' ),
				),
			)
		);

		wp_enqueue_script(
			'init-view-count-admin-shortcode-panel',
			INIT_PLUGIN_SUITE_VIEW_COUNT_URL . 'assets/js/shortcodes.js',
			array( 'init-view-count-shortcode-builder' ),
			INIT_PLUGIN_SUITE_VIEW_COUNT_VERSION,
			true
		);
	}
);
