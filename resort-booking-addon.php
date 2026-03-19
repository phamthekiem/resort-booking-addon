<?php
/**
 * Plugin Name: Resort Booking Addon for Tourfic
 * Plugin URI:  
 * Description: Mở rộng Tourfic Free: Phòng, giá theo mùa, iCal OTA sync, chống double booking, tour nội khu, ACF integration, KiotViet Hotel bridge.
 * Version:     1.4.31
 * Author:      KiemPT
 * Update URI:   
 * Text Domain: rba
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Depends:     tourfic, advanced-custom-fields
 */

defined( 'ABSPATH' ) || exit;

// ─── PHP version guard (phải kiểm tra trước khi dùng bất kỳ PHP 8+ syntax) ──
if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Resort Booking Addon</strong> yêu cầu PHP 8.0 trở lên. Phiên bản hiện tại: ' . PHP_VERSION . '</p></div>';
    } );
    return;
}

define( 'RBA_VERSION', '1.4.2' );
define( 'RBA_PATH',    plugin_dir_path( __FILE__ ) );
define( 'RBA_URL',     plugin_dir_url( __FILE__ ) );
define( 'RBA_DB_VER',  '1.1' );

// ─── Cron interval phải đăng ký SỚM (trước wp_schedule_event) ───────────────
add_filter( 'cron_schedules', function ( array $schedules ): array {
    if ( ! isset( $schedules['rba_15min'] ) ) {
        $schedules['rba_15min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 15 Minutes', 'rba' ),
        ];
    }
    if ( ! isset( $schedules['rba_30min'] ) ) {
        $schedules['rba_30min'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 30 Minutes', 'rba' ),
        ];
    }
    return $schedules;
} );

// ─── Autoload modules ────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {

    // 1. Kiểm tra Tourfic đang active
    if ( ! defined( 'TF_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Resort Booking Addon</strong> yêu cầu plugin <strong>Tourfic</strong> đang hoạt động.</p></div>';
        } );
        return;
    }

    // 2. Load các module — ĐỨng đúng thứ tự dependency:
    //    Database → Room → Price → Guard → iCal → GCal → Tour → ACF → KiotViet → Search → Admin
    $modules = [
        'includes/class-rba-database.php',       // Phải load đầu tiên — DB helpers
        'includes/class-rba-room-unlock.php',     // Bypass giới hạn 5 phòng
        'includes/class-rba-seasonal-price.php',  // Giá theo mùa (dùng RBA_Database)
        'includes/class-rba-booking-guard.php',   // Double booking (dùng RBA_Database + RBA_Seasonal_Price)
        'includes/class-rba-ical-sync.php',       // OTA sync (dùng RBA_Database)
        'includes/class-rba-tour-addon.php',      // Tour nội khu
        'includes/class-rba-acf-bridge.php',      // ACF fields
        'includes/class-rba-search.php',          // Search (dùng RBA_Database + RBA_Seasonal_Price)
        'includes/class-rba-notifications.php',   // Notifications (optional)
        'includes/class-rba-updater.php',          // GitHub auto-update system
        'includes/class-rba-gcal.php',            // Google Calendar bridge (OTA không có iCal)
        'includes/class-rba-kiotviet.php',        // KiotViet Hotel bridge
        'includes/class-rba-ota-api.php',          // OTA API full flow (Adapter Pattern)
        'admin/class-rba-admin.php',              // Admin dashboard
    ];

    foreach ( $modules as $file ) {
        $path = RBA_PATH . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }

    // ── Khởi tạo GitHub auto-updater ─────────────────────────────────────
    if ( is_admin() && class_exists( 'RBA_Updater' ) ) {
        new RBA_Updater(
            RBA_PATH . 'resort-booking-addon.php',
            (string) get_option( 'rba_updater_github_user', '' ),
            (string) get_option( 'rba_updater_github_repo', 'resort-booking-addon' ),
            RBA_VERSION,
            (string) get_option( 'rba_updater_github_token', '' )
        );
    }

}, 20 );

// ─── Clear update logs AJAX ───────────────────────────────────────────────────
add_action( 'wp_ajax_rba_clear_update_logs', function(): void {
    check_ajax_referer( 'rba_clear_update_logs', 'nonce' );
    if ( current_user_can( 'manage_options' ) ) {
        delete_option( 'rba_updater_logs' );
    }
    wp_send_json_success();
} );

// ─── Hook Google Calendar sync vào cron iCal có sẵn (dùng chung, không tạo cron riêng) ─
add_action( 'rba_ical_sync_cron', function (): void {
    if ( class_exists( 'RBA_GCal' ) ) {
        ( new RBA_GCal() )->run_sync();
    }
}, 20 );

// ─── Activation hook ─────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function (): void {
    // Load Database class trực tiếp (plugins_loaded chưa chạy tại thời điểm này)
    if ( ! class_exists( 'RBA_Database' ) ) {
        require_once RBA_PATH . 'includes/class-rba-database.php';
    }

    RBA_Database::create_tables();

    // Schedule cron — interval 'rba_15min' đã được đăng ký qua cron_schedules filter ở trên
    // (filter đó hook vào global scope, chạy trước activation hook)
    if ( ! wp_next_scheduled( 'rba_ical_sync_cron' ) ) {
        wp_schedule_event( time(), 'rba_15min', 'rba_ical_sync_cron' );
    }
    if ( ! wp_next_scheduled( 'rba_kv_sync_cron' ) ) {
        wp_schedule_event( time() + 60, 'rba_30min', 'rba_kv_sync_cron' );
    }

    // Đăng ký rewrite rules trước khi flush
    add_rewrite_rule( '^rba-ota-reservation/([a-z]+)/?$', 'index.php?rba_ota_res=1&rba_ota_name=\$matches[1]', 'top' );
    add_rewrite_rule(
        '^rba-ical/([0-9]+)/([a-f0-9]+)/?$',
        'index.php?rba_ical=1&rba_room_id=$matches[1]&rba_token=$matches[2]',
        'top'
    );
    flush_rewrite_rules();
} );

// ─── Deactivation hook ───────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, function (): void {
    wp_clear_scheduled_hook( 'rba_ical_sync_cron' );
    wp_clear_scheduled_hook( 'rba_kv_sync_cron' );
    flush_rewrite_rules();
} );

// ─── Uninstall: xóa tables và options ────────────────────────────────────────
register_uninstall_hook( __FILE__, 'rba_uninstall' );

function rba_uninstall(): void {
    // Chỉ xóa nếu admin chọn "Delete plugin data"
    if ( ! get_option( 'rba_delete_data_on_uninstall' ) ) {
        return;
    }
    global $wpdb;
    $tables = [
        'rba_seasonal_prices', 'rba_date_prices', 'rba_availability',
        'rba_ical_sources', 'rba_ical_events', 'rba_booking_locks', 'rba_tour_bookings',
    ];
    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
    }
    delete_option( 'rba_db_version' );
    delete_option( 'rba_tour_table_ver' );
}
