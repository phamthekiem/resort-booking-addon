<?php
/**
 * RBA_Booking_Guard
 * Chống double booking: pessimistic lock + idempotent confirm.
 *
 * @package ResortBookingAddon
 * @since   1.0.1
 */
defined( 'ABSPATH' ) || exit;

class RBA_Booking_Guard {

    public function __construct() {
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_and_lock' ], 10, 3 );
        add_action( 'woocommerce_cart_item_removed',      [ $this, 'release_lock_on_remove' ], 10, 2 );
        // Idempotent: cả 2 hooks đều chạy, nhưng chỉ xử lý 1 lần nhờ order meta flag
        add_action( 'woocommerce_payment_complete',        [ $this, 'confirm_booking' ] );
        add_action( 'woocommerce_order_status_processing', [ $this, 'confirm_booking' ] );
        add_action( 'woocommerce_order_status_cancelled',  [ $this, 'release_booking' ] );
        add_action( 'woocommerce_order_status_failed',     [ $this, 'release_booking' ] );
        add_action( 'woocommerce_order_status_refunded',   [ $this, 'release_booking' ] );
        add_action( 'rba_ical_sync_cron',                  [ $this, 'cleanup_expired_locks' ] );
        add_action( 'woocommerce_checkout_process',        [ $this, 'final_availability_check' ] );
        add_action( 'rest_api_init',                       [ $this, 'register_rest_endpoints' ] );
        add_action( 'wp_ajax_rba_check_availability',        [ $this, 'ajax_check_availability' ] );
        add_action( 'wp_ajax_nopriv_rba_check_availability', [ $this, 'ajax_check_availability' ] );
    }

    // ─── SESSION (không dùng PHP session_start) ───────────────────────────────

