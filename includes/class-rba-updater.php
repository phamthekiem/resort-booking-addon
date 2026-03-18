<?php
/**
 * RBA_Updater — GitHub Auto-Update System (v2)
 *
 * Fix các vấn đề phổ biến khiến update không hiển thị:
 *  1. plugin_slug phải là "folder/file.php" — plugin_basename() đôi khi trả về sai
 *     nếu $plugin_file là absolute path trỏ vào symlink hay subfolder khác nhau
 *  2. github_user/repo rỗng → API call tới URL sai → trả về null → silently fail
 *  3. WordPress cache transient update_plugins quá lâu (12h) → không thấy update mới
 *  4. $transient->checked phải chứa plugin_slug thì filter mới chạy đúng
 *
 * @package ResortBookingAddon
 * @since   1.4.2
 */
defined( 'ABSPATH' ) || exit;

class RBA_Updater {

    const CACHE_KEY = 'rba_github_release_cache';
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;
    const GITHUB_API = 'https://api.github.com';

    private string $plugin_file;
    private string $plugin_slug;    // "resort-booking-addon/resort-booking-addon.php"
    private string $plugin_dir;     // "resort-booking-addon"
    private string $github_user;
    private string $github_repo;
    private string $current_version;
    private string $github_token;

    public function __construct(
        string $plugin_file,
        string $github_user,
        string $github_repo,
        string $current_version,
        string $github_token = ''
    ) {
        $this->plugin_file     = $plugin_file;
        $this->github_user     = trim( $github_user );
        $this->github_repo     = trim( $github_repo );
        $this->current_version = trim( $current_version );
        $this->github_token    = trim( $github_token )
                              ?: trim( (string) get_option( 'rba_updater_github_token', '' ) );

        // ── Tính plugin_slug chính xác ────────────────────────────────────────
        // plugin_basename() dùng WP_PLUGIN_DIR để strip prefix.
        // Nếu plugin được symlink hoặc cài vào thư mục bất thường → có thể sai.
        // Dùng cách tính thủ công đáng tin cậy hơn.
        $plugin_dir_name    = basename( dirname( $plugin_file ) );
        $plugin_file_name   = basename( $plugin_file );
        $this->plugin_slug  = $plugin_dir_name . '/' . $plugin_file_name;
        $this->plugin_dir   = $plugin_dir_name;

        // Không khởi tạo nếu thiếu config cơ bản
        if ( ! $this->github_user || ! $this->github_repo ) {
            // Vẫn hook để hiển thị cảnh báo + trang Settings
            add_filter( 'plugin_action_links_' . $this->plugin_slug, [ $this, 'add_action_links' ] );
            add_action( 'wp_ajax_rba_check_update_now', [ $this, 'ajax_check_update_now' ] );
            add_action( 'wp_ajax_rba_save_github_config', [ $this, 'ajax_save_github_config_direct' ] );
            return;
        }

        // ── Hooks ─────────────────────────────────────────────────────────────
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',  [ $this, 'plugin_info' ], 20, 3 );
        add_action( 'upgrader_process_complete', [ $this, 'clear_cache_after_update' ], 10, 2 );
        add_filter( 'plugin_action_links_' . $this->plugin_slug, [ $this, 'add_action_links' ] );
        add_action( 'wp_ajax_rba_check_update_now', [ $this, 'ajax_check_update_now' ] );
        add_action( 'wp_ajax_rba_save_github_config', [ $this, 'ajax_save_github_config_direct' ] );
    }

    // =========================================================================
    // GITHUB API
    // =========================================================================

    public function get_latest_release( bool $force = false ): ?array {
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( false !== $cached ) {
                return $cached ?: null;
            }
        }

        if ( ! $this->github_user || ! $this->github_repo ) {
            return null;
        }

        $url  = self::GITHUB_API . "/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
        $args = [
            'timeout'    => 15,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            'headers'    => [ 'Accept' => 'application/vnd.github.v3+json' ],
        ];
        if ( $this->github_token ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->github_token;
        }

        $response = wp_remote_get( $url, $args );

