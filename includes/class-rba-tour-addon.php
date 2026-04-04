<?php
/**
 * RBA_Tour_Addon
 *
 * Nâng cấp tour nội khu Tourfic:
 *  - Quản lý slot theo giờ trong ngày
 *  - Giới hạn số khách / slot (không chỉ max/ngày)
 *  - Pricing: người lớn / trẻ em / trẻ nhỏ
 *  - Combo phòng + tour (bundle discount)
 *  - Calendar tour có sẵn slot
 */
defined( 'ABSPATH' ) || exit;

class RBA_Tour_Addon {

    public function __construct() {
        // ── Custom table cho tour slots ───────────────────────────────────────
        // Table created on activation via RBA_Database::create_tables() (moved from constructor hook)

        // ── Meta box quản lý slots ────────────────────────────────────────────
        add_action( 'add_meta_boxes',                  [ $this, 'add_slot_metabox' ] );
        add_action( 'save_post_tf_tour',               [ $this, 'save_tour_settings' ] );

        // ── Hook Tourfic tour price ────────────────────────────────────────────
        add_filter( 'tf_tour_price',                   [ $this, 'apply_tour_pricing' ], 20, 3 );

        // ── AJAX: lấy slots available cho ngày ────────────────────────────────
        add_action( 'wp_ajax_rba_get_tour_slots',        [ $this, 'ajax_get_slots' ] );
        add_action( 'wp_ajax_nopriv_rba_get_tour_slots', [ $this, 'ajax_get_slots' ] );

        // ── Validate + giữ slot khi add to cart ──────────────────────────────
        add_filter( 'woocommerce_add_to_cart_validation',[ $this, 'validate_tour_slot' ], 15, 3 );

        // ── Confirm/release slot theo order status ────────────────────────────
        add_action( 'woocommerce_payment_complete',      [ $this, 'confirm_tour_slot' ] );
        add_action( 'woocommerce_order_status_cancelled',[ $this, 'release_tour_slot' ] );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'release_tour_slot' ] );

        // ── Combo: phòng + tour discount ─────────────────────────────────────
        add_action( 'woocommerce_cart_calculate_fees',   [ $this, 'apply_bundle_discount' ] );

        // ── Shortcode tour slots ───────────────────────────────────────────────
        add_shortcode( 'rba_tour_slots',               [ $this, 'shortcode_tour_slots' ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // TABLE: Tour bookings (slots)
    // ────────────────────────────────────────────────────────────────────────

    public function maybe_create_tour_table(): void {
        global $wpdb;
        if ( get_option('rba_tour_table_ver') === '1.0' ) return;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rba_tour_bookings (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tour_id     BIGINT UNSIGNED NOT NULL,
            tour_date   DATE            NOT NULL,
            slot_time   VARCHAR(10)     NOT NULL COMMENT '08:00, 14:00, v.v.',
            adults      TINYINT UNSIGNED NOT NULL DEFAULT 1,
            children    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            infants     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            order_id    BIGINT UNSIGNED,
            status      ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
            session_id  VARCHAR(64),
            expires_at  DATETIME,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tour_date_slot (tour_id, tour_date, slot_time),
            KEY order_id (order_id)
        ) " . $wpdb->get_charset_collate() . ";" );

        update_option( 'rba_tour_table_ver', '1.0' );
    }

    // ────────────────────────────────────────────────────────────────────────
    // PRICING
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Tính giá tour theo số người.
     */
    public function apply_tour_pricing( $price, int $tour_id, array $booking_data ): float {
        $adults   = (int) ( $booking_data['adults']   ?? 1 );
        $children = (int) ( $booking_data['children'] ?? 0 );
        $infants  = (int) ( $booking_data['infants']  ?? 0 );

        $price_adult   = (float) ( get_post_meta($tour_id, '_tf_price',           true) ?: get_field('tour_price_adult',   $tour_id) ?: 0 );
        $price_child   = (float) ( get_post_meta($tour_id, '_tf_price_children',  true) ?: get_field('tour_price_child',   $tour_id) ?: $price_adult * 0.6 );
        $price_infant  = (float) ( get_post_meta($tour_id, '_tf_price_infant',    true) ?: get_field('tour_price_infant',  $tour_id) ?: 0 );

        return ( $adults * $price_adult ) + ( $children * $price_child ) + ( $infants * $price_infant );
    }

    // ────────────────────────────────────────────────────────────────────────
    // SLOT MANAGEMENT
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Lấy slots available cho 1 tour vào 1 ngày.
     */
    public static function get_available_slots( int $tour_id, string $date ): array {
        global $wpdb;

        // Lấy cấu hình slots từ ACF
        $configured_slots = get_field('tour_time_slots', $tour_id) ?: [];
        $max_pax          = (int) ( get_field('tour_max_pax', $tour_id) ?: 20 );

        if ( empty($configured_slots) ) {
            // Fallback: dùng meta Tourfic
            $configured_slots = [
                [ 'slot_time' => '08:00', 'slot_limit' => $max_pax ],
                [ 'slot_time' => '14:00', 'slot_limit' => $max_pax ],
            ];
        }

        // Lấy số người đã book cho mỗi slot
        $booked = $wpdb->get_results( $wpdb->prepare(
            "SELECT slot_time, SUM(adults + children) as pax
             FROM {$wpdb->prefix}rba_tour_bookings
             WHERE tour_id   = %d
               AND tour_date = %s
               AND status    IN ('pending','confirmed')
               AND (expires_at IS NULL OR expires_at > NOW())
             GROUP BY slot_time",
            $tour_id, $date
        ), ARRAY_A );

        $booked_map = array_column($booked, 'pax', 'slot_time');

        $result = [];
        foreach ( $configured_slots as $slot ) {
            $time    = $slot['slot_time'];
            $limit   = (int) ( $slot['slot_limit'] ?? $max_pax );
            $booked_pax = (int) ( $booked_map[$time] ?? 0 );
            $available  = max(0, $limit - $booked_pax);

            $result[] = [
                'time'      => $time,
                'limit'     => $limit,
                'booked'    => $booked_pax,
                'available' => $available,
                'full'      => $available <= 0,
            ];
        }

        return $result;
    }

    public function ajax_get_slots(): void {
        check_ajax_referer('rba_public_nonce', 'nonce');
        $tour_id = (int) ( $_POST['tour_id'] ?? 0 );
        $date    = sanitize_text_field( $_POST['date'] ?? '' );
        if (!$tour_id || !$date) wp_send_json_error('Missing params');

        wp_send_json_success([
            'slots' => self::get_available_slots($tour_id, $date),
            'date'  => $date,
        ]);
    }

    /**
     * Validate slot khi add to cart.
     */
    public function validate_tour_slot( bool $passed, int $product_id, int $qty ): bool {
        $tour_id  = (int) ( $_POST['tf_tour_id'] ?? 0 );
        $date     = sanitize_text_field( $_POST['tour_date'] ?? '' );
        $slot     = sanitize_text_field( $_POST['tour_slot'] ?? '' );
        $adults   = (int) ( $_POST['adults'] ?? 1 );
        $children = (int) ( $_POST['children'] ?? 0 );

        if ( ! $tour_id || ! $date ) return $passed;

        $slots = self::get_available_slots( $tour_id, $date );
        $target_slot = null;
        foreach ( $slots as $s ) {
            if ( $s['time'] === $slot ) { $target_slot = $s; break; }
        }

        if ( ! $target_slot ) return $passed;

        if ( $target_slot['available'] < ($adults + $children) ) {
            wc_add_notice( "⚠️ Slot {$slot} chỉ còn {$target_slot['available']} chỗ. Vui lòng chọn slot khác.", 'error' );
            return false;
        }

        // Giữ slot (pending)
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rba_tour_bookings', [
            'tour_id'    => $tour_id,
            'tour_date'  => $date,
            'slot_time'  => $slot,
            'adults'     => $adults,
            'children'   => $children,
            'infants'    => (int)($_POST['infants'] ?? 0),
            'session_id' => ( function_exists('WC') && WC()->session ) ? 'wc_' . WC()->session->get_customer_id() : md5( uniqid( '', true ) ),
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ),
        ] );

        return $passed;
    }

    public function confirm_tour_slot( int $order_id ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rba_tour_bookings
             SET status = 'confirmed', order_id = %d, expires_at = NULL
             WHERE order_id IS NULL AND session_id = %s AND status = 'pending'",
            $order_id, ( function_exists('WC') && WC()->session ) ? 'wc_' . WC()->session->get_customer_id() : ''
        ) );

        // Push tour event lên Google Calendar
        if ( class_exists( 'RBA_GCal' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $gcal = new RBA_GCal();
                foreach ( $order->get_items() as $item ) {
                    /** @var \WC_Order_Item_Product $item */
                    $tour_id = absint( $item->get_meta( 'tf_tour_id' ) ?: $item->get_meta( 'tour_id' ) );
                    $date    = (string) $item->get_meta( 'tour_date' );
                    $slot    = (string) $item->get_meta( 'tour_slot' );
                    if ( ! $tour_id || ! $date ) continue;
                    $gcal->push_tour_event(
                        $tour_id,
                        $date,
                        $slot,
                        (int) ( $item->get_meta( 'adults' )   ?: 1 ),
                        (int) ( $item->get_meta( 'children' ) ?: 0 ),
                        $order
                    );
                }
            }
        }
    }

    public function release_tour_slot( int $order_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rba_tour_bookings',
            [ 'status' => 'cancelled' ],
            [ 'order_id' => $order_id ]
        );

        // Xóa tour event trên Google Calendar
        if ( class_exists( 'RBA_GCal' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $gcal = new RBA_GCal();
                foreach ( $order->get_items() as $item ) {
                    /** @var \WC_Order_Item_Product $item */
                    $tour_id = absint( $item->get_meta( 'tf_tour_id' ) ?: $item->get_meta( 'tour_id' ) );
                    if ( ! $tour_id ) continue;
                    $event_id = (string) $order->get_meta( "_rba_gcal_tour_{$tour_id}" );
                    if ( ! $event_id ) continue;
                    $gcal->delete_event( 0, $event_id );
                    $order->delete_meta_data( "_rba_gcal_tour_{$tour_id}" );
                    $order->save_meta_data();
                }
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // COMBO: Phòng + Tour discount
    // ────────────────────────────────────────────────────────────────────────

    public function apply_bundle_discount( \WC_Cart $cart ): void {
        try {
            $has_room   = false;
            $has_tour   = false;
            $tour_total = 0.0;

            foreach ( $cart->get_cart() as $item ) {
                if ( ! empty( $item['tf_room_id'] ) || ! empty( $item['room_id'] ) ) {
                    $has_room = true;
                }
                if ( ! empty( $item['tf_tour_id'] ) || ! empty( $item['tour_id'] ) ) {
                    $has_tour    = true;
                    // line_total có thể chưa tính — dùng product price * quantity thay thế
                    $product     = $item['data'] ?? null;
                    $price       = $product ? (float) $product->get_price() : 0.0;
                    $qty         = (int) ( $item['quantity'] ?? 1 );
                    $tour_total += $price * $qty;
                }
            }

            if ( $has_room && $has_tour && $tour_total > 0 ) {
                $discount = round( $tour_total * 0.10, 0 );
                $cart->add_fee( 'Ưu đãi combo phòng + tour (-10%)', -$discount );
            }
        } catch ( \Throwable $e ) {
            // Không crash checkout nếu có lỗi tính discount
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[RBA] apply_bundle_discount error: ' . $e->getMessage() );
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // META BOX ADMIN
    // ────────────────────────────────────────────────────────────────────────

    public function add_slot_metabox(): void {
        add_meta_box(
            'rba_tour_settings',
            '🗺️ Cài Đặt Tour Nội Khu',
            [ $this, 'render_tour_metabox' ],
            'tf_tour',
            'side',
            'high'
        );
    }

    public function render_tour_metabox( \WP_Post $post ): void {
        wp_nonce_field('rba_tour_nonce','rba_tour_nonce_f');
        $price_adult  = get_post_meta($post->ID, '_tf_price', true) ?: '';
        $price_child  = get_post_meta($post->ID, '_tf_price_children', true) ?: '';
        $price_infant = get_post_meta($post->ID, '_tf_price_infant', true) ?: '0';
        ?>
        <p>
            <label>Giá người lớn (VNĐ)</label>
            <input type="number" name="rba_tour_price_adult"   value="<?php echo $price_adult; ?>"  class="widefat" step="1000">
        </p>
        <p>
            <label>Giá trẻ em 6-12 (VNĐ)</label>
            <input type="number" name="rba_tour_price_child"   value="<?php echo $price_child; ?>"  class="widefat" step="1000">
        </p>
        <p>
            <label>Giá trẻ dưới 6 (VNĐ)</label>
            <input type="number" name="rba_tour_price_infant"  value="<?php echo $price_infant; ?>" class="widefat" step="100">
        </p>
        <hr>
        <p>
            <label>
                <input type="checkbox" name="rba_tour_combo_eligible" value="1"
                    <?php checked(get_post_meta($post->ID,'_rba_combo_eligible',true), '1'); ?>>
                Áp dụng giảm giá combo phòng + tour
            </label>
        </p>
        <?php
    }

    public function save_tour_settings( int $post_id ): void {
        if ( ! isset($_POST['rba_tour_nonce_f'])
             || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rba_tour_nonce_f'] ) ), 'rba_tour_nonce') ) return;
        if ( wp_is_post_autosave($post_id) ) return;

        update_post_meta($post_id, '_tf_price',            (float)($_POST['rba_tour_price_adult']  ?? 0));
        update_post_meta($post_id, '_tf_price_children',   (float)($_POST['rba_tour_price_child']  ?? 0));
        update_post_meta($post_id, '_tf_price_infant',     (float)($_POST['rba_tour_price_infant'] ?? 0));
        update_post_meta($post_id, '_rba_combo_eligible',  (int)  ($_POST['rba_tour_combo_eligible'] ?? 0) ? '1' : '0');
    }

    // ────────────────────────────────────────────────────────────────────────
    // SHORTCODE
    // ────────────────────────────────────────────────────────────────────────

    /**
     * [rba_tour_slots tour_id="123" date="2025-07-15"]
     */
    public function shortcode_tour_slots( array $atts ): string {
        $atts = shortcode_atts([
            'tour_id' => get_the_ID(),
            'date'    => current_time( 'Y-m-d' ),
        ], $atts);

        $slots = self::get_available_slots((int)$atts['tour_id'], $atts['date']);
        if (empty($slots)) return '<p>Không có lịch tour cho ngày này.</p>';

        ob_start(); ?>
        <div class="rba-tour-slots" data-tour="<?php echo $atts['tour_id']; ?>">
            <h4>Các khung giờ — <?php echo date('d/m/Y', strtotime($atts['date'])); ?></h4>
            <?php foreach ($slots as $s): ?>
            <div class="rba-slot <?php echo $s['full'] ? 'full' : 'available'; ?>">
                <span class="slot-time">🕐 <?php echo $s['time']; ?></span>
                <span class="slot-status">
                    <?php if ($s['full']): ?>
                        <span style="color:red">❌ Hết chỗ</span>
                    <?php else: ?>
                        <span style="color:green">✅ Còn <?php echo $s['available']; ?> chỗ</span>
                    <?php endif; ?>
                </span>
                <?php if (!$s['full']): ?>
                <button class="rba-select-slot button" data-time="<?php echo $s['time']; ?>">Chọn</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php return ob_get_clean();
    }
}

new RBA_Tour_Addon();
