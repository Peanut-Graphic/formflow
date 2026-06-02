<?php
/**
 * White-Label SaaS Platform
 *
 * Enables complete white-labeling of FormFlow for resellers and agencies,
 * including custom branding, multi-tenant architecture, and reseller management.
 *
 * @package FormFlow
 * @subpackage Platform
 * @since 2.7.0
 * @status upcoming
 */

namespace ISF\Platform;

defined('ABSPATH') || exit;

/**
 * Class WhiteLabel
 *
 * Provides comprehensive white-labeling capabilities:
 * - Custom branding (logo, colors, typography)
 * - Custom domain support
 * - Reseller/agency accounts
 * - Client management
 * - Usage-based billing
 * - Isolated client environments
 */
class WhiteLabel {

    /**
     * Singleton instance
     */
    private static ?WhiteLabel $instance = null;

    /**
     * Database tables
     */
    private string $table_tenants;
    private string $table_clients;
    private string $table_usage;
    private string $table_branding;

    /**
     * Current tenant context
     */
    private ?array $current_tenant = null;

    /**
     * Get singleton instance
     */
    public static function instance(): WhiteLabel {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_tenants = $wpdb->prefix . 'isf_tenants';
        $this->table_clients = $wpdb->prefix . 'isf_tenant_clients';
        $this->table_usage = $wpdb->prefix . 'isf_tenant_usage';
        $this->table_branding = $wpdb->prefix . 'isf_branding_profiles';
    }

    /**
     * Initialize white-label system
     */
    public function init(): void {
        // Determine current tenant context
        add_action('init', [$this, 'set_tenant_context'], 1);

        // Apply branding
        add_action('admin_init', [$this, 'apply_admin_branding']);
        add_action('wp_head', [$this, 'apply_frontend_branding']);

        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu'], 30);

        // REST API
        add_action('rest_api_init', [$this, 'register_routes']);

        // Filter plugin name for white-label
        add_filter('isf_plugin_name', [$this, 'filter_plugin_name']);
        add_filter('isf_plugin_logo', [$this, 'filter_plugin_logo']);

        // Track usage
        add_action('isf_submission_complete', [$this, 'track_submission']);
        add_action('isf_api_request', [$this, 'track_api_request']);
    }

