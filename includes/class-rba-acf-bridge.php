<?php
/**
 * RBA_ACF_Bridge
 *
 * Kết nối ACF custom fields với Tourfic.
 * Cho phép dùng ACF để quản lý dữ liệu phòng / tour mà không cần thay đổi core.
 *
 * Các ACF field groups:
 *  - rba_room_details   : chi tiết phòng nâng cao
 *  - rba_tour_details   : chi tiết tour nội khu
 *  - rba_resort_info    : thông tin khu nghỉ dưỡng tổng thể
 */
defined( 'ABSPATH' ) || exit;

class RBA_ACF_Bridge {

    public function __construct() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        // Đăng ký field groups
        add_action( 'acf/init',              [ $this, 'register_room_fields' ] );
        add_action( 'acf/init',              [ $this, 'register_tour_fields' ] );
        add_action( 'acf/init',              [ $this, 'register_resort_fields' ] );

        // Sync ACF → Tourfic meta khi save
        // acf/save_post truyền $post_id dạng mixed:
        //   - int    khi lưu post/page thông thường
        //   - string khi lưu ACF Options Page ('options'), User ('user_1'), Term ('term_5')...
        // PHP 8 strict type → Fatal TypeError nếu declare int nhưng nhận string.
        // Dùng type-safe wrapper, validate bên trong trước khi gọi hàm thực.
        add_action( 'acf/save_post', [ $this, 'maybe_sync_room' ], 20 );
        add_action( 'acf/save_post', [ $this, 'maybe_sync_tour' ], 20 );

        // Hiển thị ACF data trong booking emails
        add_filter( 'tf_booking_email_data', [ $this, 'append_acf_to_email' ], 10, 2 );

