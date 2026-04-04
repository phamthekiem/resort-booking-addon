<?php
/**
 * RBA_PMS — PMS Dashboard Router & Data Layer v1.7.3
 *
 * Fix:
 * - Đọc số phòng vật lý từ ACF field_room_quantity (không phải count posts)
 * - Check-in khi order = on-hold/pending; check-out khi completed
 * - get_bookings() fix: WC search, status filter, meta query đúng
 * - get_room_status_map() fix: quantity, occupied logic đúng
 * - ajax_get_reports() thêm xuất Excel
 *
 * @package ResortBookingAddon
 * @since   1.7.3
 */
defined( 'ABSPATH' ) || exit;

class RBA_PMS {

    const ENDPOINT  = 'pms';
    const QUERY_VAR = 'rba_pms_page';
    const TEMPLATE  = 'pms-dashboard.php';

    private static array $pages = [
        ''         => 'dashboard',
        'bookings' => 'bookings',
        'checkin'  => 'checkin',
        'rooms'    => 'rooms',
        'invoices' => 'invoices',
        'reports'  => 'reports',
    ];

    // WC statuses coi là "còn hiệu lực" (chưa hủy)
    private static array $ACTIVE_STATUSES = [
        'pending', 'on-hold', 'processing', 'completed',
    ];

    // WC statuses coi là "đang giữ phòng" (checkin logic)
    private static array $HOLDING_STATUSES = [
        'on-hold', 'pending', 'processing',
    ];

    public function __construct() {
        add_action( 'init',              [ $this, 'register_endpoint' ] );
        add_filter( 'query_vars',        [ $this, 'add_query_var' ] );
        add_action( 'template_redirect', [ $this, 'intercept_template' ] );

        $ajax_actions = [
            'rba_pms_get_bookings',
            'rba_pms_get_checkins_today',
            'rba_pms_update_booking_status',
            'rba_pms_do_checkin',
            'rba_pms_do_checkout',
            'rba_pms_get_room_status',
            'rba_pms_update_room_status',
            'rba_pms_get_invoice',
            'rba_pms_get_reports',
            'rba_pms_quick_search',
            'rba_pms_export_excel',
            'rba_pms_set_deposit',
            'rba_pms_collect_remaining',
        ];
        foreach ( $ajax_actions as $action ) {
            add_action( "wp_ajax_{$action}",
                [ $this, str_replace( 'rba_pms_', 'ajax_', $action ) ] );
        }

        register_activation_hook(
            RBA_PATH . 'resort-booking-addon.php',
            [ __CLASS__, 'flush_rewrite' ]
        );
    }

    // =========================================================================
    // ROUTING
    // =========================================================================

