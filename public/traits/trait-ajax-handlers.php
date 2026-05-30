<?php
namespace ISF\Frontend;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Frontend AJAX Handlers Trait
 *
 * @package    FormFlow
 * @subpackage Frontend
 * @since      2.8.4
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

/**
 * Trait Frontend_Ajax_Handlers
 *
 * AJAX handlers for form operations.
 *
 * @since 2.8.4
 */
trait Frontend_Ajax_Handlers {
    /**
     * Load a form step
     */
    public function isf_load_step(): void {
        if (!Security::verify_ajax_request('isf_form_nonce')) {
            return;
        }

        try {
            $instance = $this->get_instance_from_request();
            $session_id = sanitize_text_field($_POST['session_id'] ?? '');

            // Check for 'success' step BEFORE casting to int
            $raw_step = $_POST['step'] ?? 1;
            $is_success_step = ($raw_step === 'success' || $raw_step === 'complete');

            $step = $is_success_step ? 'success' : (int)$raw_step;
            // Ensure numeric step is at least 1
            if (!$is_success_step && $step < 1) {
                $step = 1;
            }
            $form_data_json = stripslashes($_POST['form_data'] ?? '{}');
            $posted_form_data = json_decode($form_data_json, true) ?: [];

            if (!$instance) {
                wp_send_json_error(['message' => __('Invalid form.', 'formflow')]);
                return;
            }

            $instance_id = $instance['id'];

            // Get or create submission
            $submission = $this->db->get_submission_by_session($session_id, $instance_id);

            // Ensure form_data is always an array before merging
            $existing_data = [];
            if ($submission && isset($submission['form_data'])) {
                $existing_data = is_array($submission['form_data']) ? $submission['form_data'] : [];
            }
            $form_data = array_merge($existing_data, $posted_form_data);

            // Render step template based on form type
            ob_start();

            $form_type = $instance['form_type'] ?? 'enrollment';

            // Fetch promo codes for step 3
            $promo_codes = [];
            if ($step === 3 && $form_type === 'enrollment') {
                $promo_codes = $this->fetch_promo_codes($instance);
            }

            // Handle success template separately
            if ($is_success_step) {
                if ($form_type === 'scheduler') {
                    $template_file = ISF_PLUGIN_DIR . 'includes/intellisource/templates/scheduler/success.php';
                } else {
                    $template_file = ISF_PLUGIN_DIR . 'includes/intellisource/templates/enrollment/success.php';
                }
            } elseif ($form_type === 'scheduler') {
                $template_file = ISF_PLUGIN_DIR . "includes/intellisource/templates/scheduler/step-{$step}-" . $this->get_scheduler_step_name($step) . '.php';
            } else {
                $template_file = ISF_PLUGIN_DIR . "includes/intellisource/templates/enrollment/step-{$step}-" . $this->get_step_name($step) . '.php';
            }

            if (file_exists($template_file)) {
                include $template_file;
            } else {
                echo '<p>' . esc_html__('Step not found.', 'formflow') . '</p>';
            }

            $html = ob_get_clean();

            wp_send_json_success([
                'html' => $html,
                'step' => $step,
                'form_type' => $form_type
            ]);
        } catch (\Throwable $e) {
            // Clean up any partial output
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Log the error
            $this->db->log('error', 'Failed to load step: ' . $e->getMessage(), [
                'step' => $step ?? 0,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], $instance_id ?? null);

            wp_send_json_error([
                'message' => __('Failed to load step. Please refresh and try again.', 'formflow')
            ]);
        }
    }

    /**
     * Fetch promo codes from API
     */
    private function fetch_promo_codes(array $instance): array {
        try {
            $api = $this->get_api_client($instance);
            return $api->get_promo_codes();
        } catch (\Exception $e) {
            $this->db->log('warning', 'Failed to fetch promo codes: ' . $e->getMessage(), [], $instance['id']);
            return [];
        }
    }

    /**
     * Validate account number
     */
    public function isf_validate_account(): void {
        // Enable error reporting for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_reporting(E_ALL);
            ini_set('display_errors', 0);
        }

        if (!Security::verify_ajax_request('isf_form_nonce')) {
            wp_send_json_error(['message' => 'Security verification failed.']);
            return;
        }

        $instance = $this->get_instance_from_request();
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $account_number = sanitize_text_field($_POST['utility_no'] ?? $_POST['account_number'] ?? '');
        $zip_code = sanitize_text_field($_POST['zip'] ?? $_POST['zip_code'] ?? '');

        // Validate inputs
        if (empty($account_number) || empty($zip_code)) {
            wp_send_json_error([
                'message' => __('Please enter your account number and ZIP code.', 'formflow')
            ]);
            return;
        }

