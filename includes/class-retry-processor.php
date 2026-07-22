<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Retry Processor
 *
 * Processes the retry queue for failed API submissions.
 */


use ISF\Api\ApiClient;
use ISF\Database\Database;

class RetryProcessor {

    private Database $db;

    public function __construct() {
        $this->db = new Database();
    }

    /**
     * Process pending retries
     *
     * @param int $limit Maximum items to process
     * @return array Processing results
     */
    public function process(int $limit = 10): array {
        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'permanent_failures' => 0,
        ];

        // Only one retry processor may run at a time. Overlapping runs (cron
        // racing cron, or cron racing a manual "process now") would both claim
        // the same pending rows and both send the non-idempotent enroll POST —
        // a duplicate enrollment. If another run holds the lock, skip this pass;
        // the queue is drained by whichever run holds it.
        if (!$this->db->acquire_retry_lock()) {
            return $results;
        }

        // Holding the lock, no other processor is active — so any row still in
        // 'processing' was abandoned by a worker that died mid-attempt. Move
        // those to a terminal needs-review state rather than leaving them lost
        // or blindly re-enrolling them (see reclaim_stuck_retries()).
        $reclaimed = $this->db->reclaim_stuck_retries();
        if ($reclaimed > 0) {
            $this->db->log('warning', sprintf(
                'Reclaimed %d retry row(s) abandoned in processing; marked failed for manual review (possible duplicate risk).',
                $reclaimed
            ));
        }

        $pending = $this->db->get_pending_retries($limit);

        foreach ($pending as $item) {
            // Mark as processing
            $this->db->update_retry_status($item['id'], 'processing');

            try {
                $result = $this->retry_submission($item);
                $results['processed']++;

                if ($result['success']) {
                    $this->db->update_retry_status($item['id'], 'completed');
                    $results['succeeded']++;

                    // Update the original submission status
                    $this->db->update_submission($item['submission_id'], [
                        'status' => 'completed'
                    ]);

                    // Trigger webhook for successful retry
                    $this->trigger_webhook('enrollment.completed', $item);

                } else {
                    // Increment retry count
                    $maxed = !$this->db->increment_retry($item['id'], $result['error']);

                    if ($maxed) {
                        $results['permanent_failures']++;

                        // Trigger webhook for permanent failure
                        $this->trigger_webhook('enrollment.failed', $item, $result['error']);
                    } else {
                        $results['failed']++;
                    }
                }

            } catch (\Exception $e) {
                $this->db->increment_retry($item['id'], $e->getMessage());
                $results['failed']++;

                $this->db->log('error', 'Retry processing error: ' . $e->getMessage(), [
                    'queue_id' => $item['id'],
                    'submission_id' => $item['submission_id'],
                ], $item['instance_id']);
            }
        }

        // Log summary if any items were processed
        if ($results['processed'] > 0) {
            $this->db->log('info', sprintf(
                'Retry queue processed: %d items, %d succeeded, %d failed, %d permanent failures',
                $results['processed'],
                $results['succeeded'],
                $results['failed'],
                $results['permanent_failures']
            ), $results);
        }

        // Release the advisory lock for the next run. A per-item try/catch
        // already contains exceptions inside the loop, so process() does not
        // throw here; and if the worker is fatally killed before this line,
        // GET_LOCK is connection-scoped and MySQL frees it when the connection
        // drops — so the lock cannot leak across requests either way.
        $this->db->release_retry_lock();

