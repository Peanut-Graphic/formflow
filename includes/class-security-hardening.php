<?php
/**
 * Security Hardening
 *
 * Implements multiple layers of security protection for FormFlow.
 *
 * @package FormFlow
 */

namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SecurityHardening
 *
 * Provides anti-scraping, integrity verification, and abuse prevention.
 */
class SecurityHardening {

    /**
     * Singleton instance
     */
    private static ?SecurityHardening $instance = null;

    /**
     * Security token for this session
     */
    private string $session_token = '';

    /**
     * Get singleton instance
     */
    public static function instance(): SecurityHardening {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        $this->session_token = $this->generate_session_token();
    }

    /**
     * Initialize security hooks
     */
    public function init(): void {
        // Add security headers
        add_action('send_headers', [$this, 'add_security_headers']);

        // Validate requests
        add_action('wp_ajax_isf_form_action', [$this, 'validate_ajax_request'], 1);
        add_action('wp_ajax_formflow_form_action', [$this, 'validate_ajax_request'], 1);
        add_action('wp_ajax_nopriv_isf_form_action', [$this, 'validate_ajax_request'], 1);
        add_action('wp_ajax_nopriv_formflow_form_action', [$this, 'validate_ajax_request'], 1);

        // Add integrity checks to scripts
        add_filter('script_loader_tag', [$this, 'add_script_integrity'], 10, 3);

        // Block suspicious requests
        add_action('init', [$this, 'block_suspicious_requests'], 1);

        // Add honeypot field support
        add_action('isf_form_after_fields', [$this, 'render_honeypot_field']);

        // Rate limit by fingerprint
        add_filter('isf_rate_limit_key', [$this, 'enhance_rate_limit_key'], 10, 2);
    }

    /**
     * Generate session token for CSRF protection
     */
    private function generate_session_token(): string {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }

        $token_key = 'isf_security_token';

        if (isset($_SESSION[$token_key])) {
            return $_SESSION[$token_key];
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[$token_key] = $token;

        return $token;
    }

    /**
     * Get current session token
     */
    public function get_session_token(): string {
        return $this->session_token;
    }

    /**
     * Add security headers to responses
     */
    public function add_security_headers(): void {
        if (headers_sent()) {
            return;
        }

        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Enable XSS protection
        header('X-XSS-Protection: 1; mode=block');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Permissions policy (disable unnecessary features)
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }

    /**
     * Validate AJAX requests
     */
    public function validate_ajax_request(): void {
        // Check for bot signatures
        if ($this->is_bot_request()) {
            wp_send_json_error([
                'message' => __('Request blocked.', 'formflow'),
                'code' => 'bot_detected'
            ], 403);
        }

        // Check honeypot
        if (isset($_POST['isf_website_url']) && $_POST['isf_website_url'] !== '') {
            // Honeypot field was filled - likely a bot
            $this->log_security_event('honeypot_triggered', [
                'ip' => $this->get_client_ip(),
                'value' => sanitize_text_field($_POST['isf_website_url'])
            ]);

            // Return fake success to confuse bots
            wp_send_json_success([
                'message' => __('Form submitted successfully.', 'formflow')
            ]);
        }

        // Validate timing (forms submitted too fast are suspicious)
        $form_load_time = intval($_POST['isf_form_load_time'] ?? 0);
        $submit_time = time();

        if ($form_load_time > 0) {
            $elapsed = $submit_time - $form_load_time;

            // Less than 3 seconds is suspicious for a multi-step form
            if ($elapsed < 3) {
                $this->log_security_event('fast_submission', [
                    'ip' => $this->get_client_ip(),
                    'elapsed_seconds' => $elapsed
                ]);

                wp_send_json_error([
                    'message' => __('Please take your time filling out the form.', 'formflow'),
                    'code' => 'too_fast'
                ], 429);
            }
        }
    }

    /**
     * Check if request appears to be from a bot
     */
    private function is_bot_request(): bool {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Empty user agent
        if (empty($user_agent)) {
            return true;
        }

        // Known bot patterns
        $bot_patterns = [
            'bot', 'crawl', 'spider', 'scrape', 'curl', 'wget', 'python',
            'java/', 'httpclient', 'libwww', 'lwp-', 'go-http-client',
            'node-fetch', 'axios', 'phantom', 'headless', 'selenium',
            'puppeteer', 'playwright'
        ];

        $user_agent_lower = strtolower($user_agent);

        foreach ($bot_patterns as $pattern) {
            if (strpos($user_agent_lower, $pattern) !== false) {
                // Allow legitimate bots like Googlebot on public pages
                // but block them from form submissions
                return true;
            }
        }

        // Check for missing headers that browsers always send
        if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return true;
        }

