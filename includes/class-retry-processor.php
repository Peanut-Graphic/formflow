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
            'needs_review' => 0,
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

                } elseif (!empty($result['ambiguous'])) {
                    // The enroll POST is not idempotent, and this failure could
                    // mean the request reached IntelliSource and enrolled the
                    // customer (a read timeout, a dropped connection after send,
                    // or a 2xx body we couldn't parse). Auto-retrying would risk
                    // a duplicate enrollment — exactly the corruption the utility
                    // cannot tolerate. Park it terminally instead of re-queuing:
                    // 'failed' is a stop state that get_pending_retries() never
                    // re-selects, so nothing retries it. A human must confirm
                    // whether the enrollment actually went through.
                    //
                    // ('failed' is reused deliberately: the status column is a
                    // MySQL ENUM, so a fresh value would be coerced to '' in
                    // production. The needs-review meaning is carried by the
                    // distinct webhook event and the last_error text below.)
                    $this->db->update_retry_status(
                        $item['id'],
                        'failed',
                        'NEEDS MANUAL REVIEW — enrollment may have reached IntelliSource '
                        . '(ambiguous transmission failure); not auto-retried to avoid a '
                        . 'duplicate enrollment. Verify in IntelliSource before re-submitting. '
                        . 'Original error: ' . ($result['error'] ?? 'unknown')
                    );

                    $results['needs_review']++;

                    $this->db->log('warning', 'Enrollment retry parked for manual review (possible duplicate risk)', [
                        'queue_id' => $item['id'],
                        'submission_id' => $item['submission_id'],
                        'error' => $result['error'] ?? '',
                    ], $item['instance_id']);

                    // Distinct from enrollment.failed so operators can route
                    // possible-duplicates to a human rather than a dead-letter.
                    $this->trigger_webhook('enrollment.needs_review', $item, $result['error'] ?? null);

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
                'Retry queue processed: %d items, %d succeeded, %d failed, %d permanent failures, %d parked for review',
                $results['processed'],
                $results['succeeded'],
                $results['failed'],
                $results['permanent_failures'],
                $results['needs_review']
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

            // A parsed response with no success flag means IntelliSource
            // received the request and returned a business-level rejection.
            // That is a definitive negative, not an ambiguous outcome — safe
            // to treat as an ordinary retryable/permanent failure.
            return [
                'success' => false,
                'error' => $response['error'] ?? 'Unknown enrollment error',
            ];

        } catch (\Exception $e) {
            // ApiClient::request() throws ApiException($message, $status): a
            // non-zero code is an HTTP status the server returned (it received
            // the request); code 0 is a transport-level failure whose message
            // carries the cURL reason. Only failures we can prove happened
            // before any bytes were sent may be safely retried.
            return [
                'success' => false,
                'ambiguous' => !$this->enroll_failure_is_transmission_safe(
                    (int) $e->getCode(),
                    $e->getMessage()
                ),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Can a failed enrollment attempt be safely auto-retried without risking a
     * duplicate enrollment?
     *
     * The enroll POST is not idempotent, so retry is safe ONLY when we can
     * prove the request never reached IntelliSource — i.e. a DNS resolution
     * failure or a refused/failed TCP connection, where no bytes were sent.
     * Everything else is ambiguous and must NOT be retried:
     *   - any non-zero HTTP status: the server received the request;
     *   - a read timeout: the request may have been delivered and processed;
     *   - "max retries exceeded": ApiClient exhausted its own attempts, state
     *     unknown;
     *   - an unparseable body: a 2xx response we couldn't read — the server
     *     almost certainly enrolled the customer.
     *
     * @param int    $status  ApiException code (HTTP status, or 0 for transport)
     * @param string $message ApiException message (carries the cURL reason at 0)
     * @return bool True only when the failure is provably pre-transmission.
     */
    private function enroll_failure_is_transmission_safe(int $status, string $message): bool {
        // Any HTTP status the server returned means it received the request.
        if ($status !== 0) {
            return false;
        }

        $m = strtolower($message);

        // Provably pre-transmission transport failures: no request was sent.
        $pre_transmission = [
            'could not resolve host',
            "couldn't resolve host",
            "couldn't resolve",
            'name or service not known',
            'failed to connect',
            "couldn't connect",
            'connection refused',
        ];

        foreach ($pre_transmission as $needle) {
            if (strpos($m, $needle) !== false) {
                return true;
            }
        }

        // Timeouts, "max retries exceeded", parse failures, resets, unknown —
        // all ambiguous. Fail safe: do not retry.
        return false;
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
            if (is_string($response) && $this->booking_response_reads_as_success($response)) {
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
     * Does a plain-string booking response indicate success?
     *
     * The old check was `stripos($r,'success') || stripos($r,'confirmed')`,
     * which matched the substring inside "unsuccessful" — so a response like
     * "Booking unsuccessful" was read as a SUCCESS, marking a failed booking
     * completed and firing enrollment.completed while the appointment was
     * silently lost. Guard against the negative words before accepting a
     * positive token.
     *
     * @param string $response Raw string response from the booking API.
     * @return bool True only when the response reads as an unambiguous success.
     */
    private function booking_response_reads_as_success(string $response): bool {
        $r = strtolower($response);

        // Any negative marker disqualifies the response, even if it also
        // contains "success" (as "unsuccessful" does).
        $negatives = ['unsuccess', 'not success', 'unable', 'fail', 'error', 'denied', 'invalid', 'reject'];
        foreach ($negatives as $needle) {
            if (strpos($r, $needle) !== false) {
                return false;
            }
        }

        return strpos($r, 'success') !== false || strpos($r, 'confirmed') !== false;
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
