<?php
/**
 * Appointment Bundler
 *
 * Manages bundled appointments for multi-program enrollments.
 * Consolidates multiple program installations into single service calls.
 *
 * @package FormFlow
 * @since 2.6.0
 */

namespace ISF\Programs;

defined('ABSPATH') || exit;

class AppointmentBundler {

    /**
     * Singleton instance
     */
    private static ?AppointmentBundler $instance = null;

    /**
     * Database table name
     */
    private string $table_appointments;
    private string $table_bundled_appointments;

    /**
     * Program manager instance
     */
    private ?ProgramManager $program_manager = null;

    /**
     * Get singleton instance
     */
    public static function instance(): AppointmentBundler {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_appointments = $wpdb->prefix . 'isf_appointments';
        $this->table_bundled_appointments = $wpdb->prefix . 'isf_bundled_appointments';
        $this->program_manager = ProgramManager::instance();
    }

    /**
     * Initialize hooks
     */
    public function init(): void {
        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Ensure tables exist
        add_action('admin_init', [$this, 'maybe_create_tables']);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        // Public pre-flight: frontend asks whether a bundle is possible.
        // Rate-limited because the eligibility logic is DB-heavy.
        register_rest_route('isf/v1', '/appointments/bundle-check', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_check_bundle'],
            'permission_callback' => ['\\ISF\\Security', 'rate_limit_public'],
        ]);

