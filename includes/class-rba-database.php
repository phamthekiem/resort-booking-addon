<?php
/**
 * RBA_Database
 * Tạo và quản lý tất cả custom tables mở rộng Tourfic.
 *
 * Tables:
 *  - rba_seasonal_prices  : Giá theo mùa / khoảng ngày
 *  - rba_date_prices      : Giá override theo ngày cụ thể
 *  - rba_availability     : Inventory phòng theo ngày (realtime)
 *  - rba_ical_sources     : Danh sách iCal feed từ OTA
 *  - rba_ical_events      : Events đã sync từ OTA
 *  - rba_booking_log      : Log booking để chống double booking
 */
defined( 'ABSPATH' ) || exit;

class RBA_Database {

    /**
     * Tạo tất cả tables khi activate plugin.
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── 1. Seasonal Prices ────────────────────────────────────────────────
        // Lưu giá theo khoảng thời gian (mùa hè, lễ Tết, v.v.)
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rba_seasonal_prices (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id      BIGINT UNSIGNED NOT NULL COMMENT 'WP post ID của phòng',
            season_name  VARCHAR(100)    NOT NULL,
            date_from    DATE            NOT NULL,
            date_to      DATE            NOT NULL,
            price_type   ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
            price_value  DECIMAL(10,2)   NOT NULL COMMENT 'Giá cố định hoặc % điều chỉnh',
            min_nights   TINYINT         NOT NULL DEFAULT 1,
            priority     TINYINT         NOT NULL DEFAULT 10 COMMENT 'Số nhỏ = ưu tiên cao hơn',
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY room_date (room_id, date_from, date_to)
        ) $charset;" );

        // ── 2. Date Prices (override từng ngày) ───────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rba_date_prices (
            id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id   BIGINT UNSIGNED NOT NULL,
            price_date DATE           NOT NULL,
            price     DECIMAL(10,2)   NOT NULL,
            note      VARCHAR(255),
            PRIMARY KEY  (id),
            UNIQUE KEY room_date (room_id, price_date)
        ) $charset;" );

        // ── 3. Availability (inventory theo ngày) ─────────────────────────────
        // Mỗi row = 1 phòng x 1 ngày, theo dõi số phòng trống
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rba_availability (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id      BIGINT UNSIGNED NOT NULL,
            avail_date   DATE            NOT NULL,
            total_rooms  TINYINT UNSIGNED NOT NULL DEFAULT 1,
            booked_rooms TINYINT UNSIGNED NOT NULL DEFAULT 0,
            blocked      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = bị khóa bởi admin/OTA',
            PRIMARY KEY  (id),
            UNIQUE KEY room_date (room_id, avail_date),
            KEY room_id  (room_id)
        ) $charset;" );

        // ── 4. iCal Sources ───────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rba_ical_sources (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id     BIGINT UNSIGNED NOT NULL,
            source_name VARCHAR(100)    NOT NULL COMMENT 'Booking.com, Airbnb, Agoda...',
            ical_url    TEXT            NOT NULL,
            last_synced DATETIME,
            sync_status VARCHAR(50)     NOT NULL DEFAULT 'pending',
            error_msg   TEXT,
            PRIMARY KEY (id),
            KEY room_id (room_id)
        ) $charset;" );

        // ── 5. iCal Events (dữ liệu đã sync) ─────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rba_ical_events (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id   BIGINT UNSIGNED NOT NULL,
            room_id     BIGINT UNSIGNED NOT NULL,
            uid         VARCHAR(255)    NOT NULL COMMENT 'UID từ iCal event',
            date_start  DATE            NOT NULL,
            date_end    DATE            NOT NULL,
            summary     VARCHAR(500),
            raw_data    TEXT,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_uid (source_id, uid),
            KEY room_dates (room_id, date_start, date_end)
        ) $charset;" );

        // ── 6. Booking Guard Log ──────────────────────────────────────────────
        // Lock table chống race condition khi 2 người đặt cùng lúc
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rba_booking_locks (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id    BIGINT UNSIGNED NOT NULL,
            date_from  DATE            NOT NULL,
            date_to    DATE            NOT NULL,
            session_id VARCHAR(64)     NOT NULL,
            expires_at DATETIME        NOT NULL COMMENT 'Lock hết hạn sau 15 phút',
            order_id   BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY room_dates (room_id, date_from, date_to),
            KEY expires    (expires_at)
        ) $charset;" );

        // ── 7. Tour Bookings (slots) ──────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rba_tour_bookings (
            id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            tour_id     BIGINT UNSIGNED  NOT NULL,
            tour_date   DATE             NOT NULL,
            slot_time   VARCHAR(10)      NOT NULL COMMENT '08:00, 14:00...',
            adults      TINYINT UNSIGNED NOT NULL DEFAULT 1,
            children    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            infants     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            order_id    BIGINT UNSIGNED  DEFAULT NULL,
            status      ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
            session_id  VARCHAR(64),
            expires_at  DATETIME,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tour_date_slot (tour_id, tour_date, slot_time),
            KEY order_id       (order_id)
        ) $charset;" );

        update_option( 'rba_db_version', RBA_DB_VER );
    }

    /**
     * Khởi tạo inventory cho 1 phòng trong N ngày tới.
     */
    public static function init_room_availability( int $room_id, int $total_rooms = 1, int $days = 730 ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rba_availability';

        $date  = new DateTime();
        $end   = ( new DateTime() )->modify( "+{$days} days" );

        while ( $date <= $end ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (room_id, avail_date, total_rooms, booked_rooms)
                 VALUES (%d, %s, %d, 0)",
                $room_id,
                $date->format( 'Y-m-d' ),
                $total_rooms
            ) );
            $date->modify( '+1 day' );
        }
    }

    /**
     * Lấy số phòng trống trong 1 khoảng ngày.
     * Trả về số nguyên tối thiểu (bottleneck ngày ít phòng nhất).
     */
    public static function get_available_rooms( int $room_id, string $from, string $to ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'rba_availability';

        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(total_rooms - booked_rooms)
             FROM {$table}
             WHERE room_id    = %d
               AND avail_date >= %s
               AND avail_date <  %s
               AND blocked    = 0",
            $room_id, $from, $to
        ) );

        return max( 0, (int) $result );
    }

    /**
     * Cập nhật booked_rooms khi booking được confirm.
     */
    public static function decrement_availability( int $room_id, string $from, string $to ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rba_availability
             SET booked_rooms = booked_rooms + 1
             WHERE room_id    = %d
               AND avail_date >= %s
               AND avail_date <  %s",
            $room_id, $from, $to
        ) );
    }

    /**
     * Giải phóng availability khi booking bị hủy.
     */
    public static function increment_availability( int $room_id, string $from, string $to ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rba_availability
             SET booked_rooms = GREATEST(0, booked_rooms - 1)
             WHERE room_id    = %d
               AND avail_date >= %s
               AND avail_date <  %s",
            $room_id, $from, $to
        ) );
    }

    /**
     * Block ngày từ iCal OTA event.
     */
    public static function block_dates_from_ical( int $room_id, string $from, string $to ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rba_availability';
        $date  = new DateTime( $from );
        $end   = new DateTime( $to );

        while ( $date < $end ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table} (room_id, avail_date, total_rooms, booked_rooms, blocked)
                 VALUES (%d, %s, 1, 1, 1)
                 ON DUPLICATE KEY UPDATE blocked = 1, booked_rooms = total_rooms",
                $room_id,
                $date->format( 'Y-m-d' )
            ) );
            $date->modify( '+1 day' );
        }
    }

    /**
     * Xóa block OTA khi event bị xóa khỏi iCal.
     */
    public static function unblock_ical_dates( int $room_id, string $from, string $to ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rba_availability
             SET blocked = 0, booked_rooms = 0
             WHERE room_id    = %d
               AND avail_date >= %s
               AND avail_date <  %s
               AND blocked    = 1",
            $room_id, $from, $to
        ) );
    }
}
