<?php
namespace ISF\Analytics;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Handoff Tracker
 *
 * Tracks redirects to external enrollment systems (like IntelliSOURCE).
 * Creates trackable URLs and records handoff events for attribution.
 */


use ISF\Database\Database;

class HandoffTracker {

    /**
     * Database instance
     */
    private Database $db;

    /**
     * Visitor tracker instance
     */
    private VisitorTracker $visitor_tracker;

    /**
     * Touch recorder instance
     */
    private TouchRecorder $touch_recorder;

    /**
     * Constructor
     */
    public function __construct(
        ?VisitorTracker $visitor_tracker = null,
        ?TouchRecorder $touch_recorder = null
    ) {
        $this->db = new Database();
        $this->visitor_tracker = $visitor_tracker ?? new VisitorTracker();
        $this->touch_recorder = $touch_recorder ?? new TouchRecorder($this->visitor_tracker);
    }

    /**
     * Create a tracked handoff and return the redirect URL
     *
     * @param int $instance_id Form instance ID
     * @param string $destination_url External enrollment URL
     * @param array $params Additional parameters to append to URL
     * @return array Handoff data including redirect URL and token
     */
    public function create_handoff(int $instance_id, string $destination_url, array $params = []): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        // Generate unique handoff token
        $handoff_token = $this->generate_token();

        // Get visitor ID
        $visitor_id = $this->visitor_tracker->get_visitor_id();

        // Capture current attribution
        $attribution = $this->capture_attribution();

        // Build final destination URL with tracking parameter
        $tracked_url = $this->build_tracked_url($destination_url, $handoff_token, $params);

        // Insert handoff record
        $result = $wpdb->insert(
            $table,
            [
                'instance_id' => $instance_id,
                'visitor_id' => $visitor_id,
                'handoff_token' => $handoff_token,
                'destination_url' => $destination_url,
                'attribution' => wp_json_encode($attribution),
                'status' => 'redirected',
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$result) {
            return ['error' => 'Failed to create handoff record'];
        }

        $handoff_id = $wpdb->insert_id;

        // Record the handoff touch
        $this->touch_recorder->record_handoff($instance_id, $destination_url, $handoff_token);

        // Log the handoff
        $this->db->log('info', 'Handoff created', [
            'handoff_id' => $handoff_id,
            'token' => $handoff_token,
            'destination' => $destination_url,
        ], $instance_id);

        // Fire handoff redirect hook for Peanut Suite integration
        do_action(\ISF\Hooks::HANDOFF_REDIRECT, $instance_id, $visitor_id, $destination_url, $attribution);

        return [
            'handoff_id' => $handoff_id,
            'token' => $handoff_token,
            'redirect_url' => $tracked_url,
            'destination_url' => $destination_url,
            'visitor_id' => $visitor_id,
        ];
    }

    /**
     * Get the internal redirect URL that tracks the handoff
     *
     * @param int $instance_id Form instance ID
     * @param string $destination_url External enrollment URL
     * @param array $params Additional parameters
     * @return string The tracking redirect URL
     */
    public function get_tracking_redirect_url(int $instance_id, string $destination_url, array $params = []): string {
        $handoff = $this->create_handoff($instance_id, $destination_url, $params);

        if (isset($handoff['error'])) {
            // Fall back to direct URL on error
            return $destination_url;
        }

        // Return the internal redirect endpoint URL
        return add_query_arg([
            'isf_handoff' => $handoff['token'],
        ], home_url('/'));
    }

