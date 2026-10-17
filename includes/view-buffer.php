<?php
/**
 * Bộ đệm lượt xem trong Object Cache (Redis, Memcached...) — chế độ "write-behind".
 *
 * Mặc định TẮT. Khi bật (Settings → Init View Count → "Buffer view writes in the object
 * cache?") và site có persistent object cache hoạt động bình thường:
 *
 * - Mỗi lượt xem KHÔNG ghi vào database nữa, chỉ cộng dồn vào 1 bộ đếm trong object cache
 *   (wp_cache_incr — atomic trên Redis/Memcached). Không còn khoá dòng (row lock) trên
 *   wp_postmeta, không còn xoá cache post meta sau mỗi view.
 * - Một WP-Cron job chạy mỗi khoảng thời gian (mặc định 60 giây) gom toàn bộ số đã cộng dồn
 *   và ghi vào database theo lô: mỗi meta key chỉ 1 câu UPDATE cho tối đa 500 post, rồi xoá
 *   cache post meta đúng 1 lần cho các post vừa ghi.
 * - Cron reset hàng ngày, tắt plugin, tắt chế độ này... đều ghi hết phần đang chờ trước.
 *
 * Mọi hook đi sau việc đếm view ('init_plugin_suite_view_count_after_counted'...) vẫn chạy
 * cho TỪNG lượt xem như cũ, với cùng tham số; số 'total'/'day'/'week'/'month' trả về là
 * "giá trị trong DB + phần đang chờ ghi", nên vẫn tăng đều sau mỗi lượt xem.
 *
 * Thiết kế chỉ dùng API object cache chuẩn của WordPress (add, incr, decr, set, get, delete)
 * để chạy được với mọi drop-in có incr/decr atomic:
 *
 * - Bộ đếm: 1 key cho mỗi cặp (post, meta key) trong mỗi "thế hệ" 1 giờ. Thế hệ cũ không
 *   bao giờ được cộng thêm nên có thể để key tự hết hạn (TTL 2 ngày) mà không mất view —
 *   bộ nhớ object cache luôn bị chặn trên, không phình theo thời gian.
 * - Hàng đợi: lần đầu 1 cặp có view trong thế hệ (wp_cache_add() marker thành công), cặp đó
 *   được đẩy vào hàng đợi bằng 1 số thứ tự tăng dần (wp_cache_incr). Job flush đọc lần lượt
 *   các mục theo số thứ tự (con trỏ đã xử lý cũng nằm trong object cache).
 * - Khi flush: xoá marker TRƯỚC, đọc bộ đếm, rồi wp_cache_decr() đúng bằng số vừa đọc (KHÔNG
 *   set về 0). View đến giữa chừng vẫn nằm lại trong bộ đếm và tự đăng ký lại hàng đợi → không
 *   mất, không đếm trùng. Nếu ghi DB lỗi, phần đó được trả lại bộ đệm cho lần sau.
 *
 * @package Init_View_Count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP     = 'init_plugin_suite_view_count_buffer';
const INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_CRON_HOOK = 'init_plugin_suite_view_count_buffer_flush';
const INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_SCHEDULE  = 'init_plugin_suite_view_count_buffer';

// Độ dài 1 "thế hệ" bộ đếm (giây) và thời gian sống của key bộ đếm trong object cache.
const INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GENERATION = HOUR_IN_SECONDS;
const INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_KEY_TTL    = 2 * DAY_IN_SECONDS;

// Số mục hàng đợi xử lý trong mỗi lượt đọc (1 lần get_multiple + 1 lần ghi DB/meta key).
const INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_CHUNK = 500;

/*
 * ==========================================================================
 * 1) TRẠNG THÁI & ĐIỀU KIỆN BẬT
 * ==========================================================================
 */

/**
 * Admin có bật tuỳ chọn "Buffer view writes in the object cache?" hay không
 * (chỉ đọc option, chưa xét object cache).
 *
 * @return bool
 */
function init_plugin_suite_view_count_buffer_option_enabled() {
	return 1 === (int) get_option( 'init_plugin_suite_view_count_buffer_writes', 0 );
}

/**
 * Tình trạng object cache đối với chế độ bộ đệm.
 *
 * - 'ok'              : dùng được.
 * - 'no_object_cache' : site không có persistent object cache.
 * - 'unavailable'     : có drop-in nhưng hiện không kết nối được (VD: Redis đang down —
 *                       drop-in Redis Object Cache khi đó lặng lẽ chỉ lưu trong bộ nhớ của
 *                       request, nếu vẫn đệm thì view sẽ mất).
 * - 'non_atomic'      : drop-in đang cấu hình theo kiểu incr/decr KHÔNG atomic (Redis Object
 *                       Cache với igbinary: GET rồi SET) → nhiều request song song sẽ ghi đè
 *                       lên nhau và mất view.
 * - 'non_persistent'  : group của plugin bị cấu hình là "không lưu bền" (ignored/non-persistent
 *                       group) → dữ liệu chỉ sống trong 1 request, đệm vào là mất.
 *
 * @return string
 */
