<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * SMS Notification Handler
 *
 * Sends SMS notifications via Twilio for enrollment confirmations and appointment reminders.
 * Feature-togglable per instance via FeatureManager.
 */


class SmsHandler {

    /**
     * Twilio API endpoints
     */
    private const TWILIO_API_BASE = 'https://api.twilio.com/2010-04-01';

    /**
     * Database instance
     */
    private Database\Database $db;

    /**
     * Encryption instance
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
     * Send enrollment confirmation SMS
     *
     * @param array $instance The form instance
     * @param array $form_data The enrollment data
     * @param string $confirmation_number The confirmation number
     * @return bool Success status
     */
    public function send_enrollment_confirmation(array $instance, array $form_data, string $confirmation_number): bool {
        // Check if SMS notifications are enabled for this instance
        if (!FeatureManager::is_enabled($instance, 'sms_notifications')) {
            return false;
        }

        $sms_config = FeatureManager::get_feature($instance, 'sms_notifications');

        // Check if enrollment confirmation is enabled
        if (empty($sms_config['send_enrollment_confirmation'])) {
            return false;
        }

        $phone = $form_data['phone'] ?? '';
        if (empty($phone)) {
            return false;
        }

        // Format phone number for Twilio (E.164 format)
        $to_number = $this->format_phone_e164($phone);
        if (!$to_number) {
            $this->log('warning', 'Invalid phone number for SMS', ['phone' => $phone], $instance['id']);
            return false;
        }

        // Build message
        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'Energy Wise Rewards';
        $customer_name = trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? ''));

        $message = sprintf(
            "%s: Thank you for enrolling! Your confirmation number is %s. A technician will contact you to schedule your installation.",
            $program_name,
            $confirmation_number
        );

        return $this->send_sms($instance, $to_number, $message);
    }

    /**
     * Send appointment reminder SMS
     *
     * @param array $instance The form instance
     * @param array $submission The submission data
     * @return bool Success status
     */
    public function send_appointment_reminder(array $instance, array $submission): bool {
        // Check if SMS notifications are enabled for this instance
        if (!FeatureManager::is_enabled($instance, 'sms_notifications')) {
            return false;
        }

        $sms_config = FeatureManager::get_feature($instance, 'sms_notifications');

        // Check if appointment reminders are enabled
        if (empty($sms_config['send_appointment_reminder'])) {
            return false;
        }

        $form_data = $submission['form_data'] ?? [];
        $phone = $form_data['phone'] ?? '';

        if (empty($phone)) {
            return false;
        }

        $to_number = $this->format_phone_e164($phone);
        if (!$to_number) {
            return false;
        }

        // Build message
        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'Energy Wise Rewards';
        $schedule_date = $form_data['schedule_date'] ?? '';
        $schedule_time = $form_data['schedule_time'] ?? '';

        // Format date
        $date_display = $schedule_date;
        if ($schedule_date) {
            $date_obj = \DateTime::createFromFormat('Y-m-d', $schedule_date);
            if ($date_obj) {
                $date_display = $date_obj->format('l, F j');
            }
        }

        // Format time
        $time_display = $this->get_time_display($schedule_time);

        $message = sprintf(
            "%s Reminder: Your installation appointment is tomorrow, %s, between %s. Please ensure an adult is home. Questions? Call the number in your confirmation email.",
            $program_name,
            $date_display,
            $time_display
        );

        return $this->send_sms($instance, $to_number, $message);
    }

    /**
     * Send SMS via Twilio
     *
     * @param array $instance The form instance
     * @param string $to Phone number in E.164 format
     * @param string $message The message to send
     * @return bool Success status
     */
    private function send_sms(array $instance, string $to, string $message): bool {
        $sms_config = FeatureManager::get_feature($instance, 'sms_notifications');

        $account_sid = $sms_config['account_sid'] ?? '';
        $auth_token = $sms_config['auth_token'] ?? '';
        $from_number = $sms_config['from_number'] ?? '';

        // Decrypt credentials if encrypted
        if (!empty($account_sid) && strpos($account_sid, 'enc:') === 0) {
            $account_sid = $this->encryption->decrypt(substr($account_sid, 4));
        }
        if (!empty($auth_token) && strpos($auth_token, 'enc:') === 0) {
            $auth_token = $this->encryption->decrypt(substr($auth_token, 4));
        }

        if (empty($account_sid) || empty($auth_token) || empty($from_number)) {
            $this->log('error', 'SMS credentials not configured', [], $instance['id']);
            return false;
        }

        // Format from number
        $from_number = $this->format_phone_e164($from_number);

        $url = self::TWILIO_API_BASE . "/Accounts/{$account_sid}/Messages.json";

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($account_sid . ':' . $auth_token),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'To' => $to,
                'From' => $from_number,
                'Body' => $message,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->log('error', 'Twilio API error: ' . $response->get_error_message(), [], $instance['id']);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300) {
            $this->log('info', 'SMS sent successfully', [
                'to' => $this->mask_phone($to),
                'sid' => $body['sid'] ?? 'unknown',
            ], $instance['id']);
            return true;
        }

        $this->log('error', 'Twilio API error', [
            'status' => $status_code,
            'error' => $body['message'] ?? 'Unknown error',
            'code' => $body['code'] ?? null,
        ], $instance['id']);

        return false;
    }

    /**
     * Format phone number to E.164 format
     *
     * @param string $phone The phone number
     * @return string|null E.164 formatted number or null if invalid
     */
    private function format_phone_e164(string $phone): ?string {
        // Remove all non-digits
        $digits = preg_replace('/\D/', '', $phone);

        // Handle US numbers
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        // Already has country code
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }

        // Invalid
        if (strlen($digits) < 10) {
            return null;
        }

        // Assume it's valid with country code
        return '+' . $digits;
    }

    /**
     * Mask phone number for logging
     *
     * @param string $phone The phone number
     * @return string Masked phone number
     */
    private function mask_phone(string $phone): string {
        if (strlen($phone) < 6) {
            return '***';
        }
        return substr($phone, 0, 4) . '****' . substr($phone, -2);
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
        $this->db->log($type, '[SMS] ' . $message, $details, $instance_id);
    }

    /**
     * Test SMS configuration
     *
     * @param array $config SMS configuration
     * @param string $test_number Phone number to send test to
     * @return array Result with success status and message
     */
    public static function test_configuration(array $config, string $test_number): array {
        $handler = new self();

        $account_sid = $config['account_sid'] ?? '';
        $auth_token = $config['auth_token'] ?? '';
        $from_number = $config['from_number'] ?? '';

        // Validate configuration
        if (empty($account_sid) || empty($auth_token) || empty($from_number)) {
            return [
                'success' => false,
                'message' => __('Please fill in all Twilio credentials.', 'formflow'),
            ];
        }

        // Format numbers
        $to = $handler->format_phone_e164($test_number);
        $from = $handler->format_phone_e164($from_number);

        if (!$to) {
            return [
                'success' => false,
                'message' => __('Invalid test phone number format.', 'formflow'),
            ];
        }

        if (!$from) {
            return [
                'success' => false,
                'message' => __('Invalid from phone number format.', 'formflow'),
            ];
        }

        // Send test message
        $url = self::TWILIO_API_BASE . "/Accounts/{$account_sid}/Messages.json";

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($account_sid . ':' . $auth_token),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'To' => $to,
                'From' => $from,
                'Body' => 'FormFlow test message. If you received this, SMS is configured correctly!',
            ],
            'timeout' => 30,
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

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300) {
            return [
                'success' => true,
                'message' => __('Test SMS sent successfully! Check your phone.', 'formflow'),
                'sid' => $body['sid'] ?? null,
            ];
        }

        return [
            'success' => false,
            'message' => sprintf(
                __('Twilio error: %s', 'formflow'),
                $body['message'] ?? 'Unknown error'
            ),
            'code' => $body['code'] ?? null,
        ];
    }
}
