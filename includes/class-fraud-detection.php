<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Fraud Detection
 *
 * Detects and prevents fraudulent enrollment submissions.
 */


class FraudDetection {

    /**
     * Risk score weights
     */
    private const WEIGHTS = [
        'duplicate_account' => 30,
        'ip_velocity' => 25,
        'suspicious_fingerprint' => 20,
        'disposable_email' => 15,
        'vpn_proxy' => 20,
        'data_mismatch' => 15,
        'bot_behavior' => 25,
    ];

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
     * Analyze submission for fraud risk
     */
    public function analyze(array $instance, array $form_data, array $context = []): array {
        if (!FeatureManager::is_enabled($instance, 'fraud_detection')) {
            return ['risk_score' => 0, 'passed' => true, 'checks' => []];
        }

        $config = FeatureManager::get_feature($instance, 'fraud_detection');
        $checks = [];
        $risk_score = 0;

        // Run enabled checks
        if (!empty($config['check_duplicate_accounts'])) {
            $check = $this->check_duplicate_account($instance, $form_data);
            $checks['duplicate_account'] = $check;
            if ($check['flagged']) {
                $risk_score += self::WEIGHTS['duplicate_account'] * ($check['severity'] ?? 1);
            }
        }

        if (!empty($config['check_ip_velocity'])) {
            $check = $this->check_ip_velocity($instance, $config);
            $checks['ip_velocity'] = $check;
            if ($check['flagged']) {
                $risk_score += self::WEIGHTS['ip_velocity'] * ($check['severity'] ?? 1);
            }
        }

        if (!empty($config['check_device_fingerprint'])) {
            $check = $this->check_device_fingerprint($instance, $context);
            $checks['device_fingerprint'] = $check;
            if ($check['flagged']) {
                $risk_score += self::WEIGHTS['suspicious_fingerprint'] * ($check['severity'] ?? 1);
            }
        }

        if (!empty($config['check_email_domain'])) {
            $check = $this->check_email_domain($form_data, $config);
            $checks['email_domain'] = $check;
            if ($check['flagged']) {
                $risk_score += self::WEIGHTS['disposable_email'] * ($check['severity'] ?? 1);
            }
        }

        // Check for VPN/proxy
        $vpn_check = $this->check_vpn_proxy($context);
        $checks['vpn_proxy'] = $vpn_check;
        if ($vpn_check['flagged']) {
            $risk_score += self::WEIGHTS['vpn_proxy'] * ($vpn_check['severity'] ?? 1);
        }

        // Check for data inconsistencies
        $data_check = $this->check_data_consistency($form_data);
        $checks['data_consistency'] = $data_check;
        if ($data_check['flagged']) {
            $risk_score += self::WEIGHTS['data_mismatch'] * ($data_check['severity'] ?? 1);
        }

        // Check for bot behavior
        $bot_check = $this->check_bot_behavior($context);
        $checks['bot_behavior'] = $bot_check;
        if ($bot_check['flagged']) {
            $risk_score += self::WEIGHTS['bot_behavior'] * ($bot_check['severity'] ?? 1);
        }

        // Cap risk score at 100
        $risk_score = min(100, $risk_score);
        $threshold = $config['risk_score_threshold'] ?? 70;
        $passed = $risk_score < $threshold;

        $result = [
            'risk_score' => $risk_score,
            'threshold' => $threshold,
            'passed' => $passed,
            'checks' => $checks,
            'action' => $passed ? 'allow' : ($config['action_on_high_risk'] ?? 'flag'),
        ];

        // Log the analysis
        $this->log_analysis($instance, $form_data, $result);

        // Notify if high risk and configured
        if (!$passed && !empty($config['notify_on_fraud'])) {
            $this->notify_fraud_detected($instance, $form_data, $result);
        }

        return $result;
    }

    /**
     * Check for duplicate account submissions
     */
    private function check_duplicate_account(array $instance, array $form_data): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;

        $account_number = $form_data['utility_no'] ?? '';

        if (empty($account_number)) {
            return ['flagged' => false, 'reason' => 'No account number'];
        }