function init_plugin_suite_view_count_buffer_backend_status() {
	global $wp_object_cache;

	if ( ! wp_using_ext_object_cache() ) {
		$status = 'no_object_cache';
	} else {
		$status = 'ok';

		// Group bị cấu hình "không lưu bền": Redis Object Cache ($ignored_groups, hằng
		// WP_REDIS_IGNORED_GROUPS) và drop-in Memcached phổ biến ($no_mc_groups).
		foreach ( array( 'ignored_groups', 'no_mc_groups' ) as $property ) {
			if ( is_object( $wp_object_cache ) && isset( $wp_object_cache->$property ) && is_array( $wp_object_cache->$property )
				&& in_array( INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP, $wp_object_cache->$property, true ) ) {
				$status = 'non_persistent';
			}
		}

		// Drop-in "Redis Object Cache" (rhubarbgroup/redis-cache).
		if ( 'ok' === $status && is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'redis_status' ) ) {
			if ( ! $wp_object_cache->redis_status() ) {
				$status = 'unavailable';
			} elseif ( defined( 'WP_REDIS_IGBINARY' ) && WP_REDIS_IGBINARY ) {
				$status = 'non_atomic';
			}
		}
	}

	/**
	 * Filter tình trạng object cache đối với chế độ bộ đệm lượt xem.
	 *
	 * Trả về 'ok' để cho phép đệm, bất kỳ giá trị nào khác để ghi thẳng vào database như
	 * các bản trước. Dùng khi drop-in của bạn có cách riêng để báo đang mất kết nối.
	 *
	 * @param string $status 'ok', 'no_object_cache', 'unavailable', 'non_atomic' hoặc 'non_persistent'.
	 */
	return (string) apply_filters( 'init_plugin_suite_view_count_buffer_backend_status', $status );
}

/**
 * Chế độ bộ đệm có đang thực sự hoạt động trong request này không: admin đã bật VÀ
 * object cache đang dùng được. Không đủ điều kiện → mọi lượt xem ghi thẳng DB như cũ.
 *
 * @return bool
 */
function init_plugin_suite_view_count_buffer_enabled() {
	$enabled = init_plugin_suite_view_count_buffer_option_enabled()
		&& 'ok' === init_plugin_suite_view_count_buffer_backend_status();

	/**
	 * Filter bật/tắt chế độ đệm lượt xem trong object cache cho request hiện tại.
	 *
	 * Chỉ có thể TẮT khi điều kiện đã đủ; không thể ép bật khi site không có persistent
	 * object cache hoạt động.
	 *
	 * @param bool $enabled Chế độ đệm có đang bật hay không.
	 */
	return $enabled && (bool) apply_filters( 'init_plugin_suite_view_count_buffer_enabled', true );
}

/**
 * Khoảng thời gian (giây) giữa 2 lần ghi phần đang chờ vào database.
 *
 * @return int
 */
function init_plugin_suite_view_count_get_buffer_flush_interval() {
	$interval = init_plugin_suite_view_count_clamp_int(
		get_option( 'init_plugin_suite_view_count_buffer_flush_interval', INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_DEFAULT ),
		INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MIN,
		INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MAX
	);

	/**
	 * Filter khoảng thời gian (giây) giữa 2 lần ghi lượt xem đang đệm vào database.
	 *
	 * @param int $interval Số giây, đã được ép trong khoảng [MIN, MAX] của Settings.
	 */
	$interval = (int) apply_filters( 'init_plugin_suite_view_count_buffer_flush_interval', $interval );

	return max( INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_FLUSH_MIN, $interval );
}

/**
 * Trạng thái job flush, lưu trong chính object cache (key 'state', không thời hạn).
 *
 * KHÔNG lưu trong wp_options: trạng thái chỉ có ý nghĩa đi cùng hàng đợi (cùng nằm trong
 * object cache — mất cùng mất, còn cùng còn), và việc đọc 1 option không autoload từ nhiều
 * request song song dễ dính race của cache 'notoptions' trong WordPress, khiến
 * update_option() âm thầm thất bại và con trỏ không bao giờ tiến lên.
 *
 * - cursor     : số thứ tự mục hàng đợi cuối cùng đã xử lý.
 * - last_seq   : số thứ tự lớn nhất đã thấy ở lần flush trước (để nhận biết mục bị thiếu
 *                là "đang ghi dở" hay đã thật sự mất).
 * - last_flush : thời điểm (Unix) của lần flush gần nhất.
 * - last_views : số lượt xem đã ghi vào DB ở lần flush gần nhất.
 * - last_items : số mục hàng đợi đã xử lý ở lần flush gần nhất.
 *
 * @param bool $force True để bỏ qua bản sao trong bộ nhớ của request hiện tại.
 * @return array
 */
function init_plugin_suite_view_count_buffer_get_state( $force = true ) {
	$state = wp_using_ext_object_cache() ? wp_cache_get( 'state', INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP, $force ) : false;
	$state = is_array( $state ) ? $state : array();

	return array(
		'cursor'     => isset( $state['cursor'] ) ? max( 0, (int) $state['cursor'] ) : 0,
		'last_seq'   => isset( $state['last_seq'] ) ? max( 0, (int) $state['last_seq'] ) : 0,
		'last_flush' => isset( $state['last_flush'] ) ? (int) $state['last_flush'] : 0,
		'last_views' => isset( $state['last_views'] ) ? (int) $state['last_views'] : 0,
		'last_items' => isset( $state['last_items'] ) ? (int) $state['last_items'] : 0,
	);
}

