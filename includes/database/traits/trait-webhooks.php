<?php
namespace ISF\Database\Traits;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Webhook-related database operations.
 *
 * This trait contains all webhook management database methods.
 * It is designed to be used by ISF_Database class.
 *
 * Requirements: The using class must have these properties:
 * - $wpdb - WordPress database object
 *
 * Methods included:
 * - create_webhook()
 * - get_webhooks()
 * - get_webhooks_for_event()
 * - update_webhook_triggered()
 * - update_webhook()
 * - delete_webhook()
 *
 * @package FormFlow_Pro
 * @since   2.9.0
 */


if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Trait for webhook database operations.
 */
trait Webhooks {

	/**
	 * Create a new webhook.
	 *
	 * @param array $data Webhook data.
	 * @return int|false The new webhook ID or false on failure.
	 */
	public function create_webhook( array $data ): int|false {
		$table = $this->wpdb->prefix . 'isf_webhooks';

		$result = $this->wpdb->insert( $table, [
			'instance_id' => $data['instance_id'] ?? null,
			'name'        => $data['name'],
			'url'         => $data['url'],
			'events'      => is_array( $data['events'] ) ? wp_json_encode( $data['events'] ) : $data['events'],
			'secret'      => $data['secret'] ?? null,
			'is_active'   => $data['is_active'] ?? 1,
		] );

		return $result ? $this->wpdb->insert_id : false;
	}

	/**
	 * Get all webhooks for an instance (or all if null).
	 *
	 * @param int|null $instance_id Instance ID.
	 * @param bool     $active_only Only return active webhooks.
	 * @return array List of webhooks.
	 */
	public function get_webhooks( ?int $instance_id = null, bool $active_only = false ): array {
		$table = $this->wpdb->prefix . 'isf_webhooks';

		$where  = [];
		$values = [];

		if ( $instance_id !== null ) {
			$where[]  = '(instance_id = %d OR instance_id IS NULL)';
			$values[] = $instance_id;
		}

		if ( $active_only ) {
			$where[] = 'is_active = 1';
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$sql          = "SELECT * FROM {$table} {$where_clause} ORDER BY name ASC";

		if ( ! empty( $values ) ) {
			$sql = $this->wpdb->prepare( $sql, ...$values );
		}

		$results = $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];

		// Decode events JSON.
		foreach ( $results as &$row ) {
			$row['events'] = json_decode( $row['events'], true ) ?: [];
		}

		return $results;
	}

	/**
	 * Get webhooks for a specific event.
	 *
	 * @param string   $event       Event name.
	 * @param int|null $instance_id Instance ID.
	 * @return array List of matching webhooks.
	 */
	public function get_webhooks_for_event( string $event, ?int $instance_id = null ): array {
		$webhooks = $this->get_webhooks( $instance_id, true );

		return array_filter( $webhooks, function ( $webhook ) use ( $event ) {
			return in_array( $event, $webhook['events'], true ) || in_array( '*', $webhook['events'], true );
		} );
	}

	/**
	 * Update webhook last triggered time.
	 *
	 * @param int  $webhook_id Webhook ID.
	 * @param bool $success    Whether the trigger was successful.
	 * @return bool Success.
	 */
	public function update_webhook_triggered( int $webhook_id, bool $success = true ): bool {
		$table = $this->wpdb->prefix . 'isf_webhooks';

		if ( ! $success ) {
			return $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$table} SET last_triggered_at = %s, failure_count = failure_count + 1 WHERE id = %d",
					current_time( 'mysql' ),
					$webhook_id
				)
			) !== false;
		}

		return $this->wpdb->update(
			$table,
			[ 'last_triggered_at' => current_time( 'mysql' ) ],
			[ 'id' => $webhook_id ]
		) !== false;
	}

	/**
	 * Update a webhook.
	 *
	 * @param int   $webhook_id Webhook ID.
	 * @param array $data       Webhook data to update.
	 * @return bool Success.
	 */
	public function update_webhook( int $webhook_id, array $data ): bool {
		$table = $this->wpdb->prefix . 'isf_webhooks';

		$update_data = [];

		if ( isset( $data['name'] ) ) {
			$update_data['name'] = $data['name'];
		}
		if ( isset( $data['url'] ) ) {
			$update_data['url'] = $data['url'];
		}
		if ( array_key_exists( 'instance_id', $data ) ) {
			$update_data['instance_id'] = $data['instance_id'];
		}
		if ( isset( $data['events'] ) ) {
			$update_data['events'] = wp_json_encode( $data['events'] );
		}
		if ( array_key_exists( 'secret', $data ) ) {
			$update_data['secret'] = $data['secret'];
		}
		if ( isset( $data['is_active'] ) ) {
			$update_data['is_active'] = $data['is_active'] ? 1 : 0;
		}

		if ( empty( $update_data ) ) {
			return false;
		}

		return $this->wpdb->update( $table, $update_data, [ 'id' => $webhook_id ] ) !== false;
	}

	/**
	 * Delete a webhook.
	 *
	 * @param int $webhook_id Webhook ID.
	 * @return bool Success.
	 */
	public function delete_webhook( int $webhook_id ): bool {
		$table = $this->wpdb->prefix . 'isf_webhooks';
		return $this->wpdb->delete( $table, [ 'id' => $webhook_id ] ) !== false;
	}
}
