<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Report Generator
 *
 * Generates and sends scheduled and custom reports.
 */


use ISF\Database\Database;

class ReportGenerator {

    private Database $db;

    /**
     * Constructor
     */
    public function __construct(Database $db) {
        $this->db = $db;
    }

    /**
     * Generate report data for a date range
     *
     * @param string $date_from Start date (Y-m-d)
     * @param string $date_to End date (Y-m-d)
     * @param int|null $instance_id Optional instance filter
     * @param array $sections Sections to include
     * @return array Report data
     */
    public function generate_report(string $date_from, string $date_to, ?int $instance_id = null, array $sections = []): array {
        $report = [
            'generated_at' => current_time('mysql'),
            'date_from' => $date_from,
            'date_to' => $date_to,
            'instance_id' => $instance_id,
            'instance_name' => null,
            'sections' => [],
        ];

        // Get instance name if filtered
        if ($instance_id) {
            $instance = $this->db->get_instance($instance_id);
            $report['instance_name'] = $instance ? $instance['name'] : 'Unknown';
        }

        // Default sections if none specified
        if (empty($sections)) {
            $sections = ['summary', 'submissions', 'analytics', 'attribution'];
        }

        // Summary section
        if (in_array('summary', $sections)) {
            $report['sections']['summary'] = $this->generate_summary($date_from, $date_to, $instance_id);
        }

        // Submissions section
        if (in_array('submissions', $sections)) {
            $report['sections']['submissions'] = $this->generate_submissions_summary($date_from, $date_to, $instance_id);
        }

        // Analytics section
        if (in_array('analytics', $sections)) {
            $report['sections']['analytics'] = $this->generate_analytics_summary($date_from, $date_to, $instance_id);
        }

        // Attribution section (new)
        if (in_array('attribution', $sections) && $instance_id) {
            $report['sections']['attribution'] = $this->generate_attribution_summary($date_from, $date_to, $instance_id);
        }

        // Handoffs section (new)
        if (in_array('handoffs', $sections) && $instance_id) {
            $report['sections']['handoffs'] = $this->generate_handoffs_summary($date_from, $date_to, $instance_id);
        }

        return $report;
    }

    /**
     * Generate summary statistics
     */
    private function generate_summary(string $date_from, string $date_to, ?int $instance_id): array {
        $filters = [
            'date_from' => $date_from . ' 00:00:00',
            'date_to' => $date_to . ' 23:59:59',
        ];

        if ($instance_id) {
            $filters['instance_id'] = $instance_id;
        }

        $total = $this->db->get_submission_count($filters);
        $completed = $this->db->get_submission_count(array_merge($filters, ['status' => 'completed']));
        $failed = $this->db->get_submission_count(array_merge($filters, ['status' => 'failed']));
        $abandoned = $this->db->get_submission_count(array_merge($filters, ['status' => 'abandoned']));
        $in_progress = $this->db->get_submission_count(array_merge($filters, ['status' => 'in_progress']));

        $completion_rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        return [
            'total_submissions' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'abandoned' => $abandoned,
            'in_progress' => $in_progress,
            'completion_rate' => $completion_rate,
        ];
    }

    /**
     * Generate submissions breakdown by device type
     */
    private function generate_submissions_summary(string $date_from, string $date_to, ?int $instance_id): array {
        $filters = [
            'date_from' => $date_from . ' 00:00:00',
            'date_to' => $date_to . ' 23:59:59',
            'status' => 'completed',
        ];

        if ($instance_id) {
            $filters['instance_id'] = $instance_id;
        }

        $submissions = $this->db->get_submissions($filters, 1000, 0);

        $by_device = [
            'thermostat' => 0,
            'dcu' => 0,
            'other' => 0,
        ];

        $by_day = [];

        // WiFi eligibility gate. `asked` deliberately counts only non-NULL
        // answers: NULL means the question was never put to this customer
        // (they chose the switch outright, or the instance has no gate), and
        // folding those in would make the gate look like it ran everywhere.
        $wifi = [
            'asked'     => 0,
            'no_wifi'   => 0,
            'converted' => 0,
        ];

        foreach ($submissions as $sub) {
            // Count by device type
            $device = $sub['device_type'] ?? 'other';
            if (isset($by_device[$device])) {
                $by_device[$device]++;
            } else {
                $by_device['other']++;
            }

            $has_wifi = $sub['has_wifi'] ?? null;
            if ($has_wifi !== null && $has_wifi !== '') {
                $wifi['asked']++;

                if ($has_wifi === 'no') {
                    $wifi['no_wifi']++;
                }
            }

            // Only a gate-driven conversion counts. Someone who picked the
            // switch from the start is not a customer the gate saved.
            if (!empty($sub['device_converted'])) {
                $wifi['converted']++;
            }

            // Count by day
            $day = substr($sub['completed_at'] ?? $sub['created_at'], 0, 10);
            if (!isset($by_day[$day])) {
                $by_day[$day] = 0;
            }
            $by_day[$day]++;
        }

        ksort($by_day);

        return [
            'by_device' => $by_device,
            'by_day' => $by_day,
            'wifi' => $wifi,
            'total_completed' => count($submissions),
        ];
    }