/**
 * Lưu trạng thái job flush (chỉ job flush — đang giữ khoá — và trang Settings ghi).
 *
 * @param array $state Trạng thái (xem init_plugin_suite_view_count_buffer_get_state()).
 * @return void
 */
function init_plugin_suite_view_count_buffer_save_state( array $state ) {
	wp_cache_set( 'state', $state, INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP );
}

/**
 * Số mục đang chờ trong hàng đợi (mỗi mục là 1 cặp post + meta key có view chưa ghi DB).
 * Chỉ dùng để hiển thị trong Settings.
 *
 * @return int
 */
function init_plugin_suite_view_count_buffer_queue_length() {
	if ( ! wp_using_ext_object_cache() ) {
		return 0;
	}

	$seq   = (int) wp_cache_get( 'seq', INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP, true );
	$state = init_plugin_suite_view_count_buffer_get_state();

	return $seq > $state['cursor'] ? $seq - $state['cursor'] : 0;
}

/*
 * ==========================================================================
 * 2) GHI VÀO BỘ ĐỆM
 * ==========================================================================
 */

/**
 * "Thế hệ" của bộ đếm tại 1 thời điểm (mỗi thế hệ dài 1 giờ).
 *
 * @param int|null $timestamp Unix timestamp, mặc định là hiện tại.
 * @return int
 */
function init_plugin_suite_view_count_buffer_generation( $timestamp = null ) {
	$timestamp = null === $timestamp ? time() : (int) $timestamp;
	return (int) floor( $timestamp / INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GENERATION );
}

/**
 * Tên key trong object cache cho 1 cặp (post, meta key) thuộc 1 thế hệ.
 * Meta key được băm md5 để key luôn ngắn và an toàn với mọi backend (Memcached giới hạn
 * 250 ký tự, không cho phép khoảng trắng).
 *
 * @param string $type       'v' (bộ đếm) hoặc 'm' (marker đã vào hàng đợi).
 * @param int    $generation Thế hệ.
 * @param int    $post_id    Post ID.
 * @param string $meta_key   Meta key.
 * @return string
 */
function init_plugin_suite_view_count_buffer_key( $type, $generation, $post_id, $meta_key ) {
	return $type . '_' . (int) $generation . '_' . (int) $post_id . '_' . md5( (string) $meta_key );
}

/**
 * Cộng dồn atomic vào 1 key, đảm bảo key luôn có thời hạn.
 *
 * Dùng wp_cache_add() (chỉ thành công khi key CHƯA tồn tại) tạo key = 0 kèm TTL trước, rồi
 * mới wp_cache_incr(). Gọi incr thẳng lên key chưa tồn tại thì tuỳ drop-in: có cái trả về false,
 * có cái (Redis) tự tạo key nhưng KHÔNG có thời hạn → bộ nhớ phình mãi.
 *
 * @param string $key    Key.
 * @param int    $offset Số cần cộng (>= 1).
 * @param int    $ttl    Thời hạn (giây) nếu key được tạo mới.
 * @return int|false Giá trị mới, hoặc false nếu object cache lỗi.
 */
function init_plugin_suite_view_count_buffer_incr( $key, $offset, $ttl ) {
	wp_cache_add( $key, 0, INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP, $ttl );

	$value = wp_cache_incr( $key, $offset, INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP );

	return false === $value ? false : (int) $value;
}

/**
 * Đưa 1 cặp (post, meta key) của thế hệ hiện tại vào hàng đợi chờ flush, nếu chưa có.
 *
 * @param int    $post_id    Post ID.
 * @param string $meta_key   Meta key.
 * @param int    $generation Thế hệ.
 * @return bool True nếu cặp này đã/đang nằm trong hàng đợi; false nếu object cache lỗi.
 */
function init_plugin_suite_view_count_buffer_register( $post_id, $meta_key, $generation ) {
	$group  = INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP;
	$marker = init_plugin_suite_view_count_buffer_key( 'm', $generation, $post_id, $meta_key );

	// Marker có hạn ngắn: nếu vì lý do nào đó mục hàng đợi bị mất (Redis bị evict key...),
	// marker hết hạn thì lượt xem kế tiếp sẽ tự đăng ký lại → phần đang chờ không bị kẹt.
	$marker_ttl = max( 10 * MINUTE_IN_SECONDS, 10 * init_plugin_suite_view_count_get_buffer_flush_interval() );

	if ( ! wp_cache_add( $marker, 1, $group, $marker_ttl ) ) {
		return true; // Đã nằm trong hàng đợi.
	}

	// 'seq' không có thời hạn: là số thứ tự tăng dần, mất thì job flush tự nhận biết.
	$seq = wp_cache_incr( 'seq', 1, $group );
	if ( false === $seq ) {
		wp_cache_add( 'seq', 0, $group );
		$seq = wp_cache_incr( 'seq', 1, $group );
	}

	if ( false === $seq || ! wp_cache_set( 'q_' . (int) $seq, array( (int) $post_id, (string) $meta_key, (int) $generation ), $group, DAY_IN_SECONDS ) ) {
		wp_cache_delete( $marker, $group );
		return false;
	}

	return true;
}

