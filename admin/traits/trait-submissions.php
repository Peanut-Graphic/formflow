<?php
namespace ISF\Admin;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Admin Submission Handlers Trait
 *
 * @package    FormFlow
 * @subpackage Admin
 * @since      2.8.4
 */


use ISF\Security;

/**
 * Trait Admin_Submissions
 *
 * AJAX handlers for submission management, export, duplication, and bulk actions.
 *
 * @since 2.8.4
 */
trait Admin_Submissions {
    // =========================================================================
    // Submission Details & Export AJAX Handlers
    // =========================================================================

    /**
     * Get submission details via AJAX
     */
    public function ajax_get_submission_details(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $submission_id = (int)($_POST['submission_id'] ?? 0);
        if (!$submission_id) {
            wp_send_json_error(['message' => __('Invalid submission ID.', 'formflow')]);
            return;
        }

        $submission = $this->db->get_submission($submission_id);
        if (!$submission) {
            wp_send_json_error(['message' => __('Submission not found.', 'formflow')]);
            return;
        }

        // Get instance name
        $instance = $this->db->get_instance($submission['instance_id']);
        $submission['instance_name'] = $instance ? $instance['name'] : 'Unknown';

        // Decode form data
        $form_data = $submission['form_data'] ?? [];

        wp_send_json_success([
            'submission' => $submission,
            'form_data' => $form_data,
        ]);
    }

    /**
     * Export submissions to CSV via AJAX
     */
    public function ajax_export_submissions_csv(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $filters = [
            'instance_id' => (int)($_POST['instance_id'] ?? 0) ?: null,
            'status' => sanitize_text_field($_POST['status'] ?? ''),
            'search' => sanitize_text_field($_POST['search'] ?? ''),
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
        ];

        // Get all submissions (no limit for export)
        $submissions = $this->db->get_submissions_for_export($filters);

        if (empty($submissions)) {
            wp_send_json_error(['message' => __('No submissions to export.', 'formflow')]);
            return;
        }

        // Build CSV content. Columns are derived from the actual form_data
        // keys across the export, plus IntelliSOURCE-specific columns only
        // when at least one row in the export has them populated. This keeps
        // custom builder-form exports clean (no Device Type / Account Number
        // when the form doesn't have those fields) and still works for the
        // legacy IntelliSOURCE enrollment wizard.

        // First pass: collect the union of form_data keys and figure out
        // which IntelliSOURCE columns are actually populated.
        $field_keys = [];
        $has_account_number     = false;
        $has_device_type        = false;
        $has_confirmation       = false;
        $has_schedule           = false;
        foreach ($submissions as $sub) {
            $fd = is_array($sub['form_data'] ?? null) ? $sub['form_data'] : [];
            foreach ($fd as $k => $v) {
                if (!in_array($k, $field_keys, true)) {
                    $field_keys[] = $k;
                }
            }
            if (!empty($sub['account_number'])) { $has_account_number = true; }
            if (!empty($sub['device_type']))    { $has_device_type    = true; }
            if (!empty($fd['confirmation_number'])) { $has_confirmation = true; }
            if (!empty($fd['schedule_date']) || !empty($fd['schedule_time']) || !empty($fd['schedule_time_display'])) {
                $has_schedule = true;
            }
        }

        // Schedule + confirmation are emitted explicitly below (so they
        // render with friendly column names regardless of internal key
        // shape). Pull them out of $field_keys so they're not duplicated.
        $synthetic_keys = [];
        if ($has_schedule) {
            $synthetic_keys = array_merge($synthetic_keys, ['schedule_date', 'schedule_time', 'schedule_time_display']);
        }
        if ($has_confirmation) {
            $synthetic_keys[] = 'confirmation_number';
        }
        $field_keys = array_values(array_filter($field_keys, fn($k) => !in_array($k, $synthetic_keys, true)));

        // Order first_name and last_name first if present, so the export
        // opens with the human-readable identity columns. Everything else
        // follows in schema order.
        $primary = [];
        foreach (['first_name', 'last_name'] as $first) {
            if (in_array($first, $field_keys, true)) {
                $primary[] = $first;
                $field_keys = array_values(array_filter($field_keys, fn($k) => $k !== $first));
            }
        }
        $field_keys = array_merge($primary, $field_keys);

        // Header row.
        $header = ['ID', 'Form Instance'];
        if ($has_account_number) { $header[] = 'Account Number'; }
        if ($has_device_type)    { $header[] = 'Device Type'; }
        foreach ($field_keys as $key) {
            $header[] = ucwords(str_replace(['_', '-'], ' ', $key));
        }
        if ($has_confirmation) { $header[] = 'Confirmation Number'; }
        if ($has_schedule)     { $header[] = 'Schedule Date'; $header[] = 'Schedule Time'; }
        $header[] = 'Status';
        $header[] = 'Created';
        $header[] = 'Completed';

        $csv_lines = [$header];

        foreach ($submissions as $sub) {
            $fd = is_array($sub['form_data'] ?? null) ? $sub['form_data'] : [];

            $row = [
                $sub['id'],
                $sub['instance_name'] ?? '',
            ];
            if ($has_account_number) { $row[] = $sub['account_number'] ?? ''; }
            if ($has_device_type)    { $row[] = $sub['device_type'] ?? ''; }
            foreach ($field_keys as $key) {
                $value = $fd[$key] ?? '';
                if (is_array($value)) { $value = implode(', ', $value); }
                $row[] = (string) $value;
            }
            if ($has_confirmation) { $row[] = $fd['confirmation_number'] ?? ''; }
            if ($has_schedule) {
                $row[] = $fd['schedule_date'] ?? '';
                $row[] = $fd['schedule_time_display'] ?? ($fd['schedule_time'] ?? '');
            }
            $row[] = $sub['status'] ?? '';
            $row[] = $sub['created_at'] ?? '';
            $row[] = $sub['completed_at'] ?? '';

            $csv_lines[] = $row;
        }

        // Convert to CSV string
        $csv_output = '';
        foreach ($csv_lines as $line) {
            $escaped = array_map(function($field) {
                // Escape double quotes and wrap in quotes if needed
                $field = str_replace('"', '""', $field ?? '');
                if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                    return '"' . $field . '"';
                }
                return $field;
            }, $line);
            $csv_output .= implode(',', $escaped) . "\n";
        }