    public function register_endpoint(): void {
        add_rewrite_rule( '^pms/([a-z]*)/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
        add_rewrite_rule( '^pms/?$',
            'index.php?' . self::QUERY_VAR . '=', 'top' );
    }

    public function add_query_var( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function flush_rewrite(): void {
        ( new self() )->register_endpoint();
        flush_rewrite_rules();
    }

    public function intercept_template(): void {
        $page = get_query_var( self::QUERY_VAR, null );
        if ( $page === null ) return;

        if ( ! RBA_PMS_Role::current_user_can_pms() ) {
            wp_safe_redirect( wp_login_url( home_url( '/pms/' ) ) );
            exit;
        }

        $template = RBA_PATH . 'templates/' . self::TEMPLATE;
        if ( file_exists( $template ) ) {
            define( 'RBA_PMS_CURRENT_PAGE',
                self::$pages[ $page ] ?? 'dashboard' );
            include $template;
            exit;
        }
        wp_die( 'PMS template not found.' );
    }

    // =========================================================================
    // DATA HELPERS
    // =========================================================================

    /**
     * Lấy số phòng vật lý từ ACF field_room_quantity.
     * Fallback: tf_search_num-room → 1
     */
    public static function get_room_quantity( int $room_id ): int {
        // ACF (field key hoặc field name)
        if ( function_exists( 'get_field' ) ) {
            $qty = (int) get_field( 'room_quantity', $room_id );
            if ( $qty > 0 ) return $qty;
        }
        // Post meta trực tiếp
        $qty = (int) get_post_meta( $room_id, 'room_quantity', true );
        if ( $qty > 0 ) return $qty;

        $qty = (int) get_post_meta( $room_id, '_tf_room_quantity', true );
        if ( $qty > 0 ) return $qty;

        $qty = (int) get_post_meta( $room_id, 'tf_search_num-room', true );
        return $qty > 0 ? $qty : 1;
    }

    /**
     * Lấy danh sách bookings — fix wc_get_orders với meta query đúng.
     */
    public static function get_bookings( array $args = [] ): array {
        $args = wp_parse_args( $args, [
            'limit'     => 30,
            'offset'    => 0,
            'status'    => '',   // '' = all active, hoặc tên status cụ thể
            'search'    => '',
            'check_in'  => '',
            'room_id'   => 0,
            'date_from' => '',
            'date_to'   => '',
            'order'     => 'DESC',
        ] );

        // Build WC status list
        if ( $args['status'] !== '' ) {
            $wc_statuses = [ 'wc-' . ltrim( $args['status'], 'wc-' ) ];
        } else {
            $wc_statuses = array_map(
                fn( $s ) => 'wc-' . $s,
                self::$ACTIVE_STATUSES
            );
            $wc_statuses[] = 'wc-cancelled';
            $wc_statuses[] = 'wc-refunded';
        }

        $wc_args = [
            'type'    => 'shop_order',
            'status'  => $wc_statuses,
            'limit'   => $args['limit'],
            'offset'  => $args['offset'],
            'orderby' => 'date',
            'order'   => $args['order'],
        ];

        if ( $args['date_from'] ) {
            $to = $args['date_to'] ?: gmdate( 'Y-m-d' );
            $wc_args['date_created'] = $args['date_from'] . '...' . $to;
        }

        // Tìm kiếm: nếu là số → tìm theo order ID
        if ( $args['search'] ) {
            $q = trim( $args['search'] );
            if ( ctype_digit( $q ) ) {
                $wc_args['post__in'] = [ (int) $q ];
            } else {
                $wc_args['search'] = $q;
            }
        }

        $orders  = wc_get_orders( $wc_args );
        $results = [];

        foreach ( $orders as $order ) {
            // Fallback: đọc từ order meta (Tourfic lưu ở đây, orders cũ)
            $ord_room_id   = absint( $order->get_meta('tf_room_id')  ?: $order->get_meta('room_id') );
            $ord_check_in  = (string)($order->get_meta('tf_check_in')  ?: $order->get_meta('check_in'));
            $ord_check_out = (string)($order->get_meta('tf_check_out') ?: $order->get_meta('check_out'));

            $found = false;
            foreach ( $order->get_items() as $item ) {
                $room_id = absint(
                    $item->get_meta('tf_room_id') ?: $item->get_meta('room_id') ?: $ord_room_id
                );
                if ( ! $room_id ) continue;
                if ( get_post_type($room_id) !== 'tf_room' ) continue;
                if ( $args['room_id'] && (int)$args['room_id'] !== $room_id ) continue;

                $check_in  = (string)($item->get_meta('tf_check_in')  ?: $item->get_meta('check_in')  ?: $ord_check_in);
                $check_out = (string)($item->get_meta('tf_check_out') ?: $item->get_meta('check_out') ?: $ord_check_out);
                if ( $args['check_in'] && $check_in !== $args['check_in'] ) continue;

                $nights    = ($check_in && $check_out) ? max(0,(int)(( strtotime($check_out) - strtotime($check_in) ) / DAY_IN_SECONDS)) : 0;
                $rba_total = (float)($item->get_meta('rba_total') ?: $order->get_meta('_rba_total') ?: $order->get_total());
                $wc_status = $order->get_status();
                $dep_paid  = (float)$order->get_meta('_rba_deposit_paid');
                $dep_total = (float)$order->get_meta('_rba_deposit_total');

                $results[] = [
                    'order_id'       => $order->get_id(),
                    'order_status'   => $wc_status,
                    'order_date'     => $order->get_date_created()?->date('Y-m-d H:i') ?? '',
                    'room_id'        => $room_id,
                    'room_name'      => get_the_title($room_id),
                    'check_in'       => $check_in,
                    'check_out'      => $check_out,
                    'nights'         => $nights,
                    'guest_name'     => trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),
                    'guest_phone'    => $order->get_billing_phone(),
                    'guest_email'    => $order->get_billing_email(),
                    'total'          => (float)$order->get_total(),
                    'total_fmt'      => number_format((float)$order->get_total(),0,',','.'),
                    'payment_method' => $order->get_payment_method_title(),
                    'adults'         => (int)($item->get_meta('adults')   ?: $order->get_meta('adults')   ?: 1),
                    'children'       => (int)($item->get_meta('children') ?: $order->get_meta('children') ?: 0),
                    'rba_total'      => $rba_total,
                    'pms_status'     => $order->get_meta('_rba_pms_status') ?: self::derive_pms_status($wc_status,$check_in,$check_out),
                    'checkin_time'   => $order->get_meta('_rba_checkin_time')  ?: '',
                    'checkout_time'  => $order->get_meta('_rba_checkout_time') ?: '',
                    'checkin_by'     => $order->get_meta('_rba_checkin_by')    ?: '',
                    'deposit_paid'   => $dep_paid,
                    'deposit_total'  => $dep_total,
                    'remaining'      => $dep_total > 0 ? max(0,$dep_total-$dep_paid) : 0,
                    'is_deposit'     => $dep_total > 0,
                ];
                $found = true;
            }
            // Order do Tourfic tạo (không có item với room_id)
            if (!$found && $ord_room_id && $ord_check_in && $ord_check_out) {
                if ($args['room_id'] && (int)$args['room_id'] !== $ord_room_id) continue;
                $nights    = max(0,(int)(( strtotime($ord_check_out)-strtotime($ord_check_in))/DAY_IN_SECONDS));
                $wc_status = $order->get_status();
                $results[] = [
                    'order_id'=>$order->get_id(), 'order_status'=>$wc_status,
                    'order_date'=>$order->get_date_created()?->date('Y-m-d H:i')??'',
                    'room_id'=>$ord_room_id, 'room_name'=>get_the_title($ord_room_id),
                    'check_in'=>$ord_check_in, 'check_out'=>$ord_check_out, 'nights'=>$nights,
                    'guest_name'=>trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),
                    'guest_phone'=>$order->get_billing_phone(), 'guest_email'=>$order->get_billing_email(),
                    'total'=>(float)$order->get_total(),
                    'total_fmt'=>number_format((float)$order->get_total(),0,',','.'),
                    'payment_method'=>$order->get_payment_method_title(),
                    'adults'=>(int)($order->get_meta('adults')?:1), 'children'=>(int)($order->get_meta('children')?:0),
                    'rba_total'=>(float)$order->get_total(),
                    'pms_status'=>$order->get_meta('_rba_pms_status')?:self::derive_pms_status($wc_status,$ord_check_in,$ord_check_out),
                    'checkin_time'=>$order->get_meta('_rba_checkin_time')?:'',
                    'checkout_time'=>$order->get_meta('_rba_checkout_time')?:'',
                    'checkin_by'=>$order->get_meta('_rba_checkin_by')?:'',
                    'deposit_paid'=>0,'deposit_total'=>0,'remaining'=>0,'is_deposit'=>false,
                ];
            }
        }

        return $results;

    }

