<?php
namespace ISF\Analytics;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * GTM Helper
 *
 * Server-side helper for Google Tag Manager integration.
 * Handles dataLayer initialization and GA4 Measurement Protocol.
 */


use ISF\FeatureManager;

class GtmHelper {

    /**
     * Visitor tracker instance
     */
    private VisitorTracker $visitor_tracker;

    /**
     * Constructor
     */
    public function __construct(?VisitorTracker $visitor_tracker = null) {
        $this->visitor_tracker = $visitor_tracker ?? new VisitorTracker();
    }

    /**
     * Get analytics configuration for JavaScript
     */
    public function get_js_config(array $instance): array {
        $settings = json_decode($instance['settings'] ?? '{}', true) ?: [];
        $analytics_settings = $settings['analytics'] ?? [];
        $gtm_settings = $settings['gtm'] ?? [];

        return [
            'enabled' => FeatureManager::is_enabled($instance, 'visitor_analytics'),
            'gtmEnabled' => !empty($gtm_settings['enabled']),
            'gtmContainerId' => $gtm_settings['container_id'] ?? '',
            'ga4MeasurementId' => $gtm_settings['ga4_measurement_id'] ?? '',
            'clarityProjectId' => $analytics_settings['clarity_project_id'] ?? '',
            'visitorId' => $this->visitor_tracker->get_visitor_id() ?? '',
            'instanceSlug' => $instance['slug'] ?? '',
            'instanceId' => (int) ($instance['id'] ?? 0),
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
        ];
    }

    /**
     * Render dataLayer initialization script
     */
    public function render_datalayer_init(array $instance): string {
        $config = $this->get_js_config($instance);

        if (!$config['enabled']) {
            return '';
        }

        $initial_data = [
            'isf_instance' => $config['instanceSlug'],
            'isf_instance_id' => $config['instanceId'],
            'isf_visitor_id' => $config['visitorId'],
        ];

        // Add UTM parameters if present
        $attribution = $this->visitor_tracker->get_current_attribution();
        if (!empty($attribution['utm_source'])) {
            $initial_data['isf_utm_source'] = $attribution['utm_source'];
        }
        if (!empty($attribution['utm_medium'])) {
            $initial_data['isf_utm_medium'] = $attribution['utm_medium'];
        }
        if (!empty($attribution['utm_campaign'])) {
            $initial_data['isf_utm_campaign'] = $attribution['utm_campaign'];
        }

        ob_start();
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push(<?php echo wp_json_encode($initial_data); ?>);
        </script>
        <?php

        return ob_get_clean();
    }

    /**
     * Render GTM container snippet (head)
     */
    public function render_gtm_head(array $instance): string {
        $settings = json_decode($instance['settings'] ?? '{}', true) ?: [];
        $gtm_settings = $settings['gtm'] ?? [];

        if (empty($gtm_settings['enabled']) || empty($gtm_settings['container_id'])) {
            return '';
        }

        $container_id = sanitize_text_field($gtm_settings['container_id']);

        // Validate GTM container ID format
        if (!preg_match('/^GTM-[A-Z0-9]+$/', $container_id)) {
            return '';
        }

        ob_start();
        ?>
        <!-- Google Tag Manager -->
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','<?php echo esc_js($container_id); ?>');</script>
        <!-- End Google Tag Manager -->
        <?php

        return ob_get_clean();
    }

    /**
     * Render GTM container snippet (body - noscript)
     */
    public function render_gtm_body(array $instance): string {
        $settings = json_decode($instance['settings'] ?? '{}', true) ?: [];
        $gtm_settings = $settings['gtm'] ?? [];

        if (empty($gtm_settings['enabled']) || empty($gtm_settings['container_id'])) {
            return '';
        }

        $container_id = sanitize_text_field($gtm_settings['container_id']);

        if (!preg_match('/^GTM-[A-Z0-9]+$/', $container_id)) {
            return '';
        }

        ob_start();
        ?>
        <!-- Google Tag Manager (noscript) -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($container_id); ?>"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
        <?php

        return ob_get_clean();
    }

    /**
     * Send server-side event to GA4 via Measurement Protocol
     *
     * @param string $event_name Event name
     * @param array $params Event parameters
     * @param array $instance Form instance
     * @return bool Success status
     */
    public function send_ga4_event(string $event_name, array $params, array $instance): bool {
        $settings = json_decode($instance['settings'] ?? '{}', true) ?: [];
        $gtm_settings = $settings['gtm'] ?? [];

        $measurement_id = $gtm_settings['ga4_measurement_id'] ?? '';
        $api_secret = $gtm_settings['ga4_api_secret'] ?? '';

        if (empty($measurement_id) || empty($api_secret)) {
            return false;
        }

        // Build payload
        $visitor_id = $this->visitor_tracker->get_visitor_id();

        $payload = [
            'client_id' => $visitor_id ?? $this->generate_client_id(),
            'events' => [
                [
                    'name' => $event_name,
                    'params' => array_merge($params, [
                        'isf_instance' => $instance['slug'] ?? '',
                        'isf_instance_id' => $instance['id'] ?? 0,
                    ]),
                ],
            ],
        ];

        // Send to GA4 Measurement Protocol
        $url = sprintf(
            'https://www.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
            urlencode($measurement_id),
            urlencode($api_secret)
        );

        $response = wp_remote_post($url, [
            'body' => wp_json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 5,
        ]);

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 204;
    }

