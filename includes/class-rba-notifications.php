<?php
/**
 * RBA_Notifications
 *
 * Email notifications cho booking events.
 * Module này là OPTIONAL — plugin hoạt động bình thường nếu file này không có.
 *
 * @package ResortBookingAddon
 * @since   1.0.1
 */
defined( 'ABSPATH' ) || exit;

class RBA_Notifications {

    public function __construct() {
        // Email khi booking confirmed
        add_action( 'rba_booking_confirmed', [ $this, 'send_confirmation_email' ], 10, 2 );
        // Email khi booking released/cancelled
        add_action( 'rba_booking_released',  [ $this, 'send_cancellation_email' ], 10, 2 );
    }

    /**
     * Gửi email xác nhận cho khách và admin.
     *
     * @param int       $order_id WooCommerce order ID.
     * @param \WC_Order $order    Order object.
     */
    public function send_confirmation_email( int $order_id, \WC_Order $order ): void {
        $billing_email = $order->get_billing_email();
        if ( ! $billing_email ) return;

        $name       = $order->get_billing_first_name();
        $site_name  = get_bloginfo( 'name' );
        $order_url  = $order->get_view_order_url();

        // Build booking summary
        $lines = [];
        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $room_id   = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            $check_in  = $item->get_meta( 'tf_check_in' )  ?: $item->get_meta( 'check_in' );
            $check_out = $item->get_meta( 'tf_check_out' ) ?: $item->get_meta( 'check_out' );
            if ( ! $room_id ) continue;
            $nights = $check_in && $check_out
                ? (int) ( ( strtotime( $check_out ) - strtotime( $check_in ) ) / DAY_IN_SECONDS )
                : 0;
            $lines[] = sprintf(
                '- %s: %s → %s (%d đêm)',
                get_the_title( $room_id ),
                $check_in,
                $check_out,
                $nights
            );
        }

        $subject = sprintf( '[%s] Xác nhận đặt phòng #%d', $site_name, $order_id );
        $message = sprintf(
            "Xin chào %s,\n\nĐặt phòng của bạn đã được xác nhận!\n\nChi tiết:\n%s\n\nTổng tiền: %s\n\nXem chi tiết đặt phòng: %s\n\nTrân trọng,\n%s",
            $name,
            implode( "\n", $lines ),
            wp_strip_all_tags( wc_price( $order->get_total() ) ),
            $order_url,
            $site_name
        );

        wp_mail(
            $billing_email,
            $subject,
            $message,
            [ 'Content-Type: text/plain; charset=UTF-8' ]
        );

        // Notify admin
        wp_mail(
            get_option( 'admin_email' ),
            sprintf( '[%s] Booking mới #%d — %s', $site_name, $order_id, $billing_email ),
            $message,
            [ 'Content-Type: text/plain; charset=UTF-8' ]
        );
    }

    /**
     * Gửi email thông báo hủy.
     *
     * @param int       $order_id WooCommerce order ID.
     * @param \WC_Order $order    Order object.
     */
    public function send_cancellation_email( int $order_id, \WC_Order $order ): void {
        $billing_email = $order->get_billing_email();
        if ( ! $billing_email ) return;

        $site_name = get_bloginfo( 'name' );
        $name      = $order->get_billing_first_name();

        wp_mail(
            $billing_email,
            sprintf( '[%s] Xác nhận hủy đặt phòng #%d', $site_name, $order_id ),
            sprintf(
                "Xin chào %s,\n\nĐặt phòng #%d của bạn đã được hủy thành công.\n\nNếu cần hỗ trợ, vui lòng liên hệ chúng tôi.\n\nTrân trọng,\n%s",
                $name,
                $order_id,
                $site_name
            ),
            [ 'Content-Type: text/plain; charset=UTF-8' ]
        );
    }
}

new RBA_Notifications();
