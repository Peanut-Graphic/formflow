<?php
namespace ISF;

if ( ! defined( 'ABSPATH' ) ) { exit; }


/**
 * A/B Testing Handler
 *
 * Manages form variation testing and conversion tracking.
 */


class ABTesting {

    /**
     * Session key for variation assignment
     */
    private const SESSION_KEY = 'isf_ab_variation';

    /**
     * Cookie name for cross-session consistency
     */
    private const COOKIE_NAME = 'isf_ab_test';

    /**
     * Database instance
     */
    private Database\Database $db;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database\Database();
    }

    /**
     * Get the assigned variation for a user/session
     */
    public function get_variation(array $instance): ?array {
        if (!FeatureManager::is_enabled($instance, 'ab_testing')) {
            return null;
        }

        $config = FeatureManager::get_feature($instance, 'ab_testing');
        $variations = $config['variations'] ?? [];

        if (empty($variations)) {
            return null;
        }

        // Ensure we have at least 2 variations
        if (count($variations) < 2) {
            return null;
        }

        // Check if already assigned
        $existing = $this->get_assigned_variation($instance['id']);

        if ($existing) {
            // Verify it's still a valid variation
            foreach ($variations as $variation) {
                if ($variation['id'] === $existing) {
                    return $variation;
                }
            }
        }

        // Assign new variation
        return $this->assign_variation($instance, $variations, $config);
    }

    /**
     * Get currently assigned variation ID
     */
    private function get_assigned_variation(int $instance_id): ?string {
        $key = 'instance_' . $instance_id;

        // Check session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!empty($_SESSION[self::SESSION_KEY][$key])) {
            return $_SESSION[self::SESSION_KEY][$key];
        }

        // Check cookie
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            $cookie_data = json_decode(base64_decode($_COOKIE[self::COOKIE_NAME]), true);
            if (!empty($cookie_data[$key])) {
                // Store in session for this request
                $_SESSION[self::SESSION_KEY][$key] = $cookie_data[$key];
                return $cookie_data[$key];
            }
        }

        return null;
    }

    /**
     * Assign a variation to the current user
     */
    private function assign_variation(array $instance, array $variations, array $config): array {
        // Calculate total weight
        $total_weight = 0;
        foreach ($variations as $variation) {
            $total_weight += $variation['weight'] ?? 50;
        }

        // Random weighted selection
        $random = mt_rand(1, $total_weight);
        $cumulative = 0;
        $selected = $variations[0];

        foreach ($variations as $variation) {
            $cumulative += $variation['weight'] ?? 50;
            if ($random <= $cumulative) {
                $selected = $variation;
                break;
            }
        }

        // Store assignment
        $this->store_assignment($instance['id'], $selected['id'], $config['track_by'] ?? 'session');

        // Log assignment
        $this->log_assignment($instance, $selected);

        return $selected;
    }

    /**
     * Store variation assignment
     */
    private function store_assignment(int $instance_id, string $variation_id, string $track_by): void {
        $key = 'instance_' . $instance_id;

        // Store in session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION[self::SESSION_KEY][$key] = $variation_id;

        // Store in cookie for cross-session
        if ($track_by === 'cookie' || $track_by === 'persistent') {
            $cookie_data = [];

            if (!empty($_COOKIE[self::COOKIE_NAME])) {
                $cookie_data = json_decode(base64_decode($_COOKIE[self::COOKIE_NAME]), true) ?: [];
            }

            $cookie_data[$key] = $variation_id;
            $expiry = time() + (30 * 24 * 60 * 60); // 30 days

            setcookie(
                self::COOKIE_NAME,
                base64_encode(json_encode($cookie_data)),
                $expiry,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
        }
    }

    /**
     * Log variation assignment
     */
    private function log_assignment(array $instance, array $variation): void {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_ab_assignments';

        // Ensure table exists
        $this->ensure_assignments_table();

        $session_id = session_id() ?: wp_generate_uuid4();

        $wpdb->insert($table, [
            'instance_id' => $instance['id'],
            'session_id' => $session_id,
            'variation_id' => $variation['id'],
            'variation_name' => $variation['name'] ?? '',
            'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Record a conversion
     */
    public function record_conversion(array $instance, int $submission_id, string $goal = ''): void {
        if (!FeatureManager::is_enabled($instance, 'ab_testing')) {
            return;
        }

        $config = FeatureManager::get_feature($instance, 'ab_testing');
        $variation = $this->get_variation($instance);

        if (!$variation) {
            return;
        }

        // Use configured goal or default
        $goal = $goal ?: ($config['goal'] ?? 'enrollment_completed');

        global $wpdb;
        $table = $wpdb->prefix . 'isf_ab_conversions';

        // Ensure table exists
        $this->ensure_conversions_table();

        $session_id = session_id() ?: '';

        $wpdb->insert($table, [
            'instance_id' => $instance['id'],
            'submission_id' => $submission_id,
            'session_id' => $session_id,
            'variation_id' => $variation['id'],
            'goal' => $goal,
            'created_at' => current_time('mysql'),
        ]);

        $this->db->log('info', 'A/B test conversion recorded', [
            'variation' => $variation['name'] ?? $variation['id'],
            'goal' => $goal,
        ], $instance['id'], $submission_id);
    }

    /**
     * Get A/B test results
     */
    public function get_results(array $instance, ?string $start_date = null, ?string $end_date = null): array {
        global $wpdb;
        $assignments_table = $wpdb->prefix . 'isf_ab_assignments';
        $conversions_table = $wpdb->prefix . 'isf_ab_conversions';

        // Check tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$assignments_table}'") !== $assignments_table) {
            return [];
        }

        $config = FeatureManager::get_feature($instance, 'ab_testing');
        $variations = $config['variations'] ?? [];

        if (empty($variations)) {
            return [];
        }

        $date_condition = '';
        $params = [$instance['id']];

        if ($start_date && $end_date) {
            $date_condition = 'AND created_at BETWEEN %s AND %s';
            $params[] = $start_date . ' 00:00:00';
            $params[] = $end_date . ' 23:59:59';
        }

        $results = [];

        foreach ($variations as $variation) {
            $var_id = $variation['id'];

            // Get assignment count
            $assignments = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT session_id) FROM {$assignments_table}
                 WHERE instance_id = %d AND variation_id = %s {$date_condition}",
                array_merge($params, [$var_id])
            ));

            // Get conversion count
            $conversions = 0;
            if ($wpdb->get_var("SHOW TABLES LIKE '{$conversions_table}'") === $conversions_table) {
                $conversions = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$conversions_table}
                     WHERE instance_id = %d AND variation_id = %s {$date_condition}",
                    array_merge($params, [$var_id])
                ));
            }

            $rate = $assignments > 0 ? round(($conversions / $assignments) * 100, 2) : 0;

            $results[$var_id] = [
                'id' => $var_id,
                'name' => $variation['name'] ?? $var_id,
                'assignments' => $assignments,
                'conversions' => $conversions,
                'conversion_rate' => $rate,
                'is_control' => !empty($variation['is_control']),
            ];
        }

        // Calculate statistical significance
        $results = $this->calculate_significance($results);

        return $results;
    }

    /**
     * Calculate statistical significance between variations
     */
    private function calculate_significance(array $results): array {
        if (count($results) < 2) {
            return $results;
        }

        // Find control
        $control = null;
        foreach ($results as $result) {
            if (!empty($result['is_control'])) {
                $control = $result;
                break;
            }
        }

        // If no control marked, use first variation
        if (!$control) {
            $control = reset($results);
        }

        // Calculate relative improvement and confidence
        foreach ($results as $id => &$result) {
            if ($result['id'] === $control['id']) {
                $result['relative_improvement'] = 0;
                $result['is_winner'] = false;
                continue;
            }

            // Relative improvement
            if ($control['conversion_rate'] > 0) {
                $result['relative_improvement'] = round(
                    (($result['conversion_rate'] - $control['conversion_rate']) / $control['conversion_rate']) * 100,
                    1
                );
            } else {
                $result['relative_improvement'] = $result['conversion_rate'] > 0 ? 100 : 0;
            }

            // Simple significance check (need sufficient sample size)
            $min_sample = 100;
            $result['is_significant'] = (
                $result['assignments'] >= $min_sample &&
                $control['assignments'] >= $min_sample &&
                abs($result['relative_improvement']) >= 5
            );

            $result['is_winner'] = $result['is_significant'] && $result['relative_improvement'] > 0;
        }

        return $results;
    }

    /**
     * Apply variation modifications to form
     */
    public function apply_variation(array $instance, array $form_config): array {
        $variation = $this->get_variation($instance);

        if (!$variation || empty($variation['modifications'])) {
            return $form_config;
        }

        $mods = $variation['modifications'];

        // Apply heading override
        if (!empty($mods['heading'])) {
            $form_config['heading'] = $mods['heading'];
        }

        // Apply subheading override
        if (!empty($mods['subheading'])) {
            $form_config['subheading'] = $mods['subheading'];
        }

        // Apply button text override
        if (!empty($mods['button_text'])) {
            $form_config['button_text'] = $mods['button_text'];
        }

        // Apply CTA text override
        if (!empty($mods['cta_text'])) {
            $form_config['cta_text'] = $mods['cta_text'];
        }

        // Apply CSS class
        if (!empty($mods['css_class'])) {
            $form_config['variation_class'] = $mods['css_class'];
        }

        // Apply custom styles
        if (!empty($mods['custom_css'])) {
            $form_config['variation_css'] = $mods['custom_css'];
        }

        // Apply layout changes
        if (!empty($mods['layout'])) {
            $form_config['layout'] = $mods['layout'];
        }

        // Add variation ID to form config for tracking
        $form_config['ab_variation_id'] = $variation['id'];
        $form_config['ab_variation_name'] = $variation['name'] ?? '';

        return $form_config;
    }

    /**
     * Render variation tracking hidden field
     */
    public function render_tracking_field(array $instance): string {
        $variation = $this->get_variation($instance);

        if (!$variation) {
            return '';
        }

        return sprintf(
            '<input type="hidden" name="ab_variation" value="%s">',
            esc_attr($variation['id'])
        );
    }

    /**
     * Ensure assignments table exists
     */
    private function ensure_assignments_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_ab_assignments';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NOT NULL,
            session_id VARCHAR(64),
            variation_id VARCHAR(50) NOT NULL,
            variation_name VARCHAR(255),
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_instance_variation (instance_id, variation_id),
            INDEX idx_session (session_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Ensure conversions table exists
     */
    private function ensure_conversions_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_ab_conversions';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NOT NULL,
            submission_id INT,
            session_id VARCHAR(64),
            variation_id VARCHAR(50) NOT NULL,
            goal VARCHAR(100) DEFAULT 'enrollment_completed',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_instance_variation (instance_id, variation_id),
            INDEX idx_goal (goal)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get or create default variations for an instance
     */
    public static function get_default_variations(): array {
        return [
            [
                'id' => 'control',
                'name' => 'Control (Original)',
                'weight' => 50,
                'is_control' => true,
                'modifications' => [],
            ],
            [
                'id' => 'variant_a',
                'name' => 'Variant A',
                'weight' => 50,
                'is_control' => false,
                'modifications' => [],
            ],
        ];
    }

    /**
     * Validate variation configuration
     */
    public static function validate_variations(array $variations): array {
        $errors = [];

        if (count($variations) < 2) {
            $errors[] = __('At least 2 variations are required for A/B testing', 'formflow');
        }

        $has_control = false;
        $total_weight = 0;
        $ids = [];

        foreach ($variations as $i => $variation) {
            if (empty($variation['id'])) {
                $errors[] = sprintf(__('Variation %d is missing an ID', 'formflow'), $i + 1);
            } elseif (in_array($variation['id'], $ids)) {
                $errors[] = sprintf(__('Duplicate variation ID: %s', 'formflow'), $variation['id']);
            } else {
                $ids[] = $variation['id'];
            }

            if (!empty($variation['is_control'])) {
                $has_control = true;
            }

            $total_weight += $variation['weight'] ?? 50;
        }

        if (!$has_control) {
            $errors[] = __('One variation must be marked as the control', 'formflow');
        }

        return $errors;
    }
}