        return $results;
    }

    /**
     * Retry a specific submission
     *
     * @param array $queue_item Queue item data
     * @return array Result with success status and error message
     */
    private function retry_submission(array $queue_item): array {
        // Get the submission and instance data
        $submission = $this->db->get_submission($queue_item['submission_id']);
        if (!$submission) {
            return [
                'success' => false,
                'error' => 'Submission not found',
            ];
        }

        $instance = $this->db->get_instance($queue_item['instance_id']);
        if (!$instance) {
            return [
                'success' => false,
                'error' => 'Instance not found',
            ];
        }

        // Skip demo mode instances
        if ($instance['settings']['demo_mode'] ?? false) {
            return [
                'success' => true,
                'message' => 'Demo mode - skipped',
            ];
        }

        $form_data = $submission['form_data'];

        try {
            $api = new ApiClient(
                $instance['api_endpoint'],
                $instance['api_password'],
                $instance['test_mode'],
                $instance['id']
            );

            // Determine what type of retry this is based on submission state
            if (!empty($form_data['schedule_date']) && !empty($form_data['schedule_time'])) {
                // This was a scheduling failure - retry booking
                $result = $this->retry_booking($api, $form_data);
            } else {
                // This was an enrollment failure - retry enrollment
                $result = $this->retry_enrollment($api, $form_data);
            }

            return $result;

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Retry enrollment submission
     *
     * @param ApiClient $api API client
     * @param array $form_data Form data
     * @return array Result
     */
    private function retry_enrollment(ApiClient $api, array $form_data): array {
        try {
            $response = $api->enroll($form_data);

            // Check for success in response
            if (!empty($response['success']) || !empty($response['confirmation_number'])) {
                return [
                    'success' => true,
                    'response' => $response,
                ];
            }

            return [
                'success' => false,
                'error' => $response['error'] ?? 'Unknown enrollment error',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Retry appointment booking
     *
     * @param ApiClient $api API client
     * @param array $form_data Form data
     * @return array Result
     */
    private function retry_booking(ApiClient $api, array $form_data): array {
        try {
            // Build equipment array
            $equipment = [];
            $scheduling_result = $form_data['scheduling_result'] ?? [];

            if (!empty($scheduling_result['equipment']['ac_heat']['count'])) {
                $equipment['15'] = [
                    'count' => $scheduling_result['equipment']['ac_heat']['count'],
                    'location' => $scheduling_result['equipment']['ac_heat']['location'] ?? '05',
                    'desired_device' => $scheduling_result['equipment']['ac_heat']['desired_device'] ?? '05'
                ];
            } else {
                if (!empty($scheduling_result['equipment']['ac']['count'])) {
                    $equipment['05'] = [
                        'count' => $scheduling_result['equipment']['ac']['count'],
                        'location' => $scheduling_result['equipment']['ac']['location'] ?? '05',
                        'desired_device' => $scheduling_result['equipment']['ac']['desired_device'] ?? '05'
                    ];
                }
                if (!empty($scheduling_result['equipment']['heat']['count'])) {
                    $equipment['20'] = [
                        'count' => $scheduling_result['equipment']['heat']['count'],
                        'location' => $scheduling_result['equipment']['heat']['location'] ?? '05',
                        'desired_device' => $scheduling_result['equipment']['heat']['desired_device'] ?? '05'
                    ];
                }
            }

            $fsr = $form_data['fsr_no'] ?? '';
            $ca_no = $form_data['ca_no'] ?? $form_data['comverge_no'] ?? '';

            $response = $api->book_appointment(
                $fsr,
                $ca_no,
                $form_data['schedule_date'],
                $form_data['schedule_time'],
                $equipment
            );

            // Check for success
            if (is_array($response) && (!empty($response['success']) || !empty($response['confirmation']))) {
                return [
                    'success' => true,
                    'response' => $response,
                ];
            }

            // String response might be success
            if (is_string($response) && (
                stripos($response, 'success') !== false ||
                stripos($response, 'confirmed') !== false
            )) {
                return [
                    'success' => true,
                    'response' => $response,
                ];
            }

            return [
                'success' => false,
                'error' => is_array($response) ? ($response['error'] ?? 'Unknown booking error') : $response,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Trigger webhook for retry event
     *
     * @param string $event Event name
     * @param array $queue_item Queue item
     * @param string|null $error Error message for failures
     */
    private function trigger_webhook(string $event, array $queue_item, ?string $error = null): void {
        require_once ISF_PLUGIN_DIR . 'includes/class-webhook-handler.php';

        $submission = $this->db->get_submission($queue_item['submission_id']);
        if (!$submission) {
            return;
        }

        $webhook_handler = new WebhookHandler();

        $data = [
            'submission_id' => $queue_item['submission_id'],
            'instance_id' => $queue_item['instance_id'],
            'retry_count' => $queue_item['retry_count'],
            'form_data' => [
                'account_number' => $submission['account_number'],
                'customer_name' => $submission['customer_name'],
                'device_type' => $submission['device_type'],
            ],
        ];

        if ($error) {
            $data['error'] = $error;
        }

        $webhook_handler->trigger($event, $data, $queue_item['instance_id']);
    }
}