/**
 * Cộng dồn lượt xem vào bộ đệm cho 1 cặp (post, meta key).
 *
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key (đã qua filter 'init_plugin_suite_view_count_meta_key').
 * @param int    $offset   Số lượt cần cộng (mặc định 1).
 * @return int|false Số lượt đang chờ ghi DB của cặp này trong thế hệ hiện tại (đã gồm lượt
 *                   vừa cộng), hoặc false nếu object cache lỗi — khi đó KHÔNG có gì được cộng,
 *                   bên gọi cần tự ghi thẳng vào DB.
 */
function init_plugin_suite_view_count_buffer_add( $post_id, $meta_key, $offset = 1 ) {
	$post_id  = (int) $post_id;
	$meta_key = (string) $meta_key;
	$offset   = (int) $offset;

	if ( $post_id <= 0 || '' === $meta_key || $offset <= 0 ) {
		return false;
	}

	$generation = init_plugin_suite_view_count_buffer_generation();
	$key        = init_plugin_suite_view_count_buffer_key( 'v', $generation, $post_id, $meta_key );

	// Thứ tự quan trọng: cộng bộ đếm TRƯỚC, đăng ký hàng đợi SAU. Nếu đảo lại, job flush
	// có thể xử lý mục hàng đợi (đọc bộ đếm = 0) ngay trước khi bộ đếm được cộng → kẹt.
	$pending = init_plugin_suite_view_count_buffer_incr( $key, $offset, INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_KEY_TTL );
	if ( false === $pending ) {
		return false;
	}

	if ( ! init_plugin_suite_view_count_buffer_register( $post_id, $meta_key, $generation ) ) {
		// Hoàn lại phần vừa cộng để bên gọi ghi thẳng DB mà không bị đếm 2 lần.
		wp_cache_decr( $key, $offset, INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP );
		return false;
	}

	return $pending;
}

/**
 * Số lượt xem của 1 cặp (post, meta key) đang nằm trong bộ đệm, chưa được ghi vào DB
 * (thế hệ hiện tại + thế hệ trước). Trả về 0 khi chế độ bộ đệm không dùng được.
 *
 * Dùng khi cần hiển thị con số "sống" chính xác hơn get_post_meta():
 * get_post_meta() + init_plugin_suite_view_count_get_pending_views().
 *
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key (mặc định: tổng lượt xem).
 * @return int
 */
function init_plugin_suite_view_count_get_pending_views( $post_id, $meta_key = '_init_view_count' ) {
	$post_id = (int) $post_id;

	if ( $post_id <= 0 || 'ok' !== init_plugin_suite_view_count_buffer_backend_status() ) {
		return 0;
	}

	$current = init_plugin_suite_view_count_buffer_generation();
	$keys    = array(
		init_plugin_suite_view_count_buffer_key( 'v', $current, $post_id, $meta_key ),
		init_plugin_suite_view_count_buffer_key( 'v', $current - 1, $post_id, $meta_key ),
	);

	$values = wp_cache_get_multiple( $keys, INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP );
	$total  = 0;

	foreach ( (array) $values as $value ) {
		$total += max( 0, (int) $value );
	}

	return $total;
}

/**
 * Cộng +1 lượt xem cho NHIỀU meta key của 1 post — điểm vào chung cho mọi luồng đếm view.
 *
 * - Chế độ bộ đệm đang hoạt động → cộng vào object cache, không chạm DB.
 * - Ngược lại (hoặc object cache lỗi giữa chừng với 1 key nào đó) → ghi thẳng DB bằng
 *   init_plugin_suite_view_count_atomic_increment_many() + làm mới cache post meta (có giới
 *   hạn tần suất theo tuỳ chọn "Reduce view count cache refreshes"), y hệt các bản trước.
 *
 * Theme/plugin khác muốn cộng view cho 1 post "đi kèm" (VD: chương → truyện) nên gọi hàm
 * này thay vì tự UPDATE, để được hưởng chế độ bộ đệm khi admin bật.
 *
 * @param int      $post_id   Post ID.
 * @param string[] $meta_keys Danh sách meta key cần +1 (đã qua filter meta_key).
 * @return array {
 *     @type bool  $buffered True nếu ít nhất 1 key được cộng vào bộ đệm.
 *     @type int[] $pending  Map meta_key => số lượt đang chờ ghi DB (đã gồm lượt này), chỉ có
 *                           với các key được đệm. Key KHÔNG có trong map đã được ghi thẳng DB.
 * }
 */
function init_plugin_suite_view_count_increment_meta( $post_id, array $meta_keys ) {
	$post_id   = (int) $post_id;
	$meta_keys = array_values( array_unique( array_filter( array_map( 'strval', $meta_keys ), 'strlen' ) ) );
	$result    = array(
		'buffered' => false,
		'pending'  => array(),
	);

	if ( $post_id <= 0 || empty( $meta_keys ) ) {
		return $result;
	}

	$direct = $meta_keys;

	if ( init_plugin_suite_view_count_buffer_enabled() ) {
		$direct = array();

		foreach ( $meta_keys as $meta_key ) {
			$pending = init_plugin_suite_view_count_buffer_add( $post_id, $meta_key );

			if ( false === $pending ) {
				$direct[] = $meta_key;
				continue;
			}

			$result['pending'][ $meta_key ] = $pending;
		}

		$result['buffered'] = ! empty( $result['pending'] );

		if ( $result['buffered'] ) {
			init_plugin_suite_view_count_buffer_maybe_self_heal();
		}
	}

	if ( ! empty( $direct ) ) {
		init_plugin_suite_view_count_atomic_increment_many( $post_id, $direct );
		init_plugin_suite_view_count_maybe_flush_meta_cache( $post_id );
	}

	return $result;
}