        $filename = 'isf-submissions-' . date('Y-m-d-His') . '.csv';

        wp_send_json_success([
            'csv' => $csv_output,
            'filename' => $filename,
            'count' => count($submissions),
        ]);
    }

    // =========================================================================
    // Instance Duplication AJAX Handler
    // =========================================================================

    /**
     * Duplicate a form instance via AJAX
     */
    public function ajax_duplicate_instance(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

        if (!$id) {
            wp_send_json_error(['message' => __('Invalid form ID.', 'formflow')]);
            return;
        }

        $instance = $this->db->get_instance($id);
        if (!$instance) {
            wp_send_json_error(['message' => __('Form not found.', 'formflow')]);
            return;
        }

        // Generate new slug
        $base_slug = $instance['slug'] . '-copy';
        $new_slug = $base_slug;
        $counter = 1;

        // Find unique slug
        while ($this->db->get_instance_by_slug($new_slug)) {
            $counter++;
            $new_slug = $base_slug . '-' . $counter;
        }

        // Prepare new instance data
        $new_data = [
            'name' => $instance['name'] . ' (Copy)',
            'slug' => $new_slug,
            'utility' => $instance['utility'],
            'form_type' => $instance['form_type'],
            'api_endpoint' => $instance['api_endpoint'],
            'api_password' => $instance['api_password'],
            'support_email_from' => $instance['support_email_from'],
            'support_email_to' => $instance['support_email_to'],
            'is_active' => 0, // Start inactive
            'test_mode' => $instance['test_mode'],
            'settings' => $instance['settings'],
        ];

        $new_id = $this->db->create_instance($new_data);

        if ($new_id) {
            wp_send_json_success([
                'message' => __('Form duplicated successfully.', 'formflow'),
                'new_id' => $new_id,
                'new_slug' => $new_slug,
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to duplicate form.', 'formflow')]);
        }
    }

    /**
     * Save instance display order via AJAX
     */
    public function ajax_save_instance_order(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $order = $_POST['order'] ?? [];

        if (empty($order) || !is_array($order)) {
            wp_send_json_error(['message' => __('Invalid order data.', 'formflow')]);
            return;
        }

        $order = array_map('intval', $order);

        // Update display_order for each instance
        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';
        $success = true;

        foreach ($order as $position => $instance_id) {
            $result = $wpdb->update(
                $table,
                ['display_order' => $position],
                ['id' => $instance_id],
                ['%d'],
                ['%d']
            );

            if ($result === false) {
                $success = false;
            }
        }

        if ($success) {
            wp_send_json_success(['message' => __('Order saved.', 'formflow')]);
        } else {
            wp_send_json_error(['message' => __('Failed to save order.', 'formflow')]);
        }
    }

    // =========================================================================
    // Bulk Actions AJAX Handlers
    // =========================================================================

    /**
     * Process bulk actions on submissions via AJAX
     */
    public function ajax_bulk_submissions_action(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $ids = $_POST['submission_ids'] ?? [];

        if (empty($ids)) {
            wp_send_json_error(['message' => __('No submissions selected.', 'formflow')]);
            return;
        }

        $ids = array_map('intval', (array)$ids);

        switch ($action) {
            case 'mark_test':
                $updated = $this->db->mark_submissions_as_test($ids, true);
                wp_send_json_success([
                    'message' => sprintf(__('%d submission(s) marked as test data.', 'formflow'), $updated),
                    'updated' => $updated,
                ]);
                break;

            case 'mark_production':
                $updated = $this->db->mark_submissions_as_test($ids, false);
                wp_send_json_success([
                    'message' => sprintf(__('%d submission(s) marked as production data.', 'formflow'), $updated),
                    'updated' => $updated,
                ]);
                break;

            case 'delete':
                $deleted = $this->db->delete_submissions($ids);

                // Log the bulk deletion
                $this->db->log_audit(
                    'submission_bulk_delete',
                    'submission',
                    null,
                    sprintf('%d submissions', $deleted),
                    ['submission_ids' => $ids, 'deleted_count' => $deleted]
                );

                wp_send_json_success([
                    'message' => sprintf(__('%d submission(s) deleted.', 'formflow'), $deleted),
                    'deleted' => $deleted,
                ]);
                break;

            default:
                wp_send_json_error(['message' => __('Invalid action.', 'formflow')]);
        }
    }

    /**
     * Process bulk actions on logs via AJAX
     */
    public function ajax_bulk_logs_action(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $ids = $_POST['log_ids'] ?? [];

        if (empty($ids)) {
            wp_send_json_error(['message' => __('No logs selected.', 'formflow')]);
            return;
        }

        $ids = array_map('intval', (array)$ids);

        switch ($action) {
            case 'delete':
                $deleted = $this->db->delete_logs($ids);
                wp_send_json_success([
                    'message' => sprintf(__('%d log(s) deleted.', 'formflow'), $deleted),
                    'deleted' => $deleted,
                ]);
                break;

            default:
                wp_send_json_error(['message' => __('Invalid action.', 'formflow')]);
        }
    }

}
