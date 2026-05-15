<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * UTM Tracker
 *
 * Tracks marketing attribution via UTM parameters, referrers, and landing pages.
 */


class UTMTracker {

    /**
     * UTM parameters to track
     */
    private const UTM_PARAMS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
    ];

    /**
     * Additional tracking parameters
     */
    private const EXTRA_PARAMS = [
        'gclid',      // Google Ads
        'fbclid',     // Facebook
        'msclkid',    // Microsoft Ads
        'dclid',      // DoubleClick
        'ref',        // Generic referral code
        'promo',      // Promo code
    ];

    /**
     * Session key for storing tracking data
     */
    private const SESSION_KEY = 'isf_utm_data';

    /**
     * Cookie name for persisting UTM data
     */
    private const COOKIE_NAME = 'isf_attribution';

    /**
     * Cookie expiry in days
     */
    private const COOKIE_EXPIRY_DAYS = 30;

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
     * Initialize tracking for a request
     */
    public function init(): void {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Capture UTM params from URL if present
        $this->capture_from_url();
    }

    /**
     * Capture UTM parameters from the current URL
     */
    public function capture_from_url(): array {
        $tracking = [];

        // Capture UTM parameters
        foreach (self::UTM_PARAMS as $param) {
            if (!empty($_GET[$param])) {
                $tracking[$param] = sanitize_text_field($_GET[$param]);
            }
        }

        // Capture extra tracking parameters
        foreach (self::EXTRA_PARAMS as $param) {
            if (!empty($_GET[$param])) {
                $tracking[$param] = sanitize_text_field($_GET[$param]);
            }
        }

        // Add referrer if not already set and available
        if (empty($tracking['referrer']) && !empty($_SERVER['HTTP_REFERER'])) {
            $referrer = esc_url_raw($_SERVER['HTTP_REFERER']);
            // Only store external referrers
            if (!$this->is_internal_referrer($referrer)) {
                $tracking['referrer'] = $referrer;
                $tracking['referrer_domain'] = $this->extract_domain($referrer);
            }
        }

        // Add landing page
        if (!empty($_SERVER['REQUEST_URI'])) {
            $tracking['landing_page'] = esc_url_raw(
                (isset($_SERVER['HTTPS']) ? 'https' : 'http') .
                '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
            );
        }

        // Add timestamp
        if (!empty($tracking)) {
            $tracking['captured_at'] = current_time('mysql');
        }

        // Store if we have new tracking data
        if (!empty($tracking)) {
            $this->store_tracking_data($tracking);

            // Fire hook for Peanut Suite integration
            $visitor_id = $this->get_visitor_id();
            do_action(Hooks::UTM_CAPTURED, $tracking, $visitor_id);
        }

        return $tracking;
    }

    /**
     * Get current visitor ID (integrates with Peanut Suite)
     *
     * @return string Visitor ID
     */
    private function get_visitor_id(): string {
        // Check for Peanut Suite visitor ID first via filter
        $visitor_id = apply_filters(Hooks::GET_VISITOR_ID, null);

        if ($visitor_id) {
            return $visitor_id;
        }

        // Fall back to ISF visitor tracker
        if (class_exists(Analytics\VisitorTracker::class)) {
            $tracker = new Analytics\VisitorTracker();
            return $tracker->get_or_create_visitor_id();
        }

        // Generate a session-based ID as fallback
        if (!empty($_COOKIE['isf_visitor'])) {
            return sanitize_text_field($_COOKIE['isf_visitor']);
        }

        return '';
    }

    /**
     * Store tracking data in session and cookie
     */
    private function store_tracking_data(array $data): void {
        // Merge with existing data (first touch wins for UTM, last touch for others)
        $existing = $this->get_tracking_data();

        // First touch attribution for UTM params
        foreach (self::UTM_PARAMS as $param) {
            if (!empty($data[$param]) && empty($existing[$param])) {
                $existing[$param] = $data[$param];
            }
        }

        // Last touch for extra params (they may change between visits)
        foreach (self::EXTRA_PARAMS as $param) {
            if (!empty($data[$param])) {
                $existing[$param] = $data[$param];
            }
        }

        // Keep first referrer
        if (!empty($data['referrer']) && empty($existing['referrer'])) {
            $existing['referrer'] = $data['referrer'];
            $existing['referrer_domain'] = $data['referrer_domain'] ?? '';
        }

        // Keep first landing page
        if (!empty($data['landing_page']) && empty($existing['landing_page'])) {
            $existing['landing_page'] = $data['landing_page'];
        }

        // Update timestamp
        $existing['updated_at'] = current_time('mysql');
        if (empty($existing['captured_at'])) {
            $existing['captured_at'] = $data['captured_at'] ?? current_time('mysql');
        }

        // Store in session
        $_SESSION[self::SESSION_KEY] = $existing;

        // Store in cookie for cross-session attribution
        $this->set_attribution_cookie($existing);
    }

    /**
     * Get current tracking data
     */
    public function get_tracking_data(): array {
        // Check session first
        if (!empty($_SESSION[self::SESSION_KEY])) {
            return $_SESSION[self::SESSION_KEY];
        }

        // Fall back to cookie
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            $cookie_data = json_decode(base64_decode($_COOKIE[self::COOKIE_NAME]), true);
            if (is_array($cookie_data)) {
                $_SESSION[self::SESSION_KEY] = $cookie_data;
                return $cookie_data;
            }
        }

        return [];
    }

    /**
     * Get tracking data for a form submission
     */
    public function get_submission_tracking(array $instance): array {
        $config = FeatureManager::get_feature($instance, 'utm_tracking');
        $tracking = $this->get_tracking_data();

        $result = [
            'utm_source' => $tracking['utm_source'] ?? '',
            'utm_medium' => $tracking['utm_medium'] ?? '',
            'utm_campaign' => $tracking['utm_campaign'] ?? '',
            'utm_term' => $tracking['utm_term'] ?? '',
            'utm_content' => $tracking['utm_content'] ?? '',
        ];

        if (!empty($config['track_referrer'])) {
            $result['referrer'] = $tracking['referrer'] ?? '';
            $result['referrer_domain'] = $tracking['referrer_domain'] ?? '';
        }

        if (!empty($config['track_landing_page'])) {
            $result['landing_page'] = $tracking['landing_page'] ?? '';
        }

        // Include extra tracking params
        foreach (self::EXTRA_PARAMS as $param) {
            if (!empty($tracking[$param])) {
                $result[$param] = $tracking[$param];
            }
        }

        $result['captured_at'] = $tracking['captured_at'] ?? '';

        return $result;
    }

    /**
     * Attach tracking data to API request if configured
     */
    public function get_api_tracking(array $instance): array {
        $config = FeatureManager::get_feature($instance, 'utm_tracking');

        if (empty($config['pass_to_api'])) {
            return [];
        }

        $tracking = $this->get_tracking_data();

        return [
            'marketing_source' => $tracking['utm_source'] ?? '',
            'marketing_medium' => $tracking['utm_medium'] ?? '',
            'marketing_campaign' => $tracking['utm_campaign'] ?? '',
            'promo_code' => $tracking['promo'] ?? '',
        ];
    }

    /**
     * Set attribution cookie
     */
    private function set_attribution_cookie(array $data): void {
        $cookie_data = base64_encode(json_encode($data));
        $expiry = time() + (self::COOKIE_EXPIRY_DAYS * 24 * 60 * 60);

        setcookie(
            self::COOKIE_NAME,
            $cookie_data,
            $expiry,
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
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
     * Get attribution report for a date range
     */
    public function get_attribution_report(int $instance_id, string $start_date, string $end_date): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;

        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT form_data FROM {$table}
             WHERE instance_id = %d
             AND status = 'completed'
             AND created_at BETWEEN %s AND %s",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        $report = [
            'by_source' => [],
            'by_medium' => [],
            'by_campaign' => [],
            'by_referrer' => [],
            'total_attributed' => 0,
            'total_unattributed' => 0,
        ];

        foreach ($submissions as $submission) {
            $form_data = maybe_unserialize($submission['form_data']);
            if (is_string($form_data)) {
                $form_data = json_decode($form_data, true);
            }

            $utm = $form_data['utm_tracking'] ?? [];
            $has_attribution = false;

            // Count by source
            if (!empty($utm['utm_source'])) {
                $source = $utm['utm_source'];
                $report['by_source'][$source] = ($report['by_source'][$source] ?? 0) + 1;
                $has_attribution = true;
            }

            // Count by medium
            if (!empty($utm['utm_medium'])) {
                $medium = $utm['utm_medium'];
                $report['by_medium'][$medium] = ($report['by_medium'][$medium] ?? 0) + 1;
                $has_attribution = true;
            }

            // Count by campaign
            if (!empty($utm['utm_campaign'])) {
                $campaign = $utm['utm_campaign'];
                $report['by_campaign'][$campaign] = ($report['by_campaign'][$campaign] ?? 0) + 1;
                $has_attribution = true;
            }

            // Count by referrer domain
            if (!empty($utm['referrer_domain'])) {
                $referrer = $utm['referrer_domain'];
                $report['by_referrer'][$referrer] = ($report['by_referrer'][$referrer] ?? 0) + 1;
                $has_attribution = true;
            }

            if ($has_attribution) {
                $report['total_attributed']++;
            } else {
                $report['total_unattributed']++;
            }
        }

        // Sort all arrays by count descending
        arsort($report['by_source']);
        arsort($report['by_medium']);
        arsort($report['by_campaign']);
        arsort($report['by_referrer']);

        return $report;
    }

    /**
     * Get top performing campaigns
     */
    public function get_top_campaigns(int $instance_id, int $limit = 10): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;

        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT form_data FROM {$table}
             WHERE instance_id = %d
             AND status = 'completed'
             AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $instance_id
        ), ARRAY_A);

        $campaigns = [];

        foreach ($submissions as $submission) {
            $form_data = maybe_unserialize($submission['form_data']);
            if (is_string($form_data)) {
                $form_data = json_decode($form_data, true);
            }

            $utm = $form_data['utm_tracking'] ?? [];

            if (!empty($utm['utm_campaign'])) {
                $key = $utm['utm_campaign'];
                if (!isset($campaigns[$key])) {
                    $campaigns[$key] = [
                        'campaign' => $utm['utm_campaign'],
                        'source' => $utm['utm_source'] ?? '',
                        'medium' => $utm['utm_medium'] ?? '',
                        'count' => 0,
                    ];
                }
                $campaigns[$key]['count']++;
            }
        }

        // Sort by count and return top N
        usort($campaigns, fn($a, $b) => $b['count'] - $a['count']);

        return array_slice($campaigns, 0, $limit);
    }

    /**
     * Clear tracking data (for testing or privacy)
     */
    public function clear_tracking(): void {
        unset($_SESSION[self::SESSION_KEY]);

        setcookie(
            self::COOKIE_NAME,
            '',
            time() - 3600,
            COOKIEPATH,
            COOKIE_DOMAIN
        );
    }

    /**
     * Render hidden fields for form
     */
    public static function render_hidden_fields(array $instance): string {
        if (!FeatureManager::is_enabled($instance, 'utm_tracking')) {
            return '';
        }

        $tracker = new self();
        $data = $tracker->get_submission_tracking($instance);

        $html = '';
        foreach ($data as $key => $value) {
            if (!empty($value)) {
                $html .= sprintf(
                    '<input type="hidden" name="utm_tracking[%s]" value="%s">',
                    esc_attr($key),
                    esc_attr($value)
                );
            }
        }

        return $html;
    }
}
