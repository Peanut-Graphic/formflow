<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Team Notifications Handler
 *
 * Sends notifications to Slack or Microsoft Teams when enrollments come in.
 * Feature-togglable per instance via FeatureManager.
 */


class TeamNotifications {

    /**
     * Database instance
     */
    private Database\Database $db;

    /**
     * Encryption helper (for reading the tagged-encrypted webhook URL).
     */
    private Encryption $encryption;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database\Database();
        $this->encryption = new Encryption();
    }

    /**
     * Send notification for new enrollment
     *
     * @param array $instance The form instance
     * @param array $submission The submission data
     * @param string $confirmation_number The confirmation number
     * @return bool Success status
     */
    public function notify_enrollment(array $instance, array $submission, string $confirmation_number): bool {
        // Check if team notifications are enabled
        if (!FeatureManager::is_enabled($instance, 'team_notifications')) {
            return false;
        }

        $config = FeatureManager::get_feature($instance, 'team_notifications');

        // Check if enrollment notifications are enabled
        if (empty($config['notify_on_enrollment'])) {
            return false;
        }

        $form_data = $submission['form_data'] ?? [];
        $customer_name = trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? ''));
        $device_type = $form_data['device_type'] ?? 'thermostat';
        $device_display = $device_type === 'thermostat' ? 'Thermostat' : 'Outdoor Switch';

        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'Energy Wise Rewards';

        // Build message based on provider
        $provider = $config['provider'] ?? 'slack';
        $webhook_url = $this->encryption->decrypt_secret($config['webhook_url'] ?? '');

        if (empty($webhook_url)) {
            return false;
        }

        // Schedule info
        $schedule_info = '';
        if (!empty($form_data['schedule_date'])) {
            $date = $form_data['schedule_date'];
            $time = $form_data['schedule_time'] ?? '';
            $schedule_info = $date . ' ' . $this->get_time_display($time);
        } else {
            $schedule_info = 'To be scheduled';
        }

        if ($provider === 'slack') {
            return $this->send_slack_notification($webhook_url, [
                'type' => 'enrollment',
                'program_name' => $program_name,
                'instance_name' => $instance['name'],
                'customer_name' => $customer_name,
                'confirmation_number' => $confirmation_number,
                'device_type' => $device_display,
                'schedule' => $schedule_info,
                'email' => $form_data['email'] ?? '',
                'phone' => $form_data['phone'] ?? '',
            ], $instance['id']);
        } elseif ($provider === 'teams') {
            return $this->send_teams_notification($webhook_url, [
                'type' => 'enrollment',
                'program_name' => $program_name,
                'instance_name' => $instance['name'],
                'customer_name' => $customer_name,
                'confirmation_number' => $confirmation_number,
                'device_type' => $device_display,
                'schedule' => $schedule_info,
                'email' => $form_data['email'] ?? '',
                'phone' => $form_data['phone'] ?? '',
            ], $instance['id']);
        }

        return false;
    }

    /**
     * Send notification for failed enrollment
     *
     * @param array $instance The form instance
     * @param array $submission The submission data
     * @param string $error_message The error message
     * @return bool Success status
     */
    public function notify_failure(array $instance, array $submission, string $error_message): bool {
        // Check if team notifications are enabled
        if (!FeatureManager::is_enabled($instance, 'team_notifications')) {
            return false;
        }

        $config = FeatureManager::get_feature($instance, 'team_notifications');

        // Check if failure notifications are enabled
        if (empty($config['notify_on_failure'])) {
            return false;
        }

        $form_data = $submission['form_data'] ?? [];
        $customer_name = trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? ''));

        $provider = $config['provider'] ?? 'slack';
        $webhook_url = $this->encryption->decrypt_secret($config['webhook_url'] ?? '');

        if (empty($webhook_url)) {
            return false;
        }

        if ($provider === 'slack') {
            return $this->send_slack_failure($webhook_url, [
                'instance_name' => $instance['name'],
                'customer_name' => $customer_name,
                'submission_id' => $submission['id'],
                'error' => $error_message,
            ], $instance['id']);
        } elseif ($provider === 'teams') {
            return $this->send_teams_failure($webhook_url, [
                'instance_name' => $instance['name'],
                'customer_name' => $customer_name,
                'submission_id' => $submission['id'],
                'error' => $error_message,
            ], $instance['id']);
        }

        return false;
    }

    /**
     * Send Slack notification for enrollment
     */
    private function send_slack_notification(string $webhook_url, array $data, int $instance_id): bool {
        $payload = [
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => '🎉 New Enrollment',
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Program:*\n{$data['program_name']}",
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Instance:*\n{$data['instance_name']}",
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Customer:*\n{$data['customer_name']}",
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Confirmation:*\n{$data['confirmation_number']}",
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Device:*\n{$data['device_type']}",
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Schedule:*\n{$data['schedule']}",
                        ],
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "📧 {$data['email']} | 📱 {$data['phone']}",
                        ],
                    ],
                ],
            ],
        ];

        return $this->send_webhook($webhook_url, $payload, $instance_id);
    }

    /**
     * Send Slack notification for failure
     */
    private function send_slack_failure(string $webhook_url, array $data, int $instance_id): bool {
        $payload = [
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => '⚠️ Enrollment Failed',
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Instance:*\n{$data['instance_name']}",
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Customer:*\n{$data['customer_name']}",
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Error:*\n```{$data['error']}```",
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "Submission ID: {$data['submission_id']}",
                        ],
                    ],
                ],
            ],
        ];

        return $this->send_webhook($webhook_url, $payload, $instance_id);
    }

    /**
     * Send Teams notification for enrollment
     */
    private function send_teams_notification(string $webhook_url, array $data, int $instance_id): bool {
        $payload = [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => '28a745',
            'summary' => 'New Enrollment',
            'sections' => [
                [
                    'activityTitle' => '🎉 New Enrollment',
                    'activitySubtitle' => $data['program_name'] . ' - ' . $data['instance_name'],
                    'facts' => [
                        ['name' => 'Customer', 'value' => $data['customer_name']],
                        ['name' => 'Confirmation', 'value' => $data['confirmation_number']],
                        ['name' => 'Device', 'value' => $data['device_type']],
                        ['name' => 'Schedule', 'value' => $data['schedule']],
                        ['name' => 'Email', 'value' => $data['email']],
                        ['name' => 'Phone', 'value' => $data['phone']],
                    ],
                    'markdown' => true,
                ],
            ],
        ];

        return $this->send_webhook($webhook_url, $payload, $instance_id);
    }

    /**
     * Send Teams notification for failure
     */
    private function send_teams_failure(string $webhook_url, array $data, int $instance_id): bool {
        $payload = [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => 'dc3545',
            'summary' => 'Enrollment Failed',
            'sections' => [
                [
                    'activityTitle' => '⚠️ Enrollment Failed',
                    'activitySubtitle' => $data['instance_name'],
                    'facts' => [
                        ['name' => 'Customer', 'value' => $data['customer_name']],
                        ['name' => 'Submission ID', 'value' => (string)$data['submission_id']],
                        ['name' => 'Error', 'value' => $data['error']],
                    ],
                    'markdown' => true,
                ],
            ],
        ];

        return $this->send_webhook($webhook_url, $payload, $instance_id);
    }

    /**
     * Send webhook request
     */
    private function send_webhook(string $url, array $payload, int $instance_id): bool {
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->log('error', 'Webhook error: ' . $response->get_error_message(), [], $instance_id);
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300) {
            $this->log('info', 'Team notification sent', ['status' => $status_code], $instance_id);
            return true;
        }

        $this->log('error', 'Webhook failed', [
            'status' => $status_code,
            'body' => wp_remote_retrieve_body($response),
        ], $instance_id);

        return false;
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
            'ev' => '5:00 PM - 8:00 PM',
        ];

        return $displays[$time] ?? $time;
    }

    /**
     * Log a message
     */
    private function log(string $type, string $message, array $details = [], ?int $instance_id = null): void {
        $this->db->log($type, '[Team] ' . $message, $details, $instance_id);
    }

    /**
     * Test webhook configuration
     *
     * @param string $webhook_url The webhook URL
     * @param string $provider 'slack' or 'teams'
     * @return array Result with success status and message
     */
    public static function test_webhook(string $webhook_url, string $provider): array {
        if (empty($webhook_url)) {
            return [
                'success' => false,
                'message' => __('Please enter a webhook URL.', 'formflow'),
            ];
        }

        if (!filter_var($webhook_url, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'message' => __('Please enter a valid webhook URL.', 'formflow'),
            ];
        }

        // Build test message
        if ($provider === 'slack') {
            $payload = [
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => '✅ *FormFlow* - Test notification successful! Your webhook is configured correctly.',
                        ],
                    ],
                ],
            ];
        } else {
            $payload = [
                '@type' => 'MessageCard',
                '@context' => 'http://schema.org/extensions',
                'themeColor' => '28a745',
                'summary' => 'Test Notification',
                'sections' => [
                    [
                        'activityTitle' => '✅ FormFlow',
                        'text' => 'Test notification successful! Your webhook is configured correctly.',
                    ],
                ],
            ];
        }

        $response = wp_remote_post($webhook_url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Connection error: %s', 'formflow'),
                    $response->get_error_message()
                ),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300) {
            return [
                'success' => true,
                'message' => __('Test notification sent successfully!', 'formflow'),
            ];
        }

        return [
            'success' => false,
            'message' => sprintf(
                __('Webhook returned status %d. Please check your URL.', 'formflow'),
                $status_code
            ),
        ];
    }
}
