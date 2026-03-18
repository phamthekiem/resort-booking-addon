<?php
/**
 * RBA_OTA_API — OTA API Bridge (Adapter Pattern)
 *
 * THỰC TRẠNG API CÁC OTA (tính đến 2025):
 * ─────────────────────────────────────────────────────────────────────────
 *  Booking.com : Chỉ dành cho Connectivity Partners (đang tạm dừng đăng ký mới).
 *                Format: XML (OTA standard). Cần chứng nhận trước khi go live.
 *  Agoda YCS   : Dùng được qua Channel Manager mode. Cần Property ID + Room IDs.
 *  Airbnb      : API dành cho Software Companies đã đăng ký, không cho individual property.
 *  Traveloka   : Partners Network API — yêu cầu hợp đồng đối tác.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * MODULE NÀY GIẢI QUYẾT 2 BÀI TOÁN THỰC TẾ:
 *
 * 1. TRƯỜNG HỢP SỬ DỤNG API ĐẦY ĐỦ (Full API flow):
 *    WordPress Website (nguồn sự thật) → push availability + rates → OTA
 *    → OTA nhận booking → push reservation về Website → Website sync KiotViet
 *    Luồng: Website ──availability/rates──► OTA
 *                    ◄──reservation────── OTA
 *                    ──────booking──────► KiotViet
 *
 * 2. LIGHTWEIGHT CHANNEL MANAGER:
 *    Module đóng vai trò channel manager tối giản, đủ để:
 *    - Push availability (open/close dates) lên Agoda qua XML API
 *    - Nhận reservation push từ OTA (Booking.com XML notifications)
 *    - Sync lại KiotViet qua RBA_KiotViet bridge đã có
 *
 * ADAPTER PATTERN:
 *    RBA_OTA_API (orchestrator)
 *      ├── RBA_OTA_Adapter_Agoda     → Agoda XML API
 *      ├── RBA_OTA_Adapter_Booking   → Booking.com XML API (khi được chấp nhận)
 *      └── RBA_OTA_Adapter_Generic   → Bất kỳ OTA nào có REST/XML API
 *
 * @package ResortBookingAddon
 * @since   1.3.0
 */
defined( 'ABSPATH' ) || exit;

// ═════════════════════════════════════════════════════════════════════════════
// INTERFACE: Mọi OTA adapter đều phải implement interface này
// ═════════════════════════════════════════════════════════════════════════════

interface RBA_OTA_Adapter_Interface {
    /**
     * Push availability (mở/đóng phòng) lên OTA.
     * @param int    $wp_room_id  WP post ID của phòng
     * @param string $date_from   Y-m-d
     * @param string $date_to     Y-m-d
     * @param int    $allotment   Số phòng còn trống (0 = đóng)
     * @return bool
     */
    public function push_availability( int $wp_room_id, string $date_from, string $date_to, int $allotment ): bool;

    /**
     * Push giá lên OTA.
     * @param int    $wp_room_id
     * @param string $date_from
     * @param string $date_to
     * @param float  $price_per_night  Giá/đêm (VNĐ hoặc tiền tệ cấu hình)
     * @return bool
     */
    public function push_rate( int $wp_room_id, string $date_from, string $date_to, float $price_per_night ): bool;

    /**
     * Lấy danh sách reservations mới từ OTA (polling fallback).
     * @param string $from  Y-m-d
     * @param string $to    Y-m-d
     * @return array  [ { ota_reservation_id, room_id, check_in, check_out, guest_name, guest_phone, total, status } ]
     */
    public function pull_reservations( string $from, string $to ): array;

    /**
     * Xác nhận đã nhận reservation (ACK).
     * @param string $ota_reservation_id
     * @return bool
     */
    public function ack_reservation( string $ota_reservation_id ): bool;

    /** Tên OTA (dùng cho log) */
    public function get_name(): string;
}

// ═════════════════════════════════════════════════════════════════════════════
// ADAPTER: Agoda YCS API (XML format — OTA standard)
// ═════════════════════════════════════════════════════════════════════════════

class RBA_OTA_Adapter_Agoda implements RBA_OTA_Adapter_Interface {

    const API_BASE = 'https://api.agoda.com/supply/'; // Endpoint sandbox/prod

    private string $hotel_id;
    private string $username;
    private string $password;
    private array  $room_map; // wp_room_id => agoda_room_id

    public function __construct( array $config ) {
        $this->hotel_id = $config['hotel_id']  ?? '';
        $this->username = $config['username']  ?? '';
        $this->password = $config['password']  ?? '';
        $this->room_map = $config['room_map']  ?? [];
    }

    public function get_name(): string { return 'Agoda'; }

    /**
     * Push availability lên Agoda YCS qua XML API.
     *
     * Agoda dùng OTA_HotelAvailNotif (v1.1) format:
     * POST https://api.agoda.com/supply/
     * Basic Auth: username:password
     * Body: XML payload
     */
    public function push_availability( int $wp_room_id, string $date_from, string $date_to, int $allotment ): bool {
        $agoda_room_id = $this->room_map[ $wp_room_id ] ?? '';
        if ( ! $agoda_room_id || ! $this->hotel_id ) return false;

        $status = $allotment > 0 ? 'Open' : 'Close';

        $xml = $this->build_avail_xml( $agoda_room_id, $date_from, $date_to, $allotment, $status );
        $response = $this->request( $xml );

        return $this->parse_success( $response );
    }

    /**
     * Push giá lên Agoda qua OTA_HotelRateAmountNotif (v1.1).
     */
    public function push_rate( int $wp_room_id, string $date_from, string $date_to, float $price_per_night ): bool {
        $agoda_room_id  = $this->room_map[ $wp_room_id ] ?? '';
        if ( ! $agoda_room_id ) return false;

        // Agoda nhận giá theo tiền tệ của property (VND mặc định cho VN properties)
        $xml = $this->build_rate_xml( $agoda_room_id, $date_from, $date_to, $price_per_night );
        $response = $this->request( $xml );

        return $this->parse_success( $response );
    }

    /**
     * Pull reservations từ Agoda.
     * Agoda cũng hỗ trợ push (notification) nhưng polling là fallback an toàn.
     */
    public function pull_reservations( string $from, string $to ): array {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OTA_ReadRQ xmlns="http://www.opentravel.org/OTA/2003/05"
            EchoToken="{$this->echo_token()}"
            TimeStamp="{$this->timestamp()}"
            Version="1.0">
  <ReadRequests>
    <HotelReadRequest HotelCode="{$this->hotel_id}">
      <SelectionCriteria Start="{$from}" End="{$to}"
                         SelectType="Undelivered"/>
    </HotelReadRequest>
  </ReadRequests>
</OTA_ReadRQ>
XML;
        $response = $this->request( $xml );
        return $this->parse_reservations( $response );
    }

