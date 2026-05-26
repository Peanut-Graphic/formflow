<?php
/**
 * Marketplace for Templates & Connectors
 *
 * Provides a marketplace for discovering, installing, and managing
 * form templates, API connectors, and third-party integrations.
 *
 * @package FormFlow
 * @subpackage Platform
 * @since 2.7.0
 * @status upcoming
 */

namespace ISF\Platform;

defined('ABSPATH') || exit;

/**
 * Class Marketplace
 *
 * Manages the FormFlow marketplace ecosystem including:
 * - Form templates (pre-built form configurations)
 * - API connectors (integrations with external services)
 * - Themes/skins for form styling
 * - Add-ons and extensions
 */
class Marketplace {

    /**
     * Marketplace API endpoint
     */
    const API_ENDPOINT = 'https://marketplace.formflow.io/api/v1';

    /**
     * Item types
     */
    const TYPE_TEMPLATE = 'template';
    const TYPE_CONNECTOR = 'connector';
    const TYPE_THEME = 'theme';
    const TYPE_ADDON = 'addon';

    /**
     * Singleton instance
     */
    private static ?Marketplace $instance = null;

    /**
     * Database tables
     */
    private string $table_installed;
    private string $table_templates;

    /**
     * Cache key prefix
     */
    private string $cache_prefix = 'isf_marketplace_';

    /**
     * Get singleton instance
     */
    public static function instance(): Marketplace {
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
        $this->table_installed = $wpdb->prefix . 'isf_marketplace_installed';
        $this->table_templates = $wpdb->prefix . 'isf_templates';
    }

    /**
     * Initialize marketplace
     */
    public function init(): void {
        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu'], 25);

        // AJAX handlers
        add_action('wp_ajax_isf_marketplace_browse', [$this, 'ajax_browse']);
        add_action('wp_ajax_isf_marketplace_install', [$this, 'ajax_install']);
        add_action('wp_ajax_isf_marketplace_uninstall', [$this, 'ajax_uninstall']);
        add_action('wp_ajax_isf_marketplace_activate', [$this, 'ajax_activate']);
        add_action('wp_ajax_isf_template_export', [$this, 'ajax_export_template']);
        add_action('wp_ajax_isf_template_import', [$this, 'ajax_import_template']);

        // REST API for marketplace interactions
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Create database tables
     */
    public function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Installed marketplace items
        $sql_installed = "CREATE TABLE IF NOT EXISTS {$this->table_installed} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_id VARCHAR(64) NOT NULL,
            item_type ENUM('template', 'connector', 'theme', 'addon') NOT NULL,
            name VARCHAR(255) NOT NULL,
            version VARCHAR(20) NOT NULL,
            author VARCHAR(100),
            description TEXT,
            settings JSON,
            is_active TINYINT(1) DEFAULT 1,
            installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY item_id (item_id),
            KEY item_type (item_type),
            KEY is_active (is_active)
        ) {$charset_collate};";

        // Local templates library
        $sql_templates = "CREATE TABLE IF NOT EXISTS {$this->table_templates} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            category VARCHAR(50),
            thumbnail_url VARCHAR(500),
            schema JSON NOT NULL,
            settings JSON,
            tags JSON,
            author VARCHAR(100),
            version VARCHAR(20) DEFAULT '1.0.0',
            is_premium TINYINT(1) DEFAULT 0,
            install_count INT UNSIGNED DEFAULT 0,
            rating DECIMAL(2,1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            source ENUM('local', 'marketplace', 'imported') DEFAULT 'local',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY slug (slug),
            KEY category (category),
            KEY is_active (is_active),
            KEY source (source)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_installed);
        dbDelta($sql_templates);

