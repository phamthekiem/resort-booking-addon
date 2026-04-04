<?php
/**
 * RBA_Debug — Công cụ chẩn đoán và kiểm tra tích hợp
 *
 * Chỉ active khi: WP_DEBUG = true HOẶC user là admin và có ?rba_debug=1 trong URL
 * Tự động ẩn trên production khi WP_DEBUG = false.
 *
 * Chức năng:
 *  1. Scan Tourfic source → tìm tất cả apply_filters() và do_action() thực tế
 *  2. Kiểm tra hook nào plugin đang listen nhưng Tourfic không fire
 *  3. Kiểm tra DB tables tồn tại và có data không
 *  4. Test giá theo mùa cho 1 phòng cụ thể
 *  5. Hiện toàn bộ meta của phòng (để debug ACF sync)
 *
 * @package ResortBookingAddon
 * @since   1.4.3
 */
defined( 'ABSPATH' ) || exit;

class RBA_Debug {

    public function __construct() {
        if ( ! $this->should_run() ) return;

        add_action( 'admin_menu',              [ $this, 'register_page' ], 99 );
        add_action( 'wp_ajax_rba_debug_scan',  [ $this, 'ajax_scan_tourfic_hooks' ] );
        add_action( 'wp_ajax_rba_debug_price', [ $this, 'ajax_test_price' ] );
        add_action( 'wp_ajax_rba_debug_meta',  [ $this, 'ajax_get_room_meta' ] );
        add_action( 'wp_ajax_rba_debug_fire',  [ $this, 'ajax_fire_test_hook' ] );
        add_action( 'wp_ajax_rba_debug_wc_products', [ $this, 'ajax_debug_wc_products' ] );

        // Thêm admin bar notice khi debug mode
        add_action( 'admin_bar_menu', [ $this, 'add_debug_bar_item' ], 999 );
    }

    private function should_run(): bool {
        if ( ! is_admin() && ! ( defined('WP_DEBUG') && WP_DEBUG ) ) return false;
        if ( ! current_user_can( 'manage_options' ) ) return false;
        return true;
    }

    public function add_debug_bar_item( \WP_Admin_Bar $bar ): void {
        $bar->add_node( [
            'id'     => 'rba-debug',
            'title'  => '🔍 RBA Debug',
            'href'   => admin_url( 'admin.php?page=rba-debug' ),
            'meta'   => [ 'title' => 'Resort Booking Addon Debug Tool' ],
        ] );
    }

    public function register_page(): void {
        $tf_slug = $this->detect_tourfic_slug();
        $parent  = $tf_slug ?: 'rba-dashboard';
        add_submenu_page(
            $parent,
            'RBA Debug Tool',
            '🔍 Debug Tool',
            'manage_options',
            'rba-debug',
            [ $this, 'render_page' ]
        );
    }

