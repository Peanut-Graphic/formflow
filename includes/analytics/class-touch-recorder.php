<?php
namespace ISF\Analytics;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Touch Recorder
 *
 * Records all marketing touchpoints for attribution analysis.
 * Tracks page views, form interactions, and handoffs.
 */


use ISF\Database\Database;

class TouchRecorder {

    /**
     * Database instance
     */
    private Database $db;

    /**
     * Visitor tracker instance
     */
    private VisitorTracker $visitor_tracker;

    /**
     * Constructor
     */
    public function __construct(?VisitorTracker $visitor_tracker = null) {
        $this->db = new Database();
        $this->visitor_tracker = $visitor_tracker ?? new VisitorTracker();
    }

    /**
     * Record a marketing touch
     *
     * @param string $touch_type Type of touch (page_view, form_view, form_start, form_complete, handoff, return_visit)
     * @param int|null $instance_id Form instance ID (optional)
     * @param array $extra_data Additional data to store
     * @return int|false Touch ID or false on failure
     */
    public function record_touch(string $touch_type, ?int $instance_id = null, array $extra_data = []): int|false {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        $visitor_id = $this->visitor_tracker->get_visitor_id();
        if (!$visitor_id) {
            return false;
        }

        // Validate touch type
        $valid_types = ['page_view', 'form_view', 'form_start', 'form_complete', 'handoff', 'return_visit'];
        if (!in_array($touch_type, $valid_types, true)) {
            return false;
        }

        // Get attribution data from request
        $attribution = $this->get_attribution_from_request();

        // Merge with extra data
        $touch_data = array_merge($extra_data, [
            'timestamp' => current_time('mysql'),
        ]);

        $data = [
            'visitor_id' => $visitor_id,
            'instance_id' => $instance_id,
            'touch_type' => $touch_type,
            'utm_source' => $attribution['utm_source'] ?? null,
            'utm_medium' => $attribution['utm_medium'] ?? null,
            'utm_campaign' => $attribution['utm_campaign'] ?? null,
            'utm_term' => $attribution['utm_term'] ?? null,
            'utm_content' => $attribution['utm_content'] ?? null,
            'gclid' => $attribution['gclid'] ?? null,
            'fbclid' => $attribution['fbclid'] ?? null,
            'msclkid' => $attribution['msclkid'] ?? null,
            'referrer' => $attribution['referrer'] ?? null,
            'referrer_domain' => $attribution['referrer_domain'] ?? null,
            'landing_page' => $attribution['landing_page'] ?? null,
            'page_url' => $this->get_current_url(),
            'promo_code' => $attribution['promo_code'] ?? null,
            'touch_data' => wp_json_encode($touch_data),
        ];

        $result = $wpdb->insert(
            $table,
            $data,
            [
                '%s', // visitor_id
                '%d', // instance_id
                '%s', // touch_type
                '%s', // utm_source
                '%s', // utm_medium
                '%s', // utm_campaign
                '%s', // utm_term
                '%s', // utm_content
                '%s', // gclid
                '%s', // fbclid
                '%s', // msclkid
                '%s', // referrer
                '%s', // referrer_domain
                '%s', // landing_page
                '%s', // page_url
                '%s', // promo_code
                '%s', // touch_data
            ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Record a page view touch
     */
    public function record_page_view(?int $instance_id = null): int|false {
        return $this->record_touch('page_view', $instance_id);
    }

    /**
     * Record a form view touch
     */
    public function record_form_view(int $instance_id): int|false {
        return $this->record_touch('form_view', $instance_id);
    }

    /**
     * Record a form start touch (first field interaction)
     */
    public function record_form_start(int $instance_id, array $extra_data = []): int|false {
        return $this->record_touch('form_start', $instance_id, $extra_data);
    }

    /**
     * Record a form completion touch
     */
    public function record_form_complete(int $instance_id, array $extra_data = []): int|false {
        return $this->record_touch('form_complete', $instance_id, $extra_data);
    }

    /**
     * Record a handoff touch (redirect to external enrollment)
     */
    public function record_handoff(int $instance_id, string $destination_url, string $handoff_token): int|false {
        return $this->record_touch('handoff', $instance_id, [
            'destination_url' => $destination_url,
            'handoff_token' => $handoff_token,
        ]);
    }

    /**
     * Record a return visit touch
     */
    public function record_return_visit(?int $instance_id = null): int|false {
        return $this->record_touch('return_visit', $instance_id);
    }

    /**
     * Get attribution data from current request
     */
    private function get_attribution_from_request(): array {
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
            $attribution['landing_page'] = $this->get_current_url();
        }

        return $attribution;
    }

    /**
     * Get current URL
     */
    private function get_current_url(): string {
        $protocol = is_ssl() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return esc_url_raw("{$protocol}://{$host}{$uri}");
    }

    /**
     * Check if referrer is internal
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

        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Get all touches for a visitor
     */
    public function get_visitor_touches(string $visitor_id, ?int $limit = 100): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        $query = $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE visitor_id = %s
             ORDER BY created_at DESC
             LIMIT %d",
            $visitor_id,
            $limit
        );

        $touches = $wpdb->get_results($query, ARRAY_A);

        // Decode JSON fields
        foreach ($touches as &$touch) {
            $touch['touch_data'] = json_decode($touch['touch_data'] ?? '{}', true) ?: [];
        }

        return $touches;
    }

