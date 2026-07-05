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
     * Ed25519 public key (base64) used to verify the signature of our own
     * release packages. This is a PUBLIC key — it is safe to embed and is NOT a
     * secret. Fingerprint faaad8d7a7d7eaa9. The matching private key lives only
     * in the Peanut release-signing tooling (Peanut-meta scripts/publish-plugin.sh),
     * which signs every release and ships a "<asset>.manifest.json" alongside the
     * zip. This is DEFENSE-IN-DEPTH on top of the existing host-pin: the pin
     * proves the zip came from the right host over TLS; the signature proves the
     * bytes are authentically ours.
     */
    const PEANUT_SIGNING_PUBKEY = 'NtHnWTBLVzCBKMAq9CO8LHDSD9ZfpGV0UloQdgToIwM=';

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

        // Verify our own release package's Ed25519 signature BEFORE WordPress
        // installs it. Defense-in-depth on top of the host-pin above.
        add_filter('upgrader_pre_download', [$this, 'verify_package_signature'], 10, 4);

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
     * Verify the Ed25519 signature of our own release package before install.
     *
     * Hooked on 'upgrader_pre_download'. Returning a local file path from this
     * filter makes WordPress SKIP its own download and install from that
     * (already verified) file; returning a WP_Error aborts the update. We only
     * gate OUR plugin's own packages and let everything else pass through
     * untouched. For our packages the behaviour is FAIL-CLOSED: a missing /
     * malformed manifest, a hash mismatch, an unavailable libsodium, or a bad
     * signature all abort the install rather than fall back to WordPress's
     * unverified download.
     *
     * Layered on top of assert_trusted_package_url() (the host-pin) — the pin is
     * unchanged; this adds cryptographic authenticity on top of transport trust.
     *
     * @param bool|\WP_Error $reply      Short-circuit reply (false = let WP download).
     * @param string         $package    The package URL WP is about to download.
     * @param mixed          $upgrader   The WP_Upgrader instance (unused).
     * @param array          $hook_extra Context, incl. ['plugin' => '<dir>/<file>.php'].
     * @return bool|string|\WP_Error Verified local path, original $reply, or WP_Error.
     */
    public function verify_package_signature($reply, $package, $upgrader = null, $hook_extra = array()) {
        // Only gate OUR plugin's own release packages; let everything else pass.
        if (!empty($hook_extra['plugin']) && $hook_extra['plugin'] !== self::PLUGIN_FILE) {
            return $reply;
        }

        // Only interfere with packages served from our own (host-pinned) update
        // host. NOTE: the shipped template keys this off a 'github.com/...'
        // substring, but FormFlow's packages are served by the peanutgraphic.com
        // license server (see assert_trusted_package_url()); keying off the same
        // pinned host is what makes this gate actually fire for FormFlow instead
        // of being dead code.
        if (!is_string($package) || $package === '' || !$this->is_our_package_url($package)) {
            return $reply;
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $zip = download_url($package);
        if (is_wp_error($zip)) {
            return $zip;
        }

        $manifest_url = $package . '.manifest.json';
        $mresp        = wp_remote_get($manifest_url, ['timeout' => 20]);
        $manifest     = json_decode(wp_remote_retrieve_body($mresp), true);

        if (!is_array($manifest) || empty($manifest['sha256']) || empty($manifest['signature'])) {
            @unlink($zip);
            return new \WP_Error(
                'peanut_update_signature',
                __('Update signature manifest missing — refusing to install an unsigned package.', 'formflow')
            );
        }

        $bytes = (string) file_get_contents($zip);

        if (!hash_equals((string) $manifest['sha256'], hash('sha256', $bytes))) {
            @unlink($zip);
            return new \WP_Error(
                'peanut_update_signature',
                __('Update package hash mismatch — refusing to install.', 'formflow')
            );
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            @unlink($zip);
            return new \WP_Error(
                'peanut_update_signature',
                __('Signature verification unavailable (libsodium) — refusing to install.', 'formflow')
            );
        }

        if (!$this->verify_bytes($bytes, $manifest)) {
            @unlink($zip);
            return new \WP_Error(
                'peanut_update_signature',
                __('Update package signature invalid — refusing to install.', 'formflow')
            );
        }

        return $zip; // verified — WordPress installs from this local file.
    }

    /**
     * Pure verification of package bytes against a manifest, using the embedded
     * Peanut signing public key. Extracted so the sha256 + Ed25519 logic can be
     * unit-tested in isolation (no live download / booted WordPress needed).
     *
     * @param string $zipBytes Raw package bytes.
     * @param array  $manifest Decoded manifest, expects ['sha256', 'signature'].
     * @return bool True iff the sha256 matches AND the Ed25519 signature verifies.
     */
    public function verify_bytes(string $zipBytes, array $manifest): bool {
        return self::verify_bytes_with_key($zipBytes, $manifest, self::PEANUT_SIGNING_PUBKEY);
    }

    /**
     * Key-parameterised core of verify_bytes(). Static + pure so tests can prove
     * the round-trip with a throwaway keypair (the production private key is not
     * in this repo). Fail-CLOSED: any missing field, decode failure, wrong key /
     * signature length, or unavailable libsodium returns false.
     *
     * @param string $zipBytes     Raw package bytes.
     * @param array  $manifest     Decoded manifest, expects ['sha256', 'signature'].
     * @param string $base64Pubkey Base64 Ed25519 public key to verify against.
     * @return bool
     */
    public static function verify_bytes_with_key(string $zipBytes, array $manifest, string $base64Pubkey): bool {
        if (empty($manifest['sha256']) || empty($manifest['signature'])) {
            return false;
        }

        if (!hash_equals((string) $manifest['sha256'], hash('sha256', $zipBytes))) {
            return false;
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $sig = base64_decode((string) $manifest['signature'], true);
        $key = base64_decode($base64Pubkey, true);

        if ($sig === false || $key === false
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $zipBytes, $key);
    }

    /**
     * True when $url is served from our own (host-pinned) update host or a
     * subdomain of it. Mirrors assert_trusted_package_url()'s host rules so the
     * signature gate fires on exactly the packages the pin already trusts.
     *
     * @param string $url
     * @return bool
     */
    private function is_our_package_url(string $url): bool {
        $host = strtolower((string) (wp_parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }

        foreach ($this->trusted_package_hosts() as $expected) {
            if ($host === $expected || $this->host_ends_with($host, '.' . $expected)) {
                return true;
            }
        }

        return false;
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

        // The update API lives on peanutgraphic.com, but the license server's
        // unified /updates/check now serves the canonical GitHub-release package
        // (github.com/peanutgraphic/<slug>/...). Trust both delivery hosts.
        $trusted = false;
        if ($scheme === 'https' && $host !== '') {
            foreach ($this->trusted_package_hosts() as $expected) {
                if ($host === $expected || $this->host_ends_with($host, '.' . $expected)) {
                    $trusted = true;
                    break;
                }
            }
        }

        if (!$trusted) {
            error_log(sprintf(
                '[FormFlow Updater] Rejected update package from untrusted source: %s '
                . '(require HTTPS on %s). Aborting update offer.',
                $url,
                implode(' or ', $this->trusted_package_hosts())
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
    /**
     * Hosts the update package may be served from: the update API host and the
     * GitHub-release CDN the unified /updates/check now points at.
     */
    private function trusted_package_hosts(): array {
        return array_values(array_unique([$this->get_update_host(), 'github.com']));
    }

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
