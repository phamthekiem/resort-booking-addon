<?php
/**
 * RBA_KiotViet — Bridge tích hợp KiotViet Hotel song song với WordPress.
 *
 * LUỒNG 2 CHIỀU:
 *  A. Website → KiotViet  : Khi WooCommerce order confirmed → tạo đặt phòng trong KiotViet
 *  B. KiotViet → Website  : Khi lễ tân tạo/hủy booking trong KiotViet → webhook → block/unblock dates
 *
 * API base : https://api-integration-hotel.kiotviet.vn/public/
 * Auth     : OAuth2 client_credentials → Bearer token (TTL 3600s, tự refresh)
 * Webhook  : x-signature = HMAC-SHA256(body+timestamp+retailerCode+secretKey)
 *             x-timestamp = UTC Unix timestamp (ms)
 * Docs     : kiotviet.vn/huong-dan/.../thiet-lap-webhook-web-hotel/ (07/06/2024)
 *
 * @package ResortBookingAddon
 * @since   1.4.0
 */
defined( 'ABSPATH' ) || exit;

class RBA_KiotViet {

    // ── KiotViet Hotel API endpoints ─────────────────────────────────────────
    const TOKEN_URL   = 'https://id.kiotviet.vn/connect/token';
    const API_BASE    = 'https://api-integration-hotel.kiotviet.vn/public/';
    const SCOPE       = 'PublicApi.Access';

    // ── Option keys ───────────────────────────────────────────────────────────
    const OPT_CLIENT_ID     = 'rba_kv_client_id';
    const OPT_CLIENT_SECRET = 'rba_kv_client_secret';
    const OPT_RETAILER      = 'rba_kv_retailer';
    const OPT_WEBHOOK_SEC   = 'rba_kv_webhook_secret';
    const OPT_BRANCH_ID     = 'rba_kv_branch_id';
    const OPT_ENABLED       = 'rba_kv_enabled';
    const OPT_TOKEN_CACHE   = 'rba_kv_token_cache';

    // ── Trạng thái đặt phòng KiotViet ─────────────────────────────────────────
    const STATUS_BOOKED     = 1;
    const STATUS_CHECKED_IN = 2;
    const STATUS_CANCELLED  = 3;
    const STATUS_PENDING    = 4;

    public function __construct() {
        // ── Outbound: Website → KiotViet ─────────────────────────────────────
        add_action( 'rba_booking_confirmed', [ $this, 'push_booking_to_kiotviet' ], 10, 2 );
        add_action( 'rba_booking_released',  [ $this, 'cancel_booking_in_kiotviet' ], 10, 2 );

        // ── Inbound: KiotViet → Website (webhook) ─────────────────────────────
        add_action( 'init',                  [ $this, 'register_webhook_endpoint' ] );
        add_action( 'template_redirect',     [ $this, 'handle_webhook_request' ] );

        // ── Admin settings page ───────────────────────────────────────────────
        add_action( 'admin_menu',            [ $this, 'register_settings_page' ], 99 );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_notices',         [ $this, 'show_config_notice' ] );

        // ── AJAX: test connection, sync rooms ─────────────────────────────────
        add_action( 'wp_ajax_rba_kv_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_rba_kv_sync_rooms',      [ $this, 'ajax_sync_rooms' ] );
        add_action( 'wp_ajax_rba_kv_get_branches',    [ $this, 'ajax_get_branches' ] );

        // ── Cron: đồng bộ availability từ KiotViet mỗi 30 phút (fallback) ─────
        add_action( 'rba_kv_sync_cron',      [ $this, 'cron_sync_availability' ] );
    }

    // =========================================================================
    // AUTHENTICATION — OAuth2 client_credentials với auto-refresh
    // =========================================================================