    /**
     * PMS status logic:
     * - on-hold / pending  = giữ phòng (holding)
     * - processing         = đang ở (inhouse hoặc upcoming)
     * - completed          = đã check-out
     * - cancelled/refunded = hủy
     */
    public static function derive_pms_status( string $wc_status, string $check_in, string $check_out ): string {
        if ( in_array( $wc_status, [ 'cancelled', 'refunded', 'failed' ], true ) ) {
            return 'cancelled';
        }
        if ( $wc_status === 'completed' ) {
            return 'checked_out';
        }

        $today = current_time( 'Y-m-d' );

        if ( in_array( $wc_status, [ 'on-hold', 'pending' ], true ) ) {
            // Đặt cọc / giữ phòng chưa thanh toán đủ
            if ( $check_in === $today )  return 'checkin_today';
            if ( $check_in > $today )    return 'holding';     // sắp tới, chưa TT
            return 'holding';
        }

        // processing = đã xác nhận
        if ( $check_in === $today )                                    return 'checkin_today';
        if ( $check_out === $today )                                   return 'checkout_today';
        if ( $check_in > $today )                                      return 'upcoming';
        if ( $check_in < $today && $check_out > $today )               return 'inhouse';
        if ( $check_out <= $today )                                    return 'checked_out';

        return 'confirmed';
    }

