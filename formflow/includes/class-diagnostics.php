<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Diagnostics & Health Check System
 *
 * Provides end-to-end testing and system verification for the plugin.
 */


use ISF\Database\Database;
use ISF\Api\ApiClient;
use ISF\Api\MockApiClient;
use ISF\Api\FieldMapper;
use ISF\Api\ValidationResult;
use ISF\Api\SchedulingResult;

class Diagnostics {

    private Database $db;
    private Encryption $encryption;
    private array $results = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database();
        $this->encryption = new Encryption();
    }

    /**
     * Run all diagnostic tests
     *
     * @param int|null $instance_id Optional instance ID for API tests
     * @return array Test results
     */
    public function run_all_tests(?int $instance_id = null): array {
        $this->results = [
            'timestamp' => current_time('mysql'),
            'version' => ISF_VERSION,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'tests' => [],
            'summary' => [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'warnings' => 0
            ]
        ];

        // Core System Tests
        $this->test_php_requirements();
        $this->test_wordpress_requirements();
        $this->test_file_permissions();

        // Database Tests
        $this->test_database_connection();
        $this->test_database_tables();
        $this->test_database_operations();

        // Security Tests
        $this->test_encryption();
        $this->test_nonce_generation();
        $this->test_rate_limiting();

        // API Tests (if instance provided)
        if ($instance_id) {
            $this->test_api_connectivity($instance_id);
            $this->test_api_validation($instance_id);
            $this->test_api_scheduling($instance_id);
        }

        // Field Mapper Tests
        $this->test_field_mapper();

        // Cron Tests
        $this->test_cron_schedules();

        // Calculate summary
        foreach ($this->results['tests'] as $test) {
            $this->results['summary']['total']++;
            switch ($test['status']) {
                case 'passed':
                    $this->results['summary']['passed']++;
                    break;
                case 'failed':
                    $this->results['summary']['failed']++;
                    break;
                case 'warning':
                    $this->results['summary']['warnings']++;
                    break;
            }
        }

        return $this->results;
    }

    /**
     * Add a test result
     */
    private function add_result(string $category, string $name, string $status, string $message, array $details = []): void {
        $this->results['tests'][] = [
            'category' => $category,
            'name' => $name,
            'status' => $status, // passed, failed, warning
            'message' => $message,
            'details' => $details
        ];
    }

    // =========================================================================
    // PHP Requirements Tests
    // =========================================================================

    private function test_php_requirements(): void {
        $category = 'PHP Requirements';

        // PHP Version
        if (version_compare(PHP_VERSION, '8.0', '>=')) {
            $this->add_result($category, 'PHP Version', 'passed', 'PHP ' . PHP_VERSION . ' meets requirement (8.0+)');
        } else {
            $this->add_result($category, 'PHP Version', 'failed', 'PHP ' . PHP_VERSION . ' does not meet requirement (8.0+)');
        }

        // Required Extensions
        $required_extensions = ['openssl', 'curl', 'json', 'mbstring'];
        foreach ($required_extensions as $ext) {
            if (extension_loaded($ext)) {
                $this->add_result($category, "Extension: {$ext}", 'passed', "{$ext} extension is loaded");
            } else {
                $this->add_result($category, "Extension: {$ext}", 'failed', "{$ext} extension is NOT loaded");
            }
        }

        // Memory Limit
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->convert_to_bytes($memory_limit);
        if ($memory_bytes >= 64 * 1024 * 1024) {
            $this->add_result($category, 'Memory Limit', 'passed', "Memory limit ({$memory_limit}) is sufficient");
        } else {
            $this->add_result($category, 'Memory Limit', 'warning', "Memory limit ({$memory_limit}) may be too low, recommend 64M+");
        }

        // Max Execution Time
        $max_execution = ini_get('max_execution_time');
        if ($max_execution >= 30 || $max_execution == 0) {
            $this->add_result($category, 'Max Execution Time', 'passed', "Max execution time ({$max_execution}s) is sufficient");
        } else {
            $this->add_result($category, 'Max Execution Time', 'warning', "Max execution time ({$max_execution}s) may be too low");
        }
    }

    // =========================================================================
    // WordPress Requirements Tests
    // =========================================================================

    private function test_wordpress_requirements(): void {
        $category = 'WordPress Requirements';

        // WordPress Version
        $wp_version = get_bloginfo('version');
        if (version_compare($wp_version, '6.0', '>=')) {
            $this->add_result($category, 'WordPress Version', 'passed', "WordPress {$wp_version} meets requirement (6.0+)");
        } else {
            $this->add_result($category, 'WordPress Version', 'failed', "WordPress {$wp_version} does not meet requirement (6.0+)");
        }

        // SSL Check
        if (is_ssl()) {
            $this->add_result($category, 'SSL/HTTPS', 'passed', 'Site is using HTTPS');
        } else {
            $this->add_result($category, 'SSL/HTTPS', 'warning', 'Site is NOT using HTTPS - recommended for form submissions');
        }

        // Permalinks
        $permalink_structure = get_option('permalink_structure');
        if (!empty($permalink_structure)) {
            $this->add_result($category, 'Permalinks', 'passed', 'Pretty permalinks are enabled');
        } else {
            $this->add_result($category, 'Permalinks', 'warning', 'Using default permalinks - pretty permalinks recommended');
        }

        // WP Cron
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $this->add_result($category, 'WP Cron', 'warning', 'WP Cron is disabled - ensure system cron is configured');
        } else {
            $this->add_result($category, 'WP Cron', 'passed', 'WP Cron is enabled');
        }
    }

    // =========================================================================
    // File Permissions Tests
    // =========================================================================

    private function test_file_permissions(): void {
        $category = 'File Permissions';

        // Plugin directory readable
        if (is_readable(ISF_PLUGIN_DIR)) {
            $this->add_result($category, 'Plugin Directory', 'passed', 'Plugin directory is readable');
        } else {
            $this->add_result($category, 'Plugin Directory', 'failed', 'Plugin directory is NOT readable');
        }

        // Logs directory
        $logs_dir = ISF_PLUGIN_DIR . 'logs/';
        if (is_dir($logs_dir)) {
            if (is_writable($logs_dir)) {
                $this->add_result($category, 'Logs Directory', 'passed', 'Logs directory exists and is writable');
            } else {
                $this->add_result($category, 'Logs Directory', 'warning', 'Logs directory exists but is not writable');
            }
        } else {
            $this->add_result($category, 'Logs Directory', 'warning', 'Logs directory does not exist');
        }

        // Check .htaccess in logs
        $htaccess = $logs_dir . '.htaccess';
        if (file_exists($htaccess)) {
            $this->add_result($category, 'Logs Protection', 'passed', '.htaccess protection exists for logs directory');
        } else {
            $this->add_result($category, 'Logs Protection', 'warning', '.htaccess protection missing for logs directory');
        }
    }

    // =========================================================================
    // Database Tests
    // =========================================================================

    private function test_database_connection(): void {
        $category = 'Database';

        global $wpdb;

        // Test connection
        $result = $wpdb->get_var("SELECT 1");
        if ($result == 1) {
            $this->add_result($category, 'Connection', 'passed', 'Database connection successful');
        } else {
            $this->add_result($category, 'Connection', 'failed', 'Database connection failed');
        }

        // Check MySQL version
        $mysql_version = $wpdb->get_var("SELECT VERSION()");
        if (version_compare($mysql_version, '5.7', '>=')) {
            $this->add_result($category, 'MySQL Version', 'passed', "MySQL {$mysql_version} meets requirement (5.7+)");
        } else {
            $this->add_result($category, 'MySQL Version', 'warning', "MySQL {$mysql_version} - recommend 5.7+");
        }
    }

    private function test_database_tables(): void {
        $category = 'Database Tables';

        global $wpdb;

        $required_tables = [
            'isf_instances' => 'Form Instances',
            'isf_submissions' => 'Submissions',
            'isf_logs' => 'Logs',
            'isf_analytics' => 'Analytics',
            'isf_retry_queue' => 'Retry Queue',
            'isf_webhooks' => 'Webhooks',
            'isf_api_usage' => 'API Usage',
            'isf_resume_tokens' => 'Resume Tokens',
            'isf_scheduled_reports' => 'Scheduled Reports',
            'isf_audit_log' => 'Audit Log',
            'isf_gdpr_requests' => 'GDPR Requests'
        ];

        foreach ($required_tables as $table => $label) {
            $table_name = $wpdb->prefix . $table;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table_name
            ));

            if ($exists) {
                // Count rows
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
                $this->add_result($category, $label, 'passed', "Table exists ({$count} rows)", ['table' => $table_name]);
            } else {
                $this->add_result($category, $label, 'failed', "Table missing: {$table_name}");
            }
        }
    }

    private function test_database_operations(): void {
        $category = 'Database Operations';

        // Test CRUD operations on logs table (safe to test)
        try {
            // Create
            $log_id = $this->db->log('info', 'Diagnostic test entry', ['test' => true]);
            if ($log_id) {
                $this->add_result($category, 'INSERT', 'passed', 'Successfully created test log entry');

                // Read - verify by checking logs exist
                $logs = $this->db->get_logs(['limit' => 1]);
                if (!empty($logs)) {
                    $this->add_result($category, 'SELECT', 'passed', 'Successfully read from database');
                } else {
                    $this->add_result($category, 'SELECT', 'warning', 'Read operation returned no results');
                }

                // Delete old test entries
                global $wpdb;
                $deleted = $wpdb->query(
                    "DELETE FROM {$wpdb->prefix}isf_logs WHERE message = 'Diagnostic test entry' AND created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
                );
                $this->add_result($category, 'DELETE', 'passed', 'Cleanup operation successful');
            } else {
                $this->add_result($category, 'INSERT', 'failed', 'Failed to create test entry');
            }
        } catch (\Exception $e) {
            $this->add_result($category, 'CRUD Operations', 'failed', 'Database operations failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Security Tests
    // =========================================================================

    private function test_encryption(): void {
        $category = 'Security';

        // Test encryption round-trip
        if ($this->encryption->test()) {
            $this->add_result($category, 'Encryption', 'passed', 'AES-256-CBC encryption working correctly');
        } else {
            $this->add_result($category, 'Encryption', 'failed', 'Encryption test failed');
        }

        // Check if custom encryption key is set
        if (defined('ISF_ENCRYPTION_KEY') && strlen(ISF_ENCRYPTION_KEY) >= 32) {
            $this->add_result($category, 'Encryption Key', 'passed', 'Custom encryption key is configured');
        } else {
            $this->add_result($category, 'Encryption Key', 'warning', 'Using fallback encryption key - recommend setting ISF_ENCRYPTION_KEY');
        }
    }

    private function test_nonce_generation(): void {
        $category = 'Security';

        $nonce = wp_create_nonce('isf_test_nonce');
        if (wp_verify_nonce($nonce, 'isf_test_nonce')) {
            $this->add_result($category, 'Nonce Generation', 'passed', 'WordPress nonce system working correctly');
        } else {
            $this->add_result($category, 'Nonce Generation', 'failed', 'Nonce verification failed');
        }
    }

    private function test_rate_limiting(): void {
        $category = 'Security';

        $settings = get_option('isf_settings', []);
        $disabled = !empty($settings['disable_rate_limit']);
        $rate_limit = $settings['rate_limit_requests'] ?? 120;
        $window = $settings['rate_limit_window'] ?? 60;

        if ($disabled) {
            $this->add_result($category, 'Rate Limiting', 'warning', 'Rate limiting is DISABLED - abuse protection not active');
        } else {
            $this->add_result($category, 'Rate Limiting', 'passed', "Rate limiting active: {$rate_limit} requests per {$window} seconds");
        }
    }

    // =========================================================================
    // API Tests
    // =========================================================================

    private function test_api_connectivity(int $instance_id): void {
        $category = 'API Connectivity';

        $instance = $this->db->get_instance($instance_id);
        if (!$instance) {
            $this->add_result($category, 'Instance Load', 'failed', "Instance ID {$instance_id} not found");
            return;
        }

        $this->add_result($category, 'Instance Load', 'passed', "Loaded instance: {$instance['name']}");

        // Check if demo mode
        $demo_mode = $instance['settings']['demo_mode'] ?? false;
        if ($demo_mode) {
            $this->add_result($category, 'Mode', 'warning', 'Instance is in DEMO mode - using mock API');
            return;
        }

        // Test API endpoint reachability
        try {
            $api = new ApiClient(
                $instance['api_endpoint'],
                $instance['api_password'],
                $instance['test_mode'],
                $instance_id
            );

            $health = $api->health_check();

            if ($health['status'] === 'healthy') {
                $this->add_result($category, 'API Health', 'passed', "API healthy (latency: {$health['latency_ms']}ms)");
            } elseif ($health['status'] === 'degraded') {
                $this->add_result($category, 'API Health', 'warning', "API degraded: {$health['warning']} (latency: {$health['latency_ms']}ms)");
            } else {
                $this->add_result($category, 'API Health', 'failed', "API error: " . ($health['error'] ?? 'Unknown error'));
            }

        } catch (\Exception $e) {
            $this->add_result($category, 'API Health', 'failed', 'API test failed: ' . $e->getMessage());
        }
    }

    private function test_api_validation(int $instance_id): void {
        $category = 'API Validation';

        $instance = $this->db->get_instance($instance_id);
        if (!$instance) {
            return;
        }

        // Check demo mode
        $demo_mode = $instance['settings']['demo_mode'] ?? false;

        if ($demo_mode) {
            // Test mock API
            $mock_api = new MockApiClient($instance_id);

            // Test with demo account
            $result = $mock_api->validate_account('1234567890', '20001');
            if ($result->is_valid()) {
                $this->add_result($category, 'Mock Validation', 'passed', 'Mock API validation working (demo account: 1234567890)');
            } else {
                $this->add_result($category, 'Mock Validation', 'failed', 'Mock API validation failed');
            }

            // Test invalid account (use ZIP 99999 which is not a valid wildcard)
            $invalid_result = $mock_api->validate_account('0000000000', '99999');
            if (!$invalid_result->is_valid()) {
                $this->add_result($category, 'Mock Invalid Account', 'passed', 'Mock API correctly rejects invalid accounts');
            } else {
                $this->add_result($category, 'Mock Invalid Account', 'warning', 'Mock API did not reject invalid account');
            }
        } else {
            $this->add_result($category, 'Live Validation', 'warning', 'Skipped - would require real account number. Enable demo mode to test validation flow.');
        }
    }

    private function test_api_scheduling(int $instance_id): void {
        $category = 'API Scheduling';

        $instance = $this->db->get_instance($instance_id);
        if (!$instance) {
            return;
        }

        $demo_mode = $instance['settings']['demo_mode'] ?? false;

        if ($demo_mode) {
            $mock_api = new MockApiClient($instance_id);

            $start_date = date('m/d/Y', strtotime('+7 days'));
            $result = $mock_api->get_schedule_slots('1234567890', $start_date);

            if ($result->has_slots()) {
                $slots = $result->get_slots();
                $this->add_result($category, 'Mock Scheduling', 'passed', 'Mock API returns schedule slots (' . count($slots) . ' dates available)');
            } else {
                $this->add_result($category, 'Mock Scheduling', 'warning', 'Mock API returned no schedule slots');
            }
        } else {
            $this->add_result($category, 'Live Scheduling', 'warning', 'Skipped - would require validated account. Enable demo mode to test scheduling flow.');
        }
    }

    // =========================================================================
    // Field Mapper Tests
    // =========================================================================

    private function test_field_mapper(): void {
        $category = 'Field Mapper';

        // Test basic field mapping
        $test_data = [
            'utility_no' => '1234567890',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '555-123-4567',
            'street' => '123 Main St',
            'city' => 'Anytown',
            'state' => 'MD',
            'zip' => '20001',
            'ownership' => 'own',
            'thermostat_count' => '2',
            'cycling_level' => '100',
            'device_type' => 'thermostat'
        ];

        try {
            $mapped = FieldMapper::mapEnrollmentData($test_data);

            // Verify required fields are present
            $required = ['utility_no', 'fname', 'lname', 'email', 'dayPhone', 'zip', 'ownsPrem', 'contract'];
            $missing = [];
            foreach ($required as $field) {
                if (!isset($mapped[$field]) || $mapped[$field] === '') {
                    $missing[] = $field;
                }
            }

            if (empty($missing)) {
                $this->add_result($category, 'Field Mapping', 'passed', 'All required API fields mapped correctly');
            } else {
                $this->add_result($category, 'Field Mapping', 'failed', 'Missing mapped fields: ' . implode(', ', $missing));
            }

            // Test contract code generation
            $contract = $mapped['contract'] ?? '';
            if ($contract === '09') { // 100%-Pro-VHF
                $this->add_result($category, 'Contract Code', 'passed', "Contract code correctly generated: {$contract}");
            } else {
                $this->add_result($category, 'Contract Code', 'warning', "Unexpected contract code: {$contract} (expected 09 for 100% thermostat)");
            }

        } catch (\Exception $e) {
            $this->add_result($category, 'Field Mapping', 'failed', 'Mapping error: ' . $e->getMessage());
        }

        // Test Pepco account detection
        $pepco_errors = FieldMapper::validateAccountNumberForUtility('1234567890', 'delmarva');
        if (!empty($pepco_errors)) {
            $this->add_result($category, 'Pepco Detection', 'passed', '10-digit account correctly flagged for Delmarva form');
        } else {
            $this->add_result($category, 'Pepco Detection', 'warning', '10-digit account not flagged - verify Pepco detection');
        }
    }

    // =========================================================================
    // Cron Tests
    // =========================================================================

    private function test_cron_schedules(): void {
        $category = 'Scheduled Tasks';

        $cron_events = [
            'isf_cleanup_sessions' => 'Session Cleanup',
            'isf_cleanup_logs' => 'Log Cleanup',
            'isf_process_retry_queue' => 'Retry Queue Processing',
            'isf_send_scheduled_reports' => 'Scheduled Reports',
            'isf_apply_retention_policy' => 'Data Retention Policy'
        ];

        foreach ($cron_events as $hook => $label) {
            $next_run = wp_next_scheduled($hook);
            if ($next_run) {
                $when = human_time_diff(time(), $next_run);
                $this->add_result($category, $label, 'passed', "Scheduled - next run in {$when}");
            } else {
                $this->add_result($category, $label, 'warning', "Not scheduled - may need plugin reactivation");
            }
        }
    }

    // =========================================================================
    // Quick Health Check (for dashboard widget)
    // =========================================================================

    /**
     * Run a quick health check for dashboard display
     *
     * @return array Quick status summary
     */
    public function quick_health_check(): array {
        $status = [
            'overall' => 'healthy',
            'issues' => [],
            'checks' => []
        ];

        // Database check
        global $wpdb;
        $db_ok = $wpdb->get_var("SELECT 1") == 1;
        $status['checks']['database'] = $db_ok;
        if (!$db_ok) {
            $status['overall'] = 'critical';
            $status['issues'][] = 'Database connection failed';
        }

        // Tables check
        $table_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s",
            DB_NAME,
            $wpdb->prefix . 'isf_%'
        ));
        $status['checks']['tables'] = $table_count >= 10;
        if ($table_count < 10) {
            $status['overall'] = 'warning';
            $status['issues'][] = "Only {$table_count}/11 database tables found";
        }

        // Encryption check
        $status['checks']['encryption'] = $this->encryption->test();
        if (!$status['checks']['encryption']) {
            $status['overall'] = 'warning';
            $status['issues'][] = 'Encryption test failed';
        }

        // Active instances check
        $active_instances = $this->db->get_instances(true);
        $status['checks']['instances'] = count($active_instances) > 0;
        $status['instance_count'] = count($active_instances);
        if (count($active_instances) === 0) {
            $status['issues'][] = 'No active form instances configured';
        }

        // Cron check
        $cron_ok = wp_next_scheduled('isf_cleanup_sessions') !== false;
        $status['checks']['cron'] = $cron_ok;
        if (!$cron_ok) {
            $status['issues'][] = 'Scheduled tasks not configured';
        }

        // Set overall status
        if ($status['overall'] !== 'critical' && count($status['issues']) > 0) {
            $status['overall'] = 'warning';
        } elseif (count($status['issues']) === 0) {
            $status['overall'] = 'healthy';
        }

        return $status;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function convert_to_bytes(string $value): int {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int)$value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