/*
 * ==========================================================================
 * 3) GHI PHẦN ĐANG CHỜ VÀO DATABASE (FLUSH)
 * ==========================================================================
 */

/**
 * Giữ khoá flush (chỉ 1 tiến trình flush tại 1 thời điểm).
 *
 * @param int $wait Số giây tối đa chờ nếu khoá đang bị giữ.
 * @return bool
 */
function init_plugin_suite_view_count_buffer_acquire_lock( $wait = 0 ) {
	$wait = max( 0, (int) $wait );

	do {
		if ( wp_cache_add( 'lock', time(), INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP, 5 * MINUTE_IN_SECONDS ) ) {
			return true;
		}

		if ( $wait <= 0 ) {
			return false;
		}

		sleep( 1 );
		--$wait;
	} while ( true );
}

/**
 * Nhả khoá flush.
 *
 * @return void
 */
function init_plugin_suite_view_count_buffer_release_lock() {
	wp_cache_delete( 'lock', INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP );
}

/**
 * Lấy ra (và trừ khỏi bộ đệm) phần đang chờ của các mục hàng đợi trong khoảng [$from, $to].
 *
 * @param int $from     Số thứ tự bắt đầu.
 * @param int $to       Số thứ tự kết thúc.
 * @param int $last_seq Số thứ tự lớn nhất đã thấy ở lần flush trước.
 * @return array {
 *     @type int   $cursor     Số thứ tự cuối cùng đã xử lý liên tục.
 *     @type bool  $blocked    True nếu dừng lại ở 1 mục có thể đang được ghi dở.
 *     @type int   $items      Số mục hợp lệ đã xử lý.
 *     @type array $increments Map meta_key => [ post_id => số lượt ].
 * }
 */
function init_plugin_suite_view_count_buffer_drain_range( $from, $to, $last_seq ) {
	$group  = INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP;
	$result = array(
		'cursor'     => $from - 1,
		'blocked'    => false,
		'items'      => 0,
		'increments' => array(),
	);

	$queue_keys = array();
	for ( $i = $from; $i <= $to; $i++ ) {
		$queue_keys[ $i ] = 'q_' . $i;
	}

	$items = (array) wp_cache_get_multiple( array_values( $queue_keys ), $group, true );
	$pairs = array();
	$done  = array();

	foreach ( $queue_keys as $i => $queue_key ) {
		$item = isset( $items[ $queue_key ] ) ? $items[ $queue_key ] : false;

		$valid = is_array( $item )
			&& 3 === count( $item )
			&& (int) $item[0] > 0
			&& is_string( $item[1] )
			&& '' !== $item[1];

		if ( ! $valid ) {
			// Mục thiếu có số thứ tự MỚI hơn lần flush trước → có thể request đếm view vừa
			// tăng 'seq' nhưng chưa kịp ghi mục → dừng ở đây, lần sau xử lý tiếp.
			// Mục thiếu đã CŨ (đã tồn tại từ lần flush trước) → coi như đã mất, bỏ qua; phần
			// đang chờ của nó vẫn còn trong bộ đếm và sẽ được đăng ký lại khi marker hết hạn.
			if ( $i > $last_seq ) {
				$result['blocked'] = true;
				break;
			}

			$result['cursor'] = $i;
			continue;
		}

		$pair_hash = init_plugin_suite_view_count_buffer_key( 'v', (int) $item[2], (int) $item[0], $item[1] );

		$pairs[ $pair_hash ] = array(
			'post_id'    => (int) $item[0],
			'key'        => $item[1],
			'generation' => (int) $item[2],
		);

		$done[]           = $queue_key;
		$result['cursor'] = $i;
		++$result['items'];
	}

	if ( empty( $pairs ) ) {
		return $result;
	}

	// Xoá marker TRƯỚC khi đọc bộ đếm: view đến sau thời điểm này sẽ tự vào hàng đợi lại,
	// nên phần cộng thêm sau lúc đọc không bao giờ bị bỏ sót.
	foreach ( $pairs as $pair ) {
		wp_cache_delete(
			init_plugin_suite_view_count_buffer_key( 'm', $pair['generation'], $pair['post_id'], $pair['key'] ),
			$group
		);
	}

	$values = (array) wp_cache_get_multiple( array_keys( $pairs ), $group, true );

	foreach ( $pairs as $counter_key => $pair ) {
		$value = isset( $values[ $counter_key ] ) ? (int) $values[ $counter_key ] : 0;

		if ( $value <= 0 ) {
			continue;
		}

		// Trừ đúng phần vừa đọc (KHÔNG set về 0) để giữ lại view đến giữa chừng.
		if ( false === wp_cache_decr( $counter_key, $value, $group ) ) {
			continue; // Không trừ được thì không ghi DB, để lần sau xử lý → không đếm trùng.
		}

		if ( ! isset( $result['increments'][ $pair['key'] ][ $pair['post_id'] ] ) ) {
			$result['increments'][ $pair['key'] ][ $pair['post_id'] ] = 0;
		}

		$result['increments'][ $pair['key'] ][ $pair['post_id'] ] += $value;
	}

	wp_cache_delete_multiple( $done, $group );

	return $result;
}

