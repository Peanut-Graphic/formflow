<?php
/**
 * Multi-Program Manager
 *
 * Manages program definitions, eligibility rules, and multi-program enrollments.
 * Supports program bundling and cross-sell recommendations.
 *
 * @package FormFlow
 * @since 2.6.0
 */

namespace ISF\Programs;

defined('ABSPATH') || exit;

class ProgramManager {

    /**
     * Singleton instance
     */
    private static ?ProgramManager $instance = null;

    /**
     * Database table name
     */
    private string $table_programs;
    private string $table_enrollments;
    private string $table_bundled;

    /**
     * Get singleton instance
     */
    public static function instance(): ProgramManager {
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
        $this->table_programs = $wpdb->prefix . 'isf_programs';
        $this->table_enrollments = $wpdb->prefix . 'isf_program_enrollments';
        $this->table_bundled = $wpdb->prefix . 'isf_bundled_enrollments';
    }

    /**
     * Initialize hooks
     */
    public function init(): void {
        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Ensure tables exist
        add_action('admin_init', [$this, 'maybe_create_tables']);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        // Public by design: catalog of available programs for an
        // instance. Read-only; non-PII metadata. Frontend pulls this
        // to render the program-picker list before a user is signed in.
        register_rest_route('isf/v1', '/programs', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_programs'],
            'permission_callback' => '__return_true',
            'args' => [
                'instance_id' => [
                    'required' => false,
                    'type' => 'integer',
                ],
                'utility' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ]);

        // Public, but rate-limited (DB-heavy).
        register_rest_route('isf/v1', '/programs/eligibility', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_check_eligibility'],
            'permission_callback' => ['\\ISF\\Security', 'rate_limit_public'],
        ]);