    /**
     * Get touches for an instance within a date range
     */
    public function get_instance_touches(
        int $instance_id,
        string $start_date,
        string $end_date,
        ?string $touch_type = null
    ): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        $sql = "SELECT * FROM {$table}
                WHERE instance_id = %d
                AND created_at BETWEEN %s AND %s";

        $params = [
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
        ];

        if ($touch_type) {
            $sql .= " AND touch_type = %s";
            $params[] = $touch_type;
        }

        $sql .= " ORDER BY created_at DESC";

        $touches = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        foreach ($touches as &$touch) {
            $touch['touch_data'] = json_decode($touch['touch_data'] ?? '{}', true) ?: [];
        }

        return $touches;
    }

    /**
     * Get touch counts by type for an instance
     */
    public function get_touch_counts(int $instance_id, string $start_date, string $end_date): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT touch_type, COUNT(*) as count
             FROM {$table}
             WHERE instance_id = %d
             AND created_at BETWEEN %s AND %s
             GROUP BY touch_type",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['touch_type']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Get attribution breakdown by source
     */
    public function get_attribution_by_source(
        int $instance_id,
        string $start_date,
        string $end_date,
        string $touch_type = 'form_complete'
    ): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                COALESCE(utm_source, referrer_domain, 'direct') as source,
                utm_medium,
                utm_campaign,
                COUNT(*) as count
             FROM {$table}
             WHERE instance_id = %d
             AND touch_type = %s
             AND created_at BETWEEN %s AND %s
             GROUP BY utm_source, referrer_domain, utm_medium, utm_campaign
             ORDER BY count DESC",
            $instance_id,
            $touch_type,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);
    }

    /**
     * Get conversion funnel data
     */
    public function get_funnel_data(int $instance_id, string $start_date, string $end_date): array {
        $counts = $this->get_touch_counts($instance_id, $start_date, $end_date);

        return [
            'page_views' => $counts['page_view'] ?? 0,
            'form_views' => $counts['form_view'] ?? 0,
            'form_starts' => $counts['form_start'] ?? 0,
            'form_completes' => $counts['form_complete'] ?? 0,
            'handoffs' => $counts['handoff'] ?? 0,
            'conversion_rates' => [
                'view_to_start' => $this->calculate_rate($counts['form_view'] ?? 0, $counts['form_start'] ?? 0),
                'start_to_complete' => $this->calculate_rate($counts['form_start'] ?? 0, $counts['form_complete'] ?? 0),
                'overall' => $this->calculate_rate($counts['form_view'] ?? 0, $counts['form_complete'] ?? 0),
            ],
        ];
    }

    /**
     * Calculate conversion rate
     */
    private function calculate_rate(int $from, int $to): float {
        if ($from === 0) {
            return 0.0;
        }

        return round(($to / $from) * 100, 2);
    }

    /**
     * Get unique visitor count by touch type
     */
    public function get_unique_visitors(int $instance_id, string $start_date, string $end_date): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT touch_type, COUNT(DISTINCT visitor_id) as unique_visitors
             FROM {$table}
             WHERE instance_id = %d
             AND created_at BETWEEN %s AND %s
             GROUP BY touch_type",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        $visitors = [];
        foreach ($results as $row) {
            $visitors[$row['touch_type']] = (int) $row['unique_visitors'];
        }

        return $visitors;
    }

    /**
     * Delete old touches (for data retention)
     */
    public function cleanup_old_touches(int $days_to_keep = 365): int {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_TOUCHES;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff
        ));
    }
}
