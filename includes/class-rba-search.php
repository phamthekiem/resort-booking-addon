<?php
/**
 * RBA_Search
 *
 * Nâng cấp search Tourfic:
 *  - Tích hợp availability realtime vào search results
 *  - Lọc theo loại phòng, hướng nhìn, số người, giá theo ngày chọn
 *  - Shortcode search nâng cao [rba_search]
 *  - AJAX search với kết quả realtime
 */
defined( 'ABSPATH' ) || exit;

class RBA_Search {

    public function __construct() {
        // Hook vào Tourfic search query
        add_filter( 'tf_hotel_search_query_args',  [ $this, 'inject_availability_filter' ], 20, 2 );
        add_filter( 'tf_room_search_results',       [ $this, 'filter_unavailable_rooms' ], 20, 2 );

        // Shortcode search form nâng cao
        add_shortcode( 'rba_search',               [ $this, 'shortcode_search_form' ] );
        add_shortcode( 'rba_available_rooms',      [ $this, 'shortcode_available_rooms' ] );

        // AJAX search
        add_action( 'wp_ajax_rba_search_rooms',        [ $this, 'ajax_search_rooms' ] );
        add_action( 'wp_ajax_nopriv_rba_search_rooms', [ $this, 'ajax_search_rooms' ] );

        // Enqueue assets
        add_action( 'wp_enqueue_scripts',         [ $this, 'enqueue' ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // CORE SEARCH
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Lọc phòng không còn trống khỏi kết quả search.
     */
    public function filter_unavailable_rooms( array $rooms, array $args ): array {
        $check_in  = $args['check_in']  ?? '';
        $check_out = $args['check_out'] ?? '';
        if ( ! $check_in || ! $check_out ) return $rooms;

        return array_filter( $rooms, function( $room ) use ( $check_in, $check_out ) {
            $room_id   = is_object($room) ? $room->ID : (int)$room;
            $available = RBA_Database::get_available_rooms( $room_id, $check_in, $check_out );
            return $available > 0;
        } );
    }

    /**
     * Thêm meta query vào WP_Query của Tourfic search (nếu dùng WP_Query trực tiếp).
     */
    public function inject_availability_filter( array $query_args, array $search_params ): array {
        // Lấy danh sách room IDs có phòng trống
        if ( ! empty($search_params['check_in']) && ! empty($search_params['check_out']) ) {
            $available_ids = $this->get_available_room_ids(
                $search_params['check_in'],
                $search_params['check_out'],
                (int) ($search_params['adults'] ?? 1)
            );

            if ( ! empty($available_ids) ) {
                $query_args['post__in'] = array_intersect(
                    $query_args['post__in'] ?? $available_ids,
                    $available_ids
                );
            } else {
                // Không có phòng nào trống → trả rỗng
                $query_args['post__in'] = [-1];
            }
        }
        return $query_args;
    }

    /**
     * Lấy danh sách room_ids còn trống.
     */
    public static function get_available_room_ids( string $check_in, string $check_out, int $adults = 1, ?int $hotel_id = null ): array {
        global $wpdb;

        // Rooms từ bảng availability
        $available_rooms = $wpdb->get_col( $wpdb->prepare(
            "SELECT room_id
             FROM {$wpdb->prefix}rba_availability
             WHERE avail_date >= %s AND avail_date < %s
               AND blocked = 0
             GROUP BY room_id
             HAVING MIN(total_rooms - booked_rooms) >= 1",
            $check_in, $check_out
        ) );

        if ( empty($available_rooms) ) return [];

        // Filter thêm: capacity đủ cho số adults
        if ( $adults > 1 ) {
            $capacity_ok = [];
            foreach ( $available_rooms as $rid ) {
                $cap = (int) ( get_post_meta($rid, '_tf_capacity', true)
                             ?: get_field('room_max_adults', $rid)
                             ?: 2 );
                if ( $cap >= $adults ) $capacity_ok[] = $rid;
            }
            $available_rooms = $capacity_ok;
        }

        // Filter theo hotel_id nếu có
        if ( $hotel_id ) {
            $hotel_rooms = $wpdb->get_col( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_tf_related_hotel' AND meta_value = %d",
                $hotel_id
            ) );
            $available_rooms = array_intersect( $available_rooms, $hotel_rooms );
        }

        return array_map('intval', $available_rooms);
    }

    // ────────────────────────────────────────────────────────────────────────
    // SHORTCODES
    // ────────────────────────────────────────────────────────────────────────

    /**
     * [rba_search hotel_id="123" show_tours="1"]
     */
    public function shortcode_search_form( array $atts ): string {
        $atts = shortcode_atts( [
            'hotel_id'   => 0,
            'show_tours' => 0,
            'compact'    => 0,
        ], $atts );

        ob_start();
        include RBA_PATH . 'templates/search-form.php';
        return ob_get_clean();
    }

    /**
     * [rba_available_rooms check_in="2025-07-01" check_out="2025-07-05" adults="2"]
     */
    public function shortcode_available_rooms( array $atts ): string {
        $atts = shortcode_atts( [
            'check_in'  => '',
            'check_out' => '',
            'adults'    => 1,
            'hotel_id'  => 0,
        ], $atts );

        if ( ! $atts['check_in'] || ! $atts['check_out'] ) return '';

        $ids = self::get_available_room_ids(
            $atts['check_in'], $atts['check_out'],
            (int)$atts['adults'], (int)$atts['hotel_id']
        );

        if ( empty($ids) ) return '<p class="rba-no-rooms">😔 Không còn phòng trống trong khoảng thời gian này.</p>';

        $rooms = get_posts( [
            'post_type'   => 'tf_room',
            'post__in'    => $ids,
            'numberposts' => -1,
        ] );

        ob_start();
        foreach ( $rooms as $room ) {
            $price = RBA_Seasonal_Price::calculate_total( $room->ID, $atts['check_in'], $atts['check_out'] );
            $nights = (int)(( strtotime($atts['check_out']) - strtotime($atts['check_in']) ) / DAY_IN_SECONDS);
            $price_per_night = $nights > 0 ? $price / $nights : 0;
            ?>
            <div class="rba-room-card">
                <?php if ( has_post_thumbnail($room->ID) ) : ?>
                    <div class="rba-room-thumb"><?php echo get_the_post_thumbnail($room->ID, 'medium'); ?></div>
                <?php endif; ?>
                <div class="rba-room-info">
                    <h3><?php echo esc_html($room->post_title); ?></h3>
                    <div class="rba-room-meta">
                        <?php
                        $view = (array) get_field('room_view', $room->ID);
                        $size = get_field('room_size', $room->ID);
                        if ($size) echo "<span>📐 {$size}m²</span>";
                        if ($view) echo "<span>🪟 " . implode(', ', $view) . "</span>";
                        ?>
                    </div>
                    <div class="rba-room-price">
                        <span class="price-per-night"><?php echo number_format($price_per_night, 0, ',', '.'); ?> VNĐ/đêm</span>
                        <span class="price-total">Tổng <?php echo $nights; ?> đêm: <strong><?php echo number_format($price, 0, ',', '.'); ?> VNĐ</strong></span>
                    </div>
                    <a href="<?php echo get_permalink($room->ID) . '?check_in=' . $atts['check_in'] . '&check_out=' . $atts['check_out']; ?>"
                       class="rba-btn-book">Đặt phòng ngay →</a>
                </div>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    // ────────────────────────────────────────────────────────────────────────
    // AJAX
    // ────────────────────────────────────────────────────────────────────────

    public function ajax_search_rooms(): void {
        check_ajax_referer( 'rba_public_nonce', 'nonce' );

        $check_in  = sanitize_text_field( $_POST['check_in']  ?? '' );
        $check_out = sanitize_text_field( $_POST['check_out'] ?? '' );
        $adults    = (int) ( $_POST['adults']   ?? 1 );
        $hotel_id  = (int) ( $_POST['hotel_id'] ?? 0 );
        $view_pref = sanitize_text_field( $_POST['view'] ?? '' );
        $max_price = (float) ( $_POST['max_price'] ?? 0 );

        if ( ! $check_in || ! $check_out ) wp_send_json_error( 'Missing dates' );

        $ids = self::get_available_room_ids( $check_in, $check_out, $adults, $hotel_id ?: null );
        $nights = (int)(( strtotime($check_out) - strtotime($check_in) ) / DAY_IN_SECONDS);

        $results = [];
        foreach ( $ids as $rid ) {
            // Filter hướng nhìn
            if ( $view_pref ) {
                $view = (array) get_field('room_view', $rid);
                if ( ! in_array($view_pref, $view) ) continue;
            }

            $total_price = RBA_Seasonal_Price::calculate_total( $rid, $check_in, $check_out );
            $price_night = $nights > 0 ? $total_price / $nights : 0;

            // Filter giá
            if ( $max_price && $price_night > $max_price ) continue;

            $results[] = [
                'id'          => $rid,
                'title'       => get_the_title($rid),
                'url'         => get_permalink($rid) . "?check_in={$check_in}&check_out={$check_out}&adults={$adults}",
                'thumb'       => get_the_post_thumbnail_url($rid, 'medium'),
                'price_night' => $price_night,
                'price_total' => $total_price,
                'nights'      => $nights,
                'size'        => get_field('room_size', $rid),
                'view'        => (array) get_field('room_view', $rid),
                'beds'        => (array) get_field('room_beds', $rid),
            ];
        }

        // Sort by price
        usort($results, fn($a, $b) => $a['price_night'] <=> $b['price_night']);

        wp_send_json_success( [
            'rooms'  => $results,
            'count'  => count($results),
            'dates'  => [ 'check_in' => $check_in, 'check_out' => $check_out, 'nights' => $nights ],
        ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // ASSETS
    // ────────────────────────────────────────────────────────────────────────

    public function enqueue(): void {
        wp_enqueue_style(  'rba-search', RBA_URL . 'assets/css/search.css', [], RBA_VERSION );
        wp_enqueue_script( 'rba-search', RBA_URL . 'assets/js/search.js',  ['jquery'], RBA_VERSION, true );
        wp_localize_script( 'rba-search', 'rba_search_config', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('rba_public_nonce'),
            'currency' => 'VNĐ',
        ] );
    }
}

new RBA_Search();