    public static function get_today_activity(): array {
        $today  = current_time( 'Y-m-d' );
        $all    = self::get_bookings( [ 'limit' => 500 ] );
        $result = [ 'checkins' => [], 'checkouts' => [], 'inhouse' => [], 'holding' => [] ];

        foreach ( $all as $b ) {
            if ( $b['check_in'] === $today
                 && ! in_array( $b['order_status'], [ 'cancelled', 'refunded', 'failed' ], true )
            ) {
                $result['checkins'][] = $b;
            }
            if ( $b['check_out'] === $today
                 && in_array( $b['order_status'], [ 'processing', 'on-hold' ], true )
            ) {
                $result['checkouts'][] = $b;
            }
            if ( $b['check_in'] < $today && $b['check_out'] > $today
                 && in_array( $b['order_status'], self::$ACTIVE_STATUSES, true )
            ) {
                $result['inhouse'][] = $b;
            }
            if ( $b['pms_status'] === 'holding' ) {
                $result['holding'][] = $b;
            }
        }

        return $result;
    }

    /**
     * Trạng thái tất cả phòng — dùng quantity vật lý từ ACF.
     */
    public static function get_room_status_map(): array {
        $today = current_time( 'Y-m-d' );
        $rooms = get_posts( [
            'post_type'      => 'tf_room',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        // Map: room_id → booking đang active
        $active = self::get_bookings( [ 'limit' => 500 ] );
        $room_booking_map = [];
        foreach ( $active as $b ) {
            if ( $b['check_in'] <= $today && $b['check_out'] > $today
                 && ! in_array( $b['order_status'], [ 'cancelled', 'refunded', 'failed' ], true )
            ) {
                // Nếu đã có rồi thì giữ booking mới nhất
                $rid = $b['room_id'];
                if ( ! isset( $room_booking_map[ $rid ] ) ) {
                    $room_booking_map[ $rid ] = $b;
                }
            }
        }

        $result = [];
        foreach ( $rooms as $room ) {
            $rid        = $room->ID;
            $quantity   = self::get_room_quantity( $rid );   // ← SỐ PHÒNG VẬT LÝ
            $pms_status = get_post_meta( $rid, '_rba_pms_room_status', true ) ?: 'available';
            $booking    = $room_booking_map[ $rid ] ?? null;
            $hotel_id   = (int) get_post_meta( $rid, 'tf_hotel', true );

            // Override pms_status nếu có booking đang chạy
            if ( $booking && $pms_status === 'available' ) {
                $pms_status = 'occupied';
            }

            $result[] = [
                'room_id'     => $rid,
                'room_name'   => $room->post_title,
                'hotel_id'    => $hotel_id,
                'hotel_name'  => $hotel_id ? get_the_title( $hotel_id ) : '',
                'quantity'    => $quantity,           // số phòng vật lý
                'pms_status'  => $pms_status,
                'is_occupied' => $booking !== null,
                'booking'     => $booking,
                'floor'       => (string) ( function_exists( 'get_field' )
                                    ? ( get_field( 'room_floor', $rid ) ?: '' )
                                    : get_post_meta( $rid, 'room_floor', true ) ),
                'beds'        => (array) ( function_exists( 'get_field' )
                                    ? ( get_field( 'room_beds', $rid ) ?: [] ) : [] ),
                'thumbnail'   => get_the_post_thumbnail_url( $rid, 'thumbnail' ) ?: '',
            ];
        }

        usort( $result, fn( $a, $b ) => (int) $b['is_occupied'] - (int) $a['is_occupied'] );
        return $result;
    }

    public static function get_dashboard_stats(): array {
        $month_start = current_time( 'Y-m-01' );
        $today       = current_time( 'Y-m-d' );
        $activity    = self::get_today_activity();
        $rooms       = self::get_room_status_map();

        // Tổng phòng vật lý = sum quantity
        $total_physical = array_sum( array_column( $rooms, 'quantity' ) );
        $occupied       = count( array_filter( $rooms, fn( $r ) => $r['is_occupied'] ) );
        $occupancy      = $total_physical > 0
            ? round( $occupied / $total_physical * 100 ) : 0;

        $month_orders = wc_get_orders( [
            'status'       => [ 'wc-processing', 'wc-completed' ],
            'date_created' => '>=' . $month_start,
            'limit'        => -1,
        ] );
        $month_revenue = array_sum(
            array_map( fn( $o ) => (float) $o->get_total(), $month_orders )
        );

        $today_orders = wc_get_orders( [
            'status'       => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
            'date_created' => '>=' . $today,
            'limit'        => -1,
        ] );
        $today_revenue = array_sum(
            array_map( fn( $o ) => (float) $o->get_total(), $today_orders )
        );

        return [
            'checkins_today'    => count( $activity['checkins'] ),
            'checkouts_today'   => count( $activity['checkouts'] ),
            'inhouse_count'     => count( $activity['inhouse'] ),
            'holding_count'     => count( $activity['holding'] ),
            'occupied_rooms'    => $occupied,
            'total_rooms'       => count( $rooms ),
            'total_physical'    => $total_physical,
            'occupancy_rate'    => $occupancy,
            'month_revenue'     => $month_revenue,
            'month_revenue_fmt' => number_format( $month_revenue, 0, ',', '.' ),
            'today_revenue'     => $today_revenue,
            'today_revenue_fmt' => number_format( $today_revenue, 0, ',', '.' ),
            'dirty_rooms'       => count( array_filter( $rooms, fn( $r ) => $r['pms_status'] === 'dirty' ) ),
            'maintenance_rooms' => count( array_filter( $rooms, fn( $r ) => $r['pms_status'] === 'maintenance' ) ),
        ];
    }

    public static function get_revenue_chart( int $days = 30 ): array {
        $data = [];
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $date   = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
            $orders = wc_get_orders( [
                'status'       => [ 'wc-processing', 'wc-completed' ],
                'date_created' => $date . '...' . $date . ' 23:59:59',
                'limit'        => -1,
            ] );
            $data[] = [
                'date'  => $date,
                'label' => date_i18n( 'd/m', strtotime( $date ) ),
                'value' => array_sum( array_map( fn( $o ) => (float) $o->get_total(), $orders ) ),
                'count' => count( $orders ),
            ];
        }
        return $data;
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    private function check_pms_access(): void {
        if ( ! check_ajax_referer( 'rba_pms_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce', 403 );
        }
        if ( ! RBA_PMS_Role::current_user_can_pms() ) {
            wp_send_json_error( 'Access denied', 403 );
        }
    }

    public function ajax_get_bookings(): void {
        $this->check_pms_access();
        wp_send_json_success( self::get_bookings( [
            'limit'     => absint( $_POST['limit']    ?? 30 ),
            'offset'    => absint( $_POST['offset']   ?? 0 ),
            'status'    => sanitize_text_field( $_POST['status']    ?? '' ),
            'search'    => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ),
            'check_in'  => sanitize_text_field( $_POST['check_in']  ?? '' ),
            'room_id'   => absint( $_POST['room_id']  ?? 0 ),
            'date_from' => sanitize_text_field( $_POST['date_from'] ?? '' ),
            'date_to'   => sanitize_text_field( $_POST['date_to']   ?? '' ),
        ] ) );
    }

    public function ajax_get_checkins_today(): void {
        $this->check_pms_access();
        wp_send_json_success( self::get_today_activity() );
    }

    public function ajax_update_booking_status(): void {
        $this->check_pms_access();
        $order_id   = absint( $_POST['order_id']   ?? 0 );
        $pms_status = sanitize_text_field( $_POST['pms_status'] ?? '' );
        $order      = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Order not found' );

        $allowed = [ 'confirmed', 'cancelled', 'inhouse', 'checked_out', 'no_show', 'holding' ];
        if ( ! in_array( $pms_status, $allowed, true ) ) wp_send_json_error( 'Invalid status' );

        $order->update_meta_data( '_rba_pms_status', $pms_status );
        $order->save_meta_data();
        wp_send_json_success( [ 'status' => $pms_status ] );
    }

    public function ajax_do_checkin(): void {
        $this->check_pms_access();
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Order not found' );

        $user = wp_get_current_user();
        $order->update_meta_data( '_rba_pms_status',  'inhouse' );
        $order->update_meta_data( '_rba_checkin_time', current_time( 'Y-m-d H:i:s' ) );
        $order->update_meta_data( '_rba_checkin_by',   $user->display_name );
        $order->save_meta_data();

        // Chuyển on-hold / pending → processing
        if ( in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
            $order->update_status( 'processing',
                'Check-in qua PMS bởi ' . $user->display_name );
        }

        // Đánh dấu phòng = occupied
        foreach ( $order->get_items() as $item ) {
            $rid = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            if ( $rid ) update_post_meta( $rid, '_rba_pms_room_status', 'occupied' );
        }

        wp_send_json_success( [
            'message'      => 'Check-in thành công!',
            'checkin_time' => current_time( 'H:i d/m/Y' ),
            'by'           => $user->display_name,
        ] );
    }

    public function ajax_do_checkout(): void {
        $this->check_pms_access();
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Order not found' );

        $user = wp_get_current_user();
        $order->update_meta_data( '_rba_pms_status',   'checked_out' );
        $order->update_meta_data( '_rba_checkout_time', current_time( 'Y-m-d H:i:s' ) );
        $order->update_meta_data( '_rba_checkout_by',   $user->display_name );
        $order->save_meta_data();
        $order->update_status( 'completed', 'Check-out qua PMS bởi ' . $user->display_name );

        foreach ( $order->get_items() as $item ) {
            $rid = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            if ( $rid ) update_post_meta( $rid, '_rba_pms_room_status', 'dirty' );
        }

        wp_send_json_success( [ 'message' => 'Check-out thành công! Phòng đã được đánh dấu cần dọn.' ] );
    }

    public function ajax_get_room_status(): void {
        $this->check_pms_access();
        wp_send_json_success( self::get_room_status_map() );
    }

    public function ajax_update_room_status(): void {
        $this->check_pms_access();
        $room_id = absint( $_POST['room_id'] ?? 0 );
        $status  = sanitize_text_field( $_POST['status'] ?? '' );
        $allowed = [ 'available', 'occupied', 'dirty', 'maintenance', 'blocked' ];
        if ( ! $room_id || ! in_array( $status, $allowed, true ) ) {
            wp_send_json_error( 'Invalid params' );
        }
        update_post_meta( $room_id, '_rba_pms_room_status', $status );
        wp_send_json_success( [ 'room_id' => $room_id, 'status' => $status ] );
    }

    public function ajax_get_invoice(): void {
        $this->check_pms_access();
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Order not found' );

        $items = [];
        foreach ( $order->get_items() as $item ) {
            $room_id   = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            $check_in  = $item->get_meta( 'tf_check_in' )  ?: $item->get_meta( 'check_in' );
            $check_out = $item->get_meta( 'tf_check_out' ) ?: $item->get_meta( 'check_out' );
            $nights    = ( $check_in && $check_out )
                ? max( 1, (int) ( ( strtotime( $check_out ) - strtotime( $check_in ) ) / DAY_IN_SECONDS ) )
                : 1;
            $rba_total = (float) ( $item->get_meta( 'rba_total' ) ?: $item->get_total() );
            $per_night = $nights > 0 ? $rba_total / $nights : $rba_total;

            $items[] = [
                'room_name'  => $room_id ? get_the_title( $room_id ) : $item->get_name(),
                'check_in'   => $check_in  ? date_i18n( 'd/m/Y', strtotime( $check_in ) )  : '',
                'check_out'  => $check_out ? date_i18n( 'd/m/Y', strtotime( $check_out ) ) : '',
                'nights'     => $nights,
                'per_night'  => number_format( $per_night, 0, ',', '.' ),
                'price'      => $rba_total,
                'price_fmt'  => number_format( $rba_total, 0, ',', '.' ),
                'adults'     => (int) ( $item->get_meta( 'adults' )   ?: 1 ),
                'children'   => (int) ( $item->get_meta( 'children' ) ?: 0 ),
            ];
        }

        wp_send_json_success( [
            'order_id'       => $order_id,
            'order_number'   => $order->get_order_number(),
            'order_date'     => $order->get_date_created()?->date( 'd/m/Y H:i' ) ?? '',
            'order_status'   => wc_get_order_status_name( $order->get_status() ),
            'guest_name'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'guest_phone'    => $order->get_billing_phone(),
            'guest_email'    => $order->get_billing_email(),
            'guest_address'  => trim( implode( ', ', array_filter( [
                $order->get_billing_address_1(),
                $order->get_billing_city(),
            ] ) ) ),
            'items'          => $items,
            'subtotal'       => number_format( (float) $order->get_subtotal(), 0, ',', '.' ),
            'discount'       => number_format( (float) $order->get_discount_total(), 0, ',', '.' ),
            'tax'            => number_format( (float) $order->get_total_tax(), 0, ',', '.' ),
            'total'          => number_format( (float) $order->get_total(), 0, ',', '.' ),
            'payment_method' => $order->get_payment_method_title(),
            'paid'           => $order->is_paid(),
            'checkin_time'   => $order->get_meta( '_rba_checkin_time' )  ?: '',
            'checkout_time'  => $order->get_meta( '_rba_checkout_time' ) ?: '',
            'checkin_by'     => $order->get_meta( '_rba_checkin_by' )    ?: '',
            'hotel_name'     => get_bloginfo( 'name' ),
            'hotel_address'  => trim( implode( ', ', array_filter( [
                get_option( 'woocommerce_store_address', '' ),
                get_option( 'woocommerce_store_city', '' ),
            ] ) ) ),
            'hotel_phone'    => get_option( 'admin_email', '' ),
            'total_raw'      => (float) $order->get_total(),
            'deposit_paid'   => (float) $order->get_meta( '_rba_deposit_paid'  ),
            'deposit_total'  => (float) $order->get_meta( '_rba_deposit_total' ),
            'deposit_note'   => (string) $order->get_meta( '_rba_deposit_note' ),
        ] );
    }

    public function ajax_get_reports(): void {
        $this->check_pms_access();
        $type = sanitize_text_field( $_POST['type'] ?? 'revenue' );
        $days = absint( $_POST['days'] ?? 30 );

        $data = match ( $type ) {
            'revenue' => self::get_revenue_chart( $days ),
            'rooms'   => self::get_room_status_map(),
            'stats'   => self::get_dashboard_stats(),
            default   => [],
        };

        wp_send_json_success( $data );
    }

    public function ajax_quick_search(): void {
        $this->check_pms_access();
        $q = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
        if ( strlen( $q ) < 2 ) wp_send_json_success( [] );
        wp_send_json_success( self::get_bookings( [ 'search' => $q, 'limit' => 10 ] ) );
    }

    /**
     * Tạo/cập nhật thông tin đặt cọc cho booking.
     * Luồng: on-hold = giữ phòng bằng tiền cọc → processing/completed = thanh toán nốt
     */
    public function ajax_set_deposit(): void {
        $this->check_pms_access();
        $order_id    = absint( $_POST['order_id']    ?? 0 );
        $dep_amount  = (float) ( $_POST['deposit']   ?? 0 );
        $dep_total   = (float) ( $_POST['total']     ?? 0 );
        $note        = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error('Order not found');

        // Lưu thông tin đặt cọc
        $order->update_meta_data( '_rba_deposit_paid',  $dep_amount );
        $order->update_meta_data( '_rba_deposit_total', $dep_total  );
        $order->update_meta_data( '_rba_deposit_note',  $note       );
        $order->update_meta_data( '_rba_deposit_date',  current_time('Y-m-d H:i:s') );
        $order->update_meta_data( '_rba_deposit_by',    wp_get_current_user()->display_name );

        // Nếu chưa on-hold → chuyển sang on-hold (giữ phòng)
        if ( in_array( $order->get_status(), ['pending','processing'], true ) ) {
            $order->update_status( 'on-hold',
                sprintf( 'Đặt cọc %s₫ / %s₫ qua PMS bởi %s',
                    number_format($dep_amount,0,',','.'),
                    number_format($dep_total,0,',','.'),
                    wp_get_current_user()->display_name
                )
            );
        } else {
            $order->save_meta_data();
        }

        $remaining = max(0, $dep_total - $dep_amount);
        wp_send_json_success([
            'message'   => 'Đã lưu thông tin đặt cọc!',
            'remaining' => number_format($remaining,0,',','.'),
            'paid'      => number_format($dep_amount,0,',','.'),
        ]);
    }

    /**
     * Thu số tiền còn lại khi checkout.
     */
    public function ajax_collect_remaining(): void {
        $this->check_pms_access();
        $order_id   = absint( $_POST['order_id']  ?? 0 );
        $amount     = (float)( $_POST['amount']   ?? 0 );
        $method     = sanitize_text_field( $_POST['method'] ?? 'cash' );
        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error('Order not found');

        $dep_paid  = (float) $order->get_meta('_rba_deposit_paid');
        $dep_total = (float) $order->get_meta('_rba_deposit_total');
        $remaining = $dep_total > 0 ? max(0, $dep_total - $dep_paid) : (float)$order->get_total();

        if ( abs($amount - $remaining) > 1 ) {
            wp_send_json_error( sprintf('Số tiền không khớp. Cần thu: %s₫', number_format($remaining,0,',','.')) );
        }

        // Ghi nhận thanh toán nốt
        $order->update_meta_data('_rba_remaining_collected',    $amount);
        $order->update_meta_data('_rba_remaining_collect_date', current_time('Y-m-d H:i:s'));
        $order->update_meta_data('_rba_remaining_collect_by',   wp_get_current_user()->display_name);
        $order->update_meta_data('_rba_remaining_method',       $method);

        // Tổng đã thu = cọc + còn lại
        $total_collected = $dep_paid + $amount;
        $order->update_meta_data('_rba_deposit_paid', $total_collected);

        // Chuyển sang processing (thanh toán đủ)
        $order->update_status('processing',
            sprintf('Thu đủ %s₫ (cọc %s₫ + còn lại %s₫) - %s qua PMS',
                number_format($total_collected,0,',','.'),
                number_format($dep_paid,0,',','.'),
                number_format($amount,0,',','.'),
                $method
            )
        );

        wp_send_json_success([
            'message'         => 'Đã thu đủ tiền! Booking chuyển sang Processing.',
            'total_collected' => number_format($total_collected,0,',','.'),
        ]);
    }

    /**
     * Xuất Excel (CSV) danh sách booking cho báo cáo.
     */
    public function ajax_export_excel(): void {
        $this->check_pms_access();

        $days    = absint( $_POST['days']     ?? 30 );
        $status  = sanitize_text_field( $_POST['status']   ?? '' );
        $date_from = sanitize_text_field( $_POST['date_from'] ?? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) );
        $date_to   = sanitize_text_field( $_POST['date_to']   ?? gmdate( 'Y-m-d' ) );

        $bookings = self::get_bookings( [
            'limit'     => 1000,
            'status'    => $status,
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ] );

        // Build CSV
        $rows = [];
        $rows[] = [ 'Order #', 'Ngày đặt', 'Khách', 'SĐT', 'Email', 'Phòng',
                    'Check-in', 'Check-out', 'Số đêm', 'Người lớn', 'Trẻ em',
                    'Tổng tiền (VNĐ)', 'TT thanh toán', 'Trạng thái WC', 'Trạng thái PMS',
                    'Giờ check-in thực', 'Giờ check-out thực', 'NV check-in' ];

        foreach ( $bookings as $b ) {
            $rows[] = [
                $b['order_id'],
                $b['order_date'],
                $b['guest_name'],
                $b['guest_phone'],
                $b['guest_email'],
                $b['room_name'],
                $b['check_in'],
                $b['check_out'],
                $b['nights'],
                $b['adults'],
                $b['children'],
                $b['rba_total'],
                $b['payment_method'],
                $b['order_status'],
                $b['pms_status'],
                $b['checkin_time'],
                $b['checkout_time'],
                $b['checkin_by'],
            ];
        }

        // Trả về JSON — frontend tự tạo file CSV/Excel
        wp_send_json_success( [
            'rows'     => $rows,
            'filename' => 'bao-cao-booking-' . $date_from . '-to-' . $date_to . '.csv',
            'total'    => count( $bookings ),
        ] );
    }
}

new RBA_PMS();