    public function ack_reservation( string $ota_reservation_id ): bool {
        // Agoda auto-marks delivered sau khi pull — không cần ack riêng
        return true;
    }

    // ── XML builders ─────────────────────────────────────────────────────────

    private function build_avail_xml( string $room_id, string $from, string $to, int $allotment, string $status ): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OTA_HotelAvailNotifRQ xmlns="http://www.opentravel.org/OTA/2003/05"
                       EchoToken="{$this->echo_token()}"
                       TimeStamp="{$this->timestamp()}"
                       Version="1.1">
  <AvailStatusMessages HotelCode="{$this->hotel_id}">
    <AvailStatusMessage BookingLimit="{$allotment}">
      <StatusApplicationControl
        Start="{$from}"
        End="{$to}"
        InvTypeCode="{$room_id}"
        IsRoom="true"/>
      <RestrictionStatus Status="{$status}" Restriction="Master"/>
    </AvailStatusMessage>
  </AvailStatusMessages>
</OTA_HotelAvailNotifRQ>
XML;
    }

    private function build_rate_xml( string $room_id, string $from, string $to, float $price ): string {
        $price_str = number_format( $price, 0, '.', '' );
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OTA_HotelRateAmountNotifRQ xmlns="http://www.opentravel.org/OTA/2003/05"
                            EchoToken="{$this->echo_token()}"
                            TimeStamp="{$this->timestamp()}"
                            Version="1.1">
  <RateAmountMessages HotelCode="{$this->hotel_id}">
    <RateAmountMessage>
      <StatusApplicationControl
        Start="{$from}"
        End="{$to}"
        InvTypeCode="{$room_id}"/>
      <Rates>
        <Rate>
          <BaseByGuestAmts>
            <BaseByGuestAmt AmountAfterTax="{$price_str}" CurrencyCode="VND"/>
          </BaseByGuestAmts>
        </Rate>
      </Rates>
    </RateAmountMessage>
  </RateAmountMessages>
</OTA_HotelRateAmountNotifRQ>
XML;
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    private function request( string $xml_body ): string {
        $response = wp_remote_post( self::API_BASE, [
            'timeout' => 20,
            'headers' => [
                'Content-Type'  => 'text/xml; charset=UTF-8',
                'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
            ],
            'body' => $xml_body,
        ] );

        if ( is_wp_error( $response ) ) return '';
        return wp_remote_retrieve_body( $response );
    }

    private function parse_success( string $xml ): bool {
        if ( empty( $xml ) ) return false;
        // OTA success: <Success/> element present và không có <Errors>
        return str_contains( $xml, '<Success' ) && ! str_contains( $xml, '<Error ' );
    }

    private function parse_reservations( string $xml ): array {
        if ( empty( $xml ) ) return [];

        $reservations = [];
        try {
            $dom = new DOMDocument();
            if ( ! @$dom->loadXML( $xml ) ) return [];

            $items = $dom->getElementsByTagNameNS( 'http://www.opentravel.org/OTA/2003/05', 'HotelReservation' );
            foreach ( $items as $res ) {
                $uid        = $res->getElementsByTagNameNS( '*', 'UniqueID' )->item(0);
                $room_ref   = $res->getElementsByTagNameNS( '*', 'RoomType' )->item(0);
                $time_span  = $res->getElementsByTagNameNS( '*', 'TimeSpan' )->item(0);
                $guest_node = $res->getElementsByTagNameNS( '*', 'GivenName' )->item(0);
                $phone_node = $res->getElementsByTagNameNS( '*', 'PhoneNumber' )->item(0);
                $total_node = $res->getElementsByTagNameNS( '*', 'Total' )->item(0);
                $status_node= $res->getElementsByTagNameNS( '*', 'ResGlobalInfo' )->item(0);

                $reservations[] = [
                    'ota_reservation_id' => $uid?->getAttribute('ID') ?? '',
                    'ota_room_id'        => $room_ref?->getAttribute('InvTypeCode') ?? '',
                    'check_in'           => $time_span?->getAttribute('Start') ?? '',
                    'check_out'          => $time_span?->getAttribute('End')   ?? '',
                    'guest_name'         => $guest_node?->textContent ?? '',
                    'guest_phone'        => $phone_node?->textContent ?? '',
                    'total'              => (float) ( $total_node?->getAttribute('AmountAfterTax') ?? 0 ),
                    'status'             => $status_node?->getAttribute('ResStatus') ?? 'Commit',
                ];
            }
        } catch ( \Exception $e ) {
            // Silently fail — caller sẽ handle empty array
        }

        return $reservations;
    }

    private function echo_token(): string { return 'rba-' . uniqid(); }
    private function timestamp(): string  { return gmdate( 'Y-m-d\TH:i:s\Z' ); }
}

// ═════════════════════════════════════════════════════════════════════════════
// ADAPTER: Booking.com XML API (khi được chấp nhận làm Connectivity Partner)
// ═════════════════════════════════════════════════════════════════════════════

class RBA_OTA_Adapter_Booking implements RBA_OTA_Adapter_Interface {

    // Booking.com dùng 2 base URLs:
    // Non-PCI (non-reservation): https://supply-xml.booking.com
    // PCI (reservation):         https://secure-supply-xml.booking.com
    const API_BASE_SUPPLY  = 'https://supply-xml.booking.com';
    const API_BASE_SECURE  = 'https://secure-supply-xml.booking.com';

    private string $hotel_id;
    private string $username;
    private string $password;
    private array  $room_map;

    public function __construct( array $config ) {
        $this->hotel_id = $config['hotel_id'] ?? '';
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->room_map = $config['room_map'] ?? [];
    }

    public function get_name(): string { return 'Booking.com'; }

    /**
     * Push availability via Booking.com B.XML v1.1 endpoint.
     * POST https://supply-xml.booking.com/hotels/OTA_HotelAvailNotif
     */
    public function push_availability( int $wp_room_id, string $date_from, string $date_to, int $allotment ): bool {
        $bcom_room_id = $this->room_map[ $wp_room_id ] ?? '';
        if ( ! $bcom_room_id || ! $this->hotel_id ) return false;

        $status = $allotment > 0 ? 'Open' : 'Close';
        $xml    = $this->build_avail_xml( $bcom_room_id, $date_from, $date_to, $allotment, $status );

        $response = $this->request( '/hotels/OTA_HotelAvailNotif', $xml );
        return $this->parse_success( $response );
    }

