<?php
namespace ISF\Database\Traits;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Submission-related database operations.
 *
 * This trait contains all submission management database methods.
 * It is designed to be used by ISF_Database class.
 *
 * Requirements: The using class must have these properties:
 * - $wpdb - WordPress database object
 * - $encryption - ISF_Encryption instance
 * - $table_submissions - Submissions table name
 * - $table_instances - Instances table name
 *
 * Methods included:
 * - create_submission()
 * - get_submission_by_session()
 * - get_submission()
 * - update_submission()
 * - get_submissions()
 * - get_submission_count()
 * - get_submissions_for_export()
 * - mark_abandoned_sessions()
 * - delete_submissions()
 *
 * @package FormFlow_Pro
 * @since   2.9.0
 */


if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Trait for submission database operations.
 */
trait Submissions {

    /**
     * Create a new submission.
     *
     * @param array $data Submission data.
     * @return int|false The new submission ID or false on failure.
     */
    public function create_submission( array $data ): int|false {
        $insert_data = [
            'instance_id'    => $data['instance_id'],
            'session_id'     => $data['session_id'],
            'account_number' => $data['account_number'] ?? null,
            'customer_name'  => $data['customer_name'] ?? null,
            'device_type'    => $data['device_type'] ?? null,
            'form_data'      => $this->encryption->encrypt_array( $data['form_data'] ?? [] ),
            'status'         => $data['status'] ?? 'in_progress',
            'step'           => $data['step'] ?? 1,
            'ip_address'     => $data['ip_address'] ?? '',
            'user_agent'     => substr( sanitize_text_field( $data['user_agent'] ?? '' ), 0, 500 ),
        ];

        $result = $this->wpdb->insert( $this->table_submissions, $insert_data );

        if ( $result === false ) {
            return false;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Get a submission by session ID.
     *
     * @param string $session_id Session ID.
     * @param int    $instance_id Instance ID.
     * @return array|null Submission data or null.
     */
    public function get_submission_by_session( string $session_id, int $instance_id ): ?array {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_submissions}
             WHERE session_id = %s AND instance_id = %d
             ORDER BY created_at DESC LIMIT 1",
            $session_id,
            $instance_id
        );

        $result = $this->wpdb->get_row( $sql, ARRAY_A );

        return $result ? $this->decode_submission( $result ) : null;
    }

