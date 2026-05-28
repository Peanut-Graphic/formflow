<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Feature Manager
 *
 * Manages per-instance feature toggles and configurations.
 */


class FeatureManager {

    /**
     * Default feature settings
     */
    private static array $defaults = [
        // Form Experience
        'inline_validation' => [
            'enabled' => true,
            'show_success_icons' => true,
            'validate_on_blur' => true,
            'validate_on_keyup' => false,
        ],
        'auto_save' => [
            'enabled' => true,
            'interval_seconds' => 60,
            'use_local_storage' => true,
            'show_save_indicator' => true,
        ],
        'spanish_translation' => [
            'enabled' => false,
            'default_language' => 'en',
            'show_language_toggle' => true,
            'auto_detect' => true,
        ],

        // Notifications
        'sms_notifications' => [
            'enabled' => false,
            'provider' => 'twilio',
            'account_sid' => '',
            'auth_token' => '',
            'from_number' => '',
            'send_enrollment_confirmation' => true,
            'send_appointment_reminder' => true,
            'reminder_hours_before' => 24,
        ],
        'team_notifications' => [
            'enabled' => false,
            'provider' => 'slack',
            'webhook_url' => '',
            'notify_on_enrollment' => true,
            'notify_on_failure' => true,
            'daily_digest' => false,
            'digest_time' => '09:00',
        ],
        'email_digest' => [
            'enabled' => false,
            'frequency' => 'daily',
            'recipients' => '',
            'send_time' => '08:00',
            'include_comparison' => true,
        ],

        // Scheduling
        'appointment_self_service' => [
            'enabled' => false,
            'allow_reschedule' => true,
            'allow_cancel' => true,
            'reschedule_deadline_hours' => 24,
            'cancel_deadline_hours' => 24,
            'require_reason_for_cancel' => true,
            'token_expiry_days' => 30,
        ],
        'capacity_management' => [
            'enabled' => false,
            'daily_cap' => 0,
            'per_slot_cap' => 0,
            'blackout_dates' => [],
            'enable_waitlist' => false,
            'waitlist_notification' => true,
        ],

        // Analytics & Tracking
        'utm_tracking' => [
            'enabled' => true,
            'track_referrer' => true,
            'track_landing_page' => true,
            'pass_to_api' => false,
            'store_in_submission' => true,
        ],
        'visitor_analytics' => [
            'enabled' => true,
            'track_page_views' => true,
            'visitor_cookie_days' => 365,
            'use_fingerprinting' => false,
            'gtm_enabled' => false,
            'gtm_container_id' => '',
        ],
        'handoff_tracking' => [
            'enabled' => false,
            'destination_url' => '',
            'append_account_param' => true,
            'append_utm_params' => true,
            'show_interstitial' => false,
            'interstitial_message' => 'Redirecting you to complete your enrollment...',
        ],
        'ab_testing' => [
            'enabled' => false,
            'variations' => [],
            'track_by' => 'session',
            'goal' => 'enrollment_completed',
        ],

        // Integrations
        'document_upload' => [
            'enabled' => false,
            'max_files' => 3,
            'max_file_size_mb' => 10,
            'allowed_types' => ['jpg', 'jpeg', 'png', 'pdf'],
            'required' => false,
            'upload_step' => 3,
            'storage' => 'local',
        ],
        'crm_integration' => [
            'enabled' => false,
            'provider' => 'salesforce',
            'api_url' => '',
            'api_key' => '',
            'api_secret' => '',
            'object_type' => 'Lead',
            'field_mapping' => [],
            'sync_on_completion' => true,
            'sync_on_update' => false,
        ],
        'calendar_integration' => [
            'enabled' => false,
            'provider' => 'google',
            'calendar_id' => '',
            'api_credentials' => '',
            'create_events' => true,
            'send_invites' => false,
            'event_title_template' => '{program_name} - {customer_name}',
            'event_description_template' => '',
        ],

        // Advanced
        'chatbot_assistant' => [
            'enabled' => false,
            'provider' => 'custom',
            'api_key' => '',
            'bot_name' => 'EnergyWise Assistant',
            'welcome_message' => 'Hi! I can help you with the enrollment process. What questions do you have?',
            'position' => 'bottom-right',
            'auto_open_delay' => 0,
            'available_on_steps' => [1, 2, 3, 4, 5],
            'knowledge_base' => [],
        ],
        'fraud_detection' => [
            'enabled' => false,
            'check_duplicate_accounts' => true,
            'check_ip_velocity' => true,
            'ip_threshold_per_hour' => 5,
            'check_device_fingerprint' => true,
            'check_email_domain' => true,
            'blocked_email_domains' => [],
            'risk_score_threshold' => 70,
            'action_on_high_risk' => 'flag',
            'notify_on_fraud' => true,
        ],
    ];

