<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Security Utilities
 *
 * Handles input sanitization, nonce verification, and rate limiting.
 */


class Security {

    /**
     * Sanitize form data based on field type
     */
    public static function sanitize_form_data(array $data): array {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitize_form_data($value);
            } else {
                $sanitized[$key] = self::sanitize_field($key, $value);
            }
        }

        return $sanitized;
    }

    /**
     * Instance method wrapper for sanitize_field (backwards compatibility)
     *
     * @param mixed $value The value to sanitize
     * @param string $type The sanitization type
     * @return string Sanitized value
     */
    public function sanitize(mixed $value, string $type = 'text'): string {
        return self::sanitize_field($type, $value);
    }

    /**
     * Sanitize a single field based on its key/type
     */
    public static function sanitize_field(string $key, mixed $value): string {
        if (!is_string($value)) {
            $value = (string) $value;
        }

        // Field-specific sanitization
        switch (strtolower($key)) {
            case 'email':
            case 'email_address':
                return sanitize_email($value);

            case 'phone':
            case 'phone_number':
            case 'telephone':
                // Allow only numbers, dashes, parentheses, plus, spaces
                return preg_replace('/[^0-9\-\+\(\)\s]/', '', $value);

            case 'account_number':
            case 'accountnumber':
            case 'utility_no':
                // Allow only alphanumeric and dashes
                return preg_replace('/[^0-9A-Za-z\-]/', '', $value);

            case 'zip':
            case 'zip_code':
            case 'zipcode':
            case 'postal_code':
                // Allow only numbers and dashes (for ZIP+4)
                return preg_replace('/[^0-9\-]/', '', $value);

            case 'state':
                // Uppercase, letters only, max 2 chars
                return strtoupper(preg_replace('/[^A-Za-z]/', '', substr($value, 0, 2)));

            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Verify AJAX request (nonce and optional capabilities)
     */
    public static function verify_ajax_request(string $action = 'formflow_ajax_nonce', string $capability = ''): bool {
        // Check nonce
        $nonce = $_POST['nonce'] ?? $_GET['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_send_json_error([
                'message' => __('Security check failed. Please refresh the page and try again.', 'formflow'),
                'code' => 'nonce_failed'
            ], 403);
            return false;
        }

        // Check capability if specified (for admin actions)
        if ($capability && !current_user_can($capability)) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'formflow'),
                'code' => 'permission_denied'
            ], 403);
            return false;
        }

        // Check rate limit
        if (!self::check_rate_limit()) {
            wp_send_json_error([
                'message' => __('Too many requests. Please wait a moment and try again.', 'formflow'),
                'code' => 'rate_limited'
            ], 429);
            return false;
        }

        return true;
    }

    /**
     * Check rate limiting for current IP
     */
    public static function check_rate_limit(): bool {
        $settings = get_option('isf_settings', []);

        // Allow disabling rate limiting via settings
        if (!empty($settings['disable_rate_limit'])) {
            return true;
        }

        // Increased defaults: 120 requests per 60 seconds (was 10/60 which was too aggressive for multi-step forms)
        $max_requests = $settings['rate_limit_requests'] ?? 120;
        $window_seconds = $settings['rate_limit_window'] ?? 60;

        $ip = self::get_client_ip();
        $key = 'isf_rate_' . md5($ip);
        $attempts = get_transient($key);

        if ($attempts === false) {
            set_transient($key, 1, $window_seconds);
            return true;
        }

        if ($attempts >= $max_requests) {
            // Log the rate limit event
            self::log_security_event('rate_limit_exceeded', [
                'ip' => $ip,
                'attempts' => $attempts
            ]);
            return false;
        }

        set_transient($key, $attempts + 1, $window_seconds);
        return true;
    }

    /**
     * Clear rate limit for an IP address
     */
    public static function clear_rate_limit(?string $ip = null): void {
        if ($ip === null) {
            $ip = self::get_client_ip();
        }
        $key = 'isf_rate_' . md5($ip);
        delete_transient($key);
    }

    /**
     * Get client IP address
     */
    public static function get_client_ip(): string {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
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
     * Generate secure session ID
     */
    public static function generate_session_id(): string {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Exception $e) {
            // Fallback if random_bytes fails (should never happen in PHP 7+)
            return wp_generate_password(64, false);
        }
    }

    /**
     * Generate secure nonce for forms
     */
    public static function create_form_nonce(string $action = 'formflow_form'): string {
        return wp_create_nonce($action);
    }

    /**
     * Log a security-related event
     */
    public static function log_security_event(string $event, array $details = []): void {
        $db = new Database\Database();
        $db->log('security', $event, array_merge($details, [
            'ip' => self::get_client_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'),
            'timestamp' => current_time('mysql')
        ]));
    }

    /**
     * Validate that required fields are present and non-empty
     */
    public static function validate_required_fields(array $data, array $required): array {
        $errors = [];

        foreach ($required as $field => $label) {
            if (is_numeric($field)) {
                $field = $label;
                $label = ucwords(str_replace('_', ' ', $field));
            }

            if (empty($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $errors[$field] = sprintf(
                    __('%s is required.', 'formflow'),
                    $label
                );
            }
        }

        return $errors;
    }

    /**
     * Validate email format
     */
    public static function validate_email(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate phone number format (basic US format)
     */
    public static function validate_phone(string $phone): bool {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        return strlen($digits) >= 10 && strlen($digits) <= 11;
    }

    /**
     * Validate ZIP code format
     */
    public static function validate_zip(string $zip): bool {
        // US ZIP code: 5 digits or 5+4 format
        return preg_match('/^\d{5}(-\d{4})?$/', $zip) === 1;
    }

    /**
     * Sanitize and validate an instance slug
     */
    public static function sanitize_slug(string $slug): string {
        return sanitize_title($slug);
    }

    /**
     * Check if SSL is being used (required for form pages)
     */
    public static function is_ssl(): bool {
        return is_ssl();
    }

    /**
     * Validate SSL requirement for form submission
     */
    public static function require_ssl(): bool {
        if (!self::is_ssl() && !defined('ISF_DISABLE_SSL_CHECK')) {
            wp_send_json_error([
                'message' => __('Secure connection required. Please access this form via HTTPS.', 'formflow'),
                'code' => 'ssl_required'
            ], 403);
            return false;
        }
        return true;
    }
}
