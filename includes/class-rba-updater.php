<?php
/**
 * RBA_Updater — GitHub Auto-Update System (v3)
 *
 * HỖ TRỢ 3 CHẾ ĐỘ — chọn trong Update Settings:
 *
 * MODE 1: "raw_file" (MẶC ĐỊNH, KHUYẾN NGHỊ)
 * ─────────────────────────────────────────────
 * Đọc header "Version:" trực tiếp từ file PHP trên GitHub branch.
 * URL: https://raw.githubusercontent.com/{user}/{repo}/{branch}/{file}.php
 *
 * Workflow: git commit → git push → WordPress nhận update ngay
 * Không cần tạo Release, không cần tag.
 * Download: zipball của branch (GitHub tự generate).
 *
 * MODE 2: "tags"
 * ─────────────────────────────────────────────
 * Đọc tag mới nhất từ GitHub Tags API.
 * Workflow: git tag v1.4.3 && git push origin v1.4.3 → WordPress nhận update
 * Không cần tạo Release trên UI GitHub.
 *
 * MODE 3: "releases" (cũ)
 * ─────────────────────────────────────────────
 * Dùng GitHub Releases API — cần tạo Release thủ công trên GitHub UI
 * hoặc qua GitHub Actions. Phù hợp khi cần release notes + file .zip đính kèm.
 *
 * @package ResortBookingAddon
 * @since   1.4.3
 */
defined( 'ABSPATH' ) || exit;

class RBA_Updater {

    const CACHE_KEY  = 'rba_github_update_cache';
    const CACHE_TTL  = 3 * HOUR_IN_SECONDS;
    const RAW_BASE   = 'https://raw.githubusercontent.com';
    const API_BASE   = 'https://api.github.com';

    // Update modes
    const MODE_RAW_FILE = 'raw_file';   // Đọc Version header từ file PHP
    const MODE_TAGS     = 'tags';       // Dùng Git Tags
    const MODE_RELEASES = 'releases';   // Dùng GitHub Releases (cần tạo release)

    private string $plugin_file;
    private string $plugin_slug;
    private string $plugin_dir;
    private string $github_user;
    private string $github_repo;
    private string $github_branch;
    private string $current_version;
    private string $github_token;
    private string $mode;

    public function __construct(
        string $plugin_file,
        string $github_user,
        string $github_repo,
        string $current_version,
        string $github_token = '',
        string $mode         = ''
    ) {
        $this->plugin_file     = $plugin_file;
        $this->github_user     = trim( $github_user );
        $this->github_repo     = trim( $github_repo );
        $this->current_version = trim( $current_version );
        $this->github_token    = trim( $github_token )
                              ?: trim( (string) get_option( 'rba_updater_github_token', '' ) );
        $this->github_branch   = trim( (string) get_option( 'rba_updater_github_branch', 'main' ) ) ?: 'main';
        $this->mode            = $mode
                              ?: trim( (string) get_option( 'rba_updater_mode', self::MODE_RAW_FILE ) )
                              ?: self::MODE_RAW_FILE;

        // Plugin slug: "resort-booking-addon/resort-booking-addon.php"
        $dir_name          = basename( dirname( $plugin_file ) );
        $file_name         = basename( $plugin_file );
        $this->plugin_slug = $dir_name . '/' . $file_name;
        $this->plugin_dir  = $dir_name;

        if ( ! $this->github_user || ! $this->github_repo ) {
            // Chưa config → chỉ hook action links để hiện nút Setup
            add_filter( 'plugin_action_links_' . $this->plugin_slug, [ $this, 'add_action_links' ] );
            add_action( 'wp_ajax_rba_check_update_now',    [ $this, 'ajax_check_update_now' ] );
            add_action( 'wp_ajax_rba_save_github_config',  [ $this, 'ajax_save_config' ] );
            return;
        }

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',  [ $this, 'plugin_info' ], 20, 3 );
        add_action( 'upgrader_process_complete', [ $this, 'clear_cache_after_update' ], 10, 2 );
        add_filter( 'plugin_action_links_' . $this->plugin_slug, [ $this, 'add_action_links' ] );
        add_action( 'wp_ajax_rba_check_update_now',   [ $this, 'ajax_check_update_now' ] );
        add_action( 'wp_ajax_rba_save_github_config', [ $this, 'ajax_save_config' ] );
    }

    // =========================================================================
    // FETCH UPDATE INFO — tuỳ theo mode
    // =========================================================================

