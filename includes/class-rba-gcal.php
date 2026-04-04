<?php
/**
 * RBA_GCal — Google Calendar Bridge
 *
 * Giải pháp cho OTA không có iCal trực tiếp (Traveloka, Trip.com...):
 * Dùng Google Calendar làm trung gian — OTA sync vào Google Calendar,
 * plugin đọc Google Calendar qua API → block dates trong rba_availability.
 *
 * ──────────────────────────────────────────────────────────────────────
 * 2 PHƯƠNG THỨC (admin chọn 1 trong 2):
 *
 * A. iCal URL của Google Calendar (KHUYẾN NGHỊ - không cần API key)
 *    Google Calendar có sẵn iCal public URL → plugin dùng parser iCal
 *    có sẵn (giống OTA thông thường). Zero setup, zero API quota.
 *
 * B. Google Calendar API + Service Account (nâng cao)
 *    Dùng khi calendar là PRIVATE (không share public).
 *    Cần tạo Google Cloud Project + Service Account (free).
 *    Quota: 1.000.000 request/ngày — hoàn toàn đủ dùng.
 *    Ưu điểm: real-time, đọc được calendar private, push notification.
 * ──────────────────────────────────────────────────────────────────────
 *
 * LUỒNG SỬ DỤNG THỰC TẾ (ví dụ với Traveloka):
 *   1. Traveloka → sync booking vào Google Calendar của khách sạn
 *   2. Plugin fetch events từ Google Calendar (iCal hoặc API)
 *   3. Block dates trong rba_availability
 *   4. Feed iCal outbound của website cập nhật → OTA khác biết
 *
 * @package ResortBookingAddon
 * @since   1.2.0
 */
defined( 'ABSPATH' ) || exit;

class RBA_GCal {

    // ── Option keys ───────────────────────────────────────────────────────────
    const OPT_ENABLED        = 'rba_gcal_enabled';
    const OPT_METHOD         = 'rba_gcal_method';         // 'ical' | 'api'
    const OPT_SA_JSON        = 'rba_gcal_sa_json';        // Service Account JSON (encrypted)
    const OPT_CALENDARS      = 'rba_gcal_calendars';      // Danh sách calendars đã map
    const CRON_HOOK          = 'rba_gcal_sync_cron';

    // ── Google API endpoints ──────────────────────────────────────────────────
    const TOKEN_URL          = 'https://oauth2.googleapis.com/token';
    const EVENTS_URL         = 'https://www.googleapis.com/calendar/v3/calendars/{calendarId}/events';
    const CALENDAR_LIST_URL  = 'https://www.googleapis.com/calendar/v3/users/me/calendarList';
    const GOOGLE_ICAL_BASE   = 'https://calendar.google.com/calendar/ical/';

    public function __construct() {
        // ── Hooks ─────────────────────────────────────────────────────────────
        add_action( self::CRON_HOOK,         [ $this, 'run_sync' ] );
        add_action( 'admin_menu',            [ $this, 'register_settings_page' ], 99 );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );

        // ── Push booking events lên Google Calendar (Mode B - API) ────────────
        // Dùng priority 30 để chạy sau KiotViet (priority 10) và OTA API (priority 20)
        add_action( 'rba_booking_confirmed',  [ $this, 'push_room_booking_event'  ], 30, 2 );
        add_action( 'rba_booking_released',   [ $this, 'delete_room_booking_event'], 30, 2 );
        add_action( 'rba_kv_booking_created', [ $this, 'push_kv_booking_event'    ], 30, 6 );

        // ── AJAX ──────────────────────────────────────────────────────────────
        add_action( 'wp_ajax_rba_gcal_test',         [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_rba_gcal_list_cals',    [ $this, 'ajax_list_calendars' ] );
        add_action( 'wp_ajax_rba_gcal_sync_now',     [ $this, 'ajax_sync_now' ] );
        add_action( 'wp_ajax_rba_gcal_save_mapping', [ $this, 'ajax_save_mapping' ] );
        add_action( 'wp_ajax_rba_gcal_test_push',    [ $this, 'ajax_test_push' ] );
    }

    // =========================================================================
    // PHƯƠNG THỨC A — iCal URL của Google Calendar (không cần API)
    // =========================================================================

    /**
     * Lấy iCal URL từ Google Calendar ID (public/shared calendar).
     *
     * Google Calendar format:
     *   Public:  https://calendar.google.com/calendar/ical/{ENCODED_ID}/public/basic.ics
     *   Private: https://calendar.google.com/calendar/ical/{ENCODED_ID}/{secret_key}/basic.ics
     *             (lấy từ Calendar Settings → "Secret address in iCal format")
     *
     * @param string $calendar_id  Google Calendar ID (xxx@gmail.com hoặc xxx@group.calendar.google.com)
     * @param string $secret_key   Secret key (cho private calendar, lấy từ Calendar Settings)
     */
    public static function get_ical_url( string $calendar_id, string $secret_key = '' ): string {
        $encoded = rawurlencode( $calendar_id );
        if ( $secret_key ) {
            return self::GOOGLE_ICAL_BASE . $encoded . '/' . rawurlencode( $secret_key ) . '/basic.ics';
        }
        return self::GOOGLE_ICAL_BASE . $encoded . '/public/basic.ics';
    }

    /**
     * Sync 1 calendar bằng iCal URL → dùng lại RBA_iCal_Sync::parse_ical().
     * Đây là phương thức ưu tiên vì không cần API key.
     */
    private function sync_via_ical( array $mapping ): array {
        $ical_url  = $mapping['ical_url'] ?? '';
        $room_id   = (int) ( $mapping['wp_room_id'] ?? 0 );
        $cal_name  = $mapping['name'] ?? 'Google Calendar';

        if ( ! $ical_url || ! $room_id ) {
            return [ 'status' => 'error', 'message' => 'Thiếu iCal URL hoặc room_id' ];
        }

        // Tạo source object giả để dùng với RBA_iCal_Sync::sync_one()
        $source = (object) [
            'id'          => 'gcal_' . md5( $ical_url ),
            'room_id'     => $room_id,
            'source_name' => $cal_name,
            'ical_url'    => $ical_url,
            'last_synced' => null,
            'sync_status' => 'pending',
            'error_msg'   => null,
        ];

        // Dùng lại toàn bộ logic của RBA_iCal_Sync (parser + block/unblock)
        if ( class_exists( 'RBA_iCal_Sync' ) ) {
            $ical_sync = new RBA_iCal_Sync_Standalone();
            return $ical_sync->sync_single_source( $source );
        }

        return [ 'status' => 'error', 'message' => 'RBA_iCal_Sync chưa được load' ];
    }

    // =========================================================================
    // PHƯƠNG THỨC B — Google Calendar API + Service Account
    // =========================================================================

    /**
     * Lấy JWT access token từ Service Account JSON.
     *
     * Không cần thư viện Google API Client — tự tạo JWT theo RFC 7519.
     * Hoàn toàn tương thích PHP 8.0+, không cần composer.
     */
    private function get_service_account_token(): string {
        $cached = get_transient( 'rba_gcal_sa_token' );
        if ( $cached ) return $cached;

        $sa_json = $this->get_service_account_json();
        if ( ! $sa_json ) {
            $this->log( 'Service Account JSON chưa được cấu hình' );
            return '';
        }

        $sa = json_decode( $sa_json, true );
        if ( ! isset( $sa['private_key'], $sa['client_email'] ) ) {
            $this->log( 'Service Account JSON không hợp lệ — thiếu private_key hoặc client_email' );
            return '';
        }

        // Tạo JWT header + payload
        $now = time();
        $header = $this->base64url_encode( wp_json_encode( [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ] ) );
        $payload = $this->base64url_encode( wp_json_encode( [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
            'aud'   => self::TOKEN_URL,
            'exp'   => $now + 3600,
            'iat'   => $now,
        ] ) );

        $signing_input = $header . '.' . $payload;

        // Sign bằng RS256 — dùng openssl_sign (có sẵn trong PHP)
        $private_key = openssl_pkey_get_private( $sa['private_key'] );
        if ( ! $private_key ) {
            $this->log( 'Không đọc được private key từ Service Account JSON' );
            return '';
        }

        $signature = '';
        if ( ! openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 ) ) {
            $this->log( 'Ký JWT thất bại' );
            return '';
        }

        $jwt = $signing_input . '.' . $this->base64url_encode( $signature );

        // Đổi JWT lấy access token
        $response = wp_remote_post( self::TOKEN_URL, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $err = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
            $this->log( 'Lấy token thất bại: ' . $err );
            return '';
        }

        $body  = json_decode( wp_remote_retrieve_body( $response ), true );
        $token = $body['access_token'] ?? '';

        if ( $token ) {
            // Cache 55 phút (token TTL 60 phút)
            set_transient( 'rba_gcal_sa_token', $token, 55 * MINUTE_IN_SECONDS );
        }

        return $token;
    }

