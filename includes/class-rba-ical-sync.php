<?php
/**
 * RBA_iCal_Sync
 *
 * Sync 2 chiều iCal giữa website và các OTA.
 *
 * FLOW:
 *  1. Inbound  (OTA → Site): Fetch iCal từ từng OTA, block dates trong rba_availability.
 *  2. Website booking: WooCommerce order → tự động giảm availability (RBA_Booking_Guard).
 *  3. Outbound (Site → OTA): Generate iCal feed tổng hợp (orders + OTA blocks từ các kênh khác)
 *     → OTA subscribe feed này → biết ngày nào bị block.
 *
 * Kết quả: Booking ở bất kỳ kênh nào đều dồn về website, rồi đẩy ngược sang các OTA còn lại.
 *
 * @package ResortBookingAddon
 * @since   1.0.3
 */
defined( 'ABSPATH' ) || exit;

class RBA_iCal_Sync {

    public function __construct() {
        add_action( 'rba_ical_sync_cron', [ $this, 'run_all_syncs' ] );
        add_action( 'add_meta_boxes',     [ $this, 'add_ical_metabox' ] );
        add_action( 'save_post_tf_room',  [ $this, 'save_ical_sources' ] );
        add_action( 'init',               [ $this, 'register_feed_rewrite' ] );
        add_filter( 'query_vars',         [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect',  [ $this, 'handle_feed_request' ] );
        add_action( 'wp_ajax_rba_manual_sync', [ $this, 'ajax_manual_sync' ] );
        // Không đăng ký admin_menu ở đây — đã được RBA_Admin xử lý ở class-rba-admin.php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INBOUND: OTA → Website
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Chạy tất cả sync inbound (cron + manual all).
     */
    public function run_all_syncs(): void {
        global $wpdb;
        $sources = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}rba_ical_sources ORDER BY room_id, id" );
        foreach ( $sources as $source ) {
            $this->sync_one( $source );
        }
    }

    /**
     * Sync 1 iCal feed vào website.
     *
     * Thuật toán:
     *  1. Fetch URL → parse VEVENT
     *  2. Insert/update events trong rba_ical_events
     *  3. Block dates trong rba_availability (blocked=1)
     *  4. Xóa events không còn trong feed → unblock dates
     *  5. Update sync_status
     */
    public function sync_one( object $source ): void {
        global $wpdb;
        $src_tbl = $wpdb->prefix . 'rba_ical_sources';
        $evt_tbl = $wpdb->prefix . 'rba_ical_events';

        $response = wp_remote_get( $source->ical_url, [
            'timeout'    => 30,
            'user-agent' => 'Resort-Booking-Addon/1.0 iCalSync (+https://wordpress.org)',
            'sslverify'  => true,
        ] );

        if ( is_wp_error( $response ) ) {
            $wpdb->update( $src_tbl, [
                'sync_status' => 'error',
                'error_msg'   => mb_strimwidth( $response->get_error_message(), 0, 255 ),
                'last_synced' => current_time( 'mysql' ),
            ], [ 'id' => $source->id ], [ '%s', '%s', '%s' ], [ '%d' ] );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $wpdb->update( $src_tbl, [
                'sync_status' => 'error',
                'error_msg'   => "HTTP {$code}",
                'last_synced' => current_time( 'mysql' ),
            ], [ 'id' => $source->id ], [ '%s', '%s', '%s' ], [ '%d' ] );
            return;
        }

        $ical_raw = wp_remote_retrieve_body( $response );
        if ( empty( $ical_raw ) || false === strpos( $ical_raw, 'BEGIN:VCALENDAR' ) ) {
            $wpdb->update( $src_tbl, [
                'sync_status' => 'error',
                'error_msg'   => 'Response không phải iCal hợp lệ (thiếu BEGIN:VCALENDAR)',
                'last_synced' => current_time( 'mysql' ),
            ], [ 'id' => $source->id ], [ '%s', '%s', '%s' ], [ '%d' ] );
            return;
        }

        $events   = $this->parse_ical( $ical_raw );
        $new_uids = [];

        foreach ( $events as $event ) {
            if ( ! $event['dtstart'] || ! $event['dtend'] ) continue;
            if ( $event['dtstart'] >= $event['dtend'] ) continue; // skip zero-length

            $new_uids[] = $event['uid'];

            $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$evt_tbl} WHERE source_id = %d AND uid = %s",
                $source->id, $event['uid']
            ) );

            if ( $existing_id ) {
                // Jika tanggal berubah (booking diperpanjang/dipersingkat), update block
                $old = $wpdb->get_row( $wpdb->prepare( "SELECT date_start, date_end FROM {$evt_tbl} WHERE id = %d", $existing_id ) );
                if ( $old && ( $old->date_start !== $event['dtstart'] || $old->date_end !== $event['dtend'] ) ) {
                    RBA_Database::unblock_ical_dates( $source->room_id, $old->date_start, $old->date_end );
                    RBA_Database::block_dates_from_ical( $source->room_id, $event['dtstart'], $event['dtend'] );
                }
                $wpdb->update( $evt_tbl, [
                    'date_start' => $event['dtstart'],
                    'date_end'   => $event['dtend'],
                    'summary'    => sanitize_text_field( $event['summary'] ),
                ], [ 'id' => $existing_id ], [ '%s', '%s', '%s' ], [ '%d' ] );
            } else {
                $wpdb->insert( $evt_tbl, [
                    'source_id'  => $source->id,
                    'room_id'    => $source->room_id,
                    'uid'        => $event['uid'],
                    'date_start' => $event['dtstart'],
                    'date_end'   => $event['dtend'],
                    'summary'    => sanitize_text_field( $event['summary'] ),
                    'raw_data'   => wp_json_encode( $event ),
                ], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ] );
                RBA_Database::block_dates_from_ical( $source->room_id, $event['dtstart'], $event['dtend'] );
            }
        }

        // Xóa events không còn trong feed → unblock
        if ( ! empty( $new_uids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $new_uids ), '%s' ) );
            $args         = array_merge( [ $source->id ], $new_uids );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $old_events   = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$evt_tbl} WHERE source_id = %d AND uid NOT IN ({$placeholders})",
                $args
            ) );
            foreach ( $old_events as $old ) {
                RBA_Database::unblock_ical_dates( $source->room_id, $old->date_start, $old->date_end );
                $wpdb->delete( $evt_tbl, [ 'id' => $old->id ], [ '%d' ] );
            }
        } elseif ( empty( $events ) ) {
            // Feed trả về không có event nào → xóa toàn bộ block của source này
            $old_events = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$evt_tbl} WHERE source_id = %d",
                $source->id
            ) );
            foreach ( $old_events as $old ) {
                RBA_Database::unblock_ical_dates( $source->room_id, $old->date_start, $old->date_end );
            }
            $wpdb->delete( $evt_tbl, [ 'source_id' => $source->id ], [ '%d' ] );
        }

        $wpdb->update( $src_tbl, [
            'sync_status' => 'ok',
            'error_msg'   => null,
            'last_synced' => current_time( 'mysql' ),
        ], [ 'id' => $source->id ], [ '%s', '%s', '%s' ], [ '%d' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // iCal PARSER — RFC 5545 compliant
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse iCal text → array of events.
     * Hỗ trợ: Booking.com, Airbnb, Agoda, Trip.com, Expedia, HomeAway feeds.
     */
    private function parse_ical( string $raw ): array {
        // RFC 5545: unfold long lines (CRLF + whitespace = continuation)
        $raw = preg_replace( "/\r\n([ \t])/", '$1', $raw );
        $raw = preg_replace( "/\n([ \t])/",   '$1', $raw );
        $raw = str_replace( "\r\n", "\n", $raw );
        $raw = str_replace( "\r",   "\n", $raw );

        $events  = [];
        $current = null;

        foreach ( explode( "\n", $raw ) as $raw_line ) {
            $line = rtrim( $raw_line );
            if ( '' === $line ) continue;

            if ( 'BEGIN:VEVENT' === $line ) {
                $current = [ 'uid' => '', 'dtstart' => '', 'dtend' => '', 'summary' => '', 'status' => 'CONFIRMED' ];
                continue;
            }
            if ( 'END:VEVENT' === $line ) {
                if ( $current && $current['uid'] && $current['dtstart'] && $current['dtend'] ) {
                    // Bỏ qua events bị cancel
                    if ( 'CANCELLED' !== strtoupper( $current['status'] ) ) {
                        $events[] = $current;
                    }
                }
                $current = null;
                continue;
            }
            if ( null === $current ) continue;

            // Split property name/params từ value
            // Format: PROPNAME;PARAM=VAL:VALUE  hoặc  PROPNAME:VALUE
            $colon_pos = strpos( $line, ':' );
            if ( false === $colon_pos ) continue;

            $prop_full = substr( $line, 0, $colon_pos );
            $value     = substr( $line, $colon_pos + 1 );

            // Tách tên property khỏi params (DTSTART;TZID=Asia/Ho_Chi_Minh → DTSTART)
            $prop_name = strtoupper( strtok( $prop_full, ';' ) );

            switch ( $prop_name ) {
                case 'UID':
                    $current['uid'] = trim( $value );
                    break;

                case 'SUMMARY':
                    // Decode RFC 5545 escaped chars: \n \, \; \\
                    $current['summary'] = str_replace(
                        [ '\\n', '\\,', '\\;', '\\\\' ],
                        [ ' ',   ',',   ';',   '\\'   ],
                        trim( $value )
                    );
                    break;

                case 'DTSTART':
                    $current['dtstart'] = $this->parse_ical_date( trim( $value ), $prop_full );
                    break;

                case 'DTEND':
                    $current['dtend'] = $this->parse_ical_date( trim( $value ), $prop_full );
                    break;

                case 'STATUS':
                    $current['status'] = strtoupper( trim( $value ) );
                    break;
            }
        }

        return $events;
    }

    /**
     * Parse iCal date value thành Y-m-d string.
     *
     * Xử lý tất cả formats OTA hay dùng:
     *  - 20250620             → date-only (all-day event)
     *  - 20250620T140000Z     → UTC datetime
     *  - 20250620T140000      → floating datetime (local)
     *  - 20250620T140000+0700 → offset datetime
     *  TZID param được bỏ qua — lấy date part là đủ cho booking.
     */
    private function parse_ical_date( string $raw, string $prop_full = '' ): string {
        // Lấy phần date (8 ký tự đầu, bỏ Txxxxxx và timezone suffix)
        $raw = preg_replace( '/T[0-9]{6}([Z+\-][0-9:]*)?$/', '', $raw );
        $raw = preg_replace( '/[^0-9]/', '', $raw );
        $raw = substr( $raw, 0, 8 );

        if ( strlen( $raw ) !== 8 ) return '';

        $y = (int) substr( $raw, 0, 4 );
        $m = (int) substr( $raw, 4, 2 );
        $d = (int) substr( $raw, 6, 2 );

        if ( ! checkdate( $m, $d, $y ) ) return '';

        return sprintf( '%04d-%02d-%02d', $y, $m, $d );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OUTBOUND: Website → OTA
    // ─────────────────────────────────────────────────────────────────────────

    public function register_feed_rewrite(): void {
        add_rewrite_rule(
            '^rba-ical/([0-9]+)/([a-f0-9A-F]{32,64})/?$',
            'index.php?rba_ical=1&rba_room_id=$matches[1]&rba_token=$matches[2]',
            'top'
        );
    }

    public function add_query_vars( array $vars ): array {
        $vars[] = 'rba_ical';
        $vars[] = 'rba_room_id';
        $vars[] = 'rba_token';
        return $vars;
    }

    public function handle_feed_request(): void {
        if ( ! get_query_var( 'rba_ical' ) ) return;

        $room_id = (int) get_query_var( 'rba_room_id' );
        $token   = sanitize_text_field( get_query_var( 'rba_token' ) );

        if ( ! $room_id || ! $token ) {
            status_header( 400 );
            exit( 'Bad Request' );
        }

        $expected = self::get_or_create_token( $room_id );

        if ( ! hash_equals( $expected, $token ) ) {
            status_header( 403 );
            exit( 'Forbidden' );
        }

        // Không cache feed này
        nocache_headers();
        $this->output_ical_feed( $room_id );
    }

    /**
     * Generate iCal feed tổng hợp cho 1 phòng.
     *
     * Feed này bao gồm ĐẦY ĐỦ:
     *  A. WooCommerce orders (booking trực tiếp từ website)
     *  B. Events từ tất cả OTA đã sync vào (Booking.com, Airbnb, Agoda...)
     *
     * → OTA subscribe feed này sẽ thấy TOÀN BỘ ngày bị block, không bị double booking.
     */
    private function output_ical_feed( int $room_id ): void {
        global $wpdb;

        $room_name = get_the_title( $room_id ) ?: "Room {$room_id}";
        $site_name = get_bloginfo( 'name' );
        $host      = wp_parse_url( home_url(), PHP_URL_HOST );
        $now_stamp = gmdate( 'Ymd\THis\Z' );
        $feed_url  = self::get_feed_url( $room_id );

        $events = [];

        // ── A. WooCommerce direct bookings ────────────────────────────────────
        $orders = wc_get_orders( [
            'status'     => [ 'processing', 'completed' ],
            'limit'      => -1,
            'meta_query' => [ [
                'key'     => '_rba_booking_confirmed',
                'value'   => '1',
                'compare' => '=',
            ] ],
        ] );

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                /** @var \WC_Order_Item_Product $item */
                $r    = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
                $cin  = $item->get_meta( 'tf_check_in' )  ?: $item->get_meta( 'check_in' );
                $cout = $item->get_meta( 'tf_check_out' ) ?: $item->get_meta( 'check_out' );
                if ( $r !== $room_id || ! $cin || ! $cout ) continue;

                $events[] = [
                    'uid'     => 'rba-order-' . $order->get_id() . '@' . $host,
                    'dtstart' => str_replace( '-', '', $cin ),
                    'dtend'   => str_replace( '-', '', $cout ),
                    'summary' => 'RESERVED',       // Không tiết lộ thông tin khách cho OTA
                    'source'  => 'direct',
                ];
            }
        }

        // ── B. Events từ OTA khác đã sync vào (Booking.com block → đẩy sang Airbnb, v.v.) ──
        $ota_events = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.uid, e.date_start, e.date_end, e.summary, s.source_name
             FROM {$wpdb->prefix}rba_ical_events e
             JOIN {$wpdb->prefix}rba_ical_sources s ON s.id = e.source_id
             WHERE e.room_id = %d
             ORDER BY e.date_start ASC",
            $room_id
        ) );

        foreach ( $ota_events as $evt ) {
            if ( ! $evt->date_start || ! $evt->date_end ) continue;
            $events[] = [
                // Prefix uid với 'rba-ota-' để không trùng với direct bookings
                // Giữ UID gốc của OTA để OTA nguồn có thể dedup nếu cần
                'uid'     => 'rba-ota-' . md5( $evt->uid . $evt->date_start ) . '@' . $host,
                'dtstart' => str_replace( '-', '', $evt->date_start ),
                'dtend'   => str_replace( '-', '', $evt->date_end ),
                'summary' => 'NOT AVAILABLE',
                'source'  => $evt->source_name,
            ];
        }

        // ── Output iCal ───────────────────────────────────────────────────────
        header( 'Content-Type: text/calendar; charset=utf-8' );
        header( 'Content-Disposition: inline; filename="rba-' . $room_id . '.ics"' );
        header( 'Cache-Control: no-store, no-cache' );

        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "PRODID:-//{$site_name}//ResortBookingAddon 1.0//EN\r\n";
        echo "CALSCALE:GREGORIAN\r\n";
        echo "METHOD:PUBLISH\r\n";
        echo "X-WR-CALNAME:" . $this->ical_escape( $room_name ) . "\r\n";
        echo "X-WR-CALDESC:Availability feed for " . $this->ical_escape( $room_name ) . "\r\n";
        echo "X-PUBLISHED-TTL:PT15M\r\n"; // Gợi ý OTA poll mỗi 15 phút
        echo "SOURCE:" . $feed_url . "\r\n";

        foreach ( $events as $ev ) {
            echo "BEGIN:VEVENT\r\n";
            echo "UID:" . $this->ical_escape( $ev['uid'] ) . "\r\n";
            echo "DTSTAMP:{$now_stamp}\r\n";
            echo "DTSTART;VALUE=DATE:{$ev['dtstart']}\r\n";
            echo "DTEND;VALUE=DATE:{$ev['dtend']}\r\n";
            echo "SUMMARY:" . $this->ical_escape( $ev['summary'] ) . "\r\n";
            echo "STATUS:CONFIRMED\r\n";
            echo "TRANSP:OPAQUE\r\n";
            echo "END:VEVENT\r\n";
        }

        echo "END:VCALENDAR\r\n";
        exit;
    }

    /**
     * Escape ký tự đặc biệt trong iCal text value (RFC 5545).
     */
    private function ical_escape( string $text ): string {
        return str_replace(
            [ '\\',  "\n", ',', ';' ],
            [ '\\\\', '\\n', '\\,', '\\;' ],
            $text
        );
    }

    /**
     * Lấy hoặc tạo mới token bảo vệ feed URL.
     */
    public static function get_or_create_token( int $room_id ): string {
        $token = get_post_meta( $room_id, '_rba_ical_token', true );
        if ( ! $token || strlen( $token ) < 32 ) {
            $token = bin2hex( random_bytes( 16 ) ); // 32 hex chars, crypto-random
            update_post_meta( $room_id, '_rba_ical_token', $token );
        }
        return $token;
    }

    /**
     * Trả về URL feed outbound cho 1 phòng.
     */
    public static function get_feed_url( int $room_id ): string {
        return home_url( '/rba-ical/' . $room_id . '/' . self::get_or_create_token( $room_id ) . '/' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN META BOX
    // ─────────────────────────────────────────────────────────────────────────

    public function add_ical_metabox(): void {
        add_meta_box(
            'rba_ical_sync',
            'OTA Sync — iCal 2 chiều (Booking.com / Airbnb / Agoda / Trip.com)',
            [ $this, 'render_ical_metabox' ],
            'tf_room',
            'normal',
            'default'
        );
    }

    public function render_ical_metabox( \WP_Post $post ): void {
        global $wpdb;
        wp_nonce_field( 'rba_ical_nonce', 'rba_ical_nonce_field' );

        $sources  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rba_ical_sources WHERE room_id = %d ORDER BY id ASC",
            $post->ID
        ) );
        $feed_url = self::get_feed_url( $post->ID );
        ?>
        <div id="rba-ical-wrapper" style="max-width:900px">

            <!-- OUTBOUND -->
            <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;padding:16px;margin-bottom:20px">
                <h4 style="margin:0 0 8px 0;color:#1a6b3c">
                    <span class="dashicons dashicons-arrow-up-alt" style="vertical-align:middle"></span>
                    OUTBOUND — URL feed cho OTA subscribe
                </h4>
                <!-- <p style="margin:0 0 10px 0;font-size:13px;color:#333">
                    Copy URL bên dưới, sau đó <strong>import vào từng OTA</strong>:<br>
                    &bull; <strong>Booking.com:</strong> Extranet → Calendar → Sync → Import → Paste URL<br>
                    &bull; <strong>Airbnb:</strong> Calendar → Import/Export → Import → Paste URL<br>
                    &bull; <strong>Agoda YCS:</strong> Inventory → Calendar Sync → Import URL<br>
                    &bull; <strong>Trip.com:</strong> Property Management → Calendar → iCal Import<br>
                    Feed này tổng hợp <strong>toàn bộ ngày đã book</strong> (website + tất cả OTA khác).
                </p> -->
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="text" value="<?php echo esc_attr( $feed_url ); ?>" readonly
                           style="flex:1;min-width:300px;font-family:monospace;font-size:12px;background:#fff"
                           onclick="this.select()">
                    <button type="button" class="button"
                            onclick="navigator.clipboard.writeText('<?php echo esc_js( $feed_url ); ?>').then(()=>{ this.textContent='Copied!'; setTimeout(()=>{ this.textContent='Copy URL'; },2000); })">
                        Copy URL
                    </button>
                    <a href="<?php echo esc_url( $feed_url ); ?>" target="_blank" class="button">Preview feed</a>
                </div>
            </div>

            <!-- INBOUND -->
            <div style="background:#e3f2fd;border:1px solid #90caf9;border-radius:6px;padding:16px">
                <h4 style="margin:0 0 8px 0;color:#1565c0">
                    <span class="dashicons dashicons-arrow-down-alt" style="vertical-align:middle"></span>
                    INBOUND — Import iCal từ OTA (block ngày OTA đã giữ)
                </h4>
                <!-- <p style="margin:0 0 12px 0;font-size:13px;color:#333">
                    Lấy URL iCal export từ từng OTA, paste vào đây. Plugin sẽ tự sync mỗi 15 phút.<br>
                    &bull; <strong>Booking.com:</strong> Extranet → Calendar → Sync → Export → Copy URL<br>
                    &bull; <strong>Airbnb:</strong> Calendar → Import/Export → Export → Copy URL<br>
                    &bull; <strong>Agoda:</strong> YCS → Inventory → Calendar Sync → Export URL
                </p> -->

                <table class="widefat" id="rba-ical-table">
                    <thead>
                        <tr>
                            <th style="width:130px">Kênh OTA</th>
                            <th>iCal URL (Export từ OTA)</th>
                            <th style="width:130px">Sync gần nhất</th>
                            <th style="width:120px">Trạng thái</th>
                            <th style="width:160px">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="rba-ical-rows">
                    <?php foreach ( $sources as $src ) : ?>
                    <tr data-id="<?php echo esc_attr( $src->id ); ?>">
                        <td>
                            <select name="rba_ical[<?php echo esc_attr( $src->id ); ?>][name]" style="width:100%">
                                <?php foreach ( [ 'Booking.com', 'Airbnb', 'Agoda', 'Trip.com', 'Expedia', 'Traveloka', 'Klook', 'Khác' ] as $ota ) : ?>
                                    <option value="<?php echo esc_attr( $ota ); ?>" <?php selected( $src->source_name, $ota ); ?>>
                                        <?php echo esc_html( $ota ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="url"
                                   name="rba_ical[<?php echo esc_attr( $src->id ); ?>][url]"
                                   value="<?php echo esc_attr( $src->ical_url ); ?>"
                                   class="large-text" style="width:100%"
                                   placeholder="https://admin.booking.com/...ical...">
                        </td>
                        <td style="font-size:12px;color:#555">
                            <?php echo $src->last_synced
                                ? esc_html( human_time_diff( strtotime( $src->last_synced ) ) ) . ' trước'
                                : '<em>Chưa sync</em>'; ?>
                        </td>
                        <td>
                            <?php if ( 'ok' === $src->sync_status ) : ?>
                                <span style="color:#2e7d32;font-weight:600">&#10003; OK</span>
                            <?php elseif ( 'error' === $src->sync_status ) : ?>
                                <span style="color:#c62828;font-weight:600" title="<?php echo esc_attr( $src->error_msg ); ?>">&#10007; Lỗi</span>
                            <?php else : ?>
                                <span style="color:#888">&#8987; Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="button button-small rba-sync-now"
                                    data-source="<?php echo esc_attr( $src->id ); ?>"
                                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'rba_manual_sync' ) ); ?>">
                                Sync ngay
                            </button>
                            <button type="button" class="button button-small rba-delete-ical"
                                    data-id="<?php echo esc_attr( $src->id ); ?>">
                                Xóa
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:10px">
                    <button type="button" class="button" id="rba-add-ical">+ Thêm OTA</button>
                </p>
                <input type="hidden" id="rba_deleted_ical" name="rba_deleted_ical" value="">
            </div>
        </div>

        <script>
        (function($){
            let idx = 9000;

            // Thêm dòng OTA mới
            $('#rba-add-ical').on('click', function(){
                idx++;
                $('#rba-ical-rows').append(
                    '<tr>' +
                    '<td><select name="rba_ical[new_'+idx+'][name]" style="width:100%">' +
                    '<option>Booking.com</option><option>Airbnb</option><option>Agoda</option>' +
                    '<option>Trip.com</option><option>Expedia</option><option>Traveloka</option><option>Klook</option><option>Khác</option>' +
                    '</select></td>' +
                    '<td><input type="url" name="rba_ical[new_'+idx+'][url]" class="large-text" style="width:100%" placeholder="https://..."></td>' +
                    '<td><em>Chưa sync</em></td>' +
                    '<td><span style="color:#888">&#8987; Pending</span></td>' +
                    '<td></td>' +
                    '</tr>'
                );
            });

            // Xóa dòng
            $(document).on('click', '.rba-delete-ical', function(){
                const id = $(this).data('id');
                if ( id ) {
                    const $d = $('#rba_deleted_ical');
                    $d.val( $d.val() ? $d.val() + ',' + id : id );
                }
                $(this).closest('tr').remove();
            });

            // Sync ngay
            $(document).on('click', '.rba-sync-now', function(){
                const btn = $(this);
                const nonce = btn.data('nonce');
                btn.prop('disabled', true).text('Đang sync...');
                $.post(ajaxurl, {
                    action: 'rba_manual_sync',
                    source_id: btn.data('source'),
                    nonce: nonce
                }, function(res){
                    btn.prop('disabled', false).text('Sync ngay');
                    if ( res.success ) {
                        btn.closest('tr').find('td:nth-child(3)').html('vừa xong');
                        btn.closest('tr').find('td:nth-child(4)').html('<span style="color:#2e7d32;font-weight:600">&#10003; OK</span>');
                    } else {
                        alert('Lỗi: ' + res.data);
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function save_ical_sources( int $post_id ): void {
        if ( ! isset( $_POST['rba_ical_nonce_field'] )
             || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rba_ical_nonce_field'] ) ), 'rba_ical_nonce' ) ) {
            return;
        }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'rba_ical_sources';

        // Xóa sources bị đánh dấu xóa
        $raw_deleted = sanitize_text_field( wp_unslash( $_POST['rba_deleted_ical'] ?? '' ) );
        $deleted_ids = array_filter( array_map( 'absint', explode( ',', $raw_deleted ) ) );
        foreach ( $deleted_ids as $id ) {
            // Unblock dates trước khi xóa source
            $events = $wpdb->get_results( $wpdb->prepare(
                "SELECT date_start, date_end FROM {$wpdb->prefix}rba_ical_events WHERE source_id = %d",
                $id
            ) );
            foreach ( $events as $evt ) {
                RBA_Database::unblock_ical_dates( $post_id, $evt->date_start, $evt->date_end );
            }
            $wpdb->delete( $wpdb->prefix . 'rba_ical_events', [ 'source_id' => $id ], [ '%d' ] );
            $wpdb->delete( $table, [ 'id' => $id, 'room_id' => $post_id ], [ '%d', '%d' ] );
        }

        // Insert / update sources
        $raw_ical = wp_unslash( $_POST['rba_ical'] ?? [] );
        if ( ! empty( $raw_ical ) && is_array( $raw_ical ) ) {
            foreach ( $raw_ical as $key => $src ) {
                $url = esc_url_raw( trim( $src['url'] ?? '' ) );
                if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) continue;

                $data = [
                    'room_id'     => $post_id,
                    'source_name' => sanitize_text_field( $src['name'] ?? 'Khác' ),
                    'ical_url'    => $url,
                ];

                if ( str_starts_with( (string) $key, 'new_' ) ) {
                    $wpdb->insert( $table, $data, [ '%d', '%s', '%s' ] );
                } else {
                    $wpdb->update( $table, $data, [ 'id' => absint( $key ), 'room_id' => $post_id ], [ '%d', '%s', '%s' ], [ '%d', '%d' ] );
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_manual_sync(): void {
        check_ajax_referer( 'rba_manual_sync', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $raw_id = sanitize_text_field( wp_unslash( $_POST['source_id'] ?? '' ) );

        if ( 'all' === $raw_id ) {
            $this->run_all_syncs();
            wp_send_json_success( 'Đã sync tất cả feeds.' );
        }

        $source_id = absint( $raw_id );
        if ( ! $source_id ) {
            wp_send_json_error( 'ID không hợp lệ.' );
        }

        global $wpdb;
        $source = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rba_ical_sources WHERE id = %d",
            $source_id
        ) );

        if ( ! $source ) {
            wp_send_json_error( 'Source không tồn tại.' );
        }

        $this->sync_one( $source );
        wp_send_json_success( 'Sync hoàn tất.' );
    }
}

new RBA_iCal_Sync();
