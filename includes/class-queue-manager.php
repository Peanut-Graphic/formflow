<?php
/**
 * Queue Manager
 *
 * Handles asynchronous task processing using Action Scheduler.
 * Provides queueing for API calls, notifications, webhooks, and other async operations.
 *
 * @package FormFlow
 * @since 2.1.0
 */

namespace ISF;

use ISF\Database\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class QueueManager
 */
class QueueManager {

    /**
     * Action Scheduler group name
     */
    public const GROUP = 'isf_queue';

    /**
     * Queue action names
     */
    public const ACTION_API_CALL = 'isf_queue_api_call';
    public const ACTION_SEND_EMAIL = 'isf_queue_send_email';
    public const ACTION_SEND_SMS = 'isf_queue_send_sms';
    public const ACTION_WEBHOOK = 'isf_queue_webhook';
    public const ACTION_CRM_SYNC = 'isf_queue_crm_sync';
    public const ACTION_RETRY = 'isf_queue_retry';

    /**
     * Database instance
     */
    private Database $db;

    /**
     * Whether Action Scheduler is available
     */
    private bool $as_available;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database();
        $this->as_available = $this->check_action_scheduler();
    }

    /**
     * Initialize queue manager
     */
    public function init(): void {
        // Register action handlers
        add_action(self::ACTION_API_CALL, [$this, 'process_api_call'], 10, 2);
        add_action(self::ACTION_SEND_EMAIL, [$this, 'process_send_email'], 10, 2);
        add_action(self::ACTION_SEND_SMS, [$this, 'process_send_sms'], 10, 2);
        add_action(self::ACTION_WEBHOOK, [$this, 'process_webhook'], 10, 2);
        add_action(self::ACTION_CRM_SYNC, [$this, 'process_crm_sync'], 10, 2);
        add_action(self::ACTION_RETRY, [$this, 'process_retry'], 10, 2);

        // Admin notice if Action Scheduler not available
        if (!$this->as_available && is_admin()) {
            add_action('admin_notices', [$this, 'show_as_notice']);
        }
    }

    /**
     * Check if Action Scheduler is available
     *
     * @return bool
     */
    private function check_action_scheduler(): bool {
        // Check if Action Scheduler functions exist
        if (function_exists('as_schedule_single_action')) {
            return true;
        }

        // Try to load bundled Action Scheduler
        $as_path = ISF_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
        if (file_exists($as_path)) {
            require_once $as_path;
            return function_exists('as_schedule_single_action');
        }

        return false;
    }

    /**
     * Show admin notice if Action Scheduler unavailable
     */
    public function show_as_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'isf') === false) {
            return;
        }

        // Don't interrupt the instance-editor flow with a perf warning —
        // it belongs on Dashboard / Tools.
        if (strpos($screen->id, 'isf-instance-editor') !== false) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo esc_html__(
            'FormFlow Pro: Action Scheduler not found. Async processing will fall back to synchronous mode. ' .
            'Install WooCommerce or the Action Scheduler plugin for better performance.',
            'formflow'
        );
        echo '</p></div>';
    }

    /**
     * Queue an API call
     *
     * @param string $action API action (validate, enroll, schedule, book)
     * @param array $data Request data
     * @param int $instance_id Instance ID
     * @param int $priority Priority (1-10, lower = higher priority)
     * @param int $delay Delay in seconds before processing
     * @return int|bool Action ID or false on failure
     */
    public function queue_api_call(
        string $action,
        array $data,
        int $instance_id,
        int $priority = 5,
        int $delay = 0
    ): int|bool {
        $args = [
            'action' => $action,
            'data' => $data,
            'instance_id' => $instance_id,
            'priority' => $priority,
            'queued_at' => time(),
        ];

        return $this->schedule_action(self::ACTION_API_CALL, $args, $delay);
    }

    /**
     * Queue an email
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $message Email body
     * @param array $headers Email headers
     * @param array $attachments Attachments
     * @param int $delay Delay in seconds
     * @return int|bool Action ID or false
     */
    public function queue_email(
        string $to,
        string $subject,
        string $message,
        array $headers = [],
        array $attachments = [],
        int $delay = 0
    ): int|bool {
        $args = [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'attachments' => $attachments,
            'queued_at' => time(),
        ];

        return $this->schedule_action(self::ACTION_SEND_EMAIL, $args, $delay);
    }

    /**
     * Queue an SMS
     *
     * @param string $to Phone number
     * @param string $message SMS message
     * @param int $instance_id Instance ID for Twilio credentials
     * @param int $delay Delay in seconds
     * @return int|bool Action ID or false
     */
    public function queue_sms(
        string $to,
        string $message,
        int $instance_id,
        int $delay = 0
    ): int|bool {
        $args = [
            'to' => $to,
            'message' => $message,
            'instance_id' => $instance_id,
            'queued_at' => time(),
        ];

        return $this->schedule_action(self::ACTION_SEND_SMS, $args, $delay);
    }

    /**
     * Queue a webhook delivery
     *
     * @param string $url Webhook URL
     * @param array $payload Webhook payload
     * @param string $event Event name
     * @param int $instance_id Instance ID
     * @param int $delay Delay in seconds
     * @return int|bool Action ID or false
     */
    public function queue_webhook(
        string $url,
        array $payload,
        string $event,
        int $instance_id,
        int $delay = 0
    ): int|bool {
        $args = [
            'url' => $url,
            'payload' => $payload,
            'event' => $event,
            'instance_id' => $instance_id,
            'queued_at' => time(),
        ];

        return $this->schedule_action(self::ACTION_WEBHOOK, $args, $delay);
    }

    /**
     * Queue CRM sync
     *
     * @param int $submission_id Submission ID
     * @param int $instance_id Instance ID
     * @param string $crm_type CRM type (salesforce, hubspot, zoho, custom)
     * @param int $delay Delay in seconds
     * @return int|bool Action ID or false
     */
    public function queue_crm_sync(
        int $submission_id,
        int $instance_id,
        string $crm_type,
        int $delay = 0
    ): int|bool {
        $args = [
            'submission_id' => $submission_id,
            'instance_id' => $instance_id,
            'crm_type' => $crm_type,
            'queued_at' => time(),
        ];

        return $this->schedule_action(self::ACTION_CRM_SYNC, $args, $delay);
    }

    /**
     * Queue a retry for a failed action
     *
     * @param string $original_action Original action that failed
     * @param array $args Original arguments
     * @param int $attempt Current attempt number
     * @param int $max_attempts Maximum attempts
     * @return int|bool Action ID or false
     */
    public function queue_retry(
        string $original_action,
        array $args,
        int $attempt = 1,
        int $max_attempts = 3
    ): int|bool {
        if ($attempt > $max_attempts) {
            $this->log_failure($original_action, $args, 'Max retry attempts exceeded');
            return false;
        }

        // Exponential backoff: 30s, 2min, 8min
        $delay = pow(4, $attempt - 1) * 30;

        $retry_args = [
            'original_action' => $original_action,
            'original_args' => $args,
            'attempt' => $attempt,
            'max_attempts' => $max_attempts,
            'queued_at' => time(),
        ];

        return $this->schedule_action(self::ACTION_RETRY, $retry_args, $delay);
    }

    /**
     * Schedule an action
     *
     * @param string $hook Action hook name
     * @param array $args Action arguments
     * @param int $delay Delay in seconds
     * @return int|bool Action ID or false
     */
    private function schedule_action(string $hook, array $args, int $delay = 0): int|bool {
        if ($this->as_available) {
            // Use Action Scheduler
            $timestamp = time() + $delay;

            return as_schedule_single_action(
                $timestamp,
                $hook,
                [$args, uniqid('isf_', true)],
                self::GROUP
            );
        }

        // Fallback to immediate processing
        if ($delay === 0) {
            do_action($hook, $args, uniqid('isf_sync_', true));
            return true;
        }

        // For delayed actions without AS, use WP Cron (less reliable)
        $event_id = uniqid('isf_cron_', true);
        wp_schedule_single_event(time() + $delay, $hook, [$args, $event_id]);

        return true;
    }

    /**
     * Process queued API call
     *
     * @param array $args
     * @param string $action_id
     */
    public function process_api_call(array $args, string $action_id): void {
        $start = microtime(true);

        try {
            $instance = $this->db->get_instance($args['instance_id']);
            if (!$instance) {
                throw new \Exception('Instance not found: ' . $args['instance_id']);
            }

            // Get connector
            $connector = $this->get_connector_for_instance($instance);
            if (!$connector) {
                throw new \Exception('Connector not available');
            }

            $config = $this->get_connector_config($instance);

            // Execute the API call
            $result = match ($args['action']) {
                'validate' => $connector->validate_account($args['data'], $config),
                'enroll' => $connector->submit_enrollment($args['data'], $config),
                'schedule' => $connector->get_schedule_slots($args['data'], $config),
                'book' => $connector->book_appointment($args['data'], $config),
                default => throw new \Exception('Unknown API action: ' . $args['action']),
            };

            // Store result in transient for retrieval
            $result_key = 'isf_api_result_' . $action_id;
            set_transient($result_key, $result->toArray(), HOUR_IN_SECONDS);

            // Log success
            $elapsed = round((microtime(true) - $start) * 1000);
            $this->db->log('info', 'Queue: API call completed', [
                'action' => $args['action'],
                'instance_id' => $args['instance_id'],
                'elapsed_ms' => $elapsed,
                'action_id' => $action_id,
            ], $args['instance_id']);

            /**
             * Action: Queue API call completed
             *
             * @param array $result Result data
             * @param array $args Original arguments
             * @param string $action_id Action ID
             */
            do_action('isf_queue_api_completed', $result->toArray(), $args, $action_id);

        } catch (\Exception $e) {
            $this->handle_failure(self::ACTION_API_CALL, $args, $e->getMessage());
        }
    }

    /**
     * Process queued email
     *
     * @param array $args
     * @param string $action_id
     */
    public function process_send_email(array $args, string $action_id): void {
        try {
            $sent = wp_mail(
                $args['to'],
                $args['subject'],
                $args['message'],
                $args['headers'],
                $args['attachments']
            );

            if (!$sent) {
                throw new \Exception('wp_mail returned false');
            }

            $this->db->log('info', 'Queue: Email sent', [
                'to' => $args['to'],
                'subject' => $args['subject'],
                'action_id' => $action_id,
            ]);

        } catch (\Exception $e) {
            $this->handle_failure(self::ACTION_SEND_EMAIL, $args, $e->getMessage());
        }
    }

    /**
     * Process queued SMS
     *
     * @param array $args
     * @param string $action_id
     */
    public function process_send_sms(array $args, string $action_id): void {
        try {
            $sms_handler = new SmsHandler();
            $result = $sms_handler->send(
                $args['to'],
                $args['message'],
                $args['instance_id']
            );

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'SMS send failed');
            }

            $this->db->log('info', 'Queue: SMS sent', [
                'to' => $args['to'],
                'action_id' => $action_id,
            ], $args['instance_id']);

        } catch (\Exception $e) {
            $this->handle_failure(self::ACTION_SEND_SMS, $args, $e->getMessage());
        }
    }

    /**
     * Process queued webhook
     *
     * @param array $args
     * @param string $action_id
     */
    public function process_webhook(array $args, string $action_id): void {
        try {
            $webhook_handler = new WebhookHandler();
            $result = $webhook_handler->deliver(
                $args['url'],
                $args['payload'],
                $args['event']
            );

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Webhook delivery failed');
            }

            $this->db->log('info', 'Queue: Webhook delivered', [
                'url' => $args['url'],
                'event' => $args['event'],
                'status' => $result['status_code'] ?? 0,
                'action_id' => $action_id,
            ], $args['instance_id']);

        } catch (\Exception $e) {
            $this->handle_failure(self::ACTION_WEBHOOK, $args, $e->getMessage());
        }
    }

    /**
     * Process queued CRM sync
     *
     * @param array $args
     * @param string $action_id
     */
    public function process_crm_sync(array $args, string $action_id): void {
        try {
            $crm = new CrmIntegration();
            $result = $crm->sync_submission(
                $args['submission_id'],
                $args['instance_id']
            );

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'CRM sync failed');
            }

            $this->db->log('info', 'Queue: CRM sync completed', [
                'submission_id' => $args['submission_id'],
                'crm_type' => $args['crm_type'],
                'action_id' => $action_id,
            ], $args['instance_id']);

        } catch (\Exception $e) {
            $this->handle_failure(self::ACTION_CRM_SYNC, $args, $e->getMessage());
        }
    }

    /**
     * Process retry action
     *
     * @param array $args
     * @param string $action_id
     */
    public function process_retry(array $args, string $action_id): void {
        $this->db->log('info', 'Queue: Retrying action', [
            'original_action' => $args['original_action'],
            'attempt' => $args['attempt'],
            'action_id' => $action_id,
        ]);

        // Re-queue the original action
        $this->schedule_action(
            $args['original_action'],
            array_merge($args['original_args'], ['retry_attempt' => $args['attempt']]),
            0
        );
    }

    /**
     * Handle action failure
     *
     * @param string $action
     * @param array $args
     * @param string $error
     */
    private function handle_failure(string $action, array $args, string $error): void {
        $attempt = ($args['retry_attempt'] ?? 0) + 1;

        $this->db->log('error', 'Queue: Action failed', [
            'action' => $action,
            'error' => $error,
            'attempt' => $attempt,
            'args' => $this->sanitize_args_for_log($args),
        ], $args['instance_id'] ?? null);

        // Queue retry if not exceeded max attempts
        $max_retries = (int) get_option('isf_queue_max_retries', 3);
        if ($attempt <= $max_retries) {
            $this->queue_retry($action, $args, $attempt, $max_retries);
        } else {
            $this->log_failure($action, $args, $error);
        }
    }

    /**
     * Log permanent failure
     *
     * @param string $action
     * @param array $args
     * @param string $error
     */
    private function log_failure(string $action, array $args, string $error): void {
        $this->db->log('error', 'Queue: Permanent failure', [
            'action' => $action,
            'error' => $error,
            'args' => $this->sanitize_args_for_log($args),
        ], $args['instance_id'] ?? null);

        /**
         * Action: Queue action permanently failed
         *
         * @param string $action Action that failed
         * @param array $args Action arguments
         * @param string $error Error message
         */
        do_action('isf_queue_permanent_failure', $action, $args, $error);
    }

    /**
     * Sanitize arguments for logging (remove sensitive data)
     *
     * @param array $args
     * @return array
     */
    private function sanitize_args_for_log(array $args): array {
        $sensitive_keys = ['password', 'api_password', 'api_key', 'token', 'secret'];

        array_walk_recursive($args, function (&$value, $key) use ($sensitive_keys) {
            if (in_array(strtolower($key), $sensitive_keys, true)) {
                $value = '***REDACTED***';
            }
        });

        return $args;
    }

    /**
     * Get connector for instance
     *
     * @param array $instance
     * @return Api\ApiConnectorInterface|null
     */
    private function get_connector_for_instance(array $instance): ?Api\ApiConnectorInterface {
        $settings = json_decode($instance['settings'] ?? '{}', true);
        $connector_id = $settings['connector'] ?? 'intellisource';

        return Api\ConnectorRegistry::instance()->get($connector_id);
    }

    /**
     * Get connector configuration from instance
     *
     * @param array $instance
     * @return array
     */
    private function get_connector_config(array $instance): array {
        $encryption = new Encryption();

        return [
            'api_endpoint' => $instance['api_endpoint'] ?? '',
            'api_password' => $encryption->decrypt($instance['api_password'] ?? ''),
            'test_mode' => (bool) ($instance['test_mode'] ?? false),
        ];
    }

    /**
     * Get queue statistics
     *
     * @return array
     */
    public function get_stats(): array {
        if (!$this->as_available) {
            return [
                'available' => false,
                'message' => 'Action Scheduler not available',
            ];
        }

        global $wpdb;

        $table = $wpdb->prefix . 'actionscheduler_actions';

        $stats = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) as count
                 FROM {$table}
                 WHERE `group_id` = (
                     SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups
                     WHERE slug = %s LIMIT 1
                 )
                 GROUP BY status",
                self::GROUP
            ),
            ARRAY_A
        );

        $result = [
            'available' => true,
            'pending' => 0,
            'running' => 0,
            'complete' => 0,
            'failed' => 0,
        ];

        foreach ($stats as $row) {
            $result[$row['status']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * Cancel all pending actions for an instance
     *
     * @param int $instance_id
     * @return int Number of cancelled actions
     */
    public function cancel_instance_actions(int $instance_id): int {
        if (!$this->as_available) {
            return 0;
        }

        $cancelled = 0;

        // Get all pending actions for this instance
        $actions = as_get_scheduled_actions([
            'group' => self::GROUP,
            'status' => 'pending',
            'per_page' => -1,
        ]);

        foreach ($actions as $action_id => $action) {
            $args = $action->get_args();
            if (isset($args[0]['instance_id']) && $args[0]['instance_id'] === $instance_id) {
                as_unschedule_action($action->get_hook(), $args, self::GROUP);
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * Check if Action Scheduler is available
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->as_available;
    }
}
