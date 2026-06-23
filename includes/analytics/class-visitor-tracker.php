<?php
namespace ISF\Analytics;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Visitor Tracker
 *
 * Tracks anonymous visitors across sessions using first-party cookies.
 * Provides visitor identification for attribution tracking.
 */


use ISF\Database\Database;

class VisitorTracker {

    /**
     * Cookie name for visitor ID
     */
    private const COOKIE_NAME = 'isf_visitor';

    /**
     * Cookie expiry in days (default: 365)
     */
    private int $cookie_days = 365;

    /**
     * Minimum seconds between "last seen" writes for the same visitor.
     * Without this, every front-end pageview issues an UPDATE (the tracker is
     * hooked on init@5), which is needless write load on busy sites. One write
     * per visitor per window is plenty for last-seen/visit-count accuracy.
     */
    private const SEEN_THROTTLE_SECONDS = 900; // 15 minutes

    /**
     * Database instance
     */
    private Database $db;

    /**
     * Current visitor ID
     */
    private ?string $visitor_id = null;

    /**
     * Per-instance cache of the visitors-table existence probe (null = unknown).
     */
    private ?bool $table_ready = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database();
    }

    /**
     * Initialize visitor tracking
     * Should be called early in request lifecycle (before headers sent)
     */
    public function init(): void {
        $this->visitor_id = $this->get_or_create_visitor_id();
    }

    /**
     * Get or create visitor ID from cookie
     */
    public function get_or_create_visitor_id(): string {
        // Check for Peanut Suite visitor ID first (shared visitor tracking)
        $peanut_visitor_id = apply_filters(\ISF\Hooks::GET_VISITOR_ID, null);
        if ($peanut_visitor_id && preg_match('/^[a-f0-9]{32}$/', $peanut_visitor_id)) {
            // Use Peanut's visitor ID but ensure we have a local record
            $this->ensure_visitor_record($peanut_visitor_id);
            return $peanut_visitor_id;
        }

        // Check for existing cookie
        if (isset($_COOKIE[self::COOKIE_NAME]) && $_COOKIE[self::COOKIE_NAME] !== '') {
            $visitor_id = sanitize_text_field($_COOKIE[self::COOKIE_NAME]);

            // Validate format (should be 32 hex chars)
            if (preg_match('/^[a-f0-9]{32}$/', $visitor_id)) {
                // Update last seen timestamp
                $this->update_visitor_seen($visitor_id);

                // Fire hook for returning visitor
                do_action(\ISF\Hooks::VISITOR_IDENTIFIED, $visitor_id, false, $this->collect_device_info());

                return $visitor_id;
            }
        }

        // Generate new visitor ID
        $visitor_id = $this->generate_visitor_id();

        // Set cookie if headers not sent
        if (!headers_sent()) {
            $this->set_visitor_cookie($visitor_id);
        }

        // Create visitor record
        $this->create_visitor_record($visitor_id);

        // Fire hook for new visitor
        do_action(\ISF\Hooks::VISITOR_IDENTIFIED, $visitor_id, true, $this->collect_device_info());

        return $visitor_id;
    }

    /**
     * Ensure visitor record exists (for Peanut Suite integration)
     *
     * @param string $visitor_id Visitor ID from Peanut Suite
     */
    private function ensure_visitor_record(string $visitor_id): void {
        if (!$this->visitors_table_ready()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_VISITORS;

        // Check if record exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE visitor_id = %s",
            $visitor_id
        ));

        if ($exists) {
            // Update last seen
            $this->update_visitor_seen($visitor_id);
        } else {
            // Create record for Peanut visitor
            $this->create_visitor_record($visitor_id);
        }
    }

    /**
     * Get current visitor ID (without creating new one)
     */
    public function get_visitor_id(): ?string {
        if ($this->visitor_id) {
            return $this->visitor_id;
        }

        if (isset($_COOKIE[self::COOKIE_NAME]) && $_COOKIE[self::COOKIE_NAME] !== '') {
            $visitor_id = sanitize_text_field($_COOKIE[self::COOKIE_NAME]);
            if (preg_match('/^[a-f0-9]{32}$/', $visitor_id)) {
                return $visitor_id;
            }
        }

        return null;
    }

    /**
     * Generate a unique visitor ID
     */
    private function generate_visitor_id(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Set the visitor cookie
     */
    private function set_visitor_cookie(string $visitor_id): void {
        $expiry = time() + ($this->cookie_days * DAY_IN_SECONDS);

        setcookie(
            self::COOKIE_NAME,
            $visitor_id,
            [
                'expires' => $expiry,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );

        // Also set in superglobal for immediate access
        $_COOKIE[self::COOKIE_NAME] = $visitor_id;
    }

    /**
     * Create a new visitor record in the database
     */
    private function create_visitor_record(string $visitor_id): bool {
        if (!$this->visitors_table_ready()) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_VISITORS;

        // Collect device info
        $device_info = $this->collect_device_info();

        // Get first touch attribution
        $first_touch = $this->get_current_attribution();

        $result = $wpdb->insert(
            $table,
            [
                'visitor_id' => $visitor_id,
                'fingerprint_hash' => $this->generate_fingerprint_hash(),
                'first_seen_at' => current_time('mysql'),
                'last_seen_at' => current_time('mysql'),
                'visit_count' => 1,
                'first_touch' => wp_json_encode($first_touch),
                'device_info' => wp_json_encode($device_info),
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Update visitor last seen timestamp and visit count.
     *
     * Throttled to one write per visitor per SEEN_THROTTLE_SECONDS via a
     * transient, so a burst of pageviews from the same visitor doesn't issue
     * an UPDATE on every hit. Also short-circuits when the visitors table is
     * missing/drifted so a schema problem can never become per-request error
     * spam in the log.
     */
    private function update_visitor_seen(string $visitor_id): void {
        if (!$this->visitors_table_ready()) {
            return;
        }

        $throttle_key = 'isf_vseen_' . md5($visitor_id);
        if (get_transient($throttle_key)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_VISITORS;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET last_seen_at = %s, visit_count = visit_count + 1
             WHERE visitor_id = %s",
            current_time('mysql'),
            $visitor_id
        ));

        set_transient($throttle_key, 1, self::SEEN_THROTTLE_SECONDS);
    }

    /**
     * Whether the visitors table exists.
     *
     * Cached per-request (and across requests via a short transient) so the
     * existence probe doesn't itself become a per-pageview query. Guards every
     * write so a missing/drifted table degrades gracefully instead of logging
     * a "Table doesn't exist" error on every front-end request.
     */
    private function visitors_table_ready(): bool {
        if ($this->table_ready !== null) {
            return $this->table_ready;
        }

        if (get_transient('isf_visitors_table_ok')) {
            $this->table_ready = true;
            return $this->table_ready;
        }

        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_VISITORS;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $this->table_ready = ($found === $table);

        if ($this->table_ready) {
            // Cache the positive result; negative results stay uncached so a
            // freshly-created table is picked up on the next request.
            set_transient('isf_visitors_table_ok', 1, HOUR_IN_SECONDS);
        }

        return $this->table_ready;
    }

    /**
     * Collect device information from request
     */
    private function collect_device_info(): array {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return [
            'user_agent' => substr($user_agent, 0, 500),
            'browser' => $this->parse_browser($user_agent),
            'os' => $this->parse_os($user_agent),
            'is_mobile' => $this->is_mobile($user_agent),
            'language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'screen_width' => null,  // Set via JS if needed
            'screen_height' => null,
        ];
    }

    /**
     * Get current attribution data from request
     */
    public function get_current_attribution(): array {
        $attribution = [];

        // UTM parameters
        $utm_params = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        foreach ($utm_params as $param) {
            if (isset($_GET[$param]) && $_GET[$param] !== '') {
                $attribution[$param] = sanitize_text_field($_GET[$param]);
            }
        }

        // Ad platform click IDs
        $click_ids = ['gclid', 'fbclid', 'msclkid', 'dclid'];
        foreach ($click_ids as $param) {
            if (isset($_GET[$param]) && $_GET[$param] !== '') {
                $attribution[$param] = sanitize_text_field($_GET[$param]);
            }
        }

        // Promo code
        if (isset($_GET['promo']) && $_GET['promo'] !== '') {
            $attribution['promo_code'] = sanitize_text_field($_GET['promo']);
        }

        // Referrer
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $referrer = esc_url_raw($_SERVER['HTTP_REFERER']);
            if (!$this->is_internal_referrer($referrer)) {
                $attribution['referrer'] = $referrer;
                $attribution['referrer_domain'] = $this->extract_domain($referrer);
            }
        }

        // Landing page
        if (!empty($_SERVER['REQUEST_URI'])) {
            $attribution['landing_page'] = esc_url_raw(
                (is_ssl() ? 'https' : 'http') .
                '://' . ($_SERVER['HTTP_HOST'] ?? '') . $_SERVER['REQUEST_URI']
            );
        }

        return $attribution;
    }

    /**
     * Generate a browser fingerprint hash
     * Uses available server-side signals (not as unique as client-side)
     */
    private function generate_fingerprint_hash(): ?string {
        $signals = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
        ];

        $fingerprint = implode('|', $signals);

        if (empty(trim($fingerprint, '|'))) {
            return null;
        }

        return hash('sha256', $fingerprint);
    }

    /**
     * Parse browser name from user agent
     */
    private function parse_browser(string $user_agent): string {
        if (preg_match('/Edge\/|Edg\//i', $user_agent)) {
            return 'Edge';
        }
        if (preg_match('/Chrome/i', $user_agent)) {
            return 'Chrome';
        }
        if (preg_match('/Safari/i', $user_agent) && !preg_match('/Chrome/i', $user_agent)) {
            return 'Safari';
        }
        if (preg_match('/Firefox/i', $user_agent)) {
            return 'Firefox';
        }
        if (preg_match('/MSIE|Trident/i', $user_agent)) {
            return 'Internet Explorer';
        }
        if (preg_match('/Opera|OPR/i', $user_agent)) {
            return 'Opera';
        }

        return 'Unknown';
    }

    /**
     * Parse OS from user agent
     */
    private function parse_os(string $user_agent): string {
        if (preg_match('/Windows/i', $user_agent)) {
            return 'Windows';
        }
        if (preg_match('/Macintosh|Mac OS/i', $user_agent)) {
            return 'macOS';
        }
        if (preg_match('/Linux/i', $user_agent) && !preg_match('/Android/i', $user_agent)) {
            return 'Linux';
        }
        if (preg_match('/Android/i', $user_agent)) {
            return 'Android';
        }
        if (preg_match('/iPhone|iPad|iPod/i', $user_agent)) {
            return 'iOS';
        }

        return 'Unknown';
    }

    /**
     * Check if user agent indicates mobile device
     */
    private function is_mobile(string $user_agent): bool {
        return (bool) preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $user_agent);
    }

    /**
     * Check if referrer is internal (same domain)
     */
    private function is_internal_referrer(string $referrer): bool {
        $referrer_host = parse_url($referrer, PHP_URL_HOST);
        $site_host = parse_url(home_url(), PHP_URL_HOST);

        return $referrer_host === $site_host;
    }

    /**
     * Extract domain from URL
     */
    private function extract_domain(string $url): string {
        $host = parse_url($url, PHP_URL_HOST);

        if (!$host) {
            return '';
        }

        // Remove www prefix
        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Get visitor record from database
     */
    public function get_visitor(string $visitor_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_VISITORS;

        $visitor = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE visitor_id = %s",
            $visitor_id
        ), ARRAY_A);

        if ($visitor) {
            $visitor['first_touch'] = json_decode($visitor['first_touch'] ?? '{}', true) ?: [];
            $visitor['device_info'] = json_decode($visitor['device_info'] ?? '{}', true) ?: [];
        }

        return $visitor;
    }

    /**
     * Link visitor to an email address (for cross-session stitching)
     */
    public function link_visitor_to_email(string $visitor_id, string $email): void {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_VISITORS;

        // Store email hash for privacy
        $email_hash = hash('sha256', strtolower(trim($email)));

        $wpdb->update(
            $table,
            ['email_hash' => $email_hash],
            ['visitor_id' => $visitor_id],
            ['%s'],
            ['%s']
        );
    }

    /**
     * Find visitor by email hash
     */
    public function find_visitor_by_email(string $email): ?array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_VISITORS;

        $email_hash = hash('sha256', strtolower(trim($email)));

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE email_hash = %s ORDER BY first_seen_at ASC LIMIT 1",
            $email_hash
        ), ARRAY_A);
    }

    /**
     * Get visitor statistics
     */
    public function get_visitor_stats(string $start_date, string $end_date): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_VISITORS;

        $stats = [];

        // Total visitors
        $stats['total'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE first_seen_at BETWEEN %s AND %s",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // Returning visitors (visit_count > 1)
        $stats['returning'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE first_seen_at BETWEEN %s AND %s
             AND visit_count > 1",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // By device type
        $stats['by_device'] = $wpdb->get_results($wpdb->prepare(
            "SELECT
                JSON_UNQUOTE(JSON_EXTRACT(device_info, '$.is_mobile')) as is_mobile,
                COUNT(*) as count
             FROM {$table}
             WHERE first_seen_at BETWEEN %s AND %s
             GROUP BY is_mobile",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        return $stats;
    }

    /**
     * Set cookie expiry days
     */
    public function set_cookie_days(int $days): void {
        $this->cookie_days = max(1, min(730, $days)); // 1 day to 2 years
    }

    /**
     * Clear visitor cookie (for testing/privacy)
     */
    public function clear_cookie(): void {
        if (!headers_sent()) {
            setcookie(
                self::COOKIE_NAME,
                '',
                [
                    'expires' => time() - 3600,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                ]
            );
        }
        unset($_COOKIE[self::COOKIE_NAME]);
        $this->visitor_id = null;
    }
}