    /**
     * Lấy access token hợp lệ (từ cache hoặc request mới).
     * Token KiotViet TTL = 3600s. Cache trong WP transient.
     */
    private function get_access_token(): string {
        $cached = get_transient( self::OPT_TOKEN_CACHE );
        if ( $cached ) {
            return $cached;
        }

        $client_id     = get_option( self::OPT_CLIENT_ID, '' );
        $client_secret = get_option( self::OPT_CLIENT_SECRET, '' );
        $retailer      = get_option( self::OPT_RETAILER, '' );

        if ( ! $client_id || ! $client_secret || ! $retailer ) {
            return '';
        }

        $response = wp_remote_post( self::TOKEN_URL, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => [
                'scopes'        => self::SCOPE,
                'grant_type'    => 'client_credentials',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $this->log( 'Token request failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ) ) );
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $token = $body['access_token'] ?? '';

        if ( $token ) {
            // Cache 55 phút (token TTL 60 phút, trừ 5 phút buffer)
            set_transient( self::OPT_TOKEN_CACHE, $token, 55 * MINUTE_IN_SECONDS );
        }

        return $token;
    }

    /**
     * Gọi KiotViet Hotel API.
     *
     * @param string $endpoint  Ví dụ: 'bookings', 'roomtypes'
     * @param string $method    GET | POST | PUT | DELETE
     * @param array  $body      Request body (cho POST/PUT)
     * @param array  $params    Query params (cho GET)
     * @return array|null       Decoded JSON hoặc null nếu lỗi
     */
    private function api( string $endpoint, string $method = 'GET', array $body = [], array $params = [] ): ?array {
        $token    = $this->get_access_token();
        $retailer = get_option( self::OPT_RETAILER, '' );

        if ( ! $token ) {
            $this->log( "API call failed: no token. Endpoint: {$endpoint}" );
            return null;
        }

        $url = self::API_BASE . ltrim( $endpoint, '/' );
        if ( $params ) {
            $url .= '?' . http_build_query( $params );
        }

        $args = [
            'timeout' => 20,
            'method'  => strtoupper( $method ),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Retailer'      => $retailer,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ( $body && in_array( $method, [ 'POST', 'PUT' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log( "API error [{$endpoint}]: " . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        if ( $code === 401 ) {
            // Token hết hạn → xóa cache → retry 1 lần
            delete_transient( self::OPT_TOKEN_CACHE );
            return $this->api( $endpoint, $method, $body, $params );
        }

        if ( $code < 200 || $code >= 300 ) {
            $this->log( "API HTTP {$code} [{$endpoint}]: {$raw}" );
            return null;
        }

        return json_decode( $raw, true ) ?: [];
    }

    // =========================================================================
    // A. OUTBOUND: WordPress → KiotViet
    // =========================================================================

    /**
     * Hook: rba_booking_confirmed
     * Khi khách book thành công trên website → tạo đặt phòng trong KiotViet.
     */
    public function push_booking_to_kiotviet( int $order_id, \WC_Order $order ): void {
        if ( ! $this->is_enabled() ) return;

        // Tránh push trùng
        if ( $order->get_meta( '_rba_kv_booking_id' ) ) return;

        $branch_id = (int) get_option( self::OPT_BRANCH_ID, 0 );

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $room_id   = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            $check_in  = $item->get_meta( 'tf_check_in' )  ?: $item->get_meta( 'check_in' );
            $check_out = $item->get_meta( 'tf_check_out' ) ?: $item->get_meta( 'check_out' );

            if ( ! $room_id || ! $check_in || ! $check_out ) continue;

            // Lấy KiotViet room type ID đã map với WP room
            $kv_room_type_id = (int) get_post_meta( $room_id, '_rba_kv_room_type_id', true );
            if ( ! $kv_room_type_id ) {
                $this->log( "Room {$room_id} chưa được map với KiotViet room type. Bỏ qua." );
                continue;
            }

            $payload = [
                'branchId'      => $branch_id ?: null,
                'roomTypeId'    => $kv_room_type_id,
                'checkInDate'   => $check_in . 'T14:00:00',   // Giờ check-in mặc định 14:00
                'checkOutDate'  => $check_out . 'T12:00:00',  // Giờ check-out mặc định 12:00
                'status'        => self::STATUS_BOOKED,
                'customer'      => [
                    'name'          => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                    'contactNumber' => $order->get_billing_phone(),
                    'email'         => $order->get_billing_email(),
                ],
                'note'          => sprintf( 'Booking từ website #%d', $order_id ),
                'totalAmount'   => $order->get_total(),
                'deposit'       => 0,
            ];

            $result = $this->api( 'bookings', 'POST', $payload );

            if ( ! empty( $result['id'] ) ) {
                // Lưu KiotViet booking ID vào order meta
                $order->update_meta_data( '_rba_kv_booking_id', $result['id'] );
                $order->update_meta_data( '_rba_kv_room_id', $result['roomId'] ?? '' );
                $order->save_meta_data();
                $this->log( "Tạo KiotViet booking #{$result['id']} cho WC order #{$order_id} thành công." );
            } else {
                $this->log( "Tạo KiotViet booking thất bại cho order #{$order_id}." );
            }
        }
    }

    /**
     * Hook: rba_booking_released
     * Khi order bị hủy/refund → hủy đặt phòng trong KiotViet.
     */
    public function cancel_booking_in_kiotviet( int $order_id, \WC_Order $order ): void {
        if ( ! $this->is_enabled() ) return;

        $kv_booking_id = $order->get_meta( '_rba_kv_booking_id' );
        if ( ! $kv_booking_id ) return;

        $result = $this->api( "bookings/{$kv_booking_id}", 'PUT', [
            'status' => self::STATUS_CANCELLED,
            'note'   => "Hủy từ website — WC order #{$order_id}",
        ] );

        if ( null !== $result ) {
            $order->delete_meta_data( '_rba_kv_booking_id' );
            $order->save_meta_data();
            $this->log( "Hủy KiotViet booking #{$kv_booking_id} (order #{$order_id}) thành công." );
        }
    }

    // =========================================================================
    // B. INBOUND: KiotViet → WordPress (Webhook)
    // =========================================================================

    public function register_webhook_endpoint(): void {
        add_rewrite_rule( '^rba-kv-webhook/?$', 'index.php?rba_kv_webhook=1', 'top' );
        add_filter( 'query_vars', function ( array $vars ): array {
            $vars[] = 'rba_kv_webhook';
            return $vars;
        } );
    }

    public function handle_webhook_request(): void {
        if ( ! get_query_var( 'rba_kv_webhook' ) ) return;

        // Chỉ chấp nhận POST
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            status_header( 405 );
            exit( 'Method Not Allowed' );
        }

        $raw_body = file_get_contents( 'php://input' );

        // Xác thực chữ ký HMAC-SHA256
        if ( ! $this->verify_webhook_signature( $raw_body ) ) {
            status_header( 401 );
            exit( 'Unauthorized' );
        }

        $payload = json_decode( $raw_body, true );
        if ( ! $payload ) {
            status_header( 400 );
            exit( 'Bad Request' );
        }

        // KiotViet Hotel webhook format:
        // {
        //   "Id": "evt_xxx",
        //   "Attempt": 1,
        //   "Notifications": [{
        //     "Action": "booking.create" | "booking.update" | "booking.delete",
        //     "Data": [ { booking object... } ]
        //   }]
        // }
        $this->log( 'Webhook received: ' . substr( $raw_body, 0, 200 ) );

        $notifications = $payload['Notifications'] ?? $payload['notifications'] ?? [];

        // Fallback: một số version KiotViet gửi flat (không có Notifications wrapper)
        if ( empty( $notifications ) && isset( $payload['Action'] ) ) {
            $notifications = [ $payload ];
        }

        foreach ( $notifications as $notification ) {
            $action = $notification['Action'] ?? $notification['action'] ?? '';
            $data   = $notification['Data']   ?? $notification['data']   ?? [];
            if ( ! is_array( $data ) ) $data = [ $data ];
            $this->process_webhook_action( $action, $data );
        }

        status_header( 200 );
        echo 'OK';
        exit;
    }

    /**
     * Xác thực chữ ký webhook từ KiotViet Hotel.
     *
     * Theo tài liệu KiotViet (cập nhật 07/06/2024):
     *   Header: x-signature  = HMAC-SHA256( data + timestamp + retailerCode + secretKey )
     *   Header: x-timestamp  = thời gian gửi (UTC, Unix timestamp ms)
     *
     * Signing string = raw_body + x-timestamp + retailer_code + secret_key
     * Sau đó hash toàn bộ chuỗi đó với key = secret_key
     *
     * @param string $body Raw request body (JSON string, không decode)
     */
    private function verify_webhook_signature( string $body ): bool {
        $secret      = get_option( self::OPT_WEBHOOK_SEC, '' );
        $retailer    = get_option( self::OPT_RETAILER, '' );

        if ( ! $secret ) {
            $this->log( 'Webhook: chưa cấu hình Webhook Secret — BỎ QUA XÁC THỰC (nguy hiểm, chỉ dùng khi test!)' );
            return true; // Fallback unsafe mode
        }

        // Lấy headers từ KiotViet
        $received_sig = $_SERVER['HTTP_X_SIGNATURE']  ?? '';
        $timestamp    = $_SERVER['HTTP_X_TIMESTAMP']  ?? '';

        if ( ! $received_sig ) {
            $this->log( 'Webhook: thiếu header x-signature' );
            return false;
        }

        // Kiểm tra timestamp — reject webhook cũ hơn 5 phút (chống replay attack)
        if ( $timestamp ) {
            $ts_seconds = (int) $timestamp > 9999999999
                ? (int) ( $timestamp / 1000 )   // milliseconds → seconds
                : (int) $timestamp;
            if ( abs( time() - $ts_seconds ) > 300 ) {
                $this->log( "Webhook: timestamp quá cũ ({$timestamp}) — từ chối (replay attack prevention)" );
                return false;
            }
        }

        // Build signing string: data + timestamp + retailerCode + secretKey
        // "data" = raw request body
        $signing_string = $body . $timestamp . $retailer . $secret;

        // HMAC-SHA256 với key là secretKey
        $expected = hash_hmac( 'sha256', $signing_string, $secret );

        // Constant-time comparison
        return hash_equals( strtolower( $expected ), strtolower( $received_sig ) );
    }

    /**
     * Xử lý từng action từ KiotViet webhook.
     *
     * Các action KiotViet Hotel hay phát sinh:
     *   booking.create  → lễ tân tạo booking mới (walk-in / phone)
     *   booking.update  → thay đổi ngày, trạng thái
     *   booking.delete  → xóa booking
     */
    private function process_webhook_action( string $action, array $data ): void {
        $this->log( "Webhook action: {$action}, items: " . count( $data ) );

        foreach ( $data as $booking ) {
            $kv_id         = $booking['id']          ?? $booking['Id']          ?? 0;
            $kv_room_id    = $booking['roomId']       ?? $booking['RoomId']       ?? 0;
            $check_in      = $booking['checkInDate']  ?? $booking['CheckInDate']  ?? '';
            $check_out     = $booking['checkOutDate'] ?? $booking['CheckOutDate'] ?? '';
            $status        = (int) ( $booking['status'] ?? $booking['Status'] ?? 0 );

            if ( ! $kv_id || ! $check_in || ! $check_out ) continue;

            // Chuyển ISO datetime → date
            $date_in  = substr( $check_in,  0, 10 );
            $date_out = substr( $check_out, 0, 10 );

            // Tìm WP room_id từ KiotViet room ID
            $wp_room_id = $this->get_wp_room_by_kv_room( $kv_room_id );
            if ( ! $wp_room_id ) {
                $this->log( "Webhook: không tìm thấy WP room cho KV room #{$kv_room_id}" );
                continue;
            }

            // Xác định nguồn booking (OTA channel hay walk-in/direct)
            $source_channel = $booking['sourceChannel'] ?? $booking['SourceChannel']
                           ?? $booking['channel']       ?? $booking['Channel']
                           ?? 'kiotviet';

            switch ( $action ) {
                case 'booking.create':
                    if ( $status !== self::STATUS_CANCELLED ) {
                        // 1. Block dates trong website availability
                        RBA_Database::block_dates_from_ical( $wp_room_id, $date_in, $date_out );

                        // 2. Lưu reference để track
                        $this->save_kv_booking_reference( $kv_id, $wp_room_id, $date_in, $date_out, $source_channel );

                        // 3. Cập nhật iCal feed outbound — OTA khác sẽ thấy ngay khi poll
                        do_action( 'rba_availability_changed', $wp_room_id, $date_in, $date_out, 0 );

                        $this->log( "Webhook create [{$source_channel}]: blocked {$date_in}→{$date_out} room#{$wp_room_id}" );

                        /**
                         * Hook cho developer: xử lý thêm khi KiotViet nhận booking mới từ OTA.
                         * Ví dụ: gửi email welcome, tạo WC order để có invoice, v.v.
                         *
                         * @param int    $kv_id          KiotViet booking ID
                         * @param int    $wp_room_id     WordPress room post ID
                         * @param string $date_in        Check-in date Y-m-d
                         * @param string $date_out       Check-out date Y-m-d
                         * @param string $source_channel Kênh nguồn: 'Booking.com', 'Agoda', 'walk-in'...
                         * @param array  $booking        Full booking data từ KiotViet
                         */
                        do_action( 'rba_kv_booking_created', $kv_id, $wp_room_id, $date_in, $date_out, $source_channel, $booking );
                    }
                    break;

                case 'booking.update':
                    $ref = $this->get_kv_booking_reference( $kv_id );
                    if ( $status === self::STATUS_CANCELLED ) {
                        // Hủy → unblock
                        if ( $ref ) {
                            RBA_Database::unblock_ical_dates( $wp_room_id, $ref['date_in'], $ref['date_out'] );
                            $this->delete_kv_booking_reference( $kv_id );
                            $this->log( "Webhook cancel: unblocked {$ref['date_in']}→{$ref['date_out']} cho room #{$wp_room_id}" );
                        }
                    } else {
                        // Cập nhật ngày (gia hạn, rút ngắn)
                        if ( $ref && ( $ref['date_in'] !== $date_in || $ref['date_out'] !== $date_out ) ) {
                            RBA_Database::unblock_ical_dates( $wp_room_id, $ref['date_in'], $ref['date_out'] );
                            RBA_Database::block_dates_from_ical( $wp_room_id, $date_in, $date_out );
                            $this->save_kv_booking_reference( $kv_id, $wp_room_id, $date_in, $date_out, 'kv_direct' );
                            $this->log( "Webhook update dates: {$date_in}→{$date_out} cho room #{$wp_room_id}" );
                        }
                    }
                    break;

                case 'booking.delete':
                    $ref = $this->get_kv_booking_reference( $kv_id );
                    if ( $ref ) {
                        RBA_Database::unblock_ical_dates( $wp_room_id, $ref['date_in'], $ref['date_out'] );
                        $this->delete_kv_booking_reference( $kv_id );
                        $this->log( "Webhook delete: unblocked cho room #{$wp_room_id}" );
                    }
                    break;
            }

            /**
             * Action hook cho developer mở rộng xử lý webhook.
             *
             * @param string $action    booking.create | booking.update | booking.delete
             * @param array  $booking   Raw booking data từ KiotViet
             * @param int    $wp_room_id
             */
            do_action( 'rba_kv_webhook_processed', $action, $booking, $wp_room_id );
        }
    }

    // =========================================================================
    // CRON FALLBACK SYNC — Đồng bộ availability mỗi 30 phút
    // =========================================================================

    /**
     * Fallback: nếu webhook bị miss, cron này pull list booking từ KiotViet
     * và đảm bảo availability trên website luôn đúng.
     */
    public function cron_sync_availability(): void {
        if ( ! $this->is_enabled() ) return;

        $branch_id = (int) get_option( self::OPT_BRANCH_ID, 0 );
        $from      = current_time( 'Y-m-d' );
        $to        = gmdate( 'Y-m-d', strtotime( '+90 days' ) );

        $params = [
            'pageSize'        => 100,
            'pageIndex'       => 1,
            'checkInDateFrom' => $from,
            'checkOutDateTo'  => $to,
        ];
        if ( $branch_id ) {
            $params['branchId'] = $branch_id;
        }

        $result = $this->api( 'bookings', 'GET', [], $params );
        if ( empty( $result['data'] ) ) return;

        foreach ( $result['data'] as $booking ) {
            $kv_id      = $booking['id']          ?? 0;
            $kv_room_id = $booking['roomId']       ?? 0;
            $status     = (int) ( $booking['status'] ?? 0 );
            $date_in    = substr( $booking['checkInDate']  ?? '', 0, 10 );
            $date_out   = substr( $booking['checkOutDate'] ?? '', 0, 10 );

            if ( ! $kv_id || ! $date_in || ! $date_out ) continue;

            $wp_room_id = $this->get_wp_room_by_kv_room( $kv_room_id );
            if ( ! $wp_room_id ) continue;

            $ref = $this->get_kv_booking_reference( $kv_id );

            if ( $status === self::STATUS_CANCELLED ) {
                if ( $ref ) {
                    RBA_Database::unblock_ical_dates( $wp_room_id, $ref['date_in'], $ref['date_out'] );
                    $this->delete_kv_booking_reference( $kv_id );
                }
            } else {
                if ( ! $ref ) {
                    RBA_Database::block_dates_from_ical( $wp_room_id, $date_in, $date_out );
                    $this->save_kv_booking_reference( $kv_id, $wp_room_id, $date_in, $date_out, 'kv_cron' );
                }
            }
        }

        update_option( 'rba_kv_last_cron_sync', current_time( 'mysql' ) );
        $this->log( 'Cron sync: xử lý ' . count( $result['data'] ) . ' bookings từ KiotViet.' );
    }

    // =========================================================================
    // HELPERS — Room mapping, booking reference storage
    // =========================================================================

    /**
     * Tìm WP room_id dựa trên KiotViet room_id (đã map trong post_meta).
     */
    private function get_wp_room_by_kv_room( int $kv_room_id ): int {
        if ( ! $kv_room_id ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_rba_kv_room_id' AND meta_value = %s LIMIT 1",
            (string) $kv_room_id
        ) );
    }