        // Shortcode hiển thị ACF field trên frontend
        add_shortcode( 'rba_field', [ $this, 'shortcode_field' ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // FIELD GROUP: Phòng (tf_room)
    // ────────────────────────────────────────────────────────────────────────

    public function register_room_fields(): void {
        acf_add_local_field_group( [
            'key'    => 'group_rba_room',
            'title' => 'Thông Tin Phòng Nâng Cao (Resort)',
            'fields' => [
                [
                    'key'          => 'field_room_quantity',
                    'label'        => 'Số lượng phòng vật lý',
                    'name'         => 'room_quantity',
                    'type'         => 'number',
                    'instructions' => 'VD: 3 phòng Deluxe có cùng layout',
                    'default_value'=> 1,
                    'min'          => 1,
                ],
                [
                    'key'   => 'field_room_size',
                    'label' => 'Diện tích (m²)',
                    'name'  => 'room_size',
                    'type'  => 'number',
                ],
                [
                    'key'           => 'field_room_view',
                    'label'         => 'Hướng nhìn',
                    'name'          => 'room_view',
                    'type'          => 'select',
                    'choices'       => [
                        'sea'      => 'Hướng biển',
                        'garden'   => 'Hướng vườn',
                        'pool'     => 'Hướng hồ bơi',
                        'mountain' => 'Hướng núi',
                        'city'     => 'Hướng thành phố',
                    ],
                    'multiple'      => 1,
                    'ui'            => 1,
                    'allow_null'    => 1,
                    'default_value' => '',
                    'return_format' => 'value',
                ],
                [
                    'key'     => 'field_room_floor',
                    'label'   => 'Tầng',
                    'name'    => 'room_floor',
                    'type'    => 'number',
                ],
                [
                    'key'           => 'field_room_beds',
                    'label'         => 'Loại giường',
                    'name'          => 'room_beds',
                    'type'          => 'checkbox',
                    'choices'       => [
                        'king'   => 'King Size',
                        'queen'  => 'Queen Size',
                        'twin'   => '2 Single',
                        'double' => 'Double',
                        'sofa'   => 'Sofa Bed',
                        'bunk'   => 'Giường tầng',
                    ],
                    'allow_custom'  => 0,
                    'default_value' => '',
                    'return_format' => 'value',
                    'layout'        => 'vertical',
                ],
                [
                    'key'          => 'field_room_base_price',
                    'label'        => 'Giá cơ bản / đêm (VNĐ)',
                    'name'         => 'room_base_price',
                    'type'         => 'number',
                    'instructions' => 'Giá này sync tự động vào Tourfic meta _tf_price',
                ],
                [
                    'key'           => 'field_room_included',
                    'label'         => 'Bao gồm trong giá phòng',
                    'name'          => 'room_included',
                    'type'          => 'checkbox',
                    'choices'       => [
                        'breakfast' => 'Bữa sáng',
                        'pool'      => 'Hồ bơi',
                        'wifi'      => 'WiFi',
                        'parking'   => 'Bãi đỗ xe',
                        'transfer'  => 'Đón sân bay',
                        'gym'       => 'Phòng gym',
                        'spa'       => 'Spa',
                        'minibar'   => 'Minibar',
                    ],
                    'allow_custom'  => 0,
                    'default_value' => '',
                    'return_format' => 'value',
                    'layout'        => 'vertical',
                ],
                [
                    'key'          => 'field_room_gallery_extra',
                    'label'        => 'Ảnh phòng bổ sung',
                    'name'         => 'room_gallery_extra',
                    'type'         => 'gallery',
                    'return_format'=> 'array',
                ],
                [
                    'key'   => 'field_room_map_embed',
                    'label' => 'Google Maps embed code',
                    'name'  => 'room_map_embed',
                    'type'  => 'textarea',
                    'rows'  => 3,
                ],
                [
                    'key'   => 'field_room_virtual_tour',
                    'label' => 'Virtual Tour URL (360°)',
                    'name'  => 'room_virtual_tour',
                    'type'  => 'url',
                ],
                [
                    'key'          => 'field_room_cancellation',
                    'label'        => 'Chính sách hủy phòng',
                    'name'         => 'room_cancellation_policy',
                    'type'         => 'wysiwyg',
                    'toolbar'      => 'basic',
                    'media_upload' => 0,
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_type', 'operator' => '', 'value' => 'tf_room' ] ],
            ],
            'position' => 'normal',
            'style'    => 'seamless',
        ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // FIELD GROUP: Tour nội khu (tf_tour)
    // ────────────────────────────────────────────────────────────────────────

    public function register_tour_fields(): void {
        acf_add_local_field_group( [
            'key'    => 'group_rba_tour',
            'title' => 'Thông Tin Tour Nội Khu',
            'fields' => [
                [
                    'key'           => 'field_tour_type',
                    'label'         => 'Loại tour',
                    'name'          => 'tour_type',
                    'type'          => 'select',
                    'choices'       => [
                        'nature'     => 'Thiên nhiên / Sinh thái',
                        'cultural'   => 'Văn hóa / Ẩm thực',
                        'adventure'  => 'Mạo hiểm',
                        'relaxation' => 'Thư giãn / Spa',
                        'family'     => 'Gia đình',
                    ],
                    'allow_null'    => 1,
                    'default_value' => '',
                    'return_format' => 'value',
                ],
                [
                    'key'   => 'field_tour_max_pax',
                    'label' => 'Số người tối đa / lượt',
                    'name'  => 'tour_max_pax',
                    'type'  => 'number',
                    'default_value' => 20,
                ],
                [
                    'key'    => 'field_tour_slots',
                    'label'  => 'Khung giờ tour trong ngày',
                    'name'   => 'tour_time_slots',
                    'type'   => 'repeater',
                    'layout' => 'table',
                    'sub_fields' => [
                        [ 'key' => 'field_slot_time',  'label' => 'Giờ xuất phát', 'name' => 'slot_time',  'type' => 'time_picker' ],
                        [ 'key' => 'field_slot_limit', 'label' => 'Giới hạn khách', 'name' => 'slot_limit', 'type' => 'number', 'default_value' => 15 ],
                    ],
                ],
                [
                    'key'     => 'field_tour_meeting_point',
                    'label'   => 'Điểm hẹn / xuất phát',
                    'name'    => 'tour_meeting_point',
                    'type'    => 'text',
                    'placeholder' => 'VD: Sảnh chính Khu A',
                ],
                [
                    'key'           => 'field_tour_includes',
                    'label'         => 'Tour bao gồm',
                    'name'          => 'tour_includes',
                    'type'          => 'checkbox',
                    'choices'       => [
                        'guide'     => 'Hướng dẫn viên',
                        'equipment' => 'Dụng cụ / trang thiết bị',
                        'meal'      => 'Bữa ăn',
                        'transport' => 'Đưa đón trong khu',
                        'insurance' => 'Bảo hiểm',
                        'photo'     => 'Dịch vụ chụp ảnh',
                    ],
                    'allow_custom'  => 0,
                    'default_value' => '',
                    'return_format' => 'value',
                    'layout'        => 'vertical',
                ],
                [
                    'key'           => 'field_tour_difficulty',
                    'label'         => 'Mức độ vận động',
                    'name'          => 'tour_difficulty',
                    'type'          => 'radio',
                    'choices'       => [
                        'easy'   => 'Dễ (Phù hợp mọi lứa tuổi)',
                        'medium' => 'Trung bình (Cần sức khỏe cơ bản)',
                        'hard'   => 'Cao (Người năng động)',
                    ],
                    'allow_null'    => 1,
                    'default_value' => 'easy',
                    'return_format' => 'value',
                    'layout'        => 'horizontal',
                ],
                [
                    'key'     => 'field_tour_itinerary',
                    'label'   => 'Lịch trình chi tiết',
                    'name'    => 'tour_itinerary_detail',
                    'type'    => 'repeater',
                    'layout'  => 'block',
                    'sub_fields' => [
                        [ 'key' => 'field_it_time',  'label' => 'Thời gian', 'name' => 'time',        'type' => 'text', 'placeholder' => '08:00' ],
                        [ 'key' => 'field_it_title', 'label' => 'Hoạt động', 'name' => 'title',       'type' => 'text' ],
                        [ 'key' => 'field_it_desc',  'label' => 'Mô tả',    'name' => 'description', 'type' => 'textarea', 'rows' => 2 ],
                        [ 'key' => 'field_it_img',   'label' => 'Ảnh',      'name' => 'image',        'type' => 'image', 'return_format' => 'array' ],
                    ],
                ],
                [
                    'key'     => 'field_tour_faq',
                    'label'   => 'FAQ',
                    'name'    => 'tour_faq',
                    'type'    => 'repeater',
                    'layout'  => 'block',
                    'sub_fields' => [
                        [ 'key' => 'field_faq_q', 'label' => 'Câu hỏi', 'name' => 'question', 'type' => 'text' ],
                        [ 'key' => 'field_faq_a', 'label' => 'Trả lời', 'name' => 'answer',   'type' => 'wysiwyg', 'toolbar' => 'basic', 'media_upload' => 0 ],
                    ],
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_type', 'operator' => '', 'value' => 'tf_tour' ] ],
            ],
        ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // FIELD GROUP: Khu nghỉ dưỡng tổng thể (tf_hotel)
    // ────────────────────────────────────────────────────────────────────────

    public function register_resort_fields(): void {
        acf_add_local_field_group( [
            'key'    => 'group_rba_resort',
            'title' => 'Thông Tin Khu Nghỉ Dưỡng',
            'fields' => [
                [
                    'key'     => 'field_resort_star',
                    'label'   => 'Xếp hạng sao',
                    'name'    => 'resort_star_rating',
                    'type'    => 'radio',
                    'choices' => [ '3' => '3 sao', '4' => '4 sao', '5' => '5 sao' ],
                    'layout'  => 'horizontal',
                ],
                [
                    'key'   => 'field_resort_highlight',
                    'label' => 'Điểm nổi bật',
                    'name'  => 'resort_highlights',
                    'type'  => 'repeater',
                    'layout'=> 'table',
                    'sub_fields' => [
                        [ 'key' => 'field_hl_icon',  'label' => 'Icon (emoji)', 'name' => 'icon',  'type' => 'text', 'placeholder' => '' ],
                        [ 'key' => 'field_hl_title', 'label' => 'Tiêu đề',     'name' => 'title', 'type' => 'text' ],
                    ],
                ],
                [
                    'key'     => 'field_resort_facilities',
                    'label'   => 'Tiện ích khu nghỉ dưỡng',
                    'name'    => 'resort_facilities',
                    'type'    => 'checkbox',
                    'choices' => [
                        'pool' => 'Hồ bơi',
                        'spa' => 'Spa & Wellness',
                        'restaurant' => 'Nhà hàng',
                        'bar' => 'Bar & Lounge',
                        'gym' => 'Phòng gym',
                        'kids' => 'Khu vui chơi trẻ em',
                        'beach' => 'Bãi biển riêng',
                        'helipad' => 'Sân bay trực thăng',
                        'ev_charging' => 'Trạm sạc xe điện',
                    ],
                ],
                [
                    'key'   => 'field_resort_checkin_time',
                    'label' => 'Giờ check-in',
                    'name'  => 'resort_checkin_time',
                    'type'  => 'time_picker',
                ],
                [
                    'key'   => 'field_resort_checkout_time',
                    'label' => 'Giờ check-out',
                    'name'  => 'resort_checkout_time',
                    'type'  => 'time_picker',
                ],
                [
                    'key'   => 'field_resort_policies',
                    'label' => 'Chính sách khu nghỉ dưỡng',
                    'name'  => 'resort_policies',
                    'type'  => 'wysiwyg',
                ],
                [
                    'key'   => 'field_resort_social',
                    'label' => 'Mạng xã hội',
                    'name'  => 'resort_social_links',
                    'type'  => 'group',
                    'sub_fields' => [
                        [ 'key' => 'field_social_fb',  'label' => 'Facebook', 'name' => 'facebook', 'type' => 'url' ],
                        [ 'key' => 'field_social_ig',  'label' => 'Instagram','name' => 'instagram','type' => 'url' ],
                        [ 'key' => 'field_social_yt',  'label' => 'YouTube',  'name' => 'youtube',  'type' => 'url' ],
                        [ 'key' => 'field_social_tkt', 'label' => 'TikTok',   'name' => 'tiktok',   'type' => 'url' ],
                    ],
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_type', 'operator' => '', 'value' => 'tf_hotel' ] ],
            ],
        ] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // SYNC: ACF → Tourfic meta
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Type-safe wrapper: validate $post_id trước khi sync.
     * Cần thiết vì acf/save_post có thể truyền string ('options', 'user_1'...).
     *
     * @param int|string $post_id
     */
    public function maybe_sync_room( $post_id ): void {
        // Bỏ qua nếu không phải numeric ID (options page, user meta, term meta...)
        if ( ! is_numeric( $post_id ) ) return;
        $this->sync_room_acf_to_tourfic( (int) $post_id );
    }

    public function sync_room_acf_to_tourfic( int $post_id ): void {
        if ( get_post_type( $post_id ) !== 'tf_room' ) return;

        // Sync base price
        $base_price = get_field( 'room_base_price', $post_id );
        if ( $base_price ) {
            update_post_meta( $post_id, '_tf_price',           $base_price );
            update_post_meta( $post_id, '_tf_price_per_night', $base_price );
        }

        // Sync quantity → availability table
        $qty = (int) ( get_field('room_quantity', $post_id) ?: 1 );
        update_post_meta( $post_id, '_tf_room_quantity', $qty );

        // Re-init availability nếu số lượng thay đổi
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rba_availability SET total_rooms = %d WHERE room_id = %d",
            $qty, $post_id
        ) );
    }

    /**
     * Type-safe wrapper cho tour sync.
     * @param int|string $post_id
     */
    public function maybe_sync_tour( $post_id ): void {
        if ( ! is_numeric( $post_id ) ) return;
        $this->sync_tour_acf_to_tourfic( (int) $post_id );
    }

    public function sync_tour_acf_to_tourfic( int $post_id ): void {
        if ( get_post_type($post_id) !== 'tf_tour' ) return;

        $max_pax = (int) ( get_field('tour_max_pax', $post_id) ?: 20 );
        update_post_meta( $post_id, '_tf_tour_max_persons', $max_pax );
    }

    // ────────────────────────────────────────────────────────────────────────
    // SHORTCODE: [rba_field post_id="123" field="room_view"]
    // ────────────────────────────────────────────────────────────────────────

    public function shortcode_field( array $atts ): string {
        $atts = shortcode_atts( [
            'post_id' => get_the_ID(),
            'field'   => '',
            'format'  => 'text',
        ], $atts );

        if ( ! $atts['field'] ) return '';
        $value = get_field( $atts['field'], (int) $atts['post_id'] );
        if ( is_array($value) ) return implode( ', ', $value );
        return wp_kses_post( (string) $value );
    }

    // ────────────────────────────────────────────────────────────────────────
    // EMAIL: Append ACF data vào booking confirmation
    // ────────────────────────────────────────────────────────────────────────

    public function append_acf_to_email( array $data, int $booking_id ): array {
        $room_id = $data['room_id'] ?? 0;
        if ( ! $room_id ) return $data;

        $data['room_size']        = get_field('room_size', $room_id);
        $data['room_view']        = implode(', ', (array) get_field('room_view', $room_id));
        $data['included']         = implode(', ', (array) get_field('room_included', $room_id));
        $data['checkin_time']     = get_field('resort_checkin_time', get_post_meta($room_id, '_tf_related_hotel', true));
        $data['checkout_time']    = get_field('resort_checkout_time', get_post_meta($room_id, '_tf_related_hotel', true));
        $data['cancellation_note']= get_field('room_cancellation_policy', $room_id);

        return $data;
    }

    // ────────────────────────────────────────────────────────────────────────
    // HELPER: Lấy field an toàn (fallback nếu ACF chưa active)
    // ────────────────────────────────────────────────────────────────────────

    public static function get( string $field_name, int $post_id ): mixed {
        if ( function_exists('get_field') ) {
            return get_field( $field_name, $post_id );
        }
        return get_post_meta( $post_id, $field_name, true );
    }
}

new RBA_ACF_Bridge();
