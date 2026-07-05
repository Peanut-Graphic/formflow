<?php
/**
 * License Manager
 *
 * Handles license validation with Peanut License Server at peanutgraphic.com.
 *
 * @package FormFlow
 */

namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class LicenseManager
 *
 * Manages license keys and feature access via Peanut License Server.
 */
class LicenseManager {

    /**
     * Singleton instance
     */
    private static ?LicenseManager $instance = null;

    /**
     * License data cache
     */
    private ?array $license_data = null;

    /**
     * Plugin slug
     */
    const PLUGIN_SLUG = 'formflow';

    /**
     * API base URL
     */
    const API_URL = 'https://peanutgraphic.com/wp-json/peanut-api/v1';

    /**
     * Option name for license key
     */
    const OPTION_LICENSE_KEY = 'formflow_license_key';

    /**
     * Option name for license data (cached response)
     */
    const OPTION_LICENSE_DATA = 'formflow_license_data';

    /**
     * Option name for license last check timestamp
     */
    const OPTION_LICENSE_LAST_CHECK = 'formflow_license_last_check';

    /**
     * Option name for whitelisted IPs
     */
    const OPTION_WHITELIST_IPS = 'formflow_whitelist_ips';

    /**
     * Cache duration in seconds (12 hours)
     */
    const CACHE_DURATION = 43200;

    /**
     * Features available in Free version
     */
    const FREE_FEATURES = [
        'enrollment_form',      // Basic enrollment form
        'scheduler_form',       // Scheduler form type
        'demo_mode',            // Demo mode for testing
        'basic_analytics',      // Basic form analytics (funnel, timing)
        'email_confirmation',   // Email confirmations
        'inline_validation',    // Form field validation
        'auto_save',            // Auto-save form progress
    ];

    /**
     * Features requiring Pro license
     */
    const PRO_FEATURES = [
        'external_enrollment'       => 'External Enrollment Tracking',
        'visitor_analytics'         => 'Visitor Analytics & Attribution',
        'handoff_tracking'          => 'Handoff Tracking',
        'utm_tracking'              => 'UTM Parameter Tracking',
        'gtm_integration'           => 'Google Tag Manager Integration',
        'sms_notifications'         => 'SMS Notifications',
        'team_notifications'        => 'Team Notifications (Slack/Teams)',
        'email_digest'              => 'Email Digest Reports',
        'webhooks'                  => 'Outbound Webhooks',
        'completion_import'         => 'Completion Import',
        'attribution_reports'       => 'Attribution Reports',
        'ab_testing'                => 'A/B Testing',
        'fraud_detection'           => 'Fraud Detection',
        'document_upload'           => 'Document Upload',
        'capacity_management'       => 'Capacity Management',
        'calendar_integration'      => 'Calendar Integration (iCal)',
        'crm_integration'           => 'CRM Integration',
        'spanish_translation'       => 'Spanish Translation',
        'pwa_support'               => 'Progressive Web App Support',
        'chatbot_assistant'         => 'Chatbot Assistant',
        'api_usage_monitoring'      => 'API Usage Monitoring',
        'white_label'               => 'White Label / Custom Branding',
        'priority_support'          => 'Priority Support',
    ];

    /**
     * Features requiring Agency license
     */
    const AGENCY_FEATURES = [
        'multisite_management'      => 'Multisite Management',
        'dedicated_support'         => 'Dedicated Support Contact',
        'priority_support'          => 'Priority Support',
    ];

    /**
     * License tier configuration
     */
    const LICENSE_TIERS = [
        'free' => [
            'name' => 'Free',
            'sites' => 1,
            'price' => 0,
            'features' => 'free',
        ],
        'pro' => [
            'name' => 'Pro',
            'sites' => 3,
            'price' => 79,
            'features' => 'pro',
        ],
        'agency' => [
            'name' => 'Agency',
            'sites' => 25,
            'price' => 199,
            'features' => 'agency',
        ],
    ];

    /**
     * Get singleton instance
     */
    public static function instance(): LicenseManager {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        $this->load_license_data();
    }

    /**
     * Load license data from options
     */
    private function load_license_data(): void {
        $this->license_data = [
            'key' => get_option(self::OPTION_LICENSE_KEY, ''),
            'data' => get_option(self::OPTION_LICENSE_DATA, []),
            'last_check' => get_option(self::OPTION_LICENSE_LAST_CHECK, 0),
        ];
    }