    /**
     * Generate analytics summary (funnel, timing)
     */
    private function generate_analytics_summary(string $date_from, string $date_to, ?int $instance_id): array {
        $funnel = $this->db->get_funnel_analytics($instance_id, $date_from, $date_to, true);
        $timing = $this->db->get_step_timing_analytics($instance_id, $date_from, $date_to, true);
        $devices = $this->db->get_device_analytics($instance_id, $date_from, $date_to, true);

        return [
            'funnel' => $funnel,
            'timing' => $timing,
            'devices' => $devices,
        ];
    }

    /**
     * Generate attribution summary (marketing channels, campaigns)
     */
    private function generate_attribution_summary(string $date_from, string $date_to, int $instance_id): array {
        $calculator = new Analytics\AttributionCalculator();

        $attribution = $calculator->calculate_attribution($instance_id, $date_from, $date_to, 'first_touch');
        $channel_performance = $calculator->get_channel_performance($instance_id, $date_from, $date_to, 'first_touch');
        $time_to_conversion = $calculator->get_time_to_conversion($instance_id, $date_from, $date_to);
        $touchpoint_analysis = $calculator->get_touchpoint_analysis($instance_id, $date_from, $date_to);

        // Get top 5 campaigns
        $top_campaigns = [];
        if (!empty($attribution['by_campaign'])) {
            arsort($attribution['by_campaign']);
            $top_campaigns = array_slice($attribution['by_campaign'], 0, 5, true);
        }

        // Get top 5 channels
        $top_channels = array_slice($channel_performance, 0, 5);

        return [
            'total_conversions' => $attribution['total_conversions'] ?? 0,
            'total_sources' => count($attribution['by_source'] ?? []),
            'avg_time_to_convert_hours' => $time_to_conversion['average_hours'] ?? 0,
            'avg_touchpoints' => $touchpoint_analysis['average_touches'] ?? 0,
            'top_channels' => $top_channels,
            'top_campaigns' => $top_campaigns,
            'by_source' => $attribution['by_source'] ?? [],
        ];
    }

    /**
     * Generate handoffs summary
     */
    private function generate_handoffs_summary(string $date_from, string $date_to, int $instance_id): array {
        $tracker = new Analytics\HandoffTracker();
        $stats = $tracker->get_handoff_stats($instance_id, $date_from, $date_to);

        return [
            'total' => $stats['total'] ?? 0,
            'completed' => $stats['by_status']['completed'] ?? 0,
            'pending' => $stats['by_status']['redirected'] ?? 0,
            'expired' => $stats['by_status']['expired'] ?? 0,
            'completion_rate' => $stats['completion_rate'] ?? 0,
            'avg_completion_hours' => $stats['avg_completion_hours'] ?? 0,
        ];
    }

