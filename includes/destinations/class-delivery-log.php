<?php
/**
 * Delivery Log
 *
 * Reads + writes the wp_isf_deliveries table. One row per submission +
 * destination pair, tracking queue → succeeded/failed lifecycle plus retry
 * count and last error.
 *
 * Table is created in the plugin activator (see class-activator.php).
 *
 * @package FormFlow
 * @since 2.9.0
 */

namespace ISF\Destinations;

if (!defined('ABSPATH')) {
    exit;
}

class DeliveryLog {

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RETRY     = 'retry';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'isf_deliveries';
    }

    /**
     * SQL for the deliveries table (called from the activator on install /
     * upgrade). Kept here so the schema lives next to its accessor.
     */
    public static function get_schema_sql(): string {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT(20) UNSIGNED NOT NULL,
            instance_id BIGINT(20) UNSIGNED NOT NULL,
            destination_id VARCHAR(64) NOT NULL,
            destination_label VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            failure_kind VARCHAR(32) NOT NULL DEFAULT '',
            payload_hash VARCHAR(64) NOT NULL DEFAULT '',
            meta LONGTEXT NULL,
            delivered_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY instance_status (instance_id, status),
            KEY submission_id (submission_id),
            KEY destination_id (destination_id),
            KEY status_updated (status, updated_at)
        ) $charset_collate;";
    }

    /**
     * Insert a new queued row when a submission is dispatched to a destination.
     *
     * @return int|false Inserted ID, or false on failure.
     */
    public static function insert_queued(
        int $submission_id,
        int $instance_id,
        string $destination_id,
        string $destination_label = ''
    ) {
        global $wpdb;

        $result = $wpdb->insert(
            self::table_name(),
            [
                'submission_id'     => $submission_id,
                'instance_id'       => $instance_id,
                'destination_id'    => $destination_id,
                'destination_label' => $destination_label,
                'status'            => self::STATUS_QUEUED,
                'attempt_count'     => 0,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d']
        );

        return $result === false ? false : (int) $wpdb->insert_id;
    }

    /**
     * Mark a delivery row as succeeded.
     */
    public static function mark_succeeded(int $id, DeliveryResult $result): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::table_name(),
            [
                'status'       => self::STATUS_SUCCEEDED,
                'last_error'   => null,
                'failure_kind' => '',
                'payload_hash' => $result->payload_hash,
                'meta'         => wp_json_encode($result->meta),
                'delivered_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Mark a delivery row as failed (or queued-for-retry).
     */
    public static function mark_failed(int $id, DeliveryResult $result, bool $will_retry): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::table_name(),
            [
                'status'       => $will_retry ? self::STATUS_RETRY : self::STATUS_FAILED,
                'last_error'   => $result->message,
                'failure_kind' => $result->failure_kind,
                'meta'         => wp_json_encode($result->meta),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Increment the attempt counter (called by the worker before each delivery).
     */
    public static function increment_attempt(int $id): bool {
        global $wpdb;
        $table = self::table_name();
        return false !== $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET attempt_count = attempt_count + 1 WHERE id = %d",
                $id
            )
        );
    }

    public static function get(int $id): ?object {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE id = %d", $id)
        );
        return $row ?: null;
    }

    /**
     * Get all deliveries for a given submission (for the admin Data tab).
     */
    public static function get_for_submission(int $submission_id): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_name() . " WHERE submission_id = %d ORDER BY id ASC",
                $submission_id
            )
        );
        return $rows ?: [];
    }
}