        // Log lỗi để debug
        if ( is_wp_error( $response ) ) {
            $this->log( 'GitHub API error: ' . $response->get_error_message() );
            set_transient( self::CACHE_KEY, [], 15 * MINUTE_IN_SECONDS );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['message'] ?? "HTTP {$code}";
            $this->log( "GitHub API HTTP {$code}: {$msg}" );
            // 404 = không có release nào, 401/403 = token sai
            set_transient( self::CACHE_KEY, [], 15 * MINUTE_IN_SECONDS );
            return null;
        }

        if ( empty( $body['tag_name'] ) ) {
            $this->log( 'GitHub response thiếu tag_name' );
            set_transient( self::CACHE_KEY, [], 15 * MINUTE_IN_SECONDS );
            return null;
        }

        $zip_url = $this->find_zip_asset( $body['assets'] ?? [], $body['tag_name'] );
        if ( ! $zip_url ) {
            // Fallback: zipball (GitHub tự generate, tên thư mục bên trong có thể khác)
            $zip_url = $body['zipball_url'] ?? '';
            if ( $zip_url && $this->github_token ) {
                // Thêm token vào URL để download private asset
                $zip_url = add_query_arg( 'token', $this->github_token, $zip_url );
            }
        }

        $release = [
            'version'      => ltrim( $body['tag_name'], 'v' ),
            'tag_name'     => $body['tag_name'],
            'download_url' => $zip_url,
            'body'         => $body['body'] ?? '',
            'published_at' => $body['published_at'] ?? '',
            'html_url'     => $body['html_url'] ?? '',
        ];

