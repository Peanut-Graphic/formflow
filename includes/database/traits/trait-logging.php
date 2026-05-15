<?php
namespace ISF\Database\Traits;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Logging-related database operations.
 *
 * This trait contains all logging database methods.
 * It is designed to be used by ISF_Database class.
 *
 * Requirements: The using class must have these properties:
 * - $wpdb - WordPress database object
 * - $table_logs - Logs table name
 * - $table_instances - Instances table name
 *
 * Methods included:
 * - log()
 * - get_logs()
 * - delete_old_logs()
 * - delete_logs()
 *
 * @package FormFlow_Pro
 * @since   2.9.0
 */


if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Trait for logging database operations.
 */
trait Logging {

	/**
	 * Log an event.
	 *
	 * @param string   $type          Log type.
	 * @param string   $message       Log message.
	 * @param array    $details       Additional details.
	 * @param int|null $instance_id   Instance ID.
	 * @param int|null $submission_id Submission ID.
	 * @return int|false Insert ID or false on failure.
	 */
	public function log(
		string $type,
		string $message,
		array $details = [],
		?int $instance_id = null,
		?int $submission_id = null
	): int|false {
		$insert_data = [
			'instance_id'   => $instance_id,
			'submission_id' => $submission_id,
			'log_type'      => $type,
			'message'       => $message,
			'details'       => wp_json_encode( $details ),
		];

		$result = $this->wpdb->insert( $this->table_logs, $insert_data );

		if ( $result === false ) {
			return false;
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Get log entries.
	 *
	 * @param array $filters Filter criteria.
	 * @param int   $limit   Max results.
	 * @param int   $offset  Offset.
	 * @return array Log entries.
	 */
	public function get_logs( array $filters = [], int $limit = 100, int $offset = 0 ): array {
		$where  = [ '1=1' ];
		$values = [];

		if ( ! empty( $filters['instance_id'] ) ) {
			$where[]  = 'l.instance_id = %d';
			$values[] = $filters['instance_id'];
		}

		if ( ! empty( $filters['submission_id'] ) ) {
			$where[]  = 'l.submission_id = %d';
			$values[] = $filters['submission_id'];
		}

		if ( ! empty( $filters['type'] ) ) {
			$where[]  = 'l.log_type = %s';
			$values[] = $filters['type'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'l.created_at >= %s';
			$values[] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'l.created_at <= %s';
			$values[] = $filters['date_to'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'l.message LIKE %s';
			$values[] = '%' . $this->wpdb->esc_like( $filters['search'] ) . '%';
		}

		$where_clause = implode( ' AND ', $where );

		$sql = "SELECT l.*, i.name as instance_name
				FROM {$this->table_logs} l
				LEFT JOIN {$this->table_instances} i ON l.instance_id = i.id
				WHERE {$where_clause}
				ORDER BY l.created_at DESC
				LIMIT %d OFFSET %d";

		$values[] = $limit;
		$values[] = $offset;

		if ( ! empty( $values ) ) {
			$sql = $this->wpdb->prepare( $sql, ...$values );
		}

		$results = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map( function ( $log ) {
			$log['details'] = json_decode( $log['details'] ?? '{}', true ) ?: [];
			return $log;
		}, $results ?: [] );
	}

	/**
	 * Delete old log entries.
	 *
	 * @param int $days Days to retain.
	 * @return int Number of deleted records.
	 */
	public function delete_old_logs( int $days ): int {
		$sql = $this->wpdb->prepare(
			"DELETE FROM {$this->table_logs}
			 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		);

		return (int) $this->wpdb->query( $sql );
	}

	/**
	 * Delete logs by IDs.
	 *
	 * @param array $ids Log IDs.
	 * @return int Number of deleted records.
	 */
	public function delete_logs( array $ids ): int {
		if ( empty( $ids ) ) {
			return 0;
		}

		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$sql = $this->wpdb->prepare(
			"DELETE FROM {$this->table_logs} WHERE id IN ($placeholders)",
			...$ids
		);

		return (int) $this->wpdb->query( $sql );
	}
}
