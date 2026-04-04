<?php
/**
 * RBA_Price_Display — Hiển thị giá theo ngày trên trang chi tiết phòng
 *
 * Dùng 3 điểm inject song song để đảm bảo hiển thị dù Tourfic dùng template nào:
 *  1. Hook tf_before_booking_form  — inject trước form đặt phòng Tourfic
 *  2. Hook tf_after_booking_form   — fallback sau form
 *  3. Shortcode [rba_price_display] — admin tự đặt vào bất kỳ đâu
 *
 * Widget hiển thị:
 *  - Giá base/đêm
 *  - Badge "Giá mùa cao điểm" nếu đang trong mùa
 *  - Calendar tháng với màu giá (xanh = rẻ, đỏ = cao)
 *  - Khi chọn ngày: tự tính tổng tiền theo từng đêm
 *
 * @package ResortBookingAddon
 * @since   1.4.5
 */
defined( 'ABSPATH' ) || exit;

class RBA_Price_Display {

    public function __construct() {
        // tf_before/after_booking_form fire ở mọi nơi có Tourfic search form
        // (kể cả trang chủ, trang tìm kiếm) — KHÔNG truyền int, phải dùng wrapper
        add_action( 'tf_before_booking_form', [ $this, 'maybe_render_widget' ], 5 );
        add_action( 'tf_after_booking_form',  [ $this, 'maybe_render_widget' ], 5 );

        // Shortcode để admin tự đặt
        add_shortcode( 'rba_price_display',  [ $this, 'shortcode_price_display'  ] );
        add_shortcode( 'rba_price_calendar', [ $this, 'shortcode_price_calendar' ] );

        // Inject vào the_content của single room (fallback cuối)
        add_filter( 'the_content', [ $this, 'inject_into_content' ] );

        // Enqueue
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_head',            [ $this, 'inline_styles' ] );

        // AJAX
        add_action( 'wp_ajax_rba_calculate_price',           [ $this, 'ajax_calculate_price' ] );
        add_action( 'wp_ajax_nopriv_rba_calculate_price',    [ $this, 'ajax_calculate_price' ] );
        add_action( 'wp_ajax_rba_get_price_calendar',        [ $this, 'ajax_price_calendar'  ] );
        add_action( 'wp_ajax_nopriv_rba_get_price_calendar', [ $this, 'ajax_price_calendar'  ] );
    }

    /**
     * Wrapper type-safe cho tf_before/after_booking_form.
     *
     * Hook này fire ở MỌI NƠI có Tourfic form (homepage, search, single room).
     * Tham số truyền vào có thể là bất kỳ type nào — phải guard trước.
     * Chỉ render widget khi đang ở trang single tf_room.
     *
     * @param mixed $param  Tham số từ hook (bỏ qua — tự lấy từ get_the_ID)
     */
    public function maybe_render_widget( $param = null ): void {
        // Chỉ render trên trang single phòng
        if ( ! is_singular( 'tf_room' ) ) return;

        $room_id = (int) get_the_ID();
        if ( $room_id <= 0 ) return;

        $this->render_price_widget( $room_id );
    }

    // =========================================================================
    // RENDER: Widget giá chính
    // =========================================================================