        $this->log( "Fetched release: {$release['tag_name']} (version {$release['version']}), download: " . ( $zip_url ? 'OK' : 'MISSING' ) );
        set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
        return $release;
    }

    private function find_zip_asset( array $assets, string $tag ): string {
        $version = ltrim( $tag, 'v' );
        $repo    = $this->github_repo;

        // Tên file ưu tiên theo thứ tự
        $expected = [
            "{$repo}-v{$version}.zip",
            "{$repo}-{$version}.zip",
            "{$repo}.zip",
        ];

        foreach ( $assets as $asset ) {
            if ( ! isset( $asset['browser_download_url'] ) ) continue;
            if ( ! str_ends_with( $asset['name'] ?? '', '.zip' ) ) continue;
            if ( in_array( $asset['name'], $expected, true ) ) {
                return $asset['browser_download_url'];
            }
        }

        // Fallback: zip đầu tiên tìm thấy
        foreach ( $assets as $asset ) {
            if ( str_ends_with( $asset['name'] ?? '', '.zip' ) ) {
                return $asset['browser_download_url'];
            }
        }

        return '';
    }

    // =========================================================================
    // WORDPRESS UPDATE HOOKS
    // =========================================================================

    public function check_for_update( object $transient ): object {
        // WordPress chỉ set $transient->checked sau khi quét tất cả plugin headers
        // Nếu chưa có → return sớm
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        // Kiểm tra plugin_slug có trong checked list không
        // Nếu không có → WordPress chưa nhận ra plugin này → plugin_slug sai
        if ( ! array_key_exists( $this->plugin_slug, $transient->checked ) ) {
            $this->log( "WARN: plugin_slug '{$this->plugin_slug}' không có trong transient->checked. Các slugs hiện có: " . implode( ', ', array_keys( $transient->checked ) ) );
            // Vẫn tiếp tục — WordPress vẫn hiển thị update dù không có trong checked
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $this->log( "check_for_update: current={$this->current_version}, latest={$release['version']}" );

        if ( version_compare( $release['version'], $this->current_version, '>' ) ) {
            $this->log( "Update available: {$this->current_version} → {$release['version']}" );

            $transient->response[ $this->plugin_slug ] = (object) [
                'id'           => $this->plugin_slug,
                'slug'         => $this->plugin_dir,
                'plugin'       => $this->plugin_slug,
                'new_version'  => $release['version'],
                'url'          => $release['html_url'],
                'package'      => $release['download_url'],
                'icons'        => [],
                'banners'      => [],
                'tested'       => get_bloginfo( 'version' ),
                'requires_php' => '8.0',
                'compatibility' => new \stdClass(),
            ];
        } else {
            $this->log( "No update: current={$this->current_version} >= latest={$release['version']}" );

            if ( ! isset( $transient->no_update[ $this->plugin_slug ] ) ) {
                $transient->no_update[ $this->plugin_slug ] = (object) [
                    'id'          => $this->plugin_slug,
                    'slug'        => $this->plugin_dir,
                    'plugin'      => $this->plugin_slug,
                    'new_version' => $this->current_version,
                    'url'         => $release['html_url'],
                    'package'     => '',
                ];
            }
        }

        return $transient;
    }

    public function plugin_info( $result, string $action, object $args ): mixed {
        if ( 'plugin_information' !== $action ) return $result;
        if ( ( $args->slug ?? '' ) !== $this->plugin_dir ) return $result;

        $release = $this->get_latest_release();
        if ( ! $release ) return $result;

        return (object) [
            'name'           => 'Resort Booking Addon for Tourfic',
            'slug'           => $this->plugin_dir,
            'version'        => $release['version'],
            'author'         => '<a href="https://github.com/' . esc_attr( $this->github_user ) . '">' . esc_html( $this->github_user ) . '</a>',
            'homepage'       => 'https://github.com/' . $this->github_user . '/' . $this->github_repo,
            'short_description' => 'Mở rộng Tourfic Free cho resort: giá theo mùa, OTA iCal sync, KiotViet, Google Calendar bridge.',
            'sections'       => [
                'description' => '<p>Plugin mở rộng Tourfic Free cho resort vừa và nhỏ.</p>',
                'changelog'   => $this->markdown_to_html( $release['body'] )
                              ?: '<p><a href="' . esc_url( $release['html_url'] ) . '" target="_blank">Xem changelog trên GitHub</a></p>',
            ],
            'download_link'  => $release['download_url'],
            'last_updated'   => $release['published_at'],
            'requires'       => '6.0',
            'tested'         => get_bloginfo( 'version' ),
            'requires_php'   => '8.0',
            'compatibility'  => new \stdClass(),
        ];
    }

    public function clear_cache_after_update( \WP_Upgrader $upgrader, array $hook_extra ): void {
        if ( 'update' === ( $hook_extra['action'] ?? '' ) && 'plugin' === ( $hook_extra['type'] ?? '' ) ) {
            if ( in_array( $this->plugin_slug, (array) ( $hook_extra['plugins'] ?? [] ), true ) ) {
                delete_transient( self::CACHE_KEY );
            }
        }
    }

    // =========================================================================
    // PLUGIN ACTION LINKS
    // =========================================================================

    public function add_action_links( array $links ): array {
        $nonce = wp_create_nonce( 'rba_check_update_now' );

        if ( ! $this->github_user || ! $this->github_repo ) {
            // Chưa cấu hình → hiển thị link Setup
            array_unshift( $links,
                '<a href="' . esc_url( admin_url( 'admin.php?page=rba-update-settings' ) ) . '" style="color:#e65100">⚙ Setup GitHub Update</a>'
            );
            return $links;
        }

        $custom = [
            '<a href="' . esc_url( admin_url( 'admin.php?page=rba-update-settings' ) ) . '">Update Settings</a>',
            '<a href="#" class="rba-check-update" data-nonce="' . esc_attr( $nonce ) . '">Check for updates</a>',
        ];

        // Inline script cho "Check for updates" (chỉ inject 1 lần)
        static $script_injected = false;
        if ( ! $script_injected ) {
            $script_injected = true;
            add_action( 'admin_footer', [ $this, 'print_check_update_script' ] );
        }

        return array_merge( $custom, $links );
    }

    public function print_check_update_script(): void {
        ?>
        <script>
        document.querySelectorAll('.rba-check-update').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                const orig = el.textContent;
                el.textContent = 'Checking...';
                const fd = new FormData();
                fd.append('action', 'rba_check_update_now');
                fd.append('nonce', el.dataset.nonce);
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(r => {
                        if (r.success) {
                            if (r.data.has_update) {
                                el.textContent = '⬆ Update v' + r.data.version;
                                el.style.color = '#e65100';
                                // Reload để WordPress hiện nút Update
                                setTimeout(() => location.reload(), 800);
                            } else {
                                el.textContent = '✔ Up to date';
                                el.style.color = '#2e7d32';
                                setTimeout(() => { el.textContent = orig; el.style.color = ''; }, 3000);
                            }
                        } else {
                            el.textContent = '✘ ' + (r.data || 'Error');
                            el.style.color = '#c62828';
                            setTimeout(() => { el.textContent = orig; el.style.color = ''; }, 4000);
                        }
                    });
            });
        });
        </script>
        <?php
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    public function ajax_check_update_now(): void {
        check_ajax_referer( 'rba_check_update_now', 'nonce' );
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Force fetch mới từ GitHub
        delete_transient( self::CACHE_KEY );
        $release = $this->get_latest_release( true );

        if ( ! $release ) {
            // Trả về debug info để admin biết tại sao thất bại
            wp_send_json_error( $this->get_debug_info() );
        }

        $has_update = version_compare( $release['version'], $this->current_version, '>' );

        if ( $has_update ) {
            // Xóa WordPress update transient → force rebuild
            delete_site_transient( 'update_plugins' );
        }

        wp_send_json_success( [
            'has_update'      => $has_update,
            'version'         => $release['version'],
            'current_version' => $this->current_version,
            'download_url'    => $release['download_url'],
            'html_url'        => $release['html_url'],
            'debug'           => $this->get_debug_info(),
        ] );
    }

    /**
     * AJAX lưu config trực tiếp (không qua settings_fields).
     * Dùng từ trang Update Settings.
     */
    public function ajax_save_github_config_direct(): void {
        check_ajax_referer( 'rba_save_github_config', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $user  = sanitize_text_field( wp_unslash( $_POST['user']  ?? '' ) );
        $repo  = sanitize_text_field( wp_unslash( $_POST['repo']  ?? '' ) );
        $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );

        update_option( 'rba_updater_github_user',  $user );
        update_option( 'rba_updater_github_repo',  $repo );
        update_option( 'rba_updater_github_token', $token );

        // Xóa caches
        delete_transient( self::CACHE_KEY );
        delete_site_transient( 'update_plugins' );

        wp_send_json_success( [
            'message'     => 'Đã lưu. Đang check update...',
            'plugin_slug' => $this->plugin_slug,
            'github_url'  => "https://github.com/{$user}/{$repo}",
        ] );
    }

    // =========================================================================
    // DEBUG & HELPERS
    // =========================================================================

    /**
     * Trả về thông tin debug — hiển thị trong Update Settings để admin tự kiểm tra.
     */
    public function get_debug_info(): array {
        $cached = get_transient( self::CACHE_KEY );
        return [
            'plugin_slug'     => $this->plugin_slug,
            'plugin_dir'      => $this->plugin_dir,
            'current_version' => $this->current_version,
            'github_user'     => $this->github_user ?: '(chưa điền)',
            'github_repo'     => $this->github_repo ?: '(chưa điền)',
            'has_token'       => ! empty( $this->github_token ),
            'api_url'         => $this->github_user && $this->github_repo
                ? self::GITHUB_API . "/repos/{$this->github_user}/{$this->github_repo}/releases/latest"
                : '(cần điền user và repo trước)',
            'cache_status'    => $cached === false ? 'empty (sẽ fetch mới)' : ( $cached ? 'có data: v' . ( $cached['version'] ?? '?' ) : 'lỗi đã cache' ),
            'wp_plugin_dir'   => WP_PLUGIN_DIR,
            'plugin_file'     => $this->plugin_file,
        ];
    }

    private function markdown_to_html( string $md ): string {
        if ( ! $md ) return '';
        $html = esc_html( $md );
        $html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
        $html = preg_replace( '/^## (.+)$/m',  '<h3>$1</h3>', $html );
        $html = preg_replace( '/^# (.+)$/m',   '<h2>$1</h2>', $html );
        $html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
        $html = preg_replace( '/`(.+?)`/', '<code>$1</code>', $html );
        $html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
        return wpautop( $html );
    }

    private function log( string $msg ): void {
        $logs   = get_option( 'rba_updater_logs', [] );
        $logs[] = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $msg;
        if ( count( $logs ) > 30 ) $logs = array_slice( $logs, -30 );
        update_option( 'rba_updater_logs', $logs, false );
    }
}
