<?php
/**
 * Attribution Exporter
 *
 * Exports attribution data to CSV format.
 */

namespace ISF\Analytics;

if (!defined('ABSPATH')) {
    exit;
}

class AttributionExporter {

    /**
     * Attribution calculator instance
     */
    private AttributionCalculator $calculator;

    /**
     * Handoff tracker instance
     */
    private HandoffTracker $handoff_tracker;

    /**
     * Constructor
     */
    public function __construct() {
        $this->calculator = new AttributionCalculator();
        $this->handoff_tracker = new HandoffTracker();
    }

    /**
     * Register AJAX handlers
     */
    public static function register(): void {
        add_action('wp_ajax_isf_export_attribution', [new self(), 'handle_export']);
        add_action('wp_ajax_isf_export_completions', [new self(), 'handle_completions_export']);
    }

    /**
     * Handle export request
     */
    public function handle_export(): void {
        // Verify nonce
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'isf_export_attribution')) {
            wp_die(__('Security check failed.', 'formflow'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to export data.', 'formflow'));
        }

        $export_type = sanitize_text_field($_GET['export_type'] ?? 'channel_performance');
        $format = sanitize_text_field($_GET['format'] ?? 'csv');
        $instance_id = (int) ($_GET['instance_id'] ?? 0);
        $date_from = sanitize_text_field($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
        $date_to = sanitize_text_field($_GET['date_to'] ?? date('Y-m-d'));
        $attribution_model = sanitize_text_field($_GET['attribution_model'] ?? 'first_touch');

        if (!$instance_id) {
            wp_die(__('No instance selected.', 'formflow'));
        }

        // Generate data based on export type
        switch ($export_type) {
            case 'channel_performance':
                $data = $this->get_channel_performance_data($instance_id, $date_from, $date_to, $attribution_model);
                $filename = 'channel-performance';
                break;

            case 'campaigns':
                $data = $this->get_campaigns_data($instance_id, $date_from, $date_to, $attribution_model);
                $filename = 'campaign-performance';
                break;

            case 'handoffs':
                $data = $this->get_handoffs_data($instance_id, $date_from, $date_to);
                $filename = 'handoff-tracking';
                break;

            case 'full_report':
                $data = $this->get_full_report_data($instance_id, $date_from, $date_to, $attribution_model);
                $filename = 'attribution-full-report';
                break;

            default:
                wp_die(__('Invalid export type.', 'formflow'));
        }

        // Add date range to filename
        $filename .= '-' . $date_from . '-to-' . $date_to;

        // Output CSV
        $this->output_csv($data, $filename);
    }

    /**
     * Get channel performance data
     */
    private function get_channel_performance_data(int $instance_id, string $date_from, string $date_to, string $model): array {
        $performance = $this->calculator->get_channel_performance($instance_id, $date_from, $date_to, $model);

        $rows = [
            ['Channel', 'Medium', 'Unique Visitors', 'Conversions', 'Conversion Rate (%)'],
        ];

        foreach ($performance as $channel) {
            $rows[] = [
                $channel['channel'],
                $channel['medium'] ?: '(none)',
                $channel['unique_visitors'],
                number_format($channel['conversions'], 2),
                $channel['conversion_rate'],
            ];
        }

        return $rows;
    }

    /**
     * Get campaigns data
     */
    private function get_campaigns_data(int $instance_id, string $date_from, string $date_to, string $model): array {
        $attribution = $this->calculator->calculate_attribution($instance_id, $date_from, $date_to, $model);
        $by_campaign = $attribution['by_campaign'] ?? [];

        $rows = [
            ['Campaign', 'Attributed Conversions', 'Attribution Model'],
        ];

        foreach ($by_campaign as $campaign => $conversions) {
            $rows[] = [
                $campaign ?: '(none)',
                number_format($conversions, 2),
                ucwords(str_replace('_', ' ', $model)),
            ];
        }

        return $rows;
    }

    /**
     * Get handoffs data
     */
    private function get_handoffs_data(int $instance_id, string $date_from, string $date_to): array {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_handoffs';

        $handoffs = $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, v.first_touch
             FROM {$table} h
             LEFT JOIN {$wpdb->prefix}isf_visitors v ON h.visitor_id = v.visitor_id
             WHERE h.instance_id = %d
               AND h.created_at >= %s
               AND h.created_at <= %s
             ORDER BY h.created_at DESC",
            $instance_id,
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59'
        ), ARRAY_A);

        $rows = [
            ['Handoff Token', 'Status', 'Destination URL', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'Created At', 'Completed At', 'Account Number'],
        ];

        foreach ($handoffs as $handoff) {
            $attribution = json_decode($handoff['attribution'] ?? '{}', true);
            $rows[] = [
                $handoff['handoff_token'],
                ucfirst($handoff['status']),
                $handoff['destination_url'],
                $attribution['utm_source'] ?? '',
                $attribution['utm_medium'] ?? '',
                $attribution['utm_campaign'] ?? '',
                $handoff['created_at'],
                $handoff['completed_at'] ?? '',
                $handoff['account_number'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Get full report data (multiple sheets as sections)
     */
    private function get_full_report_data(int $instance_id, string $date_from, string $date_to, string $model): array {
        $attribution = $this->calculator->calculate_attribution($instance_id, $date_from, $date_to, $model);
        $channel_performance = $this->calculator->get_channel_performance($instance_id, $date_from, $date_to, $model);
        $time_to_conversion = $this->calculator->get_time_to_conversion($instance_id, $date_from, $date_to);
        $touchpoint_analysis = $this->calculator->get_touchpoint_analysis($instance_id, $date_from, $date_to);
        $handoff_stats = $this->handoff_tracker->get_handoff_stats($instance_id, $date_from, $date_to);

        $rows = [];

        // Report Header
        $rows[] = ['Attribution Report'];
        $rows[] = ['Generated', date('Y-m-d H:i:s')];
        $rows[] = ['Date Range', $date_from . ' to ' . $date_to];
        $rows[] = ['Attribution Model', ucwords(str_replace('_', ' ', $model))];
        $rows[] = [];

        // Summary Section
        $rows[] = ['=== SUMMARY ==='];
        $rows[] = ['Total Conversions', $attribution['total_conversions'] ?? 0];
        $rows[] = ['Traffic Sources', count($attribution['by_source'] ?? [])];
        $rows[] = ['Avg Time to Convert (hours)', number_format($time_to_conversion['average_hours'] ?? 0, 1)];
        $rows[] = ['Avg Touchpoints', number_format($touchpoint_analysis['average_touches'] ?? 0, 1)];
        $rows[] = [];

        // Channel Performance Section
        $rows[] = ['=== CHANNEL PERFORMANCE ==='];
        $rows[] = ['Channel', 'Medium', 'Unique Visitors', 'Conversions', 'Conversion Rate (%)'];
        foreach ($channel_performance as $channel) {
            $rows[] = [
                $channel['channel'],
                $channel['medium'] ?: '(none)',
                $channel['unique_visitors'],
                number_format($channel['conversions'], 2),
                $channel['conversion_rate'],
            ];
        }
        $rows[] = [];

        // Attribution by Source
        $rows[] = ['=== ATTRIBUTION BY SOURCE ==='];
        $rows[] = ['Source', 'Attributed Conversions'];
        foreach ($attribution['by_source'] ?? [] as $source => $value) {
            $rows[] = [$source, number_format($value, 2)];
        }
        $rows[] = [];

        // Attribution by Campaign
        $rows[] = ['=== ATTRIBUTION BY CAMPAIGN ==='];
        $rows[] = ['Campaign', 'Attributed Conversions'];
        foreach ($attribution['by_campaign'] ?? [] as $campaign => $value) {
            $rows[] = [$campaign ?: '(none)', number_format($value, 2)];
        }
        $rows[] = [];

        // Time to Conversion
        $rows[] = ['=== TIME TO CONVERSION ==='];
        $rows[] = ['Bucket', 'Count', 'Percentage'];
        $total_ttc = array_sum($time_to_conversion['buckets'] ?? []);
        $bucket_labels = [
            'same_session' => 'Same Session',
            'same_day' => 'Same Day',
            'within_week' => 'Within Week',
            'within_month' => 'Within Month',
            'over_month' => 'Over Month',
        ];
        foreach ($time_to_conversion['buckets'] ?? [] as $bucket => $count) {
            $percentage = $total_ttc > 0 ? round(($count / $total_ttc) * 100, 1) : 0;
            $rows[] = [$bucket_labels[$bucket] ?? $bucket, $count, $percentage . '%'];
        }
        $rows[] = [];

        // Touchpoint Distribution
        $rows[] = ['=== TOUCHPOINT DISTRIBUTION ==='];
        $rows[] = ['Touchpoints', 'Count', 'Percentage'];
        $total_tp = array_sum($touchpoint_analysis['buckets'] ?? []);
        foreach ($touchpoint_analysis['buckets'] ?? [] as $bucket => $count) {
            $percentage = $total_tp > 0 ? round(($count / $total_tp) * 100, 1) : 0;
            $rows[] = [$bucket . ' touches', $count, $percentage . '%'];
        }
        $rows[] = [];

        // Handoff Stats (if any)
        if (!empty($handoff_stats['total'])) {
            $rows[] = ['=== HANDOFF PERFORMANCE ==='];
            $rows[] = ['Total Handoffs', $handoff_stats['total']];
            $rows[] = ['Completed', $handoff_stats['by_status']['completed'] ?? 0];
            $rows[] = ['Completion Rate (%)', $handoff_stats['completion_rate']];
            $rows[] = ['Avg Time to Complete (hours)', number_format($handoff_stats['avg_completion_hours'] ?? 0, 1)];
            $rows[] = [];

            $rows[] = ['Status Breakdown'];
            foreach ($handoff_stats['by_status'] ?? [] as $status => $count) {
                $rows[] = [ucfirst($status), $count];
            }
        }

        return $rows;
    }

    /**
     * Output CSV file for download
     */
    private function output_csv(array $rows, string $filename): void {
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Create output stream
        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Write rows. Every cell is passed through sanitize_csv_cell() first:
        // anon-seeded UTM fields (utm_source/medium/campaign) reach this export
        // unfiltered, so a value leading with = + - @ TAB CR could execute as a
        // formula in Excel / Sheets. fputcsv() still handles quoting/escaping.
        foreach ($rows as $row) {
            fputcsv($output, array_map([self::class, 'sanitize_csv_cell'], $row));
        }

        fclose($output);
        exit;
    }

    /**
     * Neutralize spreadsheet formula/CSV-injection in a single cell.
     *
     * A cell whose first character is one of = + - @ or a TAB / CR is treated as
     * a formula by Excel / Google Sheets / LibreOffice. Prefixing a single quote
     * forces literal-text rendering. Used on export output only; it does not
     * alter stored data.
     *
     * @param mixed $value Raw cell value.
     * @return string Neutralized cell value.
     */
    private static function sanitize_csv_cell($value): string {
        $value = (string) ($value ?? '');
        if ($value === '') {
            return $value;
        }
        $first = $value[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Handle completions export request
     */
    public function handle_completions_export(): void {
        // Verify nonce
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'isf_export_completions')) {
            wp_die(__('Security check failed.', 'formflow'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to export data.', 'formflow'));
        }

        $export_type = sanitize_text_field($_GET['export_type'] ?? 'completions');
        $instance_id = (int) ($_GET['instance_id'] ?? 0);
        $date_from = sanitize_text_field($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
        $date_to = sanitize_text_field($_GET['date_to'] ?? date('Y-m-d'));

        switch ($export_type) {
            case 'completions':
                $data = $this->get_completions_export_data($instance_id, $date_from, $date_to);
                $filename = 'external-completions';
                break;

            case 'handoffs':
                $data = $this->get_handoffs_data($instance_id ?: 0, $date_from, $date_to);
                $filename = 'handoff-records';
                break;

            case 'unmatched':
                $data = $this->get_unmatched_completions_data($instance_id, $date_from, $date_to);
                $filename = 'unmatched-completions';
                break;

            default:
                wp_die(__('Invalid export type.', 'formflow'));
        }

        $filename .= '-' . $date_from . '-to-' . $date_to;
        $this->output_csv($data, $filename);
    }

    /**
     * Get external completions export data
     */
    private function get_completions_export_data(?int $instance_id, string $date_from, string $date_to): array {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_external_completions';
        $instances_table = $wpdb->prefix . 'isf_instances';

        $where = "WHERE ec.created_at >= %s AND ec.created_at <= %s";
        $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];

        if ($instance_id) {
            $where .= " AND ec.instance_id = %d";
            $params[] = $instance_id;
        }

        $query = $wpdb->prepare(
            "SELECT ec.*, i.name as instance_name
             FROM {$table} ec
             LEFT JOIN {$instances_table} i ON ec.instance_id = i.id
             {$where}
             ORDER BY ec.created_at DESC",
            ...$params
        );

        $completions = $wpdb->get_results($query, ARRAY_A);

        $rows = [
            ['ID', 'Instance', 'Source', 'Account Number', 'Email', 'External ID', 'Completion Type', 'Handoff Matched', 'Created At', 'Processed'],
        ];

        foreach ($completions as $c) {
            $rows[] = [
                $c['id'],
                $c['instance_name'] ?? 'Unknown',
                $c['source'],
                $c['account_number'] ?? '',
                $c['customer_email'] ?? '',
                $c['external_id'] ?? '',
                $c['completion_type'] ?? '',
                $c['handoff_id'] ? 'Yes' : 'No',
                $c['created_at'],
                $c['processed'] ? 'Yes' : 'No',
            ];
        }

        return $rows;
    }

    /**
     * Get unmatched completions export data
     */
    private function get_unmatched_completions_data(?int $instance_id, string $date_from, string $date_to): array {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_external_completions';
        $instances_table = $wpdb->prefix . 'isf_instances';

        $where = "WHERE ec.created_at >= %s AND ec.created_at <= %s AND ec.handoff_id IS NULL";
        $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];

        if ($instance_id) {
            $where .= " AND ec.instance_id = %d";
            $params[] = $instance_id;
        }

        $query = $wpdb->prepare(
            "SELECT ec.*, i.name as instance_name
             FROM {$table} ec
             LEFT JOIN {$instances_table} i ON ec.instance_id = i.id
             {$where}
             ORDER BY ec.created_at DESC",
            ...$params
        );

        $completions = $wpdb->get_results($query, ARRAY_A);

        $rows = [
            ['ID', 'Instance', 'Source', 'Account Number', 'Email', 'External ID', 'Completion Type', 'Created At', 'Raw Data'],
        ];

        foreach ($completions as $c) {
            $rows[] = [
                $c['id'],
                $c['instance_name'] ?? 'Unknown',
                $c['source'],
                $c['account_number'] ?? '',
                $c['customer_email'] ?? '',
                $c['external_id'] ?? '',
                $c['completion_type'] ?? '',
                $c['created_at'],
                $c['raw_data'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Export visitor journeys
     *
     * @param int    $instance_id Instance ID
     * @param string $date_from   Start date (Y-m-d)
     * @param string $date_to     End date (Y-m-d)
     * @param int    $limit       Maximum number of visitors to include
     * @return array CSV rows
     * @throws \Exception If user lacks permission
     */
    public function export_visitor_journeys(int $instance_id, string $date_from, string $date_to, int $limit = 100): array {
        // Security check - require manage_options capability
        if (!current_user_can('manage_options')) {
            throw new \Exception(__('You do not have permission to export data.', 'formflow'));
        }

        global $wpdb;

        $touches_table = $wpdb->prefix . 'isf_touches';
        $visitors_table = $wpdb->prefix . 'isf_visitors';

        // Get visitors who completed (had form_complete touch)
        $visitors = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT t.visitor_id, v.first_seen_at
             FROM {$touches_table} t
             JOIN {$visitors_table} v ON t.visitor_id = v.visitor_id
             WHERE t.instance_id = %d
               AND t.touch_type = 'form_complete'
               AND t.created_at >= %s
               AND t.created_at <= %s
             ORDER BY t.created_at DESC
             LIMIT %d",
            $instance_id,
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59',
            $limit
        ), ARRAY_A);

        $rows = [
            ['Visitor ID', 'First Seen', 'Journey Step', 'Touch Type', 'Source', 'Medium', 'Campaign', 'Page URL', 'Timestamp'],
        ];

        foreach ($visitors as $visitor) {
            // Get all touches for this visitor
            $touches = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$touches_table}
                 WHERE visitor_id = %s
                   AND instance_id = %d
                 ORDER BY created_at ASC",
                $visitor['visitor_id'],
                $instance_id
            ), ARRAY_A);

            $step = 1;
            foreach ($touches as $touch) {
                $rows[] = [
                    $visitor['visitor_id'],
                    $visitor['first_seen_at'],
                    $step,
                    $touch['touch_type'],
                    $touch['utm_source'] ?: '(direct)',
                    $touch['utm_medium'] ?: '(none)',
                    $touch['utm_campaign'] ?: '(none)',
                    $touch['page_url'] ?: '',
                    $touch['created_at'],
                ];
                $step++;
            }
        }

        return $rows;
    }
}
