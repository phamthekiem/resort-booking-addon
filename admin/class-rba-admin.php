<?php
/**
 * RBA_Admin
 *
 * Đăng ký menu riêng (top-level) thay vì submenu vào Tourfic
 * để tránh phụ thuộc vào slug Tourfic (thay đổi theo version).
 *
 * @package ResortBookingAddon
 * @since   1.0.2
 */
defined( 'ABSPATH' ) || exit;

class RBA_Admin {

    /** Slug của top-level menu */
    const MENU_SLUG = 'rba-dashboard';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_pages' ], 99 ); // Priority 99 = chạy SAU Tourfic (default 10)
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
        add_action( 'wp_dashboard_setup',    [ $this, 'add_dashboard_widget' ] );

        // AJAX: lấy dữ liệu stats realtime cho dashboard
        add_action( 'wp_ajax_rba_dashboard_stats', [ $this, 'ajax_dashboard_stats' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MENU REGISTRATION
    // ─────────────────────────────────────────────────────────────────────────

    public function register_pages(): void {
        // Hook priority 99 ─ chạy SAU Tourfic (priority 10)
        // nên $GLOBALS['menu'] đã có đủ khi hàm này chạy.
        $tf_slug = $this->get_tourfic_menu_slug();

        if ( $tf_slug ) {
            // ── Attach vào đúng menu Tourfic ────────────────────────────────
            add_submenu_page( $tf_slug, 'Resort Dashboard', 'Resort Dashboard', 'manage_options', self::MENU_SLUG,    [ $this, 'render_dashboard' ] );
            add_submenu_page( $tf_slug, 'Availability',     'Availability',     'manage_options', 'rba-availability', [ $this, 'render_availability' ] );
            add_submenu_page( $tf_slug, 'OTA Sync',         'OTA Sync',         'manage_options', 'rba-ota-sync',     [ $this, 'render_ota_sync_page' ] );
        } else {
            // ── Fallback: top-level menu riêng ──────────────────────────────
            add_menu_page( 'Resort Booking', 'Resort Booking', 'manage_options', self::MENU_SLUG, [ $this, 'render_dashboard' ], 'dashicons-calendar-alt', 26 );
            add_submenu_page( self::MENU_SLUG, 'Resort Dashboard', 'Dashboard',    'manage_options', self::MENU_SLUG,    [ $this, 'render_dashboard' ] );
            add_submenu_page( self::MENU_SLUG, 'Availability',     'Availability', 'manage_options', 'rba-availability', [ $this, 'render_availability' ] );
            add_submenu_page( self::MENU_SLUG, 'OTA Sync',         'OTA Sync',     'manage_options', 'rba-ota-sync',     [ $this, 'render_ota_sync_page' ] );
        }
    }

    /**
     * Detect slug menu cha Tourfic bằng cách đọc $GLOBALS['menu'] + $GLOBALS['submenu'].
     *
     * Từ source Tourfic: menu cha dùng $this->option_id làm slug, và submenu
     * Dashboard dùng slug 'tf_dashboard'. Ta dùng điểm đặc trưng đó để match chắc chắn.
     */
    private function get_tourfic_menu_slug(): string {
        global $menu, $submenu;
        if ( empty( $menu ) ) return '';

        foreach ( (array) $menu as $item ) {
            if ( ! isset( $item[2] ) ) continue;
            $slug = $item[2];

            // Cách chắc nhất: Tourfic luôn có submenu slug = 'tf_dashboard'
            if ( ! empty( $submenu[ $slug ] ) ) {
                foreach ( $submenu[ $slug ] as $sub ) {
                    if ( isset( $sub[2] ) && 'tf_dashboard' === $sub[2] ) {
                        return $slug; // Tìm thấy chính xác
                    }
                }
            }

            // Fallback: match slug hoặc title
            if ( preg_match( '/^(tourfic|tf[-_]admin|tf[-_]hotel|tf-admin)$/i', $slug ) ) {
                return $slug;
            }
        }
        return '';
    }


    // ─────────────────────────────────────────────────────────────────────────
    // DASHBOARD PAGE
    // ─────────────────────────────────────────────────────────────────────────

    public function render_dashboard(): void {
        global $wpdb;
        $today = current_time( 'Y-m-d' );

        $total_rooms = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts} WHERE post_type = 'tf_room' AND post_status = 'publish'"
        );

        $occupied_rooms = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT room_id) FROM {$wpdb->prefix}rba_availability WHERE avail_date = %s AND booked_rooms > 0",
            $today
        ) );

        $occupancy_rate   = $total_rooms > 0 ? round( ( $occupied_rooms / $total_rooms ) * 100 ) : 0;
        $pending_ids      = wc_get_orders( [ 'status' => 'pending',    'limit' => -1, 'return' => 'ids' ] );
        $processing_ids   = wc_get_orders( [ 'status' => 'processing', 'limit' => -1, 'return' => 'ids' ] );
        $pending_count    = count( $pending_ids );
        $processing_count = count( $processing_ids );

        $month_start = current_time( 'Y-m-01' );
        $revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(pm.meta_value)
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
             WHERE p.post_type = 'shop_order' AND p.post_status = 'wc-completed' AND p.post_date >= %s",
            $month_start
        ) );
        ?>
        <div class="wrap rba-wrap">
            <h1 class="rba-page-title">
                <span class="dashicons dashicons-calendar-alt" style="font-size:28px;vertical-align:middle;margin-right:8px;color:#1a6b3c"></span>
                Resort Booking — Dashboard
            </h1>

            <?php $this->render_tab_nav( 'dashboard' ); ?>

            <!-- STATS CARDS -->
            <div class="rba-stats-row">
                <div class="rba-card rba-card--stat">
                    <div class="rba-card__icon dashicons dashicons-building"></div>
                    <div class="rba-card__val"><?php echo esc_html( $total_rooms ); ?></div>
                    <div class="rba-card__label">Tổng số phòng</div>
                </div>
                <div class="rba-card rba-card--stat <?php echo $occupancy_rate > 80 ? 'rba-card--danger' : ( $occupancy_rate > 50 ? 'rba-card--warn' : 'rba-card--ok' ); ?>">
                    <div class="rba-card__icon dashicons dashicons-chart-bar"></div>
                    <div class="rba-card__val"><?php echo esc_html( $occupancy_rate ); ?>%</div>
                    <div class="rba-card__label">Lấp đầy hôm nay</div>
                </div>
                <div class="rba-card rba-card--stat rba-card--warn">
                    <div class="rba-card__icon dashicons dashicons-clock"></div>
                    <div class="rba-card__val"><?php echo esc_html( $pending_count ); ?></div>
                    <div class="rba-card__label">Đơn chờ xác nhận</div>
                </div>
                <div class="rba-card rba-card--stat rba-card--ok">
                    <div class="rba-card__icon dashicons dashicons-yes-alt"></div>
                    <div class="rba-card__val"><?php echo esc_html( $processing_count ); ?></div>
                    <div class="rba-card__label">Đang xử lý</div>
                </div>
            </div>

            <!-- QUICK ACTIONS -->
            <div class="rba-card" style="margin-bottom:20px">
                <h3 style="margin-top:0">Hành động nhanh</h3>
                <div class="rba-actions-row">
                    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=tf_room' ) ); ?>" class="button button-primary rba-btn">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle"></span> Thêm phòng
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=tf_tour' ) ); ?>" class="button button-primary rba-btn">
                        <span class="dashicons dashicons-location" style="vertical-align:middle"></span> Thêm tour
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=rba-availability' ) ); ?>" class="button rba-btn">
                        <span class="dashicons dashicons-calendar" style="vertical-align:middle"></span> Xem Availability
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=rba-ota-sync' ) ); ?>" class="button rba-btn">
                        <span class="dashicons dashicons-update" style="vertical-align:middle"></span> OTA Sync
                    </a>
                    <button class="button rba-btn" id="rba-force-sync">
                        <span class="dashicons dashicons-controls-repeat" style="vertical-align:middle"></span>
                        Force iCal Sync
                    </button>
                </div>
            </div>

            <!-- AVAILABILITY HEATMAP -->
            <div class="rba-card" style="margin-bottom:20px">
                <h3 style="margin-top:0">Availability — 30 ngày tới</h3>
                <?php $this->render_availability_heatmap(); ?>
            </div>

            <!-- OTA STATUS -->
            <div class="rba-card">
                <h3 style="margin-top:0">Trạng thái OTA Sync</h3>
                <?php $this->render_ota_status_table(); ?>
            </div>
        </div>

        <?php $this->render_inline_styles(); ?>
        <?php $this->render_dashboard_scripts(); ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AVAILABILITY PAGE
    // ─────────────────────────────────────────────────────────────────────────

    public function render_availability(): void {
        ?>
        <div class="wrap rba-wrap">
            <h1 class="rba-page-title">
                <span class="dashicons dashicons-calendar" style="font-size:28px;vertical-align:middle;margin-right:8px;color:#1a6b3c"></span>
                Availability Manager
            </h1>
            <?php $this->render_tab_nav( 'availability' ); ?>
            <div class="rba-card">
                <?php $this->render_availability_heatmap(); ?>
            </div>
        </div>
        <?php $this->render_inline_styles(); ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OTA SYNC PAGE
    // ─────────────────────────────────────────────────────────────────────────

    public function render_ota_sync_page(): void {
        ?>
        <div class="wrap rba-wrap">
            <h1 class="rba-page-title">
                <span class="dashicons dashicons-update" style="font-size:28px;vertical-align:middle;margin-right:8px;color:#1a6b3c"></span>
                OTA Sync Status
            </h1>
            <?php $this->render_tab_nav( 'ota' ); ?>
            <div class="rba-card">
                <p>Tất cả iCal feeds từ Booking.com, Airbnb, Agoda được liệt kê bên dưới.
                Sync tự động mỗi <strong>15 phút</strong> qua WP Cron.</p>
                <?php $this->render_ota_status_table( true ); ?>
            </div>
        </div>
        <?php $this->render_inline_styles(); ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TAB NAV
    // ─────────────────────────────────────────────────────────────────────────

    private function render_tab_nav( string $active ): void {
        $tabs = [
            'dashboard'    => [ 'page' => 'rba-dashboard',        'label' => 'Dashboard',    'icon' => 'dashicons-chart-area' ],
            'availability' => [ 'page' => 'rba-availability',     'label' => 'Availability', 'icon' => 'dashicons-calendar'   ],
            'ota'          => [ 'page' => 'rba-ota-sync',         'label' => 'OTA Sync',     'icon' => 'dashicons-update'     ],
            'update'       => [ 'page' => 'rba-update-settings',  'label' => 'Updates',      'icon' => 'dashicons-admin-plugins' ],
        ];
        echo '<nav class="nav-tab-wrapper rba-tabs" style="margin-bottom:20px">';
        foreach ( $tabs as $key => $tab ) {
            $class = $key === $active ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url   = admin_url( 'admin.php?page=' . $tab['page'] );
            printf(
                '<a href="%s" class="%s"><span class="dashicons %s" style="vertical-align:middle;font-size:16px;margin-right:4px"></span>%s</a>',
                esc_url( $url ),
                esc_attr( $class ),
                esc_attr( $tab['icon'] ),
                esc_html( $tab['label'] )
            );
        }
        echo '</nav>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HEATMAP
    // ─────────────────────────────────────────────────────────────────────────

    private function render_availability_heatmap(): void {
        global $wpdb;

        $rooms = get_posts( [
            'post_type'   => 'tf_room',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ] );

        if ( empty( $rooms ) ) {
            echo '<p>Chưa có phòng nào. <a href="' . esc_url( admin_url( 'post-new.php?post_type=tf_room' ) ) . '">Tạo phòng đầu tiên</a>.</p>';
            return;
        }

        $today   = current_time( 'Y-m-d' );
        $end_day = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
        $dates   = [];
        for ( $i = 0; $i < 30; $i++ ) {
            $dates[] = gmdate( 'Y-m-d', strtotime( $today . " +{$i} days" ) );
        }

        echo '<div style="overflow-x:auto">';
        echo '<table class="wp-list-table widefat rba-heatmap-table">';
        echo '<thead><tr>';
        echo '<th style="min-width:140px;position:sticky;left:0;background:#fff;z-index:2">Phòng</th>';
        foreach ( $dates as $d ) {
            $dow   = (int) gmdate( 'N', strtotime( $d ) );
            $label = gmdate( 'd/m', strtotime( $d ) );
            $style = $dow >= 6 ? 'color:#e65100;font-weight:600' : '';
            echo "<th style='text-align:center;width:34px;font-size:11px;padding:6px 2px;{$style}'>" . esc_html( $label ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $rooms as $room ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT avail_date, total_rooms, booked_rooms, blocked
                 FROM {$wpdb->prefix}rba_availability
                 WHERE room_id = %d AND avail_date BETWEEN %s AND %s",
                $room->ID, $today, $end_day
            ), OBJECT_K );

            echo '<tr>';
            echo '<td style="font-weight:600;position:sticky;left:0;background:#fff;z-index:1;border-right:2px solid #e0e0e0">';
            echo '<a href="' . esc_url( get_edit_post_link( $room->ID ) ) . '">' . esc_html( $room->post_title ) . '</a>';
            echo '</td>';

            foreach ( $dates as $d ) {
                $a = $rows[ $d ] ?? null;
                if ( ! $a ) {
                    echo '<td style="text-align:center;padding:4px 2px"><span class="rba-cell rba-cell--nodata" title="' . esc_attr( $d ) . ': chưa có dữ liệu">—</span></td>';
                    continue;
                }
                $free = (int) $a->total_rooms - (int) $a->booked_rooms;
                if ( (int) $a->blocked )              { $cls = 'blocked'; $title = 'Bị chặn (OTA/Admin)'; }
                elseif ( $free <= 0 )                 { $cls = 'full';    $title = 'Hết phòng'; }
                elseif ( $free < (int) $a->total_rooms ) { $cls = 'partial'; $title = "Còn $free/{$a->total_rooms}"; }
                else                                  { $cls = 'avail';   $title = "Trống $free/{$a->total_rooms}"; }
                echo '<td style="text-align:center;padding:4px 2px"><span class="rba-cell rba-cell--' . esc_attr( $cls ) . '" title="' . esc_attr( $d . ': ' . $title ) . '">' . esc_html( $free ) . '</span></td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '<p style="font-size:12px;color:#666;margin-top:10px">';
        echo '<span class="rba-cell rba-cell--avail" style="display:inline-flex">T</span> Trống &nbsp;';
        echo '<span class="rba-cell rba-cell--partial" style="display:inline-flex">C</span> Còn ít &nbsp;';
        echo '<span class="rba-cell rba-cell--full" style="display:inline-flex">H</span> Hết &nbsp;';
        echo '<span class="rba-cell rba-cell--blocked" style="display:inline-flex">X</span> Bị chặn &nbsp;';
        echo '<span class="rba-cell rba-cell--nodata" style="display:inline-flex">—</span> Chưa có dữ liệu';
        echo '</p>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OTA STATUS TABLE
    // ─────────────────────────────────────────────────────────────────────────

    private function render_ota_status_table( bool $full_page = false ): void {
        global $wpdb;

        // Kiểm tra table tồn tại trước khi query
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rba_ical_sources'" );
        if ( ! $table_exists ) {
            echo '<p><em>Chưa có OTA nào. Vào trang phòng để thêm iCal feed.</em></p>';
            return;
        }

        $limit   = $full_page ? 100 : 10;
        $sources = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, p.post_title AS room_name
             FROM {$wpdb->prefix}rba_ical_sources s
             LEFT JOIN {$wpdb->posts} p ON p.ID = s.room_id
             ORDER BY s.sync_status DESC, s.id ASC
             LIMIT %d",
            $limit
        ) );

        if ( empty( $sources ) ) {
            echo '<p>Chưa có OTA nào được kết nối. <a href="' . esc_url( admin_url( 'edit.php?post_type=tf_room' ) ) . '">Vào quản lý phòng</a> để thêm iCal feed.</p>';
            return;
        }

        $nonce = wp_create_nonce( 'rba_manual_sync' );
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>Phòng</th><th>OTA</th><th>Sync gần nhất</th><th>Trạng thái</th><th style="width:100px">Thao tác</th>';
        echo '</tr></thead><tbody>';

        foreach ( $sources as $src ) {
            $status = esc_html( $src->sync_status ?? 'pending' );
            $icon   = 'ok'    === $src->sync_status ? '<span style="color:#2e7d32">&#10003; OK</span>'
                    : ( 'error' === $src->sync_status ? '<span style="color:#c62828">&#10007; Lỗi</span>'
                    : '<span style="color:#f57c00">&#8987; Pending</span>' );
            $last   = ! empty( $src->last_synced )
                ? esc_html( human_time_diff( strtotime( $src->last_synced ) ) . ' trước' )
                : '<em>Chưa sync</em>';
            $err    = 'error' === $src->sync_status && ! empty( $src->error_msg )
                ? '<br><small style="color:#c62828">' . esc_html( mb_strimwidth( $src->error_msg, 0, 80, '…' ) ) . '</small>'
                : '';

            echo '<tr>';
            echo '<td>' . esc_html( $src->room_name ?? '—' ) . ' <small style="color:#888">#' . esc_html( $src->room_id ) . '</small></td>';
            echo '<td>' . esc_html( $src->source_name ?? 'OTA' ) . '</td>';
            echo '<td>' . $last . '</td>';
            echo '<td>' . $icon . $err . '</td>';
            echo '<td><button class="button button-small rba-sync-btn" data-source="' . esc_attr( $src->id ) . '" data-nonce="' . esc_attr( $nonce ) . '">Sync ngay</button></td>';
            echo '</tr>';
        }

        if ( ! $full_page && count( $sources ) >= 10 ) {
            echo '<tr><td colspan="5"><a href="' . esc_url( admin_url( 'admin.php?page=rba-ota-sync' ) ) . '">Xem tất cả →</a></td></tr>';
        }

        echo '</tbody></table>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WP DASHBOARD WIDGET
    // ─────────────────────────────────────────────────────────────────────────

    public function add_dashboard_widget(): void {
        wp_add_dashboard_widget(
            'rba_overview_widget',
            'Resort Booking — Overview',
            [ $this, 'render_dashboard_widget' ]
        );
    }

    public function render_dashboard_widget(): void {
        global $wpdb;
        $today = current_time( 'Y-m-d' );

        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tf_room' AND post_status='publish'" );
        $occupied = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT room_id) FROM {$wpdb->prefix}rba_availability WHERE avail_date = %s AND booked_rooms > 0",
            $today
        ) );
        $pending = count( wc_get_orders( [ 'status' => 'pending', 'limit' => -1, 'return' => 'ids' ] ) );

        $pct = $total > 0 ? round( $occupied / $total * 100 ) : 0;
        ?>
        <table style="width:100%;border-collapse:collapse">
            <tr>
                <td style="padding:8px 0;border-bottom:1px solid #eee">
                    <strong>Lấp đầy hôm nay</strong>
                </td>
                <td style="text-align:right;padding:8px 0;border-bottom:1px solid #eee">
                    <strong style="color:<?php echo $pct > 80 ? '#c62828' : ( $pct > 50 ? '#e65100' : '#2e7d32' ); ?>">
                        <?php echo esc_html( $occupied . '/' . $total . ' phòng (' . $pct . '%)' ); ?>
                    </strong>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0">Đơn chờ xác nhận</td>
                <td style="text-align:right;padding:8px 0">
                    <?php if ( $pending > 0 ) : ?>
                        <strong style="color:#e65100"><?php echo esc_html( $pending ); ?></strong>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order&post_status=wc-pending' ) ); ?>" style="margin-left:6px">Xem →</a>
                    <?php else : ?>
                        <span style="color:#2e7d32">0 (tất cả OK)</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <p style="margin-bottom:0">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=rba-dashboard' ) ); ?>" class="button button-small">Xem Dashboard đầy đủ</a>
        </p>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENQUEUE
    // ─────────────────────────────────────────────────────────────────────────

    public function enqueue_admin( string $hook ): void {
        // Hook name format: {parent_slug}_page_{child_slug}
        // Khi nằm dưới Tourfic (option_id khác nhau) → prefix khác nhau.
        // Dùng str_ends_with để match bất kể parent prefix là gì.
        $rba_pages = [ 'rba-dashboard', 'rba-availability', 'rba-ota-sync' ];
        foreach ( $rba_pages as $page_slug ) {
            // toplevel_page_{slug} hoặc {parent}_page_{slug}
            if ( str_ends_with( $hook, '_page_' . $page_slug ) || $hook === 'toplevel_page_' . $page_slug ) {
                wp_enqueue_style( 'rba-admin', RBA_URL . 'assets/css/admin.css', [], RBA_VERSION );
                return;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: Dashboard stats (dùng cho auto-refresh nếu cần)
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_dashboard_stats(): void {
        check_ajax_referer( 'rba_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $today    = current_time( 'Y-m-d' );
        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tf_room' AND post_status='publish'" );
        $occupied = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT room_id) FROM {$wpdb->prefix}rba_availability WHERE avail_date = %s AND booked_rooms > 0",
            $today
        ) );
        wp_send_json_success( [
            'total'    => $total,
            'occupied' => $occupied,
            'free'     => $total - $occupied,
            'rate'     => $total > 0 ? round( $occupied / $total * 100 ) : 0,
            'pending'  => count( wc_get_orders( [ 'status' => 'pending', 'limit' => -1, 'return' => 'ids' ] ) ),
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INLINE STYLES (dùng khi admin.css chưa load hoặc bị cache)
    // ─────────────────────────────────────────────────────────────────────────

    private function render_inline_styles(): void {
        ?>
        <style>
        .rba-wrap { max-width: 1200px; }
        .rba-page-title { display: flex; align-items: center; margin-bottom: 16px; }
        .rba-card { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 20px; margin-bottom: 20px; }
        .rba-stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 20px; }
        .rba-card--stat { text-align: center; border-top: 4px solid #ccc; }
        .rba-card--ok   { border-top-color: #2e7d32; }
        .rba-card--warn { border-top-color: #e65100; }
        .rba-card--danger { border-top-color: #c62828; }
        .rba-card__icon { font-size: 32px; color: #999; margin-bottom: 8px; }
        .rba-card--ok   .rba-card__icon { color: #2e7d32; }
        .rba-card--warn .rba-card__icon { color: #e65100; }
        .rba-card--danger .rba-card__icon { color: #c62828; }
        .rba-card__val  { font-size: 40px; font-weight: 700; line-height: 1.1; color: #111; }
        .rba-card__label { font-size: 13px; color: #666; margin-top: 4px; }
        .rba-actions-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .rba-btn { display: inline-flex; align-items: center; gap: 4px; }
        .rba-tabs { margin-bottom: 20px; }
        /* Heatmap cells */
        .rba-cell { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 4px; font-size: 11px; font-weight: 700; color: #fff; }
        .rba-cell--avail   { background: #2e7d32; }
        .rba-cell--partial { background: #f57c00; }
        .rba-cell--full    { background: #c62828; }
        .rba-cell--blocked { background: #757575; }
        .rba-cell--nodata  { background: #e0e0e0; color: #999; }
        .rba-heatmap-table th, .rba-heatmap-table td { padding: 6px 3px; }
        @media (max-width: 782px) { .rba-stats-row { grid-template-columns: repeat(2,1fr); } }
        </style>
        <?php
    }

    private function render_dashboard_scripts(): void {
        $nonce = wp_create_nonce( 'rba_manual_sync' );
        ?>
        <script>
        // Force sync button
        document.getElementById('rba-force-sync')?.addEventListener('click', function() {
            if ( ! confirm('Sync tất cả iCal feeds ngay bây giờ?') ) return;
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="dashicons dashicons-update spin" style="vertical-align:middle"></span> Đang sync...';
            const fd = new FormData();
            fd.append('action', 'rba_manual_sync');
            fd.append('source_id', 'all');
            fd.append('nonce', '<?php echo esc_js( $nonce ); ?>');
            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(r => {
                    alert(r.success ? '✔ Sync hoàn tất!' : '✘ ' + r.data);
                    btn.disabled = false;
                    btn.innerHTML = '<span class="dashicons dashicons-controls-repeat" style="vertical-align:middle"></span> Force iCal Sync';
                    if (r.success) location.reload();
                });
        });

        // Per-source sync
        document.querySelectorAll('.rba-sync-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                btn.disabled = true; btn.textContent = '...';
                const fd = new FormData();
                fd.append('action', 'rba_manual_sync');
                fd.append('source_id', btn.dataset.source);
                fd.append('nonce', btn.dataset.nonce);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(r => { alert(r.success ? '✔ Sync xong' : '✘ ' + r.data); location.reload(); });
            });
        });

        // Spin animation for dashicons
        const style = document.createElement('style');
        style.textContent = '@keyframes spin{to{transform:rotate(360deg)}} .spin{animation:spin 1s linear infinite;display:inline-block}';
        document.head.appendChild(style);
        </script>
        <?php
    }
}

    // ─────────────────────────────────────────────────────────────────────────
    // GITHUB UPDATE SETTINGS
    // ─────────────────────────────────────────────────────────────────────────

    public function render_update_settings_page(): void {
        $github_user  = get_option( 'rba_updater_github_user', '' );
        $github_repo  = get_option( 'rba_updater_github_repo', 'resort-booking-addon' );
        $github_token = get_option( 'rba_updater_github_token', '' );
        $current_ver  = RBA_VERSION;
        ?>
        <div class="wrap" style="max-width:720px">
            <h1>
                <span class="dashicons dashicons-update" style="font-size:26px;vertical-align:middle;margin-right:8px;color:#1a6b3c"></span>
                GitHub Update Settings
            </h1>

            <?php $this->render_tab_nav( 'update' ); ?>

            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px">
                <div style="background:#f0f6fc;border:1px solid #0969da;border-radius:6px;padding:14px;margin-bottom:20px;font-size:13px">
                    <strong>Phiên bản đang cài:</strong> <?php echo esc_html( $current_ver ); ?> &nbsp;|&nbsp;
                    <strong>Repo:</strong>
                    <?php if ( $github_user && $github_repo ) : ?>
                        <a href="https://github.com/<?php echo esc_attr( $github_user . '/' . $github_repo ); ?>" target="_blank">
                            <?php echo esc_html( $github_user . '/' . $github_repo ); ?>
                        </a>
                    <?php else : ?>
                        <em style="color:#888">Chưa cấu hình</em>
                    <?php endif; ?>
                </div>

                <table class="form-table" style="margin:0">
                    <tr>
                        <th style="width:160px"><label>GitHub Username / Org</label></th>
                        <td>
                            <input type="text" id="rba-gh-user" value="<?php echo esc_attr( $github_user ); ?>"
                                   class="regular-text" placeholder="your-github-username">
                            <p class="description">Username hoặc Organization name trên GitHub</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Repository Name</label></th>
                        <td>
                            <input type="text" id="rba-gh-repo" value="<?php echo esc_attr( $github_repo ); ?>"
                                   class="regular-text" placeholder="resort-booking-addon">
                            <p class="description">Tên repo chứa plugin (không bao gồm username)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Personal Access Token</label></th>
                        <td>
                            <input type="password" id="rba-gh-token" value="<?php echo esc_attr( $github_token ); ?>"
                                   class="regular-text" placeholder="ghp_xxxxxxxxxxxxxxxxxxxx">
                            <p class="description">
                                Bắt buộc với <strong>private repo</strong>. Không cần với public repo.<br>
                                Tạo tại: GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic)<br>
                                Scope cần thiết: <code>repo</code> (đọc releases và assets)
                            </p>
                        </td>
                    </tr>
                </table>

                <div style="margin-top:20px;display:flex;gap:10px;align-items:center">
                    <button class="button button-primary" id="rba-save-github-config"
                            data-nonce="<?php echo esc_attr( wp_create_nonce('rba_save_github_config') ); ?>">
                        Lưu cài đặt
                    </button>
                    <button class="button" id="rba-test-github-api"
                            data-nonce="<?php echo esc_attr( wp_create_nonce('rba_check_update_now') ); ?>">
                        Test & Check for Updates
                    </button>
                    <span id="rba-github-msg" style="font-size:13px"></span>
                </div>

                <hr style="margin:24px 0">

                <h3 style="margin-top:0">Hướng dẫn thiết lập GitHub Release</h3>
                <div style="background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:16px;font-size:13px;line-height:1.8">
                    <strong>1. Tạo Release trên GitHub:</strong><br>
                    &nbsp;&nbsp;→ Repo → Releases → Draft a new release → Create new tag: <code>v1.4.1</code><br>
                    &nbsp;&nbsp;→ Upload file: <code>resort-booking-addon-v1.4.1.zip</code> (tên phải đúng format)<br>
                    &nbsp;&nbsp;→ Viết changelog trong Release body (Markdown)<br>
                    &nbsp;&nbsp;→ Publish release<br><br>
                    <strong>2. WordPress tự động:</strong><br>
                    &nbsp;&nbsp;→ Mỗi 12 giờ WordPress check update → plugin chen vào → so sánh version tag<br>
                    &nbsp;&nbsp;→ Nếu tag mới hơn version đang cài → hiện nút "Update" trong Plugins page<br>
                    &nbsp;&nbsp;→ Admin click Update → WordPress download .zip từ GitHub Release → cài đặt<br><br>
                    <strong>3. Đặt tên .zip đúng format:</strong><br>
                    &nbsp;&nbsp;Ưu tiên: <code>resort-booking-addon-v1.4.1.zip</code><br>
                    &nbsp;&nbsp;Fallback: <code>resort-booking-addon-1.4.1.zip</code> hoặc <code>resort-booking-addon.zip</code>
                </div>
            </div>
        </div>

        <script>
        (function($){
            $('#rba-save-github-config').on('click', function(){
                const $msg = $('#rba-github-msg');
                $.post(ajaxurl, {
                    action: 'rba_save_github_config',
                    nonce:  $(this).data('nonce'),
                    user:   $('#rba-gh-user').val(),
                    repo:   $('#rba-gh-repo').val(),
                    token:  $('#rba-gh-token').val(),
                }, function(r){
                    $msg.html( r.success
                        ? '<span style="color:#2e7d32">✔ Đã lưu</span>'
                        : '<span style="color:#c62828">✘ Lỗi</span>' );
                });
            });

            $('#rba-test-github-api').on('click', function(){
                const $msg = $('#rba-github-msg');
                $msg.text('Đang kiểm tra...');
                $.post(ajaxurl, { action: 'rba_check_update_now', nonce: $(this).data('nonce') }, function(r){
                    if ( r.success ) {
                        if ( r.data.has_update ) {
                            $msg.html('<span style="color:#e65100">⬆ Có bản mới: v' + r.data.version + ' — <a href="plugins.php">Xem trong Plugins</a></span>');
                        } else {
                            $msg.html('<span style="color:#2e7d32">✔ Đang dùng bản mới nhất (v' + r.data.current_version + ')</span>');
                        }
                    } else {
                        $msg.html('<span style="color:#c62828">✘ ' + (r.data || 'Không kết nối được GitHub API') + '</span>');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function ajax_save_github_config(): void {
        check_ajax_referer( 'rba_save_github_config', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        update_option( 'rba_updater_github_user',  sanitize_text_field( wp_unslash( $_POST['user']  ?? '' ) ) );
        update_option( 'rba_updater_github_repo',  sanitize_text_field( wp_unslash( $_POST['repo']  ?? '' ) ) );
        update_option( 'rba_updater_github_token', sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) );

        // Xóa update cache để force check mới
        delete_transient( 'rba_github_release_cache' );
        delete_site_transient( 'update_plugins' );

        wp_send_json_success();
    }

new RBA_Admin();