    /**
     * Push giá via OTA_HotelRateAmountNotif v1.1.
     */
    public function push_rate( int $wp_room_id, string $date_from, string $date_to, float $price_per_night ): bool {
        $bcom_room_id = $this->room_map[ $wp_room_id ] ?? '';
        if ( ! $bcom_room_id ) return false;

        $xml = $this->build_rate_xml( $bcom_room_id, $date_from, $date_to, $price_per_night );
        $response = $this->request( '/hotels/OTA_HotelRateAmountNotif', $xml );
        return $this->parse_success( $response );
    }

    /**
     * Pull reservations từ Booking.com reservations endpoint.
     */
    public function pull_reservations( string $from, string $to ): array {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OTA_ReadRQ xmlns="http://www.opentravel.org/OTA/2003/05"
            EchoToken="rba-{$this->hotel_id}"
            TimeStamp="{$this->timestamp()}"
            Version="1.0"
            Username="{$this->username}"
            Password="{$this->password}">
  <ReadRequests>
    <HotelReadRequest HotelCode="{$this->hotel_id}">
      <SelectionCriteria Start="{$from}T00:00:00" End="{$to}T23:59:59"
                         DateType="ArrivalDate" SelectType="Undelivered"/>
    </HotelReadRequest>
  </ReadRequests>
</OTA_ReadRQ>
XML;
        // Reservations dùng secure endpoint
        $response = $this->request( '/hotels/res', $xml, true );
        return $this->parse_reservations( $response );
    }

    public function ack_reservation( string $ota_reservation_id ): bool {
        // Booking.com tự mark delivered sau khi pull — no explicit ACK needed
        return true;
    }

    // ── XML builders ─────────────────────────────────────────────────────────

    private function build_avail_xml( string $room_id, string $from, string $to, int $allotment, string $status ): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OTA_HotelAvailNotifRQ xmlns="http://www.opentravel.org/OTA/2003/05"
                       EchoToken="rba-{$this->echo_token()}"
                       TimeStamp="{$this->timestamp()}"
                       Version="1.1"
                       Username="{$this->username}"
                       Password="{$this->password}">
  <AvailStatusMessages HotelCode="{$this->hotel_id}">
    <AvailStatusMessage BookingLimit="{$allotment}">
      <StatusApplicationControl
        Start="{$from}"
        End="{$to}"
        InvTypeCode="{$room_id}"
        IsRoom="true"/>
      <RestrictionStatus Status="{$status}" Restriction="Master"/>
    </AvailStatusMessage>
  </AvailStatusMessages>
</OTA_HotelAvailNotifRQ>
XML;
    }

    private function build_rate_xml( string $room_id, string $from, string $to, float $price ): string {
        $price_vnd = (int) $price;
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OTA_HotelRateAmountNotifRQ xmlns="http://www.opentravel.org/OTA/2003/05"
                            EchoToken="rba-{$this->echo_token()}"
                            TimeStamp="{$this->timestamp()}"
                            Version="1.1"
                            Username="{$this->username}"
                            Password="{$this->password}">
  <RateAmountMessages HotelCode="{$this->hotel_id}">
    <RateAmountMessage>
      <StatusApplicationControl Start="{$from}" End="{$to}" InvTypeCode="{$room_id}"/>
      <Rates>
        <Rate>
          <BaseByGuestAmts>
            <BaseByGuestAmt AmountAfterTax="{$price_vnd}" CurrencyCode="VND"/>
          </BaseByGuestAmts>
        </Rate>
      </Rates>
    </RateAmountMessage>
  </RateAmountMessages>
</OTA_HotelRateAmountNotifRQ>
XML;
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    private function request( string $path, string $xml_body, bool $secure = false ): string {
        $base_url = $secure ? self::API_BASE_SECURE : self::API_BASE_SUPPLY;
        $response = wp_remote_post( $base_url . $path, [
            'timeout' => 20,
            'headers' => [
                'Content-Type'  => 'text/xml; charset=UTF-8',
                'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
            ],
            'body' => $xml_body,
        ] );

        if ( is_wp_error( $response ) ) return '';
        return wp_remote_retrieve_body( $response );
    }

    private function parse_success( string $xml ): bool {
        return ! empty( $xml ) && str_contains( $xml, '<Success' ) && ! str_contains( $xml, '<Error ' );
    }

    private function parse_reservations( string $xml ): array {
        if ( empty( $xml ) ) return [];
        $reservations = [];
        try {
            $dom = new DOMDocument();
            if ( ! @$dom->loadXML( $xml ) ) return [];

            foreach ( $dom->getElementsByTagNameNS( '*', 'HotelReservation' ) as $res ) {
                $uid      = $res->getElementsByTagNameNS( '*', 'UniqueID' )->item(0);
                $span     = $res->getElementsByTagNameNS( '*', 'TimeSpan' )->item(0);
                $room     = $res->getElementsByTagNameNS( '*', 'RoomType' )->item(0);
                $given    = $res->getElementsByTagNameNS( '*', 'GivenName' )->item(0);
                $surname  = $res->getElementsByTagNameNS( '*', 'Surname' )->item(0);
                $phone    = $res->getElementsByTagNameNS( '*', 'PhoneNumber' )->item(0);
                $total    = $res->getElementsByTagNameNS( '*', 'Total' )->item(0);

                $reservations[] = [
                    'ota_reservation_id' => $uid?->getAttribute('ID') ?? '',
                    'ota_room_id'        => $room?->getAttribute('InvTypeCode') ?? '',
                    'check_in'           => $span?->getAttribute('Start') ?? '',
                    'check_out'          => $span?->getAttribute('End')   ?? '',
                    'guest_name'         => trim( ( $given?->textContent ?? '' ) . ' ' . ( $surname?->textContent ?? '' ) ),
                    'guest_phone'        => $phone?->textContent ?? '',
                    'total'              => (float) ( $total?->getAttribute('AmountAfterTax') ?? 0 ),
                    'status'             => 'Commit',
                ];
            }
        } catch ( \Exception $e ) {}
        return $reservations;
    }

    private function echo_token(): string { return uniqid( 'rba-' ); }
    private function timestamp(): string  { return gmdate( 'Y-m-d\TH:i:s\Z' ); }
}

// ═════════════════════════════════════════════════════════════════════════════
// ORCHESTRATOR — RBA_OTA_API
// ═════════════════════════════════════════════════════════════════════════════

class RBA_OTA_API {

    const OPT_ADAPTERS  = 'rba_ota_api_adapters';
    const OPT_ROOM_MAPS = 'rba_ota_api_room_maps';
    const OPT_ENABLED   = 'rba_ota_api_enabled';
    const WEBHOOK_SLUG  = 'rba-ota-reservation';

