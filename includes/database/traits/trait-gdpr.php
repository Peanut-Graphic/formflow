<?php
namespace ISF\Database\Traits;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * GDPR compliance database operations.
 *
 * This trait contains all GDPR-related database methods.
 * It is designed to be used by ISF_Database class.
 *
 * Requirements: The using class must have these properties:
 * - $wpdb - WordPress database object
 * - $encryption - ISF_Encryption instance
 * - $table_submissions - Submissions table name
 * - $table_analytics - Analytics table name
 * - $table_logs - Logs table name
 *
 * Methods included:
 * - create_gdpr_request()
 * - get_gdpr_requests()
 * - get_gdpr_requests_count()
 * - update_gdpr_request()
 * - find_submissions_for_gdpr()
 * - anonymize_submission()
 * - permanently_delete_submission()
 *
 * @package FormFlow_Pro
 * @since   2.9.0
 */


if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Trait for GDPR compliance database operations.
 */
trait GDPR {

	/**
	 * Create a GDPR request.
	 *
	 * @param array $data Request data.
	 * @return int|false Request ID or false on failure.
	 */
	public function create_gdpr_request( array $data ): int|false {
		$table = $this->wpdb->prefix . 'isf_gdpr_requests';

		$user = wp_get_current_user();

		$insert_data = [
			'request_type'   => $data['request_type'] ?? 'export',
			'email'          => $data['email'] ?? '',
			'account_number' => $data['account_number'] ?? null,
			'requested_by'   => $user ? $user->ID : null,
			'request_data'   => ! empty( $data['request_data'] ) ? wp_json_encode( $data['request_data'] ) : null,
			'status'         => $data['status'] ?? 'pending',
		];

		// If already completed, set result_data and processed info.
		if ( ( $data['status'] ?? '' ) === 'completed' ) {
			$insert_data['processed_by'] = $user ? $user->ID : null;
			$insert_data['processed_at'] = current_time( 'mysql' );
			if ( ! empty( $data['result_data'] ) ) {
				$insert_data['result_data'] = wp_json_encode( $data['result_data'] );
			}
		}

		if ( ! empty( $data['notes'] ) ) {
			$insert_data['notes'] = $data['notes'];
		}

		$result = $this->wpdb->insert( $table, $insert_data );

		return $result ? $this->wpdb->insert_id : false;
	}

	/**
	 * Get GDPR requests.
	 *
	 * @param array $filters Filters (status, type, email).
	 * @param int   $limit   Limit.
	 * @param int   $offset  Offset.
	 * @return array List of requests.
	 */
	public function get_gdpr_requests( array $filters = [], int $limit = 50, int $offset = 0 ): array {
		$table = $this->wpdb->prefix . 'isf_gdpr_requests';

		$where  = [ '1=1' ];
		$values = [];

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $filters['status'];
		}

		if ( ! empty( $filters['request_type'] ) ) {
			$where[]  = 'request_type = %s';
			$values[] = $filters['request_type'];
		}

		if ( ! empty( $filters['email'] ) ) {
			$where[]  = 'email LIKE %s';
			$values[] = '%' . $this->wpdb->esc_like( $filters['email'] ) . '%';
		}

