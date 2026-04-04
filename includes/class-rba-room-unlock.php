<?php
/**
 * RBA_Room_Unlock
 *
 * Bypass giới hạn 5 phòng của Tourfic Free.
 *
 * Cách hoạt động:
 *  - Tourfic Free kiểm tra số phòng qua meta _tf_room_count và filter.
 *  - Ta hook vào filter đó trả về false (không giới hạn).
 *  - Đồng thời hook vào save_post để khởi tạo availability khi thêm phòng mới.
 */
defined( 'ABSPATH' ) || exit;

class RBA_Room_Unlock {

    public function __construct() {
        // ── Bypass room limit filters ─────────────────────────────────────────
        // Tourfic dùng các filter này để chặn thêm phòng
        add_filter( 'tf_hotel_room_limit',        '__return_false' );
        add_filter( 'tf_room_limit_reached',      '__return_false' );
        add_filter( 'tf_free_room_limit',         [ $this, 'unlimited_rooms' ], 99 );
        add_filter( 'tf_hotel_max_rooms',         [ $this, 'unlimited_rooms' ], 99 );

        // ── Hook WP admin để ẩn/thay thông báo giới hạn ──────────────────────
        add_action( 'admin_head',                 [ $this, 'hide_limit_notice_css' ] );
        add_action( 'admin_footer',               [ $this, 'hide_limit_notice_js' ] );

        // ── Khi lưu phòng mới → khởi tạo availability ────────────────────────
        add_action( 'save_post',                  [ $this, 'on_post_saved' ], 10, 3 );

        // ── Khi phòng bị xóa → dọn dẹp availability ─────────────────────────
        add_action( 'before_delete_post',         [ $this, 'on_post_deleted' ] );

        // ── REST API: bypass limit check ──────────────────────────────────────
        add_filter( 'tf_api_room_limit_check',    '__return_false' );
    }

    /**
     * Trả về giá trị lớn để "unlimited".
     */
    public function unlimited_rooms( $val ): int {
        return 9999;
    }

    /**
     * Ẩn notice "Room limit reached" bằng CSS.
     */
    public function hide_limit_notice_css(): void {
        if ( ! is_admin() ) return;
        ?>
        <style>
        /* Hide Tourfic Free upgrade notices, pro upsells, room limits */
        .tf-room-limit-notice,
        .tf_room_limit_notice,
        [class*="room-limit"],
        [id*="room_limit"],
        /* Pro upgrade banners */
        .tf-pro-notice,
        .tf-upgrade-notice,
        .tf-pro-badge,
        [class*="upgrade-notice"],
        [class*="pro-notice"],
        [class*="tf-free-limit"],
        /* Tourfic admin notices về giới hạn */
        .notice.tf-notice[class*="limit"],
        div[id*="tf_limit"],
        div[id*="tf-limit"],
        /* Ẩn nút Pro trong các metabox */
        .tf-pro-only-badge,
        .tf-requires-pro { display: none !important; }
        </style>
        <?php
    }

    /**
     * JS fallback: xóa notice nếu render bằng JS.
     */
    public function hide_limit_notice_js(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, [ 'tf_hotel', 'tf_room' ], true ) ) return;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[class*="room-limit"], [id*="room_limit"]')
                .forEach(el => el.remove());
        });
        </script>
        <?php
    }

    /**
     * Khi lưu post tf_room → khởi tạo availability 2 năm tới.
     */
    public function on_post_saved( int $post_id, \WP_Post $post, bool $update ): void {
        if ( $post->post_type !== 'tf_room' ) return;
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

        // Lấy số lượng phòng vật lý (từ ACF hoặc meta Tourfic)
        $qty = (int) ( get_field( 'room_quantity', $post_id )
                    ?: get_post_meta( $post_id, '_tf_room_quantity', true )
                    ?: 1 );

        // Khởi tạo availability
        RBA_Database::init_room_availability( $post_id, $qty, 730 );
    }

    /**
     * Khi phòng bị xóa → xóa dữ liệu liên quan.
     */
    public function on_post_deleted( int $post_id ): void {
        if ( get_post_type( $post_id ) !== 'tf_room' ) return;

        global $wpdb;
        $tables = [
            $wpdb->prefix . 'rba_availability',
            $wpdb->prefix . 'rba_seasonal_prices',
            $wpdb->prefix . 'rba_date_prices',
            $wpdb->prefix . 'rba_ical_sources',
        ];
        foreach ( $tables as $table ) {
            $wpdb->delete( $table, [ 'room_id' => $post_id ], [ '%d' ] );
        }
    }

    /**
     * Static: lấy tất cả rooms của 1 hotel (không giới hạn).
     */
    public static function get_all_rooms( int $hotel_id ): array {
        $args = [
            'post_type'      => 'tf_room',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => '_tf_related_hotel',
                    'value' => $hotel_id,
                    'type'  => 'NUMERIC',
                ],
            ],
        ];
        return get_posts( $args ) ?: [];
    }
}

// ── Khởi tạo ─────────────────────────────────────────────────────────────────
new RBA_Room_Unlock();