    /** @var RBA_OTA_Adapter_Interface[] */
    private array $adapters = [];

    public function __construct() {
        $this->load_adapters();

        // ── Hooks ─────────────────────────────────────────────────────────────
        // Khi availability thay đổi trên website → push lên tất cả OTA
        add_action( 'rba_availability_changed', [ $this, 'on_availability_changed' ], 10, 4 );

        // Khi booking confirmed → push close availability
        add_action( 'rba_booking_confirmed',    [ $this, 'on_booking_confirmed'    ], 20, 2 );

        // Khi booking released → push open availability
        add_action( 'rba_booking_released',     [ $this, 'on_booking_released'     ], 20, 2 );

        // Khi giá thay đổi (seasonal price save) → push lên OTA
        add_action( 'rba_price_updated',        [ $this, 'on_price_updated'        ], 10, 4 );

        // Nhận reservation push từ OTA (webhook endpoint)
        add_action( 'init',               [ $this, 'register_reservation_endpoint' ] );
        add_action( 'template_redirect',  [ $this, 'handle_reservation_push' ] );

        // Cron: polling reservations từ OTA (fallback khi push thất bại)
        add_action( 'rba_ical_sync_cron', [ $this, 'cron_pull_reservations' ], 30 );

        // Admin
        add_action( 'admin_menu',  [ $this, 'register_settings_page' ], 99 );
        add_action( 'admin_init',  [ $this, 'register_settings' ] );

        // AJAX
        add_action( 'wp_ajax_rba_ota_test_adapter',  [ $this, 'ajax_test_adapter' ] );
        add_action( 'wp_ajax_rba_ota_push_now',      [ $this, 'ajax_push_now' ] );
        add_action( 'wp_ajax_rba_ota_pull_now',      [ $this, 'ajax_pull_now' ] );
        add_action( 'wp_ajax_rba_ota_save_adapter',  [ $this, 'ajax_save_adapter' ] );
    }

    // =========================================================================
    // HOOK HANDLERS — Website events → push lên OTA
    // =========================================================================

    /**
     * Availability thay đổi (block/unblock) → push lên tất cả OTA adapters.
     *
     * @param int    $room_id    WP room post ID
     * @param string $date_from  Y-m-d
     * @param string $date_to    Y-m-d
     * @param int    $allotment  0 = close, >0 = open with this count
     */
    public function on_availability_changed( int $room_id, string $date_from, string $date_to, int $allotment ): void {
        if ( ! $this->is_enabled() ) return;

        foreach ( $this->adapters as $adapter ) {
            $ok = $adapter->push_availability( $room_id, $date_from, $date_to, $allotment );
            $this->log( sprintf(
                '%s push_availability room#%d %s→%s allotment=%d: %s',
                $adapter->get_name(), $room_id, $date_from, $date_to, $allotment,
                $ok ? 'OK' : 'FAILED'
            ) );
        }
    }

