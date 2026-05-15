<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Appointment Self-Service Handler
 *
 * Allows customers to reschedule or cancel appointments via email links.
 */


class AppointmentSelfService {

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
     * Generate a secure token for appointment management
     */
    public function generate_token(int $submission_id, int $instance_id): string {
        $data = [
            'submission_id' => $submission_id,
            'instance_id' => $instance_id,
            'created' => time(),
        ];

        $payload = json_encode($data);
        $encrypted = $this->encryption->encrypt($payload);

        // URL-safe base64
        return rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
    }

    /**
     * Validate and decode a token
     */
    public function validate_token(string $token, array $instance): ?array {
        try {
            // Decode URL-safe base64
            $encrypted = base64_decode(strtr($token, '-_', '+/'));
            $payload = $this->encryption->decrypt($encrypted);

            if (!$payload) {
                return null;
            }

            $data = json_decode($payload, true);

            if (!$data || empty($data['submission_id']) || empty($data['created'])) {
                return null;
            }

            // Check token expiry
            $config = FeatureManager::get_feature($instance, 'appointment_self_service');
            $expiry_days = $config['token_expiry_days'] ?? 30;
            $expiry_seconds = $expiry_days * 24 * 60 * 60;

            if (time() - $data['created'] > $expiry_seconds) {
                return null;
            }

            // Verify instance matches
            if ($data['instance_id'] !== $instance['id']) {
                return null;
            }

            return $data;

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get appointment details by token
     */
    public function get_appointment(string $token, array $instance): ?array {
        $data = $this->validate_token($token, $instance);

        if (!$data) {
            return null;
        }

        $submission = $this->db->get_submission($data['submission_id']);

        if (!$submission || $submission['instance_id'] !== $instance['id']) {
            return null;
        }

        if ($submission['status'] !== 'completed') {
            return null;
        }

        $form_data = $submission['form_data'] ?? [];

        return [
            'submission_id' => $submission['id'],
            'customer_name' => $submission['customer_name'],
            'email' => $form_data['email'] ?? '',
            'phone' => $form_data['phone'] ?? '',
            'device_type' => $submission['device_type'],
            'schedule_date' => $form_data['schedule_date'] ?? '',
            'schedule_time' => $form_data['schedule_time'] ?? '',
            'confirmation_number' => $form_data['confirmation_number'] ?? '',
            'address' => $this->format_address($form_data),
            'can_reschedule' => $this->can_reschedule($form_data, $instance),
            'can_cancel' => $this->can_cancel($form_data, $instance),
        ];
    }

    /**
     * Check if appointment can be rescheduled
     */
    private function can_reschedule(array $form_data, array $instance): bool {
        $config = FeatureManager::get_feature($instance, 'appointment_self_service');

        if (empty($config['allow_reschedule'])) {
            return false;
        }

        // No existing appointment = can schedule
        if (empty($form_data['schedule_date'])) {
            return true;
        }

        // Check deadline
        $deadline_hours = $config['reschedule_deadline_hours'] ?? 24;
        return $this->is_before_deadline($form_data['schedule_date'], $form_data['schedule_time'] ?? 'AM', $deadline_hours);
    }

    /**
     * Check if appointment can be cancelled
     */
    private function can_cancel(array $form_data, array $instance): bool {
        $config = FeatureManager::get_feature($instance, 'appointment_self_service');

        if (empty($config['allow_cancel'])) {
            return false;
        }

        // No appointment = nothing to cancel
        if (empty($form_data['schedule_date'])) {
            return false;
        }

        // Check deadline
        $deadline_hours = $config['cancel_deadline_hours'] ?? 24;
        return $this->is_before_deadline($form_data['schedule_date'], $form_data['schedule_time'] ?? 'AM', $deadline_hours);
    }

    /**
     * Check if current time is before the deadline
     */
    private function is_before_deadline(string $date, string $time_slot, int $hours_before): bool {
        // Get start time of the slot
        $time_map = [
            'AM' => '08:00',
            'MD' => '11:00',
            'PM' => '14:00',
            'EV' => '17:00',
        ];

        $start_time = $time_map[strtoupper($time_slot)] ?? '08:00';
        $appointment_datetime = strtotime("{$date} {$start_time}");
        $deadline = $appointment_datetime - ($hours_before * 60 * 60);

        return time() < $deadline;
    }

    /**
     * Reschedule an appointment
     */
    public function reschedule(string $token, array $instance, string $new_date, string $new_time): array {
        $data = $this->validate_token($token, $instance);

        if (!$data) {
            return ['success' => false, 'message' => __('Invalid or expired link.', 'formflow')];
        }

        $submission = $this->db->get_submission($data['submission_id']);

        if (!$submission) {
            return ['success' => false, 'message' => __('Appointment not found.', 'formflow')];
        }

        $form_data = $submission['form_data'] ?? [];

        if (!$this->can_reschedule($form_data, $instance)) {
            return ['success' => false, 'message' => __('This appointment can no longer be rescheduled.', 'formflow')];
        }

        // Validate new date/time
        if (empty($new_date) || empty($new_time)) {
            return ['success' => false, 'message' => __('Please select a date and time.', 'formflow')];
        }

        // Check capacity if enabled
        if (FeatureManager::is_enabled($instance, 'capacity_management')) {
            $capacity = new CapacityManager();
            if (!$capacity->is_slot_available($instance, $new_date, $new_time)) {
                return ['success' => false, 'message' => __('This time slot is no longer available.', 'formflow')];
            }
        }

        // Update the submission
        $old_date = $form_data['schedule_date'] ?? '';
        $old_time = $form_data['schedule_time'] ?? '';

        $form_data['schedule_date'] = $new_date;
        $form_data['schedule_time'] = $new_time;

        $this->db->update_submission($submission['id'], [
            'form_data' => $form_data,
        ]);

        // Log the change
        $this->db->log('info', 'Appointment rescheduled via self-service', [
            'old_date' => $old_date,
            'old_time' => $old_time,
            'new_date' => $new_date,
            'new_time' => $new_time,
        ], $instance['id'], $submission['id']);

        // Send confirmation email
        $this->send_reschedule_confirmation($instance, $submission, $form_data);

        return [
            'success' => true,
            'message' => __('Your appointment has been rescheduled.', 'formflow'),
            'new_date' => $new_date,
            'new_time' => $new_time,
        ];
    }

    /**
     * Cancel an appointment
     */
    public function cancel(string $token, array $instance, string $reason = ''): array {
        $data = $this->validate_token($token, $instance);

        if (!$data) {
            return ['success' => false, 'message' => __('Invalid or expired link.', 'formflow')];
        }

        $submission = $this->db->get_submission($data['submission_id']);

        if (!$submission) {
            return ['success' => false, 'message' => __('Appointment not found.', 'formflow')];
        }

        $form_data = $submission['form_data'] ?? [];

        if (!$this->can_cancel($form_data, $instance)) {
            return ['success' => false, 'message' => __('This appointment can no longer be cancelled.', 'formflow')];
        }

        // Check if reason is required
        $config = FeatureManager::get_feature($instance, 'appointment_self_service');
        if (!empty($config['require_reason_for_cancel']) && empty($reason)) {
            return ['success' => false, 'message' => __('Please provide a reason for cancellation.', 'formflow')];
        }

        // Mark appointment as cancelled
        $old_date = $form_data['schedule_date'] ?? '';
        $old_time = $form_data['schedule_time'] ?? '';

        $form_data['schedule_date'] = '';
        $form_data['schedule_time'] = '';
        $form_data['cancelled'] = true;
        $form_data['cancelled_at'] = current_time('mysql');
        $form_data['cancel_reason'] = $reason;

        $this->db->update_submission($submission['id'], [
            'form_data' => $form_data,
            'status' => 'cancelled',
        ]);

        // Log the cancellation
        $this->db->log('info', 'Appointment cancelled via self-service', [
            'date' => $old_date,
            'time' => $old_time,
            'reason' => $reason,
        ], $instance['id'], $submission['id']);

        // Send cancellation confirmation
        $this->send_cancellation_confirmation($instance, $submission, $form_data, $reason);

        // Notify team if configured
        if (FeatureManager::is_enabled($instance, 'team_notifications')) {
            $team = new TeamNotifications();
            $team->notify_cancellation($instance, $submission, $reason);
        }

        return [
            'success' => true,
            'message' => __('Your appointment has been cancelled.', 'formflow'),
        ];
    }

    /**
     * Format address from form data
     */
    private function format_address(array $form_data): string {
        $parts = array_filter([
            $form_data['address'] ?? '',
            $form_data['city'] ?? '',
            ($form_data['state'] ?? '') . ' ' . ($form_data['zip'] ?? ''),
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get appointment management URL
     */
    public function get_management_url(int $submission_id, array $instance): string {
        $token = $this->generate_token($submission_id, $instance['id']);

        return add_query_arg([
            'isf_manage' => $token,
            'instance' => $instance['slug'],
        ], home_url('/'));
    }

    /**
     * Send reschedule confirmation email
     */
    private function send_reschedule_confirmation(array $instance, array $submission, array $form_data): void {
        $email = $form_data['email'] ?? '';

        if (empty($email)) {
            return;
        }

        $language = $form_data['language'] ?? 'en';
        $strings = TranslationHandler::get_email_strings($language);

        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'Energy Wise Rewards';

        $date_display = date('l, F j, Y', strtotime($form_data['schedule_date']));
        $time_display = $this->get_time_display($form_data['schedule_time']);

        $subject = $language === 'es'
            ? "Cita Reprogramada - {$program_name}"
            : "Appointment Rescheduled - {$program_name}";

        $message = $language === 'es'
            ? "Su cita ha sido reprogramada para:\n\nFecha: {$date_display}\nHora: {$time_display}\n\nSi tiene preguntas, contáctenos."
            : "Your appointment has been rescheduled to:\n\nDate: {$date_display}\nTime: {$time_display}\n\nIf you have questions, please contact us.";

        wp_mail($email, $subject, $message);
    }

    /**
     * Send cancellation confirmation email
     */
    private function send_cancellation_confirmation(array $instance, array $submission, array $form_data, string $reason): void {
        $email = $form_data['email'] ?? '';

        if (empty($email)) {
            return;
        }

        $language = $form_data['language'] ?? 'en';
        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'Energy Wise Rewards';

        $subject = $language === 'es'
            ? "Cita Cancelada - {$program_name}"
            : "Appointment Cancelled - {$program_name}";

        $message = $language === 'es'
            ? "Su cita ha sido cancelada.\n\nSi desea reprogramar, visite nuestro sitio web o llámenos."
            : "Your appointment has been cancelled.\n\nIf you would like to reschedule, please visit our website or give us a call.";

        wp_mail($email, $subject, $message);
    }

    /**
     * Get display string for time slot
     */
    private function get_time_display(string $time): string {
        $displays = [
            'AM' => '8:00 AM - 11:00 AM',
            'MD' => '11:00 AM - 2:00 PM',
            'PM' => '2:00 PM - 5:00 PM',
            'EV' => '5:00 PM - 8:00 PM',
        ];

        return $displays[strtoupper($time)] ?? $time;
    }

    /**
     * Render the self-service page
     */
    public function render_page(string $token, array $instance): string {
        $appointment = $this->get_appointment($token, $instance);

        if (!$appointment) {
            return $this->render_error_page(__('Invalid or expired link. Please contact support for assistance.', 'formflow'));
        }

        ob_start();
        include ISF_PLUGIN_DIR . 'public/templates/appointment-self-service.php';
        return ob_get_clean();
    }

    /**
     * Render error page
     */
    private function render_error_page(string $message): string {
        return sprintf(
            '<div class="isf-self-service-error">
                <div class="isf-error-icon">⚠️</div>
                <h2>%s</h2>
                <p>%s</p>
            </div>',
            esc_html__('Unable to Access Appointment', 'formflow'),
            esc_html($message)
        );
    }
}
