<?php
namespace ISF\Database;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Database Operations
 *
 * Handles all CRUD operations for plugin database tables.
 */


use ISF\Encryption;

class Database {

    /**
     * Maximum number of submissions processed per pass during the retention
     * sweep, to keep memory and per-query time bounded on large tables.
     */
    const RETENTION_BATCH_SIZE = 500;

    private \wpdb $wpdb;
    private string $table_instances;
    private string $table_submissions;
    private string $table_logs;
    private string $table_analytics;
    private Encryption $encryption;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_instances = $wpdb->prefix . ISF_TABLE_INSTANCES;
        $this->table_submissions = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;
        $this->table_logs = $wpdb->prefix . ISF_TABLE_LOGS;
        $this->table_analytics = $wpdb->prefix . ISF_TABLE_ANALYTICS;
        $this->encryption = new Encryption();
    }

    // =========================================================================
    // Form Instances
    // =========================================================================

    /**
     * Get all form instances
     *
     * @param bool $active_only Only return active instances
     * @param string $order_by Column to order by ('display_order', 'name', 'created_at')
     * @return array
     */
    public function get_instances(bool $active_only = false, string $order_by = 'display_order'): array {
        $sql = "SELECT * FROM {$this->table_instances}";

        if ($active_only) {
            $sql .= " WHERE is_active = 1";
        }

        // Determine ordering
        switch ($order_by) {
            case 'display_order':
                $sql .= " ORDER BY display_order ASC, name ASC";
                break;
            case 'created_at':
                $sql .= " ORDER BY created_at DESC";
                break;
            case 'name':
            default:
                $sql .= " ORDER BY name ASC";
                break;
        }

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map([$this, 'decode_instance'], $results ?: []);
    }

    /**
     * Get a form instance by ID
     *
     * @param int $id Instance ID
     * @return array|null
     */
    public function get_instance(int $id): ?array {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_instances} WHERE id = %d",
            $id
        );

        $result = $this->wpdb->get_row($sql, ARRAY_A);

        return $result ? $this->decode_instance($result) : null;
    }

    /**
     * Get a form instance by slug
     *
     * @param string $slug Instance slug
     * @param bool $active_only Only return if active (default true for frontend, false for AJAX)
     * @return array|null
     */
    public function get_instance_by_slug(string $slug, bool $active_only = false): ?array {
        if ($active_only) {
            $sql = $this->wpdb->prepare(
                "SELECT * FROM {$this->table_instances} WHERE slug = %s AND is_active = 1",
                $slug
            );
        } else {
            $sql = $this->wpdb->prepare(
                "SELECT * FROM {$this->table_instances} WHERE slug = %s",
                $slug
            );
        }

        $result = $this->wpdb->get_row($sql, ARRAY_A);

        return $result ? $this->decode_instance($result) : null;
    }

    /**
     * Get a form instance by utility code
     *
     * @param string $utility Utility code
     * @param bool $active_only Only return if active
     * @return array|null
     */
    public function get_instance_by_utility(string $utility, bool $active_only = true): ?array {
        if ($active_only) {
            $sql = $this->wpdb->prepare(
                "SELECT * FROM {$this->table_instances} WHERE utility = %s AND is_active = 1",
                $utility
            );
        } else {
            $sql = $this->wpdb->prepare(
                "SELECT * FROM {$this->table_instances} WHERE utility = %s",
                $utility
            );
        }

        $result = $this->wpdb->get_row($sql, ARRAY_A);

        return $result ? $this->decode_instance($result) : null;
    }

    /**
     * Create a new form instance
     *
     * @param array $data Instance data
     * @return int|false The new instance ID or false on failure
     */
    public function create_instance(array $data): int|false {
        $insert_data = [
            'name' => $data['name'],
            'slug' => sanitize_title($data['slug']),
            'utility' => $data['utility'],
            'form_type' => $data['form_type'] ?? 'enrollment',
            'api_endpoint' => $data['api_endpoint'],
            'api_password' => $this->encryption->encrypt($data['api_password'] ?? ''),
            'support_email_from' => $data['support_email_from'] ?? '',
            'support_email_to' => $data['support_email_to'] ?? '',
            'settings' => json_encode($data['settings'] ?? []),
            'is_active' => $data['is_active'] ?? 1,
            'test_mode' => $data['test_mode'] ?? 0
        ];

        $result = $this->wpdb->insert($this->table_instances, $insert_data);

        if ($result === false) {
            return false;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Update a form instance
     *
     * @param int $id Instance ID
     * @param array $data Data to update
     * @return bool Success
     */
    public function update_instance(int $id, array $data): bool {
        $update_data = [];

        if (isset($data['name'])) {
            $update_data['name'] = $data['name'];
        }

        if (isset($data['slug'])) {
            $update_data['slug'] = sanitize_title($data['slug']);
        }

        if (isset($data['utility'])) {
            $update_data['utility'] = $data['utility'];
        }

        if (isset($data['form_type'])) {
            $update_data['form_type'] = $data['form_type'];
        }

        if (isset($data['api_endpoint'])) {
            $update_data['api_endpoint'] = $data['api_endpoint'];
        }

        if (isset($data['api_password']) && !empty($data['api_password'])) {
            $update_data['api_password'] = $this->encryption->encrypt($data['api_password']);
        }

        if (isset($data['support_email_from'])) {
            $update_data['support_email_from'] = $data['support_email_from'];
        }

        if (isset($data['support_email_to'])) {
            $update_data['support_email_to'] = $data['support_email_to'];
        }

        if (isset($data['settings'])) {
            $update_data['settings'] = json_encode($data['settings']);
        }

        if (isset($data['is_active'])) {
            $update_data['is_active'] = (int)$data['is_active'];
        }

        if (isset($data['test_mode'])) {
            $update_data['test_mode'] = (int)$data['test_mode'];
        }

        if (empty($update_data)) {
            return true;
        }

        $result = $this->wpdb->update(
            $this->table_instances,
            $update_data,
            ['id' => $id]
        );

        return $result !== false;
    }

    /**
     * Delete a form instance
     *
     * @param int $id Instance ID
     * @return bool Success
     */
    public function delete_instance(int $id): bool {
        // Foreign keys will cascade delete submissions
        $result = $this->wpdb->delete(
            $this->table_instances,
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Decode instance data (decrypt password, parse JSON)
     */
    private function decode_instance(array $instance): array {
        $instance['api_password'] = $this->encryption->decrypt($instance['api_password'] ?? '');
        $instance['settings'] = json_decode($instance['settings'] ?? '{}', true) ?: [];
        $instance['is_active'] = (bool)$instance['is_active'];
        $instance['test_mode'] = (bool)$instance['test_mode'];

        return $instance;
    }

    // =========================================================================
    // Form Submissions
    // =========================================================================

    /**
     * Create a new submission
     *
     * @param array $data Submission data
     * @return int|false The new submission ID or false on failure
     */
    public function create_submission(array $data): int|false {
        $insert_data = [
            'instance_id' => $data['instance_id'],
            'session_id' => $data['session_id'],
            'account_number' => $data['account_number'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'device_type' => $data['device_type'] ?? null,
            'form_data' => $this->encryption->encrypt_array($data['form_data'] ?? []),
            'status' => $data['status'] ?? 'in_progress',
            'step' => $data['step'] ?? 1,
            'ip_address' => $data['ip_address'] ?? '',
            'user_agent' => substr(sanitize_text_field($data['user_agent'] ?? ''), 0, 500)
        ];

        $result = $this->wpdb->insert($this->table_submissions, $insert_data);

        if ($result === false) {
            return false;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Get a submission by session ID
     *
     * @param string $session_id Session ID
     * @param int $instance_id Instance ID
     * @return array|null
     */
    public function get_submission_by_session(string $session_id, int $instance_id): ?array {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_submissions}
             WHERE session_id = %s AND instance_id = %d
             ORDER BY created_at DESC LIMIT 1",
            $session_id,
            $instance_id
        );

        $result = $this->wpdb->get_row($sql, ARRAY_A);

        return $result ? $this->decode_submission($result) : null;
    }

    /**
     * Get a submission by ID
     *
     * @param int $id Submission ID
     * @return array|null
     */
    public function get_submission(int $id): ?array {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_submissions} WHERE id = %d",
            $id
        );

        $result = $this->wpdb->get_row($sql, ARRAY_A);

        return $result ? $this->decode_submission($result) : null;
    }

    /**
     * Update a submission
     *
     * @param int $id Submission ID
     * @param array $data Data to update
     * @return bool Success
     */
    public function update_submission(int $id, array $data): bool {
        $update_data = [];

        if (isset($data['account_number'])) {
            $update_data['account_number'] = $data['account_number'];
        }

        if (isset($data['customer_name'])) {
            $update_data['customer_name'] = $data['customer_name'];
        }

        if (isset($data['device_type'])) {
            $update_data['device_type'] = $data['device_type'];
        }

        if (isset($data['form_data'])) {
            $update_data['form_data'] = $this->encryption->encrypt_array($data['form_data']);
        }

        if (isset($data['api_response'])) {
            $update_data['api_response'] = $this->encryption->encrypt_array($data['api_response']);
        }

        if (isset($data['status'])) {
            $update_data['status'] = $data['status'];
            if ($data['status'] === 'completed') {
                $update_data['completed_at'] = current_time('mysql');
            }
        }

        if (isset($data['step'])) {
            $update_data['step'] = (int)$data['step'];
        }

        if (empty($update_data)) {
            return true;
        }

        $result = $this->wpdb->update(
            $this->table_submissions,
            $update_data,
            ['id' => $id]
        );

        return $result !== false;
    }

    /**
     * Get submissions with filters
     *
     * @param array $filters Filter criteria
     * @param int $limit Max results
     * @param int $offset Offset
     * @return array
     */
    public function get_submissions(array $filters = [], int $limit = 50, int $offset = 0): array {
        $where = ['1=1'];
        $values = [];

        if (!empty($filters['instance_id'])) {
            $where[] = 'instance_id = %d';
            $values[] = $filters['instance_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $values[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $values[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $values[] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(account_number LIKE %s OR customer_name LIKE %s)';
            $search = '%' . $this->wpdb->esc_like($filters['search']) . '%';
            $values[] = $search;
            $values[] = $search;
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT s.*, i.name as instance_name
                FROM {$this->table_submissions} s
                LEFT JOIN {$this->table_instances} i ON s.instance_id = i.id
                WHERE {$where_clause}
                ORDER BY s.created_at DESC
                LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map([$this, 'decode_submission'], $results ?: []);
    }

    /**
     * Get submission count
     *
     * @param array $filters Filter criteria
     * @return int
     */
    public function get_submission_count(array $filters = []): int {
        $where = ['1=1'];
        $values = [];

        if (!empty($filters['instance_id'])) {
            $where[] = 'instance_id = %d';
            $values[] = $filters['instance_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $values[] = $filters['status'];
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM {$this->table_submissions} WHERE {$where_clause}";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        return (int)$this->wpdb->get_var($sql);
    }

    /**
     * Get submissions for CSV export (no limit)
     *
     * @param array $filters Filter criteria
     * @return array
     */
    public function get_submissions_for_export(array $filters = []): array {
        $where = ['1=1'];
        $values = [];

        if (!empty($filters['instance_id'])) {
            $where[] = 's.instance_id = %d';
            $values[] = $filters['instance_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 's.status = %s';
            $values[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 's.created_at >= %s';
            $values[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 's.created_at <= %s';
            $values[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $where[] = '(s.account_number LIKE %s OR s.customer_name LIKE %s)';
            $search = '%' . $this->wpdb->esc_like($filters['search']) . '%';
            $values[] = $search;
            $values[] = $search;
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT s.*, i.name as instance_name
                FROM {$this->table_submissions} s
                LEFT JOIN {$this->table_instances} i ON s.instance_id = i.id
                WHERE {$where_clause}
                ORDER BY s.created_at DESC";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map([$this, 'decode_submission'], $results ?: []);
    }

    /**
     * Mark abandoned sessions
     *
     * @param int $hours Hours after which to mark as abandoned
     * @return int Number of updated records
     */
    public function mark_abandoned_sessions(int $hours): int {
        $sql = $this->wpdb->prepare(
            "UPDATE {$this->table_submissions}
             SET status = 'abandoned'
             WHERE status = 'in_progress'
             AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $hours
        );

        return (int)$this->wpdb->query($sql);
    }

    /**
     * Decode submission data
     */
    private function decode_submission(array $submission): array {
        $submission['form_data'] = $this->encryption->decrypt_array($submission['form_data'] ?? '');
        if (!empty($submission['api_response'])) {
            $submission['api_response'] = $this->encryption->decrypt_array($submission['api_response']);
        }

        return $submission;
    }

    // =========================================================================
    // Logging
    // =========================================================================

    /**
     * Create a log entry
     *
     * @param string $type Log type (info, warning, error, api_call, security)
     * @param string $message Log message
     * @param array $details Additional details
     * @param int|null $instance_id Related instance ID
     * @param int|null $submission_id Related submission ID
     * @return int|false The new log ID or false on failure
     */
    public function log(
        string $type,
        string $message,
        array $details = [],
        ?int $instance_id = null,
        ?int $submission_id = null
    ): int|false {
        $insert_data = [
            'instance_id' => $instance_id,
            'submission_id' => $submission_id,
            'log_type' => $type,
            'message' => $message,
            'details' => json_encode($details)
        ];

        $result = $this->wpdb->insert($this->table_logs, $insert_data);

        if ($result === false) {
            return false;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Get log entries
     *
     * @param array $filters Filter criteria
     * @param int $limit Max results
     * @param int $offset Offset
     * @return array
     */
    public function get_logs(array $filters = [], int $limit = 100, int $offset = 0): array {
        $where = ['1=1'];
        $values = [];

        if (!empty($filters['instance_id'])) {
            $where[] = 'l.instance_id = %d';
            $values[] = $filters['instance_id'];
        }

        if (!empty($filters['submission_id'])) {
            $where[] = 'l.submission_id = %d';
            $values[] = $filters['submission_id'];
        }

        if (!empty($filters['type'])) {
            $where[] = 'l.log_type = %s';
            $values[] = $filters['type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'l.created_at >= %s';
            $values[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'l.created_at <= %s';
            $values[] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $where[] = 'l.message LIKE %s';
            $values[] = '%' . $this->wpdb->esc_like($filters['search']) . '%';
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT l.*, i.name as instance_name
                FROM {$this->table_logs} l
                LEFT JOIN {$this->table_instances} i ON l.instance_id = i.id
                WHERE {$where_clause}
                ORDER BY l.created_at DESC
                LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(function($log) {
            $log['details'] = json_decode($log['details'] ?? '{}', true) ?: [];
            return $log;
        }, $results ?: []);
    }

    /**
     * Delete old log entries
     *
     * @param int $days Days to retain
     * @return int Number of deleted records
     */
    public function delete_old_logs(int $days): int {
        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table_logs}
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        );

        return (int)$this->wpdb->query($sql);
    }

    // =========================================================================
    // Statistics
    // =========================================================================

    /**
     * Get submission statistics for an instance
     *
     * @param int|null $instance_id Instance ID (null for all)
     * @return array Statistics
     */
    public function get_statistics(?int $instance_id = null): array {
        $where = '';
        $values = [];

        if ($instance_id) {
            $where = 'WHERE instance_id = %d';
            $values[] = $instance_id;
        }

        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END) as abandoned
                FROM {$this->table_submissions}
                {$where}";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        $result = $this->wpdb->get_row($sql, ARRAY_A);

        return [
            'total' => (int)($result['total'] ?? 0),
            'completed' => (int)($result['completed'] ?? 0),
            'in_progress' => (int)($result['in_progress'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'abandoned' => (int)($result['abandoned'] ?? 0),
            'completion_rate' => $result['total'] > 0
                ? round(($result['completed'] / $result['total']) * 100, 1)
                : 0
        ];
    }

    // =========================================================================
    // Step Analytics
    // =========================================================================

    /**
     * Track a step event (enter, exit, complete, abandon)
     *
     * @param array $data Event data
     * @return int|false The new analytics record ID or false on failure
     */
    public function track_step_event(array $data): int|false {
        $insert_data = [
            'instance_id' => (int)$data['instance_id'],
            'submission_id' => $data['submission_id'] ?? null,
            'session_id' => $data['session_id'],
            'step' => (int)$data['step'],
            'step_name' => $data['step_name'] ?? null,
            'action' => $data['action'],
            'time_on_step' => (int)($data['time_on_step'] ?? 0),
            'device_type' => $data['device_type'] ?? null,
            'browser' => $data['browser'] ?? null,
            'is_mobile' => (int)($data['is_mobile'] ?? 0),
            'is_test' => (int)($data['is_test'] ?? 0),
            'referrer' => $data['referrer'] ?? null
        ];

        $result = $this->wpdb->insert($this->table_analytics, $insert_data);

        if ($result === false) {
            return false;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Get funnel analytics for an instance
     * Shows how many users reached each step and where they dropped off
     *
     * @param int|null $instance_id Instance ID (null for all)
     * @param string $date_from Start date (Y-m-d)
     * @param string $date_to End date (Y-m-d)
     * @param bool $exclude_test Exclude test data (default true)
     * @return array Funnel data
     */
    public function get_funnel_analytics(?int $instance_id = null, string $date_from = '', string $date_to = '', bool $exclude_test = true): array {
        $where = ['1=1'];
        $values = [];

        if ($exclude_test) {
            $where[] = 'is_test = 0';
        }

        if ($instance_id) {
            $where[] = 'instance_id = %d';
            $values[] = $instance_id;
        }

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        // Get unique sessions that entered each step
        $sql = "SELECT
                    step,
                    step_name,
                    COUNT(DISTINCT session_id) as sessions_entered,
                    COUNT(DISTINCT CASE WHEN action = 'complete' THEN session_id END) as sessions_completed,
                    AVG(CASE WHEN action = 'exit' AND time_on_step > 0 THEN time_on_step END) as avg_time_seconds
                FROM {$this->table_analytics}
                WHERE {$where_clause} AND action IN ('enter', 'complete', 'exit')
                GROUP BY step, step_name
                ORDER BY step ASC";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        // Calculate drop-off rates
        $funnel = [];
        $prev_entered = null;

        foreach ($results as $row) {
            $entered = (int)$row['sessions_entered'];
            $completed = (int)$row['sessions_completed'];

            $funnel[] = [
                'step' => (int)$row['step'],
                'step_name' => $row['step_name'] ?? 'Step ' . $row['step'],
                'sessions_entered' => $entered,
                'sessions_completed' => $completed,
                'completion_rate' => $entered > 0 ? round(($completed / $entered) * 100, 1) : 0,
                'drop_off_rate' => $prev_entered !== null && $prev_entered > 0
                    ? round((($prev_entered - $entered) / $prev_entered) * 100, 1)
                    : 0,
                'avg_time_seconds' => (int)($row['avg_time_seconds'] ?? 0),
                'avg_time_formatted' => $this->format_seconds((int)($row['avg_time_seconds'] ?? 0))
            ];

            $prev_entered = $entered;
        }

        return $funnel;
    }

    /**
     * Get step drop-off analysis
     * Shows where users are abandoning the form
     *
     * @param int|null $instance_id Instance ID (null for all)
     * @param string $date_from Start date
     * @param string $date_to End date
     * @param bool $exclude_test Exclude test data (default true)
     * @return array Drop-off data by step
     */
    public function get_dropoff_analysis(?int $instance_id = null, string $date_from = '', string $date_to = '', bool $exclude_test = true): array {
        $where = ['1=1'];
        $values = [];

        if ($exclude_test) {
            $where[] = 'a.is_test = 0';
        }

        if ($instance_id) {
            $where[] = 'a.instance_id = %d';
            $values[] = $instance_id;
        }

        if ($date_from) {
            $where[] = 'a.created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = 'a.created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        // Find sessions that abandoned at each step (last step they entered but didn't complete)
        $sql = "SELECT
                    last_step.step,
                    last_step.step_name,
                    COUNT(DISTINCT last_step.session_id) as abandoned_count
                FROM (
                    SELECT
                        a.session_id,
                        a.step,
                        a.step_name,
                        a.action
                    FROM {$this->table_analytics} a
                    INNER JOIN (
                        SELECT session_id, MAX(step) as max_step
                        FROM {$this->table_analytics}
                        WHERE {$where_clause}
                        GROUP BY session_id
                    ) max_steps ON a.session_id = max_steps.session_id AND a.step = max_steps.max_step
                    WHERE {$where_clause}
                    AND NOT EXISTS (
                        SELECT 1 FROM {$this->table_submissions} s
                        WHERE s.session_id = a.session_id AND s.status = 'completed'
                    )
                ) last_step
                GROUP BY last_step.step, last_step.step_name
                ORDER BY last_step.step ASC";

        // Double the values since WHERE clause appears twice
        $all_values = array_merge($values, $values);

        if (!empty($all_values)) {
            $sql = $this->wpdb->prepare($sql, ...$all_values);
        }

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get average time spent on each step
     *
     * @param int|null $instance_id Instance ID (null for all)
     * @param string $date_from Start date
     * @param string $date_to End date
     * @param bool $exclude_test Exclude test data (default true)
     * @return array Time data by step
     */
    public function get_step_timing_analytics(?int $instance_id = null, string $date_from = '', string $date_to = '', bool $exclude_test = true): array {
        $where = ['1=1', "action = 'exit'", 'time_on_step > 0'];
        $values = [];

        if ($exclude_test) {
            $where[] = 'is_test = 0';
        }

        if ($instance_id) {
            $where[] = 'instance_id = %d';
            $values[] = $instance_id;
        }

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT
                    step,
                    step_name,
                    AVG(time_on_step) as avg_time,
                    MIN(time_on_step) as min_time,
                    MAX(time_on_step) as max_time,
                    COUNT(*) as sample_size
                FROM {$this->table_analytics}
                WHERE {$where_clause}
                GROUP BY step, step_name
                ORDER BY step ASC";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(function($row) {
            return [
                'step' => (int)$row['step'],
                'step_name' => $row['step_name'] ?? 'Step ' . $row['step'],
                'avg_time_seconds' => (int)$row['avg_time'],
                'avg_time_formatted' => $this->format_seconds((int)$row['avg_time']),
                'min_time_seconds' => (int)$row['min_time'],
                'max_time_seconds' => (int)$row['max_time'],
                'sample_size' => (int)$row['sample_size']
            ];
        }, $results ?: []);
    }

    /**
     * Get device and browser analytics
     *
     * @param int|null $instance_id Instance ID (null for all)
     * @param string $date_from Start date
     * @param string $date_to End date
     * @param bool $exclude_test Exclude test data (default true)
     * @return array Device breakdown
     */
    public function get_device_analytics(?int $instance_id = null, string $date_from = '', string $date_to = '', bool $exclude_test = true): array {
        $where = ['1=1', "action = 'enter'", 'step = 1'];
        $values = [];

        if ($exclude_test) {
            $where[] = 'is_test = 0';
        }

        if ($instance_id) {
            $where[] = 'instance_id = %d';
            $values[] = $instance_id;
        }

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT
                    is_mobile,
                    browser,
                    COUNT(DISTINCT session_id) as sessions
                FROM {$this->table_analytics}
                WHERE {$where_clause}
                GROUP BY is_mobile, browser
                ORDER BY sessions DESC";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        $mobile_count = 0;
        $desktop_count = 0;
        $browsers = [];

        foreach ($results ?: [] as $row) {
            if ($row['is_mobile']) {
                $mobile_count += (int)$row['sessions'];
            } else {
                $desktop_count += (int)$row['sessions'];
            }

            $browser = $row['browser'] ?: 'Unknown';
            if (!isset($browsers[$browser])) {
                $browsers[$browser] = 0;
            }
            $browsers[$browser] += (int)$row['sessions'];
        }

        return [
            'mobile' => $mobile_count,
            'desktop' => $desktop_count,
            'mobile_percentage' => ($mobile_count + $desktop_count) > 0
                ? round(($mobile_count / ($mobile_count + $desktop_count)) * 100, 1)
                : 0,
            'browsers' => $browsers
        ];
    }

    /**
     * Get overall analytics summary
     *
     * @param int|null $instance_id Instance ID (null for all)
     * @param string $date_from Start date
     * @param string $date_to End date
     * @param bool $exclude_test Exclude test data (default true)
     * @return array Summary statistics
     */
    public function get_analytics_summary(?int $instance_id = null, string $date_from = '', string $date_to = '', bool $exclude_test = true): array {
        $where = ['1=1'];
        $values = [];

        if ($exclude_test) {
            $where[] = 'is_test = 0';
        }

        if ($instance_id) {
            $where[] = 'instance_id = %d';
            $values[] = $instance_id;
        }

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        // Total unique sessions that started
        $sql_started = "SELECT COUNT(DISTINCT session_id) FROM {$this->table_analytics}
                        WHERE {$where_clause} AND step = 1 AND action = 'enter'";

        // Total that completed (reached success)
        $sql_completed = "SELECT COUNT(DISTINCT a.session_id) FROM {$this->table_analytics} a
                          INNER JOIN {$this->table_submissions} s ON a.session_id = s.session_id
                          WHERE {$where_clause} AND s.status = 'completed'";

        // Average total time (sum of all step times per session)
        $sql_avg_time = "SELECT AVG(total_time) FROM (
                            SELECT session_id, SUM(time_on_step) as total_time
                            FROM {$this->table_analytics}
                            WHERE {$where_clause} AND action = 'exit' AND time_on_step > 0
                            GROUP BY session_id
                        ) t";

        if (!empty($values)) {
            $sql_started = $this->wpdb->prepare($sql_started, ...$values);
            $sql_completed = $this->wpdb->prepare($sql_completed, ...$values);
            $sql_avg_time = $this->wpdb->prepare($sql_avg_time, ...$values);
        }

        $total_started = (int)$this->wpdb->get_var($sql_started);
        $total_completed = (int)$this->wpdb->get_var($sql_completed);
        $avg_time = (int)$this->wpdb->get_var($sql_avg_time);

        return [
            'total_started' => $total_started,
            'total_completed' => $total_completed,
            'total_abandoned' => $total_started - $total_completed,
            'completion_rate' => $total_started > 0
                ? round(($total_completed / $total_started) * 100, 1)
                : 0,
            'abandonment_rate' => $total_started > 0
                ? round((($total_started - $total_completed) / $total_started) * 100, 1)
                : 0,
            'avg_completion_time_seconds' => $avg_time,
            'avg_completion_time_formatted' => $this->format_seconds($avg_time)
        ];
    }

    /**
     * Get daily analytics for charting
     *
     * @param int|null $instance_id Instance ID (null for all)
     * @param int $days Number of days to look back
     * @param bool $exclude_test Exclude test data (default true)
     * @return array Daily data
     */
    public function get_daily_analytics(?int $instance_id = null, int $days = 30, bool $exclude_test = true): array {
        $where = ['1=1'];
        $values = [];

        if ($exclude_test) {
            $where[] = 'a.is_test = 0';
        }

        if ($instance_id) {
            $where[] = 'a.instance_id = %d';
            $values[] = $instance_id;
        }

        $where[] = 'a.created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)';
        $values[] = $days;

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT
                    DATE(a.created_at) as date,
                    COUNT(DISTINCT CASE WHEN a.step = 1 AND a.action = 'enter' THEN a.session_id END) as started,
                    COUNT(DISTINCT CASE WHEN s.status = 'completed' THEN a.session_id END) as completed
                FROM {$this->table_analytics} a
                LEFT JOIN {$this->table_submissions} s ON a.session_id = s.session_id
                WHERE {$where_clause}
                GROUP BY DATE(a.created_at)
                ORDER BY date ASC";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Format seconds into human-readable time
     *
     * @param int $seconds Total seconds
     * @return string Formatted time (e.g., "2m 30s")
     */
    private function format_seconds(int $seconds): string {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = floor($seconds / 60);
        $remaining_seconds = $seconds % 60;

        if ($minutes < 60) {
            return $minutes . 'm ' . $remaining_seconds . 's';
        }

        $hours = floor($minutes / 60);
        $remaining_minutes = $minutes % 60;

        return $hours . 'h ' . $remaining_minutes . 'm';
    }

    /**
     * Delete old analytics records
     *
     * @param int $days Days to retain
     * @return int Number of deleted records
     */
    public function delete_old_analytics(int $days): int {
        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table_analytics}
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        );

        return (int)$this->wpdb->query($sql);
    }

    // =========================================================================
    // Attribution Analytics
    // =========================================================================

    /**
     * Get visitor touch summary for attribution
     *
     * @param int|null $instance_id Filter by instance
     * @param string $date_from Start date (Y-m-d)
     * @param string $date_to End date (Y-m-d)
     * @return array Touch summary by source/medium
     */
    public function get_touch_summary(?int $instance_id = null, string $date_from = '', string $date_to = ''): array {
        $touches_table = $this->wpdb->prefix . ISF_TABLE_TOUCHES;

        $where = ['1=1'];
        $values = [];

        if ($instance_id) {
            $where[] = 'instance_id = %d';
            $values[] = $instance_id;
        }

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT
                    COALESCE(utm_source, referrer_domain, 'direct') as source,
                    COALESCE(utm_medium, 'none') as medium,
                    COUNT(*) as touches,
                    COUNT(DISTINCT visitor_id) as unique_visitors,
                    COUNT(CASE WHEN touch_type = 'form_view' THEN 1 END) as form_views,
                    COUNT(CASE WHEN touch_type = 'form_start' THEN 1 END) as form_starts,
                    COUNT(CASE WHEN touch_type = 'form_complete' THEN 1 END) as completions
                FROM {$touches_table}
                WHERE {$where_clause}
                GROUP BY source, medium
                ORDER BY touches DESC";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get handoff statistics
     *
     * @param int|null $instance_id Filter by instance
     * @param string $date_from Start date (Y-m-d)
     * @param string $date_to End date (Y-m-d)
     * @return array Handoff stats
     */
    public function get_handoff_stats(?int $instance_id = null, string $date_from = '', string $date_to = ''): array {
        $handoffs_table = $this->wpdb->prefix . ISF_TABLE_HANDOFFS;

        $where = ['1=1'];
        $values = [];

        if ($instance_id) {
            $where[] = 'instance_id = %d';
            $values[] = $instance_id;
        }

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT
                    COUNT(*) as total_handoffs,
                    SUM(CASE WHEN status = 'redirected' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                    AVG(CASE WHEN status = 'completed' THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) as avg_completion_minutes
                FROM {$handoffs_table}
                WHERE {$where_clause}";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        $result = $this->wpdb->get_row($sql, ARRAY_A);

        return [
            'total_handoffs' => (int)($result['total_handoffs'] ?? 0),
            'pending' => (int)($result['pending'] ?? 0),
            'completed' => (int)($result['completed'] ?? 0),
            'expired' => (int)($result['expired'] ?? 0),
            'completion_rate' => $result['total_handoffs'] > 0
                ? round(($result['completed'] / $result['total_handoffs']) * 100, 1)
                : 0,
            'avg_completion_minutes' => round($result['avg_completion_minutes'] ?? 0, 1),
        ];
    }

    /**
     * Get visitor journeys with attribution data
     *
     * @param int|null $instance_id Filter by instance
     * @param string $date_from Start date (Y-m-d)
     * @param string $date_to End date (Y-m-d)
     * @param int $limit Max journeys to return
     * @return array Visitor journeys
     */
    public function get_visitor_journeys(?int $instance_id = null, string $date_from = '', string $date_to = '', int $limit = 100): array {
        $touches_table = $this->wpdb->prefix . ISF_TABLE_TOUCHES;
        $visitors_table = $this->wpdb->prefix . ISF_TABLE_VISITORS;

        $where = ['t.touch_type = %s'];
        $values = ['form_complete'];

        if ($instance_id) {
            $where[] = 't.instance_id = %d';
            $values[] = $instance_id;
        }

        if ($date_from) {
            $where[] = 't.created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = 't.created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $values[] = $limit;

        $where_clause = implode(' AND ', $where);

        $sql = $this->wpdb->prepare(
            "SELECT
                t.visitor_id,
                t.created_at as conversion_time,
                v.first_touch,
                v.visit_count,
                (SELECT COUNT(*) FROM {$touches_table} t2 WHERE t2.visitor_id = t.visitor_id AND t2.created_at <= t.created_at) as touch_count
             FROM {$touches_table} t
             LEFT JOIN {$visitors_table} v ON t.visitor_id = v.visitor_id
             WHERE {$where_clause}
             ORDER BY t.created_at DESC
             LIMIT %d",
            ...$values
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get top campaigns by conversions
     *
     * @param int|null $instance_id Filter by instance
     * @param string $date_from Start date (Y-m-d)
     * @param string $date_to End date (Y-m-d)
     * @param int $limit Max campaigns to return
     * @return array Campaign stats
     */
    public function get_top_campaigns(?int $instance_id = null, string $date_from = '', string $date_to = '', int $limit = 10): array {
        $touches_table = $this->wpdb->prefix . ISF_TABLE_TOUCHES;

        $where = ['utm_campaign IS NOT NULL', "utm_campaign != ''"];
        $values = [];

        if ($instance_id) {
            $where[] = 'instance_id = %d';
            $values[] = $instance_id;
        }

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $values[] = $limit;
        $where_clause = implode(' AND ', $where);

        $sql = $this->wpdb->prepare(
            "SELECT
                utm_campaign as campaign,
                utm_source as source,
                utm_medium as medium,
                COUNT(DISTINCT visitor_id) as unique_visitors,
                COUNT(CASE WHEN touch_type = 'form_complete' THEN 1 END) as conversions
             FROM {$touches_table}
             WHERE {$where_clause}
             GROUP BY utm_campaign, utm_source, utm_medium
             ORDER BY conversions DESC, unique_visitors DESC
             LIMIT %d",
            ...$values
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get external completions summary
     *
     * @param int|null $instance_id Filter by instance
     * @param string $date_from Start date (Y-m-d)
     * @param string $date_to End date (Y-m-d)
     * @return array Completion stats by source
     */
    public function get_external_completions_summary(?int $instance_id = null, string $date_from = '', string $date_to = ''): array {
        $completions_table = $this->wpdb->prefix . ISF_TABLE_EXTERNAL_COMPLETIONS;

        $where = ['1=1'];
        $values = [];

        if ($instance_id) {
            $where[] = 'instance_id = %d';
            $values[] = $instance_id;
        }

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT
                    source,
                    completion_type,
                    COUNT(*) as total,
                    SUM(CASE WHEN handoff_id IS NOT NULL THEN 1 ELSE 0 END) as matched,
                    SUM(CASE WHEN handoff_id IS NULL THEN 1 ELSE 0 END) as unmatched
                FROM {$completions_table}
                WHERE {$where_clause}
                GROUP BY source, completion_type
                ORDER BY total DESC";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    // =========================================================================
    // Test Data Management
    // =========================================================================

    /**
     * Mark submissions as test data
     *
     * @param array $ids Submission IDs to mark
     * @param bool $is_test Whether to mark as test (true) or production (false)
     * @return int Number of updated records
     */
    public function mark_submissions_as_test(array $ids, bool $is_test = true): int {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $sql = $this->wpdb->prepare(
            "UPDATE {$this->table_submissions}
             SET is_test = %d
             WHERE id IN ({$placeholders})",
            array_merge([$is_test ? 1 : 0], $ids)
        );

        // Also update related analytics records
        $session_sql = $this->wpdb->prepare(
            "SELECT session_id FROM {$this->table_submissions} WHERE id IN ({$placeholders})",
            $ids
        );
        $sessions = $this->wpdb->get_col($session_sql);

        if (!empty($sessions)) {
            $session_placeholders = implode(',', array_fill(0, count($sessions), '%s'));
            $analytics_sql = $this->wpdb->prepare(
                "UPDATE {$this->table_analytics}
                 SET is_test = %d
                 WHERE session_id IN ({$session_placeholders})",
                array_merge([$is_test ? 1 : 0], $sessions)
            );
            $this->wpdb->query($analytics_sql);
        }

        return (int)$this->wpdb->query($sql);
    }

    /**
     * Delete all test submissions and their analytics
     *
     * @param int|null $instance_id Optional: limit to specific instance
     * @return array Number of deleted submissions and analytics records
     */
    public function delete_test_data(?int $instance_id = null): array {
        // First get session IDs for test submissions
        $where = 'is_test = 1';
        $values = [];

        if ($instance_id) {
            $where .= ' AND instance_id = %d';
            $values[] = $instance_id;
        }

        $session_sql = "SELECT session_id FROM {$this->table_submissions} WHERE {$where}";
        if (!empty($values)) {
            $session_sql = $this->wpdb->prepare($session_sql, ...$values);
        }
        $sessions = $this->wpdb->get_col($session_sql);

        $analytics_deleted = 0;
        if (!empty($sessions)) {
            $placeholders = implode(',', array_fill(0, count($sessions), '%s'));
            $analytics_sql = $this->wpdb->prepare(
                "DELETE FROM {$this->table_analytics} WHERE session_id IN ({$placeholders})",
                $sessions
            );
            $analytics_deleted = (int)$this->wpdb->query($analytics_sql);
        }

        // Delete test submissions
        $delete_sql = "DELETE FROM {$this->table_submissions} WHERE {$where}";
        if (!empty($values)) {
            $delete_sql = $this->wpdb->prepare($delete_sql, ...$values);
        }
        $submissions_deleted = (int)$this->wpdb->query($delete_sql);

        return [
            'submissions' => $submissions_deleted,
            'analytics' => $analytics_deleted
        ];
    }

    /**
     * Get test data counts
     *
     * @param int|null $instance_id Optional: limit to specific instance
     * @return array Counts of test submissions and analytics
     */
    public function get_test_data_counts(?int $instance_id = null): array {
        $where = 'is_test = 1';
        $values = [];

        if ($instance_id) {
            $where .= ' AND instance_id = %d';
            $values[] = $instance_id;
        }

        $sub_sql = "SELECT COUNT(*) FROM {$this->table_submissions} WHERE {$where}";
        $ana_sql = "SELECT COUNT(*) FROM {$this->table_analytics} WHERE {$where}";

        if (!empty($values)) {
            $sub_sql = $this->wpdb->prepare($sub_sql, ...$values);
            $ana_sql = $this->wpdb->prepare($ana_sql, ...$values);
        }

        return [
            'submissions' => (int)$this->wpdb->get_var($sub_sql),
            'analytics' => (int)$this->wpdb->get_var($ana_sql)
        ];
    }

    /**
     * Auto-mark demo mode submissions as test data
     *
     * @param string $session_id Session ID
     * @return void
     */
    public function mark_session_as_test(string $session_id): void {
        $this->wpdb->update(
            $this->table_submissions,
            ['is_test' => 1],
            ['session_id' => $session_id]
        );

        $this->wpdb->update(
            $this->table_analytics,
            ['is_test' => 1],
            ['session_id' => $session_id]
        );
    }

    /**
     * Delete submissions by IDs
     *
     * @param array $ids Array of submission IDs to delete
     * @return int Number of deleted submissions
     */
    public function delete_submissions(array $ids): int {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // First get session IDs for related analytics cleanup
        $session_sql = $this->wpdb->prepare(
            "SELECT session_id FROM {$this->table_submissions} WHERE id IN ({$placeholders})",
            $ids
        );
        $sessions = $this->wpdb->get_col($session_sql);

        // Delete related analytics
        if (!empty($sessions)) {
            $session_placeholders = implode(',', array_fill(0, count($sessions), '%s'));
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->table_analytics} WHERE session_id IN ({$session_placeholders})",
                $sessions
            ));
        }

        // Delete related logs
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table_logs} WHERE submission_id IN ({$placeholders})",
            $ids
        ));

        // Delete submissions
        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table_submissions} WHERE id IN ({$placeholders})",
            $ids
        );

        return (int)$this->wpdb->query($sql);
    }

    /**
     * Delete logs by IDs
     *
     * @param array $ids Array of log IDs to delete
     * @return int Number of deleted logs
     */
    public function delete_logs(array $ids): int {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table_logs} WHERE id IN ({$placeholders})",
            $ids
        );

        return (int)$this->wpdb->query($sql);
    }

    /**
     * Clear all analytics data
     *
     * @param int|null $instance_id Optional: limit to specific instance
     * @return int Number of deleted records
     */
    public function clear_analytics(?int $instance_id = null): int {
        if ($instance_id) {
            $sql = $this->wpdb->prepare(
                "DELETE FROM {$this->table_analytics} WHERE instance_id = %d",
                $instance_id
            );
        } else {
            $sql = "TRUNCATE TABLE {$this->table_analytics}";
        }

        return (int)$this->wpdb->query($sql);
    }

    // ========================================
    // Retry Queue Methods
    // ========================================

    /**
     * Add a failed submission to the retry queue
     *
     * @param int $submission_id Submission ID
     * @param int $instance_id Instance ID
     * @param string $error Error message
     * @param int $max_retries Maximum retry attempts
     * @return int|false Queue entry ID or false on failure
     */
    public function add_to_retry_queue(int $submission_id, int $instance_id, string $error, int $max_retries = 3): int|false {
        $table = $this->wpdb->prefix . 'isf_retry_queue';

        // Calculate next retry time with exponential backoff (5 min, 15 min, 45 min)
        $next_retry = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $result = $this->wpdb->insert($table, [
            'submission_id' => $submission_id,
            'instance_id' => $instance_id,
            'last_error' => $error,
            'max_retries' => $max_retries,
            'next_retry_at' => $next_retry,
            'status' => 'pending',
        ]);

        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Get pending retry items that are due for processing
     *
     * @param int $limit Maximum items to retrieve
     * @return array List of retry queue items
     */
    public function get_pending_retries(int $limit = 10): array {
        $table = $this->wpdb->prefix . 'isf_retry_queue';

        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'pending'
             AND next_retry_at <= NOW()
             ORDER BY next_retry_at ASC
             LIMIT %d",
            $limit
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Update retry queue item status
     *
     * @param int $queue_id Queue entry ID
     * @param string $status New status
     * @param string|null $error Optional error message
     * @return bool Success
     */
    public function update_retry_status(int $queue_id, string $status, ?string $error = null): bool {
        $table = $this->wpdb->prefix . 'isf_retry_queue';

        $data = ['status' => $status];
        if ($error !== null) {
            $data['last_error'] = $error;
        }

        return $this->wpdb->update($table, $data, ['id' => $queue_id]) !== false;
    }

    /**
     * Increment retry count and schedule next retry
     *
     * @param int $queue_id Queue entry ID
     * @param string $error Error message
     * @return bool Success (false if max retries exceeded)
     */
    public function increment_retry(int $queue_id, string $error): bool {
        $table = $this->wpdb->prefix . 'isf_retry_queue';

        // Get current retry count
        $item = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $queue_id),
            ARRAY_A
        );

        if (!$item) {
            return false;
        }

        $new_count = (int)$item['retry_count'] + 1;

        if ($new_count >= (int)$item['max_retries']) {
            // Max retries exceeded - mark as failed
            return $this->update_retry_status($queue_id, 'failed', $error);
        }

        // Calculate next retry with exponential backoff
        $delay_minutes = 5 * pow(3, $new_count); // 5, 15, 45 minutes
        $next_retry = date('Y-m-d H:i:s', strtotime("+{$delay_minutes} minutes"));

        return $this->wpdb->update(
            $table,
            [
                'retry_count' => $new_count,
                'last_error' => $error,
                'next_retry_at' => $next_retry,
                'status' => 'pending',
            ],
            ['id' => $queue_id]
        ) !== false;
    }

    /**
     * Get retry queue statistics
     *
     * @param int|null $instance_id Optional: limit to specific instance
     * @return array Queue statistics
     */
    public function get_retry_queue_stats(?int $instance_id = null): array {
        $table = $this->wpdb->prefix . 'isf_retry_queue';

        $where = $instance_id ? $this->wpdb->prepare(' WHERE instance_id = %d', $instance_id) : '';

        $sql = "SELECT status, COUNT(*) as count FROM {$table}{$where} GROUP BY status";
        $results = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'total' => 0,
        ];

        foreach ($results as $row) {
            $stats[$row['status']] = (int)$row['count'];
            $stats['total'] += (int)$row['count'];
        }

        return $stats;
    }

    // ========================================
    // Webhook Methods
    // ========================================

    /**
     * Create a new webhook
     *
     * @param array $data Webhook data
     * @return int|false Webhook ID or false on failure
     */
    public function create_webhook(array $data): int|false {
        $table = $this->wpdb->prefix . 'isf_webhooks';

        $result = $this->wpdb->insert($table, [
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'],
            'url' => $data['url'],
            'events' => is_array($data['events']) ? json_encode($data['events']) : $data['events'],
            'secret' => $data['secret'] ?? null,
            'is_active' => $data['is_active'] ?? 1,
        ]);

        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Get all webhooks for an instance (or all if null)
     *
     * @param int|null $instance_id Instance ID
     * @param bool $active_only Only return active webhooks
     * @return array List of webhooks
     */
    public function get_webhooks(?int $instance_id = null, bool $active_only = false): array {
        $table = $this->wpdb->prefix . 'isf_webhooks';

        $where = [];
        $values = [];

        if ($instance_id !== null) {
            $where[] = '(instance_id = %d OR instance_id IS NULL)';
            $values[] = $instance_id;
        }

        if ($active_only) {
            $where[] = 'is_active = 1';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM {$table} {$where_clause} ORDER BY name ASC";

        if (!empty($values)) {
            $sql = $this->wpdb->prepare($sql, ...$values);
        }

        $results = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        // Decode events JSON
        foreach ($results as &$row) {
            $row['events'] = json_decode($row['events'], true) ?: [];
        }

        return $results;
    }

    /**
     * Get webhooks for a specific event
     *
     * @param string $event Event name
     * @param int|null $instance_id Instance ID
     * @return array List of matching webhooks
     */
    public function get_webhooks_for_event(string $event, ?int $instance_id = null): array {
        $webhooks = $this->get_webhooks($instance_id, true);

        return array_filter($webhooks, function($webhook) use ($event) {
            return in_array($event, $webhook['events']) || in_array('*', $webhook['events']);
        });
    }

    /**
     * Update webhook last triggered time
     *
     * @param int $webhook_id Webhook ID
     * @param bool $success Whether the trigger was successful
     * @return bool Success
     */
    public function update_webhook_triggered(int $webhook_id, bool $success = true): bool {
        $table = $this->wpdb->prefix . 'isf_webhooks';

        $data = ['last_triggered_at' => current_time('mysql')];

        if (!$success) {
            $data['failure_count'] = new \stdClass(); // Will use raw SQL
            return $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE {$table} SET last_triggered_at = %s, failure_count = failure_count + 1 WHERE id = %d",
                    current_time('mysql'),
                    $webhook_id
                )
            ) !== false;
        }

        return $this->wpdb->update($table, $data, ['id' => $webhook_id]) !== false;
    }

    /**
     * Update a webhook
     *
     * @param int $webhook_id Webhook ID
     * @param array $data Webhook data to update
     * @return bool Success
     */
    public function update_webhook(int $webhook_id, array $data): bool {
        $table = $this->wpdb->prefix . 'isf_webhooks';

        $update_data = [];

        if (isset($data['name'])) {
            $update_data['name'] = $data['name'];
        }
        if (isset($data['url'])) {
            $update_data['url'] = $data['url'];
        }
        if (array_key_exists('instance_id', $data)) {
            $update_data['instance_id'] = $data['instance_id'];
        }
        if (isset($data['events'])) {
            $update_data['events'] = json_encode($data['events']);
        }
        if (array_key_exists('secret', $data)) {
            $update_data['secret'] = $data['secret'];
        }
        if (isset($data['is_active'])) {
            $update_data['is_active'] = $data['is_active'] ? 1 : 0;
        }

        if (empty($update_data)) {
            return false;
        }

        return $this->wpdb->update($table, $update_data, ['id' => $webhook_id]) !== false;
    }

    /**
     * Delete a webhook
     *
     * @param int $webhook_id Webhook ID
     * @return bool Success
     */
    public function delete_webhook(int $webhook_id): bool {
        $table = $this->wpdb->prefix . 'isf_webhooks';
        return $this->wpdb->delete($table, ['id' => $webhook_id]) !== false;
    }

    // =========================================================================
    // API Usage Tracking Methods
    // =========================================================================

    /**
     * Log an API call for rate limit monitoring
     *
     * @param int $instance_id Instance ID
     * @param string $endpoint API endpoint called
     * @param string $method HTTP method or API method name
     * @param int|null $status_code HTTP status code
     * @param int|null $response_time_ms Response time in milliseconds
     * @param bool $success Whether call was successful
     * @param string|null $error_message Error message if failed
     * @return int|false Insert ID or false on failure
     */
    public function log_api_call(
        int $instance_id,
        string $endpoint,
        string $method,
        ?int $status_code = null,
        ?int $response_time_ms = null,
        bool $success = true,
        ?string $error_message = null
    ): int|false {
        $table = $this->wpdb->prefix . 'isf_api_usage';

        $result = $this->wpdb->insert($table, [
            'instance_id' => $instance_id,
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $status_code,
            'response_time_ms' => $response_time_ms,
            'success' => $success ? 1 : 0,
            'error_message' => $error_message,
        ]);

        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Get API usage statistics
     *
     * @param int|null $instance_id Filter by instance ID
     * @param string $period Time period: 'hour', 'day', 'week', 'month'
     * @return array API usage statistics
     */
    public function get_api_usage_stats(?int $instance_id = null, string $period = 'day'): array {
        $table = $this->wpdb->prefix . 'isf_api_usage';

        $interval = match ($period) {
            'hour' => 'INTERVAL 1 HOUR',
            'day' => 'INTERVAL 24 HOUR',
            'week' => 'INTERVAL 7 DAY',
            'month' => 'INTERVAL 30 DAY',
            default => 'INTERVAL 24 HOUR',
        };

        $where = "WHERE created_at >= DATE_SUB(NOW(), {$interval})";
        if ($instance_id) {
            $where .= $this->wpdb->prepare(" AND instance_id = %d", $instance_id);
        }

        // Total calls
        $total = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");

        // Successful calls
        $successful = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where} AND success = 1");

        // Failed calls
        $failed = $total - $successful;

        // Average response time
        $avg_response = (float)$this->wpdb->get_var(
            "SELECT AVG(response_time_ms) FROM {$table} {$where} AND response_time_ms IS NOT NULL"
        );

        // Calls per endpoint
        $by_endpoint = $this->wpdb->get_results(
            "SELECT endpoint, COUNT(*) as count, AVG(response_time_ms) as avg_response,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count
             FROM {$table} {$where}
             GROUP BY endpoint
             ORDER BY count DESC",
            ARRAY_A
        );

        // Calls per hour (for chart)
        $hourly = $this->wpdb->get_results(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour, COUNT(*) as count
             FROM {$table} {$where}
             GROUP BY hour
             ORDER BY hour ASC",
            ARRAY_A
        );

        // Error breakdown
        $errors = $this->wpdb->get_results(
            "SELECT error_message, COUNT(*) as count
             FROM {$table} {$where} AND success = 0 AND error_message IS NOT NULL
             GROUP BY error_message
             ORDER BY count DESC
             LIMIT 10",
            ARRAY_A
        );

        return [
            'total_calls' => $total,
            'successful_calls' => $successful,
            'failed_calls' => $failed,
            'success_rate' => $total > 0 ? round(($successful / $total) * 100, 1) : 0,
            'avg_response_ms' => round($avg_response, 0),
            'by_endpoint' => $by_endpoint,
            'hourly' => $hourly,
            'errors' => $errors,
            'period' => $period,
        ];
    }

    /**
     * Get calls per minute for rate limiting check
     *
     * @param int $instance_id Instance ID
     * @param int $minutes Time window in minutes
     * @return int Number of calls
     */
    public function get_api_calls_count(int $instance_id, int $minutes = 1): int {
        $table = $this->wpdb->prefix . 'isf_api_usage';

        return (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE instance_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)",
            $instance_id,
            $minutes
        ));
    }

    /**
     * Clean up old API usage records
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted rows
     */
    public function cleanup_api_usage(int $days = 30): int {
        $table = $this->wpdb->prefix . 'isf_api_usage';

        return (int)$this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    /**
     * Get rate limit status for an instance
     *
     * @param int $instance_id Instance ID
     * @param int $limit Calls per minute limit
     * @return array Rate limit status
     */
    public function get_rate_limit_status(int $instance_id, int $limit = 60): array {
        $calls_per_minute = $this->get_api_calls_count($instance_id, 1);
        $calls_last_5_min = $this->get_api_calls_count($instance_id, 5);

        return [
            'calls_per_minute' => $calls_per_minute,
            'calls_last_5_min' => $calls_last_5_min,
            'avg_per_minute' => round($calls_last_5_min / 5, 1),
            'limit' => $limit,
            'usage_percent' => round(($calls_per_minute / $limit) * 100, 1),
            'status' => $calls_per_minute >= $limit ? 'exceeded' : ($calls_per_minute >= ($limit * 0.8) ? 'warning' : 'ok'),
        ];
    }

    // =========================================================================
    // Resume Token Methods
    // =========================================================================

    /**
     * Save a resume token for "save and continue later" feature
     *
     * @param string $session_id Session ID
     * @param int $instance_id Instance ID
     * @param string $token Resume token
     * @param string $email User's email
     * @param string $expires_at Expiration datetime
     * @return bool Success
     */
    public function save_resume_token(string $session_id, int $instance_id, string $token, string $email, string $expires_at): bool {
        $table = $this->wpdb->prefix . 'isf_resume_tokens';

        // First, delete any existing tokens for this session
        $this->wpdb->delete($table, ['session_id' => $session_id, 'instance_id' => $instance_id]);

        $result = $this->wpdb->insert($table, [
            'session_id' => $session_id,
            'instance_id' => $instance_id,
            'token' => $token,
            'email' => $email,
            'expires_at' => $expires_at,
        ]);

        return $result !== false;
    }

    /**
     * Get resume token data
     *
     * @param string $token Resume token
     * @param int $instance_id Instance ID
     * @return array|null Token data or null if not found/expired
     */
    public function get_resume_token(string $token, int $instance_id): ?array {
        $table = $this->wpdb->prefix . 'isf_resume_tokens';

        $result = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE token = %s AND instance_id = %d AND expires_at > NOW() AND used_at IS NULL",
            $token,
            $instance_id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Mark resume token as used
     *
     * @param string $token Resume token
     * @return bool Success
     */
    public function mark_resume_token_used(string $token): bool {
        $table = $this->wpdb->prefix . 'isf_resume_tokens';

        return $this->wpdb->update(
            $table,
            ['used_at' => current_time('mysql')],
            ['token' => $token]
        ) !== false;
    }

    /**
     * Clean up expired resume tokens
     *
     * @return int Number of deleted tokens
     */
    public function cleanup_expired_resume_tokens(): int {
        $table = $this->wpdb->prefix . 'isf_resume_tokens';

        return (int)$this->wpdb->query(
            "DELETE FROM {$table} WHERE expires_at < NOW() OR used_at IS NOT NULL"
        );
    }

    // =========================================================================
    // Scheduled Reports Methods
    // =========================================================================

    /**
     * Create a scheduled report
     *
     * @param array $data Report data
     * @return int|false Report ID or false on failure
     */
    public function create_scheduled_report(array $data): int|false {
        $table = $this->wpdb->prefix . 'isf_scheduled_reports';

        $result = $this->wpdb->insert($table, [
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'recipients' => is_array($data['recipients']) ? json_encode($data['recipients']) : $data['recipients'],
            'instance_id' => $data['instance_id'] ?: null,
            'settings' => is_array($data['settings']) ? json_encode($data['settings']) : ($data['settings'] ?? '{}'),
            'is_active' => $data['is_active'] ?? 1,
        ]);

        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Update a scheduled report
     *
     * @param int $id Report ID
     * @param array $data Report data
     * @return bool Success
     */
    public function update_scheduled_report(int $id, array $data): bool {
        $table = $this->wpdb->prefix . 'isf_scheduled_reports';

        $update_data = [];

        if (isset($data['name'])) {
            $update_data['name'] = $data['name'];
        }
        if (isset($data['frequency'])) {
            $update_data['frequency'] = $data['frequency'];
        }
        if (isset($data['recipients'])) {
            $update_data['recipients'] = is_array($data['recipients']) ? json_encode($data['recipients']) : $data['recipients'];
        }
        if (array_key_exists('instance_id', $data)) {
            $update_data['instance_id'] = $data['instance_id'] ?: null;
        }
        if (isset($data['settings'])) {
            $update_data['settings'] = is_array($data['settings']) ? json_encode($data['settings']) : $data['settings'];
        }
        if (isset($data['is_active'])) {
            $update_data['is_active'] = $data['is_active'] ? 1 : 0;
        }

        if (empty($update_data)) {
            return false;
        }

        return $this->wpdb->update($table, $update_data, ['id' => $id]) !== false;
    }

    /**
     * Delete a scheduled report
     *
     * @param int $id Report ID
     * @return bool Success
     */
    public function delete_scheduled_report(int $id): bool {
        $table = $this->wpdb->prefix . 'isf_scheduled_reports';
        return $this->wpdb->delete($table, ['id' => $id]) !== false;
    }

    /**
     * Get all scheduled reports
     *
     * @param bool $active_only Only return active reports
     * @return array List of reports
     */
    public function get_scheduled_reports(bool $active_only = false): array {
        $table = $this->wpdb->prefix . 'isf_scheduled_reports';

        $where = $active_only ? 'WHERE is_active = 1' : '';
        $sql = "SELECT * FROM {$table} {$where} ORDER BY name ASC";

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get a single scheduled report
     *
     * @param int $id Report ID
     * @return array|null Report data or null
     */
    public function get_scheduled_report(int $id): ?array {
        $table = $this->wpdb->prefix . 'isf_scheduled_reports';

        $result = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        if ($result) {
            $result['recipients'] = json_decode($result['recipients'], true) ?: [];
            $result['settings'] = json_decode($result['settings'], true) ?: [];
        }

        return $result ?: null;
    }

    /**
     * Get reports due to be sent
     *
     * @param string|null $frequency Frequency type (daily, weekly, monthly) or null for all
     * @return array List of due reports
     */
    public function get_due_reports(?string $frequency = null): array {
        $table = $this->wpdb->prefix . 'isf_scheduled_reports';

        if ($frequency) {
            $sql = $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE is_active = 1 AND frequency = %s",
                $frequency
            );
        } else {
            // Get all due reports when no frequency specified.
            // class-plugin.php::send_scheduled_reports() calls this with no
            // arguments — wp-cron previously fatal'd here every hour.
            $sql = "SELECT * FROM {$table} WHERE is_active = 1";
        }

        $results = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        foreach ($results as &$result) {
            $result['recipients'] = json_decode($result['recipients'], true) ?: [];
            $result['settings'] = json_decode($result['settings'], true) ?: [];
        }

        return $results;
    }

    /**
     * Update report last sent timestamp
     *
     * @param int $id Report ID
     * @return bool Success
     */
    public function update_report_sent(int $id): bool {
        $table = $this->wpdb->prefix . 'isf_scheduled_reports';

        return $this->wpdb->update(
            $table,
            ['last_sent_at' => current_time('mysql')],
            ['id' => $id]
        ) !== false;
    }

    /**
     * Get analytics data for reporting
     *
     * @param string $date_from Start date
     * @param string $date_to End date
     * @param int|null $instance_id Instance ID filter
     * @param bool $include_test Include test data
     * @return array Analytics data
     */
    public function get_report_analytics(string $date_from, string $date_to, ?int $instance_id = null, bool $include_test = false): array {
        $test_clause = $include_test ? '' : 'AND is_test = 0';
        $instance_clause = $instance_id ? $this->wpdb->prepare(' AND instance_id = %d', $instance_id) : '';

        // Summary stats
        $summary = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN step = 1 AND action = 'enter' THEN session_id END) as total_started,
                COUNT(DISTINCT CASE WHEN action = 'complete' THEN session_id END) as total_completed,
                COUNT(DISTINCT CASE WHEN action = 'abandon' THEN session_id END) as total_abandoned
             FROM {$this->table_analytics}
             WHERE created_at BETWEEN %s AND %s {$instance_clause} {$test_clause}",
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59'
        ), ARRAY_A);

        $summary['completion_rate'] = $summary['total_started'] > 0
            ? round(($summary['total_completed'] / $summary['total_started']) * 100, 1)
            : 0;

        // Daily breakdown
        $daily = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT
                DATE(created_at) as date,
                COUNT(DISTINCT CASE WHEN step = 1 AND action = 'enter' THEN session_id END) as started,
                COUNT(DISTINCT CASE WHEN action = 'complete' THEN session_id END) as completed
             FROM {$this->table_analytics}
             WHERE created_at BETWEEN %s AND %s {$instance_clause} {$test_clause}
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59'
        ), ARRAY_A);

        // Funnel
        $funnel = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT
                step,
                step_name,
                COUNT(DISTINCT CASE WHEN action = 'enter' THEN session_id END) as sessions_entered
             FROM {$this->table_analytics}
             WHERE created_at BETWEEN %s AND %s {$instance_clause} {$test_clause}
             GROUP BY step, step_name
             ORDER BY step ASC",
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59'
        ), ARRAY_A);

        return [
            'summary' => $summary,
            'daily' => $daily,
            'funnel' => $funnel,
        ];
    }

    /**
     * Export analytics data as array for CSV
     *
     * @param string $date_from Start date
     * @param string $date_to End date
     * @param int|null $instance_id Instance ID filter
     * @return array Raw analytics records
     */
    public function get_analytics_for_export(string $date_from, string $date_to, ?int $instance_id = null): array {
        $instance_clause = $instance_id ? $this->wpdb->prepare(' AND a.instance_id = %d', $instance_id) : '';

        $sql = $this->wpdb->prepare(
            "SELECT
                a.id,
                i.name as form_name,
                a.session_id,
                a.step,
                a.step_name,
                a.action,
                a.time_on_step,
                a.device_type,
                a.browser,
                a.is_mobile,
                a.is_test,
                a.referrer,
                a.created_at
             FROM {$this->table_analytics} a
             LEFT JOIN {$this->table_instances} i ON a.instance_id = i.id
             WHERE a.created_at BETWEEN %s AND %s {$instance_clause}
             ORDER BY a.created_at ASC",
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59'
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    // =========================================================================
    // Audit Log Methods
    // =========================================================================

    /**
     * Log an admin action
     *
     * @param string $action Action performed (e.g., 'delete_submission', 'update_instance')
     * @param string $object_type Type of object (e.g., 'submission', 'instance', 'settings')
     * @param int|null $object_id ID of the object
     * @param string|null $object_name Name/description of the object
     * @param array $details Additional details
     * @return int|false Audit log ID or false on failure
     */
    public function log_audit(
        string $action,
        string $object_type,
        ?int $object_id = null,
        ?string $object_name = null,
        array $details = []
    ): int|false {
        $table = $this->wpdb->prefix . 'isf_audit_log';

        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return false;
        }

        $result = $this->wpdb->insert($table, [
            'user_id' => $user->ID,
            'user_login' => $user->user_login,
            'user_email' => $user->user_email,
            'action' => $action,
            'object_type' => $object_type,
            'object_id' => $object_id,
            'object_name' => $object_name,
            'details' => !empty($details) ? json_encode($details) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);

        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Get audit log entries
     *
     * @param array $filters Filters (user_id, action, object_type, date_from, date_to)
     * @param int $limit Max results
     * @param int $offset Offset
     * @return array List of audit log entries
     */
    public function get_audit_log(array $filters = [], int $limit = 100, int $offset = 0): array {
        $table = $this->wpdb->prefix . 'isf_audit_log';

        $where = ['1=1'];
        $values = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $values[] = (int)$filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'action = %s';
            $values[] = $filters['action'];
        }

        if (!empty($filters['object_type'])) {
            $where[] = 'object_type = %s';
            $values[] = $filters['object_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $values[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $values[] = $filters['date_to'] . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);
        $values[] = $limit;
        $values[] = $offset;

        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$values
        );

        $results = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        foreach ($results as &$row) {
            $row['details'] = $row['details'] ? json_decode($row['details'], true) : [];
        }

        return $results;
    }

    /**
     * Get audit log count
     *
     * @param array $filters Same filters as get_audit_log
     * @return int Count
     */
    public function get_audit_log_count(array $filters = []): int {
        $table = $this->wpdb->prefix . 'isf_audit_log';

        $where = ['1=1'];
        $values = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $values[] = (int)$filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'action = %s';
            $values[] = $filters['action'];
        }

        if (!empty($filters['object_type'])) {
            $where[] = 'object_type = %s';
            $values[] = $filters['object_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $values[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $values[] = $filters['date_to'] . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        if (!empty($values)) {
            $sql = $this->wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", ...$values);
        } else {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
        }

        return (int)$this->wpdb->get_var($sql);
    }

    /**
     * Delete old audit log entries
     *
     * @param int $days Days to retain
     * @return int Number deleted
     */
    public function delete_old_audit_logs(int $days): int {
        $table = $this->wpdb->prefix . 'isf_audit_log';

        return (int)$this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    // =========================================================================
    // GDPR Request Methods
    // =========================================================================

    /**
     * Create a GDPR request
     *
     * @param array $data Request data with keys: request_type, email, account_number, request_data, result_data, status, notes
     * @return int|false Request ID or false
     */
    public function create_gdpr_request(array $data): int|false {
        $table = $this->wpdb->prefix . 'isf_gdpr_requests';

        $user = wp_get_current_user();

        $insert_data = [
            'request_type' => $data['request_type'] ?? 'export',
            'email' => $data['email'] ?? '',
            'account_number' => $data['account_number'] ?? null,
            'requested_by' => $user ? $user->ID : null,
            'request_data' => !empty($data['request_data']) ? json_encode($data['request_data']) : null,
            'status' => $data['status'] ?? 'pending',
        ];

        // If already completed, set result_data and processed info
        if (($data['status'] ?? '') === 'completed') {
            $insert_data['processed_by'] = $user ? $user->ID : null;
            $insert_data['processed_at'] = current_time('mysql');
            if (!empty($data['result_data'])) {
                $insert_data['result_data'] = json_encode($data['result_data']);
            }
        }

        if (!empty($data['notes'])) {
            $insert_data['notes'] = $data['notes'];
        }

        $result = $this->wpdb->insert($table, $insert_data);

        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Get GDPR requests
     *
     * @param array $filters Filters (status, type, email)
     * @param int $limit Limit
     * @param int $offset Offset
     * @return array List of requests
     */
    public function get_gdpr_requests(array $filters = [], int $limit = 50, int $offset = 0): array {
        $table = $this->wpdb->prefix . 'isf_gdpr_requests';

        $where = ['1=1'];
        $values = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $values[] = $filters['status'];
        }

        if (!empty($filters['request_type'])) {
            $where[] = 'request_type = %s';
            $values[] = $filters['request_type'];
        }

        if (!empty($filters['email'])) {
            $where[] = 'email LIKE %s';
            $values[] = '%' . $this->wpdb->esc_like($filters['email']) . '%';
        }

        $where_clause = implode(' AND ', $where);
        $values[] = $limit;
        $values[] = $offset;

        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$values
        );

        $results = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        foreach ($results as &$row) {
            $row['request_data'] = $row['request_data'] ? json_decode($row['request_data'], true) : [];
            $row['result_data'] = $row['result_data'] ? json_decode($row['result_data'], true) : [];
        }

        return $results;
    }

    /**
     * Get GDPR requests count
     *
     * @param array $filters Same filters as get_gdpr_requests
     * @return int Count
     */
    public function get_gdpr_requests_count(array $filters = []): int {
        $table = $this->wpdb->prefix . 'isf_gdpr_requests';

        $where = ['1=1'];
        $values = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $values[] = $filters['status'];
        }

        if (!empty($filters['request_type'])) {
            $where[] = 'request_type = %s';
            $values[] = $filters['request_type'];
        }

        if (!empty($filters['email'])) {
            $where[] = 'email LIKE %s';
            $values[] = '%' . $this->wpdb->esc_like($filters['email']) . '%';
        }

        $where_clause = implode(' AND ', $where);

        if (!empty($values)) {
            $sql = $this->wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", ...$values);
        } else {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
        }

        return (int)$this->wpdb->get_var($sql);
    }

    /**
     * Update GDPR request status
     *
     * @param int $request_id Request ID
     * @param string $status New status
     * @param array|null $result_data Result data
     * @param string|null $notes Notes
     * @return bool Success
     */
    public function update_gdpr_request(
        int $request_id,
        string $status,
        ?array $result_data = null,
        ?string $notes = null
    ): bool {
        $table = $this->wpdb->prefix . 'isf_gdpr_requests';

        $user = wp_get_current_user();

        $data = [
            'status' => $status,
            'processed_by' => $user ? $user->ID : null,
        ];

        if ($status === 'completed' || $status === 'failed') {
            $data['processed_at'] = current_time('mysql');
        }

        if ($result_data !== null) {
            $data['result_data'] = json_encode($result_data);
        }

        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        return $this->wpdb->update($table, $data, ['id' => $request_id]) !== false;
    }

    /**
     * Find submissions by email or account number for GDPR
     *
     * @param string $email Email address
     * @param string|null $account_number Account number
     * @return array Matching submissions (decrypted)
     */
    public function find_submissions_for_gdpr(string $email, ?string $account_number = null): array {
        $submissions = [];

        // Get all submissions and check decrypted data
        $sql = "SELECT * FROM {$this->table_submissions} ORDER BY created_at DESC";
        $all_submissions = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        foreach ($all_submissions as $sub) {
            $form_data = $this->encryption->decrypt_array($sub['form_data'] ?? '');

            $matches = false;

            // Check email
            if (!empty($form_data['email']) && strtolower($form_data['email']) === strtolower($email)) {
                $matches = true;
            }

            // Check account number
            if ($account_number && !empty($form_data['account_number'])) {
                if ($form_data['account_number'] === $account_number) {
                    $matches = true;
                }
            }

            if ($matches) {
                $sub['form_data'] = $form_data;
                $sub['api_response'] = $this->encryption->decrypt_array($sub['api_response'] ?? '');
                $submissions[] = $sub;
            }
        }

        return $submissions;
    }

    /**
     * Anonymize a submission for GDPR
     *
     * @param int $submission_id Submission ID
     * @return bool Success
     */
    public function anonymize_submission(int $submission_id): bool {
        $submission = $this->get_submission($submission_id);
        if (!$submission) {
            return false;
        }

        $form_data = $submission['form_data'];

        // Anonymize PII fields
        $pii_fields = [
            'first_name', 'last_name', 'email', 'phone', 'phone_alt',
            'address', 'address2', 'city', 'zip',
            'account_number', 'utility_no', 'comverge_no',
        ];

        foreach ($pii_fields as $field) {
            if (isset($form_data[$field])) {
                $form_data[$field] = '[REDACTED]';
            }
        }

        // Mark as anonymized
        $form_data['_anonymized'] = true;
        $form_data['_anonymized_at'] = current_time('mysql');

        return $this->wpdb->update(
            $this->table_submissions,
            [
                'customer_name' => '[REDACTED]',
                'account_number' => '[REDACTED]',
                'form_data' => $this->encryption->encrypt_array($form_data),
                'api_response' => null,
                'ip_address' => null,
            ],
            ['id' => $submission_id]
        ) !== false;
    }

    /**
     * Permanently delete submission and related data for GDPR
     *
     * @param int $submission_id Submission ID
     * @return bool Success
     */
    public function permanently_delete_submission(int $submission_id): bool {
        $submission = $this->get_submission($submission_id);
        if (!$submission) {
            return false;
        }

        // Delete related analytics
        $this->wpdb->delete($this->table_analytics, ['session_id' => $submission['session_id']]);

        // Delete related logs
        $this->wpdb->delete($this->table_logs, ['submission_id' => $submission_id]);

        // Delete resume tokens
        $resume_table = $this->wpdb->prefix . 'isf_resume_tokens';
        $this->wpdb->delete($resume_table, ['session_id' => $submission['session_id']]);

        // Delete the submission
        return $this->wpdb->delete($this->table_submissions, ['id' => $submission_id]) !== false;
    }

    // =========================================================================
    // Data Retention Policy Methods
    // =========================================================================

    /**
     * Apply data retention policy
     *
     * @param array $settings Retention settings
     * @return array Results of retention actions
     */
    public function apply_retention_policy(array $settings): array {
        $results = [
            'submissions_deleted' => 0,
            'submissions_anonymized' => 0,
            'analytics_deleted' => 0,
            'audit_logs_deleted' => 0,
            'api_usage_deleted' => 0,
            'logs_deleted' => 0,
        ];

        $anonymize = !empty($settings['anonymize_instead_of_delete']);

        // Process old submissions in bounded chunks. Loading every matching
        // row at once (then iterating) is an OOM/timeout risk on large sites:
        // the daily cron could pull hundreds of thousands of rows into memory.
        // Instead, fetch a fixed-size batch, process it, and repeat until the
        // query returns nothing. Each processed row is mutated (anonymized or
        // deleted) so it drops out of the WHERE clause on the next pass, which
        // guarantees the loop drains and never reprocesses a row.
        if (!empty($settings['retention_submissions_days'])) {
            $days = (int)$settings['retention_submissions_days'];
            $batch_size = self::RETENTION_BATCH_SIZE;

            do {
                $old_submissions = $this->get_old_submissions($days, $batch_size);

                foreach ($old_submissions as $sub) {
                    if ($anonymize) {
                        if ($this->anonymize_submission($sub['id'])) {
                            $results['submissions_anonymized']++;
                        }
                    } else {
                        if ($this->permanently_delete_submission($sub['id'])) {
                            $results['submissions_deleted']++;
                        }
                    }
                }
            } while (count($old_submissions) === $batch_size);
        }

        // Delete old analytics
        if (!empty($settings['retention_analytics_days'])) {
            $results['analytics_deleted'] = $this->delete_old_analytics((int)$settings['retention_analytics_days']);
        }

        // Delete old audit logs
        if (!empty($settings['retention_audit_log_days'])) {
            $results['audit_logs_deleted'] = $this->delete_old_audit_logs((int)$settings['retention_audit_log_days']);
        }

        // Delete old API usage
        if (!empty($settings['retention_api_usage_days'])) {
            $results['api_usage_deleted'] = $this->cleanup_api_usage((int)$settings['retention_api_usage_days']);
        }

        // Delete old logs (already handled by existing cleanup, but include here)
        if (!empty($settings['log_retention_days'])) {
            $results['logs_deleted'] = $this->delete_old_logs((int)$settings['log_retention_days']);
        }

        return $results;
    }

    /**
     * Get submissions older than X days.
     *
     * @param int $days  Days threshold
     * @param int $limit Maximum rows to return (0 = no limit). The retention
     *                   sweep passes a positive limit and loops; an unbounded
     *                   fetch on a large table is an OOM/timeout risk.
     * @return array Old submissions
     */
    public function get_old_submissions(int $days, int $limit = 0): array {
        if ($limit > 0) {
            $sql = $this->wpdb->prepare(
                "SELECT id, session_id FROM {$this->table_submissions}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                 AND (form_data NOT LIKE '%%\"_anonymized\":true%%' OR form_data IS NULL)
                 LIMIT %d",
                $days,
                $limit
            );
        } else {
            $sql = $this->wpdb->prepare(
                "SELECT id, session_id FROM {$this->table_submissions}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                 AND (form_data NOT LIKE '%%\"_anonymized\":true%%' OR form_data IS NULL)",
                $days
            );
        }

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get retention policy statistics
     *
     * @param array $settings Retention settings
     * @return array Stats about data to be affected
     */
    public function get_retention_stats(array $settings): array {
        $stats = [];

        if (!empty($settings['retention_submissions_days'])) {
            $days = (int)$settings['retention_submissions_days'];
            $stats['submissions'] = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_submissions}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                 AND (form_data NOT LIKE '%%\"_anonymized\":true%%' OR form_data IS NULL)",
                $days
            ));
        }

        if (!empty($settings['retention_analytics_days'])) {
            $days = (int)$settings['retention_analytics_days'];
            $stats['analytics'] = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_analytics}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
        }

        if (!empty($settings['retention_audit_log_days'])) {
            $table = $this->wpdb->prefix . 'isf_audit_log';
            $days = (int)$settings['retention_audit_log_days'];
            $stats['audit_logs'] = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
        }

        if (!empty($settings['retention_api_usage_days'])) {
            $table = $this->wpdb->prefix . 'isf_api_usage';
            $days = (int)$settings['retention_api_usage_days'];
            $stats['api_usage'] = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
        }

        return $stats;
    }
}
