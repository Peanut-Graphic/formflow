<?php
namespace ISF\Database\Traits;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * API usage tracking database operations.
 *
 * This trait contains all API usage tracking database methods.
 * It is designed to be used by ISF_Database class.
 *
 * Requirements: The using class must have these properties:
 * - $wpdb - WordPress database object
 *
 * Methods included:
 * - log_api_call()
 * - get_api_usage_stats()
 * - get_api_calls_count()
 * - cleanup_api_usage()
 * - get_rate_limit_status()
 *
 * @package FormFlow_Pro
 * @since   2.9.0
 */


if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Trait for API usage tracking database operations.
 */
trait ApiUsage {

	/**
	 * Log an API call for rate limit monitoring.
	 *
	 * @param int         $instance_id      Instance ID.
	 * @param string      $endpoint         API endpoint called.
	 * @param string      $method           HTTP method or API method name.
	 * @param int|null    $status_code      HTTP status code.
	 * @param int|null    $response_time_ms Response time in milliseconds.
	 * @param bool        $success          Whether call was successful.
	 * @param string|null $error_message    Error message if failed.
	 * @return int|false Insert ID or false on failure.
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

		$result = $this->wpdb->insert( $table, [
			'instance_id'      => $instance_id,
			'endpoint'         => $endpoint,
			'method'           => $method,
			'status_code'      => $status_code,
			'response_time_ms' => $response_time_ms,
			'success'          => $success ? 1 : 0,
			'error_message'    => $error_message,
		] );

		return $result ? $this->wpdb->insert_id : false;
	}

	/**
	 * Get API usage statistics.
	 *
	 * @param int|null $instance_id Filter by instance ID.
	 * @param string   $period      Time period: 'hour', 'day', 'week', 'month'.
	 * @return array API usage statistics.
	 */
	public function get_api_usage_stats( ?int $instance_id = null, string $period = 'day' ): array {
		$table = $this->wpdb->prefix . 'isf_api_usage';

		$interval = match ( $period ) {
			'hour'  => 'INTERVAL 1 HOUR',
			'day'   => 'INTERVAL 24 HOUR',
			'week'  => 'INTERVAL 7 DAY',
			'month' => 'INTERVAL 30 DAY',
			default => 'INTERVAL 24 HOUR',
		};

		$where = "WHERE created_at >= DATE_SUB(NOW(), {$interval})";
		if ( $instance_id ) {
			$where .= $this->wpdb->prepare( ' AND instance_id = %d', $instance_id );
		}

		// Total calls.
		$total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

		// Successful calls.
		$successful = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where} AND success = 1" );

		// Failed calls.
		$failed = $total - $successful;

		// Average response time.
		$avg_response = (float) $this->wpdb->get_var(
			"SELECT AVG(response_time_ms) FROM {$table} {$where} AND response_time_ms IS NOT NULL"
		);

		// Calls per endpoint.
		$by_endpoint = $this->wpdb->get_results(
			"SELECT endpoint, COUNT(*) as count, AVG(response_time_ms) as avg_response,
					SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count
			 FROM {$table} {$where}
			 GROUP BY endpoint
			 ORDER BY count DESC",
			ARRAY_A
		);

		// Calls per hour (for chart).
		$hourly = $this->wpdb->get_results(
			"SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour, COUNT(*) as count
			 FROM {$table} {$where}
			 GROUP BY hour
			 ORDER BY hour ASC",
			ARRAY_A
		);

		// Error breakdown.
		$errors = $this->wpdb->get_results(
			"SELECT error_message, COUNT(*) as count
			 FROM {$table} {$where} AND success = 0 AND error_message IS NOT NULL
			 GROUP BY error_message
			 ORDER BY count DESC
			 LIMIT 10",
			ARRAY_A
		);

		return [
			'total_calls'      => $total,
			'successful_calls' => $successful,
			'failed_calls'     => $failed,
			'success_rate'     => $total > 0 ? round( ( $successful / $total ) * 100, 1 ) : 0,
			'avg_response_ms'  => round( $avg_response, 0 ),
			'by_endpoint'      => $by_endpoint,
			'hourly'           => $hourly,
			'errors'           => $errors,
			'period'           => $period,
		];
	}

	/**
	 * Get calls per minute for rate limiting check.
	 *
	 * @param int $instance_id Instance ID.
	 * @param int $minutes     Time window in minutes.
	 * @return int Number of calls.
	 */
	public function get_api_calls_count( int $instance_id, int $minutes = 1 ): int {
		$table = $this->wpdb->prefix . 'isf_api_usage';

		return (int) $this->wpdb->get_var( $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE instance_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)",
			$instance_id,
			$minutes
		) );
	}

	/**
	 * Clean up old API usage records.
	 *
	 * @param int $days Number of days to keep.
	 * @return int Number of deleted rows.
	 */
	public function cleanup_api_usage( int $days = 30 ): int {
		$table = $this->wpdb->prefix . 'isf_api_usage';

		return (int) $this->wpdb->query( $this->wpdb->prepare(
			"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		) );
	}

	/**
	 * Get rate limit status for an instance.
	 *
	 * @param int $instance_id Instance ID.
	 * @param int $limit       Calls per minute limit.
	 * @return array Rate limit status.
	 */
	public function get_rate_limit_status( int $instance_id, int $limit = 60 ): array {
		$calls_per_minute = $this->get_api_calls_count( $instance_id, 1 );
		$calls_last_5_min = $this->get_api_calls_count( $instance_id, 5 );

		return [
			'calls_per_minute' => $calls_per_minute,
			'calls_last_5_min' => $calls_last_5_min,
			'avg_per_minute'   => round( $calls_last_5_min / 5, 1 ),
			'limit'            => $limit,
			'usage_percent'    => round( ( $calls_per_minute / $limit ) * 100, 1 ),
			'status'           => $calls_per_minute >= $limit ? 'exceeded' : ( $calls_per_minute >= ( $limit * 0.8 ) ? 'warning' : 'ok' ),
		];
	}
}
