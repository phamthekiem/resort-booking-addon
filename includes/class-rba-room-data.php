<?php
/**
 * RBA_Room_Data — Helper lấy dữ liệu phòng an toàn
 *
 * Cung cấp static methods cho template dùng.
 * Template KHÔNG cần biết data lưu ở đâu — gọi hàm này là đủ.
 *
 * Dùng trong theme template:
 *   $data = RBA_Room_Data::get( get_the_ID() );
 *
 * @package ResortBookingAddon
 * @since   1.5.1
 */
defined( 'ABSPATH' ) || exit;

class RBA_Room_Data {

    /**
     * Lấy toàn bộ data của phòng — safe, typed, không bao giờ throw.
     */
    public static function get( int $room_id ): array {
        // ── Tourfic meta — safe decode (có thể là array hoặc JSON string) ──
        $tf_opt_raw = get_post_meta( $room_id, 'tf_room_opt', true );
        if ( is_array( $tf_opt_raw ) ) {
            $tf_opt = $tf_opt_raw;
        } elseif ( is_string( $tf_opt_raw ) && $tf_opt_raw !== '' ) {
            $tf_opt = json_decode( $tf_opt_raw, true ) ?: [];
        } else {
            $tf_opt = [];
        }

        $feature_ids_raw = get_post_meta( $room_id, 'tf_search_features', true );
        if ( is_array( $feature_ids_raw ) ) {
            $feature_ids = $feature_ids_raw;
        } elseif ( is_string( $feature_ids_raw ) ) {
            $feature_ids = json_decode( $feature_ids_raw, true ) ?: [];
        } else {
            $feature_ids = [];
        }

        // ── ACF fields (safe fallback nếu ACF chưa active) ───────────────────
        $acf = function_exists( 'get_fields' ) ? ( get_fields( $room_id ) ?: [] ) : [];

        // ── Base price: đọc đúng field Tourfic dùng ───────────────────────────
        $base_price   = class_exists('RBA_Seasonal_Price')
            ? RBA_Seasonal_Price::get_base_price( $room_id )
            : (float) get_post_meta( $room_id, 'tf_search_price', true );

        $today        = current_time( 'Y-m-d' );
        $tomorrow     = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
        $today_price  = class_exists('RBA_Seasonal_Price')
            ? RBA_Seasonal_Price::get_price_for_date( $room_id, $today )
            : $base_price;
        $available    = class_exists('RBA_Database')
            ? RBA_Database::get_available_rooms( $room_id, $today, $tomorrow )
            : 1;

        // ── Gallery ───────────────────────────────────────────────────────────
        $images = [];
        $thumb  = get_post_thumbnail_id( $room_id );
        if ( $thumb ) {
            $src = wp_get_attachment_image_src( $thumb, 'large' );
            if ( $src ) $images[] = $src[0];
        }
        $gallery_extra = (array) ( $acf['room_gallery_extra'] ?? [] );
        foreach ( $gallery_extra as $img ) {
            if ( is_array( $img ) && isset( $img['url'] ) )  $images[] = $img['url'];
            elseif ( is_array( $img ) && isset( $img['sizes']['large'] ) ) $images[] = $img['sizes']['large'];
            elseif ( is_numeric( $img ) ) {
                $src = wp_get_attachment_image_src( (int) $img, 'large' );
                if ( $src ) $images[] = $src[0];
            }
        }
        $images = array_values( array_filter( $images ) );
        if ( empty( $images ) ) {
            $images[] = 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=1200&q=80';
        }

        // ── Features (taxonomy terms) ─────────────────────────────────────────
        $features = [];
        foreach ( $feature_ids as $tid ) {
            $term = get_term( (int) $tid );
            if ( $term && ! is_wp_error( $term ) ) $features[] = $term->name;
        }

        // ── Hotel parent ──────────────────────────────────────────────────────
        $hotel_id = (int) ( $tf_opt['tf_hotel']
            ?? get_post_meta( $room_id, 'tf_hotel', true )
            ?? 0 );

        // ── Seasonal prices trong DB ──────────────────────────────────────────
        global $wpdb;
        $seasons = [];
        if ( $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}rba_seasonal_prices'") ) {
            $seasons = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rba_seasonal_prices WHERE room_id = %d ORDER BY priority ASC",
                $room_id
            ) );
        }

        return [
            // IDs
            'room_id'     => $room_id,
            'hotel_id'    => $hotel_id,
            'hotel_name'  => $hotel_id ? get_the_title( $hotel_id ) : get_bloginfo('name'),
            'hotel_url'   => $hotel_id ? get_permalink( $hotel_id ) : home_url(),

            // Content
            'title'       => get_the_title( $room_id ),
            'description' => get_the_content( null, false, $room_id ),
            'permalink'   => get_permalink( $room_id ),

            // Gallery
            'images'      => $images,
            'image_count' => count( $images ),

            // Tourfic meta
            'adults_cap'    => (int) get_post_meta( $room_id, 'tf_search_adult',     true ) ?: 2,
            'children_cap'  => (int) get_post_meta( $room_id, 'tf_search_child',     true ) ?: 1,
            'num_rooms'     => (int) get_post_meta( $room_id, 'tf_search_num-room',  true ) ?: 1,

            // ACF
            'room_size'     => (string) ( $acf['room_size']     ?? '' ),
            'room_floor'    => (string) ( $acf['room_floor']    ?? '' ),
            'room_beds'     => (array)  ( $acf['room_beds']     ?? [] ),
            'room_view'     => (array)  ( $acf['room_view']     ?? [] ),
            'room_included' => (array)  ( $acf['room_included'] ?? [] ),
            'room_cancel'   => (string) ( $acf['room_cancellation_policy'] ?? '' ),
            'room_virtual'  => (string) ( $acf['room_virtual_tour']        ?? '' ),
            'room_qty'      => (int)    ( $acf['room_quantity']             ?? 1 ),

            // Features
            'features'    => $features,

            // Pricing
            'base_price'  => $base_price,
            'today_price' => $today_price,
            'available'   => $available,
            'today'       => $today,
            'tomorrow'    => $tomorrow,
            'seasons'     => $seasons,
            'has_pricing' => count( $seasons ) > 0,

            // Nonce & config cho JS
            'nonce'       => wp_create_nonce( 'rba_public_nonce' ),
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'cart_url'    => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
            'checkout_url'=> function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '',
            'currency'    => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '₫',
        ];
    }

    /** Label beds */
    public static function bed_label( string $key ): string {
        return [
            'king'   => '🛏 Giường King',
            'queen'  => '🛏 Giường Queen',
            'twin'   => '🛏 2 Giường đơn',
            'double' => '🛏 Giường đôi',
            'single' => '🛏 Giường đơn',
            'sofa'   => '🛋 Sofa bed',
            'bunk'   => '🪜 Giường tầng',
        ][ $key ] ?? ucfirst( $key );
    }

    /** Label views */
    public static function view_label( string $key ): string {
        return [
            'sea'       => '🌊 View biển',
            'pool'      => '🏊 View hồ bơi',
            'mountain'  => '⛰ View núi',
            'garden'    => '🌿 View vườn',
            'city'      => '🏙 View thành phố',
            'courtyard' => '🏛 View sân trong',
        ][ $key ] ?? ucfirst( $key );
    }

    /** Label included */
    public static function included_label( string $key ): string {
        return [
            'breakfast' => '☕ Bữa sáng',
            'wifi'      => '📶 Wi-Fi miễn phí',
            'parking'   => '🚗 Bãi đậu xe',
            'pool'      => '🏊 Hồ bơi',
            'gym'       => '🏋 Phòng gym',
            'spa'       => '💆 Spa',
            'airport'   => '✈ Đưa đón sân bay',
            'minibar'   => '🍾 Minibar',
            'laundry'   => '👔 Giặt ủi',
        ][ $key ] ?? '✓ ' . ucfirst( $key );
    }

    /** Format price VNĐ */
    public static function fmt( float $price ): string {
        return number_format( $price, 0, ',', '.' );
    }
}
