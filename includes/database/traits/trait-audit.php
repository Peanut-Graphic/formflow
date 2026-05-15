<?php
namespace ISF\Database\Traits;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Audit logging database operations.
 *
 * This trait contains all audit log database methods.
 * It is designed to be used by ISF_Database class.
 *
 * Requirements: The using class must have these properties:
 * - $wpdb - WordPress database object
 *
 * Methods included:
 * - log_audit()
 * - get_audit_log()
 * - get_audit_log_count()
 * - delete_old_audit_logs()
 *
 * @package FormFlow_Pro
 * @since   2.9.0
 */


if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Trait for audit logging database operations.
 */
trait Audit {

	/**
	 * Log an audit event.
	 *
	 * @param string      $action      Action performed.
	 * @param string      $object_type Type of object affected.
	 * @param int|null    $object_id   Object ID.
	 * @param string|null $object_name Object name.
	 * @param array       $details     Additional details.
	 * @return int|false Insert ID or false on failure.
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
		if ( ! $user || ! $user->ID ) {
			return false;
		}

		$result = $this->wpdb->insert( $table, [
			'user_id'     => $user->ID,
			'user_login'  => $user->user_login,
			'user_email'  => $user->user_email,
			'action'      => $action,
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'object_name' => $object_name,
			'details'     => ! empty( $details ) ? wp_json_encode( $details ) : null,
			'ip_address'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : null,
		] );

		return $result ? $this->wpdb->insert_id : false;
	}

	/**
	 * Get audit log entries.
	 *
	 * @param array $filters Filters (user_id, action, object_type, date_from, date_to).
	 * @param int   $limit   Max results.
	 * @param int   $offset  Offset.
	 * @return array List of audit log entries.
	 */
	public function get_audit_log( array $filters = [], int $limit = 100, int $offset = 0 ): array {
		$table = $this->wpdb->prefix . 'isf_audit_log';

		$where  = [ '1=1' ];
		$values = [];

		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $filters['user_id'];
		}

		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action = %s';
			$values[] = $filters['action'];
		}

		if ( ! empty( $filters['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$values[] = $filters['object_type'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = $filters['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = $filters['date_to'] . ' 23:59:59';
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
			$row['details'] = $row['details'] ? json_decode( $row['details'], true ) : [];
		}

		return $results;
	}

	/**
	 * Get audit log count.
	 *
	 * @param array $filters Same filters as get_audit_log.
	 * @return int Count.
	 */
	public function get_audit_log_count( array $filters = [] ): int {
		$table = $this->wpdb->prefix . 'isf_audit_log';

		$where  = [ '1=1' ];
		$values = [];

		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $filters['user_id'];
		}

		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action = %s';
			$values[] = $filters['action'];
		}

		if ( ! empty( $filters['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$values[] = $filters['object_type'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = $filters['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = $filters['date_to'] . ' 23:59:59';
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
	 * Delete old audit log entries.
	 *
	 * @param int $days Days to retain.
	 * @return int Number deleted.
	 */
	public function delete_old_audit_logs( int $days ): int {
		$table = $this->wpdb->prefix . 'isf_audit_log';

		return (int) $this->wpdb->query( $this->wpdb->prepare(
			"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		) );
	}
}
