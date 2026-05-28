<?php
/**
 * Delivery Dispatcher
 *
 * Listens for completed submissions and dispatches them to every active
 * destination configured on the instance. Inserts wp_isf_deliveries rows
 * and enqueues an Action Scheduler job per delivery; falls back to
 * synchronous delivery when Action Scheduler is unavailable.
 *
 * @package FormFlow
 * @subpackage Destinations
 * @since 2.9.0
 */

namespace ISF\Destinations;

if (!defined('ABSPATH')) {
    exit;
}

class DeliveryDispatcher {

    /** Action Scheduler hook name fired per queued delivery. */
    public const AS_HOOK = 'isf_destination_deliver';

    /** Action Scheduler group, so admins can find these jobs. */
    public const AS_GROUP = 'isf-destinations';

    private bool $action_scheduler_available;

    public function __construct() {
        $this->action_scheduler_available = function_exists('as_schedule_single_action');
    }

    /**
     * Wire the dispatcher into the plugin's form-completed hook.
     */
    public function init(): void {
        add_action(\ISF\Hooks::FORM_COMPLETED, [$this, 'dispatch'], 20, 1);

        if ($this->action_scheduler_available) {
            add_action(self::AS_HOOK, [DeliveryWorker::class, 'process'], 10, 1);
        }
    }

    /**
     * Hook callback for isf_form_completed.
     *
     * @param array $submission_data Same shape produced by trait-ajax-handlers
     *                               (submission_id, instance_id, instance_slug,
     *                                form_data, status, ...).
     */
    public function dispatch(array $submission_data): void {
        $submission_id = (int) ($submission_data['submission_id'] ?? 0);
        $instance_id = (int) ($submission_data['instance_id'] ?? 0);

        if ($submission_id <= 0 || $instance_id <= 0) {
            return;
        }

        $destinations = $this->get_active_destinations_for_instance($instance_id);
        if (empty($destinations)) {
            return;
        }

        foreach ($destinations as $idx => $dest_config) {
            $destination_id = (string) ($dest_config['type'] ?? '');
            $destination_label = (string) ($dest_config['name'] ?? $destination_id);

            if ($destination_id === '') {
                continue;
            }

            $delivery_id = DeliveryLog::insert_queued(
                $submission_id,
                $instance_id,
                $destination_id,
                $destination_label
            );

            if ($delivery_id === false) {
                continue;
            }

            do_action('isf_delivery_queued', $delivery_id, $submission_id, $instance_id, $destination_id);

            if ($this->action_scheduler_available) {
                as_schedule_single_action(
                    time() + 1,
                    self::AS_HOOK,
                    [['delivery_id' => $delivery_id]],
                    self::AS_GROUP
                );
            } else {
                // Synchronous fallback: run inline. Slow for many destinations
                // but keeps deliveries from getting silently dropped on installs
                // without Action Scheduler.
                DeliveryWorker::process(['delivery_id' => $delivery_id]);
            }
        }
    }

    /**
     * Pull the active destinations config for an instance.
     *
     * Destinations are stored on the instance row's settings JSON:
     *   settings.destinations = [
     *     [
     *       'type'      => 'sftp',
     *       'name'      => 'Dominion Energy PTR SFTP',
     *       'is_active' => true,
     *       'config'    => [host, port, ...]  // encrypted creds stored here
     *     ],
     *     ...
     *   ]
     *
     * @return array<array{type:string,name:string,is_active:bool,config:array}>
     */
    private function get_active_destinations_for_instance(int $instance_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_instances';

        $instance = $wpdb->get_row(
            $wpdb->prepare("SELECT settings FROM {$table} WHERE id = %d", $instance_id),
            ARRAY_A
        );

        if (!$instance) {
            return [];
        }

        $settings = json_decode((string) ($instance['settings'] ?? ''), true);
        if (!is_array($settings)) {
            return [];
        }

        $destinations = $settings['destinations'] ?? [];
        if (!is_array($destinations)) {
            return [];
        }

        return array_values(array_filter(
            $destinations,
            fn ($d) => is_array($d) && !empty($d['is_active']) && !empty($d['type'])
        ));
    }
}
