<?php
/**
 * RBA_Seasonal_Price
 *
 * Engine giá theo mùa và ngày cụ thể.
 *
 * Logic ưu tiên (từ cao → thấp):
 *  1. Date Price (ngày cụ thể) – priority = 1
 *  2. Seasonal Price (khoảng ngày) – priority theo cột
 *  3. Base price của phòng (Tourfic default)
 */
defined( 'ABSPATH' ) || exit;

class RBA_Seasonal_Price {

    public function __construct() {
        // Hook vào Tourfic price calculation
        add_filter( 'tf_hotel_room_price',        [ $this, 'apply_price' ], 20, 4 );
        add_filter( 'tf_room_price_per_night',    [ $this, 'apply_price' ], 20, 4 );
        add_filter( 'tf_booking_total_price',     [ $this, 'recalculate_total' ], 20, 3 );

        // Admin: thêm meta box giá theo mùa
        add_action( 'add_meta_boxes',             [ $this, 'add_seasonal_metabox' ] );
        add_action( 'save_post_tf_room',          [ $this, 'save_seasonal_prices' ] );

        // AJAX: lấy giá theo ngày (cho booking form)
        add_action( 'wp_ajax_rba_get_price_calendar',        [ $this, 'ajax_price_calendar' ] );
        add_action( 'wp_ajax_nopriv_rba_get_price_calendar', [ $this, 'ajax_price_calendar' ] );

        // Enqueue scripts cho price calendar
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // CORE: Tính giá
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Trả về giá/đêm cho room_id vào ngày cụ thể.
     */
    public static function get_price_for_date( int $room_id, string $date ): ?float {
        global $wpdb;

        // ── 1. Kiểm tra Date Price (override cụ thể) ─────────────────────────
        $date_price = $wpdb->get_var( $wpdb->prepare(
            "SELECT price FROM {$wpdb->prefix}rba_date_prices
             WHERE room_id = %d AND price_date = %s",
            $room_id, $date
        ) );
        if ( $date_price !== null ) {
            return (float) $date_price;
        }

        // ── 2. Kiểm tra Seasonal Price ────────────────────────────────────────
        $season = $wpdb->get_row( $wpdb->prepare(
            "SELECT price_type, price_value
             FROM {$wpdb->prefix}rba_seasonal_prices
             WHERE room_id  = %d
               AND date_from <= %s
               AND date_to   >= %s
             ORDER BY priority ASC
             LIMIT 1",
            $room_id, $date, $date
        ) );

        // ── 3. Base price từ Tourfic meta ─────────────────────────────────────
        $base_price = (float) ( get_post_meta( $room_id, '_tf_price', true )
                             ?: get_field( 'room_base_price', $room_id )
                             ?: 0 );

        if ( ! $season ) return $base_price;

        if ( $season->price_type === 'fixed' ) {
            return (float) $season->price_value;
        }

        // percent: điều chỉnh +/- % so với base
        return $base_price * ( 1 + ( (float) $season->price_value / 100 ) );
    }

    /**
     * Tính tổng giá cho 1 khoảng ngày (check-in đến check-out).
     */
    public static function calculate_total( int $room_id, string $check_in, string $check_out ): float {
        $total   = 0.0;
        $date    = new DateTime( $check_in );
        $end     = new DateTime( $check_out );

        while ( $date < $end ) {
            $total += self::get_price_for_date( $room_id, $date->format( 'Y-m-d' ) );
            $date->modify( '+1 day' );
        }
        return $total;
    }

    /**
     * Lấy price map (ngày → giá) trong 1 tháng, dùng cho calendar.
     */
    public static function get_monthly_prices( int $room_id, int $year, int $month ): array {
        $result = [];
        $days   = cal_days_in_month( CAL_GREGORIAN, $month, $year );

        for ( $d = 1; $d <= $days; $d++ ) {
            $date          = sprintf( '%04d-%02d-%02d', $year, $month, $d );
            $result[$date] = self::get_price_for_date( $room_id, $date );
        }
        return $result;
    }

    // ────────────────────────────────────────────────────────────────────────
    // HOOKS: Gắn vào Tourfic price filters
    // ────────────────────────────────────────────────────────────────────────

    public function apply_price( $price, $room_id = null, $check_in = null, $check_out = null ) {
        if ( ! $room_id || ! $check_in || ! $check_out ) return $price;

        $calculated = self::calculate_total( (int) $room_id, $check_in, $check_out );
        return $calculated > 0 ? $calculated : $price;
    }

    public function recalculate_total( $total, $booking_data, $room_id ) {
        if ( empty( $booking_data['check_in'] ) || empty( $booking_data['check_out'] ) ) {
            return $total;
        }
        $calculated = self::calculate_total(
            (int) $room_id,
            $booking_data['check_in'],
            $booking_data['check_out']
        );
        return $calculated > 0 ? $calculated : $total;
    }

    // ────────────────────────────────────────────────────────────────────────
    // ADMIN: Meta box quản lý giá theo mùa
    // ────────────────────────────────────────────────────────────────────────

    public function add_seasonal_metabox(): void {
        add_meta_box(
            'rba_seasonal_prices',
            '🗓️ Giá theo Mùa & Ngày Đặc Biệt',
            [ $this, 'render_seasonal_metabox' ],
            'tf_room',
            'normal',
            'high'
        );
    }

    public function render_seasonal_metabox( \WP_Post $post ): void {
        global $wpdb;
        wp_nonce_field( 'rba_seasonal_nonce', 'rba_seasonal_nonce_field' );

        $seasons = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rba_seasonal_prices WHERE room_id = %d ORDER BY priority, date_from",
            $post->ID
        ) );

