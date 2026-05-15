<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Main Plugin Class
 *
 * Orchestrates the plugin by loading dependencies and registering hooks.
 */


class Plugin {

    /**
     * Admin instance
     */
    private ?Admin\Admin $admin = null;

    /**
     * Public (frontend) instance
     */
    private ?Frontend\Frontend $public = null;

    /**
     * Run the plugin
     */
    public function run(): void {
        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_analytics_hooks();
        $this->register_cron_handlers();
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies(): void {
        // Core classes are autoloaded, but we need to ensure they exist
        require_once ISF_PLUGIN_DIR . 'includes/class-security.php';
        require_once ISF_PLUGIN_DIR . 'includes/class-encryption.php';
        require_once ISF_PLUGIN_DIR . 'includes/database/class-database.php';
        require_once ISF_PLUGIN_DIR . 'includes/class-hooks.php';

        // API classes
        require_once ISF_PLUGIN_DIR . 'includes/api/class-xml-parser.php';
        require_once ISF_PLUGIN_DIR . 'includes/api/class-api-client.php';
        require_once ISF_PLUGIN_DIR . 'includes/api/class-mock-api-client.php';
        require_once ISF_PLUGIN_DIR . 'includes/api/class-validation-result.php';
        require_once ISF_PLUGIN_DIR . 'includes/api/class-scheduling-result.php';
        require_once ISF_PLUGIN_DIR . 'includes/api/class-response-validator.php';

        // Form classes
        require_once ISF_PLUGIN_DIR . 'includes/forms/class-form-handler.php';
        require_once ISF_PLUGIN_DIR . 'includes/forms/class-email-handler.php';

        // Analytics classes
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-visitor-tracker.php';
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-touch-recorder.php';
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-handoff-tracker.php';
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-completion-receiver.php';
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-completion-importer.php';
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-gtm-helper.php';
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-attribution-calculator.php';
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-analytics-diagnostics.php';
        require_once ISF_PLUGIN_DIR . 'includes/analytics/class-attribution-exporter.php';
        require_once ISF_PLUGIN_DIR . 'includes/api/class-handoff-endpoint.php';

        // Peanut Suite integration (loads after plugins_loaded for proper detection)
        require_once ISF_PLUGIN_DIR . 'includes/class-peanut-integration.php';
        add_action('plugins_loaded', function() {
            PeanutIntegration::instance();
        }, 15); // Priority 15 to run after Peanut Suite loads
    }

    /**
     * Set up internationalization
     */
    private function set_locale(): void {
        add_action('plugins_loaded', function() {
            load_plugin_textdomain(
                'formflow',
                false,
                dirname(ISF_PLUGIN_BASENAME) . '/languages'
            );
        });
    }

    /**
     * Register admin-side hooks
     */
    private function define_admin_hooks(): void {
        if (!is_admin()) {
            return;
        }

        require_once ISF_PLUGIN_DIR . 'admin/class-admin.php';
        $this->admin = new Admin\Admin();

        // Admin notices for security warnings
        add_action('admin_notices', [$this, 'display_admin_notices']);
        add_action('wp_ajax_isf_dismiss_notice', [$this, 'ajax_dismiss_notice']);

        // Admin menu
        add_action('admin_menu', [$this->admin, 'add_admin_menu']);

        // Admin assets
        add_action('admin_enqueue_scripts', [$this->admin, 'enqueue_styles']);
        add_action('admin_enqueue_scripts', [$this->admin, 'enqueue_scripts']);

        // Admin AJAX handlers
        add_action('wp_ajax_isf_save_instance', [$this->admin, 'ajax_save_instance']);
        add_action('wp_ajax_isf_delete_instance', [$this->admin, 'ajax_delete_instance']);
        add_action('wp_ajax_isf_test_api', [$this->admin, 'ajax_test_api']);
        add_action('wp_ajax_isf_get_logs', [$this->admin, 'ajax_get_logs']);
        add_action('wp_ajax_isf_test_form', [$this->admin, 'ajax_test_form']);
        add_action('wp_ajax_isf_mark_test_data', [$this->admin, 'ajax_mark_test_data']);
        add_action('wp_ajax_isf_delete_test_data', [$this->admin, 'ajax_delete_test_data']);
        add_action('wp_ajax_isf_get_test_counts', [$this->admin, 'ajax_get_test_counts']);
        add_action('wp_ajax_isf_clear_analytics', [$this->admin, 'ajax_clear_analytics']);
        add_action('wp_ajax_isf_check_api_health', [$this->admin, 'ajax_check_api_health']);

        // Webhook AJAX handlers
        add_action('wp_ajax_isf_get_webhook', [$this->admin, 'ajax_get_webhook']);
        add_action('wp_ajax_isf_save_webhook', [$this->admin, 'ajax_save_webhook']);
        add_action('wp_ajax_isf_delete_webhook', [$this->admin, 'ajax_delete_webhook']);
        add_action('wp_ajax_isf_test_webhook', [$this->admin, 'ajax_test_webhook']);

        // API usage AJAX handlers
        add_action('wp_ajax_isf_get_api_usage', [$this->admin, 'ajax_get_api_usage']);

        // Submission details & export AJAX handlers
        add_action('wp_ajax_isf_get_submission_details', [$this->admin, 'ajax_get_submission_details']);
        add_action('wp_ajax_isf_export_submissions_csv', [$this->admin, 'ajax_export_submissions_csv']);

        // Instance management AJAX handlers
        add_action('wp_ajax_isf_duplicate_instance', [$this->admin, 'ajax_duplicate_instance']);
        add_action('wp_ajax_isf_save_instance_order', [$this->admin, 'ajax_save_instance_order']);

        // Form Builder AJAX handlers
        add_action('wp_ajax_isf_builder_save', [$this->admin, 'ajax_builder_save']);
        add_action('wp_ajax_isf_builder_preview', [$this->admin, 'ajax_builder_preview']);

        // Bulk actions AJAX handlers
        add_action('wp_ajax_isf_bulk_submissions_action', [$this->admin, 'ajax_bulk_submissions_action']);
        add_action('wp_ajax_isf_bulk_logs_action', [$this->admin, 'ajax_bulk_logs_action']);

        // Reports AJAX handlers
        add_action('wp_ajax_isf_save_scheduled_report', [$this->admin, 'ajax_save_scheduled_report']);
        add_action('wp_ajax_isf_get_scheduled_report', [$this->admin, 'ajax_get_scheduled_report']);
        add_action('wp_ajax_isf_delete_scheduled_report', [$this->admin, 'ajax_delete_scheduled_report']);
        add_action('wp_ajax_isf_send_report_now', [$this->admin, 'ajax_send_report_now']);
        add_action('wp_ajax_isf_generate_custom_report', [$this->admin, 'ajax_generate_custom_report']);
        add_action('wp_ajax_isf_export_analytics_csv', [$this->admin, 'ajax_export_analytics_csv']);

        // Attribution export AJAX handler
        Analytics\AttributionExporter::register();

        // Compliance (GDPR, Audit Log, Data Retention) AJAX handlers
        add_action('wp_ajax_isf_gdpr_search', [$this->admin, 'ajax_gdpr_search']);
        add_action('wp_ajax_isf_gdpr_export', [$this->admin, 'ajax_gdpr_export']);
        add_action('wp_ajax_isf_gdpr_anonymize', [$this->admin, 'ajax_gdpr_anonymize']);
        add_action('wp_ajax_isf_gdpr_delete', [$this->admin, 'ajax_gdpr_delete']);
        add_action('wp_ajax_isf_get_audit_log', [$this->admin, 'ajax_get_audit_log']);
        add_action('wp_ajax_isf_get_gdpr_requests', [$this->admin, 'ajax_get_gdpr_requests']);
        add_action('wp_ajax_isf_preview_retention', [$this->admin, 'ajax_preview_retention']);
        add_action('wp_ajax_isf_run_retention', [$this->admin, 'ajax_run_retention']);
        add_action('wp_ajax_isf_save_retention_settings', [$this->admin, 'ajax_save_retention_settings']);

        // Diagnostics AJAX handlers
        add_action('wp_ajax_isf_run_diagnostics', [$this->admin, 'ajax_run_diagnostics']);
        add_action('wp_ajax_isf_quick_health_check', [$this->admin, 'ajax_quick_health_check']);

        // Feature testing AJAX handlers
        add_action('wp_ajax_isf_test_sms', [$this->admin, 'ajax_test_sms']);
        add_action('wp_ajax_isf_test_team_webhook', [$this->admin, 'ajax_test_team_webhook']);
        add_action('wp_ajax_isf_test_digest', [$this->admin, 'ajax_test_digest']);
    }

    /**
     * Register public-facing hooks
     */
    private function define_public_hooks(): void {
        require_once ISF_PLUGIN_DIR . 'public/class-public.php';
        $this->public = new Frontend\Frontend();

        // Register shortcodes
        add_shortcode('isf_form', [$this->public, 'render_form_shortcode']);
        add_shortcode('isf_enroll_button', [$this->public, 'render_enroll_button_shortcode']);

        // Frontend assets
        add_action('wp_enqueue_scripts', [$this->public, 'enqueue_styles']);
        add_action('wp_enqueue_scripts', [$this->public, 'enqueue_scripts']);

        // Public AJAX handlers (both logged in and not logged in)
        $ajax_actions = [
            'isf_load_step',
            'isf_validate_account',
            'isf_get_schedule_slots',
            'isf_submit_enrollment',
            'isf_book_appointment',
            'isf_save_progress',
            'isf_save_and_email',
            'isf_resume_form',
            'isf_track_step',
        ];

        foreach ($ajax_actions as $action) {
            add_action("wp_ajax_{$action}", [$this->public, $action]);
            add_action("wp_ajax_nopriv_{$action}", [$this->public, $action]);
        }
    }

    /**
     * Register analytics and attribution hooks
     */
    private function define_analytics_hooks(): void {
        // Initialize visitor tracking early (before any output)
        add_action('init', [$this, 'init_visitor_tracking'], 5);

        // Handle handoff redirects from URL parameter
        add_action('template_redirect', [Api\HandoffEndpoint::class, 'handle_redirect_param'], 5);

        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_analytics_rest_routes']);

        // AJAX handlers for touch recording
        add_action('wp_ajax_isf_record_touch', [$this, 'ajax_record_touch']);
        add_action('wp_ajax_nopriv_isf_record_touch', [$this, 'ajax_record_touch']);

        // Cron handler for expiring old handoffs
        add_action('isf_expire_handoffs', [$this, 'expire_old_handoffs']);

        // Schedule handoff expiration if not already scheduled
        if (!wp_next_scheduled('isf_expire_handoffs')) {
            wp_schedule_event(time(), 'daily', 'isf_expire_handoffs');
        }
    }

    /**
     * Initialize visitor tracking
     */
    public function init_visitor_tracking(): void {
        // Only initialize on frontend, not in admin or AJAX
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        $visitor_tracker = new Analytics\VisitorTracker();
        $visitor_tracker->init();
    }

    /**
     * Register analytics REST API routes
     */
    public function register_analytics_rest_routes(): void {
        $handoff_endpoint = new Api\HandoffEndpoint();
        $handoff_endpoint->register_routes();

        $completion_receiver = new Analytics\CompletionReceiver();
        $completion_receiver->register_routes();
    }

    /**
     * AJAX handler for recording touches
     */
    public function ajax_record_touch(): void {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'isf_frontend')) {
            wp_send_json_error(['message' => 'Invalid security token']);
        }