    /**
     * Check if Pro license is active
     */
    public function is_pro(): bool {
        // Check for admin testing mode first
        if ($this->is_admin_testing_mode()) {
            return true;
        }

        // Check if current IP is whitelisted
        if ($this->is_ip_whitelisted()) {
            return true;
        }

        // Get cached or fresh license data
        $license = $this->get_validated_license();

        if (!$license) {
            return false;
        }

        // Check status
        if (($license['status'] ?? '') !== 'active') {
            return false;
        }

        // Check expiration
        if (!empty($license['expires_at'])) {
            $expires = strtotime($license['expires_at']);
            if ($expires && $expires < time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if Agency license is active
     */
    public function is_agency(): bool {
        if (!$this->is_pro()) {
            return false;
        }

        $license = $this->get_validated_license();
        return ($license['tier'] ?? '') === 'agency';
    }

    /**
     * Check if admin testing mode is enabled
     */
    public function is_admin_testing_mode(): bool {
        $key = $this->license_data['key'] ?? '';

        // Check for custom admin key defined in wp-config.php
        if (defined('FORMFLOW_ADMIN_KEY') && !empty(FORMFLOW_ADMIN_KEY)) {
            if ($key === FORMFLOW_ADMIN_KEY) {
                return true;
            }
        }

        // Check for dev mode constant
        if (defined('FORMFLOW_DEV_MODE') && FORMFLOW_DEV_MODE === true) {
            return true;
        }

        return false;
    }

    /**
     * Check if current IP is whitelisted for Pro access
     */
    public function is_ip_whitelisted(): bool {
        $whitelist = $this->get_whitelisted_ips();

        if (empty($whitelist)) {
            return false;
        }

        $current_ip = $this->get_client_ip();

        foreach ($whitelist as $ip) {
            $ip = trim($ip);

            if (empty($ip)) {
                continue;
            }

            // Exact match
            if ($ip === $current_ip) {
                return true;
            }

            // CIDR notation support
            if (strpos($ip, '/') !== false && $this->ip_in_cidr($current_ip, $ip)) {
                return true;
            }

            // Wildcard support
            if (strpos($ip, '*') !== false) {
                $pattern = '/^' . str_replace(['.', '*'], ['\.', '\d+'], $ip) . '$/';
                if (preg_match($pattern, $current_ip)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get list of whitelisted IP addresses
     */
    public function get_whitelisted_ips(): array {
        $whitelist = get_option(self::OPTION_WHITELIST_IPS, []);

        if (!is_array($whitelist)) {
            $whitelist = array_filter(array_map('trim', explode("\n", $whitelist)));
        }

        return $whitelist;
    }

    /**
     * Set whitelisted IP addresses
     */
    public function set_whitelisted_ips(array $ips): bool {
        $ips = array_filter(array_map('trim', $ips));
        return update_option(self::OPTION_WHITELIST_IPS, $ips);
    }

    /**
     * Get client IP address
     */
    private function get_client_ip(): string {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];

                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Check if IP is within CIDR range
     */
    private function ip_in_cidr(string $ip, string $cidr): bool {
        list($subnet, $mask) = explode('/', $cidr);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask_long = -1 << (32 - intval($mask));

            return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
        }

        return false;
    }

    /**
     * Get validated license data (with caching)
     */
    public function get_validated_license(): ?array {
        $cached = $this->license_data['data'] ?? [];
        $last_check = $this->license_data['last_check'] ?? 0;

        // Return cached data if still valid
        if (!empty($cached) && (time() - $last_check) < self::CACHE_DURATION) {
            return $cached;
        }

        // No license key set
        if (empty($this->license_data['key'])) {
            return null;
        }

        // Try to validate remotely
        $result = $this->validate_license_remote($this->license_data['key']);

        if ($result && !empty($result['license'])) {
            // Cache the validated license data
            update_option(self::OPTION_LICENSE_DATA, $result['license']);
            update_option(self::OPTION_LICENSE_LAST_CHECK, time());

            $this->license_data['data'] = $result['license'];
            $this->license_data['last_check'] = time();

            return $result['license'];
        }

        // Remote validation failed - use cached data as fallback
        if (!empty($cached)) {
            return $cached;
        }

        return null;
    }

    /**
     * Check if a specific feature is available
     */
    public function has_feature(string $feature): bool {
        // Free features are always available
        if (in_array($feature, self::FREE_FEATURES, true)) {
            return true;
        }

        // Agency features require agency license
        if (array_key_exists($feature, self::AGENCY_FEATURES)) {
            return $this->is_agency();
        }

        // Pro features require pro or higher license
        if (array_key_exists($feature, self::PRO_FEATURES)) {
            return $this->is_pro();
        }

        // Check features from license server response
        $license = $this->get_validated_license();
        if ($license && isset($license['features'][$feature])) {
            return (bool) $license['features'][$feature];
        }

        // Unknown features default to Pro
        return $this->is_pro();
    }

    /**
     * Get feature label
     */
    public function get_feature_label(string $feature): string {
        if (isset(self::AGENCY_FEATURES[$feature])) {
            return self::AGENCY_FEATURES[$feature];
        }
        return self::PRO_FEATURES[$feature] ?? ucwords(str_replace('_', ' ', $feature));
    }

    /**
     * Get all Pro features
     */
    public function get_pro_features(): array {
        return self::PRO_FEATURES;
    }

    /**
     * Get all Agency features
     */
    public function get_agency_features(): array {
        return self::AGENCY_FEATURES;
    }

    /**
     * Get license tier information
     */
    public function get_license_tiers(): array {
        return self::LICENSE_TIERS;
    }

    /**
     * Get current tier
     */
    public function get_current_tier(): string {
        if (!$this->is_pro()) {
            return 'free';
        }

        $license = $this->get_validated_license();
        return $license['tier'] ?? 'pro';
    }

    /**
     * Get current tier info
     */
    public function get_current_tier_info(): array {
        $tier = $this->get_current_tier();
        return self::LICENSE_TIERS[$tier] ?? self::LICENSE_TIERS['free'];
    }

    /**
     * Get license type label for display (e.g., "Free", "Pro", "Agency")
     */
    public function get_license_type_label(): string {
        $tier = $this->get_current_tier();

        $labels = [
            'free' => __('Free', 'formflow'),
            'pro' => __('Pro', 'formflow'),
            'agency' => __('Agency', 'formflow'),
        ];

        return $labels[$tier] ?? __('Free', 'formflow');
    }

    /**
     * Get license key (masked for display)
     */
    public function get_license_key_masked(): string {
        $key = $this->license_data['key'] ?? '';
        if (empty($key)) {
            return '';
        }

        if (strlen($key) > 12) {
            return substr($key, 0, 4) . str_repeat('*', strlen($key) - 8) . substr($key, -4);
        }

        return str_repeat('*', strlen($key));
    }

    /**
     * Get raw license key
     */
    public function get_license_key(): string {
        return $this->license_data['key'] ?? '';
    }

    /**
     * Get license status info for display
     */
    public function get_license_status(): array {
        $license = $this->get_validated_license();

        if (!$license) {
            return [
                'status' => 'inactive',
                'tier' => 'free',
                'tier_label' => __('Free', 'formflow'),
                'expires_at' => null,
                'expires_label' => '—',
                'activations_used' => 0,
                'activations_limit' => 1,
            ];
        }

        $tier_labels = [
            'free' => __('Free', 'formflow'),
            'pro' => __('Pro', 'formflow'),
            'agency' => __('Agency', 'formflow'),
        ];

        $expires_label = '—';
        if (!empty($license['expires_at'])) {
            $expires = strtotime($license['expires_at']);
            if ($expires) {
                $expires_label = date_i18n(get_option('date_format'), $expires);
            }
        }

        return [
            'status' => $license['status'] ?? 'inactive',
            'tier' => $license['tier'] ?? 'free',
            'tier_label' => $tier_labels[$license['tier'] ?? 'free'] ?? __('Unknown', 'formflow'),
            'expires_at' => $license['expires_at'] ?? null,
            'expires_label' => $expires_label,
            'activations_used' => $license['activations_used'] ?? 0,
            'activations_limit' => $license['activations_limit'] ?? 1,
        ];
    }

    /**
     * Get expiration date formatted
     */
    public function get_expiration_date(): string {
        $license = $this->get_validated_license();

        if (!$license || empty($license['expires_at'])) {
            return $this->is_pro() ? __('Never', 'formflow') : '—';
        }

        return date_i18n(get_option('date_format'), strtotime($license['expires_at']));
    }

    /**
     * Activate license key
     */
    public function activate_license(string $key): array {
        $key = sanitize_text_field(trim($key));

        if (empty($key)) {
            return [
                'success' => false,
                'message' => __('Please enter a license key.', 'formflow'),
            ];
        }

        // Admin key defined in wp-config.php
        if (defined('FORMFLOW_ADMIN_KEY') && $key === FORMFLOW_ADMIN_KEY) {
            update_option(self::OPTION_LICENSE_KEY, $key);
            update_option(self::OPTION_LICENSE_DATA, [
                'status' => 'active',
                'tier' => 'agency',
                'expires_at' => null,
                'activations_used' => 1,
                'activations_limit' => 999,
            ]);
            update_option(self::OPTION_LICENSE_LAST_CHECK, time());

            $this->load_license_data();

            return [
                'success' => true,
                'message' => __('Development mode activated. All features unlocked.', 'formflow'),
            ];
        }

        // Call Peanut License Server
        $result = $this->validate_license_remote($key, true);

        if (!$result) {
            return [
                'success' => false,
                'message' => __('Could not connect to license server. Please try again later.', 'formflow'),
            ];
        }

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => $result['error'] ?? __('Invalid license key.', 'formflow'),
            ];
        }

        // Save license data
        update_option(self::OPTION_LICENSE_KEY, $key);
        update_option(self::OPTION_LICENSE_DATA, $result['license']);
        update_option(self::OPTION_LICENSE_LAST_CHECK, time());

        $this->load_license_data();

        $tier_label = ucfirst($result['license']['tier'] ?? 'pro');

        return [
            'success' => true,
            'message' => sprintf(
                __('License activated successfully! %s features are now available.', 'formflow'),
                $tier_label
            ),
        ];
    }

    /**
     * Deactivate license
     */
    public function deactivate_license(): array {
        $key = $this->license_data['key'] ?? '';

        // Call deactivation endpoint if we have a key
        if (!empty($key)) {
            $this->deactivate_license_remote($key);
        }

        // Clear local data
        delete_option(self::OPTION_LICENSE_KEY);
        delete_option(self::OPTION_LICENSE_DATA);
        delete_option(self::OPTION_LICENSE_LAST_CHECK);

        $this->license_data = [
            'key' => '',
            'data' => [],
            'last_check' => 0,
        ];

        return [
            'success' => true,
            'message' => __('License deactivated.', 'formflow'),
        ];
    }

    /**
     * Validate license with remote server
     *
     * @param string $key License key
     * @param bool $activate Whether to activate (increase activation count)
     */
    private function validate_license_remote(string $key, bool $activate = false): ?array {
        $endpoint = $activate ? '/license/validate' : '/license/status';

        if ($activate) {
            $response = wp_remote_post(self::API_URL . $endpoint, [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'license_key' => $key,
                    'site_url' => home_url(),
                    'site_name' => get_bloginfo('name'),
                    'plugin_version' => ISF_VERSION,
                ]),
            ]);
        } else {
            $response = wp_remote_get(self::API_URL . $endpoint . '?' . http_build_query([
                'license_key' => $key,
            ]), [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        }

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 || !$data) {
            return $data ?: null;
        }

        return $data;
    }

    /**
     * Deactivate license on remote server
     */
    private function deactivate_license_remote(string $key): bool {
        $response = wp_remote_post(self::API_URL . '/license/deactivate', [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode([
                'license_key' => $key,
                'site_url' => home_url(),
            ]),
        ]);

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Check license status without activation (for status checks)
     */
    public function check_license_status(): ?array {
        $key = $this->license_data['key'] ?? '';

        if (empty($key)) {
            return null;
        }

        // Force fresh check
        $result = $this->validate_license_remote($key, false);

        if ($result && !empty($result['license'])) {
            update_option(self::OPTION_LICENSE_DATA, $result['license']);
            update_option(self::OPTION_LICENSE_LAST_CHECK, time());

            $this->license_data['data'] = $result['license'];
            $this->license_data['last_check'] = time();

            return $result['license'];
        }

        return $this->license_data['data'] ?: null;
    }

    /**
     * Render upgrade prompt for a Pro feature
     */
    public function render_upgrade_prompt(string $feature, bool $echo = true): string {
        $label = $this->get_feature_label($feature);

        $html = '<div class="formflow-pro-prompt">';
        $html .= '<div class="formflow-pro-badge">';
        $html .= '<span class="dashicons dashicons-lock"></span>';
        $html .= '<span class="formflow-pro-label">' . esc_html__('PRO', 'formflow') . '</span>';
        $html .= '</div>';
        $html .= '<p>' . sprintf(
            esc_html__('%s is a Pro feature. Upgrade to unlock this and 20+ other advanced features.', 'formflow'),
            '<strong>' . esc_html($label) . '</strong>'
        ) . '</p>';
        $html .= '<a href="' . esc_url(admin_url('admin.php?page=isf-tools&tab=license')) . '" class="button button-primary">';
        $html .= esc_html__('Upgrade to Pro', 'formflow');
        $html .= '</a>';
        $html .= '<a href="https://peanutgraphic.com/formflow/pricing" target="_blank" class="button button-link" style="margin-left: 10px;">';
        $html .= esc_html__('Learn More', 'formflow');
        $html .= '</a>';
        $html .= '</div>';

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    /**
     * Check if feature should show upgrade prompt
     */
    public function should_show_upgrade(string $feature): bool {
        return array_key_exists($feature, self::PRO_FEATURES) && !$this->is_pro();
    }
}

/**
 * Helper function to get license manager instance
 */
function formflow_license(): LicenseManager {
    return LicenseManager::instance();
}
