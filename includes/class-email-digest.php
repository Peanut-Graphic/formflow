<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Email Digest Handler
 *
 * Sends daily/weekly enrollment summary emails to administrators.
 * Feature-togglable per instance via FeatureManager.
 */


class EmailDigest {

    /**
     * Cron hook name
     */
    public const CRON_HOOK = 'isf_send_email_digest';

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
     * Schedule digest emails for all instances
     */
    public function schedule_digests(): void {
        // Get all active instances
        $instances = $this->db->get_instances(true);

        foreach ($instances as $instance) {
            if (!FeatureManager::is_enabled($instance, 'email_digest')) {
                continue;
            }

            $config = FeatureManager::get_feature($instance, 'email_digest');

            // Schedule based on frequency and time
            $this->schedule_for_instance($instance, $config);
        }
    }

    /**
     * Schedule digest for a specific instance
     */
    private function schedule_for_instance(array $instance, array $config): void {
        $frequency = $config['frequency'] ?? 'daily';
        $send_time = $config['send_time'] ?? '08:00';

        // Parse send time
        $time_parts = explode(':', $send_time);
        $hour = (int)($time_parts[0] ?? 8);
        $minute = (int)($time_parts[1] ?? 0);

        // Calculate next scheduled time
        $now = current_time('timestamp');
        $today = strtotime(sprintf('today %02d:%02d', $hour, $minute), $now);

        if ($frequency === 'weekly') {
            // Send on Mondays
            $next = strtotime('next monday ' . $send_time, $now);
            if ($next <= $now) {
                $next = strtotime('next monday ' . $send_time, $next);
            }
        } else {
            // Daily
            $next = $today > $now ? $today : strtotime('+1 day', $today);
        }

        // Schedule the event
        $hook = self::CRON_HOOK . '_' . $instance['id'];

        if (!wp_next_scheduled($hook)) {
            wp_schedule_single_event($next, $hook, [$instance['id']]);
        }
    }

