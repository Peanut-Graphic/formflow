<?php
namespace ISF\Database\Traits;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Scheduled report-related database operations.
 *
 * This trait contains all scheduled report database methods.
 * It is designed to be used by ISF_Database class.
 *
 * Requirements: The using class must have these properties:
 * - $wpdb - WordPress database object
 * - $table_analytics - Analytics table name
 *
 * Methods included:
 * - create_scheduled_report()
 * - update_scheduled_report()
 * - delete_scheduled_report()
 * - get_scheduled_reports()
 * - get_scheduled_report()
 * - get_due_reports()
 * - update_report_sent()
 * - get_report_analytics()
 *
 * @package FormFlow_Pro
 * @since   2.9.0
 */


if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Trait for scheduled report database operations.
 */
trait Reports {

	/**
	 * Create a new scheduled report.
	 *
	 * @param array $data Report data.
	 * @return int|false The new report ID or false on failure.
	 */
	public function create_scheduled_report( array $data ): int|false {
		$table = $this->wpdb->prefix . 'isf_scheduled_reports';

		$result = $this->wpdb->insert( $table, [
			'name'        => $data['name'],
			'frequency'   => $data['frequency'],
			'recipients'  => is_array( $data['recipients'] ) ? wp_json_encode( $data['recipients'] ) : $data['recipients'],
			'instance_id' => $data['instance_id'] ?: null,
			'settings'    => is_array( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : ( $data['settings'] ?? '{}' ),
			'is_active'   => $data['is_active'] ?? 1,
		] );

		return $result ? $this->wpdb->insert_id : false;
	}

	/**
	 * Update a scheduled report.
	 *
	 * @param int   $id   Report ID.
	 * @param array $data Report data.
	 * @return bool Success.
	 */
	public function update_scheduled_report( int $id, array $data ): bool {
		$table = $this->wpdb->prefix . 'isf_scheduled_reports';

		$update_data = [];

		if ( isset( $data['name'] ) ) {
			$update_data['name'] = $data['name'];
		}
		if ( isset( $data['frequency'] ) ) {
			$update_data['frequency'] = $data['frequency'];
		}
		if ( isset( $data['recipients'] ) ) {
			$update_data['recipients'] = is_array( $data['recipients'] ) ? wp_json_encode( $data['recipients'] ) : $data['recipients'];
		}
		if ( array_key_exists( 'instance_id', $data ) ) {
			$update_data['instance_id'] = $data['instance_id'] ?: null;
		}
		if ( isset( $data['settings'] ) ) {
			$update_data['settings'] = is_array( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : $data['settings'];
		}
		if ( isset( $data['is_active'] ) ) {
			$update_data['is_active'] = $data['is_active'] ? 1 : 0;
		}

		if ( empty( $update_data ) ) {
			return false;
		}

		return $this->wpdb->update( $table, $update_data, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Delete a scheduled report.
	 *
	 * @param int $id Report ID.
	 * @return bool Success.
	 */
	public function delete_scheduled_report( int $id ): bool {
		$table = $this->wpdb->prefix . 'isf_scheduled_reports';
		return $this->wpdb->delete( $table, [ 'id' => $id ] ) !== false;
	}

	/**
	 * Get all scheduled reports.
	 *
	 * @param bool $active_only Only return active reports.
	 * @return array List of reports.
	 */
	public function get_scheduled_reports( bool $active_only = false ): array {
		$table = $this->wpdb->prefix . 'isf_scheduled_reports';

		$where = $active_only ? 'WHERE is_active = 1' : '';
		$sql   = "SELECT * FROM {$table} {$where} ORDER BY name ASC";

		return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
	}

	/**
	 * Get a single scheduled report.
	 *
	 * @param int $id Report ID.
	 * @return array|null Report data or null.
	 */
	public function get_scheduled_report( int $id ): ?array {
		$table = $this->wpdb->prefix . 'isf_scheduled_reports';

		$result = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( $result ) {
			$result['recipients'] = json_decode( $result['recipients'], true ) ?: [];
			$result['settings']   = json_decode( $result['settings'], true ) ?: [];
		}

		return $result ?: null;
	}

	/**
	 * Get reports due to be sent.
	 *
	 * @param string|null $frequency Frequency type (daily, weekly, monthly) or null for all.
	 * @return array List of due reports.
	 */
	public function get_due_reports( ?string $frequency = null ): array {
		$table = $this->wpdb->prefix . 'isf_scheduled_reports';

		if ( $frequency ) {
			$sql = $this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE is_active = 1 AND frequency = %s",
				$frequency
			);
		} else {
			// Get all due reports when no frequency specified.
			$sql = "SELECT * FROM {$table} WHERE is_active = 1";
		}

		$results = $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];

		foreach ( $results as &$result ) {
			$result['recipients'] = json_decode( $result['recipients'], true ) ?: [];
			$result['settings']   = json_decode( $result['settings'], true ) ?: [];
		}

		return $results;
	}

	/**
	 * Update report last sent timestamp.
	 *
	 * @param int $id Report ID.
	 * @return bool Success.
	 */
	public function update_report_sent( int $id ): bool {
		$table = $this->wpdb->prefix . 'isf_scheduled_reports';

		return $this->wpdb->update(
			$table,
			[ 'last_sent_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ]
		) !== false;
	}

	/**
	 * Get analytics data for reporting.
	 *
	 * @param string   $date_from    Start date.
	 * @param string   $date_to      End date.
	 * @param int|null $instance_id  Instance ID filter.
	 * @param bool     $include_test Include test data.
	 * @return array Analytics data.
	 */
	public function get_report_analytics( string $date_from, string $date_to, ?int $instance_id = null, bool $include_test = false ): array {
		$test_clause     = $include_test ? '' : 'AND is_test = 0';
		$instance_clause = $instance_id ? $this->wpdb->prepare( ' AND instance_id = %d', $instance_id ) : '';

		// Summary stats.
		$summary = $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT
				COUNT(DISTINCT CASE WHEN step = 1 AND action = 'enter' THEN session_id END) as total_started,
				COUNT(DISTINCT CASE WHEN action = 'complete' THEN session_id END) as total_completed,
				COUNT(DISTINCT CASE WHEN action = 'abandon' THEN session_id END) as total_abandoned
			 FROM {$this->table_analytics}
			 WHERE created_at BETWEEN %s AND %s {$instance_clause} {$test_clause}",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		), ARRAY_A );

		$total_started   = (int) ( $summary['total_started'] ?? 0 );
		$total_completed = (int) ( $summary['total_completed'] ?? 0 );
		$total_abandoned = (int) ( $summary['total_abandoned'] ?? 0 );

		return [
			'total_started'    => $total_started,
			'total_completed'  => $total_completed,
			'total_abandoned'  => $total_abandoned,
			'completion_rate'  => $total_started > 0 ? round( ( $total_completed / $total_started ) * 100, 1 ) : 0,
			'abandonment_rate' => $total_started > 0 ? round( ( $total_abandoned / $total_started ) * 100, 1 ) : 0,
			'date_from'        => $date_from,
			'date_to'          => $date_to,
		];
	}
}
