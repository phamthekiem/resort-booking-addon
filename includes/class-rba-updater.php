<?php
/**
 * RBA_Updater — GitHub Auto-Update System
 *
 * Cơ chế hoạt động:
 *  1. WordPress định kỳ check update cho tất cả plugins
 *  2. Filter "pre_set_site_transient_update_plugins" → plugin chen vào
 *  3. Gọi GitHub API lấy latest release tag
 *  4. So sánh version → nếu mới hơn → thêm vào update queue
 *  5. Admin thấy nút "Update" như plugin thương mại
 *  6. WordPress tự download .zip từ GitHub Release → cài đặt
 *
 * HỖ TRỢ 2 CHẾ ĐỘ:
 *  - Public repo  : Không cần token, gọi API trực tiếp
 *  - Private repo : Cần GitHub Personal Access Token (PAT) với scope "repo"
 *
 * GITHUB RELEASE FORMAT:
 *  Tag    : v1.4.1  (hoặc 1.4.1 — tự động normalize)
 *  Asset  : resort-booking-addon-v1.4.1.zip  (phải đúng tên)
 *  Body   : Changelog markdown (hiển thị trong WordPress update screen)
 *
 * @package ResortBookingAddon
 * @since   1.4.1
 */
defined( 'ABSPATH' ) || exit;

class RBA_Updater {

    const OPTION_KEY   = 'rba_updater_config';
    const CACHE_KEY    = 'rba_github_release_cache';
    const CACHE_TTL    = 6 * HOUR_IN_SECONDS;     // Cache 6 giờ, tránh rate limit
    const GITHUB_API   = 'https://api.github.com';

    /** @var string Đường dẫn tới file plugin chính (plugin-slug/plugin-file.php) */
    private string $plugin_file;

    /** @var string Slug WordPress (tên thư mục/tên file) */
    private string $plugin_slug;

    /** @var string GitHub username/org */
    private string $github_user;

    /** @var string GitHub repo name */
    private string $github_repo;

    /** @var string Version đang cài đặt */
    private string $current_version;

    /** @var string GitHub PAT (cho private repo, optional) */
    private string $github_token;

    public function __construct(
        string $plugin_file,
        string $github_user,
        string $github_repo,
        string $current_version,
        string $github_token = ''
    ) {
        $this->plugin_file      = $plugin_file;
        $this->plugin_slug      = plugin_basename( $plugin_file );
        $this->github_user      = $github_user;
        $this->github_repo      = $github_repo;
        $this->current_version  = $current_version;
        $this->github_token     = $github_token ?: (string) get_option( 'rba_github_token', '' );

        // ── Hooks ─────────────────────────────────────────────────────────────
        // Check update (WordPress định kỳ gọi)
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );

        // Cung cấp plugin info khi click "View details"
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );

        // Sau khi update xong → xóa cache
        add_action( 'upgrader_process_complete', [ $this, 'clear_cache_after_update' ], 10, 2 );

        // Thêm link "Check for updates" trong plugin list
        add_filter( 'plugin_action_links_' . $this->plugin_slug, [ $this, 'add_action_links' ] );

        // AJAX: force check update
        add_action( 'wp_ajax_rba_check_update_now', [ $this, 'ajax_check_update_now' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CORE: Fetch release info từ GitHub
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lấy thông tin latest release từ GitHub API.
     * Cache 6 giờ để tránh rate limit (60 req/giờ với unauthenticated).
     *
     * @param bool $force_refresh Bỏ qua cache, fetch mới
     * @return array|null Release data hoặc null nếu lỗi
     */
    public function get_latest_release( bool $force_refresh = false ): ?array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( $cached !== false ) {
                return $cached ?: null;
            }
        }

        $url  = sprintf( '%s/repos/%s/%s/releases/latest', self::GITHUB_API, $this->github_user, $this->github_repo );
        $args = [
            'timeout'    => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            'headers'    => [ 'Accept' => 'application/vnd.github.v3+json' ],
        ];

        // Thêm auth header cho private repo
        if ( $this->github_token ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->github_token;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            set_transient( self::CACHE_KEY, [], 15 * MINUTE_IN_SECONDS ); // Cache lỗi 15 phút
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['tag_name'] ) ) {
            set_transient( self::CACHE_KEY, [], 15 * MINUTE_IN_SECONDS );
            return null;
        }

        // Tìm .zip asset đúng tên
        $zip_url = $this->find_zip_asset( $body['assets'] ?? [], $body['tag_name'] );

        // Fallback: dùng zipball_url của GitHub (tự generate)
        if ( ! $zip_url ) {
            $zip_url = $body['zipball_url'] ?? '';
        }

        $release = [
            'version'      => ltrim( $body['tag_name'], 'v' ),  // "v1.4.1" → "1.4.1"
            'tag_name'     => $body['tag_name'],
            'download_url' => $zip_url,
            'body'         => $body['body'] ?? '',               // Changelog markdown
            'published_at' => $body['published_at'] ?? '',
            'html_url'     => $body['html_url'] ?? '',
        ];

        set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
        return $release;
    }

    /**
     * Tìm .zip asset đúng tên trong danh sách assets của release.
     * GitHub Release cho phép upload nhiều files — cần tìm đúng .zip của plugin.
     */
    private function find_zip_asset( array $assets, string $tag ): string {
        $version      = ltrim( $tag, 'v' );
        $repo_name    = $this->github_repo;
        $expected_names = [
            "{$repo_name}-v{$version}.zip",
            "{$repo_name}-{$version}.zip",
            "{$repo_name}.zip",
        ];

        foreach ( $assets as $asset ) {
            if ( ! isset( $asset['name'], $asset['browser_download_url'] ) ) continue;
            if ( $asset['content_type'] !== 'application/zip' && ! str_ends_with( $asset['name'], '.zip' ) ) continue;

            // Match tên chính xác
            if ( in_array( $asset['name'], $expected_names, true ) ) {
                return $asset['browser_download_url'];
            }
        }

        // Fallback: lấy .zip đầu tiên tìm thấy
        foreach ( $assets as $asset ) {
            if ( str_ends_with( $asset['name'] ?? '', '.zip' ) ) {
                return $asset['browser_download_url'];
            }
        }

        return '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WORDPRESS HOOKS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Hook: pre_set_site_transient_update_plugins
     * WordPress gọi filter này khi check update cho tất cả plugins.
     * Nếu có version mới → thêm vào $transient->response để WordPress hiển thị nút "Update".
     */
    public function check_for_update( object $transient ): object {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        // So sánh version
        if ( version_compare( $release['version'], $this->current_version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) [
                'id'          => $this->plugin_slug,
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $release['version'],
                'url'         => $release['html_url'],
                'package'     => $release['download_url'],
                'icons'       => [],
                'banners'     => [],
                'tested'      => get_bloginfo('version'),
                'requires_php'=> '8.0',
                'compatibility'=> new \stdClass(),
            ];
        } else {
            // Không có update → đảm bảo plugin không bị xóa khỏi no_update list
            if ( ! isset( $transient->no_update[ $this->plugin_slug ] ) ) {
                $transient->no_update[ $this->plugin_slug ] = (object) [
                    'id'          => $this->plugin_slug,
                    'slug'        => dirname( $this->plugin_slug ),
                    'plugin'      => $this->plugin_slug,
                    'new_version' => $this->current_version,
                    'url'         => $release['html_url'],
                    'package'     => '',
                ];
            }
        }

        return $transient;
    }

    /**
     * Hook: plugins_api
     * Cung cấp thông tin plugin khi user click "View details" trong update screen.
     * Hiển thị changelog, screenshots, thông tin tương thích.
     */
    public function plugin_info( $result, string $action, object $args ): mixed {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        // Chỉ xử lý plugin của mình
        if ( ( $args->slug ?? '' ) !== dirname( $this->plugin_slug ) ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        // Convert markdown changelog → HTML đơn giản
        $changelog_html = $this->markdown_to_html( $release['body'] );

        return (object) [
            'name'              => 'Resort Booking Addon for Tourfic',
            'slug'              => dirname( $this->plugin_slug ),
            'version'           => $release['version'],
            'author'            => '<a href="https://github.com/' . esc_attr( $this->github_user ) . '">' . esc_html( $this->github_user ) . '</a>',
            'author_profile'    => 'https://github.com/' . $this->github_user,
            'homepage'          => 'https://github.com/' . $this->github_user . '/' . $this->github_repo,
            'short_description' => 'Mở rộng Tourfic Free: 25 phòng, giá theo mùa, iCal OTA sync, KiotViet, Google Calendar bridge.',
            'sections'          => [
                'description' => '<p>Plugin mở rộng Tourfic Free cho resort vừa và nhỏ. Hỗ trợ đầy đủ: giá theo mùa, OTA iCal sync 2 chiều, chống double booking, tour nội khu, KiotViet Hotel integration, Google Calendar bridge.</p>',
                'changelog'   => $changelog_html ?: '<p>Xem changelog tại <a href="' . esc_url( $release['html_url'] ) . '" target="_blank">GitHub Releases</a>.</p>',
                'installation'=> '<ol><li>Upload và activate plugin</li><li>Vào Settings → Permalinks → Save Changes</li><li>Cấu hình theo hướng dẫn trong Resort Booking → Dashboard</li></ol>',
            ],
            'download_link'     => $release['download_url'],
            'last_updated'      => $release['published_at'],
            'requires'          => '6.0',
            'tested'            => get_bloginfo('version'),
            'requires_php'      => '8.0',
            'compatibility'     => new \stdClass(),
        ];
    }

    /**
     * Sau khi update xong → xóa cache để lần sau fetch fresh.
     */
    public function clear_cache_after_update( \WP_Upgrader $upgrader, array $hook_extra ): void {
        if ( ( $hook_extra['action'] ?? '' ) === 'update' && ( $hook_extra['type'] ?? '' ) === 'plugin' ) {
            $plugins = $hook_extra['plugins'] ?? [];
            if ( in_array( $this->plugin_slug, $plugins, true ) ) {
                delete_transient( self::CACHE_KEY );
            }
        }
    }

    /**
     * Thêm link "Check for updates" và "Settings" vào plugin list.
     */
    public function add_action_links( array $links ): array {
        $custom = [
            '<a href="' . esc_url( admin_url( 'admin.php?page=rba-dashboard' ) ) . '">Settings</a>',
            '<a href="#" class="rba-check-update" data-nonce="' . esc_attr( wp_create_nonce('rba_check_update_now') ) . '">Check for updates</a>',
        ];

        // Thêm script nhỏ cho "Check for updates" link
        add_action( 'admin_footer', function () {
            ?>
            <script>
            document.querySelectorAll('.rba-check-update').forEach(function(el){
                el.addEventListener('click', function(e){
                    e.preventDefault();
                    el.textContent = 'Checking...';
                    const fd = new FormData();
                    fd.append('action', 'rba_check_update_now');
                    fd.append('nonce', el.dataset.nonce);
                    fetch(ajaxurl, { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(r => {
                            if (r.success) {
                                el.textContent = r.data.has_update ? '⬆ Update available: v' + r.data.version : '✔ Up to date';
                                if (r.data.has_update) location.reload();
                            } else {
                                el.textContent = 'Check for updates';
                            }
                        });
                });
            });
            </script>
            <?php
        } );

        return array_merge( $custom, $links );
    }

    /**
     * AJAX: Force check update (xóa cache + fetch mới).
     */
    public function ajax_check_update_now(): void {
        check_ajax_referer( 'rba_check_update_now', 'nonce' );
        if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( 'Unauthorized' );

        delete_transient( self::CACHE_KEY );
        $release = $this->get_latest_release( true );

        if ( ! $release ) {
            wp_send_json_error( 'Cannot reach GitHub API.' );
        }

        $has_update = version_compare( $release['version'], $this->current_version, '>' );

        // Nếu có update → invalidate WordPress update transient để WordPress rebuild
        if ( $has_update ) {
            delete_site_transient( 'update_plugins' );
        }

        wp_send_json_success( [
            'has_update'      => $has_update,
            'version'         => $release['version'],
            'current_version' => $this->current_version,
            'download_url'    => $release['download_url'],
            'changelog_url'   => $release['html_url'],
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert markdown đơn giản → HTML (cho changelog display).
     * Không cần library ngoài — chỉ xử lý các pattern phổ biến trong changelog.
     */
    private function markdown_to_html( string $md ): string {
        if ( ! $md ) return '';

        // Escape HTML trước
        $html = esc_html( $md );

        // Headers
        $html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
        $html = preg_replace( '/^## (.+)$/m',  '<h3>$1</h3>', $html );
        $html = preg_replace( '/^# (.+)$/m',   '<h2>$1</h2>', $html );

        // Bold, italic
        $html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
        $html = preg_replace( '/\*(.+?)\*/',     '<em>$1</em>',         $html );

        // Inline code
        $html = preg_replace( '/`(.+?)`/', '<code>$1</code>', $html );

        // Bullet lists
        $html = preg_replace( '/^- (.+)$/m',   '<li>$1</li>', $html );
        $html = preg_replace( '/^  - (.+)$/m', '<li style="margin-left:20px">$1</li>', $html );

        // Newlines → paragraphs
        $html = wpautop( $html );

        return $html;
    }

    /**
     * Static factory: khởi tạo từ config lưu trong WP options.
     * Dùng trong main plugin file.
     */
    public static function init_from_config( string $plugin_file, string $version ): self {
        $config = get_option( self::OPTION_KEY, [] );
        return new self(
            $plugin_file,
            $config['github_user'] ?? '',
            $config['github_repo'] ?? '',
            $version,
            $config['github_token'] ?? ''
        );
    }
}