        $touch_type = sanitize_key($_POST['touch_type'] ?? '');
        $instance_id = isset($_POST['instance_id']) ? absint($_POST['instance_id']) : null;
        $extra_data = [];

        if (!empty($_POST['extra_data'])) {
            $extra_data = json_decode(stripslashes($_POST['extra_data']), true) ?: [];
        }

        $visitor_tracker = new Analytics\VisitorTracker();
        $touch_recorder = new Analytics\TouchRecorder($visitor_tracker);

        $touch_id = $touch_recorder->record_touch($touch_type, $instance_id, $extra_data);

        if ($touch_id) {
            wp_send_json_success(['touch_id' => $touch_id]);
        } else {
            wp_send_json_error(['message' => 'Failed to record touch']);
        }
    }

    /**
     * Expire old handoffs (cron job)
     */
    public function expire_old_handoffs(): void {
        $handoff_tracker = new Analytics\HandoffTracker();
        $expired_count = $handoff_tracker->expire_old_handoffs(168); // 7 days

        if ($expired_count > 0) {
            $db = new Database\Database();
            $db->log('info', "Expired {$expired_count} old handoffs");
        }
    }

    /**
     * Display admin notices for security warnings
     */
    public function display_admin_notices(): void {
        // Only show on plugin pages or dashboard
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $is_plugin_page = strpos($screen->id, 'isf-') !== false || $screen->id === 'dashboard';
        if (!$is_plugin_page) {
            return;
        }

        // Only show to users who can manage options
        if (!current_user_can('manage_options')) {
            return;
        }

        // Check if notice was dismissed
        $dismissed = get_option('isf_dismissed_notices', []);

        // Check encryption key status
        $key_status = Encryption::get_key_status();
        if ($key_status['status'] !== 'ok' && !in_array('encryption_key_' . $key_status['code'], $dismissed)) {
            $notice_class = $key_status['status'] === 'error' ? 'notice-error' : 'notice-warning';
            ?>
            <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible" data-isf-notice="encryption_key_<?php echo esc_attr($key_status['code']); ?>">
                <p>
                    <strong><?php esc_html_e('FormFlow Security Notice:', 'formflow'); ?></strong>
                    <?php echo esc_html($key_status['message']); ?>
                </p>
                <p>
                    <code>define('ISF_ENCRYPTION_KEY', '<?php echo esc_html(wp_generate_password(32, false)); ?>');</code>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=isf-tools&tab=settings')); ?>">
                        <?php esc_html_e('View Security Settings', 'formflow'); ?>
                    </a>
                </p>
            </div>
            <script>
            jQuery(document).ready(function($) {
                $('[data-isf-notice]').on('click', '.notice-dismiss', function() {
                    var notice = $(this).closest('[data-isf-notice]').data('isf-notice');
                    $.post(ajaxurl, {
                        action: 'isf_dismiss_notice',
                        notice: notice,
                        nonce: '<?php echo esc_js(wp_create_nonce('isf_dismiss_notice')); ?>'
                    });
                });
            });
            </script>
            <?php
        }
    }

    /**
     * AJAX handler for dismissing admin notices
     */
    public function ajax_dismiss_notice(): void {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'isf_dismiss_notice')) {
            wp_send_json_error(['message' => 'Invalid security token']);
        }

        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Sanitize notice ID
        $notice = isset($_POST['notice']) ? sanitize_key($_POST['notice']) : '';
        if (empty($notice)) {
            wp_send_json_error(['message' => 'Invalid notice ID']);
        }

        // Get current dismissed notices and add this one
        $dismissed = get_option('isf_dismissed_notices', []);
        if (!is_array($dismissed)) {
            $dismissed = [];
        }

        if (!in_array($notice, $dismissed)) {
            $dismissed[] = $notice;
            update_option('isf_dismissed_notices', $dismissed);
        }

        wp_send_json_success(['dismissed' => $notice]);
    }

    /**
     * Register cron event handlers
     */
    private function register_cron_handlers(): void {
        // Add custom cron schedules (needs to be added early)
        add_filter('cron_schedules', [Activator::class, 'add_cron_schedules']);

        // Register cron handlers
        add_action('isf_cleanup_sessions', [$this, 'cleanup_abandoned_sessions']);
        add_action('isf_cleanup_logs', [$this, 'cleanup_old_logs']);
        add_action('isf_process_retry_queue', [$this, 'process_retry_queue']);
        add_action('isf_send_scheduled_reports', [$this, 'send_scheduled_reports']);
        add_action('isf_apply_retention_policy', [$this, 'apply_retention_policy']);

        // Ensure all scheduled events are registered (reschedule if missing)
        add_action('init', [$this, 'ensure_cron_events_scheduled']);
    }

    /**
     * Ensure all cron events are scheduled (reschedule if missing)
     */
    public function ensure_cron_events_scheduled(): void {
        // Only run once per day to avoid overhead
        $last_check = get_transient('isf_cron_check');
        if ($last_check) {
            return;
        }
        set_transient('isf_cron_check', true, DAY_IN_SECONDS);

        // Reschedule missing events
        if (!wp_next_scheduled('isf_cleanup_sessions')) {
            wp_schedule_event(time(), 'daily', 'isf_cleanup_sessions');
        }

        if (!wp_next_scheduled('isf_cleanup_logs')) {
            wp_schedule_event(time(), 'weekly', 'isf_cleanup_logs');
        }

        if (!wp_next_scheduled('isf_process_retry_queue')) {
            wp_schedule_event(time(), 'five_minutes', 'isf_process_retry_queue');
        }

        if (!wp_next_scheduled('isf_send_scheduled_reports')) {
            wp_schedule_event(time(), 'hourly', 'isf_send_scheduled_reports');
        }

        if (!wp_next_scheduled('isf_apply_retention_policy')) {
            wp_schedule_event(time(), 'daily', 'isf_apply_retention_policy');
        }
    }

    /**
     * Clean up abandoned form sessions
     */
    public function cleanup_abandoned_sessions(): void {
        $settings = get_option('isf_settings', []);
        $hours = $settings['cleanup_abandoned_hours'] ?? 24;

        $db = new Database\Database();
        $db->mark_abandoned_sessions($hours);
    }

    /**
     * Clean up old log entries
     */
    public function cleanup_old_logs(): void {
        $settings = get_option('isf_settings', []);
        $days = $settings['log_retention_days'] ?? 90;

        $db = new Database\Database();
        $db->delete_old_logs($days);
    }

    /**
     * Process the retry queue for failed submissions
     */
    public function process_retry_queue(): void {
        require_once ISF_PLUGIN_DIR . 'includes/class-retry-processor.php';

        $processor = new RetryProcessor();
        $processor->process();
    }

    /**
     * Send scheduled reports that are due
     */
    public function send_scheduled_reports(): void {
        require_once ISF_PLUGIN_DIR . 'includes/class-report-generator.php';

        $db = new Database\Database();
        $due_reports = $db->get_due_reports();

        if (empty($due_reports)) {
            return;
        }

        $generator = new ReportGenerator($db);

        foreach ($due_reports as $report) {
            try {
                $result = $generator->send_scheduled_report($report);
                if ($result) {
                    $db->update_report_sent($report['id']);
                }
            } catch (\Exception $e) {
                // Log the error but continue with other reports
                $db->create_log([
                    'log_type' => 'error',
                    'message' => 'Failed to send scheduled report: ' . $report['name'],
                    'details' => ['error' => $e->getMessage(), 'report_id' => $report['id']],
                ]);
            }
        }
    }

    /**
     * Apply data retention policy (cron job)
     */
    public function apply_retention_policy(): void {
        $settings = get_option('isf_settings', []);

        // Only run if retention is enabled
        if (empty($settings['retention_enabled'])) {
            return;
        }

        $db = new Database\Database();
        $result = $db->apply_retention_policy($settings);

        // Log the automated execution
        $db->log_audit(
            'retention_policy_cron',
            'system',
            null,
            'Automated execution',
            [
                'settings' => [
                    'retention_submissions_days' => $settings['retention_submissions_days'] ?? 365,
                    'retention_analytics_days' => $settings['retention_analytics_days'] ?? 180,
                    'retention_audit_log_days' => $settings['retention_audit_log_days'] ?? 365,
                    'retention_api_usage_days' => $settings['retention_api_usage_days'] ?? 90,
                    'anonymize_instead_of_delete' => $settings['anonymize_instead_of_delete'] ?? true,
                ],
                'result' => $result,
            ]
        );
    }

    /**
     * Get admin instance
     */
    public function get_admin(): ?Admin\Admin {
        return $this->admin;
    }

    /**
     * Get public instance
     */
    public function get_public(): ?Frontend\Frontend {
        return $this->public;
    }
}
