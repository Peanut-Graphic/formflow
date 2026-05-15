<?php
namespace ISF\Admin;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Admin Compliance Handlers Trait
 *
 * @package    FormFlow
 * @subpackage Admin
 * @since      2.8.4
 */


use ISF\Security;

/**
 * Trait Admin_Compliance
 *
 * Compliance page rendering and AJAX handlers for GDPR, audit log, data retention.
 *
 * @since 2.8.4
 */
trait Admin_Compliance {
    // =========================================================================
    // Compliance Page & AJAX Handlers (GDPR, Audit Log, Data Retention)
    // =========================================================================

    /**
     * Render the compliance page
     */
    public function render_compliance(): void {
        // Handle retention settings form submission
        if (isset($_POST['isf_save_retention']) && check_admin_referer('isf_retention_nonce')) {
            $this->save_retention_settings();
        }

        $instances = $this->db->get_instances();
        $settings = get_option('isf_settings', []);

        include ISF_PLUGIN_DIR . 'admin/views/compliance.php';
    }

    /**
     * Save retention policy settings from form POST
     */
    private function save_retention_settings(): void {
        $current_settings = get_option('isf_settings', []);

        $retention_settings = [
            'retention_enabled' => isset($_POST['retention_enabled']),
            'anonymize_instead_of_delete' => isset($_POST['anonymize_instead_of_delete']),
            'retention_submissions_days' => (int)($_POST['retention_submissions_days'] ?? 365),
            'retention_analytics_days' => (int)($_POST['retention_analytics_days'] ?? 180),
            'retention_audit_log_days' => (int)($_POST['retention_audit_log_days'] ?? 365),
            'retention_api_usage_days' => (int)($_POST['retention_api_usage_days'] ?? 90),
            'log_retention_days' => (int)($_POST['log_retention_days'] ?? 90),
        ];

        $new_settings = array_merge($current_settings, $retention_settings);
        update_option('isf_settings', $new_settings);

        // Log the settings change
        $this->db->log_audit(
            'retention_settings_update',
            'settings',
            null,
            'Data Retention Policy',
            [
                'retention_enabled' => $retention_settings['retention_enabled'],
                'anonymize_instead_of_delete' => $retention_settings['anonymize_instead_of_delete'],
            ]
        );

        add_settings_error(
            'isf_retention_settings',
            'settings_updated',
            __('Retention policy saved successfully.', 'formflow'),
            'success'
        );
    }

    /**
     * Search for user data (GDPR) via AJAX
     */
    public function ajax_gdpr_search(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $email = sanitize_email($_POST['email'] ?? '');
        $account_number = sanitize_text_field($_POST['account_number'] ?? '');

        if (empty($email) && empty($account_number)) {
            wp_send_json_error(['message' => __('Please provide an email address or account number.', 'formflow')]);
            return;
        }

        $submissions = $this->db->find_submissions_for_gdpr($email, $account_number);

        // Log this search for audit purposes
        $this->db->log_audit(
            'gdpr_search',
            'submission',
            null,
            $email ?: $account_number,
            [
                'email' => $email,
                'account_number' => $account_number,
                'results_count' => count($submissions),
            ]
        );

        wp_send_json_success([
            'submissions' => $submissions,
            'count' => count($submissions),
        ]);
    }