    /**
     * Send a scheduled report via email
     *
     * @param array $report The scheduled report configuration
     * @return bool Success
     */
    public function send_scheduled_report(array $report): bool {
        // Calculate date range based on frequency
        $date_to = date('Y-m-d', strtotime('-1 day'));

        switch ($report['frequency']) {
            case 'daily':
                $date_from = $date_to;
                $period_label = 'Daily';
                break;
            case 'weekly':
                $date_from = date('Y-m-d', strtotime('-7 days'));
                $period_label = 'Weekly';
                break;
            case 'monthly':
                $date_from = date('Y-m-d', strtotime('-30 days'));
                $period_label = 'Monthly';
                break;
            default:
                $date_from = date('Y-m-d', strtotime('-7 days'));
                $period_label = 'Weekly';
        }

        // Generate the report data
        $instance_id = $report['instance_id'] ?: null;
        $report_data = $this->generate_report($date_from, $date_to, $instance_id);

        // Get recipients
        $recipients = $report['recipients'];
        if (is_string($recipients)) {
            $recipients = json_decode($recipients, true) ?: [$recipients];
        }

        // Generate HTML email
        $html = $this->to_html($report_data, true);

        // Build subject
        $subject = sprintf(
            '[%s] %s Report - %s',
            get_bloginfo('name'),
            $period_label,
            $report['name']
        );

        // Send to all recipients
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        $success = true;
        foreach ($recipients as $email) {
            if (!wp_mail($email, $subject, $html, $headers)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Send a report via email
     *
     * @param array $report_data The generated report data
     * @param array $recipients Email addresses
     * @return bool Success
     */
    public function send_report_email(array $report_data, array $recipients): bool {
        $html = $this->to_html($report_data, true);

        $subject = sprintf(
            '[%s] Custom Report - %s to %s',
            get_bloginfo('name'),
            $report_data['date_from'],
            $report_data['date_to']
        );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        $success = true;
        foreach ($recipients as $email) {
            if (!wp_mail($email, $subject, $html, $headers)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Convert report data to HTML
     *
     * @param array $report_data The report data
     * @param bool $for_email Format for email
     * @return string HTML content
     */
    public function to_html(array $report_data, bool $for_email = false): string {
        $html = '';

        // Email wrapper styles
        if ($for_email) {
            $html .= '<div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px;">';
        }

        // Header
        $html .= '<h1 style="color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">FormFlow Report</h1>';

        // Date range
        $html .= '<p style="color: #666; margin-bottom: 20px;">';
        $html .= '<strong>Period:</strong> ' . esc_html($report_data['date_from']) . ' to ' . esc_html($report_data['date_to']);
        if ($report_data['instance_name']) {
            $html .= '<br><strong>Form:</strong> ' . esc_html($report_data['instance_name']);
        }
        $html .= '<br><strong>Generated:</strong> ' . esc_html($report_data['generated_at']);
        $html .= '</p>';

        // Summary section
        if (isset($report_data['sections']['summary'])) {
            $summary = $report_data['sections']['summary'];
            $html .= '<h2 style="color: #0073aa; margin-top: 30px;">Summary</h2>';
            $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
            $html .= '<tr>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 32px; font-weight: bold; color: #333;">' . number_format($summary['total_submissions']) . '</div>';
            $html .= '<div style="color: #666;">Total Submissions</div>';
            $html .= '</td>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 32px; font-weight: bold; color: #46b450;">' . number_format($summary['completed']) . '</div>';
            $html .= '<div style="color: #666;">Completed</div>';
            $html .= '</td>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 32px; font-weight: bold; color: #dc3232;">' . number_format($summary['failed']) . '</div>';
            $html .= '<div style="color: #666;">Failed</div>';
            $html .= '</td>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 32px; font-weight: bold; color: #0073aa;">' . $summary['completion_rate'] . '%</div>';
            $html .= '<div style="color: #666;">Completion Rate</div>';
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';
        }

        // Submissions section
        if (isset($report_data['sections']['submissions'])) {
            $submissions = $report_data['sections']['submissions'];
            $html .= '<h2 style="color: #0073aa; margin-top: 30px;">Completed Enrollments by Device</h2>';
            $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
            $html .= '<thead><tr style="background: #0073aa; color: white;">';
            $html .= '<th style="padding: 12px; text-align: left;">Device Type</th>';
            $html .= '<th style="padding: 12px; text-align: right;">Count</th>';
            $html .= '</tr></thead>';
            $html .= '<tbody>';

            foreach ($submissions['by_device'] as $device => $count) {
                if ($count > 0) {
                    $html .= '<tr>';
                    $html .= '<td style="padding: 12px; border-bottom: 1px solid #ddd;">' . esc_html(ucfirst($device)) . '</td>';
                    $html .= '<td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right;">' . number_format($count) . '</td>';
                    $html .= '</tr>';
                }
            }

            $html .= '</tbody>';
            $html .= '</table>';

            // Daily breakdown
            if (!empty($submissions['by_day'])) {
                $html .= '<h3 style="color: #333; margin-top: 20px;">Daily Breakdown</h3>';
                $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
                $html .= '<thead><tr style="background: #f5f5f5;">';
                $html .= '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Date</th>';
                $html .= '<th style="padding: 10px; text-align: right; border: 1px solid #ddd;">Completed</th>';
                $html .= '</tr></thead>';
                $html .= '<tbody>';

                foreach ($submissions['by_day'] as $day => $count) {
                    $html .= '<tr>';
                    $html .= '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html($day) . '</td>';
                    $html .= '<td style="padding: 10px; border: 1px solid #ddd; text-align: right;">' . number_format($count) . '</td>';
                    $html .= '</tr>';
                }

                $html .= '</tbody>';
                $html .= '</table>';
            }
        }

        // Analytics section - Funnel
        if (isset($report_data['sections']['analytics']['funnel']) && !empty($report_data['sections']['analytics']['funnel'])) {
            $funnel = $report_data['sections']['analytics']['funnel'];
            $html .= '<h2 style="color: #0073aa; margin-top: 30px;">Form Funnel</h2>';
            $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
            $html .= '<thead><tr style="background: #0073aa; color: white;">';
            $html .= '<th style="padding: 12px; text-align: left;">Step</th>';
            $html .= '<th style="padding: 12px; text-align: right;">Started</th>';
            $html .= '<th style="padding: 12px; text-align: right;">Completed</th>';
            $html .= '<th style="padding: 12px; text-align: right;">Drop-off Rate</th>';
            $html .= '</tr></thead>';
            $html .= '<tbody>';

            foreach ($funnel as $step) {
                $dropoff = $step['entries'] > 0 ? round((1 - ($step['completions'] / $step['entries'])) * 100, 1) : 0;
                $html .= '<tr>';
                $html .= '<td style="padding: 12px; border-bottom: 1px solid #ddd;">Step ' . esc_html($step['step']) . '</td>';
                $html .= '<td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right;">' . number_format($step['entries']) . '</td>';
                $html .= '<td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right;">' . number_format($step['completions']) . '</td>';
                $html .= '<td style="padding: 12px; border-bottom: 1px solid #ddd; text-align: right;">' . $dropoff . '%</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody>';
            $html .= '</table>';
        }

        // Attribution section
        if (isset($report_data['sections']['attribution'])) {
            $attribution = $report_data['sections']['attribution'];
            $html .= '<h2 style="color: #0073aa; margin-top: 30px;">Marketing Attribution</h2>';

            // Attribution summary stats
            $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
            $html .= '<tr>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 24px; font-weight: bold; color: #333;">' . number_format($attribution['total_conversions']) . '</div>';
            $html .= '<div style="color: #666;">Conversions</div>';
            $html .= '</td>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 24px; font-weight: bold; color: #333;">' . number_format($attribution['total_sources']) . '</div>';
            $html .= '<div style="color: #666;">Traffic Sources</div>';
            $html .= '</td>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 24px; font-weight: bold; color: #333;">' . number_format($attribution['avg_time_to_convert_hours'], 1) . 'h</div>';
            $html .= '<div style="color: #666;">Avg. Time to Convert</div>';
            $html .= '</td>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 24px; font-weight: bold; color: #333;">' . number_format($attribution['avg_touchpoints'], 1) . '</div>';
            $html .= '<div style="color: #666;">Avg. Touchpoints</div>';
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';

            // Top channels
            if (!empty($attribution['top_channels'])) {
                $html .= '<h3 style="color: #333; margin-top: 20px;">Top Channels</h3>';
                $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
                $html .= '<thead><tr style="background: #0073aa; color: white;">';
                $html .= '<th style="padding: 10px; text-align: left;">Channel</th>';
                $html .= '<th style="padding: 10px; text-align: left;">Medium</th>';
                $html .= '<th style="padding: 10px; text-align: right;">Visitors</th>';
                $html .= '<th style="padding: 10px; text-align: right;">Conversions</th>';
                $html .= '<th style="padding: 10px; text-align: right;">Conv. Rate</th>';
                $html .= '</tr></thead>';
                $html .= '<tbody>';
                foreach ($attribution['top_channels'] as $channel) {
                    $html .= '<tr>';
                    $html .= '<td style="padding: 10px; border-bottom: 1px solid #ddd;">' . esc_html($channel['channel']) . '</td>';
                    $html .= '<td style="padding: 10px; border-bottom: 1px solid #ddd;">' . esc_html($channel['medium'] ?: '-') . '</td>';
                    $html .= '<td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right;">' . number_format($channel['unique_visitors']) . '</td>';
                    $html .= '<td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right;">' . number_format($channel['conversions'], 1) . '</td>';
                    $html .= '<td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right;">' . $channel['conversion_rate'] . '%</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }

            // Top campaigns
            if (!empty($attribution['top_campaigns'])) {
                $html .= '<h3 style="color: #333; margin-top: 20px;">Top Campaigns</h3>';
                $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
                $html .= '<thead><tr style="background: #f5f5f5;">';
                $html .= '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Campaign</th>';
                $html .= '<th style="padding: 10px; text-align: right; border: 1px solid #ddd;">Attributed Conversions</th>';
                $html .= '</tr></thead>';
                $html .= '<tbody>';
                foreach ($attribution['top_campaigns'] as $campaign => $value) {
                    $html .= '<tr>';
                    $html .= '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html($campaign ?: '(none)') . '</td>';
                    $html .= '<td style="padding: 10px; border: 1px solid #ddd; text-align: right;">' . number_format($value, 2) . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }
        }

        // Handoffs section
        if (isset($report_data['sections']['handoffs']) && $report_data['sections']['handoffs']['total'] > 0) {
            $handoffs = $report_data['sections']['handoffs'];
            $html .= '<h2 style="color: #0073aa; margin-top: 30px;">External Handoffs</h2>';
            $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
            $html .= '<tr>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 24px; font-weight: bold; color: #333;">' . number_format($handoffs['total']) . '</div>';
            $html .= '<div style="color: #666;">Total Handoffs</div>';
            $html .= '</td>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 24px; font-weight: bold; color: #46b450;">' . number_format($handoffs['completed']) . '</div>';
            $html .= '<div style="color: #666;">Completed</div>';
            $html .= '</td>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 24px; font-weight: bold; color: #0073aa;">' . $handoffs['completion_rate'] . '%</div>';
            $html .= '<div style="color: #666;">Completion Rate</div>';
            $html .= '</td>';
            $html .= '<td style="padding: 15px; background: #f5f5f5; text-align: center; border: 1px solid #ddd;">';
            $html .= '<div style="font-size: 24px; font-weight: bold; color: #333;">' . number_format($handoffs['avg_completion_hours'], 1) . 'h</div>';
            $html .= '<div style="color: #666;">Avg. Time to Complete</div>';
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';
        }

        // Footer
        $html .= '<hr style="margin-top: 30px; border: none; border-top: 1px solid #ddd;">';
        $html .= '<p style="color: #999; font-size: 12px; text-align: center;">';
        $html .= 'This report was automatically generated by FormFlow.<br>';
        $html .= '<a href="' . admin_url('admin.php?page=isf-reports') . '" style="color: #0073aa;">Manage Reports</a>';
        $html .= '</p>';

        if ($for_email) {
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Convert report data to CSV
     *
     * @param array $report_data The report data
     * @return string CSV content
     */
    public function to_csv(array $report_data): string {
        $lines = [];

        // Header info
        $lines[] = ['FormFlow Report'];
        $lines[] = ['Period', $report_data['date_from'] . ' to ' . $report_data['date_to']];
        if ($report_data['instance_name']) {
            $lines[] = ['Form', $report_data['instance_name']];
        }
        $lines[] = ['Generated', $report_data['generated_at']];
        $lines[] = [];

        // Summary section
        if (isset($report_data['sections']['summary'])) {
            $summary = $report_data['sections']['summary'];
            $lines[] = ['SUMMARY'];
            $lines[] = ['Metric', 'Value'];
            $lines[] = ['Total Submissions', $summary['total_submissions']];
            $lines[] = ['Completed', $summary['completed']];
            $lines[] = ['Failed', $summary['failed']];
            $lines[] = ['Abandoned', $summary['abandoned']];
            $lines[] = ['In Progress', $summary['in_progress']];
            $lines[] = ['Completion Rate', $summary['completion_rate'] . '%'];
            $lines[] = [];
        }

        // Submissions section
        if (isset($report_data['sections']['submissions'])) {
            $submissions = $report_data['sections']['submissions'];
            $lines[] = ['ENROLLMENTS BY DEVICE'];
            $lines[] = ['Device Type', 'Count'];
            foreach ($submissions['by_device'] as $device => $count) {
                $lines[] = [ucfirst($device), $count];
            }
            $lines[] = [];

            if (!empty($submissions['by_day'])) {
                $lines[] = ['DAILY BREAKDOWN'];
                $lines[] = ['Date', 'Completed'];
                foreach ($submissions['by_day'] as $day => $count) {
                    $lines[] = [$day, $count];
                }
                $lines[] = [];
            }
        }

        // Funnel section
        if (isset($report_data['sections']['analytics']['funnel']) && !empty($report_data['sections']['analytics']['funnel'])) {
            $lines[] = ['FORM FUNNEL'];
            $lines[] = ['Step', 'Started', 'Completed', 'Drop-off Rate'];
            foreach ($report_data['sections']['analytics']['funnel'] as $step) {
                $dropoff = $step['entries'] > 0 ? round((1 - ($step['completions'] / $step['entries'])) * 100, 1) : 0;
                $lines[] = ['Step ' . $step['step'], $step['entries'], $step['completions'], $dropoff . '%'];
            }
            $lines[] = [];
        }

        // Attribution section
        if (isset($report_data['sections']['attribution'])) {
            $attribution = $report_data['sections']['attribution'];
            $lines[] = ['MARKETING ATTRIBUTION'];
            $lines[] = ['Metric', 'Value'];
            $lines[] = ['Total Conversions', $attribution['total_conversions']];
            $lines[] = ['Traffic Sources', $attribution['total_sources']];
            $lines[] = ['Avg. Time to Convert (hours)', number_format($attribution['avg_time_to_convert_hours'], 1)];
            $lines[] = ['Avg. Touchpoints', number_format($attribution['avg_touchpoints'], 1)];
            $lines[] = [];

            // Top channels
            if (!empty($attribution['top_channels'])) {
                $lines[] = ['TOP CHANNELS'];
                $lines[] = ['Channel', 'Medium', 'Visitors', 'Conversions', 'Conv. Rate'];
                foreach ($attribution['top_channels'] as $channel) {
                    $lines[] = [
                        $channel['channel'],
                        $channel['medium'] ?: '(none)',
                        $channel['unique_visitors'],
                        number_format($channel['conversions'], 1),
                        $channel['conversion_rate'] . '%'
                    ];
                }
                $lines[] = [];
            }

            // Top campaigns
            if (!empty($attribution['top_campaigns'])) {
                $lines[] = ['TOP CAMPAIGNS'];
                $lines[] = ['Campaign', 'Attributed Conversions'];
                foreach ($attribution['top_campaigns'] as $campaign => $value) {
                    $lines[] = [$campaign ?: '(none)', number_format($value, 2)];
                }
                $lines[] = [];
            }

            // By source
            if (!empty($attribution['by_source'])) {
                $lines[] = ['ATTRIBUTION BY SOURCE'];
                $lines[] = ['Source', 'Attributed Conversions'];
                foreach ($attribution['by_source'] as $source => $value) {
                    $lines[] = [$source, number_format($value, 2)];
                }
                $lines[] = [];
            }
        }

        // Handoffs section
        if (isset($report_data['sections']['handoffs']) && $report_data['sections']['handoffs']['total'] > 0) {
            $handoffs = $report_data['sections']['handoffs'];
            $lines[] = ['EXTERNAL HANDOFFS'];
            $lines[] = ['Metric', 'Value'];
            $lines[] = ['Total Handoffs', $handoffs['total']];
            $lines[] = ['Completed', $handoffs['completed']];
            $lines[] = ['Pending', $handoffs['pending']];
            $lines[] = ['Expired', $handoffs['expired']];
            $lines[] = ['Completion Rate', $handoffs['completion_rate'] . '%'];
            $lines[] = ['Avg. Time to Complete (hours)', number_format($handoffs['avg_completion_hours'], 1)];
            $lines[] = [];
        }

        // Convert to CSV string
        $csv = '';
        foreach ($lines as $line) {
            $escaped = array_map(function($field) {
                $field = (string)$field;
                $field = str_replace('"', '""', $field);
                if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                    return '"' . $field . '"';
                }
                return $field;
            }, $line);
            $csv .= implode(',', $escaped) . "\n";
        }

        return $csv;
    }
}