    /**
     * Lưu reference KiotViet booking → WP room + dates (vào wp_options).
     * Dùng serialized array, prefix 'rba_kv_ref_'.
     */
    private function save_kv_booking_reference( int $kv_id, int $wp_room_id, string $date_in, string $date_out, string $source ): void {
        update_option( 'rba_kv_ref_' . $kv_id, [
            'wp_room_id' => $wp_room_id,
            'date_in'    => $date_in,
            'date_out'   => $date_out,
            'source'     => $source,
            'created_at' => current_time( 'mysql' ),
        ], false );
    }

    private function get_kv_booking_reference( int $kv_id ): ?array {
        $ref = get_option( 'rba_kv_ref_' . $kv_id );
        return is_array( $ref ) ? $ref : null;
    }

    private function delete_kv_booking_reference( int $kv_id ): void {
        delete_option( 'rba_kv_ref_' . $kv_id );
    }

    private function is_enabled(): bool {
        return (bool) get_option( self::OPT_ENABLED, false );
    }

    private function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[RBA_KiotViet] ' . $message );
        }
        // Lưu 50 dòng log gần nhất vào option
        $logs   = get_option( 'rba_kv_logs', [] );
        $logs[] = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $message;
        if ( count( $logs ) > 50 ) {
            $logs = array_slice( $logs, -50 );
        }
        update_option( 'rba_kv_logs', $logs, false );
    }

    // =========================================================================
    // ADMIN: Settings Page + Room Mapping
    // =========================================================================

    public function register_settings_page(): void {
        $tf_slug = $this->detect_parent_menu();
        $parent  = $tf_slug ?: 'rba-dashboard';
        add_submenu_page(
            $parent,
            'KiotViet Hotel',
            'KiotViet',
            'manage_options',
            'rba-kiotviet',
            [ $this, 'render_settings_page' ]
        );
    }

    private function detect_parent_menu(): string {
        global $menu, $submenu;
        if ( empty( $menu ) ) return '';
        foreach ( (array) $menu as $item ) {
            if ( ! isset( $item[2] ) ) continue;
            if ( ! empty( $submenu[ $item[2] ] ) ) {
                foreach ( $submenu[ $item[2] ] as $sub ) {
                    if ( isset( $sub[2] ) && 'tf_dashboard' === $sub[2] ) return $item[2];
                }
            }
        }
        return '';
    }

    public function register_settings(): void {
        register_setting( 'rba_kv_settings', self::OPT_ENABLED,       [ 'type' => 'boolean', 'default' => false ] );
        register_setting( 'rba_kv_settings', self::OPT_CLIENT_ID,     [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'rba_kv_settings', self::OPT_CLIENT_SECRET, [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'rba_kv_settings', self::OPT_RETAILER,      [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'rba_kv_settings', self::OPT_WEBHOOK_SEC,   [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'rba_kv_settings', self::OPT_BRANCH_ID,     [ 'type' => 'integer', 'sanitize_callback' => 'absint' ] );
    }

    public function show_config_notice(): void {
        if ( ! get_option( self::OPT_ENABLED ) ) return;
        if ( get_option( self::OPT_CLIENT_ID ) && get_option( self::OPT_CLIENT_SECRET ) ) return;
        $url = admin_url( 'admin.php?page=rba-kiotviet' );
        echo '<div class="notice notice-warning"><p><strong>Resort Booking Addon:</strong> KiotViet đang bật nhưng chưa nhập Client ID / Secret. <a href="' . esc_url( $url ) . '">Cấu hình ngay →</a></p></div>';
    }

    public function render_settings_page(): void {
        global $wpdb;
        $webhook_url  = home_url( '/rba-kv-webhook/' );
        $last_sync    = get_option( 'rba_kv_last_cron_sync', '' );
        $logs         = array_reverse( get_option( 'rba_kv_logs', [] ) );
        $rooms        = get_posts( [ 'post_type' => 'tf_room', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title' ] );
        ?>
        <div class="wrap" style="max-width:900px">
            <h1>
                <span class="dashicons dashicons-store" style="font-size:28px;vertical-align:middle;margin-right:8px;color:#1a6b3c"></span>
                KiotViet Hotel — Cấu hình tích hợp
            </h1>

            <?php settings_errors(); ?>

            <!-- TABS -->
            <?php
            $active_tab = sanitize_key( $_GET['tab'] ?? 'settings' );
            $tabs = [ 'settings' => 'Cài đặt', 'room-mapping' => 'Map Phòng', 'webhook' => 'Webhook', 'logs' => 'Logs' ];
            echo '<nav class="nav-tab-wrapper">';
            foreach ( $tabs as $slug => $label ) {
                $url   = admin_url( 'admin.php?page=rba-kiotviet&tab=' . $slug );
                $class = $slug === $active_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
                echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
            }
            echo '</nav><div style="background:#fff;border:1px solid #ccd0d4;border-top:none;padding:20px">';
            ?>

            <?php if ( 'settings' === $active_tab ) : ?>
                <form method="post" action="options.php">
                    <?php settings_fields( 'rba_kv_settings' ); ?>
                    <table class="form-table">
                        <tr>
                            <th>Bật tích hợp KiotViet</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPT_ENABLED ); ?>" value="1" <?php checked( get_option( self::OPT_ENABLED ) ); ?>>
                                    Bật đồng bộ 2 chiều Website ↔ KiotViet Hotel
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Client ID</label></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr( self::OPT_CLIENT_ID ); ?>"
                                       value="<?php echo esc_attr( get_option( self::OPT_CLIENT_ID ) ); ?>"
                                       class="regular-text" placeholder="dc1d7025-0578-4426-...">
                                <p class="description">Lấy từ KiotViet: Thiết lập cửa hàng → Thiết lập kết nối API</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Client Secret (Mã bảo mật)</label></th>
                            <td>
                                <input type="password" name="<?php echo esc_attr( self::OPT_CLIENT_SECRET ); ?>"
                                       value="<?php echo esc_attr( get_option( self::OPT_CLIENT_SECRET ) ); ?>"
                                       class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label>Retailer (Tên cửa hàng)</label></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr( self::OPT_RETAILER ); ?>"
                                       value="<?php echo esc_attr( get_option( self::OPT_RETAILER ) ); ?>"
                                       class="regular-text" placeholder="tenresort">
                                <p class="description">Tên đăng nhập KiotViet (không phải email)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Branch ID (Chi nhánh)</label></th>
                            <td>
                                <input type="number" name="<?php echo esc_attr( self::OPT_BRANCH_ID ); ?>"
                                       value="<?php echo esc_attr( get_option( self::OPT_BRANCH_ID ) ); ?>"
                                       class="small-text" placeholder="0">
                                <button type="button" class="button" id="rba-kv-load-branches" style="margin-left:8px">Lấy danh sách chi nhánh</button>
                                <span id="rba-kv-branches-result" style="margin-left:8px;color:#555"></span>
                                <p class="description">Để trống = dùng chi nhánh mặc định</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Webhook Secret</label></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr( self::OPT_WEBHOOK_SEC ); ?>"
                                       value="<?php echo esc_attr( get_option( self::OPT_WEBHOOK_SEC ) ); ?>"
                                       class="regular-text" placeholder="Nhập secret bạn đặt khi đăng ký webhook">
                                <p class="description">Secret dùng để xác thực chữ ký X-Hub-Signature từ KiotViet</p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( 'Lưu cài đặt' ); ?>
                </form>

                <hr>
                <h3>Kiểm tra kết nối</h3>
                <button class="button button-primary" id="rba-kv-test">Test kết nối KiotViet</button>
                <span id="rba-kv-test-result" style="margin-left:12px"></span>

            <?php elseif ( 'room-mapping' === $active_tab ) : ?>
                <h3>Map phòng WordPress ↔ KiotViet Room Type</h3>
                <p>Để hệ thống biết phòng nào tương ứng với nhau, bạn cần map từng phòng WordPress với Room Type trong KiotViet.</p>

                <button class="button" id="rba-kv-sync-rooms" style="margin-bottom:16px">
                    Lấy danh sách Room Types từ KiotViet
                </button>
                <span id="rba-kv-rooms-msg" style="margin-left:8px"></span>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:35%">Phòng WordPress</th>
                            <th style="width:35%">KiotViet Room Type ID</th>
                            <th style="width:30%">KiotViet Room ID (cụ thể)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rooms as $room ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $room->post_title ); ?></strong>
                                <small style="display:block;color:#888">#<?php echo esc_html( $room->ID ); ?></small>
                            </td>
                            <td>
                                <input type="number"
                                       class="small-text rba-kv-room-type-input"
                                       data-room-id="<?php echo esc_attr( $room->ID ); ?>"
                                       value="<?php echo esc_attr( get_post_meta( $room->ID, '_rba_kv_room_type_id', true ) ); ?>"
                                       placeholder="VD: 101">
                            </td>
                            <td>
                                <input type="number"
                                       class="small-text rba-kv-room-id-input"
                                       data-room-id="<?php echo esc_attr( $room->ID ); ?>"
                                       value="<?php echo esc_attr( get_post_meta( $room->ID, '_rba_kv_room_id', true ) ); ?>"
                                       placeholder="VD: 201">
                                <button type="button" class="button button-small rba-kv-save-mapping"
                                        data-room-id="<?php echo esc_attr( $room->ID ); ?>"
                                        style="margin-left:4px">Lưu</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ( 'webhook' === $active_tab ) : ?>
                <h3>Cấu hình Webhook (KiotViet → Website)</h3>

                <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;padding:16px;margin-bottom:20px">
                    <strong>URL Webhook của website bạn:</strong><br>
                    <code style="font-size:13px;display:block;margin:8px 0;word-break:break-all"><?php echo esc_html( $webhook_url ); ?></code>
                    <button type="button" class="button"
                            onclick="navigator.clipboard.writeText('<?php echo esc_js( $webhook_url ); ?>').then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy URL',2000)})">
                        Copy URL
                    </button>
                </div>

                <h4>Hướng dẫn đăng ký Webhook trong KiotViet:</h4>
                <ol>
                    <li>Vào <strong>KiotViet Hotel</strong> → biểu tượng Cài đặt (⚙️) → <strong>Thiết lập kết nối API</strong></li>
                    <li>Chọn tab <strong>Webhook</strong> → click <strong>Tạo mới</strong></li>
                    <li>Nhập URL: <code><?php echo esc_html( $webhook_url ); ?></code></li>
                    <li>Nhập Secret: đặt 1 chuỗi bất kỳ (ví dụ: <code>my_resort_secret_2025</code>) → copy vào ô "Webhook Secret" bên tab Cài đặt</li>
                    <li>Chọn các sự kiện: <strong>booking.create</strong>, <strong>booking.update</strong>, <strong>booking.delete</strong></li>
                    <li>Lưu lại</li>
                </ol>

                <div style="background:#fff3e0;border:1px solid #ffb74d;border-radius:6px;padding:12px">
                    <strong>Lưu ý:</strong> Website phải chạy HTTPS. KiotViet từ chối gửi webhook đến HTTP.
                </div>

                <?php if ( $last_sync ) : ?>
                    <p style="margin-top:16px;color:#555">Cron sync gần nhất: <strong><?php echo esc_html( $last_sync ); ?></strong></p>
                <?php endif; ?>

            <?php elseif ( 'logs' === $active_tab ) : ?>
                <h3>Activity Logs (50 dòng gần nhất)</h3>
                <button class="button" style="margin-bottom:12px"
                        onclick="fetch(ajaxurl+'?action=rba_kv_clear_logs&nonce=<?php echo esc_js( wp_create_nonce( 'rba_kv_clear_logs' ) ); ?>').then(()=>location.reload())">
                    Xóa logs
                </button>
                <div style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;padding:16px;border-radius:6px;max-height:400px;overflow-y:auto">
                    <?php if ( empty( $logs ) ) : ?>
                        <em style="color:#888">Chưa có log nào.</em>
                    <?php else : ?>
                        <?php foreach ( $logs as $line ) : ?>
                            <div><?php echo esc_html( $line ); ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            </div><!-- end tab content -->
        </div>

        <script>
        (function($){
            const nonce_test  = '<?php echo esc_js( wp_create_nonce( 'rba_kv_test' ) ); ?>';
            const nonce_rooms = '<?php echo esc_js( wp_create_nonce( 'rba_kv_sync_rooms' ) ); ?>';
            const nonce_br    = '<?php echo esc_js( wp_create_nonce( 'rba_kv_get_branches' ) ); ?>';
            const nonce_map   = '<?php echo esc_js( wp_create_nonce( 'rba_kv_save_mapping' ) ); ?>';

            // Test kết nối
            $('#rba-kv-test').on('click', function(){
                const $r = $('#rba-kv-test-result');
                $r.text('Đang kiểm tra...');
                $.post(ajaxurl, { action:'rba_kv_test_connection', nonce: nonce_test }, function(res){
                    $r.html( res.success
                        ? '<span style="color:#2e7d32">✔ Kết nối thành công! Retailer: '+ res.data.retailer +'</span>'
                        : '<span style="color:#c62828">✘ '+res.data+'</span>' );
                });
            });

            // Lấy chi nhánh
            $('#rba-kv-load-branches').on('click', function(){
                const $r = $('#rba-kv-branches-result');
                $r.text('Đang tải...');
                $.post(ajaxurl, { action:'rba_kv_get_branches', nonce: nonce_br }, function(res){
                    if(res.success && res.data.length){
                        let html = 'Chi nhánh: ';
                        res.data.forEach(b => html += '<strong>'+b.id+'</strong>: '+b.name+' &nbsp; ');
                        $r.html(html);
                    } else {
                        $r.text('Không lấy được chi nhánh.');
                    }
                });
            });

            // Sync rooms từ KiotViet
            $('#rba-kv-sync-rooms').on('click', function(){
                const $m = $('#rba-kv-rooms-msg');
                $m.text('Đang tải...');
                $.post(ajaxurl, { action:'rba_kv_sync_rooms', nonce: nonce_rooms }, function(res){
                    if(res.success){
                        $m.html('<span style="color:#2e7d32">✔ ' + res.data + '</span>');
                    } else {
                        $m.html('<span style="color:#c62828">✘ '+res.data+'</span>');
                    }
                });
            });

            // Lưu room mapping
            $(document).on('click', '.rba-kv-save-mapping', function(){
                const $btn    = $(this);
                const roomId  = $btn.data('room-id');
                const typeId  = $('.rba-kv-room-type-input[data-room-id="'+roomId+'"]').val();
                const kvRoomId= $('.rba-kv-room-id-input[data-room-id="'+roomId+'"]').val();
                $btn.prop('disabled',true).text('...');
                $.post(ajaxurl, {
                    action: 'rba_kv_save_mapping',
                    nonce: nonce_map,
                    room_id: roomId,
                    kv_room_type_id: typeId,
                    kv_room_id: kvRoomId
                }, function(res){
                    $btn.prop('disabled',false).text('Lưu');
                    $btn.after( res.success
                        ? '<span style="color:#2e7d32;margin-left:6px">✔</span>'
                        : '<span style="color:#c62828;margin-left:6px">✘</span>' );
                    setTimeout(()=>$btn.nextAll('span').first().remove(), 2000);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    public function ajax_test_connection(): void {
        check_ajax_referer( 'rba_kv_test', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        // Xóa cache token để force request mới
        delete_transient( self::OPT_TOKEN_CACHE );
        $token = $this->get_access_token();

        if ( ! $token ) {
            wp_send_json_error( 'Không lấy được token. Kiểm tra Client ID / Secret / Retailer.' );
        }

        // Thử gọi API lấy branches để verify
        $result = $this->api( 'branches', 'GET', [], [ 'pageSize' => 1 ] );
        if ( null === $result ) {
            wp_send_json_error( 'Token OK nhưng gọi API thất bại. Kiểm tra Retailer name.' );
        }

        wp_send_json_success( [ 'retailer' => get_option( self::OPT_RETAILER ), 'branches' => count( $result['data'] ?? $result ) ] );
    }

    public function ajax_get_branches(): void {
        check_ajax_referer( 'rba_kv_get_branches', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $result = $this->api( 'branches', 'GET', [], [ 'pageSize' => 100 ] );
        if ( empty( $result ) ) {
            wp_send_json_error( 'Không lấy được danh sách chi nhánh.' );
        }

        $branches = array_map( fn( $b ) => [ 'id' => $b['id'], 'name' => $b['branchName'] ?? $b['name'] ?? '' ], $result['data'] ?? $result );
        wp_send_json_success( $branches );
    }

    public function ajax_sync_rooms(): void {
        check_ajax_referer( 'rba_kv_sync_rooms', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $result = $this->api( 'roomtypes', 'GET', [], [ 'pageSize' => 100 ] );
        if ( empty( $result ) ) {
            wp_send_json_error( 'Không lấy được room types từ KiotViet.' );
        }

        $room_types = $result['data'] ?? $result;
        if ( empty( $room_types ) ) {
            wp_send_json_error( 'KiotViet chưa có room type nào.' );
        }

        $list = array_map( fn( $r ) => "#{$r['id']}: {$r['name']}", $room_types );
        wp_send_json_success( 'Room Types: ' . implode( ', ', $list ) . '. Hãy điền ID tương ứng vào cột bên trái.' );
    }

    // =========================================================================
    // PUBLIC STATIC HELPERS (dùng từ theme/plugin khác)
    // =========================================================================

    /**
     * Lấy URL webhook để đăng ký với KiotViet.
     */
    public static function get_webhook_url(): string {
        return home_url( '/rba-kv-webhook/' );
    }
}

// Thêm AJAX save mapping (không nằm trong class để tránh circular dependency)
add_action( 'wp_ajax_rba_kv_save_mapping', function (): void {
    check_ajax_referer( 'rba_kv_save_mapping', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $room_id         = absint( $_POST['room_id'] ?? 0 );
    $kv_room_type_id = absint( $_POST['kv_room_type_id'] ?? 0 );
    $kv_room_id      = absint( $_POST['kv_room_id'] ?? 0 );

    if ( ! $room_id ) wp_send_json_error( 'Invalid room_id' );

    update_post_meta( $room_id, '_rba_kv_room_type_id', $kv_room_type_id );
    if ( $kv_room_id ) {
        update_post_meta( $room_id, '_rba_kv_room_id', $kv_room_id );
    }
    wp_send_json_success( 'Saved' );
} );

add_action( 'wp_ajax_rba_kv_clear_logs', function (): void {
    check_ajax_referer( 'rba_kv_clear_logs', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    delete_option( 'rba_kv_logs' );
    wp_send_json_success();
} );

new RBA_KiotViet();
