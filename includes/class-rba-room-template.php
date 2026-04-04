<?php
/**
 * RBA_Room_Template — Override trang chi tiết phòng Tourfic
 *
 * Thay thế toàn bộ single-tf_room.php bằng template mới:
 * - Giao diện hiện đại dạng Booking.com
 * - Tích hợp giá theo mùa realtime
 * - Form đặt phòng trực tiếp qua WooCommerce
 * - Hiển thị đầy đủ ACF fields + Tourfic fields
 *
 * Cơ chế: Hook vào 'single_template' filter của WordPress
 * → khi WordPress cần render single post type tf_room
 * → trả về file template của plugin thay vì theme/Tourfic
 *
 * @package ResortBookingAddon
 * @since   1.4.6
 */
defined( 'ABSPATH' ) || exit;

class RBA_Room_Template {

    public function __construct() {
        // Template do theme xử lý — plugin chỉ provide data & AJAX

        // Enqueue assets chỉ trên trang phòng
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX: add to cart từ custom form
        add_action( 'wp_ajax_rba_add_to_cart',        [ $this, 'ajax_add_to_cart' ] );
        add_action( 'wp_ajax_nopriv_rba_add_to_cart', [ $this, 'ajax_add_to_cart' ] );

        // AJAX: check availability realtime
        add_action( 'wp_ajax_rba_room_check',         [ $this, 'ajax_check_room' ] );
        add_action( 'wp_ajax_nopriv_rba_room_check',  [ $this, 'ajax_check_room' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEMPLATE LOADER — chuẩn WordPress
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * WordPress template hierarchy cho tf_room:
     *  1. {theme}/single-tf_room.php        ← template chính (copy từ plugin)
     *  2. {theme}/single.php
     *  3. {theme}/index.php
     *
     * Plugin KHÔNG override template — để WordPress/theme xử lý.
     * Plugin chỉ cung cấp data qua RBA_Room_Data::get($room_id).
     *
     * Để dùng template đẹp: copy file từ plugin vào theme:
     *   FROM: wp-content/plugins/resort-booking-addon/theme-templates/single-tf_room.php
     *   TO:   wp-content/themes/{your-theme}/single-tf_room.php
     */
    public function override_room_template( string $template ): string {
        // Không override — trả về template WordPress đã tìm thấy
        return $template;
    }

    public function enqueue_assets(): void {
        if ( ! is_singular( 'tf_room' ) ) return;
        // JS được inline trong theme-templates/single-tf_room.php
        // Không cần enqueue file riêng
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: Add to cart
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_add_to_cart(): void {
        if ( ob_get_length() ) ob_clean();
        check_ajax_referer( 'rba_public_nonce', 'nonce' );

        $room_id   = absint( $_POST['room_id']   ?? 0 );
        $check_in  = sanitize_text_field( wp_unslash( $_POST['check_in']  ?? '' ) );
        $check_out = sanitize_text_field( wp_unslash( $_POST['check_out'] ?? '' ) );
        $adults    = absint( $_POST['adults']    ?? 1 );
        $children  = absint( $_POST['children']  ?? 0 );

        if ( ! $room_id || ! $check_in || ! $check_out ) {
            wp_send_json_error( 'Vui lòng chọn ngày nhận và trả phòng.' );
        }
        if ( $check_out <= $check_in ) {
            wp_send_json_error( 'Ngày trả phòng phải sau ngày nhận phòng.' );
        }

        // Validate post type
        if ( get_post_type( $room_id ) !== 'tf_room' ) {
            wp_send_json_error( 'ID phòng không hợp lệ.' );
        }

        // Check availability
        $available = RBA_Database::get_available_rooms( $room_id, $check_in, $check_out );
        if ( $available <= 0 ) {
            wp_send_json_error( 'Phòng không còn trống trong khoảng thời gian này.' );
        }

        // Tính giá
        $nights = (int) ( ( strtotime( $check_out ) - strtotime( $check_in ) ) / DAY_IN_SECONDS );
        if ( $nights <= 0 ) wp_send_json_error( 'Ngày không hợp lệ.' );

        $total = RBA_Seasonal_Price::calculate_total( $room_id, $check_in, $check_out );
        if ( $total <= 0 ) {
            $total = RBA_Seasonal_Price::get_base_price( $room_id ) * $nights;
        }

        // ── Tìm WC product ID đúng cách ──────────────────────────────────────
        // Tourfic lưu WC product trong _tf_wc_product_id hoặc dùng chính room post ID
        // KHÔNG tự tạo product mới — product phải được quản lý qua Tourfic
        $product_id = $this->get_wc_product_id( $room_id );
        if ( ! $product_id ) {
            wp_send_json_error(
                'Phòng chưa được liên kết với sản phẩm WooCommerce. ' .
                'Vui lòng lưu lại phòng trong Tourfic để tạo liên kết.'
            );
        }

        // Validate product tồn tại
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( 'Không tìm thấy sản phẩm WooCommerce (ID: ' . $product_id . ').' );
        }

        // KHÔNG save() giá vào DB — product 401 là product chung của Tourfic,
        // save() sẽ ảnh hưởng tất cả phòng khác và gây conflict với Tourfic.
        // Thay vào đó: lưu giá vào cart_item_data, hook woocommerce_before_calculate_totals
        // sẽ đọc rba_total từ cart item và set_price() trong memory.

        // Xóa cart item cũ của cùng phòng (nếu có)
        foreach ( WC()->cart->get_cart() as $key => $item ) {
            $er = absint( $item['tf_room_id'] ?? $item['room_id'] ?? 0 );
            if ( $er === $room_id ) {
                WC()->cart->remove_cart_item( $key );
                break;
            }
        }

        // Thêm vào cart với đầy đủ meta — giá được set bởi hook calculate_totals
        $cart_item_data = [
            'tf_room_id'   => $room_id,
            'room_id'      => $room_id,
            'tf_check_in'  => $check_in,
            'tf_check_out' => $check_out,
            'check_in'     => $check_in,
            'check_out'    => $check_out,
            'adults'       => $adults,
            'children'     => $children,
            'rba_nights'   => $nights,
            'rba_total'    => $total,   // Giá đúng — hook sẽ dùng cái này
        ];

        $cart_key = WC()->cart->add_to_cart( $product_id, 1, 0, [], $cart_item_data );
        if ( ! $cart_key ) {
            wp_send_json_error( 'Không thể thêm vào giỏ hàng. Kiểm tra lại cài đặt WooCommerce.' );
        }

        // Force recalculate với giá đúng ngay sau khi add
        // Đây là lúc hook woocommerce_before_calculate_totals sẽ chạy
        WC()->cart->calculate_totals();

        // Acquire booking lock
        RBA_Booking_Guard::acquire_lock( $room_id, $check_in, $check_out );

        wp_send_json_success( [
            'message'      => 'Đã thêm vào giỏ hàng!',
            'cart_url'     => wc_get_cart_url(),
            'checkout_url' => wc_get_checkout_url(),
            'cart_count'   => WC()->cart->get_cart_contents_count(),
        ] );
    }

    public function ajax_check_room(): void {
        if ( ob_get_length() ) ob_clean();
        check_ajax_referer( 'rba_public_nonce', 'nonce' );

        $room_id   = absint( $_POST['room_id']   ?? 0 );
        $check_in  = sanitize_text_field( wp_unslash( $_POST['check_in']  ?? '' ) );
        $check_out = sanitize_text_field( wp_unslash( $_POST['check_out'] ?? '' ) );

        if ( ! $room_id || ! $check_in || ! $check_out ) {
            wp_send_json_error( 'Thiếu thông tin' );
        }

        $nights    = (int) ( ( strtotime( $check_out ) - strtotime( $check_in ) ) / DAY_IN_SECONDS );
        $available = RBA_Database::get_available_rooms( $room_id, $check_in, $check_out );
        $total     = RBA_Seasonal_Price::calculate_total( $room_id, $check_in, $check_out );
        if ( $total <= 0 ) {
            $total = RBA_Seasonal_Price::get_base_price( $room_id ) * $nights;
        }

        // Breakdown giá từng đêm
        $breakdown = [];
        $date      = new DateTime( $check_in );
        $end       = new DateTime( $check_out );
        $base      = RBA_Seasonal_Price::get_base_price( $room_id );
        while ( $date < $end ) {
            $d     = $date->format( 'Y-m-d' );
            $price = RBA_Seasonal_Price::get_price_for_date( $room_id, $d );
            $breakdown[] = [
                'date'    => $d,
                'price'   => $price,
                'is_special' => abs( $price - $base ) > 1,
            ];
            $date->modify( '+1 day' );
        }

        wp_send_json_success( [
            'available'  => $available > 0,
            'rooms_left' => $available,
            'nights'     => $nights,
            'total'      => $total,
            'per_night'  => $nights > 0 ? $total / $nights : 0,
            'breakdown'  => $breakdown,
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tìm WC product ID liên kết với phòng.
     *
     * Tourfic có nhiều cách lưu product tùy version:
     * 1. _tf_wc_product_id  meta trên tf_room (Tourfic Pro)
     * 2. tf_room post_type = product (một số theme/version dùng cùng ID)
     * 3. Meta _tf_hotel trên product → product của hotel, dùng chung cho các phòng
     * 4. Slug 'tf-hotel-booking' hoặc 'hotel-booking' — product Tourfic tạo khi activate
     * 5. Nếu không có → tự tạo virtual product (lưu lại để dùng sau)
     */
    private function get_wc_product_id( int $room_id ): int {
        global $wpdb;

        // Cache trong request
        static $cache = [];
        if ( isset( $cache[ $room_id ] ) ) return $cache[ $room_id ];

        // 1. Meta trực tiếp trên phòng (đã lưu từ lần trước)
        $pid = (int) get_post_meta( $room_id, '_tf_wc_product_id', true );
        if ( $pid && wc_get_product( $pid ) ) {
            return $cache[ $room_id ] = $pid;
        }

        // 2. Room post tự nó là WC product
        if ( wc_get_product( $room_id ) ) {
            return $cache[ $room_id ] = $room_id;
        }

        // 3. Tìm product liên kết với HOTEL cha của phòng này
        //    Tourfic thường tạo 1 product cho mỗi hotel, không phải mỗi phòng
        $hotel_id = (int) get_post_meta( $room_id, 'tf_hotel', true );
        if ( ! $hotel_id ) {
            $tf_opt = get_post_meta( $room_id, 'tf_room_opt', true );
            if ( is_array($tf_opt) ) $hotel_id = (int)($tf_opt['tf_hotel'] ?? 0);
            elseif ( is_string($tf_opt) ) {
                $opt = json_decode($tf_opt, true);
                $hotel_id = (int)($opt['tf_hotel'] ?? 0);
            }
        }
        if ( $hotel_id ) {
            // Hotel post là WC product?
            if ( wc_get_product( $hotel_id ) ) {
                $cache[ $room_id ] = $hotel_id;
                update_post_meta( $room_id, '_tf_wc_product_id', $hotel_id );
                return $hotel_id;
            }
            // Product liên kết với hotel qua meta
            $pid = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key IN ('_tf_hotel_id','tf_hotel_id','_related_hotel','tf_hotel')
                   AND meta_value = %s
                   AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish')
                 LIMIT 1",
                $hotel_id
            ) );
            if ( $pid && wc_get_product($pid) ) {
                update_post_meta( $room_id, '_tf_wc_product_id', $pid );
                return $cache[ $room_id ] = $pid;
            }
        }

        // 4. Tìm product có meta liên kết với room
        $pid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_tf_room_id','tf_room_id','_related_room','tf_room')
               AND meta_value = %s
             LIMIT 1",
            $room_id
        ) );
        if ( $pid && wc_get_product($pid) ) {
            update_post_meta( $room_id, '_tf_wc_product_id', $pid );
            return $cache[ $room_id ] = $pid;
        }

        // 5. Tourfic tạo sẵn 1 product chung khi activate plugin
        //    Thường có slug: 'tourfic-booking' hoặc title chứa 'Tourfic'
        $pid = (int) $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status IN ('publish','private')
               AND (
                   post_name LIKE 'tourfic%'
                   OR post_title LIKE 'Tourfic%'
                   OR post_name LIKE 'tf-booking%'
                   OR post_name LIKE 'hotel-booking%'
               )
             ORDER BY ID ASC LIMIT 1"
        );
        if ( $pid && wc_get_product($pid) ) {
            update_post_meta( $room_id, '_tf_wc_product_id', $pid );
            return $cache[ $room_id ] = $pid;
        }

        // 6. Không tìm thấy → tự tạo virtual product và lưu lại
        $pid = $this->create_room_product( $room_id );
        if ( $pid ) {
            return $cache[ $room_id ] = $pid;
        }

        return $cache[ $room_id ] = 0;
    }

    private function create_room_product( int $room_id, float $price = 0.0 ): int {
        $room_title = get_the_title( $room_id );

        $product = new \WC_Product_Simple();
        $product->set_name( $room_title );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_price( $price );
        $product->set_regular_price( $price );
        $product->set_virtual( true );
        $product->set_sold_individually( true );
        $product_id = $product->save();

        if ( $product_id ) {
            update_post_meta( $room_id, '_tf_wc_product_id', $product_id );
            // Đánh dấu là product do plugin tạo (để biết khi nào cần update)
            update_post_meta( $product_id, '_rba_auto_product', $room_id );
        }

        return $product_id ?: 0;
    }
}

new RBA_Room_Template();