        return false;
    }

    /**
     * Block suspicious requests early
     */
    public function block_suspicious_requests(): void {
        // Only check on form-related requests
        if (!isset($_REQUEST['action']) || strpos($_REQUEST['action'], 'isf_') !== 0) {
            return;
        }

        $ip = $this->get_client_ip();

        // Check if IP is in blocklist
        if ($this->is_ip_blocked($ip)) {
            status_header(403);
            exit('Access denied.');
        }

        // Check for SQL injection attempts in any field
        $suspicious_patterns = [
            '/(\bunion\b.*\bselect\b)/i',
            '/(\bselect\b.*\bfrom\b)/i',
            '/(\'|\").*(\bor\b|\band\b).*(\=|like)/i',
            '/(\bdrop\b|\btruncate\b|\bdelete\b).*\btable\b/i',
            '/<script[^>]*>/i',
            '/javascript:/i',
            '/on(error|load|click|mouse)/i',
        ];

        $request_data = json_encode($_REQUEST);

        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $request_data)) {
                $this->log_security_event('injection_attempt', [
                    'ip' => $ip,
                    'pattern' => $pattern,
                    'data' => substr($request_data, 0, 500)
                ]);

                // Temporarily block this IP
                $this->block_ip($ip, 3600); // 1 hour

                status_header(403);
                exit('Access denied.');
            }
        }
    }

    /**
     * Render honeypot field (hidden from real users, bots fill it)
     */
    public function render_honeypot_field(): void {
        ?>
        <div style="position: absolute; left: -9999px; opacity: 0; height: 0; overflow: hidden;" aria-hidden="true">
            <label for="isf_website_url">Website</label>
            <input type="text" name="isf_website_url" id="isf_website_url" tabindex="-1" autocomplete="off">
        </div>
        <?php
    }

    /**
     * Enhance rate limit key with browser fingerprint
     */
    public function enhance_rate_limit_key(string $key, array $context): string {
        // Add user agent hash to rate limit key
        $ua_hash = substr(md5($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 8);

        return $key . '_' . $ua_hash;
    }

    /**
     * Add subresource integrity to scripts
     */
    public function add_script_integrity(string $tag, string $handle, string $src): string {
        // Only add to our scripts
        if (strpos($handle, 'isf-') !== 0 && strpos($handle, 'formflow-') !== 0) {
            return $tag;
        }

        // Get file path from URL
        $file_path = str_replace(
            [ISF_PLUGIN_URL, '/'],
            [ISF_PLUGIN_DIR, DIRECTORY_SEPARATOR],
            $src
        );

        // Remove query string
        $file_path = preg_replace('/\?.*$/', '', $file_path);

        if (file_exists($file_path)) {
            $hash = base64_encode(hash_file('sha384', $file_path, true));
            $integrity = 'sha384-' . $hash;

            // Add integrity attribute
            $tag = str_replace(
                ' src=',
                ' integrity="' . esc_attr($integrity) . '" crossorigin="anonymous" src=',
                $tag
            );
        }

        return $tag;
    }

    /**
     * Get client IP address
     */
    public function get_client_ip(): string {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];

                // Handle comma-separated list
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
     * Check if IP is blocked
     */
    private function is_ip_blocked(string $ip): bool {
        $blocked = get_transient('isf_blocked_ips') ?: [];
        return isset($blocked[$ip]) && $blocked[$ip] > time();
    }

    /**
     * Block an IP address temporarily
     */
    private function block_ip(string $ip, int $duration = 3600): void {
        $blocked = get_transient('isf_blocked_ips') ?: [];
        $blocked[$ip] = time() + $duration;

        // Clean up expired entries
        $blocked = array_filter($blocked, fn($expires) => $expires > time());

        set_transient('isf_blocked_ips', $blocked, DAY_IN_SECONDS);
    }

    /**
     * Log security event
     */
    private function log_security_event(string $event_type, array $data): void {
        global $wpdb;

        $table = $wpdb->prefix . ISF_TABLE_LOGS;

        $wpdb->insert($table, [
            'log_type' => 'security',
            'log_level' => 'warning',
            'message' => 'Security event: ' . $event_type,
            'context' => wp_json_encode($data),
            'ip_address' => $data['ip'] ?? $this->get_client_ip(),
            'created_at' => current_time('mysql')
        ]);
    }

    /**
     * Get JavaScript security config for frontend
     */
    public function get_js_security_config(): array {
        return [
            'token' => $this->session_token,
            'nonce' => wp_create_nonce('isf_form_nonce'),
            'loadTime' => time(),
            'fingerprint' => $this->generate_browser_fingerprint_seed(),
        ];
    }

    /**
     * Generate a seed for client-side fingerprinting
     */
    private function generate_browser_fingerprint_seed(): string {
        return substr(hash('sha256', $this->session_token . date('Y-m-d')), 0, 16);
    }

    /**
     * Verify request integrity
     */
    public function verify_request_integrity(array $data): bool {
        // Check nonce
        if (!wp_verify_nonce($data['nonce'] ?? '', 'isf_form_nonce')) {
            return false;
        }

        // Check session token (constant-time comparison to defeat timing attacks)
        if (! hash_equals((string) $this->session_token, (string) ($data['security_token'] ?? ''))) {
            return false;
        }

        // Check timing
        $load_time = intval($data['form_load_time'] ?? 0);
        if ($load_time > 0 && (time() - $load_time) < 2) {
            return false;
        }

        return true;
    }

    /**
     * Generate file checksum for integrity verification
     */
    public static function generate_plugin_checksum(): string {
        $files = [
            ISF_PLUGIN_DIR . 'formflow.php',
            ISF_PLUGIN_DIR . 'includes/class-plugin.php',
            ISF_PLUGIN_DIR . 'includes/class-license-manager.php',
            ISF_PLUGIN_DIR . 'includes/class-security-hardening.php',
        ];

        $checksums = [];

        foreach ($files as $file) {
            if (file_exists($file)) {
                $checksums[] = md5_file($file);
            }
        }

        return hash('sha256', implode('', $checksums));
    }

    /**
     * Verify plugin integrity
     */
    public static function verify_plugin_integrity(): bool {
        $stored_checksum = get_option('isf_plugin_checksum');
        $current_checksum = self::generate_plugin_checksum();

        if (empty($stored_checksum)) {
            // First run, store checksum
            update_option('isf_plugin_checksum', $current_checksum);
            return true;
        }

        return $stored_checksum === $current_checksum;
    }
}

/**
 * Helper function to get security instance
 */
function isf_security(): SecurityHardening {
    return SecurityHardening::instance();
}