		$where_clause = implode( ' AND ', $where );
		$values[]     = $limit;
		$values[]     = $offset;

		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			...$values
		);

		$results = $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];

		foreach ( $results as &$row ) {
			$row['request_data'] = $row['request_data'] ? json_decode( $row['request_data'], true ) : [];
			$row['result_data']  = $row['result_data'] ? json_decode( $row['result_data'], true ) : [];
		}

		return $results;
	}

	/**
	 * Get GDPR requests count.
	 *
	 * @param array $filters Same filters as get_gdpr_requests.
	 * @return int Count.
	 */
	public function get_gdpr_requests_count( array $filters = [] ): int {
		$table = $this->wpdb->prefix . 'isf_gdpr_requests';

		$where  = [ '1=1' ];
		$values = [];

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $filters['status'];
		}

		if ( ! empty( $filters['request_type'] ) ) {
			$where[]  = 'request_type = %s';
			$values[] = $filters['request_type'];
		}

		if ( ! empty( $filters['email'] ) ) {
			$where[]  = 'email LIKE %s';
			$values[] = '%' . $this->wpdb->esc_like( $filters['email'] ) . '%';
		}

		$where_clause = implode( ' AND ', $where );

		if ( ! empty( $values ) ) {
			$sql = $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}", ...$values );
		} else {
			$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Update GDPR request status.
	 *
	 * @param int         $request_id  Request ID.
	 * @param string      $status      New status.
	 * @param array|null  $result_data Result data.
	 * @param string|null $notes       Notes.
	 * @return bool Success.
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
			'status'       => $status,
			'processed_by' => $user ? $user->ID : null,
		];

		if ( $status === 'completed' || $status === 'failed' ) {
			$data['processed_at'] = current_time( 'mysql' );
		}

		if ( $result_data !== null ) {
			$data['result_data'] = wp_json_encode( $result_data );
		}

		if ( $notes !== null ) {
			$data['notes'] = $notes;
		}

		return $this->wpdb->update( $table, $data, [ 'id' => $request_id ] ) !== false;
	}

	/**
	 * Find submissions by email or account number for GDPR.
	 *
	 * @param string      $email          Email address.
	 * @param string|null $account_number Account number.
	 * @return array Matching submissions (decrypted).
	 */
	public function find_submissions_for_gdpr( string $email, ?string $account_number = null ): array {
		$submissions = [];

		// Get all submissions and check decrypted data.
		$sql             = "SELECT * FROM {$this->table_submissions} ORDER BY created_at DESC";
		$all_submissions = $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];

		foreach ( $all_submissions as $sub ) {
			$form_data = $this->encryption->decrypt_array( $sub['form_data'] ?? '' );

			$matches = false;

			// Check email.
			if ( ! empty( $form_data['email'] ) && strtolower( $form_data['email'] ) === strtolower( $email ) ) {
				$matches = true;
			}

			// Check account number.
			if ( $account_number && ! empty( $form_data['account_number'] ) ) {
				if ( $form_data['account_number'] === $account_number ) {
					$matches = true;
				}
			}

			if ( $matches ) {
				$sub['form_data']    = $form_data;
				$sub['api_response'] = $this->encryption->decrypt_array( $sub['api_response'] ?? '' );
				$submissions[]       = $sub;
			}
		}

		return $submissions;
	}

	/**
	 * Anonymize a submission for GDPR.
	 *
	 * @param int $submission_id Submission ID.
	 * @return bool Success.
	 */
	public function anonymize_submission( int $submission_id ): bool {
		$submission = $this->get_submission( $submission_id );
		if ( ! $submission ) {
			return false;
		}

		$form_data = $submission['form_data'];

		// Anonymize PII fields.
		$pii_fields = [
			'first_name', 'last_name', 'email', 'phone', 'phone_alt',
			'address', 'address2', 'city', 'zip',
			'account_number', 'utility_no', 'comverge_no',
		];

		foreach ( $pii_fields as $field ) {
			if ( isset( $form_data[ $field ] ) ) {
				$form_data[ $field ] = '[REDACTED]';
			}
		}

		// Mark as anonymized.
		$form_data['_anonymized']    = true;
		$form_data['_anonymized_at'] = current_time( 'mysql' );

		return $this->wpdb->update(
			$this->table_submissions,
			[
				'customer_name'  => '[REDACTED]',
				'account_number' => '[REDACTED]',
				'form_data'      => $this->encryption->encrypt_array( $form_data ),
				'api_response'   => null,
				'ip_address'     => null,
			],
			[ 'id' => $submission_id ]
		) !== false;
	}

	/**
	 * Permanently delete submission and related data for GDPR.
	 *
	 * @param int $submission_id Submission ID.
	 * @return bool Success.
	 */
	public function permanently_delete_submission( int $submission_id ): bool {
		$submission = $this->get_submission( $submission_id );
		if ( ! $submission ) {
			return false;
		}

		// Delete related analytics.
		$this->wpdb->delete( $this->table_analytics, [ 'session_id' => $submission['session_id'] ] );

		// Delete related logs.
		$this->wpdb->delete( $this->table_logs, [ 'submission_id' => $submission_id ] );

		// Delete resume tokens.
		$resume_table = $this->wpdb->prefix . 'isf_resume_tokens';
		$this->wpdb->delete( $resume_table, [ 'session_id' => $submission['session_id'] ] );

		// Delete the submission.
		return $this->wpdb->delete( $this->table_submissions, [ 'id' => $submission_id ] ) !== false;
	}
}
