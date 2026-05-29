<?php
/**
 * API Platform
 *
 * Core API platform providing REST API v2, API key management,
 * rate limiting, usage tracking, and developer tools.
 *
 * @package FormFlow
 * @subpackage Platform
 * @since 2.7.0
 * @status upcoming
 */

namespace ISF\Platform;

defined('ABSPATH') || exit;

/**
 * Class APIPlatform
 *
 * Provides a comprehensive API platform for developers to integrate
 * with FormFlow programmatically.
 */
class APIPlatform {

    /**
     * API version
     */
    const API_VERSION = '2.0';

    /**
     * Rate limit defaults
     */
    const DEFAULT_RATE_LIMIT = 1000;      // Requests per hour
    const DEFAULT_BURST_LIMIT = 100;       // Requests per minute

    /**
     * Singleton instance
     */
    private static ?APIPlatform $instance = null;

    /**
     * Database tables
     */
    private string $table_keys;
    private string $table_usage;
    private string $table_logs;

    /**
     * Get singleton instance
     */
    public static function instance(): APIPlatform {
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
        $this->table_keys = $wpdb->prefix . 'isf_api_keys';
        $this->table_usage = $wpdb->prefix . 'isf_api_usage';
        $this->table_logs = $wpdb->prefix . 'isf_api_logs';
    }

    /**
     * Initialize the API platform
     */
    public function init(): void {
        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_routes']);

        // Add API authentication filter
        add_filter('rest_pre_dispatch', [$this, 'authenticate_request'], 10, 3);

        // Track API usage
        add_action('rest_post_dispatch', [$this, 'track_usage'], 10, 3);

        // Cron for usage aggregation
        add_action('isf_aggregate_api_usage', [$this, 'aggregate_usage']);

        // Schedule cron if not scheduled
        if (!wp_next_scheduled('isf_aggregate_api_usage')) {
            wp_schedule_event(time(), 'hourly', 'isf_aggregate_api_usage');
        }
    }