/**
 * Ghi 1 lô số lượt xem vào wp_postmeta: mỗi meta key 1 câu UPDATE cho tối đa
 * INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_CHUNK post; post chưa có row thì tạo mới.
 *
 * @param array $increments Map meta_key => [ post_id => số lượt ].
 * @return array {
 *     @type int[] $touched Post ID đã được ghi (cần làm mới cache post meta).
 *     @type int   $views   Tổng số lượt đã ghi thành công.
 *     @type array $failed  Map meta_key => [ post_id => số lượt ] ghi lỗi.
 * }
 */
function init_plugin_suite_view_count_apply_increments( array $increments ) {
	global $wpdb;

	$result = array(
		'touched' => array(),
		'views'   => 0,
		'failed'  => array(),
	);

	foreach ( $increments as $meta_key => $per_post ) {
		$meta_key = (string) $meta_key;
		$per_post = array_filter( array_map( 'intval', (array) $per_post ) );

		if ( '' === $meta_key || empty( $per_post ) ) {
			continue;
		}

		foreach ( array_chunk( $per_post, INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_CHUNK, true ) as $chunk ) {
			$ids = array_map( 'intval', array_keys( $chunk ) );
			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// $in chỉ chứa chuỗi '%d' lặp lại, mọi giá trị thật đều đi qua prepare().
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$existing = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($in)",
					array_merge( array( $meta_key ), $ids )
				)
			);

			if ( '' !== $wpdb->last_error ) {
				$result['failed'][ $meta_key ] = isset( $result['failed'][ $meta_key ] ) ? $result['failed'][ $meta_key ] + $chunk : $chunk;
				continue;
			}

			$existing = array_flip( array_map( 'intval', (array) $existing ) );
			$update   = array_intersect_key( $chunk, $existing );
			$missing  = array_diff_key( $chunk, $existing );

			if ( ! empty( $update ) ) {
				$cases = '';
				$args  = array();

				foreach ( $update as $post_id => $views ) {
					$cases .= ' WHEN %d THEN %d';
					$args[] = (int) $post_id;
					$args[] = (int) $views;
				}

				$in_update = implode( ',', array_fill( 0, count( $update ), '%d' ) );
				$args[]    = $meta_key;
				$args      = array_merge( $args, array_map( 'intval', array_keys( $update ) ) );

				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + (CASE post_id{$cases} ELSE 0 END) WHERE meta_key = %s AND post_id IN ($in_update)",
						$args
					)
				);

				if ( false === $updated ) {
					$result['failed'][ $meta_key ] = isset( $result['failed'][ $meta_key ] ) ? $result['failed'][ $meta_key ] + $update : $update;
				} else {
					$result['views']  += array_sum( $update );
					$result['touched'] = array_merge( $result['touched'], array_keys( $update ) );
				}
			}

			// Lượt xem đầu tiên của kỳ: tạo row (có khoá, chống tạo trùng với luồng ghi thẳng
			// đang chạy song song), hoặc cộng dồn nếu row vừa được tạo ở nơi khác.
			foreach ( $missing as $post_id => $views ) {
				init_plugin_suite_view_count_insert_or_increment( (int) $post_id, $meta_key, (int) $views );

				$result['views']    += (int) $views;
				$result['touched'][] = (int) $post_id;
			}
			// phpcs:enable
		}
	}

	$result['touched'] = array_values( array_unique( array_map( 'intval', $result['touched'] ) ) );

	return $result;
}

/**
 * Ghi toàn bộ lượt xem đang đệm trong object cache vào database.
 *
 * An toàn khi gọi bất cứ lúc nào (kể cả khi chế độ bộ đệm đã tắt — để ghi nốt phần còn
 * sót): không có persistent object cache, hoặc tiến trình flush khác đang chạy, thì trả về
 * false ngay.
 *
 * @param array $args {
 *     Tuỳ chọn.
 *
 *     @type int    $wait       Số giây tối đa chờ nếu tiến trình flush khác đang chạy. Mặc định 0.
 *     @type int    $time_limit Số giây tối đa cho lần flush này. Mặc định 20 (filterable).
 *     @type string $context    Ngữ cảnh gọi ('cron', 'reset', 'manual', 'disable', 'deactivate',
 *                              'self_heal'...), truyền qua action sau khi flush.
 * }
 * @return array|false Tóm tắt kết quả, hoặc false nếu không chạy.
 */
