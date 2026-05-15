<?php
namespace ISF\Admin;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Admin Tools Handlers Trait
 *
 * @package    FormFlow
 * @subpackage Admin
 * @since      2.8.4
 */


use ISF\Security;

/**
 * Trait Admin_Tools
 *
 * Diagnostics, attribution, and form builder handlers.
 *
 * @since 2.8.4
 */
trait Admin_Tools {
    // =========================================================================
    // Diagnostics AJAX Handlers
    // =========================================================================

    /**
     * Run full diagnostics via AJAX
     */
    public function ajax_run_diagnostics(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $instance_id = isset($_POST['instance_id']) ? (int)$_POST['instance_id'] : null;
        $instance_id = $instance_id ?: null;

        require_once ISF_PLUGIN_DIR . 'includes/class-diagnostics.php';

        try {
            $diagnostics = new \ISF\Diagnostics();
            $results = $diagnostics->run_all_tests($instance_id);

            // Log the diagnostics run
            $this->db->log_audit(
                'diagnostics_run',
                'system',
                null,
                'Full diagnostics',
                [
                    'instance_id' => $instance_id,
                    'passed' => $results['summary']['passed'],
                    'failed' => $results['summary']['failed'],
                    'warnings' => $results['summary']['warnings'],
                ]
            );

            wp_send_json_success($results);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run quick health check via AJAX
     */
    public function ajax_quick_health_check(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        require_once ISF_PLUGIN_DIR . 'includes/class-diagnostics.php';

        try {
            $diagnostics = new \ISF\Diagnostics();
            $status = $diagnostics->quick_health_check();

            wp_send_json_success($status);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Test SMS configuration via AJAX
     */
    public function ajax_test_sms(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $test_number = sanitize_text_field($_POST['test_number'] ?? '');
        $config = [
            'account_sid' => sanitize_text_field($_POST['account_sid'] ?? ''),
            'auth_token' => sanitize_text_field($_POST['auth_token'] ?? ''),
            'from_number' => sanitize_text_field($_POST['from_number'] ?? ''),
        ];

        require_once ISF_PLUGIN_DIR . 'includes/class-sms-handler.php';

        $result = \ISF\SmsHandler::test_configuration($config, $test_number);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Test team webhook configuration via AJAX
     */
    public function ajax_test_team_webhook(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $webhook_url = esc_url_raw($_POST['webhook_url'] ?? '');
        $provider = sanitize_text_field($_POST['provider'] ?? 'slack');

        require_once ISF_PLUGIN_DIR . 'includes/class-team-notifications.php';

        $result = \ISF\TeamNotifications::test_webhook($webhook_url, $provider);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Send test digest email via AJAX
     */
    public function ajax_test_digest(): void {
        if (!Security::verify_ajax_request('isf_admin_nonce', 'manage_options')) {
            return;
        }

        $instance_id = (int)($_POST['instance_id'] ?? 0);
        $recipient = sanitize_email($_POST['recipient'] ?? '');

        require_once ISF_PLUGIN_DIR . 'includes/class-email-digest.php';

        $result = \ISF\EmailDigest::send_test($instance_id, $recipient);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    // =========================================================================
    // Attribution Page - Marketing Analytics & Conversion Tracking
    // =========================================================================

    /**
     * Render the attribution analytics page
     */
    public function render_attribution(): void {
        $instances = $this->db->get_instances();

        // Get filter parameters
        $instance_id = isset($_GET['instance_id']) ? (int)$_GET['instance_id'] : 0;
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');
        $attribution_model = isset($_GET['model']) ? sanitize_text_field($_GET['model']) : 'first_touch';

        // Validate attribution model
        $valid_models = ['first_touch', 'last_touch', 'linear', 'time_decay', 'position_based'];
        if (!in_array($attribution_model, $valid_models)) {
            $attribution_model = 'first_touch';
        }

        include ISF_PLUGIN_DIR . 'admin/views/attribution-report.php';
    }

    /**
     * Render the import completions page
     */
    public function render_import_completions(): void {
        $instances = $this->db->get_instances();

        // Get import history
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-completion-importer.php';
        $importer = new \ISF\Analytics\CompletionImporter();
        $import_history = $importer->get_import_history(10);

        include ISF_PLUGIN_DIR . 'admin/views/import-completions.php';
    }

    /**
     * Render the analytics settings page
     */
    public function render_analytics_settings(): void {
        $instances = $this->db->get_instances();
        $settings = get_option('isf_settings', []);

        include ISF_PLUGIN_DIR . 'admin/views/analytics-settings.php';
    }

    // =========================================================================
    // Visual Form Builder Page
    // =========================================================================

    /**
     * Render the visual form builder page
     */
    public function render_form_builder(): void {
        include ISF_PLUGIN_DIR . 'admin/views/form-builder.php';
    }

    /**
     * AJAX handler: Save form builder schema
     */
    public function ajax_builder_save(): void {
        check_ajax_referer('isf_builder_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'formflow')]);
        }

        $instance_id = isset($_POST['instance_id']) ? absint($_POST['instance_id']) : 0;
        $schema_json = isset($_POST['schema']) ? wp_unslash($_POST['schema']) : '';

        if (!$instance_id) {
            wp_send_json_error(['message' => __('Invalid instance ID.', 'formflow')]);
        }

        $schema = json_decode($schema_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid schema format.', 'formflow')]);
        }

        // Validate schema using FormBuilder class
        $builder = new \ISF\Builder\FormBuilder();
        $validation = $builder->validate_schema($schema);

        if (!$validation['valid']) {
            wp_send_json_error([
                'message' => __('Schema validation failed.', 'formflow'),
                'errors' => $validation['errors']
            ]);
        }

        // Get current instance settings
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_INSTANCES;
        $instance = $wpdb->get_row($wpdb->prepare("SELECT settings FROM {$table} WHERE id = %d", $instance_id));

        if (!$instance) {
            wp_send_json_error(['message' => __('Instance not found.', 'formflow')]);
        }

        // Update settings with new schema
        $settings = json_decode($instance->settings, true) ?: [];
        $settings['form_schema'] = $schema;

        $updated = $wpdb->update(
            $table,
            ['settings' => wp_json_encode($settings)],
            ['id' => $instance_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => __('Failed to save schema.', 'formflow')]);
        }

        wp_send_json_success(['message' => __('Form saved successfully.', 'formflow')]);
    }

    /**
     * AJAX handler: Preview form builder schema
     */
    public function ajax_builder_preview(): void {
        check_ajax_referer('isf_builder_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'formflow')]);
        }

        $schema_json = isset($_POST['schema']) ? wp_unslash($_POST['schema']) : '';

        $schema = json_decode($schema_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid schema format.', 'formflow')]);
        }

        // Render preview HTML using FormRenderer
        $renderer = new \ISF\Builder\FormRenderer();
        $html = $renderer->render($schema, [], []);

        wp_send_json_success(['html' => $html]);
    }
}
