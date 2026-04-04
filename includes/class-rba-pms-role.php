<?php
/**
 * RBA_PMS_Role — Quản lý role và quyền cho PMS Dashboard
 *
 * Role: rba_receptionist
 * - Truy cập PMS dashboard (/pms/)
 * - Xem và cập nhật booking
 * - Check-in / Check-out
 * - Xem hóa đơn, in PDF
 * - Xem báo cáo cơ bản
 * - KHÔNG có quyền vào WP Admin
 *
 * @package ResortBookingAddon
 * @since   1.7.0
 */
defined( 'ABSPATH' ) || exit;

class RBA_PMS_Role {

    const ROLE_SLUG = 'rba_receptionist';

    const CAPS = [
        'rba_pms_access'          => true,  // Truy cập PMS
        'rba_view_bookings'       => true,  // Xem danh sách booking
        'rba_manage_checkin'      => true,  // Check-in / Check-out
        'rba_view_reports'        => true,  // Xem báo cáo
        'rba_view_invoices'       => true,  // Xem hóa đơn
        'rba_manage_room_status'  => true,  // Cập nhật trạng thái phòng (dọn, sẵn sàng...)
        'read'                    => true,  // WP cơ bản
    ];

    public function __construct() {
        register_activation_hook( RBA_PATH . 'resort-booking-addon.php', [ __CLASS__, 'create_role' ] );
        register_deactivation_hook( RBA_PATH . 'resort-booking-addon.php', [ __CLASS__, 'maybe_remove_role' ] );

        // Admin: trang quản lý nhân viên lễ tân
        // Priority 100 = chạy SAU class-rba-admin.php (priority 99) để menu cha đã tồn tại
        add_action( 'admin_menu',         [ $this, 'register_admin_page' ], 100 );
        add_action( 'wp_ajax_rba_pms_save_user_role',   [ $this, 'ajax_save_user_role' ] );
        add_action( 'wp_ajax_rba_pms_remove_user_role', [ $this, 'ajax_remove_user_role' ] );

        // Ngăn nhân viên vào WP Admin (chỉ cho vào /pms/)
        add_action( 'admin_init', [ $this, 'restrict_admin_access' ] );

        // Ẩn admin bar cho receptionist
        add_action( 'after_setup_theme', [ $this, 'hide_admin_bar' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ROLE LIFECYCLE
    // ─────────────────────────────────────────────────────────────────────────

    public static function create_role(): void {
        if ( get_role( self::ROLE_SLUG ) ) {
            // Role đã tồn tại — cập nhật caps nếu thiếu
            $role = get_role( self::ROLE_SLUG );
            foreach ( self::CAPS as $cap => $granted ) {
                if ( $granted ) $role->add_cap( $cap );
            }
            return;
        }
        add_role( self::ROLE_SLUG, 'Nhân viên Lễ tân (PMS)', self::CAPS );
    }

    public static function maybe_remove_role(): void {
        // Chỉ xóa role khi không còn user nào dùng
        $users = get_users( [ 'role' => self::ROLE_SLUG, 'number' => 1 ] );
        if ( empty( $users ) ) {
            remove_role( self::ROLE_SLUG );
        }
    }

    public static function ensure_role_exists(): void {
        if ( ! get_role( self::ROLE_SLUG ) ) {
            self::create_role();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECURITY: Chặn truy cập WP Admin
    // ─────────────────────────────────────────────────────────────────────────

    public function restrict_admin_access(): void {
        if ( ! is_admin() ) return;
        if ( defined('DOING_AJAX') && DOING_AJAX ) return;

        $user = wp_get_current_user();
        if ( ! $user->exists() ) return;

        // Nếu chỉ có role receptionist (không có admin/editor) → redirect về PMS
        $roles = (array) $user->roles;
        $has_admin_role = array_intersect( $roles, [ 'administrator', 'editor', 'author', 'contributor', 'shop_manager' ] );

        if ( empty( $has_admin_role ) && in_array( self::ROLE_SLUG, $roles, true ) ) {
            wp_safe_redirect( home_url( '/pms/' ) );
            exit;
        }
    }

    public function hide_admin_bar(): void {
        if ( ! is_user_logged_in() ) return;
        if ( current_user_can( 'manage_options' ) ) return;
        if ( current_user_can( self::CAPS['rba_pms_access'] ? 'rba_pms_access' : 'read' )
             && ! current_user_can( 'edit_posts' ) ) {
            show_admin_bar( false );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    public static function current_user_can_pms(): bool {
        return is_user_logged_in()
            && ( current_user_can( 'rba_pms_access' ) || current_user_can( 'manage_options' ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN PAGE: Quản lý nhân viên
    // ─────────────────────────────────────────────────────────────────────────

    public function register_admin_page(): void {
        add_submenu_page(
            $this->find_parent_slug(),
            'Quản lý Nhân viên PMS',
            '👤 Nhân viên PMS',
            'manage_options',
            'rba-pms-staff',
            [ $this, 'render_staff_page' ]
        );
    }

    private function find_parent_slug(): string {
        global $submenu;

        // Ưu tiên 1: rba-dashboard đã được đăng ký (standalone mode)
        if ( isset( $submenu['rba-dashboard'] ) ) return 'rba-dashboard';

        // Ưu tiên 2: Tìm menu Tourfic đang chứa rba-dashboard submenu
        foreach ( (array) $submenu as $parent_slug => $items ) {
            foreach ( $items as $item ) {
                if ( isset( $item[2] ) && $item[2] === 'rba-dashboard' ) {
                    return $parent_slug;
                }
            }
        }

        // Fallback
        return 'rba-dashboard';
    }

    public function render_staff_page(): void {
        self::ensure_role_exists();
        $receptionists = get_users( [ 'role' => self::ROLE_SLUG ] );
        $all_users     = get_users( [ 'number' => 100, 'exclude' => array_map( fn($u) => $u->ID, $receptionists ) ] );
        $pms_url       = home_url( '/pms/' );
        $nonce         = wp_create_nonce( 'rba_pms_staff_nonce' );
        ?>
        <div class="wrap" style="max-width:900px">
            <h1>👤 Quản lý Nhân viên PMS</h1>

            <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:8px;padding:14px;margin-bottom:20px;font-size:13px">
                Nhân viên được gán role <strong>"Nhân viên Lễ tân (PMS)"</strong> có thể truy cập:
                <a href="<?php echo esc_url($pms_url); ?>" target="_blank"><strong><?php echo esc_html($pms_url); ?></strong></a>
                — Họ <strong>không thể</strong> vào WP Admin.
            </div>

            <!-- Danh sách nhân viên hiện tại -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px">
                <h3 style="margin-top:0">Nhân viên đang có quyền PMS (<?php echo count($receptionists); ?>)</h3>
                <?php if ( empty($receptionists) ) : ?>
                    <p style="color:#888;font-size:13px">Chưa có nhân viên nào. Thêm từ danh sách bên dưới.</p>
                <?php else : ?>
                <table class="wp-list-table widefat" style="font-size:13px">
                    <thead><tr>
                        <th>Tên</th><th>Email / Username</th><th>Ngày tạo tài khoản</th><th>Thao tác</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $receptionists as $u ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($u->display_name); ?></strong></td>
                        <td><?php echo esc_html($u->user_email); ?> <small style="color:#888">(<?php echo esc_html($u->user_login); ?>)</small></td>
                        <td><?php echo esc_html( date_i18n('d/m/Y', strtotime($u->user_registered)) ); ?></td>
                        <td>
                            <button class="button button-small rba-remove-role"
                                    data-user="<?php echo esc_attr($u->ID); ?>"
                                    data-nonce="<?php echo esc_attr($nonce); ?>"
                                    style="color:#c62828">
                                Xóa quyền PMS
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Thêm nhân viên -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px">
                <h3 style="margin-top:0">Thêm nhân viên từ user có sẵn</h3>
                <?php if ( empty($all_users) ) : ?>
                    <p style="color:#888;font-size:13px">Tất cả user đều đã có quyền PMS.</p>
                <?php else : ?>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <select id="rba-add-user" style="min-width:300px">
                        <option value="">-- Chọn user --</option>
                        <?php foreach ( $all_users as $u ) : ?>
                        <option value="<?php echo esc_attr($u->ID); ?>">
                            <?php echo esc_html("{$u->display_name} ({$u->user_login}) — " . implode(', ', $u->roles)); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary" id="rba-grant-role" data-nonce="<?php echo esc_attr($nonce); ?>">
                        + Cấp quyền PMS
                    </button>
                    <span id="rba-role-msg" style="font-size:13px"></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tạo user mới -->
            <div style="background:#fff3e0;border:1px solid #ffb74d;border-radius:8px;padding:20px">
                <h3 style="margin-top:0">Tạo tài khoản nhân viên mới</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:600px">
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Tên hiển thị *</label>
                        <input type="text" id="new-display" class="regular-text" style="width:100%" placeholder="Nguyễn Văn A">
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Username *</label>
                        <input type="text" id="new-username" class="regular-text" style="width:100%" placeholder="nhanvien1">
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Email *</label>
                        <input type="email" id="new-email" class="regular-text" style="width:100%" placeholder="nhanvien@resort.com">
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Mật khẩu *</label>
                        <input type="password" id="new-password" class="regular-text" style="width:100%" placeholder="Mật khẩu mạnh">
                    </div>
                </div>
                <button class="button button-primary" id="rba-create-staff" data-nonce="<?php echo esc_attr($nonce); ?>" style="margin-top:12px">
                    Tạo tài khoản & cấp quyền PMS
                </button>
                <span id="rba-create-msg" style="font-size:13px;margin-left:10px"></span>
            </div>

            <div style="margin-top:20px;background:#e3f2fd;border:1px solid #90caf9;border-radius:8px;padding:14px;font-size:13px">
                <strong>Link đăng nhập cho nhân viên:</strong>
                <code style="background:#fff;padding:4px 8px;border-radius:4px;margin-left:8px"><?php echo esc_html(wp_login_url( $pms_url )); ?></code>
                <p style="margin:6px 0 0;color:#555">Sau khi đăng nhập, nhân viên sẽ tự động vào PMS Dashboard.</p>
            </div>
        </div>

        <script>
        (function($){
            const ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

            // Grant role
            $('#rba-grant-role').on('click', function(){
                const uid = $('#rba-add-user').val();
                if(!uid){ alert('Chọn user trước'); return; }
                $(this).prop('disabled',true);
                $.post(ajaxurl, { action:'rba_pms_save_user_role', nonce:$(this).data('nonce'), user_id:uid }, function(r){
                    $('#rba-grant-role').prop('disabled',false);
                    if(r.success){ location.reload(); }
                    else { $('#rba-role-msg').html('<span style="color:#c62828">'+r.data+'</span>'); }
                });
            });

            // Remove role
            $(document).on('click', '.rba-remove-role', function(){
                if(!confirm('Xóa quyền PMS của nhân viên này?')) return;
                const $btn = $(this).prop('disabled',true);
                $.post(ajaxurl, { action:'rba_pms_remove_user_role', nonce:$(this).data('nonce'), user_id:$(this).data('user') }, function(r){
                    if(r.success){ location.reload(); }
                    else { alert(r.data); $btn.prop('disabled',false); }
                });
            });

            // Create new staff
            $('#rba-create-staff').on('click', function(){
                const display = $('#new-display').val().trim();
                const username = $('#new-username').val().trim();
                const email = $('#new-email').val().trim();
                const password = $('#new-password').val();
                if(!display||!username||!email||!password){ alert('Điền đầy đủ thông tin'); return; }
                $(this).prop('disabled',true).text('Đang tạo...');
                $.post(ajaxurl, {
                    action:'rba_pms_save_user_role', nonce:$(this).data('nonce'),
                    create_new:1, display_name:display, username, email, password
                }, function(r){
                    $('#rba-create-staff').prop('disabled',false).text('Tạo tài khoản & cấp quyền PMS');
                    if(r.success){ $('#rba-create-msg').html('<span style="color:#2e7d32">✓ Tạo thành công!</span>'); setTimeout(()=>location.reload(),1500); }
                    else { $('#rba-create-msg').html('<span style="color:#c62828">'+r.data+'</span>'); }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_save_user_role(): void {
        check_ajax_referer( 'rba_pms_staff_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        // Tạo user mới
        if ( ! empty( $_POST['create_new'] ) ) {
            $display  = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
            $username = sanitize_user( wp_unslash( $_POST['username'] ?? '' ) );
            $email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
            $password = wp_unslash( $_POST['password'] ?? '' );

            if ( username_exists($username) ) wp_send_json_error( 'Username đã tồn tại.' );
            if ( email_exists($email) )       wp_send_json_error( 'Email đã tồn tại.' );

            $user_id = wp_create_user( $username, $password, $email );
            if ( is_wp_error($user_id) ) wp_send_json_error( $user_id->get_error_message() );

            wp_update_user( [ 'ID' => $user_id, 'display_name' => $display ] );
        } else {
            $user_id = absint( $_POST['user_id'] ?? 0 );
        }

        if ( ! $user_id ) wp_send_json_error( 'User ID không hợp lệ' );

        $user = new \WP_User( $user_id );
        self::ensure_role_exists();
        $user->set_role( self::ROLE_SLUG );

        wp_send_json_success( 'Đã cấp quyền' );
    }

    public function ajax_remove_user_role(): void {
        check_ajax_referer( 'rba_pms_staff_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $user_id = absint( $_POST['user_id'] ?? 0 );
        if ( ! $user_id ) wp_send_json_error( 'User ID không hợp lệ' );

        $user = new \WP_User( $user_id );
        $user->remove_role( self::ROLE_SLUG );
        // Không xóa user — chỉ xóa role

        wp_send_json_success( 'Đã xóa quyền' );
    }
}

new RBA_PMS_Role();