    private static function get_session_id(): string {
        // 1. WooCommerce customer ID (best)
        if ( function_exists( 'WC' ) && WC()->session ) {
            $id = WC()->session->get_customer_id();
            if ( $id ) return 'wc_' . $id;
        }
        // 2. Custom cookie (no PHP session)
        $cookie = 'rba_sid_' . COOKIEHASH;
        if ( ! empty( $_COOKIE[ $cookie ] ) ) {
            $val = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) );
            if ( preg_match( '/^[a-f0-9]{32}$/', $val ) ) return $val;
        }
        // 3. Tạo token mới
        $token = md5( uniqid( '', true ) . wp_rand() );
        if ( ! headers_sent() ) {
            setcookie( $cookie, $token, time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }
        return $token;
    }

    // ─── LOCK ─────────────────────────────────────────────────────────────────

    public static function acquire_lock( int $room_id, string $from, string $to ): bool {
        global $wpdb;
        $table      = $wpdb->prefix . 'rba_booking_locks';
        $session_id = self::get_session_id();
        $expires    = gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS );

        // Xóa lock cũ của session này
        $wpdb->delete( $table, [ 'session_id' => $session_id, 'room_id' => $room_id ], [ '%s', '%d' ] );

        // Đếm lock đang giữ từ sessions khác (overlap dates)
        $active_locks = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE room_id = %d AND session_id != %s AND expires_at > UTC_TIMESTAMP()
               AND date_from < %s AND date_to > %s",
            $room_id, $session_id, $to, $from
        ) );

        $available = RBA_Database::get_available_rooms( $room_id, $from, $to );
        if ( ( $available - $active_locks ) <= 0 ) return false;

        return (bool) $wpdb->insert( $table, [
            'room_id'    => $room_id,
            'date_from'  => $from,
            'date_to'    => $to,
            'session_id' => $session_id,
            'expires_at' => $expires,
        ], [ '%d', '%s', '%s', '%s', '%s' ] );
    }

    public static function attach_order_to_lock( int $room_id, string $from, string $to, int $order_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rba_booking_locks',
            [ 'order_id' => $order_id, 'expires_at' => '9999-12-31 00:00:00' ],
            [ 'session_id' => self::get_session_id(), 'room_id' => $room_id ],
            [ '%d', '%s' ], [ '%s', '%d' ]
        );
    }

    public static function release_lock( int $room_id, string $session_id = '' ): void {
        global $wpdb;
        if ( ! $session_id ) $session_id = self::get_session_id();
        $wpdb->delete( $wpdb->prefix . 'rba_booking_locks', [ 'session_id' => $session_id, 'room_id' => $room_id ], [ '%s', '%d' ] );
    }

    public static function release_lock_by_order( int $order_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'rba_booking_locks', [ 'order_id' => $order_id ], [ '%d' ] );
    }

    // ─── HOOKS ────────────────────────────────────────────────────────────────

    public function validate_and_lock( bool $passed, int $product_id, int $qty ): bool {
        if ( ! $passed ) return false;

        $room_id   = absint( $_POST['tf_room_id']   ?? $_POST['room_id']   ?? 0 );
        $check_in  = sanitize_text_field( wp_unslash( $_POST['tf_check_in']  ?? $_POST['check_in']  ?? '' ) );
        $check_out = sanitize_text_field( wp_unslash( $_POST['tf_check_out'] ?? $_POST['check_out'] ?? '' ) );

        if ( ! $room_id || ! $check_in || ! $check_out ) return $passed;

        if ( ! self::is_valid_date( $check_in ) || ! self::is_valid_date( $check_out ) ) {
            wc_add_notice( '⚠️ Ngày check-in / check-out không hợp lệ.', 'error' );
            return false;
        }
        if ( strtotime( $check_out ) <= strtotime( $check_in ) ) {
            wc_add_notice( '⚠️ Ngày check-out phải sau ngày check-in.', 'error' );
            return false;
        }
        if ( RBA_Database::get_available_rooms( $room_id, $check_in, $check_out ) <= 0 ) {
            wc_add_notice( sprintf( '⚠️ Phòng <strong>%s</strong> đã hết trống trong khoảng thời gian này.', esc_html( get_the_title( $room_id ) ) ), 'error' );
            return false;
        }
        if ( ! self::acquire_lock( $room_id, $check_in, $check_out ) ) {
            wc_add_notice( '⚠️ Phòng đang được người khác giữ chỗ. Vui lòng thử lại sau ít phút.', 'error' );
            return false;
        }
        return true;
    }

    public function final_availability_check(): void {
        if ( ! WC()->cart ) return;
        foreach ( WC()->cart->get_cart() as $item ) {
            $room_id   = absint( $item['tf_room_id'] ?? $item['room_id'] ?? 0 );
            $check_in  = $item['tf_check_in']  ?? $item['check_in']  ?? '';
            $check_out = $item['tf_check_out'] ?? $item['check_out'] ?? '';
            if ( ! $room_id || ! $check_in || ! $check_out ) continue;
            if ( ! $this->is_lock_valid( $room_id, $check_in, $check_out ) ) {
                if ( ! self::acquire_lock( $room_id, $check_in, $check_out ) ) {
                    wc_add_notice(
                        sprintf( '⚠️ Phòng <strong>%s</strong> không còn trống. <a href="%s">Xóa và chọn lại.</a>', esc_html( get_the_title( $room_id ) ), esc_url( wc_get_cart_url() ) ),
                        'error'
                    );
                }
            }
        }
    }

    private function is_lock_valid( int $room_id, string $from, string $to ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rba_booking_locks
             WHERE room_id = %d AND date_from = %s AND date_to = %s AND session_id = %s AND expires_at > UTC_TIMESTAMP()",
            $room_id, $from, $to, self::get_session_id()
        ) );
    }

    /**
     * Idempotent confirm — dùng order meta '_rba_booking_confirmed' tránh double decrement.
     */
    public function confirm_booking( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( '1' === $order->get_meta( '_rba_booking_confirmed' ) ) return; // đã xử lý rồi

        $confirmed = false;
        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $room_id   = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            $check_in  = $item->get_meta( 'tf_check_in' )  ?: $item->get_meta( 'check_in' );
            $check_out = $item->get_meta( 'tf_check_out' ) ?: $item->get_meta( 'check_out' );
            if ( ! $room_id || ! $check_in || ! $check_out ) continue;

            RBA_Database::decrement_availability( $room_id, $check_in, $check_out );
            self::attach_order_to_lock( $room_id, $check_in, $check_out, $order_id );
            $confirmed = true;
        }

        if ( $confirmed ) {
            $order->update_meta_data( '_rba_booking_confirmed', '1' );
            $order->save_meta_data();
            do_action( 'rba_booking_confirmed', $order_id, $order );
        }
    }

    public function release_booking( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( '1' !== $order->get_meta( '_rba_booking_confirmed' ) ) return; // belum pernah confirm

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $room_id   = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            $check_in  = $item->get_meta( 'tf_check_in' )  ?: $item->get_meta( 'check_in' );
            $check_out = $item->get_meta( 'tf_check_out' ) ?: $item->get_meta( 'check_out' );
            if ( ! $room_id || ! $check_in || ! $check_out ) continue;
            RBA_Database::increment_availability( $room_id, $check_in, $check_out );
        }

        self::release_lock_by_order( $order_id );
        $order->delete_meta_data( '_rba_booking_confirmed' );
        $order->save_meta_data();
        do_action( 'rba_booking_released', $order_id, $order );
    }

    public function release_lock_on_remove( string $cart_item_key, \WC_Cart $cart ): void {
        $item = $cart->removed_cart_contents[ $cart_item_key ] ?? [];
        if ( empty( $item ) ) return;
        $room_id = absint( $item['tf_room_id'] ?? $item['room_id'] ?? 0 );
        if ( $room_id ) self::release_lock( $room_id );
    }

    public function cleanup_expired_locks(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}rba_booking_locks WHERE order_id IS NULL AND expires_at < UTC_TIMESTAMP()"
        );
    }

    // ─── REST API ─────────────────────────────────────────────────────────────

    public function register_rest_endpoints(): void {
        register_rest_route( 'rba/v1', '/availability', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_availability' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'room_id'   => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'check_in'  => [ 'required' => true, 'type' => 'string', 'validate_callback' => [ $this, 'validate_date_param' ], 'sanitize_callback' => 'sanitize_text_field' ],
                'check_out' => [ 'required' => true, 'type' => 'string', 'validate_callback' => [ $this, 'validate_date_param' ], 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );
    }

    public function rest_get_availability( \WP_REST_Request $request ): \WP_REST_Response {
        $room_id   = $request->get_param( 'room_id' );
        $check_in  = $request->get_param( 'check_in' );
        $check_out = $request->get_param( 'check_out' );
        $available = RBA_Database::get_available_rooms( $room_id, $check_in, $check_out );
        $nights    = (int) ( ( strtotime( $check_out ) - strtotime( $check_in ) ) / DAY_IN_SECONDS );
        return rest_ensure_response( [
            'available'   => $available > 0,
            'rooms_left'  => $available,
            'total_price' => RBA_Seasonal_Price::calculate_total( $room_id, $check_in, $check_out ),
            'nights'      => $nights,
        ] );
    }

    public function validate_date_param( string $value ): bool {
        return self::is_valid_date( $value );
    }

    public function ajax_check_availability(): void {
        check_ajax_referer( 'rba_public_nonce', 'nonce' );
        $room_id   = absint( $_POST['room_id']   ?? 0 );
        $check_in  = sanitize_text_field( wp_unslash( $_POST['check_in']  ?? '' ) );
        $check_out = sanitize_text_field( wp_unslash( $_POST['check_out'] ?? '' ) );
        if ( ! $room_id || ! $check_in || ! $check_out ) {
            wp_send_json_error( [ 'message' => 'Missing params.' ] );
        }
        wp_send_json_success( [
            'available'   => RBA_Database::get_available_rooms( $room_id, $check_in, $check_out ) > 0,
            'total_price' => RBA_Seasonal_Price::calculate_total( $room_id, $check_in, $check_out ),
        ] );
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private static function is_valid_date( string $date ): bool {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return false;
        [ $y, $m, $d ] = explode( '-', $date );
        return checkdate( (int) $m, (int) $d, (int) $y );
    }
}

new RBA_Booking_Guard();