    /**
     * Get feature settings for an instance
     */
    public static function get_features(array $instance): array {
        $settings = $instance['settings'] ?? [];
        $features = $settings['features'] ?? [];

        // Merge with defaults
        $merged = [];
        foreach (self::$defaults as $feature => $defaults) {
            if (isset($features[$feature]) && is_array($features[$feature])) {
                $merged[$feature] = array_merge($defaults, $features[$feature]);
            } else {
                $merged[$feature] = $defaults;
            }
        }

        return $merged;
    }

    /**
     * Check if a feature is enabled for an instance
     */
    public static function is_enabled(array $instance, string $feature): bool {
        $features = self::get_features($instance);
        return !empty($features[$feature]['enabled']);
    }

    /**
     * Get a specific feature's settings
     */
    public static function get_feature(array $instance, string $feature): array {
        $features = self::get_features($instance);
        return $features[$feature] ?? [];
    }

    /**
     * Get feature setting value
     */
    public static function get_setting(array $instance, string $feature, string $setting, $default = null) {
        $feature_settings = self::get_feature($instance, $feature);
        return $feature_settings[$setting] ?? $default;
    }

    /**
     * Get all available features with their metadata
     */
    public static function get_available_features(): array {
        return [
            // Form Experience
            'inline_validation' => [
                'name' => __('Inline Field Validation', 'formflow'),
                'description' => __('Real-time validation feedback as users type', 'formflow'),
                'category' => 'form_experience',
                'icon' => 'yes-alt',
            ],
            'auto_save' => [
                'name' => __('Auto-Save Drafts', 'formflow'),
                'description' => __('Automatically save form progress', 'formflow'),
                'category' => 'form_experience',
                'icon' => 'backup',
            ],
            'spanish_translation' => [
                'name' => __('Spanish Translation', 'formflow'),
                'description' => __('Full Spanish language support with toggle', 'formflow'),
                'category' => 'form_experience',
                'icon' => 'translation',
            ],

            // Notifications
            'sms_notifications' => [
                'name' => __('SMS Notifications', 'formflow'),
                'description' => __('Send text confirmations to customers', 'formflow'),
                'category' => 'notifications',
                'icon' => 'smartphone',
                'requires_config' => true,
            ],
            'team_notifications' => [
                'name' => __('Slack/Teams Notifications', 'formflow'),
                'description' => __('Alert staff when enrollments come in', 'formflow'),
                'category' => 'notifications',
                'icon' => 'groups',
                'requires_config' => true,
            ],
            'email_digest' => [
                'name' => __('Admin Email Digest', 'formflow'),
                'description' => __('Daily/weekly summary instead of individual emails', 'formflow'),
                'category' => 'notifications',
                'icon' => 'email-alt',
                'requires_config' => true,
            ],

            // Scheduling
            'appointment_self_service' => [
                'name' => __('Appointment Self-Service', 'formflow'),
                'description' => __('Let customers reschedule or cancel via email link', 'formflow'),
                'category' => 'scheduling',
                'icon' => 'calendar-alt',
            ],
            'capacity_management' => [
                'name' => __('Capacity Management', 'formflow'),
                'description' => __('Daily caps, blackout dates, and waitlist', 'formflow'),
                'category' => 'scheduling',
                'icon' => 'groups',
                'requires_config' => true,
            ],

            // Analytics & Tracking
            'utm_tracking' => [
                'name' => __('UTM Parameter Tracking', 'formflow'),
                'description' => __('Track marketing campaign effectiveness', 'formflow'),
                'category' => 'analytics',
                'icon' => 'chart-bar',
            ],
            'visitor_analytics' => [
                'name' => __('Visitor Analytics', 'formflow'),
                'description' => __('Track visitor journeys and marketing attribution', 'formflow'),
                'category' => 'analytics',
                'icon' => 'analytics',
                'requires_config' => true,
            ],
            'handoff_tracking' => [
                'name' => __('External Handoff', 'formflow'),
                'description' => __('Redirect to external enrollment with attribution tracking', 'formflow'),
                'category' => 'analytics',
                'icon' => 'external',
                'requires_config' => true,
            ],
            'ab_testing' => [
                'name' => __('A/B Testing', 'formflow'),
                'description' => __('Test different form variations', 'formflow'),
                'category' => 'analytics',
                'icon' => 'randomize',
                'requires_config' => true,
            ],

            // Integrations
            'document_upload' => [
                'name' => __('Document Upload', 'formflow'),
                'description' => __('Allow customers to upload photos/documents', 'formflow'),
                'category' => 'integrations',
                'icon' => 'media-default',
                'requires_config' => true,
            ],
            'crm_integration' => [
                'name' => __('CRM Integration', 'formflow'),
                'description' => __('Sync enrollments to Salesforce, HubSpot, or custom CRM', 'formflow'),
                'category' => 'integrations',
                'icon' => 'businessman',
                'requires_config' => true,
            ],
            'calendar_integration' => [
                'name' => __('Calendar Integration', 'formflow'),
                'description' => __('Create calendar events for appointments', 'formflow'),
                'category' => 'integrations',
                'icon' => 'calendar',
                'requires_config' => true,
            ],

            // Advanced
            'chatbot_assistant' => [
                'name' => __('Chatbot Assistant', 'formflow'),
                'description' => __('AI-powered help during enrollment', 'formflow'),
                'category' => 'advanced',
                'icon' => 'format-chat',
                'requires_config' => true,
            ],
            'fraud_detection' => [
                'name' => __('Fraud Detection', 'formflow'),
                'description' => __('Detect and prevent fraudulent submissions', 'formflow'),
                'category' => 'advanced',
                'icon' => 'shield-alt',
                'requires_config' => true,
            ],
        ];
    }