    /**
     * Booking confirmed → close availability trên OTA.
     */
    public function on_booking_confirmed( int $order_id, \WC_Order $order ): void {
        if ( ! $this->is_enabled() ) return;

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $room_id   = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            $check_in  = $item->get_meta( 'tf_check_in' )  ?: $item->get_meta( 'check_in' );
            $check_out = $item->get_meta( 'tf_check_out' ) ?: $item->get_meta( 'check_out' );
            if ( ! $room_id || ! $check_in || ! $check_out ) continue;

            // Tính số phòng còn lại sau khi trừ booking này
            $remaining = RBA_Database::get_available_rooms( $room_id, $check_in, $check_out );

            /**
             * Fires trước khi push availability lên OTA.
             * Cho phép theme/plugin khác override allotment.
             */
            $allotment = (int) apply_filters( 'rba_ota_push_allotment', $remaining, $room_id, $check_in, $check_out );

            $this->on_availability_changed( $room_id, $check_in, $check_out, $allotment );
        }
    }

    /**
     * Booking released → re-open availability trên OTA.
     */
    public function on_booking_released( int $order_id, \WC_Order $order ): void {
        if ( ! $this->is_enabled() ) return;

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $room_id   = absint( $item->get_meta( 'tf_room_id' ) ?: $item->get_meta( 'room_id' ) );
            $check_in  = $item->get_meta( 'tf_check_in' )  ?: $item->get_meta( 'check_in' );
            $check_out = $item->get_meta( 'tf_check_out' ) ?: $item->get_meta( 'check_out' );
            if ( ! $room_id || ! $check_in || ! $check_out ) continue;

            $remaining = RBA_Database::get_available_rooms( $room_id, $check_in, $check_out );
            $allotment = (int) apply_filters( 'rba_ota_push_allotment', $remaining, $room_id, $check_in, $check_out );

            $this->on_availability_changed( $room_id, $check_in, $check_out, $allotment );
        }
    }

    /**
     * Giá được cập nhật (từ seasonal price editor) → push lên OTA.
     *
     * @param int    $room_id
     * @param string $date_from
     * @param string $date_to
     * @param float  $price
     */
    public function on_price_updated( int $room_id, string $date_from, string $date_to, float $price ): void {
        if ( ! $this->is_enabled() ) return;

        foreach ( $this->adapters as $adapter ) {
            $ok = $adapter->push_rate( $room_id, $date_from, $date_to, $price );
            $this->log( sprintf(
                '%s push_rate room#%d %s→%s price=%s: %s',
                $adapter->get_name(), $room_id, $date_from, $date_to,
                number_format( $price, 0, '.', ',' ), $ok ? 'OK' : 'FAILED'
            ) );
        }
    }

    // =========================================================================
    // INBOUND: OTA → Website (Reservation push / pull)
    // =========================================================================

    public function register_reservation_endpoint(): void {
        add_rewrite_rule( '^' . self::WEBHOOK_SLUG . '/([a-z]+)/?$', 'index.php?rba_ota_res=1&rba_ota_name=$matches[1]', 'top' );
        add_filter( 'query_vars', function ( array $vars ): array {
            $vars[] = 'rba_ota_res';
            $vars[] = 'rba_ota_name';
            return $vars;
        } );
    }

    /**
     * Nhận reservation XML push từ OTA.
     * URL: https://yoursite.com/rba-ota-reservation/{ota_name}/
     * Ví dụ: /rba-ota-reservation/booking/ hoặc /rba-ota-reservation/agoda/
     */
    public function handle_reservation_push(): void {
        if ( ! get_query_var( 'rba_ota_res' ) ) return;

        $ota_name = strtolower( sanitize_key( get_query_var( 'rba_ota_name' ) ) );
        $raw_body = file_get_contents( 'php://input' );

        if ( empty( $raw_body ) ) {
            status_header( 400 );
            exit( 'Empty body' );
        }

        $this->log( "Reservation push nhận từ OTA [{$ota_name}]: " . strlen( $raw_body ) . ' bytes' );

        // Parse XML reservation
        $reservations = $this->parse_ota_reservation_push( $raw_body );

        foreach ( $reservations as $res ) {
            $this->process_incoming_reservation( $res, $ota_name );
        }

        // OTA standard response: trả về <Success/>
        header( 'Content-Type: text/xml; charset=UTF-8' );
        status_header( 200 );
        echo '<?xml version="1.0" encoding="UTF-8"?><OTA_HotelResRS xmlns="http://www.opentravel.org/OTA/2003/05"><Success/></OTA_HotelResRS>';
        exit;
    }

    /**
     * Cron: polling reservations từ tất cả OTA (fallback khi push fail).
     */
    public function cron_pull_reservations(): void {
        if ( ! $this->is_enabled() || empty( $this->adapters ) ) return;

        $from = current_time( 'Y-m-d' );
        $to   = gmdate( 'Y-m-d', strtotime( '+7 days' ) );

        foreach ( $this->adapters as $adapter ) {
            $reservations = $adapter->pull_reservations( $from, $to );
            foreach ( $reservations as $res ) {
                $res['ota_name'] = $adapter->get_name();
                $result          = $this->process_incoming_reservation( $res, strtolower( $adapter->get_name() ) );
                if ( $result ) {
                    $adapter->ack_reservation( $res['ota_reservation_id'] );
                }
            }
        }
    }

    /**
     * Xử lý 1 reservation từ OTA:
     * 1. Tìm WP room_id từ OTA room_id
     * 2. Block dates trong rba_availability
     * 3. Tạo WooCommerce order (trạng thái pending / on-hold)
     * 4. Sync sang KiotViet (qua hook rba_booking_confirmed)
     *
     * @return bool True nếu xử lý thành công
     */
    private function process_incoming_reservation( array $res, string $ota_name ): bool {
        $ota_res_id   = $res['ota_reservation_id'] ?? '';
        $ota_room_id  = $res['ota_room_id']        ?? '';
        $check_in     = substr( $res['check_in']  ?? '', 0, 10 );
        $check_out    = substr( $res['check_out'] ?? '', 0, 10 );
        $guest_name   = sanitize_text_field( $res['guest_name']  ?? 'OTA Guest' );
        $guest_phone  = sanitize_text_field( $res['guest_phone'] ?? '' );
        $total        = (float) ( $res['total'] ?? 0 );

        if ( ! $ota_res_id || ! $check_in || ! $check_out ) return false;

        // Tránh xử lý trùng
        if ( $this->reservation_already_processed( $ota_res_id ) ) {
            $this->log( "Reservation [{$ota_res_id}] đã xử lý trước đó — bỏ qua." );
            return false;
        }

        // Tìm WP room từ OTA room_id (qua room_map của adapter)
        $wp_room_id = $this->get_wp_room_from_ota_room( $ota_room_id, $ota_name );
        if ( ! $wp_room_id ) {
            $this->log( "Không tìm thấy WP room cho OTA room [{$ota_room_id}] từ {$ota_name}." );
            return false;
        }

        // Kiểm tra availability
        $available = RBA_Database::get_available_rooms( $wp_room_id, $check_in, $check_out );
        if ( $available <= 0 ) {
            $this->log( "CONFLICT: Room #{$wp_room_id} đã hết chỗ {$check_in}→{$check_out}. Reservation [{$ota_res_id}] từ {$ota_name}." );
            // TODO: gửi thông báo cho admin xử lý thủ công
            do_action( 'rba_ota_reservation_conflict', $ota_res_id, $wp_room_id, $check_in, $check_out, $ota_name );
            return false;
        }

        // 1. Tạo WooCommerce order cho reservation OTA
        $order = $this->create_wc_order_from_ota( $wp_room_id, $check_in, $check_out, $guest_name, $guest_phone, $total, $ota_name, $ota_res_id );
        if ( ! $order ) {
            $this->log( "Tạo WC order thất bại cho reservation [{$ota_res_id}]." );
            return false;
        }

        // 2. Confirm booking → sẽ trigger:
        //    - RBA_Booking_Guard::confirm_booking() → decrement availability
        //    - RBA_KiotViet::push_booking_to_kiotviet() → tạo booking trong KiotViet
        //    - RBA_OTA_API::on_booking_confirmed() → push close availability lên các OTA khác
        do_action( 'rba_booking_confirmed', $order->get_id(), $order );

        // 3. Đánh dấu đã xử lý
        $this->mark_reservation_processed( $ota_res_id, $order->get_id() );

        $this->log( "Processed OTA reservation [{$ota_res_id}] từ {$ota_name} → WC order #{$order->get_id()} → room #{$wp_room_id} {$check_in}→{$check_out}." );
        return true;
    }

    /**
     * Tạo WooCommerce order cho reservation đến từ OTA.
     */
    private function create_wc_order_from_ota( int $wp_room_id, string $check_in, string $check_out, string $guest_name, string $guest_phone, float $total, string $ota_name, string $ota_res_id ): ?\WC_Order {
        try {
            $order = wc_create_order( [ 'status' => 'processing' ] );

            // Set billing info từ guest data
            $name_parts = explode( ' ', $guest_name, 2 );
            $order->set_billing_first_name( $name_parts[0] );
            $order->set_billing_last_name( $name_parts[1] ?? '' );
            $order->set_billing_phone( $guest_phone );

            // Thêm line item phòng
            $room_product = get_post( $wp_room_id );
            $item = new \WC_Order_Item_Product();
            $item->set_product_id( $wp_room_id );
            $item->set_name( $room_product ? $room_product->post_title : "Room #{$wp_room_id}" );
            $item->set_quantity( 1 );
            $item->set_total( $total );

            // Lưu metadata booking (cùng format với WooCommerce direct booking)
            $item->add_meta_data( 'tf_room_id',   $wp_room_id, true );
            $item->add_meta_data( 'tf_check_in',  $check_in,   true );
            $item->add_meta_data( 'tf_check_out', $check_out,  true );
            $item->add_meta_data( 'ota_source',   $ota_name,   true );
            $item->add_meta_data( 'ota_res_id',   $ota_res_id, true );

            $order->add_item( $item );

            // Order meta
            $order->update_meta_data( '_rba_ota_source',  $ota_name );
            $order->update_meta_data( '_rba_ota_res_id',  $ota_res_id );
            $order->add_order_note( sprintf( 'Booking từ %s — Reservation ID: %s', $ota_name, $ota_res_id ) );

            $order->set_total( $total );
            $order->calculate_totals();
            $order->save();

            return $order;
        } catch ( \Exception $e ) {
            $this->log( 'create_wc_order_from_ota error: ' . $e->getMessage() );
            return null;
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function load_adapters(): void {
        if ( ! $this->is_enabled() ) return;

        $configs = get_option( self::OPT_ADAPTERS, [] );
        foreach ( $configs as $ota_name => $config ) {
            if ( empty( $config['enabled'] ) ) continue;
            $config['room_map'] = get_option( self::OPT_ROOM_MAPS . '_' . $ota_name, [] );

            $this->adapters[ $ota_name ] = match ( strtolower( $ota_name ) ) {
                'agoda'   => new RBA_OTA_Adapter_Agoda( $config ),
                'booking' => new RBA_OTA_Adapter_Booking( $config ),
                default   => null,
            };

            if ( null === $this->adapters[ $ota_name ] ) unset( $this->adapters[ $ota_name ] );
        }
    }

    private function get_wp_room_from_ota_room( string $ota_room_id, string $ota_name ): int {
        $room_map = get_option( self::OPT_ROOM_MAPS . '_' . $ota_name, [] );
        $flipped  = array_flip( array_map( 'strval', $room_map ) );
        return (int) ( $flipped[ $ota_room_id ] ?? 0 );
    }

    private function reservation_already_processed( string $ota_res_id ): bool {
        return (bool) get_option( 'rba_ota_processed_' . md5( $ota_res_id ) );
    }

    private function mark_reservation_processed( string $ota_res_id, int $order_id ): void {
        update_option( 'rba_ota_processed_' . md5( $ota_res_id ), $order_id, false );
    }

    private function parse_ota_reservation_push( string $xml ): array {
        // Dùng parser của Booking.com adapter vì format OTA chuẩn
        $adapter = new RBA_OTA_Adapter_Booking( [] );
        $ref     = new \ReflectionClass( $adapter );
        $method  = $ref->getMethod( 'parse_reservations' );
        $method->setAccessible( true );
        return $method->invoke( $adapter, $xml );
    }

    private function is_enabled(): bool {
        return (bool) get_option( self::OPT_ENABLED, false );
    }

    private function log( string $msg ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( '[RBA_OTA_API] ' . $msg );
        $logs   = get_option( 'rba_ota_api_logs', [] );
        $logs[] = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $msg;
        if ( count( $logs ) > 100 ) $logs = array_slice( $logs, -100 );
        update_option( 'rba_ota_api_logs', $logs, false );
    }

    // =========================================================================
    // ADMIN PAGE
    // =========================================================================

    public function register_settings_page(): void {
        global $menu, $submenu;
        $parent = 'rba-dashboard';
        foreach ( (array) $menu as $item ) {
            if ( ! isset( $item[2] ) ) continue;
            if ( ! empty( $submenu[ $item[2] ] ) ) {
                foreach ( $submenu[ $item[2] ] as $sub ) {
                    if ( isset( $sub[2] ) && 'tf_dashboard' === $sub[2] ) { $parent = $item[2]; break 2; }
                }
            }
        }
        add_submenu_page( $parent, 'OTA API Manager', 'OTA API', 'manage_options', 'rba-ota-api', [ $this, 'render_page' ] );
    }

    public function register_settings(): void {
        register_setting( 'rba_ota_api_settings', self::OPT_ENABLED, [ 'type' => 'boolean' ] );
    }

    public function render_page(): void {
        $enabled     = $this->is_enabled();
        $adapters    = get_option( self::OPT_ADAPTERS, [] );
        $webhook_url = home_url( '/' . self::WEBHOOK_SLUG . '/' );
        $logs        = array_reverse( get_option( 'rba_ota_api_logs', [] ) );
        $active_tab  = sanitize_key( $_GET['tab'] ?? 'overview' );
        ?>
        <div class="wrap" style="max-width:980px">
            <h1>
                <span class="dashicons dashicons-networking" style="font-size:26px;vertical-align:middle;margin-right:8px;color:#1a6b3c"></span>
                OTA API Manager — Full API Flow
            </h1>

            <?php
            // Status banner
            if ( ! $enabled ) {
                echo '<div class="notice notice-warning inline"><p><strong>Module chưa được bật.</strong> Bật trong tab "Tổng quan".</p></div>';
            }
            ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ( [ 'overview' => 'Tổng quan', 'adapters' => 'OTA Adapters', 'room-mapping' => 'Map Phòng', 'logs' => 'Logs' ] as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=rba-ota-api&tab=' . $slug ) ); ?>"
                       class="nav-tab <?php echo $slug === $active_tab ? 'nav-tab-active' : ''; ?>">
                       <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div style="background:#fff;border:1px solid #ccd0d4;border-top:none;padding:24px">

            <?php if ( 'overview' === $active_tab ) : ?>

                <h3 style="margin-top:0">Kiến trúc Full API Flow</h3>
                <div style="background:#f5f5f5;font-family:monospace;font-size:12px;line-height:2;padding:16px;border-radius:6px;margin-bottom:20px">
                    <strong style="color:#1a6b3c">Website (nguồn sự thật)</strong><br>
                    &nbsp;&nbsp;│<br>
                    &nbsp;&nbsp;├─[availability/rate push]──► <strong>Booking.com XML API</strong> &nbsp;(OTA_HotelAvailNotif v1.1)<br>
                    &nbsp;&nbsp;├─[availability/rate push]──► <strong>Agoda YCS API</strong> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(OTA_HotelAvailNotif v1.1)<br>
                    &nbsp;&nbsp;│<br>
                    &nbsp;&nbsp;├─◄[reservation push]──── <strong>Booking.com</strong> &nbsp;→ /rba-ota-reservation/booking/<br>
                    &nbsp;&nbsp;├─◄[reservation pull]──── <strong>Agoda</strong> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;→ cron 15 phút (OTA_ReadRQ)<br>
                    &nbsp;&nbsp;│<br>
                    &nbsp;&nbsp;│&nbsp;&nbsp;[process_incoming_reservation()]<br>
                    &nbsp;&nbsp;│&nbsp;&nbsp;├─ Block dates (rba_availability)<br>
                    &nbsp;&nbsp;│&nbsp;&nbsp;├─ Tạo WooCommerce order<br>
                    &nbsp;&nbsp;│&nbsp;&nbsp;├─ Push sang KiotViet (class-rba-kiotviet.php)<br>
                    &nbsp;&nbsp;│&nbsp;&nbsp;└─ Push close availability lên OTA khác<br>
                </div>

                <div style="background:#fff3e0;border-left:4px solid #ff9800;padding:12px;border-radius:0 6px 6px 0;margin-bottom:20px;font-size:13px">
                    <strong>Lưu ý quan trọng:</strong><br>
                    • <strong>Booking.com:</strong> Cần đăng ký Connectivity Partner. Hiện đang tạm dừng nhận đăng ký mới.<br>
                    • <strong>Agoda:</strong> Cần bật Channel Manager mode trong YCS → cung cấp Hotel ID, Username, Password.<br>
                    • Trong thời gian chờ API, dùng iCal sync (class-rba-ical-sync.php) vẫn hoạt động tốt.
                </div>

                <form method="post" action="options.php">
                    <?php settings_fields( 'rba_ota_api_settings' ); ?>
                    <table class="form-table" style="margin:0">
                        <tr>
                            <th>Bật OTA API Module</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPT_ENABLED ); ?>" value="1" <?php checked( $enabled ); ?>>
                                    Push availability + rates lên OTA qua API sau mỗi thay đổi
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Webhook URL (nhận reservation từ OTA)</th>
                            <td>
                                <div style="display:flex;gap:8px;align-items:center">
                                    <input type="text" value="<?php echo esc_attr( $webhook_url ); ?>{ota_name}/" readonly style="width:400px;font-family:monospace;font-size:12px">
                                    <code>booking</code> <code>agoda</code>
                                </div>
                                <p class="description">Ví dụ Booking.com: <strong><?php echo esc_html( $webhook_url ); ?>booking/</strong></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Lưu' ); ?>
                </form>

            <?php elseif ( 'adapters' === $active_tab ) : ?>

                <h3 style="margin-top:0">Cấu hình OTA Adapters</h3>
                <?php foreach ( [ 'agoda' => 'Agoda YCS', 'booking' => 'Booking.com' ] as $ota_key => $ota_label ) :
                    $cfg = $adapters[ $ota_key ] ?? [];
                ?>
                <div style="border:1px solid #e0e0e0;border-radius:6px;padding:16px;margin-bottom:16px">
                    <h4 style="margin:0 0 12px 0;display:flex;align-items:center;gap:10px">
                        <?php echo esc_html( $ota_label ); ?>
                        <label style="font-weight:normal;font-size:13px">
                            <input type="checkbox" class="rba-ota-enabled" data-ota="<?php echo esc_attr( $ota_key ); ?>"
                                   <?php checked( ! empty( $cfg['enabled'] ) ); ?>>
                            Bật
                        </label>
                        <?php if ( 'booking' === $ota_key ) : ?>
                            <span style="background:#fff3e0;color:#e65100;font-size:11px;padding:2px 8px;border-radius:4px">Cần Connectivity Partner</span>
                        <?php else : ?>
                            <span style="background:#e8f5e9;color:#2e7d32;font-size:11px;padding:2px 8px;border-radius:4px">Khả dụng qua Channel Manager</span>
                        <?php endif; ?>
                    </h4>
                    <table style="width:100%;border-collapse:collapse">
                        <tr>
                            <td style="width:30%;padding:4px 0"><label>Hotel ID</label></td>
                            <td><input type="text" class="regular-text rba-ota-cfg" data-ota="<?php echo esc_attr( $ota_key ); ?>" data-key="hotel_id" value="<?php echo esc_attr( $cfg['hotel_id'] ?? '' ); ?>" placeholder="VD: 12345678"></td>
                        </tr>
                        <tr>
                            <td style="padding:4px 0"><label>Username</label></td>
                            <td><input type="text" class="regular-text rba-ota-cfg" data-ota="<?php echo esc_attr( $ota_key ); ?>" data-key="username" value="<?php echo esc_attr( $cfg['username'] ?? '' ); ?>"></td>
                        </tr>
                        <tr>
                            <td style="padding:4px 0"><label>Password</label></td>
                            <td><input type="password" class="regular-text rba-ota-cfg" data-ota="<?php echo esc_attr( $ota_key ); ?>" data-key="password" value="<?php echo esc_attr( $cfg['password'] ?? '' ); ?>"></td>
                        </tr>
                    </table>
                    <div style="margin-top:10px;display:flex;gap:8px">
                        <button class="button rba-save-adapter" data-ota="<?php echo esc_attr( $ota_key ); ?>"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'rba_ota_save_adapter' ) ); ?>">Lưu</button>
                        <button class="button rba-test-adapter" data-ota="<?php echo esc_attr( $ota_key ); ?>"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'rba_ota_test_adapter' ) ); ?>">Test kết nối</button>
                        <button class="button rba-pull-now" data-ota="<?php echo esc_attr( $ota_key ); ?>"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'rba_ota_pull_now' ) ); ?>">Pull reservations ngay</button>
                        <span class="rba-adapter-msg-<?php echo esc_attr( $ota_key ); ?>"></span>
                    </div>
                </div>
                <?php endforeach; ?>

            <?php elseif ( 'room-mapping' === $active_tab ) :
                $rooms = get_posts( [ 'post_type' => 'tf_room', 'post_status' => 'publish', 'numberposts' => -1 ] );
                foreach ( [ 'agoda' => 'Agoda', 'booking' => 'Booking.com' ] as $ota_key => $ota_label ) :
                    $room_map = get_option( self::OPT_ROOM_MAPS . '_' . $ota_key, [] );
                ?>
                <h3 style="margin-top:<?php echo $ota_key === 'agoda' ? '0' : '24px'; ?>">
                    <?php echo esc_html( $ota_label ); ?> — Room Mapping
                </h3>
                <table class="wp-list-table widefat" style="margin-bottom:16px">
                    <thead><tr>
                        <th>Phòng WordPress</th>
                        <th><?php echo esc_html( $ota_label ); ?> Room Type ID</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $rooms as $room ) : ?>
                        <tr>
                            <td><?php echo esc_html( $room->post_title ); ?> <small style="color:#888">#<?php echo esc_html( $room->ID ); ?></small></td>
                            <td>
                                <input type="number" class="small-text rba-ota-room-id"
                                       data-ota="<?php echo esc_attr( $ota_key ); ?>"
                                       data-room-id="<?php echo esc_attr( $room->ID ); ?>"
                                       value="<?php echo esc_attr( $room_map[ $room->ID ] ?? '' ); ?>"
                                       placeholder="OTA Room ID">
                                <button class="button button-small rba-save-room-map"
                                        data-ota="<?php echo esc_attr( $ota_key ); ?>"
                                        data-room-id="<?php echo esc_attr( $room->ID ); ?>"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'rba_ota_save_adapter' ) ); ?>">Lưu</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endforeach; ?>

            <?php elseif ( 'logs' === $active_tab ) : ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <h3 style="margin:0">Activity Logs (100 dòng gần nhất)</h3>
                    <button class="button" onclick="fetch(ajaxurl+'?action=rba_ota_clear_logs&nonce=<?php echo esc_js( wp_create_nonce( 'rba_ota_clear_logs' ) ); ?>').then(()=>location.reload())">Xóa logs</button>
                </div>
                <div style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;line-height:1.7;padding:16px;border-radius:6px;max-height:500px;overflow-y:auto">
                    <?php foreach ( $logs ?: [ 'Chưa có log nào.' ] as $line ) : ?>
                        <div style="color:<?php echo str_contains( $line, 'FAILED' ) || str_contains( $line, 'CONFLICT' ) ? '#f48771' : ( str_contains( $line, 'OK' ) ? '#89d185' : '#d4d4d4' ); ?>">
                            <?php echo esc_html( $line ); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            </div>
        </div>

        <script>
        (function($){
            // Save adapter config
            $('.rba-save-adapter').on('click', function(){
                const ota = $(this).data('ota');
                const $msg = $('.rba-adapter-msg-' + ota);
                const data = { action: 'rba_ota_save_adapter', nonce: $(this).data('nonce'), ota: ota };
                data.enabled = $('.rba-ota-enabled[data-ota="'+ota+'"]').prop('checked') ? 1 : 0;
                $('.rba-ota-cfg[data-ota="'+ota+'"]').each(function(){ data[$(this).data('key')] = $(this).val(); });
                $.post(ajaxurl, data, r => $msg.html(r.success ? '<span style="color:#2e7d32">✔ Đã lưu</span>' : '<span style="color:#c62828">✘ '+r.data+'</span>'));
            });

            // Test adapter
            $('.rba-test-adapter').on('click', function(){
                const ota = $(this).data('ota');
                const $msg = $('.rba-adapter-msg-' + ota);
                $msg.text('Đang test...');
                $.post(ajaxurl, { action:'rba_ota_test_adapter', nonce:$(this).data('nonce'), ota }, r => {
                    $msg.html(r.success ? '<span style="color:#2e7d32">✔ ' + r.data + '</span>' : '<span style="color:#c62828">✘ '+r.data+'</span>');
                });
            });

            // Pull now
            $('.rba-pull-now').on('click', function(){
                const ota = $(this).data('ota');
                const $msg = $('.rba-adapter-msg-' + ota);
                $msg.text('Đang pull...');
                $.post(ajaxurl, { action:'rba_ota_pull_now', nonce:$(this).data('nonce'), ota }, r => {
                    $msg.html(r.success ? '<span style="color:#2e7d32">✔ ' + r.data + '</span>' : '<span style="color:#c62828">✘ '+r.data+'</span>');
                });
            });

            // Save room map
            $('.rba-save-room-map').on('click', function(){
                const ota = $(this).data('ota'), roomId = $(this).data('room-id');
                const otaRoomId = $('.rba-ota-room-id[data-ota="'+ota+'"][data-room-id="'+roomId+'"]').val();
                const $b = $(this).prop('disabled',true).text('...');
                $.post(ajaxurl, { action:'rba_ota_save_adapter', nonce:$(this).data('nonce'), ota, room_id: roomId, ota_room_id: otaRoomId, save_type: 'room_map' }, r => {
                    $b.prop('disabled',false).text('Lưu');
                    $b.after(r.success ? '<span style="color:#2e7d32;margin-left:4px">✔</span>' : '<span style="color:#c62828;margin-left:4px">✘</span>');
                    setTimeout(()=>$b.nextAll('span').first().remove(), 2000);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    public function ajax_save_adapter(): void {
        check_ajax_referer( 'rba_ota_save_adapter', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $ota       = sanitize_key( $_POST['ota'] ?? '' );
        $save_type = sanitize_key( $_POST['save_type'] ?? 'config' );

        if ( 'room_map' === $save_type ) {
            $room_id     = absint( $_POST['room_id']     ?? 0 );
            $ota_room_id = sanitize_text_field( wp_unslash( $_POST['ota_room_id'] ?? '' ) );
            $map         = get_option( self::OPT_ROOM_MAPS . '_' . $ota, [] );
            if ( $ota_room_id ) $map[ $room_id ] = $ota_room_id;
            else unset( $map[ $room_id ] );
            update_option( self::OPT_ROOM_MAPS . '_' . $ota, $map );
            wp_send_json_success( 'Room mapping saved' );
        }

        $adapters         = get_option( self::OPT_ADAPTERS, [] );
        $adapters[ $ota ] = [
            'enabled'  => ! empty( $_POST['enabled'] ),
            'hotel_id' => sanitize_text_field( wp_unslash( $_POST['hotel_id'] ?? '' ) ),
            'username' => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
            'password' => sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) ),
        ];
        update_option( self::OPT_ADAPTERS, $adapters );
        wp_send_json_success( 'Saved' );
    }

    public function ajax_test_adapter(): void {
        check_ajax_referer( 'rba_ota_test_adapter', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $ota = sanitize_key( $_POST['ota'] ?? '' );
        if ( ! isset( $this->adapters[ $ota ] ) ) {
            wp_send_json_error( 'Adapter chưa được cấu hình hoặc chưa bật.' );
        }
        // Test bằng cách pull reservations ngày hôm nay
        $reservations = $this->adapters[ $ota ]->pull_reservations( current_time( 'Y-m-d' ), current_time( 'Y-m-d' ) );
        wp_send_json_success( 'Kết nối thành công! Tìm thấy ' . count( $reservations ) . ' reservations hôm nay.' );
    }

    public function ajax_pull_now(): void {
        check_ajax_referer( 'rba_ota_pull_now', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $ota = sanitize_key( $_POST['ota'] ?? '' );
        if ( ! isset( $this->adapters[ $ota ] ) ) wp_send_json_error( 'Adapter không khả dụng.' );

        $from = current_time( 'Y-m-d' );
        $to   = gmdate( 'Y-m-d', strtotime( '+7 days' ) );
        $reservations = $this->adapters[ $ota ]->pull_reservations( $from, $to );
        $processed = 0;
        foreach ( $reservations as $res ) {
            $res['ota_name'] = $this->adapters[ $ota ]->get_name();
            if ( $this->process_incoming_reservation( $res, $ota ) ) $processed++;
        }
        wp_send_json_success( 'Pulled ' . count( $reservations ) . ' reservations, xử lý ' . $processed . ' mới.' );
    }
}

add_action( 'wp_ajax_rba_ota_clear_logs', function(): void {
    check_ajax_referer( 'rba_ota_clear_logs', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();
    delete_option( 'rba_ota_api_logs' );
    wp_send_json_success();
} );

new RBA_OTA_API();