    /**
     * Send enrollment completion event to GA4
     */
    public function track_enrollment_complete(array $instance, array $data = []): bool {
        return $this->send_ga4_event('enrollment_complete', [
            'device_type' => $data['device_type'] ?? '',
            'appointment_scheduled' => $data['appointment_scheduled'] ?? false,
            'value' => 1,
            'currency' => 'USD',
        ], $instance);
    }

    /**
     * Send handoff event to GA4
     */
    public function track_handoff(array $instance, string $destination): bool {
        return $this->send_ga4_event('enrollment_handoff', [
            'destination_url' => $destination,
        ], $instance);
    }

    /**
     * Generate a client ID for GA4 (fallback if no visitor ID)
     */
    private function generate_client_id(): string {
        return bin2hex(random_bytes(16)) . '.' . time();
    }

    /**
     * Get recommended GTM tags configuration
     * Returns configuration that can be imported into GTM
     */
    public static function get_gtm_import_config(): array {
        return [
            'tags' => [
                [
                    'name' => 'ISF - Form View',
                    'type' => 'gaawe',
                    'trigger' => 'isf_form_view',
                    'parameters' => [
                        'eventName' => 'form_view',
                        'eventParameters' => [
                            ['name' => 'form_instance', 'value' => '{{DLV - isf_instance}}'],
                            ['name' => 'visitor_id', 'value' => '{{DLV - isf_visitor_id}}'],
                        ],
                    ],
                ],
                [
                    'name' => 'ISF - Form Start',
                    'type' => 'gaawe',
                    'trigger' => 'isf_form_start',
                    'parameters' => [
                        'eventName' => 'form_start',
                        'eventParameters' => [
                            ['name' => 'form_instance', 'value' => '{{DLV - isf_instance}}'],
                        ],
                    ],
                ],
                [
                    'name' => 'ISF - Form Step',
                    'type' => 'gaawe',
                    'trigger' => 'isf_form_step',
                    'parameters' => [
                        'eventName' => 'form_step',
                        'eventParameters' => [
                            ['name' => 'form_instance', 'value' => '{{DLV - isf_instance}}'],
                            ['name' => 'step_number', 'value' => '{{DLV - isf_step}}'],
                            ['name' => 'step_name', 'value' => '{{DLV - isf_step_name}}'],
                        ],
                    ],
                ],
                [
                    'name' => 'ISF - Form Complete',
                    'type' => 'gaawe',
                    'trigger' => 'isf_form_complete',
                    'parameters' => [
                        'eventName' => 'generate_lead',
                        'eventParameters' => [
                            ['name' => 'form_instance', 'value' => '{{DLV - isf_instance}}'],
                            ['name' => 'device_type', 'value' => '{{DLV - isf_device_type}}'],
                            ['name' => 'value', 'value' => '1'],
                            ['name' => 'currency', 'value' => 'USD'],
                        ],
                    ],
                ],
                [
                    'name' => 'ISF - Handoff',
                    'type' => 'gaawe',
                    'trigger' => 'isf_handoff',
                    'parameters' => [
                        'eventName' => 'click',
                        'eventParameters' => [
                            ['name' => 'link_text', 'value' => '{{DLV - isf_button_text}}'],
                            ['name' => 'link_url', 'value' => '{{DLV - isf_destination}}'],
                            ['name' => 'outbound', 'value' => 'true'],
                        ],
                    ],
                ],
            ],
            'triggers' => [
                [
                    'name' => 'isf_form_view',
                    'type' => 'customEvent',
                    'customEventFilter' => [
                        ['type' => 'equals', 'parameter' => '{{_event}}', 'value' => 'isf_form_view'],
                    ],
                ],
                [
                    'name' => 'isf_form_start',
                    'type' => 'customEvent',
                    'customEventFilter' => [
                        ['type' => 'equals', 'parameter' => '{{_event}}', 'value' => 'isf_form_start'],
                    ],
                ],
                [
                    'name' => 'isf_form_step',
                    'type' => 'customEvent',
                    'customEventFilter' => [
                        ['type' => 'equals', 'parameter' => '{{_event}}', 'value' => 'isf_form_step'],
                    ],
                ],
                [
                    'name' => 'isf_form_complete',
                    'type' => 'customEvent',
                    'customEventFilter' => [
                        ['type' => 'equals', 'parameter' => '{{_event}}', 'value' => 'isf_form_complete'],
                    ],
                ],
                [
                    'name' => 'isf_handoff',
                    'type' => 'customEvent',
                    'customEventFilter' => [
                        ['type' => 'equals', 'parameter' => '{{_event}}', 'value' => 'isf_handoff'],
                    ],
                ],
            ],
            'variables' => [
                ['name' => 'DLV - isf_instance', 'type' => 'v', 'dataLayerName' => 'isf_instance'],
                ['name' => 'DLV - isf_instance_id', 'type' => 'v', 'dataLayerName' => 'isf_instance_id'],
                ['name' => 'DLV - isf_visitor_id', 'type' => 'v', 'dataLayerName' => 'isf_visitor_id'],
                ['name' => 'DLV - isf_step', 'type' => 'v', 'dataLayerName' => 'isf_step'],
                ['name' => 'DLV - isf_step_name', 'type' => 'v', 'dataLayerName' => 'isf_step_name'],
                ['name' => 'DLV - isf_device_type', 'type' => 'v', 'dataLayerName' => 'isf_device_type'],
                ['name' => 'DLV - isf_destination', 'type' => 'v', 'dataLayerName' => 'isf_destination'],
                ['name' => 'DLV - isf_button_text', 'type' => 'v', 'dataLayerName' => 'isf_button_text'],
            ],
        ];
    }
}