        if (!$instance) {
            wp_send_json_error(['message' => __('Invalid form.', 'formflow')]);
            return;
        }

        $instance_id = $instance['id'];

        try {
            // Call API to validate account
            $api = $this->get_api_client($instance);

            $result = $api->validate_account($account_number, $zip_code);

            if (!$result->is_valid()) {
                wp_send_json_error([
                    'message' => $result->get_error_message() ?: __('Account validation failed. Please check your information.', 'formflow')
                ]);
                return;
            }

            // Get or create submission record
            $submission = $this->db->get_submission_by_session($session_id, $instance_id);
            $form_data = $submission ? $submission['form_data'] : [];

            // Store validation data
            $form_data['account_number'] = $account_number;
            $form_data['zip_code'] = $zip_code;
            $form_data['ca_no'] = $result->get_ca_no();
            $form_data['comverge_no'] = $result->get_comverge_no();
            $form_data['validation_result'] = $result->to_array();

            // Pre-fill customer info if available
            if ($result->get_first_name()) {
                $form_data['first_name'] = $result->get_first_name();
            }
            if ($result->get_last_name()) {
                $form_data['last_name'] = $result->get_last_name();
            }
            if ($result->get_email()) {
                $form_data['email'] = $result->get_email();
            }
            $address = $result->get_address();
            if (!empty($address['street'])) {
                $form_data['address'] = $address;
            }

            // Check if demo mode (auto-mark as test data)
            $is_demo = $instance['settings']['demo_mode'] ?? false;

            if ($submission) {
                $this->db->update_submission($submission['id'], [
                    'account_number' => $account_number,
                    'form_data' => $form_data,
                    'step' => 3
                ]);
                // Mark as test if demo mode
                if ($is_demo) {
                    $this->db->mark_session_as_test($session_id);
                }
            } else {
                $this->db->create_submission([
                    'instance_id' => $instance_id,
                    'session_id' => $session_id,
                    'account_number' => $account_number,
                    'form_data' => $form_data,
                    'step' => 3,
                    'ip_address' => Security::get_client_ip(),
                    'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '')
                ]);
                // Mark as test if demo mode
                if ($is_demo) {
                    $this->db->mark_session_as_test($session_id);
                }
            }

            // Trigger webhook for account validated
            $customer_name = trim($result->get_first_name() . ' ' . $result->get_last_name());
            $this->trigger_webhook('account.validated', [
                'account_number' => $account_number,
                'customer_name' => $customer_name,
                'premise_address' => $result->get_address(),
                'is_valid' => true,
            ], $instance_id);

            // Build response with additional flags
            $response = [
                'message' => __('Account validated successfully.', 'formflow'),
                'customer' => [
                    'first_name' => $result->get_first_name(),
                    'last_name' => $result->get_last_name(),
                    'email' => $result->get_email(),
                    'address' => $result->get_address()
                ]
            ];

            // Add medical condition flag if applicable
            if ($result->requires_medical_acknowledgment()) {
                $response['requires_medical_acknowledgment'] = true;
                $response['medical_message'] = __('Important: Our records indicate that there may be a person with a critical medical condition in this household. By continuing with this enrollment, you acknowledge that cycling events may occur during high energy demand periods. If this is a concern, please contact customer service before proceeding.', 'formflow');
            }

            wp_send_json_success($response);

        } catch (\Exception $e) {
            $this->db->log('error', 'Account validation error: ' . $e->getMessage(), [
                'account' => Encryption::mask($account_number, 0, 4)
            ], $instance_id);

            wp_send_json_error([
                'message' => __('Unable to validate account. Please try again later.', 'formflow')
            ]);
        }
    }

    /**
     * Get available schedule slots
     */
    public function isf_get_schedule_slots(): void {
        if (!Security::verify_ajax_request('isf_form_nonce')) {
            return;
        }

        $instance = $this->get_instance_from_request();
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');

        if (!$instance) {
            wp_send_json_error(['message' => __('Invalid form.', 'formflow')]);
            return;
        }

        $instance_id = $instance['id'];

        $submission = $this->db->get_submission_by_session($session_id, $instance_id);
        if (!$submission) {
            wp_send_json_error(['message' => __('Session expired. Please start over.', 'formflow')]);
            return;
        }

        $form_data = $submission['form_data'];
        $account_number = $form_data['comverge_no'] ?? $form_data['account_number'] ?? '';

        // Calculate start date (3+ business days out)
        $start_date = $this->calculate_start_date();

        try {
            $api = $this->get_api_client($instance);

            // Build equipment array
            $equipment = [];
            if (!empty($form_data['ac_units'])) {
                $equipment['05'] = ['count' => (int)$form_data['ac_units'], 'location' => '05'];
            }
            if (!empty($form_data['heat_pumps'])) {
                $equipment['20'] = ['count' => (int)$form_data['heat_pumps'], 'location' => '05'];
            }
            if (!empty($form_data['ac_heat_units'])) {
                $equipment['15'] = ['count' => (int)$form_data['ac_heat_units'], 'location' => '05'];
            }

            $result = $api->get_schedule_slots($account_number, $start_date, $equipment);

            // Store scheduling data in session
            $form_data['fsr_no'] = $result->get_fsr_no();
            $form_data['scheduling_result'] = $result->to_array();

            $this->db->update_submission($submission['id'], [
                'form_data' => $form_data
            ]);

            // Get slots formatted for display
            $total_equipment = $result->get_total_equipment_count();
            $slots = $result->get_slots_for_display($total_equipment ?: 1);

            // Apply scheduling settings (blocked dates and capacity limits)
            $slots = $this->apply_scheduling_settings($slots, $instance);

            wp_send_json_success([
                'slots' => $slots,
                'is_scheduled' => $result->is_scheduled(),
                'schedule_date' => $result->get_schedule_date(),
                'schedule_time' => $result->get_schedule_time(),
                'equipment' => [
                    'ac_count' => $result->get_thermostats_ac_count(),
                    'heat_count' => $result->get_thermostats_heat_count(),
                    'ac_heat_count' => $result->get_thermostats_ac_heat_count(),
                    'total' => $total_equipment
                ]
            ]);

        } catch (\Exception $e) {
            $this->db->log('error', 'Get schedule slots error: ' . $e->getMessage(), [], $instance_id);

            wp_send_json_error([
                'message' => __('Unable to load available appointments. Please try again.', 'formflow')
            ]);
        }
    }

    /**
     * Submit enrollment
     */
    public function isf_submit_enrollment(): void {
        if (!Security::verify_ajax_request('isf_form_nonce')) {
            wp_send_json_error(['message' => 'Security verification failed.']);
            return;
        }

        $instance = $this->get_instance_from_request();
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $submitted_data = isset($_POST['form_data']) ? json_decode(stripslashes($_POST['form_data']), true) : [];

        if (!$instance) {
            wp_send_json_error(['message' => __('Invalid form.', 'formflow')]);
            return;
        }

        $instance_id = $instance['id'];

        $submission = $this->db->get_submission_by_session($session_id, $instance_id);
        if (!$submission) {
            wp_send_json_error(['message' => __('Session expired. Please start over.', 'formflow')]);
            return;
        }

        // Merge submitted data with existing form data
        $form_data = array_merge($submission['form_data'] ?? [], Security::sanitize_form_data($submitted_data));

        // Server-side validation for all steps before final submission
        $validation_errors = $this->validate_all_form_steps($form_data);
        if (!empty($validation_errors)) {
            $this->db->log('warning', 'Enrollment failed server-side validation', [
                'errors' => $validation_errors,
            ], $instance_id, $submission['id']);

            wp_send_json_error([
                'message' => reset($validation_errors), // First error
                'validation_errors' => $validation_errors
            ]);
            return;
        }

        // Ensure agree_terms is set for API submission
        $form_data['agree_terms'] = true;

        // Update submission with customer info
        $customer_name = trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? ''));

        // Determine if scheduling was skipped
        $schedule_later = !empty($form_data['schedule_later']) || empty($form_data['schedule_date']);

        // Check demo mode
        $demo_mode = $instance['settings']['demo_mode'] ?? false;

        try {
            // Validate required fields for API before proceeding
            $missing_fields = FieldMapper::validateRequiredFields($form_data, 'enrollment');
            if (!empty($missing_fields)) {
                $this->db->log('warning', 'Enrollment missing required fields', [
                    'missing' => $missing_fields,
                ], $instance_id, $submission['id']);

                wp_send_json_error([
                    'message' => sprintf(
                        __('Missing required information: %s', 'formflow'),
                        implode(', ', $missing_fields)
                    ),
                    'missing_fields' => $missing_fields
                ]);
                return;
            }

            // Generate confirmation number (will be replaced by API response if available)
            $confirmation_number = 'EWR-' . strtoupper(substr(md5($session_id . time()), 0, 8));

            // Submit enrollment to API (unless in demo mode)
            if (!$demo_mode) {
                $api = $this->get_api_client($instance);

                // The API client's enroll() method will use FieldMapper to convert
                // our form field names to the API's expected parameter names
                $api_response = $api->enroll($form_data);

                // Extract confirmation number from API response if available
                if (!empty($api_response['confirmation_number'])) {
                    $confirmation_number = $api_response['confirmation_number'];
                } elseif (!empty($api_response['confirmationNumber'])) {
                    $confirmation_number = $api_response['confirmationNumber'];
                }

                // Log API response for debugging
                $this->db->log('info', 'Enrollment API response received', [
                    'has_confirmation' => !empty($confirmation_number),
                    'response_keys' => array_keys($api_response),
                ], $instance_id, $submission['id']);

                // If there's a scheduled appointment, book it
                if (!$schedule_later && !empty($form_data['schedule_date']) && !empty($form_data['schedule_time'])) {
                    // Book the appointment via API
                    $fsr = $api_response['fsr'] ?? '';
                    $ca_no = $form_data['account_number'] ?? $api_response['caNo'] ?? '';

                    if ($fsr && $ca_no) {
                        $equipment = $this->build_equipment_array($form_data);
                        $api->book_appointment(
                            $fsr,
                            $ca_no,
                            $form_data['schedule_date'],
                            $form_data['schedule_time'],
                            $equipment
                        );
                    }
                }
            } else {
                // Demo mode - log that we're simulating
                $this->db->log('info', 'Demo mode: Enrollment simulated', [
                    'would_send' => FieldMapper::mapEnrollmentData($form_data),
                ], $instance_id, $submission['id']);
            }

            // Store confirmation number in form data
            $form_data['confirmation_number'] = $confirmation_number;

            // Mark submission as completed
            $this->db->update_submission($submission['id'], [
                'customer_name' => $customer_name,
                'device_type' => $form_data['device_type'] ?? null,
                'form_data' => $form_data,
                'status' => 'completed',
                'step' => 5,
                'completed_at' => current_time('mysql')
            ]);

            // Log successful enrollment
            $this->db->log('info', 'Enrollment completed', [
                'confirmation' => $confirmation_number,
                'customer' => $customer_name,
                'schedule_later' => $schedule_later,
                'demo_mode' => $demo_mode
            ], $instance_id, $submission['id']);

            // Trigger webhook for enrollment completion
            $this->trigger_webhook('enrollment.completed', [
                'submission_id' => $submission['id'],
                'instance_id' => $instance_id,
                'form_data' => [
                    'account_number' => $form_data['account_number'] ?? $form_data['utility_no'] ?? '',
                    'customer_name' => $customer_name,
                    'device_type' => $form_data['device_type'] ?? 'thermostat',
                ],
                'confirmation_number' => $confirmation_number,
            ], $instance_id);

            // Send confirmation email (if configured)
            $this->send_confirmation_email($instance, $form_data, $confirmation_number);

            // Send SMS notification (if enabled)
            $this->send_sms_confirmation($instance, $form_data, $confirmation_number);

            // Send team notification (if enabled)
            $this->send_team_notification($instance, $submission, $confirmation_number);

            // Get visitor ID and UTM data for analytics hooks
            $visitor_id = apply_filters(\ISF\Hooks::GET_VISITOR_ID, null);
            if (!$visitor_id) {
                $visitor_tracker = new Analytics\VisitorTracker();
                $visitor_id = $visitor_tracker->get_visitor_id() ?? '';
            }

            $utm_tracker = new \ISF\UTMTracker();
            $utm_data = $utm_tracker->get_tracking_data();

            // Build comprehensive submission data for hooks
            $submission_data = [
                'submission_id' => $submission['id'],
                'instance_id' => $instance_id,
                'instance_slug' => $instance['slug'] ?? '',
                'visitor_id' => $visitor_id,
                'form_data' => $form_data,
                'utm_data' => $utm_data,
                'form_type' => $instance['form_type'] ?? 'enrollment',
                'status' => 'completed',
                'confirmation_number' => $confirmation_number,
            ];

            // Fire enrollment completed hook (existing)
            do_action(\ISF\Hooks::ENROLLMENT_COMPLETED, $submission['id'], $instance_id, $form_data);

            // Fire form completed hook (Peanut Suite compatible)
            do_action(\ISF\Hooks::FORM_COMPLETED, $submission_data);

            wp_send_json_success([
                'message' => __('Enrollment completed successfully!', 'formflow'),
                'confirmation_number' => $confirmation_number,
                'schedule_later' => $schedule_later
            ]);

        } catch (FieldMappingException $e) {
            // Field mapping/validation error - user needs to provide more info
            $this->db->log('warning', 'Field mapping error: ' . $e->getMessage(), [
                'missing_fields' => $e->getMissingFields(),
            ], $instance_id, $submission['id']);

            $missing_labels = array_map(
                fn($field) => FieldMapper::getFieldLabel($field),
                $e->getMissingFields()
            );

            wp_send_json_error([
                'message' => sprintf(
                    __('Missing required information: %s', 'formflow'),
                    implode(', ', $missing_labels)
                ),
                'missing_fields' => $missing_labels
            ]);

        } catch (\Exception $e) {
            $this->db->log('error', 'Enrollment submission error: ' . $e->getMessage(), [
                'exception_class' => get_class($e),
            ], $instance_id, $submission['id']);

            // Add to retry queue for automatic retry
            $this->db->add_to_retry_queue(
                $submission['id'],
                $instance_id,
                $e->getMessage()
            );

            // Mark submission as failed (will be retried)
            $this->db->update_submission($submission['id'], [
                'status' => 'failed',
                'form_data' => $form_data,
            ]);

            // Send failure notification to team (if enabled)
            $this->send_failure_notification($instance, $submission, $e->getMessage());

            wp_send_json_error([
                'message' => __('An error occurred while processing your enrollment. We will automatically retry your submission. Please check your email for confirmation.', 'formflow')
            ]);
        }
    }

    /**
     * Build equipment array for API calls based on form data
     */
    private function build_equipment_array(array $form_data): array {
        $equipment = [];
        $device_type = $form_data['device_type'] ?? 'thermostat';

        if ($device_type === 'thermostat') {
            $count = (int)($form_data['thermostat_count'] ?? 1);
            $equipment['thermostat'] = [
                'count' => max(1, $count),
                'location' => 'Interior',
            ];
        } else {
            // DCU / Outdoor Switch
            $equipment['dcu'] = [
                'count' => 1,
                'location' => 'Exterior',
            ];
        }

        return $equipment;
    }

    /**
     * Send confirmation email
     */
    private function send_confirmation_email(array $instance, array $form_data, string $confirmation_number): void {
        // Check if email sending is enabled for this instance
        // Default to true for backward compatibility
        $send_email = $instance['settings']['send_confirmation_email'] ?? true;
        if (!$send_email) {
            $this->db->log('info', 'Confirmation email skipped - disabled in settings', [
                'confirmation' => $confirmation_number,
            ], $instance['id']);
            return;
        }

        $to = $form_data['email'] ?? '';
        if (empty($to)) {
            return;
        }

        $from = $instance['support_email_from'] ?? '';
        $customer_name = trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? ''));
        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? __('Energy Wise Rewards', 'formflow');
        $support_phone = $instance['settings']['support_phone'] ?? '';

        // Build address string
        $address = trim(($form_data['street'] ?? '') . ', ' .
                       ($form_data['city'] ?? '') . ', ' .
                       ($form_data['state'] ?? '') . ' ' .
                       ($form_data['zip'] ?? $form_data['zip_confirm'] ?? ''));

        // Device name
        $device = ($form_data['device_type'] ?? 'thermostat') === 'thermostat'
            ? __('Web-Programmable Thermostat', 'formflow')
            : __('Outdoor Switch', 'formflow');

        // Schedule info
        $schedule_date = $form_data['schedule_date'] ?? '';
        $schedule_time = $form_data['schedule_time'] ?? '';
        if (empty($schedule_date)) {
            $schedule_date = __('To be scheduled', 'formflow');
            $schedule_time = __('A representative will contact you', 'formflow');
        }

        // Placeholder replacements
        $replacements = [
            '{name}' => $customer_name,
            '{email}' => $to,
            '{phone}' => $support_phone,
            '{address}' => $address,
            '{device}' => $device,
            '{date}' => $schedule_date,
            '{time}' => $schedule_time,
            '{confirmation_number}' => $confirmation_number,
            '{program_name}' => $program_name,
        ];

        // Get customizable subject or use default
        $subject = $content['email_subject'] ?? '';
        if (empty($subject)) {
            $subject = sprintf(
                __('%s Enrollment Confirmation - %s', 'formflow'),
                $program_name,
                $confirmation_number
            );
        } else {
            $subject = str_replace(array_keys($replacements), array_values($replacements), $subject);
        }

        // Get customizable email body or use default
        $email_body = $content['email_body'] ?? '';
        $email_heading = $content['email_heading'] ?? __('Thank You for Enrolling!', 'formflow');
        $email_footer = $content['email_footer'] ?? __('Thank you for helping us build a more reliable energy grid!', 'formflow');

        if (empty($email_body)) {
            // Default plain text message
            $message = sprintf(
                __("Dear %s,\n\nThank you for enrolling in the %s program!\n\nConfirmation Number: %s\n\nDevice: %s\nService Address: %s\nInstallation Date: %s\nInstallation Time: %s\n\nA technician will arrive during your scheduled time window. Please ensure an adult (18+) is present.\n\nIf you have any questions, please call us at %s.\n\n%s", 'formflow'),
                $customer_name,
                $program_name,
                $confirmation_number,
                $device,
                $address,
                $schedule_date,
                $schedule_time,
                $support_phone,
                $email_footer
            );

            $headers = ['Content-Type: text/plain; charset=UTF-8'];
        } else {
            // Build HTML email from customizable template
            $email_body = str_replace(array_keys($replacements), array_values($replacements), $email_body);
            $email_heading = str_replace(array_keys($replacements), array_values($replacements), $email_heading);
            $email_footer = str_replace(array_keys($replacements), array_values($replacements), $email_footer);

            $message = $this->build_html_email($email_heading, $email_body, $email_footer, $program_name);
            $headers = ['Content-Type: text/html; charset=UTF-8'];
        }

        if ($from) {
            $headers[] = 'From: ' . $from;
        }

        // CC support emails
        $cc_emails = $instance['support_email_to'] ?? '';
        if (!empty($cc_emails)) {
            $cc_list = array_map('trim', explode(',', $cc_emails));
            foreach ($cc_list as $cc) {
                if (is_email($cc)) {
                    $headers[] = 'Cc: ' . $cc;
                }
            }
        }

        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Build HTML email template
     */
    private function build_html_email(string $heading, string $body, string $footer, string $program_name): string {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($program_name) . '</title>
</head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background-color:#f4f4f4;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f4f4;padding:20px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color:#0073aa;padding:30px;text-align:center;">
                            <h1 style="margin:0;color:#ffffff;font-size:24px;">' . esc_html($heading) . '</h1>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:30px;color:#333333;font-size:16px;line-height:1.6;">
                            ' . wp_kses_post($body) . '
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f8f8f8;padding:20px 30px;text-align:center;color:#666666;font-size:14px;border-top:1px solid #eeeeee;">
                            <p style="margin:0 0 10px;">' . esc_html($footer) . '</p>
                            <p style="margin:0;font-size:12px;color:#999999;">' . esc_html($program_name) . '</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Book appointment
     */
    public function isf_book_appointment(): void {
        if (!Security::verify_ajax_request('isf_form_nonce')) {
            return;
        }

        $instance = $this->get_instance_from_request();
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $schedule_date = sanitize_text_field($_POST['schedule_date'] ?? '');
        $schedule_time = sanitize_text_field($_POST['schedule_time'] ?? '');

        if (empty($schedule_date) || empty($schedule_time)) {
            wp_send_json_error([
                'message' => __('Please select an appointment date and time.', 'formflow')
            ]);
            return;
        }

        if (!$instance) {
            wp_send_json_error(['message' => __('Invalid form.', 'formflow')]);
            return;
        }

        $instance_id = $instance['id'];

        $submission = $this->db->get_submission_by_session($session_id, $instance_id);
        if (!$submission) {
            wp_send_json_error(['message' => __('Session expired. Please start over.', 'formflow')]);
            return;
        }

        $form_data = $submission['form_data'];

        try {
            $api = $this->get_api_client($instance);

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

            $result = $api->book_appointment(
                $fsr,
                $ca_no,
                $schedule_date,
                $schedule_time,
                $equipment
            );

            // Check result (API returns 0 for success)
            $booking_code = is_string($result) ? trim($result) : ($result['code'] ?? '-1');

            if ($booking_code === '0') {
                // Success - update submission
                $form_data['schedule_date'] = $schedule_date;
                $form_data['schedule_time'] = $schedule_time;
                $form_data['schedule_time_display'] = $this->get_time_display($schedule_time);

                $this->db->update_submission($submission['id'], [
                    'form_data' => $form_data,
                    'status' => 'completed',
                    'step' => 5
                ]);

                // Trigger webhook for appointment scheduled
                $customer_name = trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? ''));
                $this->trigger_webhook('appointment.scheduled', [
                    'submission_id' => $submission['id'],
                    'instance_id' => $instance_id,
                    'form_data' => [
                        'account_number' => $form_data['account_number'] ?? '',
                        'customer_name' => $customer_name,
                        'device_type' => $form_data['device_type'] ?? 'thermostat',
                    ],
                    'schedule_date' => $schedule_date,
                    'schedule_time' => $this->get_time_display($schedule_time),
                ], $instance_id);

                // Send confirmation email
                $this->send_confirmation_email($instance, $form_data);

                wp_send_json_success([
                    'message' => __('Your appointment has been scheduled!', 'formflow'),
                    'schedule_date' => $schedule_date,
                    'schedule_time' => $this->get_time_display($schedule_time)
                ]);

            } elseif ($booking_code === '-1') {
                wp_send_json_error([
                    'message' => __('That time slot is no longer available. Please select another.', 'formflow')
                ]);
            } else {
                wp_send_json_error([
                    'message' => __('Unable to book appointment. Please try again.', 'formflow')
                ]);
            }

        } catch (\Exception $e) {
            $this->db->log('error', 'Book appointment error: ' . $e->getMessage(), [], $instance_id);

            wp_send_json_error([
                'message' => __('Unable to book appointment. Please try again later.', 'formflow')
            ]);
        }
    }

    /**
     * Save form progress
     */
    public function isf_save_progress(): void {
        if (!Security::verify_ajax_request('isf_form_nonce')) {
            return;
        }

        $instance = $this->get_instance_from_request();
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $step = (int)($_POST['step'] ?? 1);
        $submitted_data = isset($_POST['form_data']) ? json_decode(stripslashes($_POST['form_data']), true) : [];

        if (!$instance) {
            wp_send_json_error(['message' => __('Invalid form.', 'formflow')]);
            return;
        }

        $instance_id = $instance['id'];

        $submission = $this->db->get_submission_by_session($session_id, $instance_id);

        $sanitized_data = is_array($submitted_data) ? Security::sanitize_form_data($submitted_data) : [];

        if ($submission) {
            $existing_data = is_array($submission['form_data']) ? $submission['form_data'] : [];
            $form_data = array_merge($existing_data, $sanitized_data);
            $this->db->update_submission($submission['id'], [
                'form_data' => $form_data,
                'step' => $step
            ]);
        } else {
            $this->db->create_submission([
                'instance_id' => $instance_id,
                'session_id' => $session_id,
                'form_data' => $sanitized_data,
                'step' => $step,
                'ip_address' => Security::get_client_ip(),
                'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '')
            ]);
        }

        wp_send_json_success(['saved' => true]);
    }

    /**
     * Save progress and send resume link via email
     */
    public function isf_save_and_email(): void {
        if (!Security::verify_ajax_request('isf_form_nonce')) {
            return;
        }

        try {
            $instance = $this->get_instance_from_request();
            $session_id = sanitize_text_field($_POST['session_id'] ?? '');
            $email = sanitize_email($_POST['email'] ?? '');
            $step = (int)($_POST['step'] ?? 1);
            $submitted_data = isset($_POST['form_data']) ? json_decode(stripslashes($_POST['form_data']), true) : [];

            if (!$instance) {
                wp_send_json_error(['message' => __('Invalid form.', 'formflow')]);
                return;
            }

            if (!is_email($email)) {
                wp_send_json_error(['message' => __('Please enter a valid email address.', 'formflow')]);
                return;
            }

            $instance_id = $instance['id'];

            // Save progress first
            $submission = $this->db->get_submission_by_session($session_id, $instance_id);
            $sanitized_data = is_array($submitted_data) ? Security::sanitize_form_data($submitted_data) : [];

            if ($submission) {
                $existing_data = is_array($submission['form_data']) ? $submission['form_data'] : [];
                $form_data = array_merge($existing_data, $sanitized_data);
                $this->db->update_submission($submission['id'], [
                    'form_data' => $form_data,
                    'step' => $step
                ]);
            } else {
                $this->db->create_submission([
                    'instance_id' => $instance_id,
                    'session_id' => $session_id,
                    'form_data' => $sanitized_data,
                    'step' => $step,
                    'ip_address' => Security::get_client_ip(),
                    'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '')
                ]);
            }

            // Generate resume token (valid for 7 days)
            $resume_token = wp_generate_password(32, false);
            $expiry = date('Y-m-d H:i:s', strtotime('+7 days'));

            // Store token in database
            $this->db->save_resume_token($session_id, $instance_id, $resume_token, $email, $expiry);

            // Build resume URL
            $resume_url = add_query_arg([
                'isf_resume' => $resume_token,
                'instance' => $instance['slug']
            ], home_url('/'));

            // Send email
            $content = $instance['settings']['content'] ?? [];
            $program_name = $content['program_name'] ?? __('Energy Wise Rewards', 'formflow');
            $support_phone = $instance['settings']['support_phone'] ?? '';

            $subject = sprintf(__('Continue Your %s Enrollment', 'formflow'), $program_name);

            $message = sprintf(
                __("Hello,\n\nYou requested to save your enrollment progress for the %s program.\n\nClick the link below to continue where you left off:\n%s\n\nThis link will expire in 7 days.\n\nIf you did not request this email, you can safely ignore it.\n\nQuestions? Call us at %s.\n\nThank you,\nThe %s Team", 'formflow'),
                $program_name,
                $resume_url,
                $support_phone,
                $program_name
            );

            $headers = ['Content-Type: text/plain; charset=UTF-8'];
            $from = $instance['support_email_from'] ?? '';
            if ($from) {
                $headers[] = 'From: ' . $from;
            }

            $sent = wp_mail($email, $subject, $message, $headers);

            if ($sent) {
                $this->db->log('info', 'Resume link sent', ['email' => Encryption::mask($email, 0, 4)], $instance_id);
                wp_send_json_success([
                    'message' => __('A link to continue your enrollment has been sent to your email.', 'formflow'),
                    'email_sent' => true
                ]);
            } else {
                // Progress was saved but email failed - still return success so modal closes
                $this->db->log('warning', 'Resume link email failed', ['email' => Encryption::mask($email, 0, 4)], $instance_id);
                wp_send_json_success([
                    'message' => __('Your progress has been saved, but we could not send the email. Please try again or note down the page URL.', 'formflow'),
                    'email_sent' => false
                ]);
            }
        } catch (\Throwable $e) {
            $this->db->log('error', 'Save and email error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], $instance_id ?? null);

            wp_send_json_error([
                'message' => __('Failed to save progress. Please try again.', 'formflow')
            ]);
        }
    }

    /**
     * Resume form from token
     */
    public function isf_resume_form(): void {
        if (!Security::verify_ajax_request('isf_form_nonce')) {
            return;
        }

        $token = sanitize_text_field($_POST['resume_token'] ?? '');
        $instance = $this->get_instance_from_request();

        if (!$instance || empty($token)) {
            wp_send_json_error(['message' => __('Invalid resume link.', 'formflow')]);
            return;
        }

        // Look up the token
        $resume_data = $this->db->get_resume_token($token, $instance['id']);

        if (!$resume_data) {
            wp_send_json_error(['message' => __('This link has expired or is invalid.', 'formflow')]);
            return;
        }

        // Get the submission
        $submission = $this->db->get_submission_by_session($resume_data['session_id'], $instance['id']);

        if (!$submission) {
            wp_send_json_error(['message' => __('No saved progress found.', 'formflow')]);
            return;
        }

        // Mark token as used
        $this->db->mark_resume_token_used($token);

        wp_send_json_success([
            'session_id' => $resume_data['session_id'],
            'step' => $submission['step'],
            'form_data' => $submission['form_data']
        ]);
    }

    /**
     * Track step analytics event
     */
    public function isf_track_step(): void {
        if (!Security::verify_ajax_request('isf_form_nonce')) {
            return;
        }

        $instance = $this->get_instance_from_request();
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $step = (int)($_POST['step'] ?? 1);
        $action = sanitize_text_field($_POST['event_action'] ?? 'enter');
        $step_name = sanitize_text_field($_POST['step_name'] ?? '');
        $time_on_step = (int)($_POST['time_on_step'] ?? 0);
        $browser = sanitize_text_field($_POST['browser'] ?? '');
        $is_mobile = (int)($_POST['is_mobile'] ?? 0);
        $referrer = esc_url_raw($_POST['referrer'] ?? '');

        if (!$instance || empty($session_id)) {
            wp_send_json_error(['message' => __('Invalid request.', 'formflow')]);
            return;
        }

        // Validate action
        $valid_actions = ['enter', 'exit', 'complete', 'abandon'];
        if (!in_array($action, $valid_actions)) {
            wp_send_json_error(['message' => __('Invalid action.', 'formflow')]);
            return;
        }

        // Get submission if exists
        $submission = $this->db->get_submission_by_session($session_id, $instance['id']);

        // Check if demo mode (auto-mark analytics as test)
        $is_demo = $instance['settings']['demo_mode'] ?? false;

        // Track the event
        $result = $this->db->track_step_event([
            'instance_id' => $instance['id'],
            'submission_id' => $submission['id'] ?? null,
            'session_id' => $session_id,
            'step' => $step,
            'step_name' => $step_name,
            'action' => $action,
            'time_on_step' => $time_on_step,
            'browser' => $browser,
            'is_mobile' => $is_mobile,
            'is_test' => $is_demo ? 1 : 0,
            'referrer' => $referrer
        ]);

        if ($result) {
            wp_send_json_success(['tracked' => true]);
        } else {
            wp_send_json_error(['message' => __('Failed to track event.', 'formflow')]);
        }
    }
}
