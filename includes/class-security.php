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
     * REST permission callback for endpoints that should remain public
     * but need per-IP throttling to prevent abuse of expensive backend
     * resources (Google Maps API, DB-heavy queries, external services).
     *
     * Use this in place of `__return_true` for public-by-design routes
     * where the cost of an abusive caller is real. Returns a WP_Error
     * (translated to HTTP 429) when the limit is exceeded.
     */
    public static function rate_limit_public(): bool|\WP_Error {
        if (self::check_rate_limit()) {
            return true;
        }
        return new \WP_Error(
            'rate_limit_exceeded',
            __('Too many requests. Please try again in a moment.', 'formflow'),
            ['status' => 429]
        );
    }

    /**
     * REST permission callback for write endpoints that should accept
     * either an authenticated user OR a valid WP REST nonce. This keeps
     * public-by-design form-submission flows working (frontend JS POST
     * with nonce) while rejecting CSRF/random POSTs that have neither.
     *
     * Use for state-changing routes (creates, updates, cancels) that
     * are reachable from frontend JS but should not be openly POST-able
     * by any caller.
     */
    public static function nonce_or_logged_in(): bool|\WP_Error {
        if (is_user_logged_in()) {
            return true;
        }
        $nonce = $_REQUEST['_wpnonce'] ?? ($_SERVER['HTTP_X_WP_NONCE'] ?? '');
        if (is_string($nonce) && $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest')) {
            return true;
        }
        return new \WP_Error(
            'rest_forbidden',
            __('Sorry, you are not allowed to do that.', 'formflow'),
            ['status' => 403]
        );
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
     * Get client IP address.
     *
     * SECURITY: `CF-Connecting-IP` and `X-Forwarded-For` are request headers
     * that ANY caller can set. Trusting the first value in them (as an earlier
     * revision did) let an attacker rotate the rate-limit bucket key on every
     * request by sending a fresh spoofed header — defeating the sole throttle
     * in front of the public nopriv enrollment handlers (paid external enroll +
     * SMS + email per hit) and the expensive geocode/address routes.
     *
     * This resolver therefore defaults to REMOTE_ADDR (the real TCP peer, which
     * the client cannot forge) and only honors the forwarded headers when
     * REMOTE_ADDR is itself a configured, trusted reverse proxy (Cloudflare, a
     * load balancer, etc.). When honoring X-Forwarded-For we take the
     * RIGHT-MOST hop that is not itself a trusted proxy — the closest address a
     * trusted proxy actually observed — never the left-most (attacker-supplied)
     * value.
     *
     * Configure the allowlist via either:
     *   - the `ISF_TRUSTED_PROXIES` constant (comma-separated string or array), or
     *   - the `isf_settings` option key `trusted_proxies` (comma-separated string
     *     or array).
     *
     * Follow-up (not trivial here): CIDR-range matching. The allowlist is
     * currently exact single-IP match only; document the proxy's egress IP(s).
     */
    public static function get_client_ip(): string {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';

        // REMOTE_ADDR is the only address the client cannot spoof. If it isn't a
        // valid IP we can't trust anything, so fall back to the sentinel.
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }

        // Unless the request actually arrived from a trusted proxy, IGNORE every
        // forwarded header and key on the real peer address.
        if (!self::is_trusted_proxy($remote)) {
            return $remote;
        }

        // Request came through a trusted proxy — honor the forwarded client IP.
        // Cloudflare's CF-Connecting-IP is a single, unambiguous value.
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cf = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cf, FILTER_VALIDATE_IP)) {
                return $cf;
            }
        }

        // X-Forwarded-For is "client, proxy1, proxy2, ..." with the closest hop
        // on the RIGHT. Walk right-to-left and return the first hop that is not
        // itself a trusted proxy: that is the nearest address a trusted proxy
        // genuinely observed, and the furthest left an attacker can influence.
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $hops = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
            for ($i = count($hops) - 1; $i >= 0; $i--) {
                $hop = $hops[$i];
                if ($hop === '' || !filter_var($hop, FILTER_VALIDATE_IP)) {
                    continue;
                }
                if (!self::is_trusted_proxy($hop)) {
                    return $hop;
                }
            }
        }

        // Trusted proxy but no usable forwarded value — key on the proxy itself.
        return $remote;
    }

    /**
     * Return the configured trusted-proxy allowlist (exact single IPs).
     *
     * Sources are merged: the `ISF_TRUSTED_PROXIES` constant and the
     * `isf_settings` option key `trusted_proxies`. Each may be a comma-separated
     * string or an array. Only syntactically valid IPs are kept.
     *
     * @return array<int, string>
     */
    private static function trusted_proxies(): array {
        $raw = [];

        if (defined('ISF_TRUSTED_PROXIES')) {
            $raw = array_merge($raw, self::split_ip_list(ISF_TRUSTED_PROXIES));
        }

        $settings = get_option('isf_settings', []);
        if (is_array($settings) && isset($settings['trusted_proxies'])) {
            $raw = array_merge($raw, self::split_ip_list($settings['trusted_proxies']));
        }

        $valid = [];
        foreach ($raw as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $valid[$ip] = true;
            }
        }

        return array_keys($valid);
    }

    /**
     * Normalize a trusted-proxy config value (string|array|mixed) into a flat
     * list of trimmed, non-empty IP-candidate strings.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    private static function split_ip_list($value): array {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts = array_merge($parts, self::split_ip_list($item));
            }
            return $parts;
        }

        if (!is_string($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static function ($ip) {
            return $ip !== '';
        }));
    }

    /**
     * Whether the given address is an admin-configured trusted reverse proxy.
     */
    private static function is_trusted_proxy(string $ip): bool {
        return in_array($ip, self::trusted_proxies(), true);
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
