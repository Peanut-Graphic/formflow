<?php
/**
 * WordPress Privacy API integration.
 *
 * The plugin's Database class has had find_submissions_for_gdpr(),
 * anonymize_submission(), and permanently_delete_submission() for some
 * time — but they were never registered with WordPress's built-in
 * Tools → Erase Personal Data / Export Personal Data screens. So clicking
 * those buttons in wp-admin did nothing for FormFlow's submissions data.
 *
 * This class wires the existing methods to the canonical WP Privacy API
 * filters so admins (and the data subjects whose email they punch in)
 * actually get exported / erased.
 *
 * Flagged by PHIL in the 2026-05-28 audit.
 */

namespace ISF;

use ISF\Database\Database;

class Privacy
{
    private Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
    }

    public function register_exporter(array $exporters): array
    {
        $exporters['formflow-submissions'] = [
            'exporter_friendly_name' => __('FormFlow Submissions', 'formflow'),
            'callback'               => [$this, 'export_submissions'],
        ];
        return $exporters;
    }

    public function register_eraser(array $erasers): array
    {
        $erasers['formflow-submissions'] = [
            'eraser_friendly_name' => __('FormFlow Submissions', 'formflow'),
            'callback'             => [$this, 'erase_submissions'],
        ];
        return $erasers;
    }

    /**
     * Export every submission tied to $email.
     *
     * The WP Privacy API expects a fixed-shape response: an array of
     * "data items," each with a group_id, group_label, item_id, and
     * a flat list of name/value rows. We surface the form_data fields
     * as rows so the data subject can see what was submitted.
     */
    public function export_submissions(string $email, int $page = 1): array
    {
        $submissions = $this->db->find_submissions_for_gdpr($email);

        $data_to_export = [];
        foreach ($submissions as $sub) {
            $rows = [
                ['name' => __('Submission ID', 'formflow'), 'value' => (string) $sub['id']],
                ['name' => __('Submitted at', 'formflow'), 'value' => (string) ($sub['created_at'] ?? '')],
                ['name' => __('Status', 'formflow'),       'value' => (string) ($sub['status'] ?? '')],
            ];
            $fd = is_array($sub['form_data'] ?? null) ? $sub['form_data'] : [];
            foreach ($fd as $k => $v) {
                if (is_string($k) && strpos($k, '_') === 0) { continue; }
                if (is_array($v)) { $v = implode(', ', $v); }
                $rows[] = ['name' => (string) $k, 'value' => (string) $v];
            }

            $data_to_export[] = [
                'group_id'    => 'formflow_submissions',
                'group_label' => __('Form Submissions', 'formflow'),
                'item_id'     => 'formflow-submission-' . $sub['id'],
                'data'        => $rows,
            ];
        }

        return [
            'data' => $data_to_export,
            'done' => true,
        ];
    }

    /**
     * Erase every submission tied to $email — anonymize by default
     * (preserves analytics/aggregate counts) or hard-delete when the
     * data-retention settings ask for it. WP expects a counts response.
     */
    public function erase_submissions(string $email, int $page = 1): array
    {
        $submissions = $this->db->find_submissions_for_gdpr($email);

        $items_removed     = false;
        $items_retained    = false;
        $messages          = [];

        $settings  = get_option('isf_settings', []);
        $anonymize = !empty($settings['anonymize_instead_of_delete']);

        foreach ($submissions as $sub) {
            if ($anonymize) {
                if ($this->db->anonymize_submission((int) $sub['id'])) {
                    $items_removed = true;
                } else {
                    $items_retained = true;
                    $messages[]     = sprintf(__('Could not anonymize submission %d.', 'formflow'), $sub['id']);
                }
            } else {
                if ($this->db->permanently_delete_submission((int) $sub['id'])) {
                    $items_removed = true;
                } else {
                    $items_retained = true;
                    $messages[]     = sprintf(__('Could not delete submission %d.', 'formflow'), $sub['id']);
                }
            }
        }

        return [
            'items_removed'  => $items_removed,
            'items_retained' => $items_retained,
            'messages'       => $messages,
            'done'           => true,
        ];
    }
}