    /**
     * Create database tables
     */
    public function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Tenants (Resellers/Agencies)
        $sql_tenants = "CREATE TABLE IF NOT EXISTS {$this->table_tenants} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_code VARCHAR(50) NOT NULL,
            company_name VARCHAR(255) NOT NULL,
            contact_name VARCHAR(100),
            contact_email VARCHAR(255) NOT NULL,
            contact_phone VARCHAR(50),
            tier ENUM('starter', 'professional', 'enterprise', 'custom') DEFAULT 'starter',
            settings JSON,
            limits JSON,
            billing_email VARCHAR(255),
            stripe_customer_id VARCHAR(100),
            subscription_status ENUM('trial', 'active', 'past_due', 'cancelled') DEFAULT 'trial',
            trial_ends_at TIMESTAMP NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY tenant_code (tenant_code),
            KEY subscription_status (subscription_status),
            KEY is_active (is_active)
        ) {$charset_collate};";

        // Tenant Clients (End customers of resellers)
        $sql_clients = "CREATE TABLE IF NOT EXISTS {$this->table_clients} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            client_code VARCHAR(50) NOT NULL,
            company_name VARCHAR(255) NOT NULL,
            contact_name VARCHAR(100),
            contact_email VARCHAR(255),
            custom_domain VARCHAR(255),
            branding_profile_id INT UNSIGNED,
            instance_ids JSON,
            settings JSON,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY client_code (tenant_id, client_code),
            KEY tenant_id (tenant_id),
            KEY custom_domain (custom_domain),
            KEY is_active (is_active)
        ) {$charset_collate};";

        // Usage tracking per tenant
        $sql_usage = "CREATE TABLE IF NOT EXISTS {$this->table_usage} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED,
            metric_type ENUM('submissions', 'api_calls', 'storage_mb', 'emails', 'sms') NOT NULL,
            month_bucket DATE NOT NULL,
            count INT UNSIGNED DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY usage_bucket (tenant_id, client_id, metric_type, month_bucket),
            KEY tenant_id (tenant_id),
            KEY month_bucket (month_bucket)
        ) {$charset_collate};";

        // Branding profiles
        $sql_branding = "CREATE TABLE IF NOT EXISTS {$this->table_branding} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED,
            name VARCHAR(100) NOT NULL,
            is_default TINYINT(1) DEFAULT 0,
            logo_url VARCHAR(500),
            logo_dark_url VARCHAR(500),
            favicon_url VARCHAR(500),
            primary_color VARCHAR(7) DEFAULT '#0073aa',
            secondary_color VARCHAR(7) DEFAULT '#23282d',
            accent_color VARCHAR(7) DEFAULT '#00a0d2',
            text_color VARCHAR(7) DEFAULT '#23282d',
            background_color VARCHAR(7) DEFAULT '#ffffff',
            font_family VARCHAR(100) DEFAULT 'system-ui',
            custom_css TEXT,
            custom_js TEXT,
            email_header_html TEXT,
            email_footer_html TEXT,
            hide_powered_by TINYINT(1) DEFAULT 0,
            custom_product_name VARCHAR(100),
            custom_support_url VARCHAR(500),
            custom_docs_url VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY tenant_id (tenant_id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_tenants);
        dbDelta($sql_clients);
        dbDelta($sql_usage);
        dbDelta($sql_branding);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        // Only show for super admins or tenant admins
        if (!$this->can_manage_whitelabel()) {
            return;
        }

        add_submenu_page(
            'isf-dashboard',
            __('White Label', 'formflow'),
            __('White Label', 'formflow') . ' <span class="isf-badge-pro">Pro</span>',
            'manage_options',
            'isf-whitelabel',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Check if current user can manage white-label
     */
    private function can_manage_whitelabel(): bool {
        // Check for Pro license
        $license_manager = \ISF\LicenseManager::instance();
        if (!$license_manager->has_feature('white_label')) {
            return false;
        }

        return current_user_can('manage_options');
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route('isf/v1', '/whitelabel/tenants', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_get_tenants'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_create_tenant'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route('isf/v1', '/whitelabel/tenants/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_get_tenant'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'rest_update_tenant'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route('isf/v1', '/whitelabel/branding', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_get_branding'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_save_branding'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        register_rest_route('isf/v1', '/whitelabel/usage', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_usage'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
    }

    /**
     * Check admin permission
     */
    public function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Set tenant context based on domain or configuration
     */
    public function set_tenant_context(): void {
        // Check for tenant code in URL or domain
        $host = $_SERVER['HTTP_HOST'] ?? '';

        global $wpdb;

        // Check for client with custom domain
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, t.tenant_code, t.settings as tenant_settings
             FROM {$this->table_clients} c
             JOIN {$this->table_tenants} t ON c.tenant_id = t.id
             WHERE c.custom_domain = %s AND c.is_active = 1 AND t.is_active = 1",
            $host
        ), ARRAY_A);

        if ($client) {
            $this->current_tenant = [
                'type' => 'client',
                'tenant_id' => $client['tenant_id'],
                'client_id' => $client['id'],
                'branding_profile_id' => $client['branding_profile_id'],
            ];
            return;
        }

        // Check for tenant code in settings
        $tenant_code = get_option('isf_tenant_code');
        if ($tenant_code) {
            $tenant = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_tenants} WHERE tenant_code = %s AND is_active = 1",
                $tenant_code
            ), ARRAY_A);

            if ($tenant) {
                $this->current_tenant = [
                    'type' => 'tenant',
                    'tenant_id' => $tenant['id'],
                    'settings' => json_decode($tenant['settings'], true),
                ];
            }
        }
    }

    /**
     * Get current tenant context
     */
    public function get_current_tenant(): ?array {
        return $this->current_tenant;
    }

    /**
     * Apply admin branding
     */
    public function apply_admin_branding(): void {
        $branding = $this->get_current_branding();
        if (!$branding) {
            return;
        }

        // Inject custom CSS
        add_action('admin_head', function() use ($branding) {
            $css = $this->generate_branding_css($branding);
            if ($css) {
                echo "<style id='isf-whitelabel-css'>{$css}</style>\n";
            }
        });
    }

    /**
     * Apply frontend branding
     */
    public function apply_frontend_branding(): void {
        $branding = $this->get_current_branding();
        if (!$branding) {
            return;
        }

        // Custom CSS
        $css = $this->generate_branding_css($branding);
        if ($css) {
            echo "<style id='isf-whitelabel-css'>{$css}</style>\n";
        }

        // Favicon
        if (!empty($branding['favicon_url'])) {
            echo '<link rel="icon" href="' . esc_url($branding['favicon_url']) . '">' . "\n";
        }
    }

    /**
     * Get current branding profile
     */
    public function get_current_branding(): ?array {
        if (!$this->current_tenant) {
            return $this->get_default_branding();
        }

        global $wpdb;

        $profile_id = $this->current_tenant['branding_profile_id'] ?? null;

        if ($profile_id) {
            $branding = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_branding} WHERE id = %d",
                $profile_id
            ), ARRAY_A);
        } else {
            // Get default branding for tenant
            $branding = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_branding} WHERE tenant_id = %d AND is_default = 1",
                $this->current_tenant['tenant_id']
            ), ARRAY_A);
        }

        return $branding ?: $this->get_default_branding();
    }

    /**
     * Get default branding
     */
    private function get_default_branding(): array {
        return [
            'logo_url' => '',
            'primary_color' => '#0073aa',
            'secondary_color' => '#23282d',
            'accent_color' => '#00a0d2',
            'text_color' => '#23282d',
            'background_color' => '#ffffff',
            'font_family' => 'system-ui',
            'custom_css' => '',
            'hide_powered_by' => false,
            'custom_product_name' => '',
        ];
    }

    /**
     * Generate CSS from branding profile
     */
    private function generate_branding_css(array $branding): string {
        // SECURITY: Sanitize all CSS values to prevent XSS
        $primary_color = $this->sanitize_css_color($branding['primary_color'] ?? '#0073aa');
        $secondary_color = $this->sanitize_css_color($branding['secondary_color'] ?? '#23282d');
        $accent_color = $this->sanitize_css_color($branding['accent_color'] ?? '#00a0d2');
        $text_color = $this->sanitize_css_color($branding['text_color'] ?? '#23282d');
        $background_color = $this->sanitize_css_color($branding['background_color'] ?? '#ffffff');
        $font_family = $this->sanitize_font_family($branding['font_family'] ?? 'system-ui');

        $css = ":root {\n";
        $css .= "    --isf-primary-color: {$primary_color};\n";
        $css .= "    --isf-secondary-color: {$secondary_color};\n";
        $css .= "    --isf-accent-color: {$accent_color};\n";
        $css .= "    --isf-text-color: {$text_color};\n";
        $css .= "    --isf-background-color: {$background_color};\n";

        if (!empty($font_family)) {
            $css .= "    --isf-font-family: {$font_family};\n";
        }

        $css .= "}\n";

        // Apply CSS variables
        $css .= "
.isf-form-container {
    font-family: var(--isf-font-family);
    color: var(--isf-text-color);
}
.isf-form-container .button-primary,
.isf-form-container [type='submit'] {
    background-color: var(--isf-primary-color);
    border-color: var(--isf-primary-color);
}
.isf-form-container .button-primary:hover {
    background-color: var(--isf-secondary-color);
    border-color: var(--isf-secondary-color);
}
.isf-form-container a {
    color: var(--isf-accent-color);
}
.isf-step-indicator .isf-step.active {
    background-color: var(--isf-primary-color);
}
";

        // Add custom CSS - sanitize to prevent XSS
        if (!empty($branding['custom_css'])) {
            $sanitized_css = $this->sanitize_custom_css($branding['custom_css']);
            if ($sanitized_css) {
                $css .= "\n/* Custom CSS */\n" . $sanitized_css;
            }
        }

        return $css;
    }

    /**
     * Sanitize CSS color value to prevent XSS
     *
     * @param string $value The CSS color value to sanitize
     * @return string Sanitized color value
     */
    private function sanitize_css_color(string $value): string {
        $value = trim($value);

        // Hex color (3, 4, 6, or 8 characters)
        if (preg_match('/^#[a-fA-F0-9]{3,8}$/', $value)) {
            return $value;
        }

        // RGB/RGBA
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[\d.]+)?\s*\)$/', $value)) {
            return $value;
        }

        // HSL/HSLA
        if (preg_match('/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%?\s*,\s*\d{1,3}%?\s*(,\s*[\d.]+)?\s*\)$/', $value)) {
            return $value;
        }

        // Named colors (whitelist common ones)
        $named_colors = [
            'black', 'white', 'red', 'green', 'blue', 'yellow', 'orange', 'purple',
            'pink', 'brown', 'gray', 'grey', 'cyan', 'magenta', 'lime', 'navy',
            'teal', 'olive', 'maroon', 'aqua', 'silver', 'transparent', 'inherit',
            'currentcolor', 'initial', 'unset'
        ];

        if (in_array(strtolower($value), $named_colors, true)) {
            return $value;
        }

        // Default to a safe value
        return '#000000';
    }

    /**
     * Sanitize font-family CSS value
     *
     * @param string $value The font-family value
     * @return string Sanitized font-family
     */
    private function sanitize_font_family(string $value): string {
        // Remove any potentially dangerous characters
        $value = preg_replace('/[<>"\'`;\{\}]/', '', $value);

        // Only allow alphanumeric, spaces, commas, hyphens for font names
        if (preg_match('/^[a-zA-Z0-9\s,\-_]+$/', $value)) {
            return $value;
        }

        // Fallback to system font
        return 'system-ui, -apple-system, sans-serif';
    }

    /**
     * Sanitize custom CSS to prevent XSS
     *
     * @param string $css The custom CSS string
     * @return string Sanitized CSS
     */
    private function sanitize_custom_css(string $css): string {
        // Remove any script tags or javascript: URLs
        $css = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $css);
        $css = preg_replace('/javascript\s*:/i', '', $css);
        $css = preg_replace('/expression\s*\(/i', '', $css);
        $css = preg_replace('/vbscript\s*:/i', '', $css);
        $css = preg_replace('/data\s*:/i', '', $css);

        // Remove any HTML tags
        $css = wp_strip_all_tags($css);

        // Remove behavior property (IE-specific XSS vector)
        $css = preg_replace('/behavior\s*:/i', '', $css);

        // Remove -moz-binding (Firefox XSS vector)
        $css = preg_replace('/-moz-binding\s*:/i', '', $css);

        // Remove @import rules (can be used for data exfiltration)
        $css = preg_replace('/@import\b/i', '', $css);

        // Sanitize url() - only allow http, https, and relative URLs
        $css = preg_replace_callback(
            '/url\s*\(\s*(["\']?)([^)]+)\1\s*\)/i',
            function($matches) {
                $url = trim($matches[2]);
                if (preg_match('/^(https?:\/\/|\/|\.\.?\/)/i', $url)) {
                    return 'url(' . $matches[1] . esc_url($url) . $matches[1] . ')';
                }
                return '';
            },
            $css
        );

        return $css;
    }

    /**
     * Filter plugin name for white-label
     */
    public function filter_plugin_name(string $name): string {
        $branding = $this->get_current_branding();
        if (!empty($branding['custom_product_name'])) {
            return $branding['custom_product_name'];
        }
        return $name;
    }

    /**
     * Filter plugin logo
     */
    public function filter_plugin_logo(string $logo_url): string {
        $branding = $this->get_current_branding();
        if (!empty($branding['logo_url'])) {
            return $branding['logo_url'];
        }
        return $logo_url;
    }

    /**
     * Track submission for usage billing
     */
    public function track_submission(int $submission_id): void {
        $this->increment_usage('submissions');
    }

    /**
     * Track API request for usage billing
     */
    public function track_api_request(): void {
        $this->increment_usage('api_calls');
    }

    /**
     * Increment usage counter
     */
    private function increment_usage(string $metric_type): void {
        if (!$this->current_tenant) {
            return;
        }

        global $wpdb;

        $month_bucket = date('Y-m-01');
        $tenant_id = $this->current_tenant['tenant_id'];
        $client_id = $this->current_tenant['client_id'] ?? null;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->table_usage}
             (tenant_id, client_id, metric_type, month_bucket, count)
             VALUES (%d, %d, %s, %s, 1)
             ON DUPLICATE KEY UPDATE count = count + 1",
            $tenant_id,
            $client_id ?: 0,
            $metric_type,
            $month_bucket
        ));
    }

    // =========================================================================
    // Tenant Management
    // =========================================================================

    /**
     * Create a new tenant
     */
    public function create_tenant(array $data): array|\WP_Error {
        global $wpdb;

        // Generate tenant code
        $tenant_code = sanitize_title($data['company_name']);
        $counter = 1;
        $original_code = $tenant_code;

        while ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_tenants} WHERE tenant_code = %s",
            $tenant_code
        )) > 0) {
            $tenant_code = $original_code . '-' . $counter++;
        }

        // Set tier limits
        $limits = $this->get_tier_limits($data['tier'] ?? 'starter');

        $insert_data = [
            'tenant_code' => $tenant_code,
            'company_name' => sanitize_text_field($data['company_name']),
            'contact_name' => sanitize_text_field($data['contact_name'] ?? ''),
            'contact_email' => sanitize_email($data['contact_email']),
            'contact_phone' => sanitize_text_field($data['contact_phone'] ?? ''),
            'tier' => $data['tier'] ?? 'starter',
            'settings' => wp_json_encode($data['settings'] ?? []),
            'limits' => wp_json_encode($limits),
            'billing_email' => sanitize_email($data['billing_email'] ?? $data['contact_email']),
            'subscription_status' => 'trial',
            'trial_ends_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
        ];

        $result = $wpdb->insert($this->table_tenants, $insert_data);

        if ($result === false) {
            return new \WP_Error('db_error', __('Failed to create tenant.', 'formflow'));
        }

        $tenant_id = $wpdb->insert_id;

        // Create default branding profile
        $this->create_branding_profile([
            'tenant_id' => $tenant_id,
            'name' => 'Default',
            'is_default' => 1,
        ]);

        do_action('isf_tenant_created', $tenant_id, $insert_data);

        return [
            'success' => true,
            'tenant_id' => $tenant_id,
            'tenant_code' => $tenant_code,
        ];
    }

    /**
     * Get tier limits
     */
    private function get_tier_limits(string $tier): array {
        $tiers = [
            'starter' => [
                'max_clients' => 5,
                'max_instances' => 10,
                'max_submissions' => 1000,
                'max_api_calls' => 10000,
                'max_storage_mb' => 500,
                'custom_domain' => false,
                'remove_branding' => false,
                'priority_support' => false,
            ],
            'professional' => [
                'max_clients' => 25,
                'max_instances' => 50,
                'max_submissions' => 10000,
                'max_api_calls' => 100000,
                'max_storage_mb' => 5000,
                'custom_domain' => true,
                'remove_branding' => true,
                'priority_support' => false,
            ],
            'enterprise' => [
                'max_clients' => -1, // Unlimited
                'max_instances' => -1,
                'max_submissions' => -1,
                'max_api_calls' => -1,
                'max_storage_mb' => -1,
                'custom_domain' => true,
                'remove_branding' => true,
                'priority_support' => true,
            ],
            'custom' => [
                'max_clients' => -1,
                'max_instances' => -1,
                'max_submissions' => -1,
                'max_api_calls' => -1,
                'max_storage_mb' => -1,
                'custom_domain' => true,
                'remove_branding' => true,
                'priority_support' => true,
            ],
        ];

        return $tiers[$tier] ?? $tiers['starter'];
    }

    /**
     * Get all tenants
     */
    public function get_tenants(array $filters = []): array {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (isset($filters['is_active'])) {
            $where[] = 'is_active = %d';
            $params[] = $filters['is_active'];
        }

        if (!empty($filters['tier'])) {
            $where[] = 'tier = %s';
            $params[] = $filters['tier'];
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$this->table_tenants} WHERE {$where_clause} ORDER BY created_at DESC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $tenants = $wpdb->get_results($sql, ARRAY_A);

        foreach ($tenants as &$tenant) {
            $tenant['settings'] = json_decode($tenant['settings'], true);
            $tenant['limits'] = json_decode($tenant['limits'], true);

            // Get client count
            $tenant['client_count'] = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_clients} WHERE tenant_id = %d AND is_active = 1",
                $tenant['id']
            ));

            // Get current month usage
            $tenant['current_usage'] = $this->get_tenant_usage($tenant['id'], date('Y-m'));
        }

        return $tenants;
    }

    /**
     * Get tenant usage for a month
     */
    public function get_tenant_usage(int $tenant_id, string $month): array {
        global $wpdb;

        $month_start = $month . '-01';

        $usage = $wpdb->get_results($wpdb->prepare(
            "SELECT metric_type, SUM(count) as total
             FROM {$this->table_usage}
             WHERE tenant_id = %d AND month_bucket = %s
             GROUP BY metric_type",
            $tenant_id,
            $month_start
        ), ARRAY_A);

        $result = [
            'submissions' => 0,
            'api_calls' => 0,
            'storage_mb' => 0,
            'emails' => 0,
            'sms' => 0,
        ];

        foreach ($usage as $row) {
            $result[$row['metric_type']] = intval($row['total']);
        }

        return $result;
    }

    // =========================================================================
    // Branding Profiles
    // =========================================================================

    /**
     * Create branding profile
     */
    public function create_branding_profile(array $data): int|\WP_Error {
        global $wpdb;

        $insert_data = array_merge($this->get_default_branding(), [
            'tenant_id' => $data['tenant_id'] ?? null,
            'name' => sanitize_text_field($data['name'] ?? 'Default'),
            'is_default' => $data['is_default'] ?? 0,
        ]);

        // Sanitize color fields
        $color_fields = ['primary_color', 'secondary_color', 'accent_color', 'text_color', 'background_color'];
        foreach ($color_fields as $field) {
            if (isset($data[$field])) {
                $insert_data[$field] = sanitize_hex_color($data[$field]) ?: $insert_data[$field];
            }
        }

        // URL fields
        $url_fields = ['logo_url', 'logo_dark_url', 'favicon_url', 'custom_support_url', 'custom_docs_url'];
        foreach ($url_fields as $field) {
            if (isset($data[$field])) {
                $insert_data[$field] = esc_url_raw($data[$field]);
            }
        }

        // Text fields
        if (isset($data['font_family'])) {
            $insert_data['font_family'] = sanitize_text_field($data['font_family']);
        }
        if (isset($data['custom_product_name'])) {
            $insert_data['custom_product_name'] = sanitize_text_field($data['custom_product_name']);
        }
        if (isset($data['custom_css'])) {
            $insert_data['custom_css'] = wp_strip_all_tags($data['custom_css']);
        }
        if (isset($data['hide_powered_by'])) {
            $insert_data['hide_powered_by'] = $data['hide_powered_by'] ? 1 : 0;
        }

        $result = $wpdb->insert($this->table_branding, $insert_data);

        if ($result === false) {
            return new \WP_Error('db_error', __('Failed to create branding profile.', 'formflow'));
        }

        return $wpdb->insert_id;
    }

    /**
     * Get branding profiles for a tenant
     */
    public function get_branding_profiles(?int $tenant_id = null): array {
        global $wpdb;

        if ($tenant_id) {
            $profiles = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_branding} WHERE tenant_id = %d ORDER BY is_default DESC, name ASC",
                $tenant_id
            ), ARRAY_A);
        } else {
            $profiles = $wpdb->get_results(
                "SELECT * FROM {$this->table_branding} WHERE tenant_id IS NULL ORDER BY name ASC",
                ARRAY_A
            );
        }

        return $profiles;
    }

    // =========================================================================
    // REST API Callbacks
    // =========================================================================

    /**
     * REST: Get tenants
     */
    public function rest_get_tenants(\WP_REST_Request $request): \WP_REST_Response {
        $tenants = $this->get_tenants();
        return new \WP_REST_Response(['data' => $tenants]);
    }

    /**
     * REST: Create tenant
     */
    public function rest_create_tenant(\WP_REST_Request $request): \WP_REST_Response {
        $data = $request->get_json_params();
        $result = $this->create_tenant($data);

        if (is_wp_error($result)) {
            return new \WP_REST_Response(['error' => $result->get_error_message()], 400);
        }

        return new \WP_REST_Response($result, 201);
    }

    /**
     * REST: Get tenant
     */
    public function rest_get_tenant(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $id = intval($request->get_param('id'));

        $tenant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_tenants} WHERE id = %d",
            $id
        ), ARRAY_A);

        if (!$tenant) {
            return new \WP_REST_Response(['error' => 'Not found'], 404);
        }

        $tenant['settings'] = json_decode($tenant['settings'], true);
        $tenant['limits'] = json_decode($tenant['limits'], true);
        $tenant['branding_profiles'] = $this->get_branding_profiles($id);
        $tenant['current_usage'] = $this->get_tenant_usage($id, date('Y-m'));

        return new \WP_REST_Response(['data' => $tenant]);
    }

    /**
     * REST: Get branding
     */
    public function rest_get_branding(\WP_REST_Request $request): \WP_REST_Response {
        $tenant_id = $request->get_param('tenant_id');
        $profiles = $this->get_branding_profiles($tenant_id ? intval($tenant_id) : null);
        return new \WP_REST_Response(['data' => $profiles]);
    }

    /**
     * REST: Save branding
     */
    public function rest_save_branding(\WP_REST_Request $request): \WP_REST_Response {
        $data = $request->get_json_params();

        if (!empty($data['id'])) {
            // Update existing
            global $wpdb;
            $wpdb->update(
                $this->table_branding,
                $this->sanitize_branding_data($data),
                ['id' => intval($data['id'])]
            );
            $profile_id = intval($data['id']);
        } else {
            // Create new
            $result = $this->create_branding_profile($data);
            if (is_wp_error($result)) {
                return new \WP_REST_Response(['error' => $result->get_error_message()], 400);
            }
            $profile_id = $result;
        }

        return new \WP_REST_Response([
            'success' => true,
            'profile_id' => $profile_id,
        ]);
    }

    /**
     * REST: Get usage
     */
    public function rest_get_usage(\WP_REST_Request $request): \WP_REST_Response {
        $tenant_id = intval($request->get_param('tenant_id'));
        $months = min(12, max(1, intval($request->get_param('months') ?: 6)));

        $usage = [];
        for ($i = 0; $i < $months; $i++) {
            $month = date('Y-m', strtotime("-{$i} months"));
            $usage[$month] = $this->get_tenant_usage($tenant_id, $month);
        }

        return new \WP_REST_Response(['data' => array_reverse($usage, true)]);
    }

    /**
     * Sanitize branding data for database
     */
    private function sanitize_branding_data(array $data): array {
        $sanitized = [];

        if (isset($data['name'])) {
            $sanitized['name'] = sanitize_text_field($data['name']);
        }

        $color_fields = ['primary_color', 'secondary_color', 'accent_color', 'text_color', 'background_color'];
        foreach ($color_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = sanitize_hex_color($data[$field]);
            }
        }

        $url_fields = ['logo_url', 'logo_dark_url', 'favicon_url', 'custom_support_url', 'custom_docs_url'];
        foreach ($url_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = esc_url_raw($data[$field]);
            }
        }

        if (isset($data['font_family'])) {
            $sanitized['font_family'] = sanitize_text_field($data['font_family']);
        }
        if (isset($data['custom_product_name'])) {
            $sanitized['custom_product_name'] = sanitize_text_field($data['custom_product_name']);
        }
        if (isset($data['custom_css'])) {
            $sanitized['custom_css'] = wp_strip_all_tags($data['custom_css']);
        }
        if (isset($data['hide_powered_by'])) {
            $sanitized['hide_powered_by'] = $data['hide_powered_by'] ? 1 : 0;
        }
        if (isset($data['is_default'])) {
            $sanitized['is_default'] = $data['is_default'] ? 1 : 0;
        }

        return $sanitized;
    }

    /**
     * Render admin page
     */
    public function render_admin_page(): void {
        include ISF_PLUGIN_DIR . 'admin/views/white-label.php';
    }
}