    /**
     * Create database tables
     */
    public function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // API Keys table
        $sql_keys = "CREATE TABLE IF NOT EXISTS {$this->table_keys} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            api_key VARCHAR(64) NOT NULL,
            api_secret_hash VARCHAR(255) NOT NULL,
            permissions JSON NOT NULL,
            rate_limit INT UNSIGNED DEFAULT " . self::DEFAULT_RATE_LIMIT . ",
            burst_limit INT UNSIGNED DEFAULT " . self::DEFAULT_BURST_LIMIT . ",
            allowed_ips JSON,
            allowed_origins JSON,
            webhook_url VARCHAR(500),
            webhook_secret VARCHAR(64),
            is_active TINYINT(1) DEFAULT 1,
            last_used_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY api_key (api_key),
            KEY user_id (user_id),
            KEY is_active (is_active)
        ) {$charset_collate};";

        // API Usage tracking (per-hour aggregates)
        $sql_usage = "CREATE TABLE IF NOT EXISTS {$this->table_usage} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            api_key_id INT UNSIGNED NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            method VARCHAR(10) NOT NULL,
            hour_bucket DATETIME NOT NULL,
            request_count INT UNSIGNED DEFAULT 0,
            success_count INT UNSIGNED DEFAULT 0,
            error_count INT UNSIGNED DEFAULT 0,
            total_response_time_ms BIGINT UNSIGNED DEFAULT 0,
            bytes_sent BIGINT UNSIGNED DEFAULT 0,
            bytes_received BIGINT UNSIGNED DEFAULT 0,
            UNIQUE KEY usage_bucket (api_key_id, endpoint, method, hour_bucket),
            KEY hour_bucket (hour_bucket)
        ) {$charset_collate};";

        // Detailed API logs (for debugging, rotated frequently)
        $sql_logs = "CREATE TABLE IF NOT EXISTS {$this->table_logs} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            api_key_id INT UNSIGNED,
            request_id VARCHAR(36) NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            method VARCHAR(10) NOT NULL,
            request_headers JSON,
            request_body JSON,
            response_code INT UNSIGNED,
            response_body JSON,
            response_time_ms INT UNSIGNED,
            ip_address VARCHAR(45),
            user_agent VARCHAR(500),
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY api_key_id (api_key_id),
            KEY request_id (request_id),
            KEY created_at (created_at),
            KEY response_code (response_code)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_keys);
        dbDelta($sql_usage);
        dbDelta($sql_logs);
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        $namespace = 'formflow/v2';

        // === Core Resources ===

        // Instances (Forms)
        register_rest_route($namespace, '/instances', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_instances'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_instance'],
                'permission_callback' => [$this, 'check_write_permission'],
            ],
        ]);

        register_rest_route($namespace, '/instances/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_instance'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update_instance'],
                'permission_callback' => [$this, 'check_write_permission'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_instance'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        // Submissions
        register_rest_route($namespace, '/submissions', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_submissions'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_submission'],
                'permission_callback' => [$this, 'check_write_permission'],
            ],
        ]);

        register_rest_route($namespace, '/submissions/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_submission'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update_submission'],
                'permission_callback' => [$this, 'check_write_permission'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_submission'],
                'permission_callback' => [$this, 'check_delete_permission'],
            ],
        ]);

        // Programs
        register_rest_route($namespace, '/programs', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_programs'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_program'],
                'permission_callback' => [$this, 'check_write_permission'],
            ],
        ]);

        // Analytics
        register_rest_route($namespace, '/analytics/funnel', [
            'methods' => 'GET',
            'callback' => [$this, 'get_funnel_analytics'],
            'permission_callback' => [$this, 'check_analytics_permission'],
        ]);

        register_rest_route($namespace, '/analytics/conversions', [
            'methods' => 'GET',
            'callback' => [$this, 'get_conversion_analytics'],
            'permission_callback' => [$this, 'check_analytics_permission'],
        ]);

        register_rest_route($namespace, '/analytics/attribution', [
            'methods' => 'GET',
            'callback' => [$this, 'get_attribution_analytics'],
            'permission_callback' => [$this, 'check_analytics_permission'],
        ]);

        // Webhooks
        register_rest_route($namespace, '/webhooks', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_webhooks'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_webhook'],
                'permission_callback' => [$this, 'check_write_permission'],
            ],
        ]);

        // === Developer Tools ===

        // API Key info (for authenticated key)
        register_rest_route($namespace, '/me', [
            'methods' => 'GET',
            'callback' => [$this, 'get_current_key_info'],
            'permission_callback' => [$this, 'check_read_permission'],
        ]);

        // Usage statistics
        register_rest_route($namespace, '/usage', [
            'methods' => 'GET',
            'callback' => [$this, 'get_usage_stats'],
            'permission_callback' => [$this, 'check_read_permission'],
        ]);

        // Public by design: health probe for uptime monitors and load
        // balancers. Returns 200 with minimal status payload; no PII.
        register_rest_route($namespace, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'health_check'],
            'permission_callback' => '__return_true',
        ]);

        // Public by design: OpenAPI / Swagger spec endpoint. Standard
        // discovery URL for API clients; cannot leak data on its own.
        register_rest_route($namespace, '/openapi.json', [
            'methods' => 'GET',
            'callback' => [$this, 'get_openapi_spec'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Authenticate API request
     */
    public function authenticate_request($result, $server, $request): mixed {
        $route = $request->get_route();

        // Only check formflow/v2 routes
        if (strpos($route, 'formflow/v2') === false) {
            return $result;
        }

        // Skip auth for public endpoints
        $public_endpoints = ['/health', '/openapi.json'];
        foreach ($public_endpoints as $endpoint) {
            if (strpos($route, $endpoint) !== false) {
                return $result;
            }
        }

        // Check for API key in header
        $api_key = $request->get_header('X-API-Key');
        $api_secret = $request->get_header('X-API-Secret');

        // Also support Authorization: Bearer token format
        if (!$api_key) {
            $auth_header = $request->get_header('Authorization');
            if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
                $api_key = substr($auth_header, 7);
            }
        }

        if (!$api_key) {
            return new \WP_Error(
                'rest_forbidden',
                __('API key required. Provide X-API-Key header or Authorization: Bearer token.', 'formflow'),
                ['status' => 401]
            );
        }

        // Validate API key
        $key_data = $this->validate_api_key($api_key, $api_secret);

        if (is_wp_error($key_data)) {
            return $key_data;
        }

        // Check rate limits
        $rate_check = $this->check_rate_limit($key_data);
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }

        // Check IP restrictions
        if (!empty($key_data['allowed_ips'])) {
            $client_ip = $this->get_client_ip();
            $allowed_ips = json_decode($key_data['allowed_ips'], true) ?: [];
            if (!empty($allowed_ips) && !in_array($client_ip, $allowed_ips)) {
                return new \WP_Error(
                    'rest_forbidden',
                    __('IP address not allowed for this API key.', 'formflow'),
                    ['status' => 403]
                );
            }
        }

        // Check origin restrictions
        $origin = $request->get_header('Origin');
        if (!empty($key_data['allowed_origins']) && $origin) {
            $allowed_origins = json_decode($key_data['allowed_origins'], true) ?: [];
            if (!empty($allowed_origins) && !in_array($origin, $allowed_origins)) {
                return new \WP_Error(
                    'rest_forbidden',
                    __('Origin not allowed for this API key.', 'formflow'),
                    ['status' => 403]
                );
            }
        }

        // Store key data for permission checks
        $request->set_param('_api_key_data', $key_data);

        // Update last used timestamp
        $this->update_last_used($key_data['id']);

        return $result;
    }

    /**
     * Validate API key
     */
    private function validate_api_key(string $api_key, ?string $api_secret = null): array|\WP_Error {
        global $wpdb;

        $key_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_keys} WHERE api_key = %s AND is_active = 1",
                $api_key
            ),
            ARRAY_A
        );

        if (!$key_data) {
            return new \WP_Error(
                'rest_forbidden',
                __('Invalid API key.', 'formflow'),
                ['status' => 401]
            );
        }

        // Check expiration
        if ($key_data['expires_at'] && strtotime($key_data['expires_at']) < time()) {
            return new \WP_Error(
                'rest_forbidden',
                __('API key has expired.', 'formflow'),
                ['status' => 401]
            );
        }

        // If secret is provided, validate it
        if ($api_secret && !password_verify($api_secret, $key_data['api_secret_hash'])) {
            return new \WP_Error(
                'rest_forbidden',
                __('Invalid API secret.', 'formflow'),
                ['status' => 401]
            );
        }

        return $key_data;
    }

    /**
     * Check rate limit
     */
    private function check_rate_limit(array $key_data): true|\WP_Error {
        $key_id = $key_data['id'];
        $rate_limit = $key_data['rate_limit'] ?: self::DEFAULT_RATE_LIMIT;
        $burst_limit = $key_data['burst_limit'] ?: self::DEFAULT_BURST_LIMIT;

        // Check hourly limit
        $hourly_key = 'isf_api_rate_' . $key_id . '_' . date('YmdH');
        $hourly_count = get_transient($hourly_key) ?: 0;

        if ($hourly_count >= $rate_limit) {
            return new \WP_Error(
                'rate_limit_exceeded',
                sprintf(__('Rate limit exceeded. Maximum %d requests per hour.', 'formflow'), $rate_limit),
                [
                    'status' => 429,
                    'headers' => [
                        'X-RateLimit-Limit' => $rate_limit,
                        'X-RateLimit-Remaining' => 0,
                        'X-RateLimit-Reset' => strtotime('+1 hour', strtotime(date('Y-m-d H:00:00'))),
                        'Retry-After' => 3600 - (time() % 3600),
                    ],
                ]
            );
        }

        // Check burst limit (per minute)
        $burst_key = 'isf_api_burst_' . $key_id . '_' . date('YmdHi');
        $burst_count = get_transient($burst_key) ?: 0;

        if ($burst_count >= $burst_limit) {
            return new \WP_Error(
                'rate_limit_exceeded',
                sprintf(__('Burst limit exceeded. Maximum %d requests per minute.', 'formflow'), $burst_limit),
                [
                    'status' => 429,
                    'headers' => [
                        'X-RateLimit-Limit' => $burst_limit,
                        'X-RateLimit-Remaining' => 0,
                        'Retry-After' => 60 - (time() % 60),
                    ],
                ]
            );
        }

        // Increment counters
        set_transient($hourly_key, $hourly_count + 1, HOUR_IN_SECONDS);
        set_transient($burst_key, $burst_count + 1, MINUTE_IN_SECONDS);

        return true;
    }

    /**
     * Track API usage
     */
    public function track_usage($result, $server, $request): void {
        $route = $request->get_route();

        // Only track formflow/v2 routes
        if (strpos($route, 'formflow/v2') === false) {
            return;
        }

        $key_data = $request->get_param('_api_key_data');
        if (!$key_data) {
            return;
        }

        global $wpdb;

        $hour_bucket = date('Y-m-d H:00:00');
        $endpoint = preg_replace('/\/\d+/', '/{id}', $route);
        $method = $request->get_method();
        $is_success = !is_wp_error($result) && ($result->get_status() < 400);

        // Upsert usage record
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->table_usage}
             (api_key_id, endpoint, method, hour_bucket, request_count, success_count, error_count)
             VALUES (%d, %s, %s, %s, 1, %d, %d)
             ON DUPLICATE KEY UPDATE
             request_count = request_count + 1,
             success_count = success_count + %d,
             error_count = error_count + %d",
            $key_data['id'],
            $endpoint,
            $method,
            $hour_bucket,
            $is_success ? 1 : 0,
            $is_success ? 0 : 1,
            $is_success ? 1 : 0,
            $is_success ? 0 : 1
        ));
    }

    /**
     * Permission check: Read access
     */
    public function check_read_permission(\WP_REST_Request $request): bool {
        $key_data = $request->get_param('_api_key_data');
        if (!$key_data) {
            return false;
        }

        $permissions = json_decode($key_data['permissions'], true) ?: [];
        return in_array('read', $permissions) || in_array('*', $permissions);
    }

    /**
     * Permission check: Write access
     */
    public function check_write_permission(\WP_REST_Request $request): bool {
        $key_data = $request->get_param('_api_key_data');
        if (!$key_data) {
            return false;
        }

        $permissions = json_decode($key_data['permissions'], true) ?: [];
        return in_array('write', $permissions) || in_array('*', $permissions);
    }

    /**
     * Permission check: Delete access
     */
    public function check_delete_permission(\WP_REST_Request $request): bool {
        $key_data = $request->get_param('_api_key_data');
        if (!$key_data) {
            return false;
        }

        $permissions = json_decode($key_data['permissions'], true) ?: [];
        return in_array('delete', $permissions) || in_array('*', $permissions);
    }

    /**
     * Permission check: Analytics access
     */
    public function check_analytics_permission(\WP_REST_Request $request): bool {
        $key_data = $request->get_param('_api_key_data');
        if (!$key_data) {
            return false;
        }

        $permissions = json_decode($key_data['permissions'], true) ?: [];
        return in_array('analytics', $permissions) || in_array('*', $permissions);
    }

    // =========================================================================
    // API Key Management
    // =========================================================================

    /**
     * Create a new API key
     */
    public function create_api_key(array $data): array|\WP_Error {
        global $wpdb;

        // Generate secure key and secret
        $api_key = 'ff_' . bin2hex(random_bytes(24)); // ff_xxx... (52 chars)
        $api_secret = bin2hex(random_bytes(32)); // 64 chars
        $api_secret_hash = password_hash($api_secret, PASSWORD_DEFAULT);

        $insert_data = [
            'user_id' => $data['user_id'] ?? get_current_user_id(),
            'name' => sanitize_text_field($data['name'] ?? 'API Key'),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'api_key' => $api_key,
            'api_secret_hash' => $api_secret_hash,
            'permissions' => wp_json_encode($data['permissions'] ?? ['read']),
            'rate_limit' => intval($data['rate_limit'] ?? self::DEFAULT_RATE_LIMIT),
            'burst_limit' => intval($data['burst_limit'] ?? self::DEFAULT_BURST_LIMIT),
            'allowed_ips' => wp_json_encode($data['allowed_ips'] ?? []),
            'allowed_origins' => wp_json_encode($data['allowed_origins'] ?? []),
            'webhook_url' => esc_url_raw($data['webhook_url'] ?? ''),
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] : null,
        ];

        $result = $wpdb->insert($this->table_keys, $insert_data);

        if ($result === false) {
            return new \WP_Error('db_error', __('Failed to create API key.', 'formflow'));
        }

        // Log the creation
        do_action('isf_audit_log', 'api_key_created', 'api_key', $wpdb->insert_id, $data['name']);

        // Return key and secret (secret shown only once!)
        return [
            'id' => $wpdb->insert_id,
            'api_key' => $api_key,
            'api_secret' => $api_secret, // Only returned on creation!
            'name' => $insert_data['name'],
            'permissions' => $data['permissions'] ?? ['read'],
            'message' => __('Save the API secret now! It will not be shown again.', 'formflow'),
        ];
    }

    /**
     * List API keys for a user
     */
    public function get_api_keys(?int $user_id = null): array {
        global $wpdb;

        $user_id = $user_id ?: get_current_user_id();

        $keys = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, description, api_key, permissions, rate_limit, burst_limit,
                    allowed_ips, allowed_origins, webhook_url, is_active, last_used_at,
                    expires_at, created_at
             FROM {$this->table_keys}
             WHERE user_id = %d
             ORDER BY created_at DESC",
            $user_id
        ), ARRAY_A);

        foreach ($keys as &$key) {
            $key['permissions'] = json_decode($key['permissions'], true);
            $key['allowed_ips'] = json_decode($key['allowed_ips'], true);
            $key['allowed_origins'] = json_decode($key['allowed_origins'], true);
            // Mask API key for display
            $key['api_key_masked'] = substr($key['api_key'], 0, 10) . '...' . substr($key['api_key'], -4);
        }

        return $keys;
    }

    /**
     * Revoke an API key
     */
    public function revoke_api_key(int $key_id, ?int $user_id = null): bool {
        global $wpdb;

        $where = ['id' => $key_id];
        if ($user_id) {
            $where['user_id'] = $user_id;
        }

        $result = $wpdb->update($this->table_keys, ['is_active' => 0], $where);

        if ($result !== false) {
            do_action('isf_audit_log', 'api_key_revoked', 'api_key', $key_id);
        }

        return $result !== false;
    }

    /**
     * Update last used timestamp
     */
    private function update_last_used(int $key_id): void {
        global $wpdb;
        $wpdb->update(
            $this->table_keys,
            ['last_used_at' => current_time('mysql')],
            ['id' => $key_id]
        );
    }

    // =========================================================================
    // REST API Callbacks
    // =========================================================================

    /**
     * GET /instances
     */
    public function get_instances(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';

        $page = max(1, intval($request->get_param('page') ?: 1));
        $per_page = min(100, max(1, intval($request->get_param('per_page') ?: 20)));
        $offset = ($page - 1) * $per_page;

        $instances = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        foreach ($instances as &$instance) {
            $instance['settings'] = json_decode($instance['settings'], true);
        }

        return new \WP_REST_Response([
            'data' => $instances,
            'meta' => [
                'total' => intval($total),
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ]);
    }

    /**
     * GET /instances/{id}
     */
    public function get_instance(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';
        $id = intval($request->get_param('id'));

        $instance = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$instance) {
            return new \WP_REST_Response(['error' => 'Instance not found'], 404);
        }

        $instance['settings'] = json_decode($instance['settings'], true);

        return new \WP_REST_Response(['data' => $instance]);
    }

    /**
     * POST /instances
     */
    public function create_instance(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';

        $data = [
            'name' => sanitize_text_field($request->get_param('name')),
            'slug' => sanitize_title($request->get_param('slug') ?: $request->get_param('name')),
            'utility' => sanitize_text_field($request->get_param('utility')),
            'form_type' => sanitize_text_field($request->get_param('form_type') ?: 'enrollment'),
            'settings' => wp_json_encode($request->get_param('settings') ?: []),
            'is_active' => 1,
        ];

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return new \WP_REST_Response(['error' => 'Failed to create instance'], 500);
        }

        $data['id'] = $wpdb->insert_id;
        $data['settings'] = json_decode($data['settings'], true);

        return new \WP_REST_Response(['data' => $data], 201);
    }

    /**
     * GET /submissions
     */
    public function get_submissions(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_submissions';

        $page = max(1, intval($request->get_param('page') ?: 1));
        $per_page = min(100, max(1, intval($request->get_param('per_page') ?: 20)));
        $offset = ($page - 1) * $per_page;

        $where = '1=1';
        $params = [];

        // Filter by instance
        if ($instance_id = $request->get_param('instance_id')) {
            $where .= ' AND instance_id = %d';
            $params[] = intval($instance_id);
        }

        // Filter by status
        if ($status = $request->get_param('status')) {
            $where .= ' AND status = %s';
            $params[] = sanitize_text_field($status);
        }

        // Filter by date range
        if ($date_from = $request->get_param('date_from')) {
            $where .= ' AND created_at >= %s';
            $params[] = sanitize_text_field($date_from);
        }
        if ($date_to = $request->get_param('date_to')) {
            $where .= ' AND created_at <= %s';
            $params[] = sanitize_text_field($date_to);
        }

        $query = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $submissions = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);

        $count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = $wpdb->get_var($wpdb->prepare($count_query, ...array_slice($params, 0, -2)));

        foreach ($submissions as &$submission) {
            $submission['form_data'] = json_decode($submission['form_data'], true);
            // Redact sensitive fields in list view
            if (isset($submission['form_data']['ssn'])) {
                $submission['form_data']['ssn'] = '***-**-' . substr($submission['form_data']['ssn'], -4);
            }
        }

        return new \WP_REST_Response([
            'data' => $submissions,
            'meta' => [
                'total' => intval($total),
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ]);
    }

    /**
     * GET /submissions/{id}
     */
    public function get_submission(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_submissions';
        $id = intval($request->get_param('id'));

        $submission = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$submission) {
            return new \WP_REST_Response(['error' => 'Submission not found'], 404);
        }

        $submission['form_data'] = json_decode($submission['form_data'], true);

        return new \WP_REST_Response(['data' => $submission]);
    }

    /**
     * GET /programs
     */
    public function get_programs(\WP_REST_Request $request): \WP_REST_Response {
        $program_manager = \ISF\Programs\ProgramManager::instance();

        $utility = $request->get_param('utility');
        $instance_id = $request->get_param('instance_id');

        $programs = $program_manager->get_programs($utility, $instance_id);

        return new \WP_REST_Response(['data' => $programs]);
    }

    /**
     * GET /analytics/funnel
     */
    public function get_funnel_analytics(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $instance_id = $request->get_param('instance_id');
        $date_from = $request->get_param('date_from') ?: date('Y-m-d', strtotime('-30 days'));
        $date_to = $request->get_param('date_to') ?: date('Y-m-d');

        // Get funnel data from database class
        $db = new \ISF\Database\Database();
        $funnel = $db->get_funnel_analytics($instance_id, $date_from, $date_to);

        return new \WP_REST_Response(['data' => $funnel]);
    }

    /**
     * GET /me
     */
    public function get_current_key_info(\WP_REST_Request $request): \WP_REST_Response {
        $key_data = $request->get_param('_api_key_data');

        return new \WP_REST_Response([
            'data' => [
                'id' => $key_data['id'],
                'name' => $key_data['name'],
                'permissions' => json_decode($key_data['permissions'], true),
                'rate_limit' => $key_data['rate_limit'],
                'burst_limit' => $key_data['burst_limit'],
                'last_used_at' => $key_data['last_used_at'],
                'expires_at' => $key_data['expires_at'],
                'created_at' => $key_data['created_at'],
            ],
        ]);
    }

    /**
     * GET /usage
     */
    public function get_usage_stats(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $key_data = $request->get_param('_api_key_data');
        $days = min(90, max(1, intval($request->get_param('days') ?: 7)));

        $usage = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(hour_bucket) as date,
                SUM(request_count) as requests,
                SUM(success_count) as successes,
                SUM(error_count) as errors
             FROM {$this->table_usage}
             WHERE api_key_id = %d
               AND hour_bucket >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(hour_bucket)
             ORDER BY date ASC",
            $key_data['id'],
            $days
        ), ARRAY_A);

        // Current rate limit status
        $hourly_key = 'isf_api_rate_' . $key_data['id'] . '_' . date('YmdH');
        $current_usage = get_transient($hourly_key) ?: 0;

        return new \WP_REST_Response([
            'data' => [
                'daily_usage' => $usage,
                'current_hour' => [
                    'requests' => $current_usage,
                    'limit' => $key_data['rate_limit'],
                    'remaining' => max(0, $key_data['rate_limit'] - $current_usage),
                    'resets_at' => strtotime('+1 hour', strtotime(date('Y-m-d H:00:00'))),
                ],
            ],
        ]);
    }

    /**
     * GET /health
     */
    public function health_check(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $db_ok = $wpdb->get_var("SELECT 1") == 1;

        return new \WP_REST_Response([
            'status' => $db_ok ? 'healthy' : 'degraded',
            'version' => self::API_VERSION,
            'timestamp' => current_time('c'),
            'checks' => [
                'database' => $db_ok ? 'ok' : 'error',
            ],
        ]);
    }

    /**
     * GET /openapi.json
     */
    public function get_openapi_spec(\WP_REST_Request $request): \WP_REST_Response {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'FormFlow API',
                'description' => 'API for programmatic access to FormFlow enrollment forms and data.',
                'version' => self::API_VERSION,
                'contact' => [
                    'name' => 'FormFlow Support',
                    'url' => home_url(),
                ],
            ],
            'servers' => [
                [
                    'url' => rest_url('formflow/v2'),
                    'description' => 'Production API',
                ],
            ],
            'security' => [
                ['ApiKeyAuth' => []],
            ],
            'paths' => [
                '/instances' => [
                    'get' => [
                        'summary' => 'List form instances',
                        'tags' => ['Instances'],
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'List of instances'],
                        ],
                    ],
                    'post' => [
                        'summary' => 'Create a form instance',
                        'tags' => ['Instances'],
                        'responses' => [
                            '201' => ['description' => 'Instance created'],
                        ],
                    ],
                ],
                '/submissions' => [
                    'get' => [
                        'summary' => 'List submissions',
                        'tags' => ['Submissions'],
                        'parameters' => [
                            ['name' => 'instance_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
                            ['name' => 'date_from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
                            ['name' => 'date_to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'List of submissions'],
                        ],
                    ],
                ],
                '/programs' => [
                    'get' => [
                        'summary' => 'List available programs',
                        'tags' => ['Programs'],
                        'responses' => [
                            '200' => ['description' => 'List of programs'],
                        ],
                    ],
                ],
                '/analytics/funnel' => [
                    'get' => [
                        'summary' => 'Get funnel analytics',
                        'tags' => ['Analytics'],
                        'responses' => [
                            '200' => ['description' => 'Funnel analytics data'],
                        ],
                    ],
                ],
                '/health' => [
                    'get' => [
                        'summary' => 'Health check',
                        'tags' => ['System'],
                        'security' => [],
                        'responses' => [
                            '200' => ['description' => 'System health status'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'ApiKeyAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-Key',
                    ],
                ],
            ],
        ];

        /**
         * Filter OpenAPI specification
         *
         * @param array $spec The OpenAPI spec
         */
        $spec = apply_filters('isf_openapi_spec', $spec);

        return new \WP_REST_Response($spec);
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    /**
     * Get client IP address
     */
    private function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
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
     * Aggregate usage statistics
     */
    public function aggregate_usage(): void {
        global $wpdb;

        // Clean up old detailed logs (keep 7 days)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_logs} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            7
        ));

        // Clean up old usage data (keep 90 days)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_usage} WHERE hour_bucket < DATE_SUB(NOW(), INTERVAL %d DAY)",
            90
        ));
    }

    /**
     * Get health status for service container
     */
    public function get_health_status(): array {
        global $wpdb;

        $active_keys = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_keys} WHERE is_active = 1");
        $requests_today = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(request_count) FROM {$this->table_usage} WHERE hour_bucket >= %s",
            date('Y-m-d 00:00:00')
        ));

        return [
            'healthy' => true,
            'active_api_keys' => intval($active_keys),
            'requests_today' => intval($requests_today),
            'api_version' => self::API_VERSION,
        ];
    }
}