    /**
     * Process a handoff redirect request
     * Should be called when isf_handoff parameter is present
     *
     * @param string $token Handoff token from URL
     * @return string|null Destination URL or null if invalid
     */
    public function process_redirect(string $token): ?string {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        // Validate token format
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        // Get handoff record
        $handoff = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE handoff_token = %s",
            $token
        ), ARRAY_A);

        if (!$handoff) {
            return null;
        }

        // Check if already completed or expired
        if ($handoff['status'] !== 'redirected') {
            // Still allow redirect but don't update status
            return $handoff['destination_url'];
        }

        return $handoff['destination_url'];
    }

    /**
     * Mark a handoff as completed
     *
     * @param string $token Handoff token
     * @param array $completion_data Data about the completion
     * @return bool Success status
     */
    public function mark_completed(string $token, array $completion_data = []): bool {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        $result = $wpdb->update(
            $table,
            [
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'completion_data' => wp_json_encode($completion_data),
                'account_number' => $completion_data['account_number'] ?? null,
                'external_id' => $completion_data['external_id'] ?? null,
            ],
            ['handoff_token' => $token],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%s']
        );

        if ($result !== false) {
            $this->db->log('info', 'Handoff completed', [
                'token' => $token,
                'completion_data' => $completion_data,
            ]);
        }

        return $result !== false;
    }

    /**
     * Mark old handoffs as expired
     *
     * @param int $hours_old Hours after which to expire handoffs
     * @return int Number of handoffs expired
     */
    public function expire_old_handoffs(int $hours_old = 168): int {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$hours_old} hours"));

        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'expired'
             WHERE status = 'redirected'
             AND created_at < %s",
            $cutoff
        ));
    }

    /**
     * Get handoff by token
     */
    public function get_handoff(string $token): ?array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        $handoff = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE handoff_token = %s",
            $token
        ), ARRAY_A);

        if ($handoff) {
            $handoff['attribution'] = json_decode($handoff['attribution'] ?? '{}', true) ?: [];
            $handoff['completion_data'] = json_decode($handoff['completion_data'] ?? '{}', true) ?: [];
        }

        return $handoff;
    }

    /**
     * Get handoff by account number (for matching completions)
     */
    public function find_handoff_by_account(string $account_number, int $instance_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        // Look for recent handoffs that might match
        $handoff = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE instance_id = %d
             AND status = 'redirected'
             AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY created_at DESC
             LIMIT 1",
            $instance_id
        ), ARRAY_A);

        if ($handoff) {
            $handoff['attribution'] = json_decode($handoff['attribution'] ?? '{}', true) ?: [];
        }

        return $handoff;
    }

    /**
     * Get handoff statistics for an instance
     */
    public function get_handoff_stats(int $instance_id, string $start_date, string $end_date): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        $stats = [];

        // Total handoffs
        $stats['total'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE instance_id = %d
             AND created_at BETWEEN %s AND %s",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // By status
        $status_results = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count
             FROM {$table}
             WHERE instance_id = %d
             AND created_at BETWEEN %s AND %s
             GROUP BY status",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        $stats['by_status'] = [];
        foreach ($status_results as $row) {
            $stats['by_status'][$row['status']] = (int) $row['count'];
        }

        // Completion rate
        $completed = $stats['by_status']['completed'] ?? 0;
        $stats['completion_rate'] = $stats['total'] > 0
            ? round(($completed / $stats['total']) * 100, 2)
            : 0;

        // Average time to completion
        $stats['avg_completion_hours'] = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at))
             FROM {$table}
             WHERE instance_id = %d
             AND status = 'completed'
             AND created_at BETWEEN %s AND %s",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        return $stats;
    }

    /**
     * Get recent handoffs for an instance
     */
    public function get_recent_handoffs(int $instance_id, int $limit = 50): array {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_HANDOFFS;

        $handoffs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE instance_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $instance_id,
            $limit
        ), ARRAY_A);

        foreach ($handoffs as &$handoff) {
            $handoff['attribution'] = json_decode($handoff['attribution'] ?? '{}', true) ?: [];
            $handoff['completion_data'] = json_decode($handoff['completion_data'] ?? '{}', true) ?: [];
        }

        return $handoffs;
    }

    /**
     * Generate unique handoff token
     */
    private function generate_token(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Capture current attribution data
     */
    private function capture_attribution(): array {
        $attribution = $this->visitor_tracker->get_current_attribution();

        // Add timestamp
        $attribution['captured_at'] = current_time('mysql');

        // Add current page
        $attribution['handoff_page'] = $this->get_current_url();

        return $attribution;
    }

    /**
     * Build tracked URL with handoff token
     */
    private function build_tracked_url(string $destination_url, string $token, array $params = []): string {
        // Start with destination URL
        $url = $destination_url;

        // Add handoff token as parameter
        $params['isf_ref'] = $token;

        // Append parameters
        foreach ($params as $key => $value) {
            $url = add_query_arg($key, $value, $url);
        }

        return $url;
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
}