        // Insert default templates
        $this->insert_default_templates();
    }

    /**
     * Insert default form templates
     */
    private function insert_default_templates(): void {
        global $wpdb;

        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_templates}");
        if ($count > 0) {
            return;
        }

        $templates = [
            [
                'slug' => 'demand-response-enrollment',
                'name' => 'Demand Response Enrollment',
                'description' => 'Complete enrollment form for demand response programs including account validation, equipment selection, and appointment scheduling.',
                'category' => 'utility',
                'schema' => wp_json_encode($this->get_demand_response_schema()),
                'tags' => wp_json_encode(['utility', 'demand-response', 'scheduling', 'account-validation']),
                'author' => 'FormFlow',
                'is_premium' => 0,
            ],
            [
                'slug' => 'smart-thermostat-rebate',
                'name' => 'Smart Thermostat Rebate',
                'description' => 'Rebate application form for smart thermostat programs with device verification and instant rebate processing.',
                'category' => 'utility',
                'schema' => wp_json_encode($this->get_thermostat_rebate_schema()),
                'tags' => wp_json_encode(['utility', 'rebate', 'smart-home', 'thermostat']),
                'author' => 'FormFlow',
                'is_premium' => 0,
            ],
            [
                'slug' => 'ev-charging-program',
                'name' => 'EV Charging Program',
                'description' => 'Electric vehicle charging program enrollment with charger registration and time-of-use rate selection.',
                'category' => 'utility',
                'schema' => wp_json_encode($this->get_ev_charging_schema()),
                'tags' => wp_json_encode(['utility', 'ev', 'electric-vehicle', 'charging']),
                'author' => 'FormFlow',
                'is_premium' => 0,
            ],
            [
                'slug' => 'home-energy-audit',
                'name' => 'Home Energy Audit',
                'description' => 'Schedule a home energy audit with comprehensive intake questionnaire.',
                'category' => 'utility',
                'schema' => wp_json_encode($this->get_energy_audit_schema()),
                'tags' => wp_json_encode(['utility', 'energy-audit', 'scheduling', 'efficiency']),
                'author' => 'FormFlow',
                'is_premium' => 0,
            ],
            [
                'slug' => 'contact-form',
                'name' => 'Simple Contact Form',
                'description' => 'Basic contact form with name, email, phone, and message fields.',
                'category' => 'general',
                'schema' => wp_json_encode($this->get_contact_form_schema()),
                'tags' => wp_json_encode(['contact', 'general', 'simple']),
                'author' => 'FormFlow',
                'is_premium' => 0,
            ],
            [
                'slug' => 'service-request',
                'name' => 'Service Request Form',
                'description' => 'Customer service request form with category selection and priority levels.',
                'category' => 'general',
                'schema' => wp_json_encode($this->get_service_request_schema()),
                'tags' => wp_json_encode(['service', 'support', 'request', 'ticket']),
                'author' => 'FormFlow',
                'is_premium' => 0,
            ],
            [
                'slug' => 'multi-program-enrollment',
                'name' => 'Multi-Program Enrollment',
                'description' => 'Enroll customers in multiple programs simultaneously with cross-sell recommendations.',
                'category' => 'utility',
                'schema' => wp_json_encode($this->get_multi_program_schema()),
                'tags' => wp_json_encode(['utility', 'multi-program', 'cross-sell', 'bundle']),
                'author' => 'FormFlow',
                'is_premium' => 1,
            ],
        ];

        foreach ($templates as $template) {
            $wpdb->insert($this->table_templates, $template);
        }
    }

    /**
     * Add admin menu
     *
     * Menu label drops the "New" badge — the storefront cards on this page
     * are still placeholders. Real functionality (template Import/Export)
     * lives inside this page on its own tab. To re-enable the storefront
     * cards: define('ISF_MARKETPLACE_STOREFRONT', true) in wp-config.php
     * (consumed by the marketplace view).
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'isf-dashboard',
            __('Templates', 'formflow'),
            __('Templates', 'formflow'),
            'manage_options',
            'isf-marketplace',
            [$this, 'render_marketplace_page']
        );
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route('isf/v1', '/marketplace/templates', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_templates'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('isf/v1', '/marketplace/templates/(?P<slug>[a-z0-9-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_template'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('isf/v1', '/marketplace/connectors', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_connectors'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Render marketplace page
     */
    public function render_marketplace_page(): void {
        include ISF_PLUGIN_DIR . 'admin/views/marketplace.php';
    }

    /**
     * Get all templates
     */
    public function get_templates(array $filters = []): array {
        global $wpdb;

        $where = ['is_active = 1'];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = 'category = %s';
            $params[] = $filters['category'];
        }

        if (!empty($filters['source'])) {
            $where[] = 'source = %s';
            $params[] = $filters['source'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(name LIKE %s OR description LIKE %s OR tags LIKE %s)';
            $search = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$this->table_templates} WHERE {$where_clause} ORDER BY install_count DESC, name ASC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $templates = $wpdb->get_results($sql, ARRAY_A);

        foreach ($templates as &$template) {
            $template['schema'] = json_decode($template['schema'], true);
            $template['settings'] = json_decode($template['settings'], true);
            $template['tags'] = json_decode($template['tags'], true);
        }

        return $templates;
    }

    /**
     * Get a single template
     */
    public function get_template(string $slug): ?array {
        global $wpdb;

        $template = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_templates} WHERE slug = %s AND is_active = 1",
                $slug
            ),
            ARRAY_A
        );

        if (!$template) {
            return null;
        }

        $template['schema'] = json_decode($template['schema'], true);
        $template['settings'] = json_decode($template['settings'], true);
        $template['tags'] = json_decode($template['tags'], true);

        return $template;
    }

    /**
     * Install a template to create a new instance
     */
    public function install_template(string $slug, string $instance_name, ?string $utility = null): array|\WP_Error {
        $template = $this->get_template($slug);

        if (!$template) {
            return new \WP_Error('not_found', __('Template not found.', 'formflow'));
        }

        global $wpdb;
        $instances_table = $wpdb->prefix . 'isf_instances';

        // Create new instance from template
        $instance_data = [
            'name' => $instance_name,
            'slug' => sanitize_title($instance_name),
            'utility' => $utility ?: 'general',
            'form_type' => 'enrollment',
            'settings' => wp_json_encode(array_merge(
                $template['settings'] ?? [],
                ['form_schema' => $template['schema']]
            )),
            'is_active' => 1,
        ];

        $result = $wpdb->insert($instances_table, $instance_data);

        if ($result === false) {
            return new \WP_Error('db_error', __('Failed to create instance.', 'formflow'));
        }

        // Increment install count
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_templates} SET install_count = install_count + 1 WHERE slug = %s",
            $slug
        ));

        do_action('isf_template_installed', $slug, $wpdb->insert_id, $template);

        return [
            'success' => true,
            'instance_id' => $wpdb->insert_id,
            'message' => __('Template installed successfully!', 'formflow'),
        ];
    }

    /**
     * Export an instance as a template
     */
    public function export_template(int $instance_id): array|\WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';

        $instance = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $instance_id),
            ARRAY_A
        );

        if (!$instance) {
            return new \WP_Error('not_found', __('Instance not found.', 'formflow'));
        }

        $settings = json_decode($instance['settings'], true);

        $export = [
            'formflow_template' => true,
            'version' => '1.0',
            'exported_at' => current_time('c'),
            'template' => [
                'name' => $instance['name'],
                'description' => '',
                'category' => $instance['utility'] ?: 'general',
                'schema' => $settings['form_schema'] ?? [],
                'settings' => array_diff_key($settings, ['form_schema' => '']),
            ],
        ];

        return $export;
    }

    /**
     * Import a template from JSON
     */
    public function import_template(array $template_data): array|\WP_Error {
        if (empty($template_data['formflow_template'])) {
            return new \WP_Error('invalid_format', __('Invalid template format.', 'formflow'));
        }

        $template = $template_data['template'];

        global $wpdb;

        $slug = sanitize_title($template['name']);
        $counter = 1;
        $original_slug = $slug;

        // Ensure unique slug
        while ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_templates} WHERE slug = %s",
            $slug
        )) > 0) {
            $slug = $original_slug . '-' . $counter++;
        }

        $insert_data = [
            'slug' => $slug,
            'name' => sanitize_text_field($template['name']),
            'description' => sanitize_textarea_field($template['description'] ?? ''),
            'category' => sanitize_text_field($template['category'] ?? 'imported'),
            'schema' => wp_json_encode($template['schema']),
            'settings' => wp_json_encode($template['settings'] ?? []),
            'tags' => wp_json_encode($template['tags'] ?? []),
            'author' => 'Imported',
            'source' => 'imported',
        ];

        $result = $wpdb->insert($this->table_templates, $insert_data);

        if ($result === false) {
            return new \WP_Error('db_error', __('Failed to import template.', 'formflow'));
        }

        return [
            'success' => true,
            'template_id' => $wpdb->insert_id,
            'slug' => $slug,
            'message' => __('Template imported successfully!', 'formflow'),
        ];
    }

    /**
     * Get available connectors
     */
    public function get_connectors(): array {
        // Built-in connectors
        $connectors = [
            [
                'id' => 'intellisource',
                'name' => 'IntelliSOURCE',
                'description' => 'Native integration with IntelliSOURCE scheduling and enrollment API.',
                'category' => 'utility',
                'status' => 'built-in',
                'is_active' => true,
                'icon' => 'admin-network',
            ],
            [
                'id' => 'salesforce',
                'name' => 'Salesforce',
                'description' => 'Sync submissions and customer data with Salesforce CRM.',
                'category' => 'crm',
                'status' => 'available',
                'is_active' => false,
                'icon' => 'cloud',
            ],
            [
                'id' => 'hubspot',
                'name' => 'HubSpot',
                'description' => 'Create contacts and deals in HubSpot from form submissions.',
                'category' => 'crm',
                'status' => 'available',
                'is_active' => false,
                'icon' => 'groups',
            ],
            [
                'id' => 'mailchimp',
                'name' => 'Mailchimp',
                'description' => 'Add subscribers to Mailchimp lists from form submissions.',
                'category' => 'marketing',
                'status' => 'available',
                'is_active' => false,
                'icon' => 'email',
            ],
            [
                'id' => 'zapier',
                'name' => 'Zapier',
                'description' => 'Connect to 5,000+ apps through Zapier webhooks.',
                'category' => 'automation',
                'status' => 'available',
                'is_active' => false,
                'icon' => 'admin-links',
            ],
            [
                'id' => 'google-sheets',
                'name' => 'Google Sheets',
                'description' => 'Automatically log submissions to Google Sheets.',
                'category' => 'productivity',
                'status' => 'coming-soon',
                'is_active' => false,
                'icon' => 'media-spreadsheet',
            ],
            [
                'id' => 'slack',
                'name' => 'Slack',
                'description' => 'Send notifications to Slack channels on form events.',
                'category' => 'communication',
                'status' => 'available',
                'is_active' => false,
                'icon' => 'format-chat',
            ],
            [
                'id' => 'twilio',
                'name' => 'Twilio',
                'description' => 'Send SMS notifications using Twilio.',
                'category' => 'communication',
                'status' => 'built-in',
                'is_active' => true,
                'icon' => 'smartphone',
            ],
            [
                'id' => 'stripe',
                'name' => 'Stripe',
                'description' => 'Accept payments through Stripe.',
                'category' => 'payments',
                'status' => 'coming-soon',
                'is_active' => false,
                'icon' => 'money-alt',
            ],
        ];

        /**
         * Filter available connectors
         *
         * @param array $connectors List of connectors
         */
        return apply_filters('isf_marketplace_connectors', $connectors);
    }

    // =========================================================================
    // REST API Callbacks
    // =========================================================================

    /**
     * REST: Get templates
     */
    public function rest_get_templates(\WP_REST_Request $request): \WP_REST_Response {
        $filters = [
            'category' => $request->get_param('category'),
            'source' => $request->get_param('source'),
            'search' => $request->get_param('search'),
        ];

        $templates = $this->get_templates(array_filter($filters));

        return new \WP_REST_Response(['data' => $templates]);
    }

    /**
     * REST: Get single template
     */
    public function rest_get_template(\WP_REST_Request $request): \WP_REST_Response {
        $slug = $request->get_param('slug');
        $template = $this->get_template($slug);

        if (!$template) {
            return new \WP_REST_Response(['error' => 'Not found'], 404);
        }

        return new \WP_REST_Response(['data' => $template]);
    }

    /**
     * REST: Get connectors
     */
    public function rest_get_connectors(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response(['data' => $this->get_connectors()]);
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * AJAX: Browse marketplace
     */
    public function ajax_browse(): void {
        check_ajax_referer('isf_admin_nonce', 'nonce');

        $type = sanitize_text_field($_POST['type'] ?? 'templates');
        $category = sanitize_text_field($_POST['category'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');

        if ($type === 'templates') {
            $items = $this->get_templates(['category' => $category, 'search' => $search]);
        } else {
            $items = $this->get_connectors();
            if ($category) {
                $items = array_filter($items, fn($c) => $c['category'] === $category);
            }
        }

        wp_send_json_success(['items' => array_values($items)]);
    }

    /**
     * AJAX: Install template
     */
    public function ajax_install(): void {
        check_ajax_referer('isf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'formflow')]);
        }

        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $utility = sanitize_text_field($_POST['utility'] ?? '');

        if (!$slug || !$name) {
            wp_send_json_error(['message' => __('Missing required fields.', 'formflow')]);
        }

        $result = $this->install_template($slug, $name, $utility);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Export template
     */
    public function ajax_export_template(): void {
        check_ajax_referer('isf_admin_nonce', 'nonce');

        $instance_id = intval($_POST['instance_id'] ?? 0);

        if (!$instance_id) {
            wp_send_json_error(['message' => __('Invalid instance ID.', 'formflow')]);
        }

        $export = $this->export_template($instance_id);

        if (is_wp_error($export)) {
            wp_send_json_error(['message' => $export->get_error_message()]);
        }

        wp_send_json_success(['export' => $export]);
    }

    /**
     * AJAX: Import template
     */
    public function ajax_import_template(): void {
        check_ajax_referer('isf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'formflow')]);
        }

        $template_json = stripslashes($_POST['template'] ?? '');
        $template_data = json_decode($template_json, true);

        if (!$template_data) {
            wp_send_json_error(['message' => __('Invalid JSON format.', 'formflow')]);
        }

        $result = $this->import_template($template_data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    // =========================================================================
    // Template Schemas
    // =========================================================================

    /**
     * Get demand response enrollment schema
     */
    private function get_demand_response_schema(): array {
        return [
            'version' => '1.0',
            'steps' => [
                [
                    'id' => 'account',
                    'title' => 'Account Information',
                    'fields' => [
                        ['type' => 'account_number', 'name' => 'account_number', 'label' => 'Account Number', 'required' => true],
                        ['type' => 'email', 'name' => 'email', 'label' => 'Email Address', 'required' => true],
                        ['type' => 'phone', 'name' => 'phone', 'label' => 'Phone Number', 'required' => true],
                    ],
                ],
                [
                    'id' => 'address',
                    'title' => 'Service Address',
                    'fields' => [
                        ['type' => 'address', 'name' => 'service_address', 'label' => 'Service Address', 'required' => true],
                    ],
                ],
                [
                    'id' => 'equipment',
                    'title' => 'Equipment',
                    'fields' => [
                        ['type' => 'device_type', 'name' => 'equipment_type', 'label' => 'Equipment Type', 'required' => true],
                        ['type' => 'radio', 'name' => 'equipment_age', 'label' => 'Equipment Age', 'options' => [
                            ['value' => 'new', 'label' => 'Less than 5 years'],
                            ['value' => 'mid', 'label' => '5-10 years'],
                            ['value' => 'old', 'label' => 'More than 10 years'],
                        ]],
                    ],
                ],
                [
                    'id' => 'scheduling',
                    'title' => 'Schedule Installation',
                    'fields' => [
                        ['type' => 'date', 'name' => 'preferred_date', 'label' => 'Preferred Date', 'required' => true],
                        ['type' => 'time', 'name' => 'preferred_time', 'label' => 'Preferred Time Window', 'required' => true],
                    ],
                ],
                [
                    'id' => 'review',
                    'title' => 'Review & Submit',
                    'fields' => [
                        ['type' => 'checkbox', 'name' => 'terms', 'label' => 'I agree to the terms and conditions', 'required' => true],
                        ['type' => 'signature', 'name' => 'signature', 'label' => 'Signature', 'required' => true],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get thermostat rebate schema
     */
    private function get_thermostat_rebate_schema(): array {
        return [
            'version' => '1.0',
            'steps' => [
                [
                    'id' => 'customer',
                    'title' => 'Customer Information',
                    'fields' => [
                        ['type' => 'text', 'name' => 'first_name', 'label' => 'First Name', 'required' => true],
                        ['type' => 'text', 'name' => 'last_name', 'label' => 'Last Name', 'required' => true],
                        ['type' => 'account_number', 'name' => 'account_number', 'label' => 'Account Number', 'required' => true],
                        ['type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true],
                    ],
                ],
                [
                    'id' => 'device',
                    'title' => 'Device Information',
                    'fields' => [
                        ['type' => 'select', 'name' => 'thermostat_brand', 'label' => 'Thermostat Brand', 'required' => true, 'options' => [
                            ['value' => 'nest', 'label' => 'Google Nest'],
                            ['value' => 'ecobee', 'label' => 'ecobee'],
                            ['value' => 'honeywell', 'label' => 'Honeywell'],
                            ['value' => 'emerson', 'label' => 'Emerson Sensi'],
                            ['value' => 'other', 'label' => 'Other'],
                        ]],
                        ['type' => 'text', 'name' => 'serial_number', 'label' => 'Serial Number', 'required' => true],
                        ['type' => 'date', 'name' => 'purchase_date', 'label' => 'Purchase Date', 'required' => true],
                        ['type' => 'file', 'name' => 'receipt', 'label' => 'Upload Receipt', 'required' => true],
                    ],
                ],
                [
                    'id' => 'submit',
                    'title' => 'Submit Application',
                    'fields' => [
                        ['type' => 'checkbox', 'name' => 'certification', 'label' => 'I certify this information is accurate', 'required' => true],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get EV charging schema
     */
    private function get_ev_charging_schema(): array {
        return [
            'version' => '1.0',
            'steps' => [
                [
                    'id' => 'account',
                    'title' => 'Account Verification',
                    'fields' => [
                        ['type' => 'account_number', 'name' => 'account_number', 'label' => 'Account Number', 'required' => true],
                        ['type' => 'address', 'name' => 'service_address', 'label' => 'Service Address', 'required' => true],
                    ],
                ],
                [
                    'id' => 'vehicle',
                    'title' => 'Vehicle Information',
                    'fields' => [
                        ['type' => 'select', 'name' => 'vehicle_make', 'label' => 'Vehicle Make', 'required' => true],
                        ['type' => 'text', 'name' => 'vehicle_model', 'label' => 'Vehicle Model', 'required' => true],
                        ['type' => 'number', 'name' => 'vehicle_year', 'label' => 'Vehicle Year', 'required' => true],
                    ],
                ],
                [
                    'id' => 'charger',
                    'title' => 'Charger Information',
                    'fields' => [
                        ['type' => 'radio', 'name' => 'charger_level', 'label' => 'Charger Level', 'required' => true, 'options' => [
                            ['value' => 'level1', 'label' => 'Level 1 (120V)'],
                            ['value' => 'level2', 'label' => 'Level 2 (240V)'],
                        ]],
                        ['type' => 'radio', 'name' => 'charger_location', 'label' => 'Charger Location', 'options' => [
                            ['value' => 'garage', 'label' => 'Garage'],
                            ['value' => 'driveway', 'label' => 'Driveway'],
                            ['value' => 'other', 'label' => 'Other'],
                        ]],
                    ],
                ],
                [
                    'id' => 'rate',
                    'title' => 'Rate Selection',
                    'fields' => [
                        ['type' => 'radio', 'name' => 'rate_plan', 'label' => 'Select Rate Plan', 'required' => true, 'options' => [
                            ['value' => 'tou', 'label' => 'Time-of-Use Rate (recommended)'],
                            ['value' => 'flat', 'label' => 'Flat Rate'],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get energy audit schema
     */
    private function get_energy_audit_schema(): array {
        return [
            'version' => '1.0',
            'steps' => [
                [
                    'id' => 'contact',
                    'title' => 'Contact Information',
                    'fields' => [
                        ['type' => 'text', 'name' => 'name', 'label' => 'Full Name', 'required' => true],
                        ['type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true],
                        ['type' => 'phone', 'name' => 'phone', 'label' => 'Phone', 'required' => true],
                        ['type' => 'address', 'name' => 'address', 'label' => 'Property Address', 'required' => true],
                    ],
                ],
                [
                    'id' => 'property',
                    'title' => 'Property Details',
                    'fields' => [
                        ['type' => 'select', 'name' => 'property_type', 'label' => 'Property Type', 'required' => true, 'options' => [
                            ['value' => 'single', 'label' => 'Single Family Home'],
                            ['value' => 'townhouse', 'label' => 'Townhouse'],
                            ['value' => 'condo', 'label' => 'Condo/Apartment'],
                            ['value' => 'mobile', 'label' => 'Mobile Home'],
                        ]],
                        ['type' => 'number', 'name' => 'square_feet', 'label' => 'Square Footage'],
                        ['type' => 'number', 'name' => 'year_built', 'label' => 'Year Built'],
                    ],
                ],
                [
                    'id' => 'schedule',
                    'title' => 'Schedule Audit',
                    'fields' => [
                        ['type' => 'date', 'name' => 'preferred_date', 'label' => 'Preferred Date', 'required' => true],
                        ['type' => 'select', 'name' => 'preferred_time', 'label' => 'Preferred Time', 'required' => true, 'options' => [
                            ['value' => 'morning', 'label' => 'Morning (8am-12pm)'],
                            ['value' => 'afternoon', 'label' => 'Afternoon (12pm-5pm)'],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get contact form schema
     */
    private function get_contact_form_schema(): array {
        return [
            'version' => '1.0',
            'steps' => [
                [
                    'id' => 'contact',
                    'title' => 'Contact Us',
                    'fields' => [
                        ['type' => 'text', 'name' => 'name', 'label' => 'Your Name', 'required' => true],
                        ['type' => 'email', 'name' => 'email', 'label' => 'Email Address', 'required' => true],
                        ['type' => 'phone', 'name' => 'phone', 'label' => 'Phone Number'],
                        ['type' => 'textarea', 'name' => 'message', 'label' => 'Message', 'required' => true],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get service request schema
     */
    private function get_service_request_schema(): array {
        return [
            'version' => '1.0',
            'steps' => [
                [
                    'id' => 'request',
                    'title' => 'Service Request',
                    'fields' => [
                        ['type' => 'text', 'name' => 'name', 'label' => 'Your Name', 'required' => true],
                        ['type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true],
                        ['type' => 'account_number', 'name' => 'account_number', 'label' => 'Account Number'],
                        ['type' => 'select', 'name' => 'category', 'label' => 'Request Category', 'required' => true, 'options' => [
                            ['value' => 'billing', 'label' => 'Billing Question'],
                            ['value' => 'service', 'label' => 'Service Issue'],
                            ['value' => 'outage', 'label' => 'Report Outage'],
                            ['value' => 'other', 'label' => 'Other'],
                        ]],
                        ['type' => 'radio', 'name' => 'priority', 'label' => 'Priority', 'options' => [
                            ['value' => 'low', 'label' => 'Low'],
                            ['value' => 'medium', 'label' => 'Medium'],
                            ['value' => 'high', 'label' => 'High'],
                        ]],
                        ['type' => 'textarea', 'name' => 'description', 'label' => 'Description', 'required' => true],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get multi-program enrollment schema
     */
    private function get_multi_program_schema(): array {
        return [
            'version' => '1.0',
            'steps' => [
                [
                    'id' => 'account',
                    'title' => 'Account Verification',
                    'fields' => [
                        ['type' => 'account_number', 'name' => 'account_number', 'label' => 'Account Number', 'required' => true],
                        ['type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true],
                        ['type' => 'address', 'name' => 'service_address', 'label' => 'Service Address', 'required' => true],
                    ],
                ],
                [
                    'id' => 'programs',
                    'title' => 'Select Programs',
                    'fields' => [
                        ['type' => 'program_selector', 'name' => 'selected_programs', 'label' => 'Available Programs', 'required' => true],
                    ],
                ],
                [
                    'id' => 'equipment',
                    'title' => 'Equipment Details',
                    'description' => 'Tell us about your equipment for the selected programs.',
                    'fields' => [
                        ['type' => 'device_type', 'name' => 'equipment', 'label' => 'Equipment', 'required' => true],
                    ],
                ],
                [
                    'id' => 'scheduling',
                    'title' => 'Schedule Installation',
                    'fields' => [
                        ['type' => 'date', 'name' => 'preferred_date', 'label' => 'Preferred Date', 'required' => true],
                        ['type' => 'time', 'name' => 'preferred_time', 'label' => 'Preferred Time', 'required' => true],
                        ['type' => 'checkbox', 'name' => 'bundle_appointments', 'label' => 'Schedule all installations on the same day if possible'],
                    ],
                ],
                [
                    'id' => 'review',
                    'title' => 'Review & Submit',
                    'fields' => [
                        ['type' => 'checkbox', 'name' => 'terms', 'label' => 'I agree to the terms and conditions', 'required' => true],
                        ['type' => 'signature', 'name' => 'signature', 'label' => 'Signature', 'required' => true],
                    ],
                ],
            ],
        ];
    }
}