    /**
     * Export user data (GDPR) via AJAX
     */
    public function ajax_gdpr_export(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $submission_ids = $_POST['submission_ids'] ?? [];
        $email = sanitize_email($_POST['email'] ?? '');

        if (empty($submission_ids)) {
            wp_send_json_error(['message' => __('No submissions selected for export.', 'formflow')]);
            return;
        }

        $submission_ids = array_map('intval', (array)$submission_ids);
        $export_data = [];

        foreach ($submission_ids as $id) {
            $submission = $this->db->get_submission($id);
            if ($submission) {
                // Get instance info
                $instance = $this->db->get_instance($submission['instance_id']);

                $export_data[] = [
                    'submission_id' => $id,
                    'form_instance' => $instance ? $instance['name'] : 'Unknown',
                    'account_number' => $submission['account_number'],
                    'customer_name' => $submission['customer_name'],
                    'device_type' => $submission['device_type'],
                    'status' => $submission['status'],
                    'created_at' => $submission['created_at'],
                    'completed_at' => $submission['completed_at'],
                    'form_data' => $submission['form_data'],
                ];
            }
        }

        // Create GDPR request record
        $request_id = $this->db->create_gdpr_request([
            'request_type' => 'export',
            'email' => $email,
            'status' => 'completed',
            'request_data' => ['submission_ids' => $submission_ids],
            'result_data' => ['exported_count' => count($export_data)],
        ]);

        // Log the export
        $this->db->log_audit(
            'gdpr_export',
            'gdpr_request',
            $request_id,
            $email,
            [
                'submission_ids' => $submission_ids,
                'exported_count' => count($export_data),
            ]
        );

        wp_send_json_success([
            'export_data' => $export_data,
            'request_id' => $request_id,
            'filename' => 'gdpr-export-' . date('Y-m-d-His') . '.json',
        ]);
    }

    /**
     * Anonymize user data (GDPR) via AJAX
     */
    public function ajax_gdpr_anonymize(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $submission_ids = $_POST['submission_ids'] ?? [];
        $email = sanitize_email($_POST['email'] ?? '');
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (empty($submission_ids)) {
            wp_send_json_error(['message' => __('No submissions selected for anonymization.', 'formflow')]);
            return;
        }

        $submission_ids = array_map('intval', (array)$submission_ids);
        $anonymized = 0;
        $failed = 0;

        foreach ($submission_ids as $id) {
            if ($this->db->anonymize_submission($id)) {
                $anonymized++;
            } else {
                $failed++;
            }
        }

        // Create GDPR request record
        $request_id = $this->db->create_gdpr_request([
            'request_type' => 'erasure',
            'email' => $email,
            'status' => 'completed',
            'request_data' => [
                'submission_ids' => $submission_ids,
                'action' => 'anonymize',
                'reason' => $reason,
            ],
            'result_data' => [
                'anonymized' => $anonymized,
                'failed' => $failed,
            ],
            'notes' => $reason,
        ]);

        // Log the anonymization
        $this->db->log_audit(
            'gdpr_anonymize',
            'gdpr_request',
            $request_id,
            $email,
            [
                'submission_ids' => $submission_ids,
                'anonymized' => $anonymized,
                'failed' => $failed,
                'reason' => $reason,
            ]
        );

        wp_send_json_success([
            'message' => sprintf(
                __('Successfully anonymized %d submission(s). %d failed.', 'formflow'),
                $anonymized,
                $failed
            ),
            'anonymized' => $anonymized,
            'failed' => $failed,
            'request_id' => $request_id,
        ]);
    }

    /**
     * Permanently delete user data (GDPR) via AJAX
     */
    public function ajax_gdpr_delete(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $submission_ids = $_POST['submission_ids'] ?? [];
        $email = sanitize_email($_POST['email'] ?? '');
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (empty($submission_ids)) {
            wp_send_json_error(['message' => __('No submissions selected for deletion.', 'formflow')]);
            return;
        }

        $submission_ids = array_map('intval', (array)$submission_ids);
        $deleted = 0;
        $failed = 0;

        foreach ($submission_ids as $id) {
            if ($this->db->permanently_delete_submission($id)) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        // Create GDPR request record
        $request_id = $this->db->create_gdpr_request([
            'request_type' => 'erasure',
            'email' => $email,
            'status' => 'completed',
            'request_data' => [
                'submission_ids' => $submission_ids,
                'action' => 'delete',
                'reason' => $reason,
            ],
            'result_data' => [
                'deleted' => $deleted,
                'failed' => $failed,
            ],
            'notes' => $reason,
        ]);

        // Log the deletion
        $this->db->log_audit(
            'gdpr_delete',
            'gdpr_request',
            $request_id,
            $email,
            [
                'submission_ids' => $submission_ids,
                'deleted' => $deleted,
                'failed' => $failed,
                'reason' => $reason,
            ]
        );

        wp_send_json_success([
            'message' => sprintf(
                __('Permanently deleted %d submission(s). %d failed.', 'formflow'),
                $deleted,
                $failed
            ),
            'deleted' => $deleted,
            'failed' => $failed,
            'request_id' => $request_id,
        ]);
    }

    /**
     * Get audit log entries via AJAX
     */
    public function ajax_get_audit_log(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $filters = [
            'action' => sanitize_text_field($_POST['action'] ?? ''),
            'user_id' => (int)($_POST['user_id'] ?? 0) ?: null,
            'object_type' => sanitize_text_field($_POST['object_type'] ?? ''),
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
        ];

        $page = max(1, (int)($_POST['page'] ?? 1));
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        $logs = $this->db->get_audit_log($filters, $per_page, $offset);
        $total = $this->db->get_audit_log_count($filters);

        wp_send_json_success([
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $per_page),
        ]);
    }

