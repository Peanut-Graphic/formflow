<?php
namespace ISF\Admin;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Admin Reports Handlers Trait
 *
 * @package    FormFlow
 * @subpackage Admin
 * @since      2.8.4
 */


use ISF\Security;

/**
 * Trait Admin_Reports
 *
 * Reports page rendering and AJAX handlers for scheduled reports.
 *
 * @since 2.8.4
 */
trait Admin_Reports {
    // =========================================================================
    // Reports Page & AJAX Handlers
    // =========================================================================

    /**
     * Render the reports page
     */
    public function render_reports(): void {
        $instances = $this->db->get_instances();
        $scheduled_reports = $this->db->get_scheduled_reports();

        include ISF_PLUGIN_DIR . 'admin/views/reports.php';
    }

    /**
     * Save (create or update) a scheduled report via AJAX
     */
    public function ajax_save_scheduled_report(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $report_id = (int)($_POST['report_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'weekly');
        $recipients = sanitize_textarea_field($_POST['recipients'] ?? '');
        $instance_id = (int)($_POST['instance_id'] ?? 0) ?: null;
        $is_active = (bool)($_POST['is_active'] ?? false);

        // Parse recipients (comma or newline separated emails)
        $recipients_array = array_filter(array_map('trim', preg_split('/[,\n]+/', $recipients)));
        $recipients_array = array_filter($recipients_array, 'is_email');

        if (empty($name)) {
            wp_send_json_error(['message' => __('Report name is required.', 'formflow')]);
            return;
        }

        if (empty($recipients_array)) {
            wp_send_json_error(['message' => __('At least one valid email recipient is required.', 'formflow')]);
            return;
        }

        if (!in_array($frequency, ['daily', 'weekly', 'monthly'])) {
            wp_send_json_error(['message' => __('Invalid frequency.', 'formflow')]);
            return;
        }

        // Build settings (could include report sections, format preferences, etc.)
        $settings = [
            'include_summary' => true,
            'include_submissions' => true,
            'include_analytics' => true,
        ];

        $data = [
            'name' => $name,
            'frequency' => $frequency,
            'recipients' => $recipients_array,
            'instance_id' => $instance_id,
            'settings' => $settings,
            'is_active' => $is_active,
        ];

        if ($report_id) {
            $success = $this->db->update_scheduled_report($report_id, $data);
            $message = __('Scheduled report updated successfully.', 'formflow');
            $audit_action = 'scheduled_report_update';
        } else {
            $success = $this->db->create_scheduled_report($data);
            $message = __('Scheduled report created successfully.', 'formflow');
            $audit_action = 'scheduled_report_create';
        }

        if ($success) {
            // Log the action
            $this->db->log_audit(
                $audit_action,
                'scheduled_report',
                $report_id ?: $success,
                $name,
                ['frequency' => $frequency, 'recipients_count' => count($recipients_array)]
            );

            wp_send_json_success(['message' => $message]);
        } else {
            wp_send_json_error(['message' => __('Failed to save scheduled report.', 'formflow')]);
        }
    }

    /**
     * Get a single scheduled report via AJAX
     */
    public function ajax_get_scheduled_report(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $report_id = (int)($_POST['report_id'] ?? 0);
        if (!$report_id) {
            wp_send_json_error(['message' => __('Invalid report ID.', 'formflow')]);
            return;
        }

        $report = $this->db->get_scheduled_report($report_id);
        if (!$report) {
            wp_send_json_error(['message' => __('Report not found.', 'formflow')]);
            return;
        }

        // Convert recipients array to string for form
        if (is_array($report['recipients'])) {
            $report['recipients_text'] = implode(', ', $report['recipients']);
        } else {
            $report['recipients_text'] = $report['recipients'];
        }

        wp_send_json_success(['report' => $report]);
    }

    /**
     * Delete a scheduled report via AJAX
     */
    public function ajax_delete_scheduled_report(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $report_id = (int)($_POST['report_id'] ?? 0);
        if (!$report_id) {
            wp_send_json_error(['message' => __('Invalid report ID.', 'formflow')]);
            return;
        }

        // Get report info for audit log
        $report = $this->db->get_scheduled_report($report_id);
        $report_name = $report ? $report['name'] : 'Unknown';

        if ($this->db->delete_scheduled_report($report_id)) {
            // Log the deletion
            $this->db->log_audit(
                'scheduled_report_delete',
                'scheduled_report',
                $report_id,
                $report_name,
                []
            );

            wp_send_json_success(['message' => __('Scheduled report deleted successfully.', 'formflow')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete scheduled report.', 'formflow')]);
        }
    }

    /**
     * Send a scheduled report immediately via AJAX
     */
    public function ajax_send_report_now(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $report_id = (int)($_POST['report_id'] ?? 0);
        if (!$report_id) {
            wp_send_json_error(['message' => __('Invalid report ID.', 'formflow')]);
            return;
        }

        $report = $this->db->get_scheduled_report($report_id);
        if (!$report) {
            wp_send_json_error(['message' => __('Report not found.', 'formflow')]);
            return;
        }

        require_once ISF_PLUGIN_DIR . 'includes/class-report-generator.php';

        try {
            $generator = new \ISF\ReportGenerator($this->db);
            $result = $generator->send_scheduled_report($report);

            if ($result) {
                // Update last sent timestamp
                $this->db->update_report_sent($report_id);

                wp_send_json_success([
                    'message' => sprintf(
                        __('Report sent successfully to %d recipient(s).', 'formflow'),
                        count($report['recipients'])
                    ),
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to send report email.', 'formflow')]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Generate a custom report via AJAX
     */
    public function ajax_generate_custom_report(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $instance_id = (int)($_POST['instance_id'] ?? 0) ?: null;
        $output_format = sanitize_text_field($_POST['output_format'] ?? 'html');
        $sections = $_POST['sections'] ?? ['summary', 'submissions', 'analytics'];

        if (empty($date_from) || empty($date_to)) {
            wp_send_json_error(['message' => __('Date range is required.', 'formflow')]);
            return;
        }

        require_once ISF_PLUGIN_DIR . 'includes/class-report-generator.php';

        try {
            $generator = new \ISF\ReportGenerator($this->db);
            $report_data = $generator->generate_report($date_from, $date_to, $instance_id, $sections);

            if ($output_format === 'csv') {
                // Generate CSV
                $csv = $generator->to_csv($report_data);
                wp_send_json_success([
                    'format' => 'csv',
                    'csv' => $csv,
                    'filename' => 'isf-report-' . date('Y-m-d') . '.csv',
                ]);
            } elseif ($output_format === 'email') {
                // Send via email
                $recipients = sanitize_textarea_field($_POST['email_recipients'] ?? '');
                $recipients_array = array_filter(array_map('trim', preg_split('/[,\n]+/', $recipients)));
                $recipients_array = array_filter($recipients_array, 'is_email');

                if (empty($recipients_array)) {
                    wp_send_json_error(['message' => __('Valid email recipients are required.', 'formflow')]);
                    return;
                }

                $result = $generator->send_report_email($report_data, $recipients_array);
                if ($result) {
                    wp_send_json_success([
                        'format' => 'email',
                        'message' => sprintf(
                            __('Report sent to %d recipient(s).', 'formflow'),
                            count($recipients_array)
                        ),
                    ]);
                } else {
                    wp_send_json_error(['message' => __('Failed to send report email.', 'formflow')]);
                }
            } else {
                // Return HTML
                $html = $generator->to_html($report_data);
                wp_send_json_success([
                    'format' => 'html',
                    'html' => $html,
                ]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Export analytics data to CSV via AJAX
     */
    public function ajax_export_analytics_csv(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $instance_id = (int)($_POST['instance_id'] ?? 0) ?: null;

        if (empty($date_from) || empty($date_to)) {
            wp_send_json_error(['message' => __('Date range is required.', 'formflow')]);
            return;
        }

        $analytics = $this->db->get_analytics_for_export($date_from, $date_to, $instance_id);

        if (empty($analytics)) {
            wp_send_json_error(['message' => __('No analytics data found for the selected range.', 'formflow')]);
            return;
        }

        // Build CSV
        $csv_lines = [];

        // Header
        $csv_lines[] = [
            'Date',
            'Form Instance',
            'Step',
            'Step Name',
            'Action',
            'Session ID',
            'Time on Step (sec)',
            'Device Type',
            'Browser',
            'Is Mobile',
            'Is Test',
        ];

        foreach ($analytics as $row) {
            $csv_lines[] = [
                $row['created_at'],
                $row['instance_name'] ?? '',
                $row['step'],
                $row['step_name'] ?? '',
                $row['action'],
                $row['session_id'],
                $row['time_on_step'],
                $row['device_type'] ?? '',
                $row['browser'] ?? '',
                $row['is_mobile'] ? 'Yes' : 'No',
                $row['is_test'] ? 'Yes' : 'No',
            ];
        }

        // Convert to CSV string
        $csv_output = '';
        foreach ($csv_lines as $line) {
            $escaped = array_map(function($field) {
                $field = str_replace('"', '""', $field ?? '');
                if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                    return '"' . $field . '"';
                }
                return $field;
            }, $line);
            $csv_output .= implode(',', $escaped) . "\n";
        }

        wp_send_json_success([
            'csv' => $csv_output,
            'filename' => 'isf-analytics-' . date('Y-m-d-His') . '.csv',
            'count' => count($analytics),
        ]);
    }

}
