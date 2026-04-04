<?php
/**
 * Plugin Name: Resort Booking Addon for Tourfic
 * Plugin URI:  https://github.com/
 * Description: Mở rộng Tourfic Free: Phòng, giá theo mùa, iCal OTA sync, chống double booking, tour nội khu, ACF integration, KiotViet Hotel bridge.
 * Version:     1.7.6
 * Author:      KiemPT
 * Update URI:   https://github.com/
 * Text Domain: rba
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Depends:     tourfic, advanced-custom-fields
 */

defined( 'ABSPATH' ) || exit;

if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Resort Booking Addon</strong> yêu cầu PHP 8.0 trở lên. Phiên bản hiện tại: ' . PHP_VERSION . '</p></div>';
    } );
    return;
}

define( 'RBA_VERSION', '1.7.6' );
define( 'RBA_PATH',    plugin_dir_path( __FILE__ ) );
define( 'RBA_URL',     plugin_dir_url( __FILE__ ) );
define( 'RBA_DB_VER',  '1.1' );

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

add_action( 'plugins_loaded', function (): void {

    if ( ! defined( 'TF_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Resort Booking Addon</strong> yêu cầu plugin <strong>Tourfic</strong> đang hoạt động.</p></div>';
        } );
        return;
    }

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
        'includes/class-rba-room-data.php',        // Room data helper (safe, typed)
        'includes/class-rba-room-template.php',   // AJAX handlers cho booking form
        'includes/class-rba-price-display.php',   // Price display widget & AJAX
        'includes/class-rba-pms-role.php',        // PMS role & nhân viên lễ tân
        'includes/class-rba-pms.php',             // PMS dashboard (/pms/)
        'includes/class-rba-debug.php',           // Debug tool
    ];

    foreach ( $modules as $file ) {
        $path = RBA_PATH . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }

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

add_action( 'wp_ajax_rba_clear_update_logs', function(): void {
    check_ajax_referer( 'rba_clear_update_logs', 'nonce' );
    if ( current_user_can( 'manage_options' ) ) {
        delete_option( 'rba_updater_logs' );
    }
    wp_send_json_success();
} );

add_action( 'rba_ical_sync_cron', function (): void {
    if ( class_exists( 'RBA_GCal' ) ) {
        ( new RBA_GCal() )->run_sync();
    }
}, 20 );


add_action( 'wp_ajax_lux_refresh_summary',        'rba_lux_refresh_summary' );
add_action( 'wp_ajax_nopriv_lux_refresh_summary', 'rba_lux_refresh_summary' );
function rba_lux_refresh_summary(): void {
    if ( ob_get_level() ) ob_clean();
    check_ajax_referer( 'lux-summary', 'security' );

    if ( ! function_exists('WC') || ! WC()->cart ) {
        wp_send_json_error( 'Cart not available' );
    }

    $cart    = WC()->cart;
    $items   = [];
    foreach ( $cart->get_cart() as $item ) {
        $room_id   = absint( $item['tf_room_id'] ?? $item['room_id'] ?? 0 );
        $check_in  = (string) ( $item['tf_check_in']  ?? $item['check_in']  ?? '' );
        $check_out = (string) ( $item['tf_check_out'] ?? $item['check_out'] ?? '' );
        $nights    = 0;
        if ( $check_in && $check_out ) {
            $nights = max( 1, (int)( ( strtotime($check_out) - strtotime($check_in) ) / DAY_IN_SECONDS ) );
        }
        $items[] = [
            'name'       => $item['data'] ? $item['data']->get_name() : '',
            'room_id'    => $room_id,
            'thumb'      => $room_id ? get_the_post_thumbnail_url( $room_id, 'thumbnail' ) : '',
            'check_in'   => $check_in,
            'check_out'  => $check_out,
            'nights'     => $nights,
            'adults'     => absint( $item['adults']   ?? 2 ),
            'children'   => absint( $item['children'] ?? 0 ),
            'line_total' => (float) ( $item['line_total'] ?? 0 ),
        ];
    }

    wp_send_json_success( [
        'items'    => $items,
        'subtotal' => $cart->get_subtotal(),
        'discount' => $cart->get_discount_total(),
        'tax'      => $cart->get_total_tax(),
        'total'    => $cart->get_total( 'edit' ),
    ] );
}

register_activation_hook( __FILE__, function (): void {
    if ( ! class_exists( 'RBA_Database' ) ) {
        require_once RBA_PATH . 'includes/class-rba-database.php';
    }

    RBA_Database::create_tables();

    if ( ! wp_next_scheduled( 'rba_ical_sync_cron' ) ) {
        wp_schedule_event( time(), 'rba_15min', 'rba_ical_sync_cron' );
    }
    if ( ! wp_next_scheduled( 'rba_kv_sync_cron' ) ) {
        wp_schedule_event( time() + 60, 'rba_30min', 'rba_kv_sync_cron' );
    }

    add_rewrite_rule( '^rba-ota-reservation/([a-z]+)/?$', 'index.php?rba_ota_res=1&rba_ota_name=\$matches[1]', 'top' );
    add_rewrite_rule(
        '^rba-ical/([0-9]+)/([a-f0-9]+)/?$',
        'index.php?rba_ical=1&rba_room_id=$matches[1]&rba_token=$matches[2]',
        'top'
    );
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function (): void {
    wp_clear_scheduled_hook( 'rba_ical_sync_cron' );
    wp_clear_scheduled_hook( 'rba_kv_sync_cron' );
    flush_rewrite_rules();
} );

register_uninstall_hook( __FILE__, 'rba_uninstall' );

function rba_uninstall(): void {
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