    private function detect_tourfic_slug(): string {
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

    public function render_page(): void {
        $rooms = get_posts( [ 'post_type' => 'tf_room', 'post_status' => 'publish', 'numberposts' => 10 ] );
        $nonce = wp_create_nonce( 'rba_debug_nonce' );
        ?>
        <div class="wrap" style="max-width:1000px">
            <h1>🔍 RBA Debug Tool</h1>
            <p style="background:#fff3cd;border:1px solid #ffc107;padding:10px;border-radius:4px">
                <strong>⚠ Tool này chỉ dùng để debug.</strong>
                Tắt WP_DEBUG khi không dùng để ẩn trang này.
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

                <!-- PANEL 1: Scan Tourfic Hooks -->
                <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px">
                    <h3 style="margin-top:0">1. Tourfic Hooks thực tế</h3>
                    <p style="font-size:13px;color:#555">Scan source Tourfic → tìm tất cả <code>apply_filters</code> và <code>do_action</code> liên quan đến rooms/price/limit.</p>
                    <button class="button button-primary" id="btn-scan-hooks" data-nonce="<?php echo esc_attr($nonce); ?>">
                        Scan Tourfic Source
                    </button>
                    <div id="result-hooks" style="margin-top:12px;font-family:monospace;font-size:12px;max-height:400px;overflow-y:auto;background:#f5f5f5;padding:10px;border-radius:4px;display:none"></div>
                </div>

                <!-- PANEL 2: Hooks plugin đang listen -->
                <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px">
                    <h3 style="margin-top:0">2. Hooks plugin đang dùng</h3>
                    <p style="font-size:13px;color:#555">Danh sách hooks plugin đang listen — so sánh với Scan kết quả để tìm mismatch.</p>
                    <?php $this->render_plugin_hooks(); ?>
                </div>

                <!-- PANEL 3: Test giá -->
                <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px">
                    <h3 style="margin-top:0">3. Test giá theo mùa</h3>
                    <select id="debug-room-id" style="width:100%;margin-bottom:8px">
                        <option value="">-- Chọn phòng --</option>
                        <?php foreach ( $rooms as $r ) : ?>
                            <option value="<?php echo esc_attr($r->ID); ?>"><?php echo esc_html($r->post_title); ?> (#<?php echo esc_html($r->ID); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex;gap:8px;margin-bottom:8px">
                        <input type="date" id="debug-checkin" class="regular-text" style="flex:1" placeholder="Check-in">
                        <input type="date" id="debug-checkout" class="regular-text" style="flex:1" placeholder="Check-out">
                    </div>
                    <button class="button button-primary" id="btn-test-price" data-nonce="<?php echo esc_attr($nonce); ?>">
                        Tính giá
                    </button>
                    <div id="result-price" style="margin-top:12px;font-family:monospace;font-size:12px;background:#f5f5f5;padding:10px;border-radius:4px;display:none"></div>
                </div>

                <!-- PANEL 4: Room meta -->
                <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px">
                    <h3 style="margin-top:0">4. Room meta & ACF fields</h3>
                    <select id="debug-room-meta" style="width:100%;margin-bottom:8px">
                        <option value="">-- Chọn phòng --</option>
                        <?php foreach ( $rooms as $r ) : ?>
                            <option value="<?php echo esc_attr($r->ID); ?>"><?php echo esc_html($r->post_title); ?> (#<?php echo esc_html($r->ID); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary" id="btn-get-meta" data-nonce="<?php echo esc_attr($nonce); ?>">
                        Xem Meta
                    </button>
                    <div id="result-meta" style="margin-top:12px;font-family:monospace;font-size:12px;max-height:300px;overflow-y:auto;background:#f5f5f5;padding:10px;border-radius:4px;display:none"></div>
                </div>

            </div>

            <!-- PANEL 5: DB Status -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin-top:20px">
                <h3 style="margin-top:0">5. Database Tables Status</h3>
                <?php $this->render_db_status(); ?>
            </div>

            <!-- PANEL 6b: WC Product Mapping (NEW) -->
            <div style="background:#fff3e0;border:1px solid #ffb74d;border-radius:6px;padding:16px;margin-top:20px">
                <h3 style="margin-top:0;color:#e65100">6b. WC Product Mapping — Debug lỗi "chưa liên kết WooCommerce"</h3>
                <p style="font-size:13px;color:#555;margin-bottom:12px">
                    Tourfic Free dùng cơ chế riêng để liên kết phòng với WooCommerce.
                    Tool này quét tất cả WC products và meta Tourfic để tìm ra product đúng.
                </p>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <select id="rba-wc-room-select" style="flex:1;max-width:300px">
                        <option value="">-- Chọn phòng để kiểm tra --</option>
                        <?php foreach($rooms as $room): ?>
                        <option value="<?php echo esc_attr($room->ID); ?>">
                            <?php echo esc_html($room->post_title); ?> (#<?php echo esc_attr($room->ID); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary" id="btn-wc-products"
                            data-nonce="<?php echo esc_attr($nonce); ?>">
                        Quét WC Products
                    </button>
                </div>
                <div id="result-wc-products" style="margin-top:14px;display:none">
                    <div id="result-room-info" style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;padding:12px;margin-bottom:10px;font-family:monospace;font-size:12px;line-height:1.8;display:none"></div>
                    <div style="font-weight:600;font-size:13px;margin-bottom:8px">Tất cả WC Products tìm thấy:</div>
                    <table class="wp-list-table widefat" style="font-size:12px" id="wc-products-table">
                        <thead><tr>
                            <th style="width:50px">ID</th>
                            <th style="width:200px">Tên</th>
                            <th style="width:120px">Slug</th>
                            <th style="width:80px">Status</th>
                            <th>Meta Tourfic liên quan</th>
                        </tr></thead>
                        <tbody id="wc-products-body"></tbody>
                    </table>
                    <div style="margin-top:12px;background:#e3f2fd;border:1px solid #90caf9;border-radius:6px;padding:12px;font-size:13px">
                        <strong>Cách fix thủ công:</strong><br>
                        1. Xem bảng trên → tìm product nào liên quan đến phòng/hotel<br>
                        2. Vào phòng đó trong WP Admin → <strong>thêm custom field:</strong>
                        <code>_tf_wc_product_id</code> = <em>ID của product tìm thấy</em><br>
                        3. Save → Thử đặt phòng lại
                    </div>
                </div>
            </div>

            <!-- PANEL 6: Test fire hook -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin-top:20px">
                <h3 style="margin-top:0">6. Fire test hook</h3>
                <p style="font-size:13px;color:#555">Thủ công fire 1 filter và xem kết quả — kiểm tra hook có hoạt động không.</p>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="text" id="debug-hook-name" class="regular-text" placeholder="tf_hotel_room_price" style="flex:1">
                    <input type="text" id="debug-hook-args" class="regular-text" placeholder='{"room_id":123}' style="flex:1">
                    <button class="button" id="btn-fire-hook" data-nonce="<?php echo esc_attr($nonce); ?>">Fire filter</button>
                </div>
                <div id="result-hook" style="margin-top:12px;font-family:monospace;font-size:12px;background:#f5f5f5;padding:10px;border-radius:4px;display:none"></div>
            </div>
        </div>

        <script>
        (function($){
            const nonce = '<?php echo esc_js($nonce); ?>';

            $('#btn-scan-hooks').on('click', function(){
                $(this).prop('disabled',true).text('Scanning...');
                $('#result-hooks').show().html('<em>Đang scan...</em>');
                $.post(ajaxurl, { action:'rba_debug_scan', nonce }, function(r){
                    $('#btn-scan-hooks').prop('disabled',false).text('Scan Tourfic Source');
                    if(r.success){
                        let html = '';
                        r.data.forEach(h => {
                            const matched = h.matched ? '✅' : '❌';
                            const color = h.matched ? '#2e7d32' : (h.type === 'filter' ? '#1565c0' : '#333');
                            html += `<div style="color:${color};padding:2px 0">${matched} [${h.type}] <strong>${h.name}</strong> — ${h.file}:${h.line}</div>`;
                        });
                        $('#result-hooks').html(html || '<em>Không tìm thấy hook nào</em>');
                    } else {
                        $('#result-hooks').html('<span style="color:red">Lỗi: ' + r.data + '</span>');
                    }
                });
            });

            $('#btn-test-price').on('click', function(){
                const room_id = $('#debug-room-id').val();
                const checkin = $('#debug-checkin').val();
                const checkout = $('#debug-checkout').val();
                if(!room_id || !checkin || !checkout){ alert('Chọn phòng và nhập ngày'); return; }
                $(this).prop('disabled',true).text('...');
                $.post(ajaxurl, { action:'rba_debug_price', nonce, room_id, checkin, checkout }, function(r){
                    $('#btn-test-price').prop('disabled',false).text('Tính giá');
                    $('#result-price').show().html(r.success ? r.data : '<span style="color:red">'+r.data+'</span>');
                });
            });

            $('#btn-get-meta').on('click', function(){
                const room_id = $('#debug-room-meta').val();
                if(!room_id){ alert('Chọn phòng'); return; }
                $(this).prop('disabled',true).text('...');
                $.post(ajaxurl, { action:'rba_debug_meta', nonce, room_id }, function(r){
                    $('#btn-get-meta').prop('disabled',false).text('Xem Meta');
                    $('#result-meta').show().html(r.success ? r.data : '<span style="color:red">'+r.data+'</span>');
                });
            });

            $('#btn-fire-hook').on('click', function(){
                const hook = $('#debug-hook-name').val();
                if(!hook){ alert('Nhập hook name'); return; }
                $.post(ajaxurl, { action:'rba_debug_fire', nonce, hook_name: hook, hook_args: $('#debug-hook-args').val() }, function(r){
                    $('#result-hook').show().html(r.success ? r.data : '<span style="color:red">'+r.data+'</span>');
                });
            });

        // WC Products debug
        $('#btn-wc-products').on('click', function(){
            const $btn = $(this).prop('disabled',true).text('Đang quét...');
            const room_id = $('#rba-wc-room-select').val() || 0;
            $('#result-wc-products').show();
            $.post(ajaxurl, {
                action: 'rba_debug_wc_products',
                nonce: $(this).data('nonce'),
                room_id: room_id,
            }, function(r){
                $btn.prop('disabled',false).text('Quét WC Products');
                if(!r.success){ alert('Lỗi: '+r.data); return; }
                const d = r.data;

                // Room info
                if(d.room_info && d.room_info.length){
                    const $ri = $('#result-room-info').show();
                    $ri.html('<strong>Room #'+room_id+' meta & kết quả:</strong><br>'+
                        d.room_info.map(l => '<span style="color:'+(l.includes('KHÔNG TÌM THẤY')?'#c62828':(l.includes('→')?'#1a6b3c':'#333'))+'">'+$('<span>').text(l).html()+'</span>').join('<br>'));
                }

                // Products table
                const $tbody = $('#wc-products-body').empty();
                if(!d.products.length){
                    $tbody.append('<tr><td colspan="5" style="text-align:center;color:#888">Không có WC product nào</td></tr>');
                } else {
                    d.products.forEach(p => {
                        const hasTF = p.meta.some(m => m.includes('tf_'));
                        $tbody.append(
                            '<tr style="background:'+(hasTF?'#fff8e1':'inherit')+'">'+
                            '<td><strong>'+p.id+'</strong></td>'+
                            '<td>'+$('<span>').text(p.title).html()+'</td>'+
                            '<td><code>'+p.slug+'</code></td>'+
                            '<td><span style="color:'+(p.status==='publish'?'#2e7d32':'#888')+'">'+p.status+'</span></td>'+
                            '<td style="font-family:monospace;font-size:11px;line-height:1.7">'+(p.meta.length ? p.meta.map(m=>$('<span>').text(m).html()).join('<br>') : '<em style="color:#aaa">–</em>')+'</td>'+
                            '</tr>'
                        );
                    });
                }
            });
        });

        })(jQuery);
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RENDER HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function render_plugin_hooks(): void {
        $hooks = $this->get_plugin_registered_hooks();
        echo '<table style="width:100%;font-size:12px;border-collapse:collapse">';
        echo '<tr style="background:#f0f0f0"><th style="text-align:left;padding:4px">Hook</th><th style="text-align:left;padding:4px">Type</th><th style="text-align:left;padding:4px">Module</th></tr>';
        foreach ( $hooks as $hook ) {
            $color = str_starts_with( $hook['name'], 'tf_' ) ? '#1565c0' : '#333';
            echo '<tr style="border-bottom:1px solid #eee">';
            echo '<td style="padding:4px;font-family:monospace;color:' . esc_attr($color) . '">' . esc_html($hook['name']) . '</td>';
            echo '<td style="padding:4px">' . esc_html($hook['type']) . '</td>';
            echo '<td style="padding:4px;color:#888">' . esc_html($hook['module']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    private function get_plugin_registered_hooks(): array {
        return [
            // seasonal-price
            [ 'name' => 'tf_hotel_room_price',     'type' => 'filter', 'module' => 'seasonal-price' ],
            [ 'name' => 'tf_room_price_per_night', 'type' => 'filter', 'module' => 'seasonal-price' ],
            [ 'name' => 'tf_booking_total_price',  'type' => 'filter', 'module' => 'seasonal-price' ],
            // room-unlock
            [ 'name' => 'tf_hotel_room_limit',     'type' => 'filter', 'module' => 'room-unlock' ],
            [ 'name' => 'tf_room_limit_reached',   'type' => 'filter', 'module' => 'room-unlock' ],
            [ 'name' => 'tf_free_room_limit',      'type' => 'filter', 'module' => 'room-unlock' ],
            [ 'name' => 'tf_hotel_max_rooms',      'type' => 'filter', 'module' => 'room-unlock' ],
            [ 'name' => 'tf_api_room_limit_check', 'type' => 'filter', 'module' => 'room-unlock' ],
            // booking-guard
            [ 'name' => 'woocommerce_add_to_cart_validation', 'type' => 'filter', 'module' => 'booking-guard' ],
            [ 'name' => 'woocommerce_payment_complete',       'type' => 'action', 'module' => 'booking-guard' ],
            // acf-bridge
            [ 'name' => 'acf/save_post',           'type' => 'action', 'module' => 'acf-bridge' ],
            [ 'name' => 'tf_booking_email_data',   'type' => 'filter', 'module' => 'acf-bridge' ],
        ];
    }

    private function render_db_status(): void {
        global $wpdb;
        $tables = [
            'rba_availability'    => 'Availability (inventory phòng theo ngày)',
            'rba_seasonal_prices' => 'Seasonal Prices (giá theo mùa)',
            'rba_date_prices'     => 'Date Prices (giá ngày cụ thể)',
            'rba_ical_sources'    => 'iCal Sources (OTA feeds)',
            'rba_ical_events'     => 'iCal Events (events đã sync)',
            'rba_booking_locks'   => 'Booking Locks (pessimistic lock)',
            'rba_tour_bookings'   => 'Tour Bookings (tour slots)',
        ];

        echo '<table class="wp-list-table widefat" style="font-size:13px">';
        echo '<thead><tr><th>Table</th><th>Tồn tại</th><th>Số rows</th><th>Mô tả</th></tr></thead><tbody>';
        foreach ( $tables as $table => $desc ) {
            $full      = $wpdb->prefix . $table;
            $exists    = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$full}'" );
            $count     = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$full}" ) : 0;
            $icon      = $exists ? '<span style="color:#2e7d32">✔ Có</span>' : '<span style="color:#c62828">✘ Không</span>';
            echo "<tr><td><code>{$full}</code></td><td>{$icon}</td><td>" . ( $exists ? $count : '—' ) . "</td><td style='color:#666'>{$desc}</td></tr>";
        }
        echo '</tbody></table>';

        // Quick actions
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rba_availability'" ) ) {
            echo '<div style="margin-top:12px;background:#fce4ec;border:1px solid #e91e63;padding:10px;border-radius:4px">';
            echo '<strong>⚠ Bảng chưa được tạo!</strong> Deactivate và Activate lại plugin để chạy lại activation hook.';
            echo '</div>';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX HANDLERS
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_scan_tourfic_hooks(): void {
        check_ajax_referer( 'rba_debug_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $tf_dir  = WP_PLUGIN_DIR . '/tourfic';
        if ( ! is_dir( $tf_dir ) ) {
            wp_send_json_error( 'Không tìm thấy thư mục Tourfic tại: ' . $tf_dir );
        }

        $hooks         = [];
        $plugin_hooks  = array_column( $this->get_plugin_registered_hooks(), 'name' );
        $keywords      = [ 'room', 'hotel', 'price', 'limit', 'booking', 'tf_' ];

        // Scan PHP files
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $tf_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( $file->getExtension() !== 'php' ) continue;
            if ( str_contains( $file->getPathname(), '/vendor/' ) ) continue;

            $content = file_get_contents( $file->getPathname() );
            $lines   = explode( "\n", $content );
            $rel_path = str_replace( $tf_dir . '/', '', $file->getPathname() );

            foreach ( $lines as $i => $line ) {
                // Match apply_filters( 'xxx' ) và do_action( 'xxx' )
                if ( ! preg_match( "/(?:apply_filters|do_action)\s*\(\s*'([^']+)'/", $line, $m ) ) continue;

                $hook_name = $m[1];
                $is_tf     = str_starts_with( $hook_name, 'tf_' );
                $is_keyword = false;
                foreach ( $keywords as $kw ) {
                    if ( str_contains( strtolower( $hook_name ), $kw ) ) { $is_keyword = true; break; }
                }

                if ( ! $is_tf && ! $is_keyword ) continue;

                $type    = str_contains( $line, 'apply_filters' ) ? 'filter' : 'action';
                $matched = in_array( $hook_name, $plugin_hooks, true );

                $hooks[] = [
                    'name'    => $hook_name,
                    'type'    => $type,
                    'file'    => $rel_path,
                    'line'    => $i + 1,
                    'matched' => $matched,
                ];
            }
        }

        // Sort: unmatched tf_ hooks first (những hook Tourfic có nhưng plugin chưa listen)
        usort( $hooks, fn($a,$b) => ($b['matched'] ? 0 : 1) - ($a['matched'] ? 0 : 1) ?: strcmp($a['name'], $b['name']) );

        // Deduplicate by name (giữ lần xuất hiện đầu)
        $seen   = [];
        $unique = [];
        foreach ( $hooks as $h ) {
            if ( ! isset( $seen[ $h['name'] ] ) ) {
                $seen[ $h['name'] ] = true;
                $unique[] = $h;
            }
        }

        wp_send_json_success( $unique );
    }

    public function ajax_test_price(): void {
        check_ajax_referer( 'rba_debug_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $room_id  = absint( $_POST['room_id'] );
        $check_in = sanitize_text_field( wp_unslash( $_POST['checkin'] ?? '' ) );
        $check_out= sanitize_text_field( wp_unslash( $_POST['checkout'] ?? '' ) );

        if ( ! $room_id || ! $check_in || ! $check_out ) {
            wp_send_json_error( 'Thiếu tham số' );
        }

        global $wpdb;

        // Lấy base price
        $base_price = (float) get_post_meta( $room_id, '_tf_price', true );
        $acf_price  = function_exists('get_field') ? (float) get_field( 'room_base_price', $room_id ) : 0;

        // Tính giá theo từng ngày
        $date   = new DateTime( $check_in );
        $end    = new DateTime( $check_out );
        $days   = [];
        $total  = 0;

        while ( $date < $end ) {
            $d      = $date->format('Y-m-d');
            $price  = RBA_Seasonal_Price::get_price_for_date( $room_id, $d );
            $days[] = "{$d}: " . number_format( $price, 0, ',', '.' ) . ' VNĐ';
            $total += $price;
            $date->modify('+1 day');
        }

        // Kiểm tra seasonal prices trong DB
        $seasons = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rba_seasonal_prices WHERE room_id = %d ORDER BY priority, date_from",
            $room_id
        ) );

        $html = "<strong>Room #{$room_id} — " . get_the_title($room_id) . "</strong>\n\n";
        $html .= "Base price (_tf_price meta): " . number_format($base_price,0,',','.') . " VNĐ\n";
        $html .= "ACF room_base_price: " . number_format($acf_price,0,',','.') . " VNĐ\n\n";
        $html .= "Seasonal prices trong DB: " . count($seasons) . " mùa\n";
        foreach ( $seasons as $s ) {
            $html .= "  [{$s->priority}] {$s->date_from} → {$s->date_to}: {$s->price_type} = {$s->price_value}\n";
        }
        $html .= "\n--- Giá từng ngày ---\n";
        $html .= implode("\n", $days);
        $html .= "\n\n<strong>TỔNG: " . number_format($total,0,',','.') . " VNĐ (" . count($days) . " đêm)</strong>";

        wp_send_json_success( nl2br( esc_html( $html ) ) );
    }

    public function ajax_get_room_meta(): void {
        check_ajax_referer( 'rba_debug_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $room_id = absint( $_POST['room_id'] );
        $meta    = get_post_meta( $room_id );

        $html = "<strong>" . get_the_title($room_id) . " (#{$room_id})</strong>\n\n";
        $html .= "Post type: " . get_post_type($room_id) . "\n\n";
        $html .= "--- Post Meta (tất cả keys) ---\n";

        // Hiện tf_ meta trước
        $tf_keys  = array_filter( array_keys($meta), fn($k) => str_starts_with($k,'_tf') || str_starts_with($k,'tf_') );
        $rba_keys = array_filter( array_keys($meta), fn($k) => str_starts_with($k,'_rba') || str_starts_with($k,'rba_') );
        $acf_keys = array_filter( array_keys($meta), fn($k) => str_starts_with($k,'room_') || str_starts_with($k,'resort_') );

        foreach ( [ 'Tourfic (_tf_*)' => $tf_keys, 'Plugin (_rba_*)' => $rba_keys, 'ACF (room_*)' => $acf_keys ] as $group => $keys ) {
            $html .= "\n[{$group}]\n";
            foreach ( $keys as $key ) {
                $val = maybe_unserialize( $meta[$key][0] ?? '' );
                if ( is_array($val) ) $val = json_encode($val);
                $html .= "  {$key}: " . mb_strimwidth( (string)$val, 0, 120, '...' ) . "\n";
            }
        }

        // ACF fields riêng
        if ( function_exists('get_fields') ) {
            $acf_data = get_fields( $room_id );
            if ( $acf_data ) {
                $html .= "\n--- ACF get_fields() ---\n";
                foreach ( $acf_data as $k => $v ) {
                    if ( is_array($v) ) $v = json_encode($v);
                    $html .= "  {$k}: " . mb_strimwidth( (string)$v, 0, 120, '...' ) . "\n";
                }
            }
        }

        wp_send_json_success( '<pre style="margin:0;white-space:pre-wrap">' . esc_html($html) . '</pre>' );
    }

    public function ajax_fire_test_hook(): void {
        check_ajax_referer( 'rba_debug_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $hook_name = sanitize_text_field( wp_unslash( $_POST['hook_name'] ?? '' ) );
        $hook_args = json_decode( sanitize_text_field( wp_unslash( $_POST['hook_args'] ?? '{}' ) ), true ) ?: [];

        if ( ! $hook_name ) wp_send_json_error( 'Thiếu hook name' );

        global $wp_filter;
        $registered_callbacks = [];
        if ( isset( $wp_filter[$hook_name] ) ) {
            foreach ( $wp_filter[$hook_name]->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $cb ) {
                    $fn = $cb['function'];
                    if ( is_array($fn) ) {
                        $fn = ( is_object($fn[0]) ? get_class($fn[0]) : $fn[0] ) . '::' . $fn[1];
                    }
                    $registered_callbacks[] = "  priority {$priority}: {$fn}";
                }
            }
        }

        $result = apply_filters( $hook_name, null, ...array_values($hook_args) );

        $html = "Hook: <strong>{$hook_name}</strong>\n\n";
        if ( $registered_callbacks ) {
            $html .= "Callbacks đã đăng ký:\n" . implode("\n", $registered_callbacks) . "\n\n";
        } else {
            $html .= "⚠ Không có callback nào đăng ký cho hook này!\n\n";
        }
        $html .= "Result: " . print_r($result, true);

        wp_send_json_success( '<pre>' . esc_html($html) . '</pre>' );
    }

    /**
     * AJAX: Liệt kê tất cả WC products + meta Tourfic liên quan
     * Giúp xác định chính xác Tourfic lưu product ở đâu
     */
    public function ajax_debug_wc_products(): void {
        check_ajax_referer( 'rba_debug_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        global $wpdb;

        $room_id = absint( $_POST['room_id'] ?? 0 );

        // Lấy tất cả WC products
        $products = $wpdb->get_results(
            "SELECT ID, post_title, post_name, post_status, post_type
             FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status IN ('publish','private','draft')
             ORDER BY ID DESC LIMIT 50"
        );

        $rows = [];
        foreach ( $products as $p ) {
            $meta_keys = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d
                   AND meta_key REGEXP '^(tf_|_tf_|_price|_regular_price|_related|_room)'
                 ORDER BY meta_key",
                $p->ID
            ) );
            $meta = [];
            foreach ( $meta_keys as $m ) {
                $val = strlen($m->meta_value) > 60 ? substr($m->meta_value,0,60).'…' : $m->meta_value;
                $meta[] = $m->meta_key . ' = ' . $val;
            }
            $rows[] = [
                'id'     => $p->ID,
                'title'  => $p->post_title,
                'slug'   => $p->post_name,
                'status' => $p->post_status,
                'meta'   => $meta,
            ];
        }

        // Kiểm tra room cụ thể nếu có
        $room_info = [];
        if ( $room_id ) {
            $room_meta = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d
                   AND meta_key REGEXP '^(tf_|_tf_|_wc)'
                 ORDER BY meta_key",
                $room_id
            ) );
            foreach ( $room_meta as $m ) {
                $room_info[] = $m->meta_key . ' = ' . substr($m->meta_value,0,80);
            }

            // Test get_wc_product_id
            if ( class_exists('RBA_Room_Template') ) {
                $rt = new RBA_Room_Template();
                $ref = new ReflectionClass($rt);
                $method = $ref->getMethod('get_wc_product_id');
                $method->setAccessible(true);
                $found_id = $method->invoke($rt, $room_id);
                $room_info[] = '→ get_wc_product_id() trả về: ' . ($found_id ?: 'KHÔNG TÌM THẤY');
            }
        }

        wp_send_json_success( [
            'products'  => $rows,
            'room_info' => $room_info,
            'total'     => count($rows),
        ] );
    }

} // end class RBA_Debug

new RBA_Debug();