        $date_prices = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rba_date_prices WHERE room_id = %d ORDER BY price_date LIMIT 50",
            $post->ID
        ) );
        ?>
        <div id="rba-seasonal-wrapper">
            <h4>Giá theo Mùa (Season Pricing)</h4>
            <table class="widefat" id="rba-seasons-table">
                <thead>
                    <tr>
                        <th>Tên mùa</th>
                        <th>Từ ngày</th>
                        <th>Đến ngày</th>
                        <th>Loại giá</th>
                        <th>Giá / %</th>
                        <th>Lưu tối thiểu</th>
                        <th>Ưu tiên</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="rba-seasons-rows">
                <?php foreach ( $seasons as $s ) : ?>
                    <tr class="rba-season-row" data-id="<?php echo $s->id; ?>">
                        <td><input type="text"   name="rba_season[<?php echo $s->id; ?>][name]"       value="<?php echo esc_attr($s->season_name); ?>" class="regular-text"></td>
                        <td><input type="date"   name="rba_season[<?php echo $s->id; ?>][from]"       value="<?php echo $s->date_from; ?>"></td>
                        <td><input type="date"   name="rba_season[<?php echo $s->id; ?>][to]"         value="<?php echo $s->date_to; ?>"></td>
                        <td>
                            <select name="rba_season[<?php echo $s->id; ?>][type]">
                                <option value="fixed"   <?php selected($s->price_type,'fixed'); ?>>Cố định (VNĐ)</option>
                                <option value="percent" <?php selected($s->price_type,'percent'); ?>>% điều chỉnh</option>
                            </select>
                        </td>
                        <td><input type="number" name="rba_season[<?php echo $s->id; ?>][value]"      value="<?php echo $s->price_value; ?>" step="0.01"></td>
                        <td><input type="number" name="rba_season[<?php echo $s->id; ?>][min_nights]" value="<?php echo $s->min_nights; ?>" min="1" style="width:60px"></td>
                        <td><input type="number" name="rba_season[<?php echo $s->id; ?>][priority]"   value="<?php echo $s->priority; ?>"   min="1" style="width:60px"></td>
                        <td><button type="button" class="button rba-delete-season" data-id="<?php echo $s->id; ?>">Xóa</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="button button-secondary" id="rba-add-season">+ Thêm Mùa</button>

            <hr>
            <h4>Giá Ngày Cụ Thể (Date Override)</h4>
            <p class="description">Ghi đè giá cho ngày cụ thể (ưu tiên cao nhất, bỏ qua mùa).</p>
            <table class="widefat" id="rba-dates-table">
                <thead>
                    <tr><th>Ngày</th><th>Giá</th><th>Ghi chú</th><th></th></tr>
                </thead>
                <tbody id="rba-dates-rows">
                <?php foreach ( $date_prices as $dp ) : ?>
                    <tr class="rba-date-row" data-id="<?php echo $dp->id; ?>">
                        <td><input type="date"   name="rba_date_price[<?php echo $dp->id; ?>][date]"  value="<?php echo $dp->price_date; ?>"></td>
                        <td><input type="number" name="rba_date_price[<?php echo $dp->id; ?>][price]" value="<?php echo $dp->price; ?>" step="1000"></td>
                        <td><input type="text"   name="rba_date_price[<?php echo $dp->id; ?>][note]"  value="<?php echo esc_attr($dp->note); ?>" class="regular-text"></td>
                        <td><button type="button" class="button rba-delete-date" data-id="<?php echo $dp->id; ?>">Xóa</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="button button-secondary" id="rba-add-date">+ Thêm Ngày</button>

            <input type="hidden" id="rba_deleted_seasons" name="rba_deleted_seasons" value="">
            <input type="hidden" id="rba_deleted_dates"   name="rba_deleted_dates"   value="">
        </div>

        <style>
        #rba-seasonal-wrapper table td input { max-width: 130px; }
        #rba-seasonal-wrapper h4 { margin-top: 16px; }
        </style>
        <script>
        (function($){
            let seasonIdx = 9000, dateIdx = 9000;

            $('#rba-add-season').on('click', function() {
                seasonIdx++;
                $('#rba-seasons-rows').append(`
                    <tr class="rba-season-row">
                        <td><input type="text"   name="rba_season[new_${seasonIdx}][name]"       class="regular-text" placeholder="VD: Mùa hè 2025"></td>
                        <td><input type="date"   name="rba_season[new_${seasonIdx}][from]"></td>
                        <td><input type="date"   name="rba_season[new_${seasonIdx}][to]"></td>
                        <td><select name="rba_season[new_${seasonIdx}][type]"><option value="fixed">Cố định</option><option value="percent">%</option></select></td>
                        <td><input type="number" name="rba_season[new_${seasonIdx}][value]"      step="0.01"></td>
                        <td><input type="number" name="rba_season[new_${seasonIdx}][min_nights]" value="1" min="1" style="width:60px"></td>
                        <td><input type="number" name="rba_season[new_${seasonIdx}][priority]"   value="10" min="1" style="width:60px"></td>
                        <td></td>
                    </tr>`);
            });

            $('#rba-add-date').on('click', function() {
                dateIdx++;
                $('#rba-dates-rows').append(`
                    <tr class="rba-date-row">
                        <td><input type="date"   name="rba_date_price[new_${dateIdx}][date]"></td>
                        <td><input type="number" name="rba_date_price[new_${dateIdx}][price]" step="1000"></td>
                        <td><input type="text"   name="rba_date_price[new_${dateIdx}][note]"  class="regular-text"></td>
                        <td></td>
                    </tr>`);
            });

            $(document).on('click', '.rba-delete-season', function() {
                const id = $(this).data('id');
                if (id) {
                    const del = $('#rba_deleted_seasons');
                    del.val(del.val() ? del.val() + ',' + id : id);
                }
                $(this).closest('tr').remove();
            });

            $(document).on('click', '.rba-delete-date', function() {
                const id = $(this).data('id');
                if (id) {
                    const del = $('#rba_deleted_dates');
                    del.val(del.val() ? del.val() + ',' + id : id);
                }
                $(this).closest('tr').remove();
            });
        })(jQuery);
        </script>
        <?php
    }

    public function save_seasonal_prices( int $post_id ): void {
        if ( ! isset( $_POST['rba_seasonal_nonce_field'] )
             || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rba_seasonal_nonce_field'] ) ), 'rba_seasonal_nonce' ) ) return;
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

        global $wpdb;
        $sp_table = $wpdb->prefix . 'rba_seasonal_prices';
        $dp_table = $wpdb->prefix . 'rba_date_prices';

        // Xóa rows đã đánh dấu xóa
        foreach ( [ 'rba_deleted_seasons', 'rba_deleted_dates' ] as $field ) {
            $deleted = array_filter( array_map( 'intval', explode( ',', $_POST[$field] ?? '' ) ) );
            foreach ( $deleted as $del_id ) {
                $table = $field === 'rba_deleted_seasons' ? $sp_table : $dp_table;
                $wpdb->delete( $table, [ 'id' => $del_id, 'room_id' => $post_id ], [ '%d', '%d' ] );
            }
        }

        // Lưu seasons
        if ( ! empty( $_POST['rba_season'] ) && is_array( $_POST['rba_season'] ) ) {
            foreach ( $_POST['rba_season'] as $key => $s ) {
                $data = [
                    'room_id'     => $post_id,
                    'season_name' => sanitize_text_field( $s['name'] ),
                    'date_from'   => sanitize_text_field( $s['from'] ),
                    'date_to'     => sanitize_text_field( $s['to'] ),
                    'price_type'  => in_array( $s['type'], [ 'fixed', 'percent' ] ) ? $s['type'] : 'fixed',
                    'price_value' => (float) $s['value'],
                    'min_nights'  => (int) $s['min_nights'],
                    'priority'    => (int) $s['priority'],
                ];

                if ( str_starts_with( (string) $key, 'new_' ) ) {
                    $wpdb->insert( $sp_table, $data );
                } else {
                    $wpdb->update( $sp_table, $data, [ 'id' => (int) $key, 'room_id' => $post_id ] );
                }
            }
        }

        // Lưu date prices
        if ( ! empty( $_POST['rba_date_price'] ) && is_array( $_POST['rba_date_price'] ) ) {
            foreach ( $_POST['rba_date_price'] as $key => $dp ) {
                $data = [
                    'room_id'    => $post_id,
                    'price_date' => sanitize_text_field( $dp['date'] ),
                    'price'      => (float) $dp['price'],
                    'note'       => sanitize_text_field( $dp['note'] ),
                ];

                if ( str_starts_with( (string) $key, 'new_' ) ) {
                    $wpdb->replace( $dp_table, $data );
                } else {
                    $wpdb->update( $dp_table, $data, [ 'id' => (int) $key, 'room_id' => $post_id ] );
                }
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // AJAX: Price calendar cho frontend
    // ────────────────────────────────────────────────────────────────────────

    public function ajax_price_calendar(): void {
        check_ajax_referer( 'rba_public_nonce', 'nonce' );

        $room_id = (int) ( $_POST['room_id'] ?? 0 );
        $year    = (int) ( $_POST['year']    ?? gmdate( 'Y' ) );
        $month   = (int) ( $_POST['month']   ?? gmdate( 'm' ) );

        if ( ! $room_id ) wp_send_json_error( 'Invalid room' );

        // Lấy cả prices + availability
        $prices = self::get_monthly_prices( $room_id, $year, $month );
        $avail  = $this->get_monthly_availability( $room_id, $year, $month );

        wp_send_json_success( [
            'prices'       => $prices,
            'availability' => $avail,
        ] );
    }

    private function get_monthly_availability( int $room_id, int $year, int $month ): array {
        global $wpdb;
        $from = sprintf( '%04d-%02d-01', $year, $month );
        $to   = date( 'Y-m-t', strtotime( $from ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT avail_date, (total_rooms - booked_rooms) as available, blocked
             FROM {$wpdb->prefix}rba_availability
             WHERE room_id = %d AND avail_date BETWEEN %s AND %s",
            $room_id, $from, $to
        ), ARRAY_A );

        $result = [];
        foreach ( $rows as $r ) {
            $result[ $r['avail_date'] ] = [
                'available' => (int) $r['available'],
                'blocked'   => (bool) $r['blocked'],
            ];
        }
        return $result;
    }

    public function enqueue_scripts(): void {
        if ( ! is_singular( 'tf_room' ) && ! is_singular( 'tf_hotel' ) ) return;
        wp_localize_script( 'jquery', 'rba_price_config', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'rba_public_nonce' ),
        ] );
    }
}

new RBA_Seasonal_Price();
