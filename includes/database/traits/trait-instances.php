<?php
namespace ISF\Database\Traits;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Instance-related database operations.
 *
 * This trait contains all form instance management database methods.
 * It is designed to be used by ISF_Database class.
 *
 * Requirements: The using class must have these properties:
 * - $wpdb - WordPress database object
 * - $encryption - ISF_Encryption instance
 * - $table_instances - Instances table name
 *
 * Methods included:
 * - get_instances()
 * - get_instance()
 * - get_instance_by_slug()
 * - get_instance_by_utility()
 * - create_instance()
 * - update_instance()
 * - delete_instance()
 * - decode_instance()
 *
 * @package FormFlow_Pro
 * @since   2.9.0
 */


if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Trait for form instance database operations.
 */
trait Instances {

	/**
	 * Get all form instances.
	 *
	 * @param bool   $active_only Only return active instances.
	 * @param string $order_by    Column to order by.
	 * @return array List of instances.
	 */
	public function get_instances( bool $active_only = false, string $order_by = 'display_order' ): array {
		$sql = "SELECT * FROM {$this->table_instances}";

		if ( $active_only ) {
			$sql .= ' WHERE is_active = 1';
		}

		// Determine ordering.
		switch ( $order_by ) {
			case 'display_order':
				$sql .= ' ORDER BY display_order ASC, name ASC';
				break;
			case 'created_at':
				$sql .= ' ORDER BY created_at DESC';
				break;
			case 'name':
			default:
				$sql .= ' ORDER BY name ASC';
				break;
		}

		$results = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ $this, 'decode_instance' ], $results ?: [] );
	}

	/**
	 * Get a form instance by ID.
	 *
	 * @param int $id Instance ID.
	 * @return array|null Instance data or null.
	 */
	public function get_instance( int $id ): ?array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table_instances} WHERE id = %d",
			$id
		);

		$result = $this->wpdb->get_row( $sql, ARRAY_A );

		return $result ? $this->decode_instance( $result ) : null;
	}

	/**
	 * Get a form instance by slug.
	 *
	 * @param string $slug        Instance slug.
	 * @param bool   $active_only Only return if active.
	 * @return array|null Instance data or null.
	 */
	public function get_instance_by_slug( string $slug, bool $active_only = false ): ?array {
		if ( $active_only ) {
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

		$result = $this->wpdb->get_row( $sql, ARRAY_A );

		return $result ? $this->decode_instance( $result ) : null;
	}

	/**
	 * Get a form instance by utility code.
	 *
	 * @param string $utility     Utility code.
	 * @param bool   $active_only Only return if active.
	 * @return array|null Instance data or null.
	 */
	public function get_instance_by_utility( string $utility, bool $active_only = true ): ?array {
		if ( $active_only ) {
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

		$result = $this->wpdb->get_row( $sql, ARRAY_A );

		return $result ? $this->decode_instance( $result ) : null;
	}

	/**
	 * Create a new form instance.
	 *
	 * @param array $data Instance data.
	 * @return int|false The new instance ID or false on failure.
	 */
	public function create_instance( array $data ): int|false {
		$insert_data = [
			'name'               => $data['name'],
			'slug'               => sanitize_title( $data['slug'] ),
			'utility'            => $data['utility'],
			'form_type'          => $data['form_type'] ?? 'enrollment',
			'api_endpoint'       => $data['api_endpoint'],
			'api_password'       => $this->encryption->encrypt( $data['api_password'] ?? '' ),
			'support_email_from' => $data['support_email_from'] ?? '',
			'support_email_to'   => $data['support_email_to'] ?? '',
			'settings'           => wp_json_encode( $data['settings'] ?? [] ),
			'is_active'          => $data['is_active'] ?? 1,
			'test_mode'          => $data['test_mode'] ?? 0,
		];

		$result = $this->wpdb->insert( $this->table_instances, $insert_data );

		if ( $result === false ) {
			return false;
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Update a form instance.
	 *
	 * @param int   $id   Instance ID.
	 * @param array $data Data to update.
	 * @return bool Success.
	 */
	public function update_instance( int $id, array $data ): bool {
		$update_data = [];

		if ( isset( $data['name'] ) ) {
			$update_data['name'] = $data['name'];
		}

		if ( isset( $data['slug'] ) ) {
			$update_data['slug'] = sanitize_title( $data['slug'] );
		}

		if ( isset( $data['utility'] ) ) {
			$update_data['utility'] = $data['utility'];
		}

		if ( isset( $data['form_type'] ) ) {
			$update_data['form_type'] = $data['form_type'];
		}

		if ( isset( $data['api_endpoint'] ) ) {
			$update_data['api_endpoint'] = $data['api_endpoint'];
		}

		if ( isset( $data['api_password'] ) && ! empty( $data['api_password'] ) ) {
			$update_data['api_password'] = $this->encryption->encrypt( $data['api_password'] );
		}

		if ( isset( $data['support_email_from'] ) ) {
			$update_data['support_email_from'] = $data['support_email_from'];
		}

		if ( isset( $data['support_email_to'] ) ) {
			$update_data['support_email_to'] = $data['support_email_to'];
		}

		if ( isset( $data['settings'] ) ) {
			$update_data['settings'] = wp_json_encode( $data['settings'] );
		}

		if ( isset( $data['is_active'] ) ) {
			$update_data['is_active'] = (int) $data['is_active'];
		}

		if ( isset( $data['test_mode'] ) ) {
			$update_data['test_mode'] = (int) $data['test_mode'];
		}

		if ( empty( $update_data ) ) {
			return true;
		}

		$result = $this->wpdb->update(
			$this->table_instances,
			$update_data,
			[ 'id' => $id ]
		);

		return $result !== false;
	}

	/**
	 * Delete a form instance.
	 *
	 * @param int $id Instance ID.
	 * @return bool Success.
	 */
	public function delete_instance( int $id ): bool {
		// Foreign keys will cascade delete submissions.
		$result = $this->wpdb->delete(
			$this->table_instances,
			[ 'id' => $id ],
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Decode instance data (decrypt password, parse JSON).
	 *
	 * @param array $instance Raw instance data.
	 * @return array Decoded instance.
	 */
	private function decode_instance( array $instance ): array {
		$instance['api_password'] = $this->encryption->decrypt( $instance['api_password'] ?? '' );
		$instance['settings']     = json_decode( $instance['settings'] ?? '{}', true ) ?: [];
		$instance['is_active']    = (bool) $instance['is_active'];
		$instance['test_mode']    = (bool) $instance['test_mode'];

		return $instance;
	}
}