    /**
     * Get GDPR request history via AJAX
     */
    public function ajax_get_gdpr_requests(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $page = max(1, (int)($_POST['page'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        $requests = $this->db->get_gdpr_requests([], $per_page, $offset);
        $total = $this->db->get_gdpr_requests_count();

        wp_send_json_success([
            'requests' => $requests,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $per_page),
        ]);
    }

    /**
     * Preview data retention policy results via AJAX
     */
    public function ajax_preview_retention(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $settings = [
            'retention_submissions_days' => (int)($_POST['retention_submissions_days'] ?? 365),
            'retention_analytics_days' => (int)($_POST['retention_analytics_days'] ?? 180),
            'retention_audit_log_days' => (int)($_POST['retention_audit_log_days'] ?? 365),
            'retention_api_usage_days' => (int)($_POST['retention_api_usage_days'] ?? 90),
            'anonymize_instead_of_delete' => (bool)($_POST['anonymize_instead_of_delete'] ?? true),
        ];

        $stats = $this->db->get_retention_stats($settings);

        wp_send_json_success([
            'stats' => $stats,
            'settings' => $settings,
        ]);
    }

    /**
     * Apply data retention policy via AJAX
     */
    public function ajax_run_retention(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $settings = [
            'retention_submissions_days' => (int)($_POST['retention_submissions_days'] ?? 365),
            'retention_analytics_days' => (int)($_POST['retention_analytics_days'] ?? 180),
            'retention_audit_log_days' => (int)($_POST['retention_audit_log_days'] ?? 365),
            'retention_api_usage_days' => (int)($_POST['retention_api_usage_days'] ?? 90),
            'anonymize_instead_of_delete' => (bool)($_POST['anonymize_instead_of_delete'] ?? true),
        ];

        $result = $this->db->apply_retention_policy($settings);

        // Log the retention policy execution
        $this->db->log_audit(
            'retention_policy_run',
            'system',
            null,
            'Manual execution',
            [
                'settings' => $settings,
                'result' => $result,
            ]
        );

        wp_send_json_success([
            'results' => $result,
            'message' => __('Data retention policy applied successfully.', 'formflow'),
        ]);
    }

    /**
     * Save retention settings via AJAX
     */
    public function ajax_save_retention_settings(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $current_settings = get_option('isf_settings', []);

        $retention_settings = [
            'retention_submissions_days' => (int)($_POST['retention_submissions_days'] ?? 365),
            'retention_analytics_days' => (int)($_POST['retention_analytics_days'] ?? 180),
            'retention_audit_log_days' => (int)($_POST['retention_audit_log_days'] ?? 365),
            'retention_api_usage_days' => (int)($_POST['retention_api_usage_days'] ?? 90),
            'retention_enabled' => (bool)($_POST['retention_enabled'] ?? false),
            'anonymize_instead_of_delete' => (bool)($_POST['anonymize_instead_of_delete'] ?? true),
        ];

        $new_settings = array_merge($current_settings, $retention_settings);
        update_option('isf_settings', $new_settings);

        // Log the settings change
        $this->db->log_audit(
            'retention_settings_update',
            'settings',
            null,
            'Data Retention Policy',
            [
                'old_settings' => array_intersect_key($current_settings, $retention_settings),
                'new_settings' => $retention_settings,
            ]
        );

        wp_send_json_success([
            'message' => __('Retention settings saved successfully.', 'formflow'),
        ]);
    }

}