    /**
     * Fetch events từ Google Calendar API.
     *
     * @param string $calendar_id  Google Calendar ID
     * @param string $time_min     ISO 8601 start (mặc định: hôm nay)
     * @param string $time_max     ISO 8601 end (mặc định: 12 tháng tới)
     * @return array               Mảng events [ { start, end, summary, id } ]
     */
    public function fetch_events_api( string $calendar_id, string $time_min = '', string $time_max = '' ): array {
        $token = $this->get_service_account_token();
        if ( ! $token ) return [];

        if ( ! $time_min ) $time_min = gmdate( 'Y-m-d\T00:00:00\Z' );
        if ( ! $time_max ) $time_max = gmdate( 'Y-m-d\T00:00:00\Z', strtotime( '+12 months' ) );

        $url = str_replace( '{calendarId}', rawurlencode( $calendar_id ), self::EVENTS_URL );
        $url .= '?' . http_build_query( [
            'timeMin'      => $time_min,
            'timeMax'      => $time_max,
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => 2500,
            'fields'       => 'items(id,summary,start,end,status)',
        ] );

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $err = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
            $this->log( "Fetch events lỗi [{$calendar_id}]: " . $err );
            if ( wp_remote_retrieve_response_code( $response ) === 401 ) {
                delete_transient( 'rba_gcal_sa_token' ); // Force refresh token lần sau
            }
            return [];
        }

        $data   = json_decode( wp_remote_retrieve_body( $response ), true );
        $items  = $data['items'] ?? [];
        $events = [];

        foreach ( $items as $item ) {
            // Bỏ qua events đã cancelled
            if ( ( $item['status'] ?? '' ) === 'cancelled' ) continue;

            $start = $item['start']['date']     ?? substr( $item['start']['dateTime'] ?? '', 0, 10 );
            $end   = $item['end']['date']       ?? substr( $item['end']['dateTime']   ?? '', 0, 10 );

            if ( ! $start || ! $end ) continue;

            $events[] = [
                'id'      => $item['id'],
                'summary' => $item['summary'] ?? 'Booking',
                'start'   => $start,
                'end'     => $end,
            ];
        }