    /**
     * Get features grouped by category
     */
    public static function get_features_by_category(): array {
        $features = self::get_available_features();
        $categories = [
            'form_experience' => [
                'label' => __('Form Experience', 'formflow'),
                'features' => [],
            ],
            'notifications' => [
                'label' => __('Notifications', 'formflow'),
                'features' => [],
            ],
            'scheduling' => [
                'label' => __('Scheduling', 'formflow'),
                'features' => [],
            ],
            'analytics' => [
                'label' => __('Analytics & Tracking', 'formflow'),
                'features' => [],
            ],
            'integrations' => [
                'label' => __('Integrations', 'formflow'),
                'features' => [],
            ],
            'advanced' => [
                'label' => __('Advanced', 'formflow'),
                'features' => [],
            ],
        ];

        foreach ($features as $key => $feature) {
            $category = $feature['category'];
            if (isset($categories[$category])) {
                $categories[$category]['features'][$key] = $feature;
            }
        }

        return $categories;
    }

    /**
     * Get default settings for a feature
     */
    public static function get_defaults(string $feature): array {
        return self::$defaults[$feature] ?? [];
    }

    /**
     * Validate feature configuration
     */
    public static function validate_config(string $feature, array $config): array {
        $errors = [];

        switch ($feature) {
            case 'sms_notifications':
                if (!empty($config['enabled'])) {
                    if (empty($config['account_sid'])) {
                        $errors[] = __('Twilio Account SID is required', 'formflow');
                    }
                    if (empty($config['auth_token'])) {
                        $errors[] = __('Twilio Auth Token is required', 'formflow');
                    }
                    if (empty($config['from_number'])) {
                        $errors[] = __('From phone number is required', 'formflow');
                    }
                }
                break;

            case 'team_notifications':
                if (!empty($config['enabled'])) {
                    if (empty($config['webhook_url'])) {
                        $errors[] = __('Webhook URL is required', 'formflow');
                    }
                    if (!filter_var($config['webhook_url'], FILTER_VALIDATE_URL)) {
                        $errors[] = __('Webhook URL must be a valid URL', 'formflow');
                    }
                }
                break;

            case 'email_digest':
                if (!empty($config['enabled'])) {
                    if (empty($config['recipients'])) {
                        $errors[] = __('At least one recipient email is required', 'formflow');
                    }
                }
                break;
        }

        return $errors;
    }
}