    public function render_price_widget( int $room_id = 0 ): void {
        if ( $room_id <= 0 ) {
            $room_id = (int) get_the_ID();
        }
        if ( $room_id <= 0 ) return;
        if ( get_post_type( $room_id ) !== 'tf_room' ) return;

        // Tránh render 2 lần (tf_before + tf_after đều fire)
        static $rendered = [];
        if ( isset( $rendered[ $room_id ] ) ) return;
        $rendered[ $room_id ] = true;

        echo $this->get_price_widget_html( $room_id ); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public function inject_into_content( string $content ): string {
        if ( ! is_singular( 'tf_room' ) ) return $content;

        $room_id = get_the_ID();
        if ( ! $room_id ) return $content;

        static $injected = false;
        if ( $injected ) return $content;
        $injected = true;

        return $content . $this->get_price_widget_html( $room_id );
    }

    /**
     * Tạo HTML widget giá đầy đủ.
     */
    private function get_price_widget_html( int $room_id ): string {
        $base_price    = RBA_Seasonal_Price::get_base_price( $room_id );
        $today         = current_time( 'Y-m-d' );
        $today_price   = RBA_Seasonal_Price::get_price_for_date( $room_id, $today );
        $has_seasonal  = $this->has_any_pricing( $room_id );
        $currency      = get_woocommerce_currency_symbol() ?: '₫';
        $nonce         = wp_create_nonce( 'rba_public_nonce' );

        // Lấy mùa đang active
        $active_season = $this->get_active_season( $room_id, $today );

        ob_start();
        ?>
        <div class="rba-price-widget" id="rba-price-widget-<?php echo esc_attr( $room_id ); ?>"
             data-room="<?php echo esc_attr( $room_id ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-currency="<?php echo esc_attr( $currency ); ?>">

            <!-- HEADER: Giá hiện tại -->
            <div class="rba-pw-header">
                <div class="rba-pw-price-block">
                    <?php if ( $today_price > 0 ) : ?>
                        <span class="rba-pw-label">Giá từ</span>
                        <span class="rba-pw-price">
                            <?php echo esc_html( number_format( $today_price, 0, ',', '.' ) ); ?>
                            <span class="rba-pw-currency"><?php echo esc_html( $currency ); ?></span>
                        </span>
                        <span class="rba-pw-night">/đêm</span>
                    <?php else : ?>
                        <span class="rba-pw-label">Liên hệ để biết giá</span>
                    <?php endif; ?>
                </div>

                <?php if ( $active_season ) : ?>
                <div class="rba-pw-season-badge">
                    <span class="rba-pw-badge <?php echo esc_attr( $active_season['type'] ); ?>">
                        <?php echo esc_html( $active_season['label'] ); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( $has_seasonal ) : ?>
            <!-- CALENDAR -->
            <div class="rba-pw-section">
                <div class="rba-pw-section-title">
                    Giá theo ngày
                    <button class="rba-pw-toggle" data-target="rba-cal-<?php echo esc_attr( $room_id ); ?>">
                        <span class="rba-pw-toggle-icon">▼</span>
                    </button>
                </div>
                <div class="rba-pw-calendar-wrap" id="rba-cal-<?php echo esc_attr( $room_id ); ?>">
                    <div class="rba-pw-calendar-nav">
                        <button class="rba-cal-prev" data-room="<?php echo esc_attr( $room_id ); ?>">‹</button>
                        <span class="rba-cal-month-label"></span>
                        <button class="rba-cal-next" data-room="<?php echo esc_attr( $room_id ); ?>">›</button>
                    </div>
                    <div class="rba-pw-calendar" data-room="<?php echo esc_attr( $room_id ); ?>">
                        <div class="rba-cal-loading">Đang tải...</div>
                    </div>
                    <div class="rba-pw-legend">
                        <span class="rba-legend-item"><span class="rba-dot rba-dot--low"></span> Giá thấp</span>
                        <span class="rba-legend-item"><span class="rba-dot rba-dot--mid"></span> Bình thường</span>
                        <span class="rba-legend-item"><span class="rba-dot rba-dot--high"></span> Cao điểm</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- TÍNH GIÁ THEO NGÀY CHỌN -->
            <div class="rba-pw-section">
                <div class="rba-pw-section-title">Tính giá</div>
                <div class="rba-pw-calc">
                    <div class="rba-pw-dates">
                        <div class="rba-pw-date-field">
                            <label>Nhận phòng</label>
                            <input type="date" class="rba-checkin" data-room="<?php echo esc_attr($room_id); ?>"
                                   min="<?php echo esc_attr( $today ); ?>"
                                   value="<?php echo esc_attr( $today ); ?>">
                        </div>
                        <div class="rba-pw-date-sep">→</div>
                        <div class="rba-pw-date-field">
                            <label>Trả phòng</label>
                            <input type="date" class="rba-checkout" data-room="<?php echo esc_attr($room_id); ?>"
                                   min="<?php echo esc_attr( gmdate('Y-m-d', strtotime($today . ' +1 day')) ); ?>"
                                   value="<?php echo esc_attr( gmdate('Y-m-d', strtotime($today . ' +1 day')) ); ?>">
                        </div>
                    </div>
                    <div class="rba-pw-result" id="rba-result-<?php echo esc_attr($room_id); ?>">
                        <div class="rba-pw-result-loading" style="display:none">Đang tính...</div>
                        <div class="rba-pw-result-content"></div>
                    </div>
                </div>
            </div>

        </div><!-- .rba-price-widget -->
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // SHORTCODES
    // =========================================================================

    public function shortcode_price_display( array $atts ): string {
        $atts    = shortcode_atts( [ 'room_id' => get_the_ID() ], $atts );
        $room_id = absint( $atts['room_id'] );
        if ( ! $room_id ) return '';
        return $this->get_price_widget_html( $room_id );
    }

    /**
     * [rba_price_calendar room_id="X" months="2"]
     * Hiển thị calendar giá đơn giản, nhúng vào bất kỳ đâu.
     */
    public function shortcode_price_calendar( array $atts ): string {
        $atts    = shortcode_atts( [ 'room_id' => get_the_ID(), 'months' => 1 ], $atts );
        $room_id = absint( $atts['room_id'] );
        if ( ! $room_id ) return '';

        $this->enqueue();
        ob_start();
        ?>
        <div class="rba-price-widget rba-inline-calendar"
             data-room="<?php echo esc_attr($room_id); ?>"
             data-nonce="<?php echo esc_attr(wp_create_nonce('rba_public_nonce')); ?>">
            <div class="rba-pw-calendar-nav">
                <button class="rba-cal-prev" data-room="<?php echo esc_attr($room_id); ?>">‹</button>
                <span class="rba-cal-month-label"></span>
                <button class="rba-cal-next" data-room="<?php echo esc_attr($room_id); ?>">›</button>
            </div>
            <div class="rba-pw-calendar" data-room="<?php echo esc_attr($room_id); ?>">
                <div class="rba-cal-loading">Đang tải...</div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function has_any_pricing( int $room_id ): bool {
        global $wpdb;
        $s = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rba_seasonal_prices WHERE room_id = %d",
            $room_id
        ) );
        $d = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rba_date_prices WHERE room_id = %d",
            $room_id
        ) );
        return ( (int) $s + (int) $d ) > 0;
    }

    private function get_active_season( int $room_id, string $date ): ?array {
        global $wpdb;
        $season = $wpdb->get_row( $wpdb->prepare(
            "SELECT season_name, price_type, price_value
             FROM {$wpdb->prefix}rba_seasonal_prices
             WHERE room_id = %d AND date_from <= %s AND date_to >= %s
             ORDER BY priority ASC LIMIT 1",
            $room_id, $date, $date
        ) );
        if ( ! $season ) return null;

        $is_high = $season->price_type === 'fixed'
            ? (float) $season->price_value > RBA_Seasonal_Price::get_base_price( $room_id )
            : (float) $season->price_value > 0;

        return [
            'label' => $season->season_name ?: 'Giá đặc biệt',
            'type'  => $is_high ? 'high' : 'low',
        ];
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    public function ajax_calculate_price(): void {
        if ( ob_get_length() ) ob_clean();
        check_ajax_referer( 'rba_public_nonce', 'nonce' );

        $room_id   = absint( $_POST['room_id']   ?? 0 );
        $check_in  = sanitize_text_field( wp_unslash( $_POST['check_in']  ?? '' ) );
        $check_out = sanitize_text_field( wp_unslash( $_POST['check_out'] ?? '' ) );

        if ( ! $room_id || ! $check_in || ! $check_out ) {
            wp_send_json_error( 'Thiếu tham số' );
        }

        $nights = (int) ( ( strtotime( $check_out ) - strtotime( $check_in ) ) / DAY_IN_SECONDS );
        if ( $nights <= 0 ) {
            wp_send_json_error( 'Ngày không hợp lệ' );
        }

        // Tính từng đêm
        $breakdown = [];
        $total     = 0;
        $date      = new DateTime( $check_in );
        $end       = new DateTime( $check_out );
        $base      = RBA_Seasonal_Price::get_base_price( $room_id );
        $has_vary  = false;

        while ( $date < $end ) {
            $d     = $date->format( 'Y-m-d' );
            $price = RBA_Seasonal_Price::get_price_for_date( $room_id, $d );
            if ( $price !== $base ) $has_vary = true;
            $breakdown[] = [ 'date' => $d, 'price' => $price ];
            $total += $price;
            $date->modify( '+1 day' );
        }

        $currency = get_woocommerce_currency_symbol() ?: '₫';

        wp_send_json_success( [
            'total'     => $total,
            'nights'    => $nights,
            'currency'  => $currency,
            'has_vary'  => $has_vary,
            'breakdown' => $breakdown,
            'formatted' => number_format( $total, 0, ',', '.' ) . ' ' . $currency,
            'per_night' => number_format( $total / $nights, 0, ',', '.' ) . ' ' . $currency . '/đêm (trung bình)',
        ] );
    }

    public function ajax_price_calendar(): void {
        // Xóa bất kỳ output nào đã bị echo ra (PHP warnings/notices) trước khi trả JSON
        if ( ob_get_length() ) ob_clean();

        check_ajax_referer( 'rba_public_nonce', 'nonce' );

        $room_id = absint( $_POST['room_id'] ?? 0 );
        $year    = (int) ( $_POST['year']    ?? gmdate('Y') );
        $month   = (int) ( $_POST['month']   ?? gmdate('m') );

        if ( ! $room_id ) wp_send_json_error( 'Missing room_id' );

        $days    = cal_days_in_month( CAL_GREGORIAN, $month, $year );
        $prices  = [];
        $base    = RBA_Seasonal_Price::get_base_price( $room_id );
        $min_p   = PHP_FLOAT_MAX;
        $max_p   = 0.0;

        for ( $d = 1; $d <= $days; $d++ ) {
            $date      = sprintf( '%04d-%02d-%02d', $year, $month, $d );
            $price     = RBA_Seasonal_Price::get_price_for_date( $room_id, $date );
            $prices[]  = [ 'date' => $date, 'price' => $price ];
            if ( $price > 0 ) {
                $min_p = min( $min_p, $price );
                $max_p = max( $max_p, $price );
            }
        }

        // Phân loại giá: low / mid / high
        $range = $max_p - $min_p;
        foreach ( $prices as &$p ) {
            if ( $p['price'] <= 0 ) {
                $p['level'] = 'none';
            } elseif ( $range < 1000 ) {
                $p['level'] = 'mid';
            } elseif ( $p['price'] <= $min_p + $range * 0.33 ) {
                $p['level'] = 'low';
            } elseif ( $p['price'] <= $min_p + $range * 0.66 ) {
                $p['level'] = 'mid';
            } else {
                $p['level'] = 'high';
            }
        }

        wp_send_json_success( [
            'prices'  => $prices,
            'year'    => $year,
            'month'   => $month,
            'label'   => date_i18n( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) ),
            'days'    => $days,
            'weekday' => (int) date( 'N', mktime( 0, 0, 0, $month, 1, $year ) ), // 1=Mon
            'currency'=> get_woocommerce_currency_symbol() ?: '₫',
            'base'    => $base,
        ] );
    }

    // =========================================================================
    // ENQUEUE ASSETS
    // =========================================================================

    public function enqueue(): void {
        if ( ! is_singular( ['tf_room', 'tf_hotel'] ) && ! did_action('wp_head') ) return;
        wp_enqueue_script(
            'rba-price-display',
            RBA_URL . 'assets/js/price-display.js',
            [ 'jquery' ],
            RBA_VERSION,
            true
        );
        wp_localize_script( 'rba-price-display', 'rba_price_cfg', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'rba_public_nonce' ),
            'i18n'     => [
                'nights'   => 'đêm',
                'total'    => 'Tổng tiền',
                'average'  => 'Trung bình',
                'loading'  => 'Đang tính...',
                'mon' => 'T2', 'tue' => 'T3', 'wed' => 'T4',
                'thu' => 'T5', 'fri' => 'T6', 'sat' => 'T7', 'sun' => 'CN',
            ],
        ] );
    }

    public function inline_styles(): void {
        if ( ! is_singular( ['tf_room', 'tf_hotel'] ) ) return;
        ?>
        <style id="rba-price-display-css">
        /* ── RBA Price Widget ─────────────────────────────────────────────── */
        .rba-price-widget {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            font-family: inherit;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        /* Header */
        .rba-pw-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .rba-pw-label { font-size: 13px; color: #888; display: block; margin-bottom: 2px; }
        .rba-pw-price {
            font-size: 28px;
            font-weight: 700;
            color: #1a6b3c;
            line-height: 1;
        }
        .rba-pw-currency { font-size: 16px; font-weight: 400; }
        .rba-pw-night { font-size: 13px; color: #888; margin-left: 4px; }

        /* Season badge */
        .rba-pw-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .rba-pw-badge.high { background: #fce4ec; color: #c62828; }
        .rba-pw-badge.low  { background: #e8f5e9; color: #2e7d32; }

        /* Section */
        .rba-pw-section { border-top: 1px solid #f0f0f0; padding-top: 14px; margin-top: 14px; }
        .rba-pw-section-title {
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .rba-pw-toggle {
            background: none; border: none; cursor: pointer;
            color: #888; font-size: 12px; padding: 0;
        }
        .rba-pw-toggle-icon { display: inline-block; transition: transform .2s; }
        .rba-pw-toggle.open .rba-pw-toggle-icon { transform: rotate(180deg); }

        /* Calendar */
        .rba-pw-calendar-wrap { overflow: hidden; transition: max-height .3s; }
        .rba-pw-calendar-wrap.collapsed { max-height: 0 !important; }
        .rba-pw-calendar-nav {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 8px;
        }
        .rba-cal-prev, .rba-cal-next {
            background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;
            padding: 4px 10px; cursor: pointer; font-size: 16px; line-height: 1;
        }
        .rba-cal-prev:hover, .rba-cal-next:hover { background: #e8f5e9; border-color: #1a6b3c; }
        .rba-cal-month-label { font-weight: 600; font-size: 14px; }

        .rba-pw-calendar { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
        .rba-cal-dow {
            text-align: center; font-size: 11px; color: #999;
            padding: 4px 0; font-weight: 600;
        }
        .rba-cal-day {
            text-align: center; padding: 5px 2px; border-radius: 6px;
            font-size: 11px; cursor: default; position: relative;
            min-height: 44px; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            border: 1px solid transparent;
        }
        .rba-cal-day .rba-day-num { font-size: 13px; font-weight: 600; line-height: 1; }
        .rba-cal-day .rba-day-price { font-size: 10px; color: #666; margin-top: 2px; white-space: nowrap; }
        .rba-cal-day--low  { background: #e8f5e9; }
        .rba-cal-day--mid  { background: #fff9c4; }
        .rba-cal-day--high { background: #fce4ec; }
        .rba-cal-day--past { opacity: .4; }
        .rba-cal-day--today { border-color: #1a6b3c !important; }
        .rba-cal-day--none  { background: #fafafa; }
        .rba-cal-day--selected { border-color: #1a6b3c !important; background: #c8e6c9 !important; }
        .rba-cal-day--in-range { background: #e8f5e9 !important; }
        .rba-cal-empty { min-height: 44px; }
        .rba-cal-loading { grid-column: 1/-1; text-align: center; padding: 20px; color: #888; font-size: 13px; }

        /* Legend */
        .rba-pw-legend { display: flex; gap: 12px; margin-top: 8px; flex-wrap: wrap; }
        .rba-legend-item { font-size: 11px; color: #666; display: flex; align-items: center; gap: 4px; }
        .rba-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .rba-dot--low  { background: #a5d6a7; }
        .rba-dot--mid  { background: #fff176; border: 1px solid #ddd; }
        .rba-dot--high { background: #ef9a9a; }

        /* Calculator */
        .rba-pw-dates {
            display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;
        }
        .rba-pw-date-field { flex: 1; min-width: 120px; }
        .rba-pw-date-field label { font-size: 11px; color: #888; display: block; margin-bottom: 4px; }
        .rba-pw-date-field input[type="date"] {
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px;
            font-size: 13px; box-sizing: border-box;
        }
        .rba-pw-date-sep { color: #999; font-size: 18px; }

        /* Result */
        .rba-pw-result { background: #f9f9f9; border-radius: 8px; padding: 12px; min-height: 60px; }
        .rba-pw-total {
            font-size: 22px; font-weight: 700; color: #1a6b3c; margin-bottom: 4px;
        }
        .rba-pw-breakdown { font-size: 12px; color: #666; line-height: 1.8; }
        .rba-pw-breakdown-row { display: flex; justify-content: space-between; }
        .rba-pw-breakdown-row.vary { color: #e65100; }

        /* Inline calendar shortcode */
        .rba-inline-calendar { max-width: 320px; }

        /* Responsive */
        @media (max-width: 480px) {
            .rba-pw-price { font-size: 22px; }
            .rba-cal-day { min-height: 36px; }
            .rba-cal-day .rba-day-price { display: none; }
        }
        </style>
        <?php
    }
}

new RBA_Price_Display();