        return $events;
    }

    /**
     * Lấy danh sách calendars mà service account có quyền đọc.
     * (Cần share calendar với email service account trước)
     */
    public function list_calendars(): array {
        $token = $this->get_service_account_token();
        if ( ! $token ) return [];

        $response = wp_remote_get( self::CALENDAR_LIST_URL, [
            'timeout' => 15,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return array_map( fn( $cal ) => [
            'id'      => $cal['id'],
            'summary' => $cal['summary'] ?? $cal['id'],
            'color'   => $cal['backgroundColor'] ?? '#4285f4',
        ], $data['items'] ?? [] );
    }

    // =========================================================================
    // SYNC RUNNER
    // =========================================================================

    /**
     * Chạy sync tất cả calendars đã được map.
     * Mỗi mapping có thể dùng phương thức A (iCal) hoặc B (API).
     */
    public function run_sync(): void {
        if ( ! $this->is_enabled() ) return;

        $calendars = $this->get_calendars();
        if ( empty( $calendars ) ) return;

        $method   = get_option( self::OPT_METHOD, 'ical' );
        $synced   = 0;
        $errors   = 0;

        foreach ( $calendars as $mapping ) {
            if ( empty( $mapping['wp_room_id'] ) || empty( $mapping['calendar_id'] ) ) continue;

            if ( 'ical' === $method || ! empty( $mapping['ical_url'] ) ) {
                // ── Phương thức A: iCal URL ────────────────────────────────
                $result = $this->sync_via_ical( $mapping );
                if ( 'ok' === ( $result['status'] ?? '' ) ) $synced++;
                else $errors++;
            } else {
                // ── Phương thức B: Google Calendar API ────────────────────
                $result = $this->sync_via_api( $mapping );
                if ( 'ok' === ( $result['status'] ?? '' ) ) $synced++;
                else $errors++;
            }
        }

        update_option( 'rba_gcal_last_sync', [
            'time'   => current_time( 'mysql' ),
            'synced' => $synced,
            'errors' => $errors,
        ], false );

        $this->log( "Sync hoàn tất: {$synced} OK, {$errors} lỗi." );
    }

    /**
     * Sync 1 calendar bằng Google Calendar API → block/unblock dates.
     */
    private function sync_via_api( array $mapping ): array {
        $calendar_id = $mapping['calendar_id'];
        $room_id     = (int) $mapping['wp_room_id'];

        if ( ! $calendar_id || ! $room_id ) {
            return [ 'status' => 'error', 'message' => 'Thiếu calendar_id hoặc room_id' ];
        }

        $events = $this->fetch_events_api( $calendar_id );
        if ( empty( $events ) && ! is_array( $events ) ) {
            return [ 'status' => 'error', 'message' => 'Không fetch được events' ];
        }

        // Lấy danh sách events đã lưu trước đó để phát hiện events bị xóa
        $option_key     = 'rba_gcal_events_' . md5( $calendar_id . '_' . $room_id );
        $previous_ids   = get_option( $option_key, [] );
        $current_ids    = [];

        foreach ( $events as $event ) {
            $event_key      = md5( $event['id'] . $event['start'] . $event['end'] );
            $current_ids[]  = $event_key;
            $prev_data      = get_option( 'rba_gcal_ev_' . $event_key );

            if ( ! $prev_data ) {
                // Event mới → block dates
                RBA_Database::block_dates_from_ical( $room_id, $event['start'], $event['end'] );
                update_option( 'rba_gcal_ev_' . $event_key, [
                    'room_id' => $room_id,
                    'start'   => $event['start'],
                    'end'     => $event['end'],
                    'gcal_id' => $event['id'],
                ], false );
            }
        }

        // Events đã biến mất → unblock
        $removed_ids = array_diff( $previous_ids, $current_ids );
        foreach ( $removed_ids as $old_key ) {
            $old = get_option( 'rba_gcal_ev_' . $old_key );
            if ( $old && (int) $old['room_id'] === $room_id ) {
                RBA_Database::unblock_ical_dates( $room_id, $old['start'], $old['end'] );
                delete_option( 'rba_gcal_ev_' . $old_key );
            }
        }

        // Lưu danh sách current event IDs
        update_option( $option_key, $current_ids, false );

        $this->log( "API sync calendar [{$calendar_id}] → room #{$room_id}: " . count( $events ) . " events." );
        return [ 'status' => 'ok', 'events' => count( $events ) ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private function get_service_account_json(): string {
        // Decrypt trước khi trả về
        $encrypted = get_option( self::OPT_SA_JSON, '' );
        if ( ! $encrypted ) return '';
        // Dùng WP auth key làm encryption key
        $key = substr( AUTH_KEY, 0, 32 );
        $decoded = base64_decode( $encrypted );
        if ( strlen( $decoded ) < 16 ) return $decoded; // unencrypted fallback
        $iv        = substr( $decoded, 0, 16 );
        $cipher    = substr( $decoded, 16 );
        $decrypted = openssl_decrypt( $cipher, 'AES-128-CBC', $key, 0, $iv );
        return $decrypted ?: $decoded;
    }

    private function save_service_account_json( string $json ): void {
        if ( ! $json ) {
            delete_option( self::OPT_SA_JSON );
            return;
        }
        // Encrypt trước khi lưu — tránh lộ private key trong DB
        $key       = substr( AUTH_KEY, 0, 32 );
        $iv        = random_bytes( 16 );
        $cipher    = openssl_encrypt( $json, 'AES-128-CBC', $key, 0, $iv );
        update_option( self::OPT_SA_JSON, base64_encode( $iv . $cipher ) );
    }

    private function get_calendars(): array {
        return get_option( self::OPT_CALENDARS, [] );
    }

    private function save_calendars( array $calendars ): void {
        update_option( self::OPT_CALENDARS, $calendars );
    }

    private function is_enabled(): bool {
        return (bool) get_option( self::OPT_ENABLED, false );
    }

    private function log( string $msg ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( '[RBA_GCal] ' . $msg );
        $logs   = get_option( 'rba_gcal_logs', [] );
        $logs[] = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $msg;
        if ( count( $logs ) > 100 ) $logs = array_slice( $logs, -100 );
        update_option( 'rba_gcal_logs', $logs, false );
    }

    // =========================================================================
    // ADMIN PAGE
    // =========================================================================

    public function register_settings_page(): void {
        $tf_slug = $this->detect_parent_menu();
        $parent  = $tf_slug ?: 'rba-dashboard';
        add_submenu_page(
            $parent,
            'Google Calendar Bridge',
            'Google Calendar',
            'manage_options',
            'rba-gcal',
            [ $this, 'render_page' ]
        );
    }

    private function detect_parent_menu(): string {
        global $menu, $submenu;
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
        register_setting( 'rba_gcal_settings', self::OPT_ENABLED, [ 'type' => 'boolean' ] );
        register_setting( 'rba_gcal_settings', self::OPT_METHOD,  [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ] );
    }

    public function render_page(): void {
        $method    = get_option( self::OPT_METHOD, 'ical' );
        $enabled   = (bool) get_option( self::OPT_ENABLED, false );
        $calendars = $this->get_calendars();
        $last_sync = get_option( 'rba_gcal_last_sync', [] );
        $logs      = array_reverse( get_option( 'rba_gcal_logs', [] ) );
        $rooms     = get_posts( [ 'post_type' => 'tf_room', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title' ] );

        $active_tab = sanitize_key( $_GET['tab'] ?? 'setup' );
        ?>
        <div class="wrap" style="max-width:960px">
            <h1>
                <span class="dashicons dashicons-calendar-alt" style="font-size:26px;vertical-align:middle;margin-right:8px;color:#4285f4"></span>
                Google Calendar Bridge
                <span style="font-size:13px;color:#888;font-weight:normal;margin-left:8px">
                    Cho OTA không có iCal trực tiếp (Traveloka, Trip.com...)
                </span>
            </h1>

            <?php settings_errors(); ?>

            <?php
            // Tab navigation
            $tabs = [
                'setup'   => 'Thiết lập',
                'mapping' => 'Map Phòng',
                'logs'    => 'Logs',
            ];
            echo '<nav class="nav-tab-wrapper" style="margin-bottom:0">';
            foreach ( $tabs as $slug => $label ) {
                $cls = $slug === $active_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
                echo '<a href="' . esc_url( admin_url( 'admin.php?page=rba-gcal&tab=' . $slug ) ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</a>';
            }
            echo '</nav>';
            echo '<div style="background:#fff;border:1px solid #ccd0d4;border-top:none;padding:24px">';
            ?>

            <?php if ( 'setup' === $active_tab ) : ?>

                <!-- METHOD COMPARISON CARD -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
                    <div style="border:2px solid <?php echo $method === 'ical' ? '#2e7d32' : '#ddd'; ?>;border-radius:8px;padding:16px;cursor:pointer" onclick="document.getElementById('method_ical').click()">
                        <h3 style="margin:0 0 8px 0;color:#2e7d32">
                            <input type="radio" name="method_radio" id="method_ical" value="ical" <?php checked( $method, 'ical' ); ?> style="margin-right:6px">
                            Phương thức A — iCal URL
                            <span style="background:#e8f5e9;color:#2e7d32;font-size:11px;padding:2px 6px;border-radius:4px;font-weight:normal;margin-left:6px">KHUYẾN NGHỊ</span>
                        </h3>
                        <ul style="font-size:13px;color:#444;margin:0;padding-left:20px">
                            <li>Không cần Google Cloud account</li>
                            <li>Không cần API key</li>
                            <li>Dùng iCal Secret URL của Google Calendar</li>
                            <li>Phù hợp: Calendar được share public hoặc có secret URL</li>
                        </ul>
                    </div>
                    <div style="border:2px solid <?php echo $method === 'api' ? '#1565c0' : '#ddd'; ?>;border-radius:8px;padding:16px;cursor:pointer" onclick="document.getElementById('method_api').click()">
                        <h3 style="margin:0 0 8px 0;color:#1565c0">
                            <input type="radio" name="method_radio" id="method_api" value="api" <?php checked( $method, 'api' ); ?> style="margin-right:6px">
                            Phương thức B — Google Calendar API
                        </h3>
                        <ul style="font-size:13px;color:#444;margin:0;padding-left:20px">
                            <li>Cần Google Cloud Project (free)</li>
                            <li>Cần Service Account JSON</li>
                            <li>Đọc được calendar <strong>private</strong></li>
                            <li>Quota: 1.000.000 request/ngày — miễn phí</li>
                        </ul>
                    </div>
                </div>

                <form method="post" action="options.php">
                    <?php settings_fields( 'rba_gcal_settings' ); ?>
                    <input type="hidden" name="<?php echo esc_attr( self::OPT_METHOD ); ?>" id="method_hidden" value="<?php echo esc_attr( $method ); ?>">

                    <table class="form-table" style="margin-top:0">
                        <tr>
                            <th>Bật Google Calendar Bridge</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPT_ENABLED ); ?>" value="1" <?php checked( $enabled ); ?>>
                                    Tự động sync mỗi 15 phút (dùng chung cron iCal)
                                </label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Lưu cài đặt' ); ?>
                </form>

                <!-- PHƯƠNG THỨC A INSTRUCTIONS -->
                <div id="setup-ical" style="display:<?php echo $method !== 'api' ? 'block' : 'none'; ?>">
                    <h3>Hướng dẫn — Phương thức A: iCal URL</h3>
                    <div style="background:#f9fbe7;border-left:4px solid #9ccc65;padding:16px;border-radius:0 6px 6px 0;margin-bottom:16px">
                        <ol style="margin:0;font-size:13px;line-height:1.8">
                            <li>Mở <strong>Google Calendar</strong> trên máy tính (calendar.google.com)</li>
                            <li>Ở sidebar trái, hover vào tên calendar → click <strong>3 chấm (...)</strong> → <strong>Settings and sharing</strong></li>
                            <li>Kéo xuống phần <strong>"Integrate calendar"</strong></li>
                            <li>Copy <strong>"Secret address in iCal format"</strong> (URL dạng .../basic.ics) — <em>Đây là URL private, không chia sẻ ra ngoài</em></li>
                            <li>Paste URL vào tab <strong>"Map Phòng"</strong> bên cạnh phòng tương ứng</li>
                            <li>Chọn phương thức <strong>iCal URL</strong> cho từng mapping</li>
                        </ol>
                    </div>
                    <div style="background:#fff3e0;border-left:4px solid #ff9800;padding:12px;border-radius:0 6px 6px 0">
                        <strong>Lưu ý với Traveloka/Trip.com:</strong> OTA cần <em>import</em> calendar của bạn (để biết ngày blocked),
                        hoặc bạn kết nối ứng dụng OTA partner với Google Calendar trước, rồi plugin đọc ngược lại.
                    </div>
                </div>

                <!-- PHƯƠNG THỨC B INSTRUCTIONS + SERVICE ACCOUNT UPLOAD -->
                <div id="setup-api" style="display:<?php echo $method === 'api' ? 'block' : 'none'; ?>">
                    <h3>Hướng dẫn — Phương thức B: Google Calendar API</h3>
                    <div style="background:#e3f2fd;border-left:4px solid #2196f3;padding:16px;border-radius:0 6px 6px 0;margin-bottom:16px">
                        <ol style="margin:0;font-size:13px;line-height:2">
                            <li>Truy cập <a href="https://console.cloud.google.com/" target="_blank"><strong>console.cloud.google.com</strong></a> → Tạo project mới (VD: "my-resort-calendar")</li>
                            <li>Vào <strong>APIs & Services → Enable APIs</strong> → Tìm "<strong>Google Calendar API</strong>" → Enable</li>
                            <li>Vào <strong>IAM & Admin → Service Accounts</strong> → <strong>Create Service Account</strong></li>
                            <li>Đặt tên (VD: "resort-booking") → Create → bỏ qua các bước phân quyền</li>
                            <li>Click vào service account vừa tạo → tab <strong>Keys</strong> → <strong>Add Key → Create new key → JSON</strong> → Download file .json</li>
                            <li>Upload file JSON vào ô bên dưới</li>
                            <li>Trong Google Calendar: Share calendar với <strong>email của service account</strong> (dạng: xxx@project.iam.gserviceaccount.com) với quyền <em>"See all event details"</em></li>
                        </ol>
                    </div>

                    <!-- Service Account JSON Upload -->
                    <div style="border:1px solid #e0e0e0;border-radius:6px;padding:16px;margin-bottom:16px">
                        <h4 style="margin:0 0 12px 0">Upload Service Account JSON</h4>
                        <?php
                        $has_sa = ! empty( get_option( self::OPT_SA_JSON ) );
                        if ( $has_sa ) {
                            $sa = json_decode( $this->get_service_account_json(), true );
                            echo '<div style="background:#e8f5e9;padding:10px;border-radius:4px;margin-bottom:10px;font-size:13px">';
                            echo '<strong>✓ Đã cấu hình:</strong> ' . esc_html( $sa['client_email'] ?? 'Service Account' );
                            echo ' — Project: ' . esc_html( $sa['project_id'] ?? '?' );
                            echo '</div>';
                        }
                        ?>
                        <div style="display:flex;gap:10px;align-items:center">
                            <input type="file" id="rba-gcal-sa-file" accept=".json" style="flex:1">
                            <button type="button" class="button button-primary" id="rba-gcal-upload-sa">Upload JSON</button>
                            <?php if ( $has_sa ) : ?>
                                <button type="button" class="button" id="rba-gcal-remove-sa" style="color:#c62828">Xóa</button>
                            <?php endif; ?>
                        </div>
                        <p id="rba-gcal-sa-msg" style="margin-top:8px;font-size:13px"></p>
                    </div>

                    <!-- Test + List Calendars -->
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <button type="button" class="button button-primary" id="rba-gcal-test">
                            Test kết nối API
                        </button>
                        <button type="button" class="button" id="rba-gcal-list-cals">
                            Lấy danh sách Calendars
                        </button>
                        <span id="rba-gcal-test-msg"></span>
                    </div>
                    <div id="rba-gcal-cal-list" style="margin-top:12px"></div>
                </div>

                <?php if ( $last_sync ) : ?>
                    <hr>
                    <p style="color:#555;font-size:13px">
                        Sync gần nhất: <strong><?php echo esc_html( $last_sync['time'] ?? '' ); ?></strong>
                        — <?php echo esc_html( $last_sync['synced'] ?? 0 ); ?> OK,
                        <?php echo esc_html( $last_sync['errors'] ?? 0 ); ?> lỗi
                        <button type="button" class="button button-small" id="rba-gcal-sync-now" style="margin-left:10px">Sync ngay</button>
                    </p>
                <?php endif; ?>

                <!-- PUSH BOOKING EVENTS INFO -->
                <?php $method = get_option( self::OPT_METHOD, 'ical' ); ?>
                <hr>
                <div style="background:<?php echo $method === 'api' ? '#e8f5e9' : '#fff3e0'; ?>;border:1px solid <?php echo $method === 'api' ? '#a5d6a7' : '#ffb74d'; ?>;border-radius:6px;padding:14px;margin-top:4px">
                    <strong style="font-size:13px">
                        <?php echo $method === 'api' ? '✅ Push Booking → Google Calendar: Đang hoạt động' : '⚠️ Push Booking → Google Calendar: Cần Mode B (API)'; ?>
                    </strong>
                    <p style="font-size:12px;color:#555;margin:6px 0 0">
                        <?php if ( $method === 'api' ) : ?>
                            Mỗi khi khách đặt phòng/tour, event sẽ tự động tạo trên Google Calendar.
                            Khi hủy booking, event sẽ tự động xóa.
                        <?php else : ?>
                            Tính năng push booking chỉ hoạt động ở <strong>Mode B — Google Calendar API</strong>.
                            Chuyển mode và upload Service Account JSON để bật.
                        <?php endif; ?>
                    </p>
                    <?php if ( $method === 'api' ) : ?>
                    <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <select id="rba-gcal-test-room" style="flex:1;max-width:260px">
                            <option value="0">-- Chọn phòng để test --</option>
                            <?php foreach ( $rooms as $room ) : ?>
                            <option value="<?php echo esc_attr($room->ID); ?>"><?php echo esc_html($room->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button button-primary" id="rba-gcal-test-push"
                                data-nonce="<?php echo esc_attr( wp_create_nonce('rba_gcal_test') ); ?>">
                            🧪 Test Push Event
                        </button>
                        <span id="rba-gcal-push-msg" style="font-size:13px"></span>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ( 'mapping' === $active_tab ) : ?>
                <h3 style="margin-top:0">Map Phòng ↔ Google Calendar</h3>
                <p style="color:#555;font-size:13px">
                    Mỗi dòng = 1 liên kết giữa phòng WordPress và 1 Google Calendar.
                    Có thể map nhiều calendars cho 1 phòng (VD: 1 phòng có cả Traveloka lẫn Trip.com).
                </p>

                <button type="button" class="button" id="rba-gcal-add-row" style="margin-bottom:12px">+ Thêm mapping</button>

                <table class="wp-list-table widefat" id="rba-gcal-mapping-table">
                    <thead><tr>
                        <th style="width:160px">Phòng</th>
                        <th style="width:120px">Nguồn</th>
                        <th style="width:100px">Phương thức</th>
                        <th>Calendar ID / iCal URL</th>
                        <th style="width:80px">Thao tác</th>
                    </tr></thead>
                    <tbody id="rba-gcal-rows">
                    <?php foreach ( $calendars as $i => $cal ) : ?>
                        <tr data-index="<?php echo esc_attr( $i ); ?>">
                            <td>
                                <select name="gcal_map[<?php echo $i; ?>][wp_room_id]" style="width:100%">
                                    <option value="">-- Chọn phòng --</option>
                                    <?php foreach ( $rooms as $room ) : ?>
                                        <option value="<?php echo esc_attr( $room->ID ); ?>" <?php selected( $cal['wp_room_id'] ?? '', $room->ID ); ?>>
                                            <?php echo esc_html( $room->post_title ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="gcal_map[<?php echo $i; ?>][name]" style="width:100%">
                                    <?php foreach ( [ 'Traveloka', 'Trip.com', 'Expedia', 'Klook', 'Ctrip', 'Google Calendar', 'Khác' ] as $src ) : ?>
                                        <option <?php selected( $cal['name'] ?? '', $src ); ?>><?php echo esc_html( $src ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="gcal_map[<?php echo $i; ?>][sync_method]" style="width:100%">
                                    <option value="ical" <?php selected( $cal['sync_method'] ?? 'ical', 'ical' ); ?>>iCal URL</option>
                                    <option value="api"  <?php selected( $cal['sync_method'] ?? '', 'api' ); ?>>API</option>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="gcal_map[<?php echo $i; ?>][calendar_id]"
                                       value="<?php echo esc_attr( $cal['calendar_id'] ?? '' ); ?>"
                                       style="width:49%" placeholder="Calendar ID (cho API)">
                                <input type="text" name="gcal_map[<?php echo $i; ?>][ical_url]"
                                       value="<?php echo esc_attr( $cal['ical_url'] ?? '' ); ?>"
                                       style="width:49%" placeholder="iCal Secret URL (cho iCal)">
                            </td>
                            <td>
                                <button type="button" class="button button-small rba-gcal-save-row"
                                        data-index="<?php echo esc_attr( $i ); ?>"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'rba_gcal_save_mapping' ) ); ?>">Lưu</button>
                                <button type="button" class="button button-small rba-gcal-del-row" data-index="<?php echo esc_attr( $i ); ?>" style="color:#c62828">Xóa</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ( 'logs' === $active_tab ) : ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <h3 style="margin:0">Activity Logs</h3>
                    <button class="button" id="rba-gcal-clear-logs">Xóa logs</button>
                </div>
                <div style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;line-height:1.7;padding:16px;border-radius:6px;max-height:500px;overflow-y:auto">
                    <?php if ( empty( $logs ) ) : ?>
                        <span style="color:#888">Chưa có log nào.</span>
                    <?php else : ?>
                        <?php foreach ( $logs as $line ) : ?>
                            <div><?php echo esc_html( $line ); ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

            </div><!-- /.tab-content -->
        </div>

        <script>
        (function($){
            const nonces = {
                test:    '<?php echo esc_js( wp_create_nonce( 'rba_gcal_test' ) ); ?>',
                list:    '<?php echo esc_js( wp_create_nonce( 'rba_gcal_list_cals' ) ); ?>',
                sync:    '<?php echo esc_js( wp_create_nonce( 'rba_gcal_sync_now' ) ); ?>',
                mapping: '<?php echo esc_js( wp_create_nonce( 'rba_gcal_save_mapping' ) ); ?>',
                clear:   '<?php echo esc_js( wp_create_nonce( 'rba_gcal_clear_logs' ) ); ?>',
            };

            // Sync method radio → update hidden input + show/hide panels
            $('input[name=method_radio]').on('change', function(){
                $('#method_hidden').val($(this).val());
                $('#setup-ical').toggle($(this).val() !== 'api');
                $('#setup-api').toggle($(this).val() === 'api');
            });

            // Test connection
            $('#rba-gcal-test').on('click', function(){
                const $m = $('#rba-gcal-test-msg').text('Đang kiểm tra...');
                $.post(ajaxurl, { action:'rba_gcal_test', nonce: nonces.test }, function(r){
                    $m.html(r.success
                        ? '<span style="color:#2e7d32">✔ ' + r.data + '</span>'
                        : '<span style="color:#c62828">✘ ' + r.data + '</span>');
                });
            });

            // List calendars
            $('#rba-gcal-list-cals').on('click', function(){
                $.post(ajaxurl, { action:'rba_gcal_list_cals', nonce: nonces.list }, function(r){
                    if(!r.success){ alert('✘ ' + r.data); return; }
                    let html = '<table class="wp-list-table widefat" style="margin-top:12px"><thead><tr><th>Calendar ID</th><th>Tên</th></tr></thead><tbody>';
                    r.data.forEach(c => html += '<tr><td><code>' + c.id + '</code></td><td>' + c.summary + '</td></tr>');
                    html += '</tbody></table>';
                    $('#rba-gcal-cal-list').html(html);
                });
            });

            // Upload SA JSON
            $('#rba-gcal-upload-sa').on('click', function(){
                const file = $('#rba-gcal-sa-file')[0].files[0];
                if(!file){ alert('Chọn file JSON trước'); return; }
                const reader = new FileReader();
                reader.onload = function(e){
                    const $m = $('#rba-gcal-sa-msg');
                    try { JSON.parse(e.target.result); } catch(e){ $m.html('<span style="color:#c62828">File JSON không hợp lệ</span>'); return; }
                    $.post(ajaxurl, { action:'rba_gcal_save_sa', nonce: nonces.test, json: e.target.result }, function(r){
                        $m.html(r.success
                            ? '<span style="color:#2e7d32">✔ Đã lưu: ' + r.data + '</span>'
                            : '<span style="color:#c62828">✘ ' + r.data + '</span>');
                        if(r.success) setTimeout(()=>location.reload(), 1500);
                    });
                };
                reader.readAsText(file);
            });

            // Remove SA
            $('#rba-gcal-remove-sa').on('click', function(){
                if(!confirm('Xóa Service Account JSON?')) return;
                $.post(ajaxurl, { action:'rba_gcal_save_sa', nonce: nonces.test, json: '' }, function(){
                    location.reload();
                });
            });

            // Sync now
            $('#rba-gcal-sync-now').on('click', function(){
                const $b = $(this).prop('disabled', true).text('...');
                $.post(ajaxurl, { action:'rba_gcal_sync_now', nonce: nonces.sync }, function(r){
                    alert(r.success ? '✔ ' + r.data : '✘ ' + r.data);
                    $b.prop('disabled', false).text('Sync ngay');
                });
            });

            // Test push event
            $('#rba-gcal-test-push').on('click', function(){
                const $b   = $(this).prop('disabled', true).text('⏳ Đang tạo...');
                const $msg = $('#rba-gcal-push-msg');
                const room_id = $('#rba-gcal-test-room').val() || 0;
                $msg.text('');
                $.post(ajaxurl, {
                    action:  'rba_gcal_test_push',
                    nonce:   $(this).data('nonce'),
                    room_id: room_id,
                }, function(r){
                    $b.prop('disabled', false).text('🧪 Test Push Event');
                    if(r.success){
                        $msg.html('<span style="color:#2e7d32">' + r.data.message +
                            ' — <a href="' + r.data.view_url + '" target="_blank">Mở Google Calendar</a></span>');
                    } else {
                        $msg.html('<span style="color:#c62828">✘ ' + r.data + '</span>');
                    }
                });
            });

            // Add mapping row
            let rowIdx = <?php echo max( count( $calendars ), 0 ); ?>;
            const rooms = <?php echo wp_json_encode( array_map( fn($r) => ['id' => $r->ID, 'title' => $r->post_title], $rooms ) ); ?>;
            const sources = ['Traveloka','Trip.com','Expedia','Klook','Ctrip','Google Calendar','Khác'];
            $('#rba-gcal-add-row').on('click', function(){
                const opts = rooms.map(r => '<option value="'+r.id+'">'+r.title+'</option>').join('');
                const srcs = sources.map(s => '<option>'+s+'</option>').join('');
                $('#rba-gcal-rows').append(`
                    <tr data-index="${rowIdx}">
                        <td><select name="gcal_map[${rowIdx}][wp_room_id]" style="width:100%"><option value="">-- Chọn --</option>${opts}</select></td>
                        <td><select name="gcal_map[${rowIdx}][name]" style="width:100%">${srcs}</select></td>
                        <td><select name="gcal_map[${rowIdx}][sync_method]" style="width:100%"><option value="ical">iCal URL</option><option value="api">API</option></select></td>
                        <td>
                            <input type="text" name="gcal_map[${rowIdx}][calendar_id]" style="width:49%" placeholder="Calendar ID (cho API)">
                            <input type="text" name="gcal_map[${rowIdx}][ical_url]" style="width:49%" placeholder="iCal Secret URL (cho iCal)">
                        </td>
                        <td>
                            <button type="button" class="button button-small rba-gcal-save-row" data-index="${rowIdx}" data-nonce="${nonces.mapping}">Lưu</button>
                            <button type="button" class="button button-small rba-gcal-del-row" data-index="${rowIdx}" style="color:#c62828">Xóa</button>
                        </td>
                    </tr>`);
                rowIdx++;
            });

            // Save row
            $(document).on('click', '.rba-gcal-save-row', function(){
                const $row = $(this).closest('tr');
                const idx  = $(this).data('index');
                const data = {
                    action: 'rba_gcal_save_mapping',
                    nonce:  $(this).data('nonce'),
                    index:  idx,
                    wp_room_id:   $row.find('[name*=wp_room_id]').val(),
                    name:         $row.find('[name*="[name]"]').val(),
                    sync_method:  $row.find('[name*=sync_method]').val(),
                    calendar_id:  $row.find('[name*=calendar_id]').val(),
                    ical_url:     $row.find('[name*=ical_url]').val(),
                };
                const $b = $(this).prop('disabled',true).text('...');
                $.post(ajaxurl, data, function(r){
                    $b.prop('disabled',false).text('Lưu');
                    $b.after(r.success ? '<span style="color:#2e7d32;margin-left:4px">✔</span>' : '<span style="color:#c62828;margin-left:4px">✘</span>');
                    setTimeout(()=>$b.nextAll('span').first().remove(), 2000);
                });
            });

            // Delete row
            $(document).on('click', '.rba-gcal-del-row', function(){
                const idx = $(this).data('index');
                $(this).closest('tr').remove();
                $.post(ajaxurl, { action:'rba_gcal_save_mapping', nonce: nonces.mapping, index: idx, delete: 1 });
            });

            // Clear logs
            $('#rba-gcal-clear-logs').on('click', function(){
                $.post(ajaxurl, { action:'rba_gcal_clear_logs', nonce: nonces.clear }, ()=>location.reload());
            });
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    public function ajax_test_connection(): void {
        check_ajax_referer( 'rba_gcal_test', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $method = get_option( self::OPT_METHOD, 'ical' );
        if ( 'ical' === $method ) {
            wp_send_json_success( 'Phương thức iCal URL không cần test — nhập URL vào tab Map Phòng là đủ.' );
        }

        delete_transient( 'rba_gcal_sa_token' );
        $token = $this->get_service_account_token();
        if ( ! $token ) {
            wp_send_json_error( 'Không lấy được token. Kiểm tra Service Account JSON.' );
        }

        $sa   = json_decode( $this->get_service_account_json(), true );
        $cals = $this->list_calendars();
        wp_send_json_success( 'Kết nối thành công! Service Account: ' . ( $sa['client_email'] ?? '?' ) . '. Tìm thấy ' . count( $cals ) . ' calendars.' );
    }

    public function ajax_list_calendars(): void {
        check_ajax_referer( 'rba_gcal_list_cals', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $cals = $this->list_calendars();
        if ( empty( $cals ) ) wp_send_json_error( 'Không có calendar nào. Share calendar với email service account trước.' );
        wp_send_json_success( $cals );
    }

    public function ajax_sync_now(): void {
        check_ajax_referer( 'rba_gcal_sync_now', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $this->run_sync();
        $last = get_option( 'rba_gcal_last_sync', [] );
        wp_send_json_success( 'Sync xong: ' . ( $last['synced'] ?? 0 ) . ' OK, ' . ( $last['errors'] ?? 0 ) . ' lỗi.' );
    }

    public function ajax_save_mapping(): void {
        check_ajax_referer( 'rba_gcal_save_mapping', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $calendars = $this->get_calendars();
        $index     = absint( $_POST['index'] ?? count( $calendars ) );

        if ( ! empty( $_POST['delete'] ) ) {
            unset( $calendars[ $index ] );
            $this->save_calendars( array_values( $calendars ) );
            wp_send_json_success( 'Deleted' );
        }

        $calendars[ $index ] = [
            'wp_room_id'  => absint( $_POST['wp_room_id']  ?? 0 ),
            'name'        => sanitize_text_field( wp_unslash( $_POST['name']        ?? 'Google Calendar' ) ),
            'sync_method' => in_array( $_POST['sync_method'] ?? 'ical', [ 'ical', 'api' ], true ) ? $_POST['sync_method'] : 'ical',
            'calendar_id' => sanitize_text_field( wp_unslash( $_POST['calendar_id'] ?? '' ) ),
            'ical_url'    => esc_url_raw( wp_unslash( $_POST['ical_url'] ?? '' ) ),
        ];

        $this->save_calendars( array_values( $calendars ) );
        wp_send_json_success( 'Saved' );
    }

    // =========================================================================
    // PUSH BOOKING → GOOGLE CALENDAR  (chỉ hoạt động ở Mode B - API)
    // =========================================================================

    /**
     * Hook: rba_booking_confirmed
     * Website booking confirmed → tạo all-day event trên GCal.
     */
    public function push_room_booking_event( int $order_id, \WC_Order $order ): void {
        if ( ! $this->is_enabled() || get_option( self::OPT_METHOD, 'ical' ) !== 'api' ) return;

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $room_id   = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            $check_in  = (string) ( $item->get_meta( 'tf_check_in' )  ?: $item->get_meta( 'check_in' ) );
            $check_out = (string) ( $item->get_meta( 'tf_check_out' ) ?: $item->get_meta( 'check_out' ) );
            if ( ! $room_id || ! $check_in || ! $check_out ) continue;

            $guest = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $phone = $order->get_billing_phone();
            $email = $order->get_billing_email();
            $total = number_format( (float) $order->get_total(), 0, ',', '.' );
            $room  = get_the_title( $room_id );
            $nights = (int) ( ( strtotime( $check_out ) - strtotime( $check_in ) ) / DAY_IN_SECONDS );

            $event_id = $this->create_event( $room_id, [
                'summary'     => "🏨 {$room} — {$guest}",
                'description' => implode( "\n", array_filter( [
                    "Khách: {$guest}",
                    $phone ? "SĐT: {$phone}"   : '',
                    $email ? "Email: {$email}" : '',
                    "Phòng: {$room}",
                    "Check-in: " . date_i18n( 'd/m/Y', strtotime( $check_in ) ),
                    "Check-out: " . date_i18n( 'd/m/Y', strtotime( $check_out ) ),
                    "Số đêm: {$nights}",
                    "Tổng: {$total} ₫",
                    "Order #: {$order_id}",
                    "Nguồn: Website trực tiếp",
                ] ) ),
                'start'    => $check_in,
                'end'      => $check_out,
                'colorId'  => '2',  // Sage/xanh lá = booking mới
            ] );

            if ( $event_id ) {
                $order->update_meta_data( "_rba_gcal_event_{$room_id}", $event_id );
                $order->save_meta_data();
                $this->log( "push_room_booking: event [{$event_id}] → order #{$order_id} room #{$room_id}" );
            }
        }
    }

    /**
     * Hook: rba_booking_released
     * Booking bị hủy → xóa event trên GCal.
     */
    public function delete_room_booking_event( int $order_id, \WC_Order $order ): void {
        if ( ! $this->is_enabled() || get_option( self::OPT_METHOD, 'ical' ) !== 'api' ) return;

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $room_id  = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            if ( ! $room_id ) continue;

            $event_id = (string) $order->get_meta( "_rba_gcal_event_{$room_id}" );
            if ( ! $event_id ) continue;

            $deleted = $this->delete_event( $room_id, $event_id );
            if ( $deleted ) {
                $order->delete_meta_data( "_rba_gcal_event_{$room_id}" );
                $order->save_meta_data();
                $this->log( "delete_room_booking: event [{$event_id}] deleted ← order #{$order_id}" );
            }
        }
    }

    /**
     * Hook: rba_kv_booking_created
     * KiotViet webhook (booking từ OTA: Booking.com, Agoda...) → push lên GCal.
     *
     * @param int    $kv_id   KiotViet booking ID
     * @param int    $room_id WP room post ID
     * @param string $date_in  Y-m-d
     * @param string $date_out Y-m-d
     * @param string $channel  Tên kênh: 'Booking.com', 'Agoda', 'walk-in'...
     * @param array  $booking  Raw booking data từ KiotViet
     */
    public function push_kv_booking_event( int $kv_id, int $room_id, string $date_in,
                                           string $date_out, string $channel, array $booking ): void {
        if ( ! $this->is_enabled() || get_option( self::OPT_METHOD, 'ical' ) !== 'api' ) return;

        // Tránh push trùng
        if ( get_option( "rba_gcal_kv_{$kv_id}" ) ) return;

        $icon = match ( strtolower( trim( $channel ) ) ) {
            'booking.com' => '🌐',
            'agoda'       => '🟡',
            'airbnb'      => '🔴',
            'traveloka'   => '🔵',
            'expedia'     => '🟣',
            'walk-in', 'walkin', 'kiotviet' => '🚶',
            default       => '📱',
        };

        $guest = (string) ( $booking['customerName'] ?? $booking['customer_name'] ?? 'Khách OTA' );
        $phone = (string) ( $booking['contactNumber'] ?? $booking['phone'] ?? '' );
        $total = number_format( (float) ( $booking['totalAmount'] ?? $booking['total'] ?? 0 ), 0, ',', '.' );
        $room  = get_the_title( $room_id );
        $nights = (int) ( ( strtotime( $date_out ) - strtotime( $date_in ) ) / DAY_IN_SECONDS );

        $event_id = $this->create_event( $room_id, [
            'summary'     => "{$icon} {$room} — {$guest} ({$channel})",
            'description' => implode( "\n", array_filter( [
                "Khách: {$guest}",
                $phone ? "SĐT: {$phone}" : '',
                "Phòng: {$room}",
                "Check-in: " . date_i18n( 'd/m/Y', strtotime( $date_in ) ),
                "Check-out: " . date_i18n( 'd/m/Y', strtotime( $date_out ) ),
                "Số đêm: {$nights}",
                $total !== '0' ? "Tổng: {$total} ₫" : '',
                "KiotViet ID: #{$kv_id}",
                "Nguồn: {$channel}",
            ] ) ),
            'start'    => $date_in,
            'end'      => $date_out,
            'colorId'  => '5',  // Banana/vàng = từ OTA
        ] );

        if ( $event_id ) {
            update_option( "rba_gcal_kv_{$kv_id}", $event_id, false );
            $this->log( "push_kv_booking: event [{$event_id}] ← KV#{$kv_id} [{$channel}] room #{$room_id}" );
        }
    }

    /**
     * Push tour booking lên GCal.
     * Gọi từ class-rba-tour-addon.php sau khi confirm_tour_slot.
     */
    public function push_tour_event( int $tour_id, string $date, string $slot,
                                     int $adults, int $children, \WC_Order $order ): void {
        if ( ! $this->is_enabled() || get_option( self::OPT_METHOD, 'ical' ) !== 'api' ) return;

        $tour  = get_the_title( $tour_id );
        $guest = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $phone = $order->get_billing_phone();
        $pax   = $adults . ' người lớn' . ( $children ? ", {$children} trẻ em" : '' );

        // Tour mapping: dùng room_id = 0 → fallback calendar đầu tiên
        $event_id = $this->create_event( 0, [
            'summary'     => "🗺️ {$tour} — {$guest}",
            'description' => implode( "\n", array_filter( [
                "Khách: {$guest}",
                $phone ? "SĐT: {$phone}" : '',
                "Tour: {$tour}",
                "Ngày: " . date_i18n( 'd/m/Y', strtotime( $date ) ) . ( $slot ? " lúc {$slot}" : '' ),
                "Khách: {$pax}",
                "Order #: {$order->get_id()}",
            ] ) ),
            'start'    => $date,
            'end'      => $date,  // All-day 1 ngày
            'colorId'  => '7',   // Peacock/xanh dương = tour
        ] );

        if ( $event_id ) {
            $order->update_meta_data( "_rba_gcal_tour_{$tour_id}", $event_id );
            $order->save_meta_data();
            $this->log( "push_tour_event: event [{$event_id}] ← tour #{$tour_id} order #{$order->get_id()}" );
        }
    }

    // =========================================================================
    // GOOGLE CALENDAR API — CREATE / DELETE EVENT
    // =========================================================================

    /**
     * Tạo all-day event trên Google Calendar.
     *
     * GCal color IDs:
     *  1=Lavender 2=Sage 3=Grape 4=Flamingo 5=Banana 6=Tangerine 7=Peacock 8=Graphite 9=Blueberry 10=Basil 11=Tomato
     *
     * @param int   $room_id  WP room ID để tìm calendar_id. 0 = dùng calendar đầu tiên.
     * @param array $event    { summary, description, start(Y-m-d), end(Y-m-d), colorId }
     * @return string|null    GCal event ID hoặc null nếu lỗi
     */
    public function create_event( int $room_id, array $event ): ?string {
        $token       = $this->get_service_account_token();
        $calendar_id = $this->get_calendar_id_for_room( $room_id );

        if ( ! $token || ! $calendar_id ) {
            $this->log( 'create_event: ' . ( ! $token ? 'thiếu token' : "không có calendar map cho room #{$room_id}" ) );
            return null;
        }

        // GCal all-day event: end date phải là ngày SAU ngày cuối cùng
        // Ví dụ: check-out 05/07 → end = '2025-07-06'
        $end_exclusive = gmdate( 'Y-m-d', strtotime( $event['end'] . ' +1 day' ) );

        $body = [
            'summary'     => $event['summary'],
            'description' => $event['description'] ?? '',
            'start'       => [ 'date' => $event['start'] ],
            'end'         => [ 'date' => $end_exclusive ],
            'colorId'     => (string) ( $event['colorId'] ?? '2' ),
            'reminders'   => [ 'useDefault' => false, 'overrides' => [] ],
        ];

        $url = str_replace( '{calendarId}', rawurlencode( $calendar_id ), self::EVENTS_URL );

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log( 'create_event wp_error: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? "HTTP {$code}";
            $this->log( "create_event failed [{$code}]: {$msg}" );
            if ( $code === 401 ) delete_transient( 'rba_gcal_sa_token' );
            return null;
        }

        return $data['id'] ?? null;
    }

    /**
     * Xóa event trên Google Calendar.
     *
     * @param int    $room_id   WP room ID để tìm calendar_id (0 = thử tất cả)
     * @param string $event_id  GCal event ID
     */
    public function delete_event( int $room_id, string $event_id ): bool {
        $token = $this->get_service_account_token();
        if ( ! $token ) return false;

        // Lấy danh sách calendar_ids cần thử
        $calendar_ids = [];
        if ( $room_id > 0 ) {
            $cid = $this->get_calendar_id_for_room( $room_id );
            if ( $cid ) $calendar_ids[] = $cid;
        }
        // Thêm tất cả calendars đã map (thử từng cái)
        foreach ( $this->get_calendars() as $cal ) {
            $cid = $cal['calendar_id'] ?? '';
            if ( $cid && ! in_array( $cid, $calendar_ids, true ) ) $calendar_ids[] = $cid;
        }

        foreach ( $calendar_ids as $calendar_id ) {
            $url = str_replace( '{calendarId}', rawurlencode( $calendar_id ), self::EVENTS_URL )
                 . '/' . rawurlencode( $event_id );

            $response = wp_remote_request( $url, [
                'method'  => 'DELETE',
                'timeout' => 10,
                'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            ] );

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code === 204 || $code === 200 ) {
                $this->log( "delete_event [{$event_id}] OK from calendar [{$calendar_id}]" );
                return true;
            }
            if ( $code === 410 ) {
                // Gone — event đã bị xóa trước đó
                $this->log( "delete_event [{$event_id}] already gone (410)" );
                return true;
            }
        }

        $this->log( "delete_event [{$event_id}] failed — không tìm thấy trong calendar nào" );
        return false;
    }

    /**
     * Lấy calendar_id cho phòng từ mapping đã cấu hình.
     * Nếu room_id = 0 hoặc không tìm thấy → dùng calendar đầu tiên (fallback).
     */
    private function get_calendar_id_for_room( int $room_id ): string {
        $calendars = $this->get_calendars();

        // Tìm chính xác theo room_id
        if ( $room_id > 0 ) {
            foreach ( $calendars as $cal ) {
                if ( (int) ( $cal['wp_room_id'] ?? 0 ) === $room_id
                     && ! empty( $cal['calendar_id'] )
                     && ( $cal['sync_method'] ?? 'api' ) === 'api' ) {
                    return $cal['calendar_id'];
                }
            }
        }

        // Fallback: calendar đầu tiên có ID và sync_method = api
        foreach ( $calendars as $cal ) {
            if ( ! empty( $cal['calendar_id'] ) && ( $cal['sync_method'] ?? '' ) === 'api' ) {
                return $cal['calendar_id'];
            }
        }

        // Fallback cuối: calendar đầu tiên bất kỳ có ID
        foreach ( $calendars as $cal ) {
            if ( ! empty( $cal['calendar_id'] ) ) return $cal['calendar_id'];
        }

        return '';
    }

    // =========================================================================
    // AJAX: TEST PUSH
    // =========================================================================

    /**
     * AJAX: Test push event thủ công lên GCal.
     * Dùng để kiểm tra kết nối trước khi có booking thật.
     */
    public function ajax_test_push(): void {
        check_ajax_referer( 'rba_gcal_test', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $room_id = absint( $_POST['room_id'] ?? 0 );
        $today   = current_time( 'Y-m-d' );
        $tomorrow = gmdate( 'Y-m-d', strtotime( '+1 day' ) );

        // Tạo event test
        $event_id = $this->create_event( $room_id, [
            'summary'     => '🧪 [TEST] Booking thử nghiệm — Plugin RBA',
            'description' => implode( "\n", [
                'Đây là event test từ Resort Booking Addon.',
                "Phòng ID: #{$room_id}",
                "Ngày tạo: " . current_time( 'd/m/Y H:i:s' ),
                'Bạn có thể xóa event này.',
            ] ),
            'start'    => $today,
            'end'      => $tomorrow,
            'colorId'  => '8',  // Graphite = test
        ] );

        if ( ! $event_id ) {
            wp_send_json_error( 'Tạo event thất bại. Xem tab Logs để biết nguyên nhân.' );
        }

        // Xóa luôn event test sau 2s (cleanup)
        // Không xóa ngay để user kịp thấy trên GCal
        update_option( 'rba_gcal_test_event_cleanup', [
            'event_id' => $event_id,
            'room_id'  => $room_id,
            'created'  => time(),
        ], false );

        $cal_id = $this->get_calendar_id_for_room( $room_id );
        $cal_url = "https://calendar.google.com/calendar/r";

        wp_send_json_success( [
            'event_id'   => $event_id,
            'calendar'   => $cal_id,
            'message'    => "✅ Tạo event test thành công! Event ID: {$event_id}",
            'view_url'   => $cal_url,
        ] );
    }
}

// ── AJAX ngoài class (save SA JSON + clear logs) ───────────────────────────
add_action( 'wp_ajax_rba_gcal_save_sa', function (): void {
    check_ajax_referer( 'rba_gcal_test', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $json = sanitize_textarea_field( wp_unslash( $_POST['json'] ?? '' ) );

    if ( ! $json ) {
        delete_option( RBA_GCal::OPT_SA_JSON );
        wp_send_json_success( 'Đã xóa' );
    }

    // Validate JSON structure
    $sa = json_decode( $json, true );
    if ( ! isset( $sa['type'], $sa['private_key'], $sa['client_email'] ) || $sa['type'] !== 'service_account' ) {
        wp_send_json_error( 'File không phải Service Account JSON hợp lệ (thiếu type/private_key/client_email)' );
    }

    // Encrypt và lưu
    $instance = new RBA_GCal();
    $reflection = new ReflectionClass( $instance );
    $method = $reflection->getMethod( 'save_service_account_json' );
    $method->setAccessible( true );
    $method->invoke( $instance, $json );

    wp_send_json_success( $sa['client_email'] );
} );

add_action( 'wp_ajax_rba_gcal_clear_logs', function (): void {
    check_ajax_referer( 'rba_gcal_clear_logs', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();
    delete_option( 'rba_gcal_logs' );
    wp_send_json_success();
} );

new RBA_GCal();