    /**
     * Send digest email for an instance
     *
     * @param int $instance_id The instance ID
     * @return bool Success status
     */
    public function send_digest(int $instance_id): bool {
        $instance = $this->db->get_instance($instance_id);

        if (!$instance) {
            return false;
        }

        if (!FeatureManager::is_enabled($instance, 'email_digest')) {
            return false;
        }

        $config = FeatureManager::get_feature($instance, 'email_digest');
        $recipients = $config['recipients'] ?? '';

        if (empty($recipients)) {
            return false;
        }

        // Calculate date range
        $frequency = $config['frequency'] ?? 'daily';
        if ($frequency === 'weekly') {
            $start_date = date('Y-m-d', strtotime('-7 days'));
            $period_label = 'Weekly';
        } else {
            $start_date = date('Y-m-d', strtotime('-1 day'));
            $period_label = 'Daily';
        }
        $end_date = date('Y-m-d');

        // Get statistics
        $stats = $this->get_period_stats($instance_id, $start_date, $end_date);

        // Get comparison stats if enabled
        $comparison = null;
        if (!empty($config['include_comparison'])) {
            if ($frequency === 'weekly') {
                $prev_start = date('Y-m-d', strtotime('-14 days'));
                $prev_end = date('Y-m-d', strtotime('-7 days'));
            } else {
                $prev_start = date('Y-m-d', strtotime('-2 days'));
                $prev_end = date('Y-m-d', strtotime('-1 day'));
            }
            $comparison = $this->get_period_stats($instance_id, $prev_start, $prev_end);
        }

        // Build and send email
        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'Energy Wise Rewards';

        $subject = sprintf(
            '%s Digest - %s - %s',
            $period_label,
            $program_name,
            date('M j, Y')
        );

        $html = $this->build_digest_html($instance, $stats, $comparison, $period_label);

        $recipient_list = array_map('trim', explode(',', $recipients));
        $valid_recipients = array_filter($recipient_list, 'is_email');

        if (empty($valid_recipients)) {
            $this->log('warning', 'No valid digest recipients', [], $instance_id);
            return false;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        $from = $instance['support_email_from'] ?? '';
        if ($from) {
            $headers[] = 'From: ' . $from;
        }

        $sent = wp_mail($valid_recipients, $subject, $html, $headers);

        if ($sent) {
            $this->log('info', 'Digest email sent', [
                'recipients' => count($valid_recipients),
                'period' => $frequency,
            ], $instance_id);
        } else {
            $this->log('error', 'Failed to send digest email', [], $instance_id);
        }

        // Reschedule for next period
        $this->schedule_for_instance($instance, $config);

        return $sent;
    }

    /**
     * Get statistics for a date range
     */
    private function get_period_stats(int $instance_id, string $start_date, string $end_date): array {
        global $wpdb;
        $submissions_table = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;
        $analytics_table = $wpdb->prefix . ISF_TABLE_ANALYTICS;

        // Total submissions
        $total = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$submissions_table}
             WHERE instance_id = %d
             AND created_at >= %s
             AND created_at < %s",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // Completed
        $completed = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$submissions_table}
             WHERE instance_id = %d
             AND status = 'completed'
             AND created_at >= %s
             AND created_at < %s",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // Failed
        $failed = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$submissions_table}
             WHERE instance_id = %d
             AND status = 'failed'
             AND created_at >= %s
             AND created_at < %s",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // Abandoned
        $abandoned = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$submissions_table}
             WHERE instance_id = %d
             AND status = 'abandoned'
             AND created_at >= %s
             AND created_at < %s",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // Device breakdown
        $devices = $wpdb->get_results($wpdb->prepare(
            "SELECT device_type, COUNT(*) as count FROM {$submissions_table}
             WHERE instance_id = %d
             AND status = 'completed'
             AND created_at >= %s
             AND created_at < %s
             GROUP BY device_type",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        // Top drop-off steps
        $dropoff = $wpdb->get_results($wpdb->prepare(
            "SELECT step, COUNT(*) as count FROM {$submissions_table}
             WHERE instance_id = %d
             AND status = 'abandoned'
             AND created_at >= %s
             AND created_at < %s
             GROUP BY step
             ORDER BY count DESC
             LIMIT 3",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ), ARRAY_A);

        // Conversion rate
        $started = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM {$analytics_table}
             WHERE instance_id = %d
             AND step = 1
             AND action = 'enter'
             AND created_at >= %s
             AND created_at < %s",
            $instance_id,
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        $conversion_rate = $started > 0 ? round(($completed / $started) * 100, 1) : 0;

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'abandoned' => $abandoned,
            'started' => $started,
            'conversion_rate' => $conversion_rate,
            'devices' => $devices,
            'dropoff' => $dropoff,
        ];
    }

    /**
     * Build digest HTML email
     */
    private function build_digest_html(array $instance, array $stats, ?array $comparison, string $period_label): string {
        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'Energy Wise Rewards';

        // Calculate change indicators
        $completed_change = '';
        $conversion_change = '';
        if ($comparison) {
            if ($comparison['completed'] > 0) {
                $change = round((($stats['completed'] - $comparison['completed']) / $comparison['completed']) * 100);
                $completed_change = $change >= 0 ? "+{$change}%" : "{$change}%";
            }
            if ($comparison['conversion_rate'] > 0) {
                $change = round($stats['conversion_rate'] - $comparison['conversion_rate'], 1);
                $conversion_change = $change >= 0 ? "+{$change}%" : "{$change}%";
            }
        }

        // Device breakdown
        $device_html = '';
        foreach ($stats['devices'] as $device) {
            $type = $device['device_type'] === 'thermostat' ? 'Thermostats' : 'Outdoor Switches';
            $device_html .= "<tr><td style=\"padding:8px;border-bottom:1px solid #eee;\">{$type}</td><td style=\"padding:8px;border-bottom:1px solid #eee;text-align:right;\">{$device['count']}</td></tr>";
        }

        // Drop-off points
        $dropoff_html = '';
        $step_names = [
            1 => 'Program Selection',
            2 => 'Account Validation',
            3 => 'Customer Information',
            4 => 'Scheduling',
            5 => 'Confirmation',
        ];
        foreach ($stats['dropoff'] as $drop) {
            $step_name = $step_names[$drop['step']] ?? "Step {$drop['step']}";
            $dropoff_html .= "<tr><td style=\"padding:8px;border-bottom:1px solid #eee;\">{$step_name}</td><td style=\"padding:8px;border-bottom:1px solid #eee;text-align:right;\">{$drop['count']}</td></tr>";
        }

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="padding:20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background:#0073aa;padding:30px;text-align:center;">
                            <h1 style="margin:0;color:#fff;font-size:24px;">' . esc_html($period_label) . ' Enrollment Digest</h1>
                            <p style="margin:10px 0 0;color:rgba(255,255,255,0.8);font-size:14px;">' . esc_html($program_name) . ' - ' . esc_html($instance['name']) . '</p>
                        </td>
                    </tr>

                    <!-- Summary Stats -->
                    <tr>
                        <td style="padding:30px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="text-align:center;padding:15px;background:#e8f5e9;border-radius:8px;">
                                        <div style="font-size:36px;font-weight:bold;color:#28a745;">' . $stats['completed'] . '</div>
                                        <div style="font-size:14px;color:#666;margin-top:5px;">Completed Enrollments</div>
                                        ' . ($completed_change ? '<div style="font-size:12px;color:#28a745;margin-top:5px;">' . $completed_change . ' vs previous</div>' : '') . '
                                    </td>
                                    <td width="20"></td>
                                    <td style="text-align:center;padding:15px;background:#e3f2fd;border-radius:8px;">
                                        <div style="font-size:36px;font-weight:bold;color:#0073aa;">' . $stats['conversion_rate'] . '%</div>
                                        <div style="font-size:14px;color:#666;margin-top:5px;">Conversion Rate</div>
                                        ' . ($conversion_change ? '<div style="font-size:12px;color:#0073aa;margin-top:5px;">' . $conversion_change . '</div>' : '') . '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Status Breakdown -->
                    <tr>
                        <td style="padding:0 30px 30px;">
                            <h3 style="margin:0 0 15px;font-size:16px;color:#333;">Status Breakdown</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eee;border-radius:4px;">
                                <tr style="background:#f8f9fa;">
                                    <td style="padding:10px;font-weight:bold;">Status</td>
                                    <td style="padding:10px;text-align:right;font-weight:bold;">Count</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px;border-bottom:1px solid #eee;">Started</td>
                                    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . $stats['started'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px;border-bottom:1px solid #eee;">Completed</td>
                                    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;color:#28a745;font-weight:bold;">' . $stats['completed'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px;border-bottom:1px solid #eee;">Failed</td>
                                    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;color:#dc3545;">' . $stats['failed'] . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px;">Abandoned</td>
                                    <td style="padding:8px;text-align:right;color:#ffc107;">' . $stats['abandoned'] . '</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    ' . ($device_html ? '
                    <!-- Device Breakdown -->
                    <tr>
                        <td style="padding:0 30px 30px;">
                            <h3 style="margin:0 0 15px;font-size:16px;color:#333;">Device Breakdown</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eee;border-radius:4px;">
                                <tr style="background:#f8f9fa;">
                                    <td style="padding:10px;font-weight:bold;">Device Type</td>
                                    <td style="padding:10px;text-align:right;font-weight:bold;">Count</td>
                                </tr>
                                ' . $device_html . '
                            </table>
                        </td>
                    </tr>
                    ' : '') . '

                    ' . ($dropoff_html ? '
                    <!-- Drop-off Points -->
                    <tr>
                        <td style="padding:0 30px 30px;">
                            <h3 style="margin:0 0 15px;font-size:16px;color:#333;">Top Drop-off Points</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eee;border-radius:4px;">
                                <tr style="background:#f8f9fa;">
                                    <td style="padding:10px;font-weight:bold;">Step</td>
                                    <td style="padding:10px;text-align:right;font-weight:bold;">Abandons</td>
                                </tr>
                                ' . $dropoff_html . '
                            </table>
                        </td>
                    </tr>
                    ' : '') . '

                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8f9fa;padding:20px 30px;text-align:center;border-top:1px solid #eee;">
                            <p style="margin:0;font-size:12px;color:#666;">
                                This is an automated digest from FormFlow.<br>
                                <a href="' . admin_url('admin.php?page=intellisource-forms') . '" style="color:#0073aa;">View Dashboard</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Log a message
     */
    private function log(string $type, string $message, array $details = [], ?int $instance_id = null): void {
        $this->db->log($type, '[Digest] ' . $message, $details, $instance_id);
    }

    /**
     * Register cron hooks for all instances
     */
    public static function register_cron_hooks(): void {
        $db = new Database\Database();
        $instances = $db->get_instances(true);

        foreach ($instances as $instance) {
            $hook = self::CRON_HOOK . '_' . $instance['id'];
            add_action($hook, function($instance_id) {
                $digest = new self();
                $digest->send_digest($instance_id);
            });
        }
    }

    /**
     * Send test digest email
     *
     * @param int $instance_id The instance ID
     * @param string $recipient Email address to send to
     * @return array Result with success status and message
     */
    public static function send_test(int $instance_id, string $recipient): array {
        if (!is_email($recipient)) {
            return [
                'success' => false,
                'message' => __('Please enter a valid email address.', 'formflow'),
            ];
        }

        $db = new Database\Database();
        $instance = $db->get_instance($instance_id);

        if (!$instance) {
            return [
                'success' => false,
                'message' => __('Instance not found.', 'formflow'),
            ];
        }

        $digest = new self();

        // Get sample stats (last 7 days)
        $start_date = date('Y-m-d', strtotime('-7 days'));
        $end_date = date('Y-m-d');
        $stats = $digest->get_period_stats($instance_id, $start_date, $end_date);

        // Get comparison
        $prev_start = date('Y-m-d', strtotime('-14 days'));
        $prev_end = date('Y-m-d', strtotime('-7 days'));
        $comparison = $digest->get_period_stats($instance_id, $prev_start, $prev_end);

        $html = $digest->build_digest_html($instance, $stats, $comparison, 'Test');

        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'Energy Wise Rewards';

        $subject = sprintf(
            '[TEST] Digest - %s - %s',
            $program_name,
            date('M j, Y')
        );

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($recipient, $subject, $html, $headers);

        if ($sent) {
            return [
                'success' => true,
                'message' => __('Test digest sent successfully!', 'formflow'),
            ];
        }

        return [
            'success' => false,
            'message' => __('Failed to send test digest. Check your email configuration.', 'formflow'),
        ];
    }
}