        // Public, but rate-limited (DB-heavy).
        register_rest_route('isf/v1', '/programs/recommendations', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_get_recommendations'],
            'permission_callback' => ['\\ISF\\Security', 'rate_limit_public'],
        ]);

        // Write endpoint: requires either an authenticated user or a
        // valid WP REST nonce. Form-submission flows from frontend JS
        // supply the nonce; random POSTs are rejected.
        register_rest_route('isf/v1', '/programs/enroll', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_create_enrollment'],
            'permission_callback' => ['\\ISF\\Security', 'nonce_or_logged_in'],
        ]);

        // Admin routes
        register_rest_route('isf/v1', '/admin/programs', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_admin_get_programs'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('isf/v1', '/admin/programs', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_admin_save_program'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('isf/v1', '/admin/programs/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'rest_admin_delete_program'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Create database tables if they don't exist
     */
    public function maybe_create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Programs table
        $sql_programs = "CREATE TABLE IF NOT EXISTS {$this->table_programs} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id INT UNSIGNED,
            utility VARCHAR(50) NOT NULL,
            program_code VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            short_description VARCHAR(500),
            category ENUM('demand_response', 'energy_efficiency', 'rebate', 'smart_home', 'ev', 'solar', 'other') DEFAULT 'other',
            program_type ENUM('enrollment', 'scheduling', 'both') DEFAULT 'enrollment',
            requires_scheduling TINYINT(1) DEFAULT 0,
            eligibility_rules JSON,
            cross_sell_rules JSON,
            incentive_amount DECIMAL(10,2),
            incentive_type ENUM('one_time', 'recurring', 'per_event', 'none') DEFAULT 'none',
            priority INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            external_api_code VARCHAR(100),
            settings JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY utility_program (utility, program_code),
            KEY instance_id (instance_id),
            KEY category (category),
            KEY is_active (is_active)
        ) {$charset_collate};";

        // Program enrollments table
        $sql_enrollments = "CREATE TABLE IF NOT EXISTS {$this->table_enrollments} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            submission_id INT UNSIGNED NOT NULL,
            program_id INT UNSIGNED NOT NULL,
            account_number VARCHAR(50),
            status ENUM('pending', 'enrolled', 'rejected', 'cancelled') DEFAULT 'pending',
            enrollment_date TIMESTAMP NULL,
            api_response JSON,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY submission_id (submission_id),
            KEY program_id (program_id),
            KEY status (status)
        ) {$charset_collate};";

        // Bundled enrollments table (tracks multi-program submissions)
        $sql_bundled = "CREATE TABLE IF NOT EXISTS {$this->table_bundled} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bundle_id VARCHAR(64) NOT NULL,
            submission_id INT UNSIGNED NOT NULL,
            programs JSON NOT NULL,
            bundled_appointment TINYINT(1) DEFAULT 0,
            appointment_id INT UNSIGNED,
            status ENUM('pending', 'partial', 'complete', 'failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY bundle_id (bundle_id),
            KEY submission_id (submission_id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_programs);
        dbDelta($sql_enrollments);
        dbDelta($sql_bundled);

        // Insert default programs if empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_programs}");
        if ($count == 0) {
            $this->insert_default_programs();
        }
    }

    /**
     * Insert default program definitions
     */
    private function insert_default_programs(): void {
        $default_programs = [
            [
                'utility' => 'pepco',
                'program_code' => 'peak_rewards',
                'name' => 'Peak Rewards',
                'description' => 'Earn bill credits by allowing brief cycling of your central air conditioning or heat pump during peak energy demand periods.',
                'short_description' => 'AC cycling program with bill credits',
                'category' => 'demand_response',
                'program_type' => 'both',
                'requires_scheduling' => 1,
                'incentive_amount' => 80.00,
                'incentive_type' => 'recurring',
                'priority' => 10,
                'eligibility_rules' => json_encode([
                    'equipment' => ['central_ac', 'heat_pump'],
                    'account_status' => 'active',
                    'not_enrolled' => true,
                ]),
                'cross_sell_rules' => json_encode([
                    'recommend_with' => ['smart_thermostat', 'ev_charging'],
                    'bundle_discount' => 10,
                ]),
            ],
            [
                'utility' => 'pepco',
                'program_code' => 'smart_thermostat',
                'name' => 'Smart Thermostat Program',
                'description' => 'Get a free smart thermostat installed and earn rewards for participating in energy-saving events.',
                'short_description' => 'Free smart thermostat with energy rewards',
                'category' => 'smart_home',
                'program_type' => 'both',
                'requires_scheduling' => 1,
                'incentive_amount' => 0.00,
                'incentive_type' => 'one_time',
                'priority' => 8,
                'eligibility_rules' => json_encode([
                    'equipment' => ['central_ac', 'heat_pump', 'gas_furnace'],
                    'account_status' => 'active',
                    'wifi_required' => true,
                ]),
                'cross_sell_rules' => json_encode([
                    'recommend_with' => ['peak_rewards'],
                    'upsell_message' => 'Maximize savings with Peak Rewards!',
                ]),
            ],
            [
                'utility' => 'pepco',
                'program_code' => 'ev_charging',
                'name' => 'EV Charging Program',
                'description' => 'Receive incentives for charging your electric vehicle during off-peak hours.',
                'short_description' => 'EV charging incentives',
                'category' => 'ev',
                'program_type' => 'enrollment',
                'requires_scheduling' => 0,
                'incentive_amount' => 50.00,
                'incentive_type' => 'recurring',
                'priority' => 7,
                'eligibility_rules' => json_encode([
                    'has_ev' => true,
                    'charger_type' => ['level_2', 'level_3'],
                ]),
                'cross_sell_rules' => json_encode([
                    'recommend_with' => ['peak_rewards', 'solar'],
                ]),
            ],
            [
                'utility' => 'delmarva',
                'program_code' => 'energy_wise_rewards',
                'name' => 'Energy Wise Rewards',
                'description' => 'Earn rewards by reducing energy usage during peak demand periods.',
                'short_description' => 'Peak demand reduction rewards',
                'category' => 'demand_response',
                'program_type' => 'both',
                'requires_scheduling' => 1,
                'incentive_amount' => 75.00,
                'incentive_type' => 'recurring',
                'priority' => 10,
                'eligibility_rules' => json_encode([
                    'equipment' => ['central_ac', 'heat_pump'],
                    'account_status' => 'active',
                ]),
                'cross_sell_rules' => json_encode([
                    'recommend_with' => ['home_energy_audit'],
                ]),
            ],
        ];

        global $wpdb;

        foreach ($default_programs as $program) {
            $wpdb->insert($this->table_programs, $program);
        }
    }

    /**
     * REST callback: Get available programs
     */
    public function rest_get_programs(\WP_REST_Request $request): \WP_REST_Response {
        $instance_id = $request->get_param('instance_id');
        $utility = $request->get_param('utility');

        $programs = $this->get_programs($utility, $instance_id);

        return new \WP_REST_Response([
            'success' => true,
            'programs' => $programs,
        ], 200);
    }

    /**
     * REST callback: Check eligibility
     */
    public function rest_check_eligibility(\WP_REST_Request $request): \WP_REST_Response {
        $program_ids = $request->get_param('program_ids') ?: [];
        $customer_data = $request->get_param('customer_data') ?: [];

        $results = [];
        foreach ($program_ids as $program_id) {
            $results[$program_id] = $this->check_eligibility($program_id, $customer_data);
        }

        return new \WP_REST_Response([
            'success' => true,
            'eligibility' => $results,
        ], 200);
    }

    /**
     * REST callback: Get recommendations
     */
    public function rest_get_recommendations(\WP_REST_Request $request): \WP_REST_Response {
        $selected_programs = $request->get_param('selected_programs') ?: [];
        $customer_data = $request->get_param('customer_data') ?: [];
        $utility = $request->get_param('utility');

        $recommendations = $this->get_cross_sell_recommendations($selected_programs, $customer_data, $utility);

        return new \WP_REST_Response([
            'success' => true,
            'recommendations' => $recommendations,
        ], 200);
    }

    /**
     * REST callback: Create enrollment
     */
    public function rest_create_enrollment(\WP_REST_Request $request): \WP_REST_Response {
        $submission_id = $request->get_param('submission_id');
        $program_ids = $request->get_param('program_ids') ?: [];
        $bundle_appointment = $request->get_param('bundle_appointment') ?: false;

        if (empty($program_ids)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'No programs selected',
            ], 400);
        }

        $result = $this->create_multi_program_enrollment($submission_id, $program_ids, $bundle_appointment);

        if (!$result['success']) {
            return new \WP_REST_Response($result, 400);
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * REST callback: Admin get programs
     */
    public function rest_admin_get_programs(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $programs = $wpdb->get_results("SELECT * FROM {$this->table_programs} ORDER BY utility, priority DESC", ARRAY_A);

        foreach ($programs as &$program) {
            $program['eligibility_rules'] = json_decode($program['eligibility_rules'], true);
            $program['cross_sell_rules'] = json_decode($program['cross_sell_rules'], true);
            $program['settings'] = json_decode($program['settings'], true);
        }

        return new \WP_REST_Response([
            'success' => true,
            'programs' => $programs,
        ], 200);
    }

    /**
     * REST callback: Admin save program
     */
    public function rest_admin_save_program(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');
        $data = [
            'instance_id' => $request->get_param('instance_id'),
            'utility' => sanitize_text_field($request->get_param('utility')),
            'program_code' => sanitize_text_field($request->get_param('program_code')),
            'name' => sanitize_text_field($request->get_param('name')),
            'description' => sanitize_textarea_field($request->get_param('description')),
            'short_description' => sanitize_text_field($request->get_param('short_description')),
            'category' => sanitize_text_field($request->get_param('category')),
            'program_type' => sanitize_text_field($request->get_param('program_type')),
            'requires_scheduling' => $request->get_param('requires_scheduling') ? 1 : 0,
            'incentive_amount' => floatval($request->get_param('incentive_amount')),
            'incentive_type' => sanitize_text_field($request->get_param('incentive_type')),
            'priority' => intval($request->get_param('priority')),
            'is_active' => $request->get_param('is_active') ? 1 : 0,
            'external_api_code' => sanitize_text_field($request->get_param('external_api_code')),
            'eligibility_rules' => wp_json_encode($request->get_param('eligibility_rules') ?: []),
            'cross_sell_rules' => wp_json_encode($request->get_param('cross_sell_rules') ?: []),
            'settings' => wp_json_encode($request->get_param('settings') ?: []),
        ];

        global $wpdb;

        if ($id) {
            $wpdb->update($this->table_programs, $data, ['id' => $id]);
            $program_id = $id;
        } else {
            $wpdb->insert($this->table_programs, $data);
            $program_id = $wpdb->insert_id;
        }

        return new \WP_REST_Response([
            'success' => true,
            'program_id' => $program_id,
        ], 200);
    }

    /**
     * REST callback: Admin delete program
     */
    public function rest_admin_delete_program(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');

        global $wpdb;
        $deleted = $wpdb->delete($this->table_programs, ['id' => $id]);

        return new \WP_REST_Response([
            'success' => $deleted !== false,
        ], 200);
    }

    /**
     * Get programs for a utility/instance
     */
    public function get_programs(?string $utility = null, ?int $instance_id = null): array {
        global $wpdb;

        $where = ['is_active = 1'];
        $params = [];

        if ($utility) {
            $where[] = 'utility = %s';
            $params[] = $utility;
        }

        if ($instance_id) {
            $where[] = '(instance_id IS NULL OR instance_id = %d)';
            $params[] = $instance_id;
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT * FROM {$this->table_programs} WHERE {$where_clause} ORDER BY priority DESC, name ASC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $programs = $wpdb->get_results($sql, ARRAY_A);

        foreach ($programs as &$program) {
            $program['eligibility_rules'] = json_decode($program['eligibility_rules'], true) ?: [];
            $program['cross_sell_rules'] = json_decode($program['cross_sell_rules'], true) ?: [];
            $program['settings'] = json_decode($program['settings'], true) ?: [];
        }

        return $programs;
    }

    /**
     * Get a single program by ID
     */
    public function get_program(int $program_id): ?array {
        global $wpdb;

        $program = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_programs} WHERE id = %d", $program_id),
            ARRAY_A
        );

        if (!$program) {
            return null;
        }

        $program['eligibility_rules'] = json_decode($program['eligibility_rules'], true) ?: [];
        $program['cross_sell_rules'] = json_decode($program['cross_sell_rules'], true) ?: [];
        $program['settings'] = json_decode($program['settings'], true) ?: [];

        return $program;
    }

    /**
     * Check eligibility for a program
     *
     * Evaluates customer data against program eligibility rules.
     * Highly customizable through filters for tailored program flows.
     */
    public function check_eligibility(int $program_id, array $customer_data): array {
        $program = $this->get_program($program_id);

        if (!$program) {
            return [
                'eligible' => false,
                'reason' => 'Program not found',
            ];
        }

        /**
         * Filter customer data before eligibility check
         *
         * Allows normalization or enrichment of customer data.
         *
         * @param array $customer_data Customer data to evaluate
         * @param array $program       The program being checked
         */
        $customer_data = apply_filters('isf_eligibility_customer_data', $customer_data, $program);

        /**
         * Filter eligibility rules before evaluation
         *
         * Allows dynamic modification of rules per-instance or per-customer.
         *
         * @param array $rules         Eligibility rules to apply
         * @param array $program       The program being checked
         * @param array $customer_data Customer data being evaluated
         */
        $rules = apply_filters('isf_eligibility_rules', $program['eligibility_rules'], $program, $customer_data);
        $issues = [];

        // Check equipment requirements
        if (!empty($rules['equipment'])) {
            $customer_equipment = $customer_data['equipment'] ?? [];
            if (!is_array($customer_equipment)) {
                $customer_equipment = [$customer_equipment];
            }

            $has_required = array_intersect($rules['equipment'], $customer_equipment);
            if (empty($has_required)) {
                $issues[] = 'Required equipment not present';
            }
        }

        // Check account status
        if (!empty($rules['account_status'])) {
            $customer_status = $customer_data['account_status'] ?? 'unknown';
            if ($customer_status !== $rules['account_status']) {
                $issues[] = 'Account status does not qualify';
            }
        }

        // Check not already enrolled
        if (!empty($rules['not_enrolled']) && !empty($customer_data['account_number'])) {
            if ($this->is_already_enrolled($program_id, $customer_data['account_number'])) {
                $issues[] = 'Already enrolled in this program';
            }
        }

        // Check WiFi requirement
        if (!empty($rules['wifi_required'])) {
            if (empty($customer_data['has_wifi'])) {
                $issues[] = 'WiFi is required for this program';
            }
        }

        // Check EV requirement
        if (!empty($rules['has_ev'])) {
            if (empty($customer_data['has_ev'])) {
                $issues[] = 'Electric vehicle ownership required';
            }
        }

        // Check charger type
        if (!empty($rules['charger_type'])) {
            $customer_charger = $customer_data['charger_type'] ?? '';
            if (!in_array($customer_charger, $rules['charger_type'])) {
                $issues[] = 'Compatible EV charger required';
            }
        }

        // Check service territory
        if (!empty($rules['service_territory']) && !empty($customer_data['coordinates'])) {
            $geocoding = \ISF\Services\GeocodingService::instance();
            $territory_check = $geocoding->check_service_territory(
                $customer_data['coordinates']['latitude'],
                $customer_data['coordinates']['longitude'],
                $program['utility']
            );

            if (!$territory_check['in_territory']) {
                $issues[] = 'Address is outside service territory';
            }
        }

        /**
         * Filter to add custom eligibility checks
         *
         * Allows third-party code to add additional eligibility criteria.
         *
         * @param array $issues        Current list of eligibility issues
         * @param array $program       The program being checked
         * @param array $customer_data Customer data being evaluated
         * @param array $rules         The eligibility rules that were applied
         */
        $issues = apply_filters('isf_eligibility_issues', $issues, $program, $customer_data, $rules);

        $result = [
            'eligible' => empty($issues),
            'issues' => $issues,
            'program' => [
                'id' => $program['id'],
                'name' => $program['name'],
                'incentive_amount' => $program['incentive_amount'],
                'incentive_type' => $program['incentive_type'],
            ],
        ];

        /**
         * Filter the final eligibility result
         *
         * Allows complete override of eligibility determination.
         *
         * @param array $result        Eligibility result with 'eligible', 'issues', 'program' keys
         * @param int   $program_id    The program ID that was checked
         * @param array $customer_data Customer data that was evaluated
         */
        return apply_filters('isf_eligibility_result', $result, $program_id, $customer_data);
    }

    /**
     * Check if customer is already enrolled
     */
    private function is_already_enrolled(int $program_id, string $account_number): bool {
        global $wpdb;

        $enrolled = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_enrollments}
             WHERE program_id = %d AND account_number = %s AND status IN ('enrolled', 'pending')",
            $program_id,
            $account_number
        ));

        return $enrolled > 0;
    }

    /**
     * Get cross-sell recommendations
     */
    public function get_cross_sell_recommendations(array $selected_program_ids, array $customer_data, ?string $utility = null): array {
        $recommendations = [];
        $already_recommended = [];

        foreach ($selected_program_ids as $program_id) {
            $program = $this->get_program($program_id);
            if (!$program) continue;

            $cross_sell_rules = $program['cross_sell_rules'];
            $recommend_with = $cross_sell_rules['recommend_with'] ?? [];

            foreach ($recommend_with as $rec_code) {
                if (in_array($rec_code, $already_recommended)) {
                    continue;
                }

                // Find the recommended program
                $rec_program = $this->get_program_by_code($rec_code, $utility ?: $program['utility']);
                if (!$rec_program || in_array($rec_program['id'], $selected_program_ids)) {
                    continue;
                }

                // Check eligibility
                $eligibility = $this->check_eligibility($rec_program['id'], $customer_data);

                if ($eligibility['eligible']) {
                    $recommendations[] = [
                        'program' => $rec_program,
                        'reason' => $cross_sell_rules['upsell_message'] ?? 'Recommended based on your selections',
                        'bundle_discount' => $cross_sell_rules['bundle_discount'] ?? 0,
                        'recommended_by' => $program['name'],
                    ];
                    $already_recommended[] = $rec_code;
                }
            }
        }

        // Sort by priority
        usort($recommendations, function($a, $b) {
            return ($b['program']['priority'] ?? 0) - ($a['program']['priority'] ?? 0);
        });

        return $recommendations;
    }

    /**
     * Get program by code
     */
    public function get_program_by_code(string $code, string $utility): ?array {
        global $wpdb;

        $program = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_programs} WHERE program_code = %s AND utility = %s AND is_active = 1",
                $code,
                $utility
            ),
            ARRAY_A
        );

        if (!$program) {
            return null;
        }

        $program['eligibility_rules'] = json_decode($program['eligibility_rules'], true) ?: [];
        $program['cross_sell_rules'] = json_decode($program['cross_sell_rules'], true) ?: [];
        $program['settings'] = json_decode($program['settings'], true) ?: [];

        return $program;
    }

    /**
     * Create multi-program enrollment
     */
    public function create_multi_program_enrollment(int $submission_id, array $program_ids, bool $bundle_appointment = false): array {
        global $wpdb;

        // Generate bundle ID
        $bundle_id = 'bundle_' . wp_generate_password(12, false);

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Create individual enrollments
            $enrollment_ids = [];
            $programs_data = [];

            foreach ($program_ids as $program_id) {
                $program = $this->get_program($program_id);
                if (!$program) {
                    continue;
                }

                $inserted = $wpdb->insert($this->table_enrollments, [
                    'submission_id' => $submission_id,
                    'program_id' => $program_id,
                    'status' => 'pending',
                ]);

                if ($inserted) {
                    $enrollment_ids[] = $wpdb->insert_id;
                    $programs_data[] = [
                        'program_id' => $program_id,
                        'program_code' => $program['program_code'],
                        'name' => $program['name'],
                        'enrollment_id' => $wpdb->insert_id,
                    ];
                }
            }

            if (empty($enrollment_ids)) {
                throw new \Exception('No enrollments created');
            }

            // Create bundle record
            $wpdb->insert($this->table_bundled, [
                'bundle_id' => $bundle_id,
                'submission_id' => $submission_id,
                'programs' => wp_json_encode($programs_data),
                'bundled_appointment' => $bundle_appointment ? 1 : 0,
                'status' => 'pending',
            ]);

            $wpdb->query('COMMIT');

            return [
                'success' => true,
                'bundle_id' => $bundle_id,
                'enrollment_ids' => $enrollment_ids,
                'programs' => $programs_data,
                'bundled_appointment' => $bundle_appointment,
            ];

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update enrollment status
     */
    public function update_enrollment_status(int $enrollment_id, string $status, ?array $api_response = null, ?string $notes = null): bool {
        global $wpdb;

        $data = ['status' => $status];

        if ($status === 'enrolled') {
            $data['enrollment_date'] = current_time('mysql');
        }

        if ($api_response !== null) {
            $data['api_response'] = wp_json_encode($api_response);
        }

        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        $updated = $wpdb->update($this->table_enrollments, $data, ['id' => $enrollment_id]);

        // Update bundle status if applicable
        $this->update_bundle_status_from_enrollments($enrollment_id);

        return $updated !== false;
    }

    /**
     * Update bundle status based on enrollment statuses
     */
    private function update_bundle_status_from_enrollments(int $enrollment_id): void {
        global $wpdb;

        // Find bundle for this enrollment
        $enrollment = $wpdb->get_row(
            $wpdb->prepare("SELECT submission_id FROM {$this->table_enrollments} WHERE id = %d", $enrollment_id),
            ARRAY_A
        );

        if (!$enrollment) return;

        $bundle = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_bundled} WHERE submission_id = %d", $enrollment['submission_id']),
            ARRAY_A
        );

        if (!$bundle) return;

        // Get all enrollment statuses for this bundle
        $programs = json_decode($bundle['programs'], true);
        $enrollment_ids = array_column($programs, 'enrollment_id');

        if (empty($enrollment_ids)) return;

        $placeholders = implode(',', array_fill(0, count($enrollment_ids), '%d'));
        $statuses = $wpdb->get_col($wpdb->prepare(
            "SELECT status FROM {$this->table_enrollments} WHERE id IN ($placeholders)",
            ...$enrollment_ids
        ));

        // Determine bundle status
        $all_enrolled = !in_array('pending', $statuses) && !in_array('rejected', $statuses) && !in_array('cancelled', $statuses);
        $any_enrolled = in_array('enrolled', $statuses);
        $all_failed = !in_array('enrolled', $statuses) && !in_array('pending', $statuses);

        if ($all_enrolled) {
            $bundle_status = 'complete';
        } elseif ($all_failed) {
            $bundle_status = 'failed';
        } elseif ($any_enrolled) {
            $bundle_status = 'partial';
        } else {
            $bundle_status = 'pending';
        }

        $wpdb->update($this->table_bundled, ['status' => $bundle_status], ['id' => $bundle['id']]);
    }

    /**
     * Get bundle details
     */
    public function get_bundle(string $bundle_id): ?array {
        global $wpdb;

        $bundle = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_bundled} WHERE bundle_id = %s", $bundle_id),
            ARRAY_A
        );

        if (!$bundle) return null;

        $bundle['programs'] = json_decode($bundle['programs'], true);

        // Get enrollment details
        $programs = json_decode($bundle['programs'], true) ?: [];
        $enrollment_ids = array_column($programs, 'enrollment_id');

        if (!empty($enrollment_ids)) {
            $placeholders = implode(',', array_fill(0, count($enrollment_ids), '%d'));
            $enrollments = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_enrollments} WHERE id IN ($placeholders)",
                ...$enrollment_ids
            ), ARRAY_A);

            $bundle['enrollments'] = $enrollments;
        }

        return $bundle;
    }

    /**
     * Get programs requiring scheduling
     */
    public function get_scheduling_programs(array $program_ids): array {
        $scheduling_programs = [];

        foreach ($program_ids as $program_id) {
            $program = $this->get_program($program_id);
            if ($program && $program['requires_scheduling']) {
                $scheduling_programs[] = $program;
            }
        }

        return $scheduling_programs;
    }

    /**
     * Calculate total incentives for selected programs
     */
    public function calculate_incentives(array $program_ids): array {
        $one_time = 0;
        $recurring = 0;
        $per_event = 0;
        $programs_with_incentives = [];

        foreach ($program_ids as $program_id) {
            $program = $this->get_program($program_id);
            if (!$program || $program['incentive_type'] === 'none') {
                continue;
            }

            $amount = floatval($program['incentive_amount']);

            switch ($program['incentive_type']) {
                case 'one_time':
                    $one_time += $amount;
                    break;
                case 'recurring':
                    $recurring += $amount;
                    break;
                case 'per_event':
                    $per_event += $amount;
                    break;
            }

            $programs_with_incentives[] = [
                'name' => $program['name'],
                'amount' => $amount,
                'type' => $program['incentive_type'],
            ];
        }

        return [
            'one_time_total' => $one_time,
            'recurring_total' => $recurring,
            'per_event_total' => $per_event,
            'estimated_annual' => $one_time + ($recurring * 12),
            'programs' => $programs_with_incentives,
        ];
    }
}