    /**
     * Get a submission by ID.
     *
     * @param int $id Submission ID.
     * @return array|null Submission data or null.
     */
    public function get_submission( int $id ): ?array {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_submissions} WHERE id = %d",
            $id
        );

        $result = $this->wpdb->get_row( $sql, ARRAY_A );

        return $result ? $this->decode_submission( $result ) : null;
    }

    /**
     * Update a submission.
     *
     * @param int   $id   Submission ID.
     * @param array $data Data to update.
     * @return bool Success.
     */
    public function update_submission( int $id, array $data ): bool {
        $update_data = [];

        if ( isset( $data['account_number'] ) ) {
            $update_data['account_number'] = $data['account_number'];
        }

        if ( isset( $data['customer_name'] ) ) {
            $update_data['customer_name'] = $data['customer_name'];
        }

        if ( isset( $data['device_type'] ) ) {
            $update_data['device_type'] = $data['device_type'];
        }

        if ( isset( $data['form_data'] ) ) {
            $update_data['form_data'] = $this->encryption->encrypt_array( $data['form_data'] );
        }

        if ( isset( $data['api_response'] ) ) {
            $update_data['api_response'] = $this->encryption->encrypt_array( $data['api_response'] );
        }

        if ( isset( $data['status'] ) ) {
            $update_data['status'] = $data['status'];
            if ( $data['status'] === 'completed' ) {
                $update_data['completed_at'] = current_time( 'mysql' );
            }
        }

        if ( isset( $data['step'] ) ) {
            $update_data['step'] = (int) $data['step'];
        }

        if ( empty( $update_data ) ) {
            return true;
        }

        $result = $this->wpdb->update(
            $this->table_submissions,
            $update_data,
            [ 'id' => $id ]
        );

        return $result !== false;
    }

    /**
     * Get submissions with filters.
     *
     * @param array $filters Filter criteria.
     * @param int   $limit   Max results.
     * @param int   $offset  Offset.
     * @return array Submissions.
     */
    public function get_submissions( array $filters = [], int $limit = 50, int $offset = 0 ): array {
        $where  = [ '1=1' ];
        $values = [];

        if ( ! empty( $filters['instance_id'] ) ) {
            $where[]  = 'instance_id = %d';
            $values[] = $filters['instance_id'];
        }

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $filters['status'];
        }

        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'created_at >= %s';
            $values[] = $filters['date_from'];
        }

        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'created_at <= %s';
            $values[] = $filters['date_to'];
        }

        if ( ! empty( $filters['search'] ) ) {
            $where[]  = '(account_number LIKE %s OR customer_name LIKE %s)';
            $search   = '%' . $this->wpdb->esc_like( $filters['search'] ) . '%';
            $values[] = $search;
            $values[] = $search;
        }

        $where_clause = implode( ' AND ', $where );

        $sql = "SELECT s.*, i.name as instance_name
                FROM {$this->table_submissions} s
                LEFT JOIN {$this->table_instances} i ON s.instance_id = i.id
                WHERE {$where_clause}
                ORDER BY s.created_at DESC
                LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        if ( ! empty( $values ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$values );
        }

        $results = $this->wpdb->get_results( $sql, ARRAY_A );

        return array_map( [ $this, 'decode_submission' ], $results ?: [] );
    }

    /**
     * Get submission count.
     *
     * @param array $filters Filter criteria.
     * @return int Count.
     */
    public function get_submission_count( array $filters = [] ): int {
        $where  = [ '1=1' ];
        $values = [];

        if ( ! empty( $filters['instance_id'] ) ) {
            $where[]  = 'instance_id = %d';
            $values[] = $filters['instance_id'];
        }

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $filters['status'];
        }

        $where_clause = implode( ' AND ', $where );

        $sql = "SELECT COUNT(*) FROM {$this->table_submissions} WHERE {$where_clause}";

        if ( ! empty( $values ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$values );
        }

        return (int) $this->wpdb->get_var( $sql );
    }

    /**
     * Get submissions for CSV export (no limit).
     *
     * @param array $filters Filter criteria.
     * @return array Submissions.
     */
    public function get_submissions_for_export( array $filters = [] ): array {
        $where  = [ '1=1' ];
        $values = [];

        if ( ! empty( $filters['instance_id'] ) ) {
            $where[]  = 's.instance_id = %d';
            $values[] = $filters['instance_id'];
        }

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 's.status = %s';
            $values[] = $filters['status'];
        }

        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 's.created_at >= %s';
            $values[] = $filters['date_from'] . ' 00:00:00';
        }

        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 's.created_at <= %s';
            $values[] = $filters['date_to'] . ' 23:59:59';
        }

        if ( ! empty( $filters['search'] ) ) {
            $where[]  = '(s.account_number LIKE %s OR s.customer_name LIKE %s)';
            $search   = '%' . $this->wpdb->esc_like( $filters['search'] ) . '%';
            $values[] = $search;
            $values[] = $search;
        }

        $where_clause = implode( ' AND ', $where );

        $sql = "SELECT s.*, i.name as instance_name
                FROM {$this->table_submissions} s
                LEFT JOIN {$this->table_instances} i ON s.instance_id = i.id
                WHERE {$where_clause}
                ORDER BY s.created_at DESC";

        if ( ! empty( $values ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$values );
        }

        $results = $this->wpdb->get_results( $sql, ARRAY_A );

        return array_map( [ $this, 'decode_submission' ], $results ?: [] );
    }

    /**
     * Mark abandoned sessions.
     *
     * @param int $hours Hours after which to mark as abandoned.
     * @return int Number of updated records.
     */
    public function mark_abandoned_sessions( int $hours ): int {
        $sql = $this->wpdb->prepare(
            "UPDATE {$this->table_submissions}
             SET status = 'abandoned'
             WHERE status = 'in_progress'
             AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $hours
        );

        return (int) $this->wpdb->query( $sql );
    }

    /**
     * Delete submissions by IDs.
     *
     * @param array $ids Submission IDs.
     * @return int Number of deleted records.
     */
    public function delete_submissions( array $ids ): int {
        if ( empty( $ids ) ) {
            return 0;
        }

        $ids = array_map( 'intval', $ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // First delete related logs.
        $log_sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table_logs} WHERE submission_id IN ($placeholders)",
            ...$ids
        );
        $this->wpdb->query( $log_sql );

        // Delete related analytics.
        $analytics_sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table_analytics} WHERE submission_id IN ($placeholders)",
            ...$ids
        );
        $this->wpdb->query( $analytics_sql );

        // Delete retry queue items.
        $retry_sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table_retry_queue} WHERE submission_id IN ($placeholders)",
            ...$ids
        );
        $this->wpdb->query( $retry_sql );

        // Delete submissions.
        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table_submissions} WHERE id IN ($placeholders)",
            ...$ids
        );

        return (int) $this->wpdb->query( $sql );
    }

    /**
     * Decode submission data (decrypt form_data and api_response).
     *
     * NOTE: This method should remain in the main class as it's used by multiple traits.
     *
     * @param array $submission Raw submission data.
     * @return array Decoded submission.
     */
    private function decode_submission( array $submission ): array {
        $submission['form_data'] = $this->encryption->decrypt_array( $submission['form_data'] ?? '' );
        if ( ! empty( $submission['api_response'] ) ) {
            $submission['api_response'] = $this->encryption->decrypt_array( $submission['api_response'] );
        }

        return $submission;
    }
}
