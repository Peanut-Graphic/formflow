<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Webhook Handler
 *
 * Handles sending webhook notifications for enrollment events.
 */


use ISF\Database\Database;

class WebhookHandler {

    private Database $db;

    /**
     * Available webhook events
     */
    public const EVENTS = [
        'enrollment.completed' => 'Enrollment Completed',
        'enrollment.failed' => 'Enrollment Failed',
        'appointment.scheduled' => 'Appointment Scheduled',
        'account.validated' => 'Account Validated',
    ];

    public function __construct() {
        $this->db = new Database();
    }

    /**
     * Trigger webhooks for an event
     *
     * @param string $event Event name
     * @param array $data Event data
     * @param int|null $instance_id Instance ID
     * @return array Results for each webhook
     */
    public function trigger(string $event, array $data, ?int $instance_id = null): array {
        $webhooks = $this->db->get_webhooks_for_event($event, $instance_id);
        $results = [];

        foreach ($webhooks as $webhook) {
            $result = $this->send($webhook, $event, $data);
            $results[$webhook['id']] = $result;

            // Update webhook record
            $this->db->update_webhook_triggered($webhook['id'], $result['success']);

            // Log the webhook call
            $this->db->log(
                $result['success'] ? 'info' : 'warning',
                "Webhook {$webhook['name']}: " . ($result['success'] ? 'Success' : 'Failed'),
                [
                    'webhook_id' => $webhook['id'],
                    'event' => $event,
                    'status_code' => $result['status_code'] ?? null,
                    'error' => $result['error'] ?? null,
                ],
                $instance_id
            );
        }

        return $results;
    }

    /**
     * Send a webhook request
     *
     * @param array $webhook Webhook configuration
     * @param string $event Event name
     * @param array $data Event data
     * @return array Result with success status
     */
    private function send(array $webhook, string $event, array $data): array {
        $payload = [
            'event' => $event,
            'timestamp' => current_time('c'),
            'data' => $data,
        ];

        $json_payload = json_encode($payload);

        $headers = [
            'Content-Type' => 'application/json',
            'X-ISF-Event' => $event,
            'X-ISF-Timestamp' => time(),
        ];

        // Add signature if secret is configured
        if (!empty($webhook['secret'])) {
            $signature = hash_hmac('sha256', $json_payload, $webhook['secret']);
            $headers['X-ISF-Signature'] = $signature;
        }

        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $json_payload,
            'timeout' => 15,
            'sslverify' => true,
        ];

        $response = wp_remote_request($webhook['url'], $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);

        return [
            'success' => $status_code >= 200 && $status_code < 300,
            'status_code' => $status_code,
            'body' => wp_remote_retrieve_body($response),
        ];
    }

    /**
     * Get available events for display
     *
     * @return array Event name => Label pairs
     */
    public static function get_available_events(): array {
        return self::EVENTS;
    }
}