        // Check for recent submissions with same account
        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE instance_id = %d
             AND JSON_EXTRACT(form_data, '$.utility_no') = %s
             AND status = 'completed'
             AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $instance['id'],
            $account_number
        ));

        if ($recent > 0) {
            return [
                'flagged' => true,
                'severity' => min(2, $recent),
                'reason' => "Account has {$recent} recent submission(s)",
                'details' => ['previous_submissions' => $recent],
            ];
        }

        return ['flagged' => false];
    }

    /**
     * Check IP submission velocity
     */
    private function check_ip_velocity(array $instance, array $config): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;

        $ip = $this->get_client_ip();
        $threshold = $config['ip_threshold_per_hour'] ?? 5;

        // Count submissions from this IP in last hour
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE ip_address = %s
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $ip
        ));

        if ($count >= $threshold) {
            return [
                'flagged' => true,
                'severity' => min(2, floor($count / $threshold)),
                'reason' => "{$count} submissions from IP in last hour",
                'details' => ['ip' => $ip, 'count' => $count, 'threshold' => $threshold],
            ];
        }

        // Also check daily volume
        $daily_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE ip_address = %s
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $ip
        ));

        if ($daily_count >= $threshold * 5) {
            return [
                'flagged' => true,
                'severity' => 1.5,
                'reason' => "{$daily_count} submissions from IP in last 24 hours",
                'details' => ['ip' => $ip, 'daily_count' => $daily_count],
            ];
        }

        return ['flagged' => false, 'details' => ['hourly_count' => $count, 'daily_count' => $daily_count]];
    }

    /**
     * Check device fingerprint for suspicious patterns
     */
    private function check_device_fingerprint(array $instance, array $context): array {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_fraud_fingerprints';

        $fingerprint = $context['fingerprint'] ?? '';

        if (empty($fingerprint)) {
            return ['flagged' => false, 'reason' => 'No fingerprint available'];
        }

        // Ensure table exists
        $this->ensure_fingerprint_table();

        // Check if fingerprint has been flagged before
        $flagged = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE fingerprint = %s AND status = 'blocked'",
            $fingerprint
        ), ARRAY_A);

        if ($flagged) {
            return [
                'flagged' => true,
                'severity' => 2,
                'reason' => 'Device previously blocked',
                'details' => ['blocked_at' => $flagged['created_at']],
            ];
        }

        // Check submission count from this fingerprint
        $submissions_table = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$submissions_table}
             WHERE JSON_EXTRACT(form_data, '$.device_fingerprint') = %s
             AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $fingerprint
        ));

        if ($count >= 5) {
            return [
                'flagged' => true,
                'severity' => 1,
                'reason' => "Device used for {$count} submissions in 7 days",
                'details' => ['submission_count' => $count],
            ];
        }

        return ['flagged' => false];
    }

    /**
     * Check email domain for disposable/suspicious domains
     */
    private function check_email_domain(array $form_data, array $config): array {
        $email = $form_data['email'] ?? '';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['flagged' => false, 'reason' => 'Invalid email'];
        }

        $domain = strtolower(substr($email, strpos($email, '@') + 1));

        // Check custom blocked domains
        $blocked = $config['blocked_email_domains'] ?? [];
        if (is_string($blocked)) {
            $blocked = array_filter(array_map('trim', explode("\n", $blocked)));
        }

        if (in_array($domain, $blocked)) {
            return [
                'flagged' => true,
                'severity' => 2,
                'reason' => 'Email domain blocked',
                'details' => ['domain' => $domain],
            ];
        }

        // Check against known disposable email domains
        $disposable_domains = $this->get_disposable_domains();

        if (in_array($domain, $disposable_domains)) {
            return [
                'flagged' => true,
                'severity' => 1.5,
                'reason' => 'Disposable email detected',
                'details' => ['domain' => $domain],
            ];
        }

        return ['flagged' => false, 'details' => ['domain' => $domain]];
    }

    /**
     * Check for VPN/proxy usage
     */
    private function check_vpn_proxy(array $context): array {
        $ip = $this->get_client_ip();

        // Check common VPN/proxy indicators
        $headers_to_check = [
            'HTTP_VIA',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_X_FORWARDED',
            'HTTP_CLIENT_IP',
            'HTTP_FORWARDED_FOR_IP',
            'VIA',
            'X_FORWARDED_FOR',
            'FORWARDED_FOR',
            'X_FORWARDED',
            'FORWARDED',
        ];

        $proxy_indicators = 0;
        foreach ($headers_to_check as $header) {
            if (!empty($_SERVER[$header])) {
                $proxy_indicators++;
            }
        }

        if ($proxy_indicators >= 2) {
            return [
                'flagged' => true,
                'severity' => 0.8,
                'reason' => 'Multiple proxy headers detected',
                'details' => ['indicator_count' => $proxy_indicators],
            ];
        }

        // Check if IP is in known datacenter ranges (simplified check)
        if ($this->is_datacenter_ip($ip)) {
            return [
                'flagged' => true,
                'severity' => 1,
                'reason' => 'Datacenter IP detected',
                'details' => ['ip' => $ip],
            ];
        }

        return ['flagged' => false];
    }

    /**
     * Check for data consistency issues
     */
    private function check_data_consistency(array $form_data): array {
        $issues = [];

        // Check name vs email consistency
        $email = strtolower($form_data['email'] ?? '');
        $first_name = strtolower($form_data['first_name'] ?? '');
        $last_name = strtolower($form_data['last_name'] ?? '');

        // Very generic name with specific email
        $generic_names = ['test', 'user', 'customer', 'john doe', 'jane doe', 'asdf', 'qwerty'];
        $full_name = $first_name . ' ' . $last_name;

        if (in_array($first_name, $generic_names) || in_array($full_name, $generic_names)) {
            $issues[] = 'Generic test name used';
        }

        // Check phone format
        $phone = preg_replace('/[^0-9]/', '', $form_data['phone'] ?? '');
        if (strlen($phone) >= 10) {
            // Check for obvious fake patterns
            if (preg_match('/^(1234567890|0000000000|1111111111|5555555555)$/', $phone)) {
                $issues[] = 'Suspicious phone number pattern';
            }
        }

        // Check ZIP code format
        $zip = $form_data['zip'] ?? '';
        if ($zip === '00000' || $zip === '12345' || $zip === '99999') {
            $issues[] = 'Suspicious ZIP code';
        }

        if (!empty($issues)) {
            return [
                'flagged' => true,
                'severity' => 0.5 * count($issues),
                'reason' => implode('; ', $issues),
                'details' => ['issues' => $issues],
            ];
        }

        return ['flagged' => false];
    }

    /**
     * Check for bot behavior patterns
     */
    private function check_bot_behavior(array $context): array {
        $issues = [];

        // Check form completion time (too fast = suspicious)
        $start_time = $context['form_start_time'] ?? 0;
        $completion_time = time() - $start_time;

        if ($start_time > 0 && $completion_time < 30) {
            $issues[] = 'Form completed too quickly (' . $completion_time . 's)';
        }

        // Check for honeypot field
        if (!empty($context['honeypot'])) {
            $issues[] = 'Honeypot field filled';
        }

        // Check mouse movement data
        if (isset($context['has_mouse_movement']) && !$context['has_mouse_movement']) {
            $issues[] = 'No mouse movement detected';
        }

        // Check for missing/suspicious user agent
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (empty($user_agent)) {
            $issues[] = 'Missing user agent';
        } elseif (preg_match('/(bot|crawler|spider|scraper|curl|wget)/i', $user_agent)) {
            $issues[] = 'Bot-like user agent';
        }

        if (!empty($issues)) {
            return [
                'flagged' => true,
                'severity' => 0.7 * count($issues),
                'reason' => implode('; ', $issues),
                'details' => ['issues' => $issues],
            ];
        }

        return ['flagged' => false];
    }

    /**
     * Get client IP address
     */
    private function get_client_ip(): string {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs
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
     * Check if IP is from known datacenter
     */
    private function is_datacenter_ip(string $ip): bool {
        // Simplified check - in production would use a proper IP database
        $datacenter_ranges = [
            '104.16.',   // Cloudflare
            '172.64.',   // Cloudflare
            '13.52.',    // AWS
            '3.0.',      // AWS
            '34.0.',     // Google Cloud
            '35.0.',     // Google Cloud
            '40.0.',     // Azure
            '52.0.',     // AWS
        ];

        foreach ($datacenter_ranges as $range) {
            if (strpos($ip, $range) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of known disposable email domains
     */
    private function get_disposable_domains(): array {
        return [
            'tempmail.com', 'temp-mail.org', 'guerrillamail.com', 'mailinator.com',
            '10minutemail.com', 'throwaway.email', 'fakeinbox.com', 'trashmail.com',
            'getnada.com', 'maildrop.cc', 'dispostable.com', 'yopmail.com',
            'tempail.com', 'emailondeck.com', 'mohmal.com', 'temp-mail.io',
            'sharklasers.com', 'guerrillamailblock.com', 'discard.email',
        ];
    }

    /**
     * Log fraud analysis
     */
    private function log_analysis(array $instance, array $form_data, array $result): void {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_fraud_logs';

        $this->ensure_fraud_log_table();

        $wpdb->insert($table, [
            'instance_id' => $instance['id'],
            'ip_address' => $this->get_client_ip(),
            'email' => $form_data['email'] ?? '',
            'account_number' => substr($form_data['utility_no'] ?? '', 0, 4) . '****',
            'risk_score' => $result['risk_score'],
            'passed' => $result['passed'] ? 1 : 0,
            'checks' => json_encode($result['checks']),
            'action_taken' => $result['action'],
            'created_at' => current_time('mysql'),
        ]);

        // Also log to main log
        if (!$result['passed']) {
            $this->db->log('warning', 'High fraud risk detected', [
                'risk_score' => $result['risk_score'],
                'action' => $result['action'],
            ], $instance['id']);
        }
    }

    /**
     * Notify admins of fraud detection
     */
    private function notify_fraud_detected(array $instance, array $form_data, array $result): void {
        // Use team notifications if available
        if (FeatureManager::is_enabled($instance, 'team_notifications')) {
            $team = new TeamNotifications();
            $team->send_notification($instance, 'fraud_detected', [
                'risk_score' => $result['risk_score'],
                'email' => $form_data['email'] ?? 'N/A',
                'ip' => $this->get_client_ip(),
                'checks' => $result['checks'],
            ]);
        }

        // Send email notification
        $admin_email = $instance['support_email_to'] ?? get_option('admin_email');

        $subject = sprintf(
            '[%s] Fraud Alert: High Risk Submission Detected',
            $instance['name'] ?? 'FormFlow'
        );

        $message = "A high-risk submission was detected:\n\n";
        $message .= "Risk Score: {$result['risk_score']}/100\n";
        $message .= "Email: " . ($form_data['email'] ?? 'N/A') . "\n";
        $message .= "IP Address: " . $this->get_client_ip() . "\n";
        $message .= "Action: {$result['action']}\n\n";
        $message .= "Triggered Checks:\n";

        foreach ($result['checks'] as $name => $check) {
            if (!empty($check['flagged'])) {
                $message .= "- {$name}: {$check['reason']}\n";
            }
        }

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Block a fingerprint
     */
    public function block_fingerprint(string $fingerprint, string $reason = ''): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_fraud_fingerprints';

        $this->ensure_fingerprint_table();

        return (bool)$wpdb->insert($table, [
            'fingerprint' => $fingerprint,
            'status' => 'blocked',
            'reason' => $reason,
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Get fraud statistics
     */
    public function get_statistics(int $instance_id, string $period = '30 days'): array {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_fraud_logs';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE instance_id = %d
             AND created_at > DATE_SUB(NOW(), INTERVAL {$period})",
            $instance_id
        ));

        $blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE instance_id = %d
             AND passed = 0
             AND created_at > DATE_SUB(NOW(), INTERVAL {$period})",
            $instance_id
        ));

        $avg_score = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(risk_score) FROM {$table}
             WHERE instance_id = %d
             AND created_at > DATE_SUB(NOW(), INTERVAL {$period})",
            $instance_id
        ));

        $top_reasons = $wpdb->get_results($wpdb->prepare(
            "SELECT checks, COUNT(*) as count FROM {$table}
             WHERE instance_id = %d
             AND passed = 0
             AND created_at > DATE_SUB(NOW(), INTERVAL {$period})
             GROUP BY checks
             ORDER BY count DESC
             LIMIT 5",
            $instance_id
        ), ARRAY_A);

        return [
            'total_analyzed' => (int)$total,
            'blocked' => (int)$blocked,
            'block_rate' => $total > 0 ? round(($blocked / $total) * 100, 1) : 0,
            'average_risk_score' => round((float)$avg_score, 1),
            'top_reasons' => $top_reasons,
        ];
    }

    /**
     * Ensure fraud log table exists
     */
    private function ensure_fraud_log_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_fraud_logs';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NOT NULL,
            ip_address VARCHAR(45),
            email VARCHAR(255),
            account_number VARCHAR(50),
            risk_score INT,
            passed TINYINT(1),
            checks JSON,
            action_taken VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_instance (instance_id),
            INDEX idx_ip (ip_address),
            INDEX idx_score (risk_score)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Ensure fingerprint table exists
     */
    private function ensure_fingerprint_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_fraud_fingerprints';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fingerprint VARCHAR(255) NOT NULL,
            status ENUM('blocked', 'trusted') DEFAULT 'blocked',
            reason TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_fingerprint (fingerprint)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
