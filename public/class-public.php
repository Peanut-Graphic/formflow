<?php
namespace ISF\Frontend;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Frontend Controller
 *
 * Handles public-facing functionality including shortcode rendering and AJAX handlers.
 */


use ISF\Database\Database;
use ISF\Security;
use ISF\Encryption;
use ISF\Api\ApiClient;
use ISF\Api\MockApiClient;
use ISF\Api\FieldMapper;
use ISF\Api\FieldMappingException;
use ISF\FeatureManager;
use ISF\Forms\FormHandler;
use ISF\Analytics;

// Load traits.
require_once __DIR__ . '/traits/trait-ajax-handlers.php';

class Frontend {

    use Frontend_Ajax_Handlers;

    private Database $db;
    private Encryption $encryption;
    private FormHandler $form_handler;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database();
        $this->encryption = new Encryption();
        $this->form_handler = new FormHandler($this->db, new Security());
    }

    /**
     * Enqueue frontend styles
     */
    public function enqueue_styles(): void {
        wp_register_style(
            'isf-forms',
            ISF_PLUGIN_URL . 'public/assets/css/forms.css',
            [],
            ISF_VERSION
        );
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts(): void {
        wp_register_script(
            'isf-validation',
            ISF_PLUGIN_URL . 'public/assets/js/validation.js',
            [],
            ISF_VERSION,
            true
        );

        wp_register_script(
            'isf-inline-validation',
            ISF_PLUGIN_URL . 'public/assets/js/inline-validation.js',
            ['jquery'],
            ISF_VERSION,
            true
        );

        wp_register_script(
            'isf-auto-save',
            ISF_PLUGIN_URL . 'public/assets/js/auto-save.js',
            ['jquery'],
            ISF_VERSION,
            true
        );

        wp_register_script(
            'isf-enrollment',
            ISF_PLUGIN_URL . 'public/assets/js/enrollment.js',
            ['jquery', 'isf-validation', 'isf-inline-validation', 'isf-auto-save'],
            ISF_VERSION,
            true
        );

        wp_register_script(
            'isf-analytics',
            ISF_PLUGIN_URL . 'public/assets/js/analytics-integration.js',
            [],
            ISF_VERSION,
            true
        );

        wp_register_script(
            'isf-security',
            ISF_PLUGIN_URL . 'public/assets/js/security.js',
            [],
            ISF_VERSION,
            true
        );

        // Behavior layer for builder-rendered (form_type=custom) forms:
        // conditional show/hide, scroll-to-bottom gate, single-step submit.
        wp_register_script(
            'isf-builder-form',
            ISF_PLUGIN_URL . 'public/assets/js/builder-form.js',
            [],
            ISF_VERSION,
            true
        );
    }

    /**
     * Render the form shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_form_shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'instance' => '',
            'class' => ''
        ], $atts, 'isf_form');

        // Get instance by slug (must be active for frontend display)
        $instance = $this->db->get_instance_by_slug($atts['instance'], true);

        if (!$instance) {
            // Check if it exists but is inactive
            $inactive_instance = $this->db->get_instance_by_slug($atts['instance'], false);
            if ($inactive_instance) {
                return '<div class="isf-maintenance">' .
                    esc_html__('This form is currently unavailable. Please try again later.', 'formflow') .
                    '</div>';
            }

            if (current_user_can('manage_options')) {
                return '<div class="isf-error">' .
                    esc_html__('Form instance not found. Please check the shortcode slug.', 'formflow') .
                    '</div>';
            }
            return '';
        }

        // Handle external form type differently
        if ($instance['form_type'] === 'external') {
            return $this->render_external_form($instance, $atts);
        }

        // Custom form_type: render from builder schema via FormRenderer instead of
        // the hardcoded IntelliSOURCE enrollment wizard.
        if ($instance['form_type'] === 'custom') {
            return $this->render_custom_form($instance, $atts);
        }

        // Enqueue assets
        wp_enqueue_style('isf-forms');
        wp_enqueue_script('isf-enrollment');
        wp_enqueue_script('isf-security');

        // Localize security config
        $security = \ISF\SecurityHardening::instance();
        wp_localize_script('isf-security', 'isfSecurityConfig', $security->get_js_security_config());

        // Enqueue analytics if visitor analytics is enabled
        $features = FeatureManager::get_features($instance);
        if (!empty($features['visitor_analytics']['enabled'])) {
            wp_enqueue_script('isf-analytics');

            // Localize analytics config
            $gtm_helper = new Analytics\GtmHelper();
            wp_localize_script('isf-analytics', 'ISFAnalyticsConfig', $gtm_helper->get_js_config($instance));
        }

        // Localize script with instance data (variable name must match JS: isf_frontend)
        // Get Google Places API key from settings
        $settings = get_option('isf_settings', []);
        $google_places_key = $settings['google_places_api_key'] ?? '';

        // Get feature settings for this instance
        $features = FeatureManager::get_features($instance);

        wp_localize_script('isf-enrollment', 'isf_frontend', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('isf_form_nonce'),
            'instance_id' => $instance['id'],
            'instance_slug' => $instance['slug'],
            'form_type' => $instance['form_type'],
            'test_mode' => $instance['test_mode'],
            'google_places_key' => $google_places_key,
            'autosave_interval' => ($features['auto_save']['interval_seconds'] ?? 60) * 1000,
            'features' => $features,
            'strings' => [
                'loading' => __('Loading...', 'formflow'),
                'loading_dates' => __('Loading available dates...', 'formflow'),
                'validating' => __('Validating account...', 'formflow'),
                'submitting' => __('Submitting...', 'formflow'),
                'error' => __('An error occurred. Please try again.', 'formflow'),
                'network_error' => __('Network error. Please check your connection and try again.', 'formflow'),
                'validation_error' => __('Account validation failed. Please check your information.', 'formflow'),
                'submission_error' => __('Submission failed. Please try again.', 'formflow'),
                'schedule_error' => __('Unable to load available appointments.', 'formflow'),
                'required_field' => __('This field is required.', 'formflow'),
                'invalid_email' => __('Please enter a valid email address.', 'formflow'),
                'invalid_phone' => __('Please enter a valid phone number.', 'formflow'),
                'invalid_zip' => __('Please enter a valid ZIP code.', 'formflow'),
                'select_time' => __('Please select an appointment time.', 'formflow'),
                'save_progress' => __('Save & Continue Later', 'formflow'),
                'progress_saved' => __('Progress saved! Check your email for a link to continue.', 'formflow'),
                'email_mismatch' => __('Email addresses do not match.', 'formflow'),
                'saving' => __('Saving...', 'formflow'),
                'autosaved' => __('Auto-saved', 'formflow')
            ]
        ]);

        // Generate session ID
        $session_id = Security::generate_session_id();

        // Get visitor ID (integrates with Peanut Suite)
        $visitor_id = apply_filters(\ISF\Hooks::GET_VISITOR_ID, null);
        if (!$visitor_id) {
            $visitor_tracker = new Analytics\VisitorTracker();
            $visitor_id = $visitor_tracker->get_or_create_visitor_id();
        }

        // Fire form viewed hook for analytics/Peanut Suite integration
        do_action(\ISF\Hooks::FORM_VIEWED, (int) $instance['id'], $visitor_id, [
            'form_type' => $instance['form_type'],
            'instance_slug' => $instance['slug'],
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'referrer' => $_SERVER['HTTP_REFERER'] ?? '',
        ]);

        // Build CSS classes
        $classes = ['isf-form-container'];
        if (!empty($atts['class'])) {
            $classes[] = sanitize_html_class($atts['class']);
        }
        if ($instance['test_mode']) {
            $classes[] = 'isf-test-mode';
        }

        // Start output buffering
        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"
             id="isf-form-<?php echo esc_attr($instance['slug']); ?>"
             data-instance="<?php echo esc_attr($instance['slug']); ?>"
             data-session="<?php echo esc_attr($session_id); ?>"
             data-step="1"
             data-form-type="<?php echo esc_attr($instance['form_type']); ?>">

            <?php if ($instance['settings']['demo_mode'] ?? false) : ?>
                <div class="isf-demo-banner">
                    <?php esc_html_e('DEMO MODE - Using test data. Try account: 1234567890 with ZIP: 20001 (or any account with ZIP: 00000)', 'formflow'); ?>
                </div>
            <?php elseif ($instance['test_mode']) : ?>
                <div class="isf-test-banner">
                    <?php esc_html_e('TEST MODE - Submissions will not be processed', 'formflow'); ?>
                </div>
            <?php endif; ?>

            <?php
            $is_scheduler = $instance['form_type'] === 'scheduler';
            $total_steps = $is_scheduler ? 2 : 5;
            ?>

            <!-- Progress Bar (enrollment only) -->
            <?php if (!$is_scheduler) : ?>
                <?php include ISF_PLUGIN_DIR . 'public/templates/partials/progress-bar.php'; ?>
            <?php endif; ?>

            <!-- Form Content Area -->
            <div class="isf-form-content">
                <div class="isf-loader" style="display:none;">
                    <div class="isf-spinner"></div>
                    <span class="isf-loader-text"><?php esc_html_e('Loading...', 'formflow'); ?></span>
                </div>

                <div class="isf-step-content">
                    <?php
                    // Load initial step based on form type
                    $step = 1;
                    $form_data = [];

                    if ($is_scheduler) {
                        include ISF_PLUGIN_DIR . 'public/templates/scheduler/step-1-account.php';
                    } else {
                        include ISF_PLUGIN_DIR . 'public/templates/enrollment/step-1-program.php';
                    }
                    ?>
                </div>
            </div>

            <!-- Error Message Container -->
            <div class="isf-error-container" style="display:none;"></div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render the enrollment button shortcode
     *
     * Supports both internal form links and external handoff tracking.
     *
     * Usage:
     *   [isf_enroll_button instance="pepco-dc"]                    - Links to form page
     *   [isf_enroll_button instance="pepco-dc" external="https://..."] - Tracked handoff
     *   [isf_enroll_button instance="pepco-dc" text="Sign Up Now"]
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_enroll_button_shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'instance' => '',
            'external' => '',         // External URL for handoff
            'text' => __('Enroll Now', 'formflow'),
            'class' => '',
            'style' => 'primary',     // primary, secondary, outline
            'size' => 'medium',       // small, medium, large
            'new_tab' => false,       // Open in new tab
            'form_page' => '',        // URL of page with form (for internal links)
        ], $atts, 'isf_enroll_button');

        // Get instance
        $instance = $this->db->get_instance_by_slug($atts['instance'], true);

        if (!$instance) {
            if (current_user_can('manage_options')) {
                return '<p class="isf-error">' . esc_html__('Error: Form instance not found or inactive.', 'formflow') . '</p>';
            }
            return '';
        }

        // Build button classes
        $classes = ['isf-enroll-button'];
        $classes[] = 'isf-btn-' . sanitize_html_class($atts['style']);
        $classes[] = 'isf-btn-' . sanitize_html_class($atts['size']);

        if (!empty($atts['class'])) {
            $classes[] = sanitize_html_class($atts['class']);
        }

        // Determine URL and whether this is a handoff
        $is_handoff = !empty($atts['external']);
        $url = '';
        $data_attrs = [
            'instance' => esc_attr($instance['slug']),
            'instance-id' => esc_attr($instance['id']),
        ];

        if ($is_handoff) {
            // External handoff - use tracking redirect
            $handoff_tracker = new \ISF\Analytics\HandoffTracker();
            $url = $handoff_tracker->get_tracking_redirect_url(
                (int) $instance['id'],
                esc_url($atts['external'])
            );
            $classes[] = 'isf-handoff-button';
            $data_attrs['destination'] = esc_url($atts['external']);
            $data_attrs['isf-handoff'] = 'true';
        } else {
            // Internal form link
            if (!empty($atts['form_page'])) {
                $url = esc_url($atts['form_page']);
            } else {
                // Try to find page with the form shortcode
                $url = $this->find_form_page_url($instance['slug']);
                if (!$url) {
                    // Fallback to current page with anchor
                    $url = '#isf-form-' . esc_attr($instance['slug']);
                }
            }
        }

        // Build link target
        $target = '';
        $rel = '';
        if ($atts['new_tab'] || $is_handoff) {
            $target = ' target="_blank"';
            $rel = ' rel="noopener noreferrer"';
        }

        // Build data attributes string
        $data_attr_string = '';
        foreach ($data_attrs as $key => $value) {
            $data_attr_string .= ' data-' . $key . '="' . $value . '"';
        }

        // Enqueue button styles if not already enqueued
        wp_enqueue_style('isf-forms');

        return sprintf(
            '<a href="%s" class="%s"%s%s%s>%s</a>',
            esc_url($url),
            esc_attr(implode(' ', $classes)),
            $target,
            $rel,
            $data_attr_string,
            esc_html($atts['text'])
        );
    }

    /**
     * Render a custom builder-driven form from its stored schema.
     *
     * Avoids the IntelliSOURCE enrollment wizard for instances where the
     * fields are user-defined via the form builder.
     */
    private function render_custom_form(array $instance, array $atts): string {
        $schema = $instance['settings']['form_schema'] ?? [];

        if (empty($schema) || empty($schema['steps'] ?? [])) {
            if (current_user_can('manage_options')) {
                return '<div class="isf-error">' .
                    esc_html__('Custom form has no schema. Edit the instance and add fields in the Form fields task.', 'formflow') .
                    '</div>';
            }
            return '';
        }

        wp_enqueue_style('isf-forms');
        wp_enqueue_script('isf-security');
        wp_enqueue_script('isf-builder-form');

        $security = \ISF\SecurityHardening::instance();
        wp_localize_script('isf-security', 'isfSecurityConfig', $security->get_js_security_config());

        // Configure the builder-form JS so it can AJAX-submit instead of
        // letting the browser POST to the page URL (which drops the data).
        wp_localize_script('isf-builder-form', 'isfBuilderForm', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'action'      => 'formflow_submit_builder_form',
            'nonce'       => wp_create_nonce('isf_form_submit'),
            'instance_id' => (int) $instance['id'],
            'strings'     => [
                'submitting' => __('Submitting…', 'formflow'),
                'error'      => __('Something went wrong. Please try again.', 'formflow'),
                'network'    => __('Network error. Please check your connection and try again.', 'formflow'),
            ],
        ]);

        $visitor_id = apply_filters(\ISF\Hooks::GET_VISITOR_ID, null);
        if (!$visitor_id) {
            $visitor_tracker = new Analytics\VisitorTracker();
            $visitor_id = $visitor_tracker->get_or_create_visitor_id();
        }

        do_action(\ISF\Hooks::FORM_VIEWED, (int) $instance['id'], $visitor_id, [
            'form_type' => 'custom',
            'instance_slug' => $instance['slug'],
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'referrer' => $_SERVER['HTTP_REFERER'] ?? '',
        ]);

        $renderer = new \ISF\Builder\FormRenderer();
        return $renderer->render($schema, $instance, []);
    }

    /**
     * Render external form type (tracking + redirect button)
     *
     * For external enrollment instances, we don't show the full form.
     * Instead, we track the visit and show a button to redirect to the external platform.
     *
     * @param array $instance Instance data
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    private function render_external_form(array $instance, array $atts): string {
        // Enqueue styles
        wp_enqueue_style('isf-forms');

        // Enqueue analytics if visitor analytics is enabled
        $features = FeatureManager::get_features($instance);
        if (!empty($features['visitor_analytics']['enabled'])) {
            wp_enqueue_script('isf-analytics');

            // Localize analytics config
            $gtm_helper = new Analytics\GtmHelper();
            wp_localize_script('isf-analytics', 'ISFAnalyticsConfig', $gtm_helper->get_js_config($instance));
        }

        // Get external URL from instance settings
        $external_url = $instance['settings']['external_url'] ?? '';
        if (empty($external_url)) {
            if (current_user_can('manage_options')) {
                return '<div class="isf-error">' .
                    esc_html__('External enrollment URL not configured. Please set it in the instance settings.', 'formflow') .
                    '</div>';
            }
            return '';
        }

        // Get button text (custom or default)
        $button_text = $instance['settings']['external_button_text'] ?? '';
        if (empty($button_text)) {
            $button_text = __('Enroll Now', 'formflow');
        }

        // Check if should open in new tab
        $new_tab = !empty($instance['settings']['external_new_tab']);

        // Build tracking redirect URL via HandoffTracker
        $handoff_tracker = new \ISF\Analytics\HandoffTracker();
        $tracking_url = $handoff_tracker->get_tracking_redirect_url(
            (int) $instance['id'],
            $external_url
        );

        // Record form view touch
        if (!empty($features['visitor_analytics']['enabled'])) {
            $visitor_tracker = new \ISF\Analytics\VisitorTracker();
            $touch_recorder = new \ISF\Analytics\TouchRecorder($visitor_tracker);
            $touch_recorder->record_form_view((int) $instance['id']);
        }

        // Build CSS classes
        $classes = ['isf-external-form', 'isf-form-container'];
        if (!empty($atts['class'])) {
            $classes[] = sanitize_html_class($atts['class']);
        }

        // Get content settings
        $content = $instance['settings']['content'] ?? [];
        $form_title = $content['form_title'] ?? '';
        $form_description = $content['form_description'] ?? '';

        // Start output buffering
        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"
             id="isf-form-<?php echo esc_attr($instance['slug']); ?>"
             data-instance="<?php echo esc_attr($instance['slug']); ?>"
             data-form-type="external">

            <?php if (!empty($form_title) || !empty($form_description)) : ?>
            <div class="isf-external-header">
                <?php if (!empty($form_title)) : ?>
                    <h2 class="isf-external-title"><?php echo esc_html($form_title); ?></h2>
                <?php endif; ?>
                <?php if (!empty($form_description)) : ?>
                    <p class="isf-external-description"><?php echo esc_html($form_description); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="isf-external-action">
                <a href="<?php echo esc_url($tracking_url); ?>"
                   class="isf-external-button isf-btn-primary isf-btn-large"
                   data-instance="<?php echo esc_attr($instance['slug']); ?>"
                   data-instance-id="<?php echo esc_attr($instance['id']); ?>"
                   data-destination="<?php echo esc_url($external_url); ?>"
                   data-isf-handoff="true"
                   <?php echo $new_tab ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
                    <?php echo esc_html($button_text); ?>
                </a>
            </div>

            <?php if (current_user_can('manage_options')) : ?>
            <div class="isf-admin-notice" style="margin-top: 15px; padding: 10px; background: #f0f6fc; border-left: 3px solid #0073aa; font-size: 12px;">
                <strong><?php esc_html_e('Admin Note:', 'formflow'); ?></strong>
                <?php printf(
                    esc_html__('This is an external enrollment form. Visitors will be redirected to: %s', 'formflow'),
                    '<code>' . esc_html($external_url) . '</code>'
                ); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Find the URL of a page containing the form shortcode
     *
     * @param string $instance_slug Instance slug to search for
     * @return string|null Page URL or null if not found
     */
    private function find_form_page_url(string $instance_slug): ?string {
        global $wpdb;

        // Search for page containing the shortcode
        $page = $wpdb->get_row($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN ('page', 'post')
             AND post_status = 'publish'
             AND post_content LIKE %s
             LIMIT 1",
            '%[isf_form instance="' . $wpdb->esc_like($instance_slug) . '"%'
        ));

        if ($page) {
            return get_permalink($page->ID);
        }

        return null;
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * Get instance from POST data (supports both slug and ID)
     */
    private function get_instance_from_request(): ?array {
        $instance_slug = sanitize_text_field($_POST['instance'] ?? '');
        $instance_id = (int)($_POST['instance_id'] ?? 0);

        if (!empty($instance_slug)) {
            return $this->db->get_instance_by_slug($instance_slug);
        } elseif ($instance_id > 0) {
            return $this->db->get_instance($instance_id);
        }
        return null;
    }

    /**
     * Get the appropriate API client (real or mock) based on instance settings
     *
     * @param array $instance The form instance data
     * @return ApiClient|MockApiClient
     */
    private function get_api_client(array $instance): ApiClient|MockApiClient {
        // Check if demo mode is enabled
        $demo_mode = $instance['settings']['demo_mode'] ?? false;

        if ($demo_mode) {
            return new MockApiClient($instance['id']);
        }

        return new ApiClient(
            $instance['api_endpoint'],
            $instance['api_password'],
            $instance['test_mode'],
            $instance['id']
        );
    }


    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Trigger webhook for an event
     *
     * @param string $event Event name
     * @param array $data Event data
     * @param int|null $instance_id Instance ID
     */
    private function trigger_webhook(string $event, array $data, ?int $instance_id = null): void {
        require_once ISF_PLUGIN_DIR . 'includes/class-webhook-handler.php';

        $webhook_handler = new \ISF\WebhookHandler();
        $webhook_handler->trigger($event, $data, $instance_id);
    }

    /**
     * Get step name by number
     */
    private function get_step_name(int $step): string {
        $names = [
            1 => 'program',
            2 => 'validate',
            3 => 'info',
            4 => 'schedule',
            5 => 'confirm'
        ];

        return $names[$step] ?? 'program';
    }

    /**
     * Get scheduler step name by number
     */
    private function get_scheduler_step_name(int $step): string {
        $names = [
            1 => 'account',
            2 => 'schedule'
        ];

        return $names[$step] ?? 'account';
    }

    /**
     * Calculate scheduling start date (3+ business days out)
     */
    private function calculate_start_date(): string {
        $days_to_add = 3;
        $day_of_week = (int)date('w');

        // Adjust for weekends
        if ($day_of_week === 3) { // Wednesday
            $days_to_add = 5;
        } elseif ($day_of_week === 4) { // Thursday
            $days_to_add = 5;
        } elseif ($day_of_week === 5) { // Friday
            $days_to_add = 5;
        } elseif ($day_of_week === 6) { // Saturday
            $days_to_add = 4;
        }

        return date('m/d/Y', strtotime("+{$days_to_add} days"));
    }

    /**
     * Get display string for time slot
     */
    private function get_time_display(string $time): string {
        $displays = [
            'AM' => '8:00 AM - 11:00 AM',
            'am' => '8:00 AM - 11:00 AM',
            'MD' => '11:00 AM - 2:00 PM',
            'md' => '11:00 AM - 2:00 PM',
            'PM' => '2:00 PM - 5:00 PM',
            'pm' => '2:00 PM - 5:00 PM',
            'EV' => '5:00 PM - 8:00 PM',
            'ev' => '5:00 PM - 8:00 PM'
        ];

        return $displays[$time] ?? $time;
    }

    /**
     * Apply scheduling settings to filter/modify slots
     *
     * Handles blocked dates (holidays) and capacity limits per time slot
     *
     * @param array $slots Slots from API
     * @param array $instance The form instance
     * @return array Modified slots
     */
    private function apply_scheduling_settings(array $slots, array $instance): array {
        $scheduling = $instance['settings']['scheduling'] ?? [];

        // Get blocked dates as array of Y-m-d strings
        $blocked_dates = [];
        if (!empty($scheduling['blocked_dates'])) {
            foreach ($scheduling['blocked_dates'] as $blocked) {
                if (!empty($blocked['date'])) {
                    $blocked_dates[] = $blocked['date'];
                }
            }
        }

        // Get capacity limits
        $capacity_limits = $scheduling['capacity_limits'] ?? [];
        $capacity_enabled = !empty($capacity_limits['enabled']);

        // Process each slot
        $filtered_slots = [];

        foreach ($slots as $slot) {
            $date = $slot['date'];

            // Convert date format for comparison (API uses various formats)
            $normalized_date = date('Y-m-d', strtotime($date));

            // Skip blocked dates entirely
            if (in_array($normalized_date, $blocked_dates)) {
                continue;
            }

            // Apply capacity limits if enabled
            if ($capacity_enabled) {
                foreach (['am', 'md', 'pm', 'ev'] as $time_slot) {
                    if (isset($capacity_limits[$time_slot]) && $capacity_limits[$time_slot] !== '') {
                        $max_capacity = (int)$capacity_limits[$time_slot];

                        // Override capacity
                        if (isset($slot['times'][$time_slot])) {
                            $current_capacity = $slot['times'][$time_slot]['capacity'] ?? 0;

                            // Apply the lower of API capacity or custom limit
                            $effective_capacity = min($current_capacity, $max_capacity);

                            $slot['times'][$time_slot]['capacity'] = $effective_capacity;
                            $slot['times'][$time_slot]['available'] = $effective_capacity > 0;
                        }
                    }
                }
            }

            // Check if any time slots are still available after modifications
            $any_available = false;
            foreach ($slot['times'] as $time_data) {
                if (!empty($time_data['available'])) {
                    $any_available = true;
                    break;
                }
            }

            // Only include dates that have at least one available slot
            if ($any_available) {
                $filtered_slots[] = $slot;
            }
        }

        return $filtered_slots;
    }

    /**
     * Send SMS confirmation if feature is enabled
     */
    private function send_sms_confirmation(array $instance, array $form_data, string $confirmation_number): void {
        try {
            $sms_handler = new \ISF\SmsHandler();
            $sms_handler->send_enrollment_confirmation($instance, $form_data, $confirmation_number);
        } catch (\Throwable $e) {
            // Don't fail the enrollment if SMS fails
            $this->db->log('warning', 'SMS notification failed: ' . $e->getMessage(), [], $instance['id']);
        }
    }

    /**
     * Send team notification if feature is enabled
     */
    private function send_team_notification(array $instance, array $submission, string $confirmation_number): void {
        try {
            $team_handler = new \ISF\TeamNotifications();
            $team_handler->notify_enrollment($instance, $submission, $confirmation_number);
        } catch (\Throwable $e) {
            // Don't fail the enrollment if notification fails
            $this->db->log('warning', 'Team notification failed: ' . $e->getMessage(), [], $instance['id']);
        }
    }

    /**
     * Send team notification for failed enrollment
     */
    private function send_failure_notification(array $instance, array $submission, string $error_message): void {
        try {
            $team_handler = new \ISF\TeamNotifications();
            $team_handler->notify_failure($instance, $submission, $error_message);
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * Validate all form steps server-side before final submission
     *
     * This provides a security layer to catch any data that bypassed
     * client-side validation (e.g., manipulated requests).
     *
     * @param array $form_data The complete form data
     * @return array Array of validation errors, empty if valid
     */
    private function validate_all_form_steps(array $form_data): array {
        $all_errors = [];

        // Step 1: Device type validation
        if (!$this->form_handler->validateStep1($form_data)) {
            $all_errors = array_merge($all_errors, $this->form_handler->getErrors());
        }

        // Step 2: Account validation (already validated via API, but check format)
        if (!$this->form_handler->validateStep2($form_data)) {
            $all_errors = array_merge($all_errors, $this->form_handler->getErrors());
        }

        // Step 3: Customer information (most critical)
        if (!$this->form_handler->validateStep3($form_data)) {
            $all_errors = array_merge($all_errors, $this->form_handler->getErrors());
        }

        // Step 4: Scheduling (only if not skipping)
        $schedule_later = !empty($form_data['schedule_later']) || empty($form_data['schedule_date']);
        if (!$schedule_later && !$this->form_handler->validateStep4($form_data)) {
            $all_errors = array_merge($all_errors, $this->form_handler->getErrors());
        }

        // Step 5: Terms agreement
        if (!$this->form_handler->validateStep5($form_data)) {
            $all_errors = array_merge($all_errors, $this->form_handler->getErrors());
        }

        return $all_errors;
    }

    /**
     * Public AJAX handler for builder-form (form_type='custom') submissions.
     *
     * The legacy IntelliSOURCE wizard had isf_submit_enrollment. The new
     * builder renderer had no submit wiring at all — submissions just
     * POST'd back to the page URL and were dropped on the floor. This
     * handler persists the submission, fires the FORM_COMPLETED hook so
     * destinations / notifications / analytics integrations all run,
     * and returns a success payload for the JS to render the
     * confirmation state.
     */
    public function isf_submit_builder_form(): void {
        check_ajax_referer('isf_form_submit', 'isf_nonce');

        $instance_id = isset($_POST['instance_id']) ? (int) $_POST['instance_id'] : 0;
        if ($instance_id <= 0) {
            wp_send_json_error(['message' => __('Invalid form.', 'formflow')], 400);
        }

        $instance = $this->db->get_instance($instance_id);
        if (!$instance || empty($instance['is_active'])) {
            wp_send_json_error(['message' => __('This form is no longer available.', 'formflow')], 404);
        }

        // Collect the form payload, dropping plugin control fields.
        $reserved = ['instance_id', 'isf_nonce', 'current_step', 'action', '_wp_http_referer'];
        $form_data = [];
        foreach ($_POST as $key => $value) {
            if (in_array($key, $reserved, true)) { continue; }
            if (is_array($value)) {
                $form_data[$key] = array_map('sanitize_text_field', wp_unslash($value));
            } else {
                $form_data[$key] = sanitize_textarea_field(wp_unslash((string) $value));
            }
        }

        $session_id = Security::generate_session_id();

        $submission_id = $this->db->create_submission([
            'instance_id'    => $instance_id,
            'session_id'     => $session_id,
            'customer_name'  => trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? '')),
            'form_data'      => $form_data,
            'status'         => 'completed',
            'step'           => 1,
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);

        if ($submission_id === false) {
            wp_send_json_error([
                'message' => __('We could not save your submission. Please try again.', 'formflow'),
            ], 500);
        }

        // Fire the standard completion hooks so destinations / webhooks /
        // notifications / analytics all run on builder-form submissions
        // the same way they do for the enrollment wizard. FORM_COMPLETED
        // expects a single associative-array argument; ENROLLMENT_COMPLETED
        // takes (submission_id, instance_id, form_data).
        $visitor_id = apply_filters(\ISF\Hooks::GET_VISITOR_ID, null);
        $utm_data   = [];
        if (class_exists('\ISF\UTMTracker')) {
            try {
                $utm_data = (new \ISF\UTMTracker())->get_tracking_data();
            } catch (\Throwable $e) { /* utm is optional */ }
        }

        $submission_data = [
            'submission_id' => (int) $submission_id,
            'instance_id'   => $instance_id,
            'instance_slug' => $instance['slug'] ?? '',
            'visitor_id'    => $visitor_id,
            'form_data'     => $form_data,
            'utm_data'      => $utm_data,
            'form_type'     => $instance['form_type'] ?? 'custom',
            'status'        => 'completed',
            'session_id'    => $session_id,
        ];

        do_action(\ISF\Hooks::ENROLLMENT_COMPLETED, (int) $submission_id, $instance_id, $form_data);
        do_action(\ISF\Hooks::FORM_COMPLETED, $submission_data);

        $success = $instance['settings']['success_message']
            ?? $instance['settings']['form_schema']['settings']['success_message']
            ?? __('Thank you! Your enrollment has been received. We will email a confirmation shortly.', 'formflow');

        wp_send_json_success([
            'submission_id'   => (int) $submission_id,
            'success_message' => wp_kses_post($success),
        ]);
    }
}

/**
 * Get content text from instance settings with fallback to default.
 *
 * This function is available in templates and retrieves customizable text.
 *
 * @param array $instance The form instance data
 * @param string $key The content key to retrieve
 * @param string $default Default text if not set
 * @return string The content text
 */
function isf_get_content(array $instance, string $key, string $default = ''): string {
    $content = $instance['settings']['content'][$key] ?? '';

    if (empty($content)) {
        return $default;
    }

    // Replace {phone} placeholder with support phone number
    $phone = $instance['settings']['support_phone'] ?? '';
    $content = str_replace('{phone}', $phone, $content);

    return $content;
}

/**
 * Get the default state for the form instance.
 *
 * @param array $instance The form instance data
 * @return string The default state abbreviation or empty string
 */
function isf_get_default_state(array $instance): string {
    return $instance['settings']['default_state'] ?? '';
}

/**
 * Get the support phone number for the form instance.
 *
 * @param array $instance The form instance data
 * @return string The support phone number
 */
function isf_get_support_phone(array $instance): string {
    return $instance['settings']['support_phone'] ?? '';
}