    public function get_update_info( bool $force = false ): ?array {
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( false !== $cached ) return $cached ?: null;
        }

        // $info = match ( $this->mode ) {
        //     self::MODE_RAW_FILE => $this->fetch_via_raw_file(),
        //     self::MODE_TAGS     => $this->fetch_via_tags(),
        //     self::MODE_RELEASES => $this->fetch_via_releases(),
        //     default             => $this->fetch_via_raw_file(),
        // };

        // Cache kết quả (kể cả null → cache [] để tránh spam API)
        set_transient( self::CACHE_KEY, $info ?? [], $info ? self::CACHE_TTL : 15 * MINUTE_IN_SECONDS );

        if ( $info ) {
            $this->log( "Fetched [{$this->mode}]: version={$info['version']}, download=" . ( $info['download_url'] ? 'OK' : 'MISSING' ) );
        }

        return $info;
    }

    // ── MODE 1: Raw file ──────────────────────────────────────────────────────

    /**
     * Đọc "Version:" header trực tiếp từ file PHP trên GitHub.
     *
     * URL format:
     * https://raw.githubusercontent.com/{user}/{repo}/{branch}/{plugin-file}.php
     *
     * Ưu điểm: push code là có update ngay, zero workflow thêm.
     */
    private function fetch_via_raw_file(): ?array {
        $file_name = basename( $this->plugin_file );
        $raw_url   = sprintf(
            '%s/%s/%s/%s/%s',
            self::RAW_BASE,
            $this->github_user,
            $this->github_repo,
            $this->github_branch,
            $file_name
        );

        $response = $this->github_request( $raw_url );
        if ( ! $response ) return null;

        // Parse "Version: X.Y.Z" từ plugin header
        if ( ! preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $response, $matches ) ) {
            $this->log( "raw_file: không tìm thấy Version header trong {$raw_url}" );
            return null;
        }

        $version      = trim( $matches[1] );
        $download_url = $this->build_branch_zip_url();

        return [
            'version'      => $version,
            'download_url' => $download_url,
            'details_url'  => "https://github.com/{$this->github_user}/{$this->github_repo}",
            'changelog'    => '',
            'source'       => 'raw_file',
        ];
    }

    /**
     * URL download zipball của branch — GitHub tự generate, luôn là code mới nhất.
     * Format: https://github.com/{user}/{repo}/archive/refs/heads/{branch}.zip
     *
     * LƯU Ý: Tên thư mục bên trong zip sẽ là "{repo}-{branch}" thay vì "{repo}".
     * WordPress sẽ cài vào thư mục "{repo}-{branch}" → cần rename.
     * Fix: dùng filter upgrader_source_selection (xem bên dưới).
     */
    private function build_branch_zip_url(): string {
        $base = "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/heads/{$this->github_branch}.zip";
        // Với private repo cần thêm token vào header khi download (không thể qua URL)
        // WordPress sẽ dùng WP_Http để download — thêm filter sau
        return $base;
    }

    // ── MODE 2: Tags ──────────────────────────────────────────────────────────

    /**
     * Lấy tag mới nhất từ GitHub Tags API.
     * Push tag là có update — không cần tạo Release trên UI.
     */
    private function fetch_via_tags(): ?array {
        $url      = self::API_BASE . "/repos/{$this->github_user}/{$this->github_repo}/tags?per_page=10";
        $raw      = $this->github_request( $url, true );
        if ( ! $raw ) return null;

        $tags = json_decode( $raw, true );
        if ( empty( $tags ) || ! is_array( $tags ) ) {
            $this->log( 'tags: repo chưa có tag nào' );
            return null;
        }

        // Tìm tag version hợp lệ mới nhất (format vX.Y.Z hoặc X.Y.Z)
        $latest_version = '0.0.0';
        $latest_tag     = null;

        foreach ( $tags as $tag ) {
            $ver = ltrim( $tag['name'] ?? '', 'v' );
            if ( preg_match( '/^\d+\.\d+\.\d+/', $ver ) && version_compare( $ver, $latest_version, '>' ) ) {
                $latest_version = $ver;
                $latest_tag     = $tag;
            }
        }

        if ( ! $latest_tag ) return null;

        // Download URL: zipball của tag commit
        $zip_url = $latest_tag['zipball_url'] ?? sprintf(
            'https://github.com/%s/%s/archive/refs/tags/%s.zip',
            $this->github_user, $this->github_repo, $latest_tag['name']
        );

        return [
            'version'      => $latest_version,
            'download_url' => $zip_url,
            'details_url'  => "https://github.com/{$this->github_user}/{$this->github_repo}/releases/tag/{$latest_tag['name']}",
            'changelog'    => '',
            'source'       => 'tags',
        ];
    }

    // ── MODE 3: Releases (cũ) ────────────────────────────────────────────────

    private function fetch_via_releases(): ?array {
        $url = self::API_BASE . "/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
        $raw = $this->github_request( $url, true );
        if ( ! $raw ) return null;

        $release = json_decode( $raw, true );
        if ( empty( $release['tag_name'] ) ) {
            $this->log( 'releases: không có release nào (404 hoặc repo private)' );
            return null;
        }

        $version = ltrim( $release['tag_name'], 'v' );
        $zip_url = $this->find_zip_asset( $release['assets'] ?? [], $release['tag_name'] )
                ?: ( $release['zipball_url'] ?? '' );

        return [
            'version'      => $version,
            'download_url' => $zip_url,
            'details_url'  => $release['html_url'] ?? '',
            'changelog'    => $release['body'] ?? '',
            'source'       => 'releases',
        ];
    }

    private function find_zip_asset( array $assets, string $tag ): string {
        $version  = ltrim( $tag, 'v' );
        $expected = [
            "{$this->github_repo}-v{$version}.zip",
            "{$this->github_repo}-{$version}.zip",
            "{$this->github_repo}.zip",
        ];
        foreach ( $assets as $asset ) {
            if ( in_array( $asset['name'] ?? '', $expected, true ) ) return $asset['browser_download_url'];
        }
        foreach ( $assets as $asset ) {
            if ( str_ends_with( $asset['name'] ?? '', '.zip' ) ) return $asset['browser_download_url'];
        }
        return '';
    }

    // ── HTTP helper ───────────────────────────────────────────────────────────

    private function github_request( string $url, bool $json = false ): ?string {
        $args = [
            'timeout'    => 15,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            'headers'    => [],
        ];
        if ( $json ) {
            $args['headers']['Accept'] = 'application/vnd.github.v3+json';
        }
        if ( $this->github_token ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->github_token;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log( "HTTP error [{$url}]: " . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code === 404 ) {
            $this->log( "404: {$url} — repo không tồn tại, chưa có release/tag, hoặc repo private (cần token)" );
            return null;
        }
        if ( $code === 401 || $code === 403 ) {
            $this->log( "Auth error {$code}: token sai hoặc thiếu quyền" );
            return null;
        }
        if ( $code !== 200 ) {
            $this->log( "HTTP {$code}: {$url}" );
            return null;
        }

        return $body ?: null;
    }

    // =========================================================================
    // WORDPRESS UPDATE HOOKS
    // =========================================================================

    public function check_for_update( object $transient ): object {
        if ( empty( $transient->checked ) ) return $transient;

        $info = $this->get_update_info();
        if ( ! $info ) return $transient;

        $this->log( "check_for_update: current={$this->current_version}, latest={$info['version']} [{$info['source']}]" );

        if ( version_compare( $info['version'], $this->current_version, '>' ) ) {
            $this->log( "Update available: {$this->current_version} → {$info['version']}" );

            $transient->response[ $this->plugin_slug ] = (object) [
                'id'           => $this->plugin_slug,
                'slug'         => $this->plugin_dir,
                'plugin'       => $this->plugin_slug,
                'new_version'  => $info['version'],
                'url'          => $info['details_url'],
                'package'      => $info['download_url'],
                'icons'        => [],
                'banners'      => [],
                'tested'       => get_bloginfo( 'version' ),
                'requires_php' => '8.0',
                'compatibility' => new \stdClass(),
            ];

            // MODE raw_file: tên thư mục trong zip là "{repo}-{branch}" → cần rename
            if ( self::MODE_RAW_FILE === $this->mode || self::MODE_TAGS === $this->mode ) {
                add_filter( 'upgrader_source_selection', [ $this, 'fix_zip_folder_name' ], 10, 4 );
            }
        } else {
            if ( ! isset( $transient->no_update[ $this->plugin_slug ] ) ) {
                $transient->no_update[ $this->plugin_slug ] = (object) [
                    'id'          => $this->plugin_slug,
                    'slug'        => $this->plugin_dir,
                    'plugin'      => $this->plugin_slug,
                    'new_version' => $this->current_version,
                    'url'         => $info['details_url'],
                    'package'     => '',
                ];
            }
        }

        return $transient;
    }

    /**
     * Fix tên thư mục sau khi WordPress giải nén zip từ GitHub branch/tag.
     *
     * GitHub archive zip có cấu trúc bên trong:
     *   {repo}-{branch}/    hoặc   {repo}-{commit-sha}/
     *
     * WordPress cần:
     *   {plugin-dir}/       tức là   resort-booking-addon/
     *
     * Filter này rename thư mục tạm thành đúng tên trước khi WordPress copy vào plugins.
     */
    public function fix_zip_folder_name( string $source, string $remote_source, \WP_Upgrader $upgrader, array $hook_extra ): string {
        // Chỉ xử lý khi đang update plugin này
        if ( ( $hook_extra['plugin'] ?? '' ) !== $this->plugin_slug ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) return $source;

        $path_parts = explode( '/', untrailingslashit( $source ) );
        $folder     = end( $path_parts );

        // Kiểm tra: tên thư mục có bắt đầu bằng "{repo}-" không
        if ( str_starts_with( $folder, $this->github_repo . '-' ) ) {
            $new_source = str_replace( $folder, $this->plugin_dir, $source );
            if ( $wp_filesystem->move( $source, $new_source ) ) {
                $this->log( "Renamed zip folder: {$folder} → {$this->plugin_dir}" );
                return $new_source;
            }
        }

        return $source;
    }

    public function plugin_info( $result, string $action, object $args ): mixed {
        if ( 'plugin_information' !== $action ) return $result;
        if ( ( $args->slug ?? '' ) !== $this->plugin_dir ) return $result;

        $info = $this->get_update_info();
        if ( ! $info ) return $result;

        $changelog = $this->markdown_to_html( $info['changelog'] )
                  ?: '<p><a href="' . esc_url( $info['details_url'] ) . '" target="_blank">Xem trên GitHub →</a></p>';

        return (object) [
            'name'              => 'Resort Booking Addon for Tourfic',
            'slug'              => $this->plugin_dir,
            'version'           => $info['version'],
            'author'            => '<a href="https://github.com/' . esc_attr( $this->github_user ) . '">' . esc_html( $this->github_user ) . '</a>',
            'homepage'          => "https://github.com/{$this->github_user}/{$this->github_repo}",
            'short_description' => 'Mở rộng Tourfic Free: giá theo mùa, OTA sync, KiotViet, Google Calendar.',
            'sections'          => [
                'description' => '<p>Plugin mở rộng Tourfic Free cho resort vừa và nhỏ.</p>',
                'changelog'   => $changelog,
            ],
            'download_link'  => $info['download_url'],
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
    // ACTION LINKS + AJAX
    // =========================================================================

    public function add_action_links( array $links ): array {
        $nonce = wp_create_nonce( 'rba_check_update_now' );

        if ( ! $this->github_user || ! $this->github_repo ) {
            array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=rba-update-settings' ) ) . '" style="color:#e65100">⚙ Setup GitHub Update</a>' );
            return $links;
        }

        array_unshift( $links,
            '<a href="' . esc_url( admin_url( 'admin.php?page=rba-update-settings' ) ) . '">Update Settings</a>',
            '<a href="#" class="rba-check-update" data-nonce="' . esc_attr( $nonce ) . '">Check for updates</a>'
        );

        static $script_printed = false;
        if ( ! $script_printed ) {
            $script_printed = true;
            add_action( 'admin_footer', [ $this, 'print_check_update_script' ] );
        }

        return $links;
    }

    public function print_check_update_script(): void { ?>
        <script>
        document.querySelectorAll('.rba-check-update').forEach(el => {
            el.addEventListener('click', e => {
                e.preventDefault();
                const orig = el.textContent;
                el.textContent = 'Checking...';
                const fd = new FormData();
                fd.append('action', 'rba_check_update_now');
                fd.append('nonce', el.dataset.nonce);
                fetch(ajaxurl, { method: 'POST', body: fd }).then(r => r.json()).then(r => {
                    if (r.success && r.data.has_update) {
                        el.textContent = '⬆ v' + r.data.version;
                        el.style.color = '#e65100';
                        setTimeout(() => location.reload(), 600);
                    } else if (r.success) {
                        el.textContent = '✔ Up to date';
                        el.style.color = '#2e7d32';
                        setTimeout(() => { el.textContent = orig; el.style.color = ''; }, 3000);
                    } else {
                        el.textContent = '✘ Error';
                        el.style.color = '#c62828';
                        setTimeout(() => { el.textContent = orig; el.style.color = ''; }, 4000);
                    }
                });
            });
        });
        </script>
    <?php }

    public function ajax_check_update_now(): void {
        check_ajax_referer( 'rba_check_update_now', 'nonce' );
        if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( 'Unauthorized' );

        delete_transient( self::CACHE_KEY );
        $info = $this->get_update_info( true );

        if ( ! $info ) {
            wp_send_json_error( $this->get_debug_info() );
        }

        $has_update = version_compare( $info['version'], $this->current_version, '>' );
        if ( $has_update ) delete_site_transient( 'update_plugins' );

        wp_send_json_success( [
            'has_update'      => $has_update,
            'version'         => $info['version'],
            'current_version' => $this->current_version,
            'source'          => $info['source'],
            'download_url'    => $info['download_url'],
            'debug'           => $this->get_debug_info(),
        ] );
    }

    public function ajax_save_config(): void {
        check_ajax_referer( 'rba_save_github_config', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        update_option( 'rba_updater_github_user',   sanitize_text_field( wp_unslash( $_POST['user']   ?? '' ) ) );
        update_option( 'rba_updater_github_repo',   sanitize_text_field( wp_unslash( $_POST['repo']   ?? '' ) ) );
        update_option( 'rba_updater_github_token',  sanitize_text_field( wp_unslash( $_POST['token']  ?? '' ) ) );
        update_option( 'rba_updater_github_branch', sanitize_text_field( wp_unslash( $_POST['branch'] ?? 'main' ) ) );
        update_option( 'rba_updater_mode',          sanitize_key( $_POST['mode'] ?? self::MODE_RAW_FILE ) );

        delete_transient( self::CACHE_KEY );
        delete_site_transient( 'update_plugins' );

        wp_send_json_success( [
            'message' => 'Đã lưu.',
            'debug'   => $this->get_debug_info(),
        ] );
    }

    // =========================================================================
    // DEBUG + HELPERS
    // =========================================================================

    public function get_debug_info(): array {
        $cached = get_transient( self::CACHE_KEY );
        return [
            'plugin_slug'     => $this->plugin_slug,
            'current_version' => $this->current_version,
            'mode'            => $this->mode,
            'github_user'     => $this->github_user ?: '(chưa điền)',
            'github_repo'     => $this->github_repo ?: '(chưa điền)',
            'github_branch'   => $this->github_branch,
            'has_token'       => ! empty( $this->github_token ),
            'check_url'       => $this->get_check_url(),
            'download_url'    => $this->mode === self::MODE_RAW_FILE ? $this->build_branch_zip_url() : '(từ API)',
            'cache'           => $cached === false ? 'trống' : ( $cached ? 'v' . ( $cached['version'] ?? '?' ) . ' [' . ( $cached['source'] ?? '?' ) . ']' : 'lỗi' ),
        ];
    }

    // private function get_check_url(): string {
    //     if ( ! $this->github_user || ! $this->github_repo ) return '(cần điền user và repo)';
    //     return match ( $this->mode ) {
    //         self::MODE_RAW_FILE => self::RAW_BASE . "/{$this->github_user}/{$this->github_repo}/{$this->github_branch}/" . basename( $this->plugin_file ),
    //         self::MODE_TAGS     => self::API_BASE . "/repos/{$this->github_user}/{$this->github_repo}/tags",
    //         self::MODE_RELEASES => self::API_BASE . "/repos/{$this->github_user}/{$this->github_repo}/releases/latest",
    //         default             => '',
    //     };
    // }

    private function markdown_to_html( string $md ): string {
        if ( ! $md ) return '';
        $h = esc_html( $md );
        $h = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $h );
        $h = preg_replace( '/^## (.+)$/m',  '<h3>$1</h3>', $h );
        $h = preg_replace( '/^# (.+)$/m',   '<h2>$1</h2>', $h );
        $h = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $h );
        $h = preg_replace( '/`(.+?)`/', '<code>$1</code>', $h );
        $h = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $h );
        return wpautop( $h );
    }

    private function log( string $msg ): void {
        $logs   = get_option( 'rba_updater_logs', [] );
        $logs[] = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $msg;
        if ( count( $logs ) > 30 ) $logs = array_slice( $logs, -30 );
        update_option( 'rba_updater_logs', $logs, false );
    }
}
