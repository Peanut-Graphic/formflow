<?php
namespace ISF\Admin;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Admin Webhook Handlers Trait
 *
 * @package    FormFlow
 * @subpackage Admin
 * @since      2.8.4
 */


use ISF\Security;

/**
 * Trait Admin_Webhooks
 *
 * AJAX handlers for webhook management.
 *
 * @since 2.8.4
 */
trait Admin_Webhooks {
    // =========================================================================
    // Webhook AJAX Handlers
    // =========================================================================

    /**
     * Get a single webhook via AJAX
     */
    public function ajax_get_webhook(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $webhook_id = (int)($_POST['webhook_id'] ?? 0);
        if (!$webhook_id) {
            wp_send_json_error(['message' => __('Invalid webhook ID.', 'formflow')]);
            return;
        }

        $webhooks = $this->db->get_webhooks();
        $webhook = null;
        foreach ($webhooks as $wh) {
            if ($wh['id'] == $webhook_id) {
                $webhook = $wh;
                break;
            }
        }

        if (!$webhook) {
            wp_send_json_error(['message' => __('Webhook not found.', 'formflow')]);
            return;
        }

        wp_send_json_success(['webhook' => $webhook]);
    }

    /**
     * Save (create or update) a webhook via AJAX
     */
    public function ajax_save_webhook(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $webhook_id = (int)($_POST['webhook_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        $url = esc_url_raw($_POST['url'] ?? '');
        $instance_id = (int)($_POST['instance_id'] ?? 0) ?: null;
        $events = $_POST['events'] ?? [];
        $secret = sanitize_text_field($_POST['secret'] ?? '');
        $is_active = (bool)($_POST['is_active'] ?? false);

        // Validate required fields
        if (empty($name) || empty($url)) {
            wp_send_json_error(['message' => __('Name and URL are required.', 'formflow')]);
            return;
        }

        if (empty($events)) {
            wp_send_json_error(['message' => __('Please select at least one event.', 'formflow')]);
            return;
        }

        // Sanitize events
        $valid_events = array_keys(\ISF\WebhookHandler::get_available_events());
        $events = array_intersect((array)$events, $valid_events);

        if (empty($events)) {
            wp_send_json_error(['message' => __('Please select valid events.', 'formflow')]);
            return;
        }

        $data = [
            'name' => $name,
            'url' => $url,
            'instance_id' => $instance_id,
            'events' => $events,
            'secret' => $secret,
            'is_active' => $is_active,
        ];

        if ($webhook_id) {
            // Update existing webhook
            $success = $this->db->update_webhook($webhook_id, $data);
            $message = __('Webhook updated successfully.', 'formflow');
            $audit_action = 'webhook_update';
        } else {
            // Create new webhook
            $success = $this->db->create_webhook($data);
            $message = __('Webhook created successfully.', 'formflow');
            $audit_action = 'webhook_create';
        }

        if ($success) {
            // Log the action
            $this->db->log_audit(
                $audit_action,
                'webhook',
                $webhook_id ?: $success,
                $name,
                ['url' => $url, 'events' => $events, 'is_active' => $is_active]
            );

            wp_send_json_success(['message' => $message]);
        } else {
            wp_send_json_error(['message' => __('Failed to save webhook.', 'formflow')]);
        }
    }

    /**
     * Delete a webhook via AJAX
     */
    public function ajax_delete_webhook(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $webhook_id = (int)($_POST['webhook_id'] ?? 0);
        if (!$webhook_id) {
            wp_send_json_error(['message' => __('Invalid webhook ID.', 'formflow')]);
            return;
        }

        // Get webhook info before deletion for audit log
        $webhooks = $this->db->get_webhooks();
        $webhook_name = 'Unknown';
        foreach ($webhooks as $wh) {
            if ($wh['id'] == $webhook_id) {
                $webhook_name = $wh['name'];
                break;
            }
        }

        if ($this->db->delete_webhook($webhook_id)) {
            // Log the deletion
            $this->db->log_audit(
                'webhook_delete',
                'webhook',
                $webhook_id,
                $webhook_name,
                []
            );

            wp_send_json_success(['message' => __('Webhook deleted successfully.', 'formflow')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete webhook.', 'formflow')]);
        }
    }

    /**
     * Test a webhook via AJAX
     */
    public function ajax_test_webhook(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $webhook_id = (int)($_POST['webhook_id'] ?? 0);
        if (!$webhook_id) {
            wp_send_json_error(['message' => __('Invalid webhook ID.', 'formflow')]);
            return;
        }

        // Get the webhook
        $webhooks = $this->db->get_webhooks();
        $webhook = null;
        foreach ($webhooks as $wh) {
            if ($wh['id'] == $webhook_id) {
                $webhook = $wh;
                break;
            }
        }

        if (!$webhook) {
            wp_send_json_error(['message' => __('Webhook not found.', 'formflow')]);
            return;
        }

        // Send test payload
        require_once ISF_PLUGIN_DIR . 'includes/class-webhook-handler.php';

        $test_data = [
            'submission_id' => 0,
            'instance_id' => $webhook['instance_id'] ?? 0,
            'form_data' => [
                'account_number' => '1234567890',
                'customer_name' => 'Test User',
                'device_type' => 'thermostat',
            ],
            'test' => true,
        ];

        $payload = [
            'event' => 'test',
            'timestamp' => current_time('c'),
            'data' => $test_data,
        ];

        $json_payload = json_encode($payload);

        $headers = [
            'Content-Type' => 'application/json',
            'X-ISF-Event' => 'test',
            'X-ISF-Timestamp' => time(),
        ];

        if (!empty($webhook['secret'])) {
            $signature = hash_hmac('sha256', $json_payload, $webhook['secret']);
            $headers['X-ISF-Signature'] = $signature;
        }

        $response = wp_remote_post($webhook['url'], [
            'headers' => $headers,
            'body' => $json_payload,
            'timeout' => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => $response->get_error_message(),
            ]);
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        wp_send_json_success([
            'status_code' => $status_code,
            'success' => $status_code >= 200 && $status_code < 300,
        ]);
    }

}
