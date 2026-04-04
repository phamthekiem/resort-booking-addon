<?php
/**
 * RBA_Seasonal_Price — Engine giá theo mùa (v3 - safe)
 *
 * KIẾN TRÚC AN TOÀN:
 * - KHÔNG hook vào woocommerce_product_get_price (gây recursive loop)
 * - KHÔNG hook vào woocommerce_before_calculate_totals (gây infinite recalc)
 * - Giá được SET THẲNG vào product trong DB khi add_to_cart (trong class-rba-room-template.php)
 * - Plugin chỉ hook woocommerce_cart_item_subtotal để hiển thị "N đêm" info
 *
 * @package ResortBookingAddon
 * @since   1.5.3
 */
defined( 'ABSPATH' ) || exit;

class RBA_Seasonal_Price {

    public function __construct() {
        // Admin meta boxes
        add_action( 'add_meta_boxes',    [ $this, 'add_seasonal_metabox' ] );
        add_action( 'save_post_tf_room', [ $this, 'save_seasonal_prices' ] );

        // Override giá cart item — đọc rba_total từ cart meta (lưu khi add_to_cart)
        // Priority 10 — chạy trước các hook khác của WC
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'set_cart_item_price' ], 10 );

        // Hiển thị "N đêm" info trong cart/checkout
        add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'display_nights_info' ], 20, 3 );

        // AJAX price calendar
        add_action( 'wp_ajax_rba_get_price_calendar',        [ $this, 'ajax_price_calendar' ] );
        add_action( 'wp_ajax_nopriv_rba_get_price_calendar', [ $this, 'ajax_price_calendar' ] );

        // Fire hook để các module khác biết giá đã thay đổi
        add_action( 'rba_price_updated', [ $this, 'on_price_updated' ], 10, 4 );
    }

    // =========================================================================
    // WOOCOMMERCE CART PRICE — đọc từ rba_total trong cart item meta
    // =========================================================================

    /**
     * Set giá cart item từ rba_total được lưu khi add_to_cart.
     *
     * Cơ chế:
     * - ajax_add_to_cart tính giá đúng (seasonal) → lưu vào cart_item_data['rba_total']
     * - Hook này đọc rba_total và set_price() vào WC_Product object (chỉ trong memory)
     * - WC tính total dựa trên giá này → hiển thị và thanh toán đúng
     * - Không save() vào DB → không ảnh hưởng product gốc của Tourfic
     */
    public function set_cart_item_price( \WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( did_action( 'woocommerce_before_calculate_totals' ) > 1 ) return;

        foreach ( $cart->get_cart() as $cart_item ) {
            // Chỉ xử lý item có rba_total (do plugin add_to_cart tạo)
            $rba_total = (float) ( $cart_item['rba_total'] ?? 0 );
            if ( $rba_total <= 0 ) continue;

            $product = $cart_item['data'] ?? null;
            if ( ! $product instanceof \WC_Product ) continue;

            // set_price() chỉ trong memory — an toàn, không ghi DB
            $product->set_price( $rba_total );
        }
    }

    // =========================================================================
    // CORE PRICE METHODS — static, dùng ở mọi nơi
    // =========================================================================

    /**
     * Lấy base price từ Tourfic meta.
     * Tourfic lưu tại: tf_search_price (confirmed từ debug)
     */
    public static function get_base_price( int $room_id ): float {
        $sources = [ 'tf_search_price', '_tf_price' ];
        foreach ( $sources as $key ) {
            $val = get_post_meta( $room_id, $key, true );
            if ( $val !== '' && (float) $val > 0 ) return (float) $val;
        }
        // ACF fallback
        if ( function_exists( 'get_field' ) ) {
            $acf = get_field( 'room_base_price', $room_id );
            if ( $acf && (float) $acf > 0 ) return (float) $acf;
        }
        // tf_room_opt JSON fallback
        $opt = get_post_meta( $room_id, 'tf_room_opt', true );
        if ( $opt ) {
            $arr = is_array( $opt ) ? $opt : json_decode( $opt, true );
            foreach ( [ 'tf_price', 'price', 'tf_room_price', 'tf_search_price' ] as $k ) {
                if ( isset( $arr[$k] ) && (float) $arr[$k] > 0 ) return (float) $arr[$k];
            }
        }
        return 0.0;
    }

    /**
     * Giá cho 1 ngày cụ thể.
     */
    public static function get_price_for_date( int $room_id, string $date ): float {
        global $wpdb;

        // 1. Date override
        $date_price = $wpdb->get_var( $wpdb->prepare(
            "SELECT price FROM {$wpdb->prefix}rba_date_prices WHERE room_id = %d AND price_date = %s",
            $room_id, $date
        ) );
        if ( $date_price !== null ) return (float) $date_price;

        // 2. Seasonal price
        $season = $wpdb->get_row( $wpdb->prepare(
            "SELECT price_type, price_value FROM {$wpdb->prefix}rba_seasonal_prices
             WHERE room_id = %d AND date_from <= %s AND date_to >= %s
             ORDER BY priority ASC LIMIT 1",
            $room_id, $date, $date
        ) );

        $base = self::get_base_price( $room_id );
        if ( ! $season ) return $base;

        return $season->price_type === 'fixed'
            ? (float) $season->price_value
            : ( $base > 0 ? $base * ( 1 + (float) $season->price_value / 100 ) : 0.0 );
    }

    /**
     * Tổng giá cho khoảng ngày.
     */
    public static function calculate_total( int $room_id, string $check_in, string $check_out ): float {
        if ( ! $check_in || ! $check_out ) return 0.0;
        $total = 0.0;
        try {
            $date = new DateTime( $check_in );
            $end  = new DateTime( $check_out );
            while ( $date < $end ) {
                $total += self::get_price_for_date( $room_id, $date->format( 'Y-m-d' ) );
                $date->modify( '+1 day' );
            }
        } catch ( \Throwable $e ) { return 0.0; }
        return $total;
    }

    // =========================================================================
    // WC DISPLAY — chỉ hiển thị thêm info "N đêm", không can thiệp giá tính
    // =========================================================================

    /**
     * Thêm thông tin "N đêm" vào subtotal trong cart/checkout.
     * Không thay đổi giá — chỉ thay đổi text hiển thị.
     */
    public function display_nights_info( $subtotal, array $cart_item, string $cart_item_key ): string {
        try {
            $room_id   = (int) ( $cart_item['tf_room_id']  ?? $cart_item['room_id']  ?? 0 );
            $check_in  = (string) ( $cart_item['tf_check_in']  ?? $cart_item['check_in']  ?? '' );
            $check_out = (string) ( $cart_item['tf_check_out'] ?? $cart_item['check_out'] ?? '' );
            if ( ! $room_id || ! $check_in || ! $check_out ) return (string) $subtotal;

            $nights = (int) ( ( strtotime( $check_out ) - strtotime( $check_in ) ) / DAY_IN_SECONDS );
            if ( $nights <= 0 ) return (string) $subtotal;

            return $subtotal . ' <small style="color:#888;font-size:11px;display:block">'
                . $nights . ' đêm: ' . esc_html( $check_in ) . ' → ' . esc_html( $check_out )
                . '</small>';
        } catch ( \Throwable $e ) {
            return (string) $subtotal;
        }
    }

    /**
     * Hook rba_price_updated: sync giá mới vào tf_search_price
     */
    public function on_price_updated( int $room_id, string $from, string $to, float $price ): void {
        // Thông báo cho các module khác (KiotViet, OTA API...)
        // Không làm gì thêm ở đây vì seasonal price được tính runtime
    }

    // =========================================================================
    // ADMIN META BOX
    // =========================================================================

    public static function get_price_source_info( int $room_id ): array {
        $tf  = (float) get_post_meta( $room_id, 'tf_search_price', true );
        $tf2 = (float) get_post_meta( $room_id, '_tf_price', true );
        $acf = function_exists('get_field') ? (float) get_field( 'room_base_price', $room_id ) : 0;
        return [
            'sources'   => [
                'tf_search_price' => $tf,
                '_tf_price'       => $tf2,
                'room_base_price' => $acf,
            ],
            'effective' => self::get_base_price( $room_id ),
        ];
    }

    public function add_seasonal_metabox(): void {
        add_meta_box( 'rba_seasonal_prices', 'Giá theo Mùa & Ngày Đặc Biệt', [ $this, 'render_seasonal_metabox' ], 'tf_room', 'normal', 'high' );
    }

    public function render_seasonal_metabox( \WP_Post $post ): void {
        global $wpdb;
        wp_nonce_field( 'rba_seasonal_nonce', 'rba_seasonal_nonce_field' );
        $info = self::get_price_source_info( $post->ID );
        $eff  = $info['effective'];
        $seasons     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}rba_seasonal_prices WHERE room_id = %d ORDER BY priority, date_from", $post->ID ) );
        $date_prices = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}rba_date_prices WHERE room_id = %d ORDER BY price_date LIMIT 60", $post->ID ) );
        ?>
        <!-- Price source info -->
        <div style="background:<?php echo $eff>0?'#e8f5e9':'#fce4ec'; ?>;border:1px solid <?php echo $eff>0?'#a5d6a7':'#ef9a9a'; ?>;border-radius:6px;padding:12px;margin-bottom:14px;font-size:13px">
            <?php if($eff>0): ?>
            <strong>Giá base:</strong> <strong style="color:#2e7d32;font-size:15px"><?php echo number_format($eff,0,',','.'); ?> VNĐ/đêm</strong>
            <span style="color:#888;margin-left:10px;font-size:11px">
                (tf_search_price: <?php echo number_format($info['sources']['tf_search_price'],0,',','.'); ?> |
                _tf_price: <?php echo number_format($info['sources']['_tf_price'],0,',','.'); ?>)
            </span>
            <?php else: ?>
            <strong style="color:#c62828">⚠ Chưa có giá base!</strong>
            Điền giá vào trường "<strong>Price Per Night</strong>" trong tab Booking của Tourfic, hoặc điền vào ô bên dưới.
            <?php endif; ?>
        </div>

        <!-- Quick price override -->
        <div style="background:#fff3e0;border:1px solid #ffb74d;border-radius:6px;padding:12px;margin-bottom:16px">
            <label style="font-weight:600">Đặt giá cơ bản/đêm (VNĐ) — sync vào Tourfic:</label><br>
            <input type="number" name="rba_base_price_override" value="<?php echo esc_attr($eff?:''); ?>"
                   style="width:180px;margin-top:6px" min="0" step="1000" placeholder="VD: 1700000">
            <span style="font-size:12px;color:#888;margin-left:8px">Sẽ sync vào tf_search_price khi Save</span>
        </div>

        <!-- Seasons table -->
        <h4 style="margin:0 0 8px">Giá theo Mùa</h4>
        <table class="widefat" style="margin-bottom:8px">
            <thead><tr>
                <th>Tên mùa</th><th style="width:115px">Từ ngày</th><th style="width:115px">Đến ngày</th>
                <th style="width:100px">Loại</th><th style="width:110px">Giá / %</th>
                <th style="width:60px">Ưu tiên</th><th style="width:50px"></th>
            </tr></thead>
            <tbody id="rba-seasons-body">
            <?php foreach($seasons as $i=>$s): ?>
            <tr>
                <td><input type="text"   name="rba_season[<?php echo $i;?>][name]"        value="<?php echo esc_attr($s->season_name);?>"  class="widefat" placeholder="Tên mùa"></td>
                <td><input type="date"   name="rba_season[<?php echo $i;?>][date_from]"   value="<?php echo esc_attr($s->date_from);?>"    class="widefat"></td>
                <td><input type="date"   name="rba_season[<?php echo $i;?>][date_to]"     value="<?php echo esc_attr($s->date_to);?>"      class="widefat"></td>
                <td><select name="rba_season[<?php echo $i;?>][price_type]" class="widefat">
                    <option value="fixed"   <?php selected($s->price_type,'fixed');?>>Cố định (VNĐ)</option>
                    <option value="percent" <?php selected($s->price_type,'percent');?>>% điều chỉnh</option>
                </select></td>
                <td><input type="number" name="rba_season[<?php echo $i;?>][price_value]" value="<?php echo esc_attr($s->price_value);?>"  class="widefat" step="0.01"></td>
                <td><input type="number" name="rba_season[<?php echo $i;?>][priority]"    value="<?php echo esc_attr($s->priority);?>"     class="widefat" min="1" max="99"></td>
                <td><button type="button" class="button button-small rba-rm-s" style="color:#c62828">✕</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="button" id="rba-add-s">+ Thêm mùa</button>

        <!-- Date price table -->
        <h4 style="margin:18px 0 8px">Giá Ngày Cụ Thể (Override)</h4>
        <table class="widefat" style="margin-bottom:8px">
            <thead><tr><th style="width:130px">Ngày</th><th style="width:160px">Giá (VNĐ)</th><th style="width:50px"></th></tr></thead>
            <tbody id="rba-dates-body">
            <?php foreach($date_prices as $j=>$dp): ?>
            <tr>
                <td><input type="date"   name="rba_date_price[<?php echo $j;?>][date]"  value="<?php echo esc_attr($dp->price_date);?>" class="widefat"></td>
                <td><input type="number" name="rba_date_price[<?php echo $j;?>][price]" value="<?php echo esc_attr($dp->price);?>"      class="widefat" min="0" step="1000"></td>
                <td><button type="button" class="button button-small rba-rm-d" style="color:#c62828">✕</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="button" id="rba-add-d">+ Thêm ngày</button>

        <script>
        (function($){
            var si=<?php echo count($seasons); ?>, di=<?php echo count($date_prices); ?>;
            $('#rba-add-s').on('click',function(){
                $('#rba-seasons-body').append('<tr><td><input type="text" name="rba_season['+si+'][name]" class="widefat" placeholder="Tên mùa"></td><td><input type="date" name="rba_season['+si+'][date_from]" class="widefat"></td><td><input type="date" name="rba_season['+si+'][date_to]" class="widefat"></td><td><select name="rba_season['+si+'][price_type]" class="widefat"><option value="fixed">Cố định (VNĐ)</option><option value="percent">% điều chỉnh</option></select></td><td><input type="number" name="rba_season['+si+'][price_value]" class="widefat" step="0.01" value="0"></td><td><input type="number" name="rba_season['+si+'][priority]" class="widefat" value="10" min="1" max="99"></td><td><button type="button" class="button button-small rba-rm-s" style="color:#c62828">✕</button></td></tr>'); si++;
            });
            $('#rba-add-d').on('click',function(){
                $('#rba-dates-body').append('<tr><td><input type="date" name="rba_date_price['+di+'][date]" class="widefat"></td><td><input type="number" name="rba_date_price['+di+'][price]" class="widefat" min="0" step="1000" placeholder="1700000"></td><td><button type="button" class="button button-small rba-rm-d" style="color:#c62828">✕</button></td></tr>'); di++;
            });
            $(document).on('click','.rba-rm-s,.rba-rm-d',function(){ $(this).closest('tr').remove(); });
        })(jQuery);
        </script>
        <?php
    }

    public function save_seasonal_prices( int $post_id ): void {
        if ( ! isset( $_POST['rba_seasonal_nonce_field'] )
             || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rba_seasonal_nonce_field'] ) ), 'rba_seasonal_nonce' ) ) return;
        if ( wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ) return;
        global $wpdb;

        // Base price override
        $bp = (float) ( $_POST['rba_base_price_override'] ?? 0 );
        if ( $bp > 0 ) {
            update_post_meta( $post_id, 'tf_search_price', $bp );
            update_post_meta( $post_id, '_tf_price', $bp );
            if ( function_exists('update_field') ) update_field( 'room_base_price', $bp, $post_id );
            // Sync vào WC product nếu có
            $product_id = (int) get_post_meta( $post_id, '_tf_wc_product_id', true );
            if ( $product_id ) {
                $p = wc_get_product( $product_id );
                if ( $p ) { $p->set_regular_price($bp); $p->set_price($bp); $p->save(); }
            }
            do_action( 'rba_price_updated', $post_id, '', '', $bp );
        }

        // Seasons
        $wpdb->delete( $wpdb->prefix.'rba_seasonal_prices', ['room_id'=>$post_id], ['%d'] );
        foreach ( (array)wp_unslash($_POST['rba_season']??[]) as $s ) {
            if ( empty($s['date_from']) || empty($s['date_to']) ) continue;
            $wpdb->insert( $wpdb->prefix.'rba_seasonal_prices', [
                'room_id'     => $post_id,
                'season_name' => sanitize_text_field($s['name']??''),
                'date_from'   => sanitize_text_field($s['date_from']),
                'date_to'     => sanitize_text_field($s['date_to']),
                'price_type'  => in_array($s['price_type']??'fixed',['fixed','percent'],true)?$s['price_type']:'fixed',
                'price_value' => (float)($s['price_value']??0),
                'priority'    => max(1,(int)($s['priority']??10)),
            ], ['%d','%s','%s','%s','%s','%f','%d'] );
        }

        // Date prices
        $wpdb->delete( $wpdb->prefix.'rba_date_prices', ['room_id'=>$post_id], ['%d'] );
        foreach ( (array)wp_unslash($_POST['rba_date_price']??[]) as $dp ) {
            if ( empty($dp['date']) || !isset($dp['price']) ) continue;
            $wpdb->insert( $wpdb->prefix.'rba_date_prices', [
                'room_id'    => $post_id,
                'price_date' => sanitize_text_field($dp['date']),
                'price'      => (float)$dp['price'],
            ], ['%d','%s','%f'] );
        }
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    public function ajax_price_calendar(): void {
        if ( ob_get_level() ) ob_clean();
        if ( ob_get_length() ) ob_clean();
        check_ajax_referer( 'rba_public_nonce', 'nonce' );
        $room_id = absint( $_POST['room_id'] ?? 0 );
        $year    = (int) ( $_POST['year']    ?? gmdate('Y') );
        $month   = (int) ( $_POST['month']   ?? gmdate('m') );
        if ( ! $room_id ) wp_send_json_error( 'Missing room_id' );

        $days   = cal_days_in_month( CAL_GREGORIAN, $month, $year );
        $prices = [];
        $min_p  = PHP_FLOAT_MAX;
        $max_p  = 0.0;

        for ( $d = 1; $d <= $days; $d++ ) {
            $date     = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $price    = self::get_price_for_date( $room_id, $date );
            $prices[] = ['date' => $date, 'price' => $price];
            if ( $price > 0 ) { $min_p = min($min_p,$price); $max_p = max($max_p,$price); }
        }

        $range = $max_p > $min_p ? $max_p - $min_p : 0;
        foreach ( $prices as &$p ) {
            if ( $p['price'] <= 0 ) { $p['level'] = 'base'; continue; }
            if ( $range < 1000 ) { $p['level'] = 'mid'; continue; }
            $p['level'] = $p['price'] <= $min_p + $range * 0.33 ? 'low'
                        : ( $p['price'] <= $min_p + $range * 0.66 ? 'mid' : 'high' );
        }
        unset($p);

        // weekday của ngày 1 tháng đó (1=Mon theo ISO)
        $ts      = mktime(0,0,0,$month,1,$year);
        $weekday = (int) date('N', $ts); // 1=Mon, 7=Sun

        wp_send_json_success([
            'prices'   => $prices,
            'year'     => $year,
            'month'    => $month,
            'label'    => date_i18n('F Y', $ts),
            'days'     => $days,
            'weekday'  => $weekday,
            'currency' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '₫',
            'base'     => self::get_base_price($room_id),
        ]);
    }
}

new RBA_Seasonal_Price();