function init_plugin_suite_view_count_buffer_flush( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'wait'       => 0,
			'time_limit' => null,
			'context'    => 'cron',
		)
	);

	if ( ! wp_using_ext_object_cache() || 'unavailable' === init_plugin_suite_view_count_buffer_backend_status() ) {
		return false;
	}

	if ( ! init_plugin_suite_view_count_buffer_acquire_lock( (int) $args['wait'] ) ) {
		return false;
	}

	$group   = INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP;
	$started = microtime( true );

	if ( null === $args['time_limit'] ) {
		/**
		 * Filter thời gian tối đa (giây) cho mỗi lần ghi bộ đệm vào database. Phần chưa kịp
		 * ghi sẽ được ghi ở lần kế tiếp.
		 *
		 * @param int    $time_limit Số giây (mặc định 20).
		 * @param string $context    Ngữ cảnh gọi.
		 */
		$args['time_limit'] = apply_filters( 'init_plugin_suite_view_count_buffer_flush_time_limit', 20, $args['context'] );
	}

	$time_limit = max( 1, min( 240, (int) $args['time_limit'] ) );

	$state = init_plugin_suite_view_count_buffer_get_state();
	$seq   = (int) wp_cache_get( 'seq', $group, true );

	// 'seq' nhỏ hơn con trỏ → object cache vừa bị xoá/khởi động lại → đếm lại từ đầu.
	if ( $seq < $state['cursor'] ) {
		$state['cursor']   = 0;
		$state['last_seq'] = 0;
	}

	$summary = array(
		'context'    => (string) $args['context'],
		'items'      => 0,
		'views'      => 0,
		'post_ids'   => array(),
		'increments' => array(),
		'requeued'   => 0,
		'remaining'  => 0,
		'duration'   => 0.0,
	);

	while ( $state['cursor'] < $seq && ( microtime( true ) - $started ) < $time_limit ) {
		$from    = $state['cursor'] + 1;
		$to      = min( $seq, $state['cursor'] + INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_CHUNK );
		$drained = init_plugin_suite_view_count_buffer_drain_range( $from, $to, $state['last_seq'] );

		$summary['items'] += $drained['items'];

		if ( ! empty( $drained['increments'] ) ) {
			$written = init_plugin_suite_view_count_apply_increments( $drained['increments'] );

			init_plugin_suite_view_count_flush_meta_cache_many( $written['touched'] );

			$summary['views']   += $written['views'];
			$summary['post_ids'] = array_merge( $summary['post_ids'], $written['touched'] );

			foreach ( $drained['increments'] as $meta_key => $per_post ) {
				foreach ( $per_post as $post_id => $views ) {
					if ( isset( $written['failed'][ $meta_key ][ $post_id ] ) ) {
						continue;
					}
					if ( ! isset( $summary['increments'][ $meta_key ][ $post_id ] ) ) {
						$summary['increments'][ $meta_key ][ $post_id ] = 0;
					}
					$summary['increments'][ $meta_key ][ $post_id ] += $views;
				}
			}

			// Ghi DB lỗi → trả lại bộ đệm (thế hệ hiện tại) để lần sau ghi lại, không mất view.
			foreach ( $written['failed'] as $meta_key => $per_post ) {
				foreach ( $per_post as $post_id => $views ) {
					if ( false === init_plugin_suite_view_count_buffer_add( $post_id, $meta_key, $views ) ) {
						init_plugin_suite_view_count_insert_or_increment( $post_id, $meta_key, $views );
						init_plugin_suite_view_count_flush_meta_cache( $post_id );
					}
					$summary['requeued'] += (int) $views;
				}
			}
		}

		$state['cursor'] = $drained['cursor'];
		init_plugin_suite_view_count_buffer_save_state( $state );

		if ( $drained['blocked'] ) {
			break;
		}
	}

	// Traffic Shape (biểu đồ traffic theo giờ của Trending) cũng được đệm khi bật chế độ này.
	init_plugin_suite_view_count_shape_flush_buffer();

	$summary['post_ids']  = array_values( array_unique( array_map( 'intval', $summary['post_ids'] ) ) );
	$summary['remaining'] = max( 0, $seq - $state['cursor'] );
	$summary['duration']  = round( microtime( true ) - $started, 3 );

	$state['last_seq']   = $seq;
	$state['last_flush'] = time();
	$state['last_views'] = $summary['views'];
	$state['last_items'] = $summary['items'];
	init_plugin_suite_view_count_buffer_save_state( $state );

	init_plugin_suite_view_count_buffer_release_lock();

	/**
	 * Chạy sau mỗi lần ghi lượt xem đang đệm vào database.
	 *
	 * @param array $summary {
	 *     @type string $context    Ngữ cảnh gọi ('cron', 'reset', 'manual', 'disable', 'deactivate', 'self_heal').
	 *     @type int    $items      Số mục hàng đợi đã xử lý.
	 *     @type int    $views      Tổng số lượt đã ghi vào database.
	 *     @type int[]  $post_ids   Post ID vừa được ghi (cache post meta đã được làm mới).
	 *     @type array  $increments Map meta_key => [ post_id => số lượt vừa ghi ].
	 *     @type int    $requeued   Số lượt ghi lỗi, đã được trả lại bộ đệm.
	 *     @type int    $remaining  Số mục hàng đợi còn lại cho lần sau.
	 *     @type float  $duration   Thời gian chạy (giây).
	 * }
	 */
	do_action( 'init_plugin_suite_view_count_buffer_flushed', $summary );

	return $summary;
}

/*
 * ==========================================================================
 * 4) LỊCH CHẠY (WP-CRON) & TỰ PHỤC HỒI
 * ==========================================================================
 */

