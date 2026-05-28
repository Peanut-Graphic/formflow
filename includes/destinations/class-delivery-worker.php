<?php
/**
 * Delivery Worker
 *
 * Static callback invoked by Action Scheduler (or synchronously by the
 * dispatcher fallback). Loads a delivery row, looks up the destination
 * config, calls $destination->deliver(), records the outcome, and
 * schedules retries with exponential backoff on transient failures.
 *
 * On a successful delivery, honors the per-destination purge_after_export
 * flag by deleting the submission row.
 *
 * @package FormFlow
 * @subpackage Destinations
 * @since 2.9.0
 */

namespace ISF\Destinations;

if (!defined('ABSPATH')) {
    exit;
}

class DeliveryWorker {

    /** Retry backoff in seconds — index = attempt number. */
    private const BACKOFF = [60, 300, 1800]; // 1m, 5m, 30m

    /** Cap retries at this many total attempts including the first. */
    private const MAX_ATTEMPTS = 4;

    /**
     * AS callback. Signature must accept a single args array.
     */
    public static function process(array $args): void {
        $delivery_id = (int) ($args['delivery_id'] ?? 0);
        if ($delivery_id <= 0) {
            return;
        }

        $row = DeliveryLog::get($delivery_id);
        if (!$row) {
            return;
        }

        // Already terminal; don't re-run.
        if (in_array($row->status, [DeliveryLog::STATUS_SUCCEEDED, DeliveryLog::STATUS_FAILED], true)) {
            return;
        }

        DeliveryLog::increment_attempt($delivery_id);

        $destination = DestinationRegistry::instance()->get((string) $row->destination_id);
        if (!$destination) {
            DeliveryLog::mark_failed(
                $delivery_id,
                DeliveryResult::fail(
                    sprintf(
                        /* translators: %s: destination ID */
                        __('Destination "%s" is not registered.', 'formflow'),
                        $row->destination_id
                    ),
                    DeliveryFailure::CONFIG
                ),
                false
            );
            return;
        }

        [$submission, $dest_config] = self::load_context($row);
        if ($submission === null || $dest_config === null) {
            DeliveryLog::mark_failed(
                $delivery_id,
                DeliveryResult::fail(
                    __('Could not load submission or destination config.', 'formflow'),
                    DeliveryFailure::CONFIG
                ),
                false
            );
            return;
        }

        // Decrypt sensitive credential fields before handing to the destination.
        if ($destination instanceof BaseDestination) {
            $dest_config['config'] = $destination->decrypt_sensitive_fields(
                $dest_config['config'] ?? []
            );
        }

        try {
            $result = $destination->deliver($submission, $dest_config['config'] ?? []);
        } catch (\Throwable $e) {
            $result = DeliveryResult::fail(
                $e->getMessage() ?: __('Unhandled delivery exception.', 'formflow'),
                DeliveryFailure::UNKNOWN
            );
        }

        if ($result->success) {
            DeliveryLog::mark_succeeded($delivery_id, $result);
            do_action(
                'isf_delivery_succeeded',
                $delivery_id,
                (int) $row->submission_id,
                (int) $row->instance_id,
                (string) $row->destination_id
            );
            self::maybe_purge_submission($row, $dest_config);
            return;
        }

        // Failure path: maybe retry.
        $attempt = (int) DeliveryLog::get($delivery_id)->attempt_count;
        $can_retry = $result->should_retry() && $attempt < self::MAX_ATTEMPTS;

        DeliveryLog::mark_failed($delivery_id, $result, $can_retry);

        if ($can_retry && function_exists('as_schedule_single_action')) {
            $delay = self::BACKOFF[min($attempt - 1, count(self::BACKOFF) - 1)] ?? 1800;
            as_schedule_single_action(
                time() + $delay,
                DeliveryDispatcher::AS_HOOK,
                [['delivery_id' => $delivery_id]],
                DeliveryDispatcher::AS_GROUP
            );
            do_action(
                'isf_delivery_retry_scheduled',
                $delivery_id,
                $attempt,
                $delay,
                $result->failure_kind
            );
        } else {
            do_action(
                'isf_delivery_failed',
                $delivery_id,
                (int) $row->submission_id,
                (int) $row->instance_id,
                (string) $row->destination_id,
                $result->message,
                $result->failure_kind
            );
        }
    }

    /**
     * Pull the submission payload + the destination config slot for this delivery.
     *
     * @return array{0: array|null, 1: array|null}
     */
    private static function load_context(object $row): array {
        global $wpdb;

        $submission_id = (int) $row->submission_id;
        $instance_id = (int) $row->instance_id;

        // Submission
        $sub_table = $wpdb->prefix . 'isf_submissions';
        $sub = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$sub_table} WHERE id = %d", $submission_id),
            ARRAY_A
        );
        if (!$sub) {
            return [null, null];
        }

        $form_data = json_decode((string) ($sub['form_data'] ?? '{}'), true);
        if (!is_array($form_data)) {
            $form_data = [];
        }

        // Instance + destinations
        $inst_table = $wpdb->prefix . 'isf_instances';
        $inst = $wpdb->get_row(
            $wpdb->prepare("SELECT name, slug, settings FROM {$inst_table} WHERE id = %d", $instance_id),
            ARRAY_A
        );
        if (!$inst) {
            return [null, null];
        }

        $settings = json_decode((string) ($inst['settings'] ?? '{}'), true);
        if (!is_array($settings)) {
            $settings = [];
        }
        $destinations = $settings['destinations'] ?? [];
        if (!is_array($destinations)) {
            $destinations = [];
        }

        // Find the destination slot — prefer matching on (type, name)
        // since multiple destinations of the same type can be configured.
        $target = null;
        foreach ($destinations as $d) {
            if (!is_array($d) || empty($d['is_active'])) {
                continue;
            }
            if (($d['type'] ?? '') !== $row->destination_id) {
                continue;
            }
            if (($d['name'] ?? '') === $row->destination_label) {
                $target = $d;
                break;
            }
            // Fall back to the first matching type if the label isn't found.
            $target = $target ?? $d;
        }

        if ($target === null) {
            return [null, null];
        }

        // Build the submission payload handed to deliver(). Includes the
        // form_data fields plus useful metadata.
        $payload = $form_data + [
            'submission_id' => $submission_id,
            'submitted_at'  => $sub['created_at'] ?? '',
            'instance_slug' => $inst['slug'] ?? '',
            'instance_name' => $inst['name'] ?? '',
        ];

        return [$payload, $target];
    }

    /**
     * Honor purge_after_export when the destination requested it AND the
     * delivery succeeded.
     */
    private static function maybe_purge_submission(object $row, array $dest_config): void {
        $config = $dest_config['config'] ?? [];
        if (empty($config['purge_after_export'])) {
            return;
        }

        global $wpdb;
        $sub_table = $wpdb->prefix . 'isf_submissions';
        $wpdb->delete($sub_table, ['id' => (int) $row->submission_id], ['%d']);

        do_action('isf_submission_purged_after_export', (int) $row->submission_id, (int) $row->instance_id);
    }
}
