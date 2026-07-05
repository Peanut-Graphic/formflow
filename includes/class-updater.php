<?php
/**
 * Plugin Updater
 *
 * Handles automatic updates from Peanut License Server at peanutgraphic.com.
 *
 * @package FormFlow
 */

namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Updater
 *
 * Checks for plugin updates from peanutgraphic.com self-hosted update server.
 */
class Updater {

    /**
     * Plugin slug
     */
    const PLUGIN_SLUG = 'formflow';

    /**
     * Plugin file path (relative to plugins directory)
     */
    const PLUGIN_FILE = 'formflow/formflow.php';

    /**
     * API base URL
     */
    const API_URL = 'https://peanutgraphic.com/wp-json/peanut-api/v1';

    /**
     * Current version
     */
    private string $version;

    /**
     * Cache key for update data
     */
    private string $cache_key = 'formflow_update_data';

    /**
     * Cache expiration in seconds (12 hours)
     */
    private int $cache_expiration = 43200;

    /**
     * Constructor
     */
    public function __construct() {
        $this->version = ISF_VERSION;
    }

    /**
     * Initialize update hooks
     */
    public function init(): void {
        // Check for updates
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);

        // Plugin info popup
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);

        // After update, clear cache
        add_action('upgrader_process_complete', [$this, 'clear_update_cache'], 10, 2);

        // Add update check link to plugin actions
        add_filter('plugin_action_links_' . self::PLUGIN_FILE, [$this, 'add_check_update_link']);

        // Handle manual update check
        add_action('admin_init', [$this, 'handle_manual_update_check']);

        // Add license notice to update row
        add_action('in_plugin_update_message-' . self::PLUGIN_FILE, [$this, 'update_message'], 10, 2);
    }

    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $update_data = $this->get_update_data();

        if ($update_data && version_compare($this->version, $update_data['version'], '<')) {
            // INTERIM SUPPLY-CHAIN STOPGAP: the license-server response is trusted
            // to name a package URL, but a compromised/spoofed/MITM'd response
            // could point WordPress at an attacker-controlled zip that then runs
            // as plugin code. Until real package-signature verification lands
            // (the DURABLE fix — tracked, Nat-gated, lockstep with
            // peanut-license-server), pin the package to HTTPS on the expected
            // Peanut update host and drop anything else.
            $package = $this->assert_trusted_package_url($update_data['download_url'] ?? '');

            $item = (object) [
                'id' => self::PLUGIN_SLUG,
                'slug' => self::PLUGIN_SLUG,
                'plugin' => self::PLUGIN_FILE,
                'new_version' => $update_data['version'],
                'url' => $update_data['homepage'] ?? 'https://peanutgraphic.com/formflow',
                'package' => $package,
                'icons' => [
                    '1x' => $update_data['icons']['1x'] ?? '',
                    '2x' => $update_data['icons']['2x'] ?? '',
                    'default' => $update_data['icons']['default'] ?? '',
                ],
                'banners' => [
                    'low' => $update_data['banners']['low'] ?? '',
                    'high' => $update_data['banners']['high'] ?? '',
                ],
                'banners_rtl' => [],
                'requires' => $update_data['requires'] ?? '6.0',
                'tested' => $update_data['tested'] ?? '',
                'requires_php' => $update_data['requires_php'] ?? '8.0',
            ];

            // If no license, don't provide download URL
            $license = LicenseManager::instance();
            if (!$license->is_pro() && empty($update_data['free_update'])) {
                $item->package = '';
            }

            $transient->response[self::PLUGIN_FILE] = $item;
        } else {
            // No update available - add to no_update to prevent WordPress from checking
            $transient->no_update[self::PLUGIN_FILE] = (object) [
                'id' => self::PLUGIN_SLUG,
                'slug' => self::PLUGIN_SLUG,
                'plugin' => self::PLUGIN_FILE,
                'new_version' => $this->version,
                'url' => 'https://peanutgraphic.com/formflow',
                'package' => '',
            ];
        }

        return $transient;
    }

    /**
     * Get update data from server (with caching)
     */
    private function get_update_data(): ?array {
        // Check cache first
        $cached = get_transient($this->cache_key);

        if ($cached !== false) {
            return $cached ?: null;
        }

        // Fetch from server
        $response = $this->fetch_update_info();

        // Cache the result (even if empty, to prevent hammering server)
        set_transient($this->cache_key, $response ?: '', $this->cache_expiration);

        return $response;
    }

    /**
     * Fetch update info from Peanut License Server
     */
    private function fetch_update_info(): ?array {
        $license = LicenseManager::instance();

        $url = self::API_URL . '/updates/check?' . http_build_query([
            'plugin' => self::PLUGIN_SLUG,
            'version' => $this->version,
            'license' => $license->get_license_key(),
            'site_url' => home_url(),
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
        ]);

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data) {
            return null;
        }

        // Handle Peanut License Server response format
        // The API returns: { update_available, latest_version, plugin_info: { ... } }
        if (isset($data['plugin_info'])) {
            $info = $data['plugin_info'];
            $info['version'] = $data['latest_version'] ?? $info['version'] ?? null;
            $info['free_update'] = $data['can_download'] ?? true;
            return $info;
        }

        // Fallback to direct format
        if (empty($data['version'])) {
            return null;
        }

        return $data;
    }

    /**
     * Plugin info popup (shown when clicking "View details")
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        // Fetch full plugin info
        $info = $this->fetch_plugin_info();

        if (!$info) {
            return $result;
        }

        $license = LicenseManager::instance();

        return (object) [
            'name' => $info['name'] ?? 'FormFlow',
            'slug' => self::PLUGIN_SLUG,
            'version' => $info['version'] ?? $this->version,
            'author' => $info['author'] ?? '<a href="https://peanutgraphic.com">Peanut Graphic</a>',
            'author_profile' => $info['author_profile'] ?? 'https://peanutgraphic.com',
            'homepage' => $info['homepage'] ?? 'https://peanutgraphic.com/formflow',
            'requires' => $info['requires'] ?? '6.0',
            'tested' => $info['tested'] ?? '',
            'requires_php' => $info['requires_php'] ?? '8.0',
            'downloaded' => $info['downloaded'] ?? 0,
            'last_updated' => $info['last_updated'] ?? '',
            'sections' => [
                'description' => $info['sections']['description'] ?? '',
                'installation' => $info['sections']['installation'] ?? '',
                'changelog' => $info['sections']['changelog'] ?? '',
                'faq' => $info['sections']['faq'] ?? '',
            ],
            // Same interim host-pin as check_for_update(): the "View details"
            // download link is also an install vector, so refuse any URL that
            // is not HTTPS on the expected Peanut update host.
            'download_link' => $license->is_pro()
                ? $this->assert_trusted_package_url($info['download_url'] ?? '')
                : '',
            'banners' => [
                'low' => $info['banners']['low'] ?? '',
                'high' => $info['banners']['high'] ?? '',
            ],
            'icons' => [
                '1x' => $info['icons']['1x'] ?? '',
                '2x' => $info['icons']['2x'] ?? '',
            ],
            'contributors' => $info['contributors'] ?? [],
            'ratings' => $info['ratings'] ?? [],
            'num_ratings' => $info['num_ratings'] ?? 0,
            'support_threads' => $info['support_threads'] ?? 0,
            'support_threads_resolved' => $info['support_threads_resolved'] ?? 0,
            'active_installs' => $info['active_installs'] ?? 0,
        ];
    }

    /**
     * Fetch full plugin info from server
     */
    private function fetch_plugin_info(): ?array {
        $url = self::API_URL . '/updates/info?' . http_build_query([
            'plugin' => self::PLUGIN_SLUG,
        ]);

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Handle Peanut License Server response format
        if (isset($data['plugin_info'])) {
            return $data['plugin_info'];
        }

        return $data;
    }

    /**
     * Clear update cache after plugin update
     */
    public function clear_update_cache($upgrader, $options): void {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            if (isset($options['plugins']) && in_array(self::PLUGIN_FILE, $options['plugins'], true)) {
                delete_transient($this->cache_key);
            }
        }
    }

    /**
     * Add "Check for updates" link to plugin actions
     */
    public function add_check_update_link(array $links): array {
        $check_link = sprintf(
            '<a href="%s">%s</a>',
            wp_nonce_url(
                admin_url('plugins.php?formflow_check_update=1'),
                'formflow_check_update'
            ),
            __('Check for updates', 'formflow')
        );

        $links['check_update'] = $check_link;

        return $links;
    }

    /**
     * Handle manual update check
     */
    public function handle_manual_update_check(): void {
        if (!isset($_GET['formflow_check_update'])) {
            return;
        }

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'formflow_check_update')) {
            return;
        }

        if (!current_user_can('update_plugins')) {
            return;
        }

        // Clear cache to force fresh check
        delete_transient($this->cache_key);

        // Clear WordPress update cache
        delete_site_transient('update_plugins');

        // Check for updates
        wp_update_plugins();

        // Get update status for message
        $update_data = $this->get_update_data();

        if ($update_data && version_compare($this->version, $update_data['version'], '<')) {
            $message = sprintf(
                __('FormFlow %s is available! You can update from the plugins page.', 'formflow'),
                $update_data['version']
            );
            $type = 'success';
        } else {
            $message = __('You are running the latest version of FormFlow.', 'formflow');
            $type = 'info';
        }

        // Store message for display
        set_transient('formflow_update_message', [
            'message' => $message,
            'type' => $type,
        ], 30);

        wp_redirect(admin_url('plugins.php'));
        exit;
    }

    /**
     * Display update check message
     */
    public static function display_update_message(): void {
        $message = get_transient('formflow_update_message');

        if ($message) {
            delete_transient('formflow_update_message');

            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($message['type']),
                esc_html($message['message'])
            );
        }
    }

    /**
     * Add message to update row if license is needed
     */
    public function update_message($plugin_data, $response): void {
        $license = LicenseManager::instance();

        if (!$license->is_pro()) {
            printf(
                '<br><span class="update-message notice-warning notice-alt" style="display: inline-block; padding: 8px 12px; margin-top: 8px;">%s <a href="%s">%s</a></span>',
                esc_html__('A valid license key is required to download updates.', 'formflow'),
                esc_url(admin_url('admin.php?page=isf-tools&tab=license')),
                esc_html__('Enter license key', 'formflow')
            );
        }
    }

    /**
     * Get the download URL for an update (with license)
     */
    public function get_download_url(): string {
        $license = LicenseManager::instance();

        return self::API_URL . '/updates/download?' . http_build_query([
            'plugin' => self::PLUGIN_SLUG,
            'license' => $license->get_license_key(),
            'site_url' => home_url(),
        ]);
    }

    /**
     * Force check for updates (bypass cache)
     */
    public function force_check(): ?array {
        delete_transient($this->cache_key);
        return $this->get_update_data();
    }

    /**
     * Validate a package/download URL before handing it to the WP updater.
     *
     * INTERIM supply-chain stopgap. Returns the URL unchanged when it is HTTPS
     * on the expected Peanut update host; otherwise logs and returns '' so the
     * updater simply offers no package (the WP core update flow treats an empty
     * package as "no downloadable update"). This blocks MITM / rogue-host
     * package swaps without needing the full signing pipeline.
     *
     * NOTE: this is NOT a substitute for cryptographic package-signature
     * verification, which remains the durable fix (tracked, Nat-gated, in
     * lockstep with peanut-license-server). Host-pinning only proves the zip
     * came from the right host over TLS, not that its contents are authentic.
     *
     * @param string $url
     * @return string The trusted URL, or '' if it is not trusted.
     */
    private function assert_trusted_package_url(string $url): string {
        if ($url === '') {
            return '';
        }

        $parts  = wp_parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = strtolower($parts['host'] ?? '');
        $expected = $this->get_update_host();

        $trusted = $scheme === 'https'
            && $host !== ''
            && ($host === $expected || $this->host_ends_with($host, '.' . $expected));

        if (!$trusted) {
            error_log(sprintf(
                '[FormFlow Updater] Rejected update package from untrusted source: %s '
                . '(require HTTPS on %s). Aborting update offer.',
                $url,
                $expected
            ));
            return '';
        }

        return $url;
    }

    /**
     * Expected update host, derived from the pinned API base URL so the two can
     * never drift apart.
     *
     * @return string
     */
    private function get_update_host(): string {
        $host = wp_parse_url(self::API_URL, PHP_URL_HOST);

        return strtolower(is_string($host) && $host !== '' ? $host : 'peanutgraphic.com');
    }

    /**
     * Suffix match on a host label boundary (avoids "evilpeanutgraphic.com"
     * slipping past a naive substring check).
     *
     * @param string $host
     * @param string $suffix
     * @return bool
     */
    private function host_ends_with(string $host, string $suffix): bool {
        return $suffix !== '' && str_ends_with($host, $suffix);
    }
}