add_filter( 'cron_schedules', 'init_plugin_suite_view_count_buffer_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Khoảng thời gian do admin chọn (tối thiểu 30 giây), chỉ đăng ký khi bật chế độ bộ đệm.

/**
 * Đăng ký lịch chạy riêng cho job flush (khoảng thời gian lấy từ Settings).
 *
 * @param array $schedules Danh sách lịch.
 * @return array
 */
function init_plugin_suite_view_count_buffer_cron_schedules( $schedules ) {
	$schedules[ INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_SCHEDULE ] = array(
		'interval' => init_plugin_suite_view_count_get_buffer_flush_interval(),
		'display'  => __( 'Init View Count: write buffered views', 'init-view-count' ),
	);

	return $schedules;
}

add_action( INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_CRON_HOOK, 'init_plugin_suite_view_count_buffer_cron_flush' );

/**
 * Callback của WP-Cron.
 *
 * @return void
 */
function init_plugin_suite_view_count_buffer_cron_flush() {
	init_plugin_suite_view_count_buffer_flush( array( 'context' => 'cron' ) );
}

add_action( 'init', 'init_plugin_suite_view_count_buffer_maybe_schedule' );

/**
 * Lên lịch job flush khi chế độ bộ đệm hoạt động; gỡ lịch khi admin đã tắt tuỳ chọn.
 *
 * Khi object cache tạm thời mất kết nối, lịch vẫn được giữ nguyên để phần đang chờ được
 * ghi tiếp ngay khi kết nối trở lại.
 *
 * @return void
 */
function init_plugin_suite_view_count_buffer_maybe_schedule() {
	$next = wp_next_scheduled( INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_CRON_HOOK );

	if ( ! init_plugin_suite_view_count_buffer_option_enabled() ) {
		if ( $next ) {
			wp_clear_scheduled_hook( INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_CRON_HOOK );
		}
		return;
	}

	if ( ! $next && init_plugin_suite_view_count_buffer_enabled() ) {
		wp_schedule_event(
			time() + init_plugin_suite_view_count_get_buffer_flush_interval(),
			INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_SCHEDULE,
			INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_CRON_HOOK
		);
	}
}

/**
 * Tự phục hồi khi WP-Cron không chạy (DISABLE_WP_CRON mà quên cấu hình cron thật, cron bị
 * kẹt...): nếu lần flush gần nhất đã quá lâu, request đếm view hiện tại sẽ tự flush lúc
 * shutdown. Tối đa 1 request/phút được làm việc này.
 *
 * @return void
 */
function init_plugin_suite_view_count_buffer_maybe_self_heal() {
	static $checked = false;

	if ( $checked ) {
		return;
	}
	$checked = true;

	$state   = init_plugin_suite_view_count_buffer_get_state( false );
	$overdue = max( 5 * MINUTE_IN_SECONDS, 3 * init_plugin_suite_view_count_get_buffer_flush_interval() );

	if ( ( time() - $state['last_flush'] ) < $overdue ) {
		return;
	}

	if ( ! wp_cache_add( 'self_heal', 1, INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP, MINUTE_IN_SECONDS ) ) {
		return;
	}

	add_action( 'shutdown', 'init_plugin_suite_view_count_buffer_self_heal_flush', 30 );
}

/**
 * Flush lúc shutdown (xem init_plugin_suite_view_count_buffer_maybe_self_heal()).
 *
 * @return void
 */
function init_plugin_suite_view_count_buffer_self_heal_flush() {
	init_plugin_suite_view_count_buffer_flush( array( 'context' => 'self_heal' ) );
}

/*
 * ==========================================================================
 * 5) KIỂM TRA OBJECT CACHE & THAO TÁC QUẢN TRỊ
 * ==========================================================================
 */

/**
 * Tự kiểm tra object cache trước khi bật chế độ bộ đệm: add/incr/decr/get/delete phải
 * hoạt động đúng trên chính group của plugin.
 *
 * @return bool
 */
function init_plugin_suite_view_count_buffer_self_test() {
	if ( 'ok' !== init_plugin_suite_view_count_buffer_backend_status() ) {
		return false;
	}

	$group = INIT_PLUGIN_SUITE_VIEW_COUNT_BUFFER_GROUP;
	$key   = 'probe_' . wp_generate_password( 12, false );

	$ok = wp_cache_add( $key, 0, $group, MINUTE_IN_SECONDS )
		&& 3 === (int) wp_cache_incr( $key, 3, $group )
		&& 3 === (int) wp_cache_get( $key, $group, true )
		&& 1 === (int) wp_cache_decr( $key, 2, $group )
		&& 1 === (int) wp_cache_get( $key, $group, true );

	wp_cache_delete( $key, $group );

	return $ok;
}

add_action( 'admin_post_init_plugin_suite_view_count_buffer_flush_now', 'init_plugin_suite_view_count_buffer_flush_now_handler' );

/**
 * Nút "Write buffered views now" trong Settings.
 *
 * @return void
 */
function init_plugin_suite_view_count_buffer_flush_now_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( 'Sorry, you are not allowed to do that.', 'init-view-count' ),
			'',
			array( 'response' => 403 )
		);
	}

	check_admin_referer( 'init_plugin_suite_view_count_buffer_flush_now' );

	$summary = init_plugin_suite_view_count_buffer_flush(
		array(
			'wait'       => 10,
			'time_limit' => 60,
			'context'    => 'manual',
		)
	);

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'           => 'init-view-count-settings',
				'buffer_flushed' => is_array( $summary ) ? (int) $summary['views'] : -1,
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}