        // Public pre-flight: frontend pulls available slots.
        // Rate-limited (scheduler API is rate-limited too).
        register_rest_route('isf/v1', '/appointments/bundled-slots', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_get_bundled_slots'],
            'permission_callback' => ['\\ISF\\Security', 'rate_limit_public'],
        ]);

        // Write endpoint: creates a bundled appointment. Needs CSRF
        // protection — frontend supplies a WP REST nonce.
        register_rest_route('isf/v1', '/appointments/bundle', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_create_bundled_appointment'],
            'permission_callback' => ['\\ISF\\Security', 'nonce_or_logged_in'],
        ]);

        // Public: bundle ID in the URL is the auth (opaque alphanumeric
        // token). Anyone with the link can view; same shape as
        // /handoff/{token} elsewhere in the plugin.
        register_rest_route('isf/v1', '/appointments/bundle/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_bundled_appointment'],
            'permission_callback' => '__return_true',
        ]);

        // Write endpoint: reschedule. Needs CSRF protection.
        register_rest_route('isf/v1', '/appointments/bundle/(?P<id>[a-zA-Z0-9_-]+)/reschedule', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_reschedule_bundled_appointment'],
            'permission_callback' => ['\\ISF\\Security', 'nonce_or_logged_in'],
        ]);

        // Write endpoint: cancel. Needs CSRF protection.
        register_rest_route('isf/v1', '/appointments/bundle/(?P<id>[a-zA-Z0-9_-]+)/cancel', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_cancel_bundled_appointment'],
            'permission_callback' => ['\\ISF\\Security', 'nonce_or_logged_in'],
        ]);
    }

    /**
     * Create database tables if they don't exist
     */
    public function maybe_create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_bundled_appointments} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            bundle_id VARCHAR(64) NOT NULL,
            submission_id INT UNSIGNED,
            account_number VARCHAR(50),
            programs JSON NOT NULL,
            appointment_date DATE NOT NULL,
            time_slot VARCHAR(50) NOT NULL,
            time_start TIME NOT NULL,
            time_end TIME NOT NULL,
            estimated_duration INT NOT NULL DEFAULT 60,
            service_address JSON,
            contact_info JSON,
            special_instructions TEXT,
            status ENUM('scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'rescheduled', 'no_show') DEFAULT 'scheduled',
            technician_id VARCHAR(50),
            technician_name VARCHAR(255),
            confirmation_number VARCHAR(100),
            api_responses JSON,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY bundle_id (bundle_id),
            KEY submission_id (submission_id),
            KEY account_number (account_number),
            KEY appointment_date (appointment_date),
            KEY status (status)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Check per-IP rate limit for public REST endpoints.
     *
     * @param string $action Unique action identifier.
     * @param int    $limit  Max requests per window.
     * @param int    $window Time window in seconds.
     * @return \WP_REST_Response|null Error response if exceeded, null if OK.
     */
    private function check_rest_rate_limit(string $action, int $limit = 20, int $window = 60): ?\WP_REST_Response {
        $allowed = apply_filters(\ISF\Hooks::CHECK_RATE_LIMIT, true, $action, $limit, $window);
        if (!$allowed) {
            return new \WP_REST_Response(
                ['error' => 'Too many requests. Please try again later.'],
                429
            );
        }
        return null;
    }

    /**
     * REST callback: Check if programs can be bundled
     */
    public function rest_check_bundle(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_error = $this->check_rest_rate_limit('isf_bundle_check', 20, 60);
        if ($rate_limit_error !== null) {
            return $rate_limit_error;
        }

        $program_ids = $request->get_param('program_ids') ?: [];

        $result = $this->can_bundle_programs($program_ids);

        return new \WP_REST_Response([
            'success' => true,
            'can_bundle' => $result['can_bundle'],
            'programs_requiring_scheduling' => $result['scheduling_programs'],
            'estimated_duration' => $result['total_duration'],
            'message' => $result['message'],
        ], 200);
    }

    /**
     * REST callback: Get available bundled time slots
     */
    public function rest_get_bundled_slots(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_error = $this->check_rest_rate_limit('isf_bundle_slots', 20, 60);
        if ($rate_limit_error !== null) {
            return $rate_limit_error;
        }

        $program_ids = $request->get_param('program_ids') ?: [];
        $date = $request->get_param('date');
        $zip_code = $request->get_param('zip_code');
        $instance_id = $request->get_param('instance_id');

        $slots = $this->get_bundled_time_slots($program_ids, $date, $zip_code, $instance_id);

        return new \WP_REST_Response([
            'success' => true,
            'date' => $date,
            'slots' => $slots,
        ], 200);
    }

    /**
     * REST callback: Create bundled appointment
     */
    public function rest_create_bundled_appointment(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_error = $this->check_rest_rate_limit('isf_bundle_create', 10, 60);
        if ($rate_limit_error !== null) {
            return $rate_limit_error;
        }

        $data = [
            'submission_id' => $request->get_param('submission_id'),
            'program_ids' => $request->get_param('program_ids') ?: [],
            'account_number' => $request->get_param('account_number'),
            'date' => $request->get_param('date'),
            'time_slot' => $request->get_param('time_slot'),
            'service_address' => $request->get_param('service_address'),
            'contact_info' => $request->get_param('contact_info'),
            'special_instructions' => $request->get_param('special_instructions'),
        ];

        $result = $this->create_bundled_appointment($data);

        if (!$result['success']) {
            return new \WP_REST_Response($result, 400);
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * REST callback: Get bundled appointment
     */
    public function rest_get_bundled_appointment(\WP_REST_Request $request): \WP_REST_Response {
        $bundle_id = $request->get_param('id');

        $appointment = $this->get_bundled_appointment($bundle_id);

        if (!$appointment) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Appointment not found',
            ], 404);
        }

        return new \WP_REST_Response([
            'success' => true,
            'appointment' => $appointment,
        ], 200);
    }

    /**
     * REST callback: Reschedule bundled appointment
     */
    public function rest_reschedule_bundled_appointment(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_error = $this->check_rest_rate_limit('isf_bundle_reschedule', 5, 60);
        if ($rate_limit_error !== null) {
            return $rate_limit_error;
        }

        $bundle_id = $request->get_param('id');
        $new_date = $request->get_param('date');
        $new_time_slot = $request->get_param('time_slot');

        $result = $this->reschedule_bundled_appointment($bundle_id, $new_date, $new_time_slot);

        if (!$result['success']) {
            return new \WP_REST_Response($result, 400);
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * REST callback: Cancel bundled appointment
     */
    public function rest_cancel_bundled_appointment(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_error = $this->check_rest_rate_limit('isf_bundle_cancel', 5, 60);
        if ($rate_limit_error !== null) {
            return $rate_limit_error;
        }

        $bundle_id = $request->get_param('id');
        $reason = $request->get_param('reason');

        $result = $this->cancel_bundled_appointment($bundle_id, $reason);

        if (!$result['success']) {
            return new \WP_REST_Response($result, 400);
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * Check if programs can be bundled into a single appointment
     */
    public function can_bundle_programs(array $program_ids): array {
        $scheduling_programs = [];
        $total_duration = 0;
        $utilities = [];

        foreach ($program_ids as $program_id) {
            $program = $this->program_manager->get_program($program_id);
            if (!$program) continue;

            if ($program['requires_scheduling']) {
                $scheduling_programs[] = [
                    'id' => $program['id'],
                    'name' => $program['name'],
                    'program_code' => $program['program_code'],
                    'estimated_duration' => $program['settings']['appointment_duration'] ?? 30,
                ];

                $total_duration += $program['settings']['appointment_duration'] ?? 30;
                $utilities[] = $program['utility'];
            }
        }

        // Programs can only be bundled if they're from the same utility
        $unique_utilities = array_unique($utilities);

        $can_bundle = count($scheduling_programs) > 1 && count($unique_utilities) === 1;

        $message = '';
        if (count($scheduling_programs) === 0) {
            $message = 'No programs require scheduling.';
        } elseif (count($scheduling_programs) === 1) {
            $message = 'Only one program requires scheduling.';
        } elseif (count($unique_utilities) > 1) {
            $message = 'Programs from different utilities cannot be bundled.';
        } else {
            $message = sprintf(
                'Bundle %d programs into a single %d-minute appointment.',
                count($scheduling_programs),
                $total_duration
            );
        }

        return [
            'can_bundle' => $can_bundle,
            'scheduling_programs' => $scheduling_programs,
            'total_duration' => $total_duration,
            'utilities' => $unique_utilities,
            'message' => $message,
        ];
    }

    /**
     * Get available time slots for bundled appointment
     */
    public function get_bundled_time_slots(array $program_ids, string $date, string $zip_code, ?int $instance_id = null): array {
        // Get required duration
        $bundle_check = $this->can_bundle_programs($program_ids);
        $required_duration = $bundle_check['total_duration'];

        // Define available time slots (this would typically come from an API)
        $base_slots = [
            ['start' => '08:00', 'end' => '10:00', 'label' => '8:00 AM - 10:00 AM'],
            ['start' => '10:00', 'end' => '12:00', 'label' => '10:00 AM - 12:00 PM'],
            ['start' => '12:00', 'end' => '14:00', 'label' => '12:00 PM - 2:00 PM'],
            ['start' => '14:00', 'end' => '16:00', 'label' => '2:00 PM - 4:00 PM'],
            ['start' => '16:00', 'end' => '18:00', 'label' => '4:00 PM - 6:00 PM'],
        ];

        $available_slots = [];

        foreach ($base_slots as $slot) {
            // Check if slot has enough duration
            $slot_minutes = (strtotime($slot['end']) - strtotime($slot['start'])) / 60;

            if ($slot_minutes >= $required_duration) {
                // Check availability (this would query existing appointments)
                $is_available = $this->check_slot_availability($date, $slot['start'], $zip_code);

                $available_slots[] = [
                    'start' => $slot['start'],
                    'end' => $slot['end'],
                    'label' => $slot['label'],
                    'available' => $is_available,
                    'duration_minutes' => $slot_minutes,
                    'can_accommodate' => $slot_minutes >= $required_duration,
                ];
            }
        }

        return $available_slots;
    }

    /**
     * Check if a time slot is available
     */
    private function check_slot_availability(string $date, string $time_start, string $zip_code): bool {
        global $wpdb;

        // Check existing bundled appointments
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_bundled_appointments}
             WHERE appointment_date = %s
             AND time_start = %s
             AND status NOT IN ('cancelled', 'completed')
             AND JSON_EXTRACT(service_address, '$.zip') = %s",
            $date,
            $time_start,
            $zip_code
        ));

        // For demo purposes, allow up to 5 appointments per slot per zip
        // In production, this would check against actual technician availability
        return $existing < 5;
    }

    /**
     * Create a bundled appointment
     */
    public function create_bundled_appointment(array $data): array {
        global $wpdb;

        // Validate required fields
        if (empty($data['program_ids'])) {
            return ['success' => false, 'message' => 'No programs specified'];
        }

        if (empty($data['date']) || empty($data['time_slot'])) {
            return ['success' => false, 'message' => 'Date and time slot are required'];
        }

        // Generate bundle ID
        $bundle_id = 'apt_' . wp_generate_password(12, false);

        // Get program details
        $programs_data = [];
        $estimated_duration = 0;

        foreach ($data['program_ids'] as $program_id) {
            $program = $this->program_manager->get_program($program_id);
            if ($program && $program['requires_scheduling']) {
                $programs_data[] = [
                    'program_id' => $program['id'],
                    'program_code' => $program['program_code'],
                    'name' => $program['name'],
                ];
                $estimated_duration += $program['settings']['appointment_duration'] ?? 30;
            }
        }

        if (empty($programs_data)) {
            return ['success' => false, 'message' => 'No programs require scheduling'];
        }

        // Parse time slot
        $time_parts = $this->parse_time_slot($data['time_slot']);

        // Generate confirmation number
        $confirmation_number = 'FF' . strtoupper(substr(md5($bundle_id . time()), 0, 8));

        // Insert appointment
        $inserted = $wpdb->insert($this->table_bundled_appointments, [
            'bundle_id' => $bundle_id,
            'submission_id' => $data['submission_id'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'programs' => wp_json_encode($programs_data),
            'appointment_date' => $data['date'],
            'time_slot' => $data['time_slot'],
            'time_start' => $time_parts['start'],
            'time_end' => $time_parts['end'],
            'estimated_duration' => $estimated_duration,
            'service_address' => wp_json_encode($data['service_address'] ?? []),
            'contact_info' => wp_json_encode($data['contact_info'] ?? []),
            'special_instructions' => $data['special_instructions'] ?? null,
            'status' => 'scheduled',
            'confirmation_number' => $confirmation_number,
        ]);

        if (!$inserted) {
            return ['success' => false, 'message' => 'Failed to create appointment'];
        }

        // Trigger API calls to external scheduling systems
        $this->sync_to_external_systems($bundle_id);

        // Send confirmation notification
        $this->send_confirmation_notification($bundle_id);

        return [
            'success' => true,
            'bundle_id' => $bundle_id,
            'confirmation_number' => $confirmation_number,
            'appointment' => $this->get_bundled_appointment($bundle_id),
        ];
    }

    /**
     * Parse time slot string into start/end times
     */
    private function parse_time_slot(string $time_slot): array {
        // Handle formats like "08:00-10:00" or "8:00 AM - 10:00 AM"
        if (preg_match('/(\d{1,2}):?(\d{2})?\s*(AM|PM)?\s*-\s*(\d{1,2}):?(\d{2})?\s*(AM|PM)?/i', $time_slot, $matches)) {
            $start_hour = (int)$matches[1];
            $start_min = isset($matches[2]) ? (int)$matches[2] : 0;
            $start_ampm = $matches[3] ?? '';

            $end_hour = (int)$matches[4];
            $end_min = isset($matches[5]) ? (int)$matches[5] : 0;
            $end_ampm = $matches[6] ?? '';

            // Convert to 24-hour format
            if (strtoupper($start_ampm) === 'PM' && $start_hour < 12) $start_hour += 12;
            if (strtoupper($end_ampm) === 'PM' && $end_hour < 12) $end_hour += 12;

            return [
                'start' => sprintf('%02d:%02d:00', $start_hour, $start_min),
                'end' => sprintf('%02d:%02d:00', $end_hour, $end_min),
            ];
        }

        // Default fallback
        return [
            'start' => '09:00:00',
            'end' => '11:00:00',
        ];
    }

    /**
     * Get bundled appointment details
     */
    public function get_bundled_appointment(string $bundle_id): ?array {
        global $wpdb;

        $appointment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_bundled_appointments} WHERE bundle_id = %s",
                $bundle_id
            ),
            ARRAY_A
        );

        if (!$appointment) {
            return null;
        }

        // Decode JSON fields
        $appointment['programs'] = json_decode($appointment['programs'], true);
        $appointment['service_address'] = json_decode($appointment['service_address'], true);
        $appointment['contact_info'] = json_decode($appointment['contact_info'], true);
        $appointment['api_responses'] = json_decode($appointment['api_responses'], true);

        // Add formatted date/time
        $appointment['formatted_date'] = date('l, F j, Y', strtotime($appointment['appointment_date']));
        $appointment['formatted_time'] = date('g:i A', strtotime($appointment['time_start'])) .
                                         ' - ' .
                                         date('g:i A', strtotime($appointment['time_end']));

        return $appointment;
    }

    /**
     * Reschedule a bundled appointment
     */
    public function reschedule_bundled_appointment(string $bundle_id, string $new_date, string $new_time_slot): array {
        global $wpdb;

        $appointment = $this->get_bundled_appointment($bundle_id);

        if (!$appointment) {
            return ['success' => false, 'message' => 'Appointment not found'];
        }

        if (in_array($appointment['status'], ['completed', 'cancelled'])) {
            return ['success' => false, 'message' => 'Cannot reschedule this appointment'];
        }

        $time_parts = $this->parse_time_slot($new_time_slot);

        $updated = $wpdb->update(
            $this->table_bundled_appointments,
            [
                'appointment_date' => $new_date,
                'time_slot' => $new_time_slot,
                'time_start' => $time_parts['start'],
                'time_end' => $time_parts['end'],
                'status' => 'rescheduled',
            ],
            ['bundle_id' => $bundle_id]
        );

        if ($updated === false) {
            return ['success' => false, 'message' => 'Failed to reschedule'];
        }

        // Sync to external systems
        $this->sync_to_external_systems($bundle_id);

        // Send notification
        $this->send_reschedule_notification($bundle_id);

        return [
            'success' => true,
            'message' => 'Appointment rescheduled successfully',
            'appointment' => $this->get_bundled_appointment($bundle_id),
        ];
    }

    /**
     * Cancel a bundled appointment
     */
    public function cancel_bundled_appointment(string $bundle_id, ?string $reason = null): array {
        global $wpdb;

        $appointment = $this->get_bundled_appointment($bundle_id);

        if (!$appointment) {
            return ['success' => false, 'message' => 'Appointment not found'];
        }

        if ($appointment['status'] === 'cancelled') {
            return ['success' => false, 'message' => 'Appointment is already cancelled'];
        }

        if ($appointment['status'] === 'completed') {
            return ['success' => false, 'message' => 'Cannot cancel a completed appointment'];
        }

        $notes = $appointment['notes'] ?? '';
        if ($reason) {
            $notes .= "\nCancellation reason: " . $reason;
        }

        $updated = $wpdb->update(
            $this->table_bundled_appointments,
            [
                'status' => 'cancelled',
                'notes' => trim($notes),
            ],
            ['bundle_id' => $bundle_id]
        );

        if ($updated === false) {
            return ['success' => false, 'message' => 'Failed to cancel appointment'];
        }

        // Sync to external systems
        $this->sync_cancellation_to_external_systems($bundle_id);

        // Send notification
        $this->send_cancellation_notification($bundle_id, $reason);

        return [
            'success' => true,
            'message' => 'Appointment cancelled successfully',
        ];
    }

    /**
     * Sync bundled appointment to external scheduling systems
     */
    private function sync_to_external_systems(string $bundle_id): void {
        $appointment = $this->get_bundled_appointment($bundle_id);
        if (!$appointment) return;

        $api_responses = [];

        // For each program in the bundle, sync to its respective API
        foreach ($appointment['programs'] as $program_data) {
            $program = $this->program_manager->get_program($program_data['program_id']);
            if (!$program) continue;

            // Get the instance settings for API configuration
            $api_code = $program['external_api_code'] ?? $program['program_code'];

            // This would call the actual external API
            // For now, we'll simulate a successful response
            $api_responses[$program_data['program_code']] = [
                'status' => 'success',
                'external_id' => 'EXT_' . strtoupper(wp_generate_password(6, false)),
                'synced_at' => current_time('mysql'),
            ];
        }

        // Update appointment with API responses
        global $wpdb;
        $wpdb->update(
            $this->table_bundled_appointments,
            ['api_responses' => wp_json_encode($api_responses)],
            ['bundle_id' => $bundle_id]
        );
    }

    /**
     * Sync cancellation to external systems
     */
    private function sync_cancellation_to_external_systems(string $bundle_id): void {
        // Similar to sync_to_external_systems but for cancellation
        // Would call cancellation endpoints on external APIs
    }

    /**
     * Send confirmation notification
     */
    private function send_confirmation_notification(string $bundle_id): void {
        $appointment = $this->get_bundled_appointment($bundle_id);
        if (!$appointment || empty($appointment['contact_info']['email'])) return;

        $programs_list = array_column($appointment['programs'], 'name');

        $subject = sprintf(
            'Appointment Confirmed - %s',
            $appointment['confirmation_number']
        );

        $message = sprintf(
            "Your bundled appointment has been confirmed.\n\n" .
            "Confirmation Number: %s\n" .
            "Date: %s\n" .
            "Time: %s\n\n" .
            "Programs:\n- %s\n\n" .
            "Address:\n%s\n\n" .
            "Please ensure someone 18 or older is present at the time of the appointment.",
            $appointment['confirmation_number'],
            $appointment['formatted_date'],
            $appointment['formatted_time'],
            implode("\n- ", $programs_list),
            $this->format_address($appointment['service_address'])
        );

        wp_mail(
            $appointment['contact_info']['email'],
            $subject,
            $message
        );
    }

    /**
     * Send reschedule notification
     */
    private function send_reschedule_notification(string $bundle_id): void {
        $appointment = $this->get_bundled_appointment($bundle_id);
        if (!$appointment || empty($appointment['contact_info']['email'])) return;

        $subject = sprintf(
            'Appointment Rescheduled - %s',
            $appointment['confirmation_number']
        );

        $message = sprintf(
            "Your appointment has been rescheduled.\n\n" .
            "Confirmation Number: %s\n" .
            "New Date: %s\n" .
            "New Time: %s\n\n" .
            "If you need to make further changes, please contact us.",
            $appointment['confirmation_number'],
            $appointment['formatted_date'],
            $appointment['formatted_time']
        );

        wp_mail(
            $appointment['contact_info']['email'],
            $subject,
            $message
        );
    }

    /**
     * Send cancellation notification
     */
    private function send_cancellation_notification(string $bundle_id, ?string $reason = null): void {
        $appointment = $this->get_bundled_appointment($bundle_id);
        if (!$appointment || empty($appointment['contact_info']['email'])) return;

        $subject = sprintf(
            'Appointment Cancelled - %s',
            $appointment['confirmation_number']
        );

        $message = sprintf(
            "Your appointment has been cancelled.\n\n" .
            "Confirmation Number: %s\n" .
            "Original Date: %s\n" .
            "Original Time: %s\n\n" .
            "%s" .
            "If you would like to reschedule, please visit our website or contact us.",
            $appointment['confirmation_number'],
            $appointment['formatted_date'],
            $appointment['formatted_time'],
            $reason ? "Reason: {$reason}\n\n" : ''
        );

        wp_mail(
            $appointment['contact_info']['email'],
            $subject,
            $message
        );
    }

    /**
     * Format address for display
     */
    private function format_address(array $address): string {
        $parts = array_filter([
            $address['street'] ?? '',
            $address['street2'] ?? '',
            implode(', ', array_filter([
                $address['city'] ?? '',
                ($address['state'] ?? '') . ' ' . ($address['zip'] ?? ''),
            ])),
        ]);

        return implode("\n", $parts);
    }

    /**
     * Get upcoming bundled appointments for an account
     */
    public function get_account_appointments(string $account_number, bool $upcoming_only = true): array {
        global $wpdb;

        $where = "account_number = %s";
        $params = [$account_number];

        if ($upcoming_only) {
            $where .= " AND appointment_date >= CURDATE() AND status NOT IN ('cancelled', 'completed')";
        }

        $appointments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_bundled_appointments}
                 WHERE {$where}
                 ORDER BY appointment_date ASC, time_start ASC",
                ...$params
            ),
            ARRAY_A
        );

        foreach ($appointments as &$apt) {
            $apt['programs'] = json_decode($apt['programs'], true);
            $apt['service_address'] = json_decode($apt['service_address'], true);
            $apt['formatted_date'] = date('l, F j, Y', strtotime($apt['appointment_date']));
            $apt['formatted_time'] = date('g:i A', strtotime($apt['time_start'])) .
                                     ' - ' .
                                     date('g:i A', strtotime($apt['time_end']));
        }

        return $appointments;
    }

    /**
     * Update appointment status
     */
    public function update_appointment_status(string $bundle_id, string $status, ?array $additional_data = null): bool {
        global $wpdb;

        $data = ['status' => $status];

        if ($additional_data) {
            if (isset($additional_data['technician_id'])) {
                $data['technician_id'] = $additional_data['technician_id'];
            }
            if (isset($additional_data['technician_name'])) {
                $data['technician_name'] = $additional_data['technician_name'];
            }
            if (isset($additional_data['notes'])) {
                $data['notes'] = $additional_data['notes'];
            }
        }

        $updated = $wpdb->update(
            $this->table_bundled_appointments,
            $data,
            ['bundle_id' => $bundle_id]
        );

        return $updated !== false;
    }
}
