<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Capacity Manager
 *
 * Manages appointment capacity, blackout dates, and waitlist.
 */


class CapacityManager {

    /**
     * Database instance
     */
    private Database\Database $db;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database\Database();
    }

    /**
     * Check if a slot is available
     */
    public function is_slot_available(array $instance, string $date, string $time_slot): bool {
        if (!FeatureManager::is_enabled($instance, 'capacity_management')) {
            return true;
        }

        $config = FeatureManager::get_feature($instance, 'capacity_management');

        // Check blackout dates
        if ($this->is_blackout_date($config, $date)) {
            return false;
        }

        // Check daily cap
        if (!empty($config['daily_cap']) && $config['daily_cap'] > 0) {
            $daily_count = $this->get_daily_appointment_count($instance['id'], $date);
            if ($daily_count >= $config['daily_cap']) {
                return false;
            }
        }

        // Check per-slot cap
        if (!empty($config['per_slot_cap']) && $config['per_slot_cap'] > 0) {
            $slot_count = $this->get_slot_appointment_count($instance['id'], $date, $time_slot);
            if ($slot_count >= $config['per_slot_cap']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a date is a blackout date
     */
    public function is_blackout_date(array $config, string $date): bool {
        $blackout_dates = $config['blackout_dates'] ?? [];

        if (empty($blackout_dates)) {
            return false;
        }

        // Handle both array and JSON string
        if (is_string($blackout_dates)) {
            $blackout_dates = json_decode($blackout_dates, true) ?? [];
        }

        foreach ($blackout_dates as $blackout) {
            // Single date
            if (is_string($blackout) && $blackout === $date) {
                return true;
            }

            // Date range
            if (is_array($blackout)) {
                $start = $blackout['start'] ?? '';
                $end = $blackout['end'] ?? '';

                if ($start && $end) {
                    if ($date >= $start && $date <= $end) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get count of appointments for a day
     */
    public function get_daily_appointment_count(int $instance_id, string $date): int {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE instance_id = %d
             AND status IN ('completed', 'in_progress')
             AND JSON_EXTRACT(form_data, '$.schedule_date') = %s",
            $instance_id,
            $date
        ));
    }

    /**
     * Get count of appointments for a specific time slot
     */
    public function get_slot_appointment_count(int $instance_id, string $date, string $time_slot): int {
        global $wpdb;
        $table = $wpdb->prefix . ISF_TABLE_SUBMISSIONS;

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE instance_id = %d
             AND status IN ('completed', 'in_progress')
             AND JSON_EXTRACT(form_data, '$.schedule_date') = %s
             AND JSON_EXTRACT(form_data, '$.schedule_time') = %s",
            $instance_id,
            $date,
            $time_slot
        ));
    }

    /**
     * Get remaining capacity for a day
     */
    public function get_daily_remaining(array $instance, string $date): ?int {
        if (!FeatureManager::is_enabled($instance, 'capacity_management')) {
            return null;
        }

        $config = FeatureManager::get_feature($instance, 'capacity_management');

        if (empty($config['daily_cap']) || $config['daily_cap'] <= 0) {
            return null;
        }

        $current = $this->get_daily_appointment_count($instance['id'], $date);
        return max(0, $config['daily_cap'] - $current);
    }

    /**
     * Get remaining capacity for a time slot
     */
    public function get_slot_remaining(array $instance, string $date, string $time_slot): ?int {
        if (!FeatureManager::is_enabled($instance, 'capacity_management')) {
            return null;
        }

        $config = FeatureManager::get_feature($instance, 'capacity_management');

        if (empty($config['per_slot_cap']) || $config['per_slot_cap'] <= 0) {
            return null;
        }

        $current = $this->get_slot_appointment_count($instance['id'], $date, $time_slot);
        return max(0, $config['per_slot_cap'] - $current);
    }

    /**
     * Filter available slots based on capacity
     */
    public function filter_available_slots(array $instance, array $slots): array {
        if (!FeatureManager::is_enabled($instance, 'capacity_management')) {
            return $slots;
        }

        $config = FeatureManager::get_feature($instance, 'capacity_management');
        $filtered = [];

        foreach ($slots as $slot) {
            $date = $slot['date'] ?? '';

            // Skip blackout dates
            if ($this->is_blackout_date($config, $date)) {
                continue;
            }

            // Check daily cap
            if (!empty($config['daily_cap']) && $config['daily_cap'] > 0) {
                $daily_count = $this->get_daily_appointment_count($instance['id'], $date);
                if ($daily_count >= $config['daily_cap']) {
                    continue;
                }
            }

            // Filter time slots within the day
            if (!empty($slot['slots']) && !empty($config['per_slot_cap']) && $config['per_slot_cap'] > 0) {
                $available_time_slots = [];

                foreach ($slot['slots'] as $time_slot) {
                    $time_code = $time_slot['code'] ?? $time_slot;
                    $slot_count = $this->get_slot_appointment_count($instance['id'], $date, $time_code);

                    if ($slot_count < $config['per_slot_cap']) {
                        $available_time_slots[] = $time_slot;
                    }
                }

                if (empty($available_time_slots)) {
                    continue;
                }

                $slot['slots'] = $available_time_slots;
            }

            $filtered[] = $slot;
        }

        return $filtered;
    }

    /**
     * Add to waitlist
     */
    public function add_to_waitlist(array $instance, array $form_data, string $date, string $time_slot = ''): bool {
        $config = FeatureManager::get_feature($instance, 'capacity_management');

        if (empty($config['enable_waitlist'])) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'isf_waitlist';

        // Check if table exists, create if not
        $this->ensure_waitlist_table();

        $result = $wpdb->insert($table, [
            'instance_id' => $instance['id'],
            'email' => $form_data['email'] ?? '',
            'phone' => $form_data['phone'] ?? '',
            'first_name' => $form_data['first_name'] ?? '',
            'last_name' => $form_data['last_name'] ?? '',
            'preferred_date' => $date,
            'preferred_time' => $time_slot,
            'form_data' => json_encode($form_data),
            'status' => 'waiting',
            'created_at' => current_time('mysql'),
        ]);

        if ($result) {
            $this->db->log('info', 'Added to waitlist', [
                'date' => $date,
                'time' => $time_slot,
            ], $instance['id']);

            return true;
        }

        return false;
    }

    /**
     * Get waitlist entries for a date
     */
    public function get_waitlist(int $instance_id, ?string $date = null): array {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_waitlist';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        $query = "SELECT * FROM {$table} WHERE instance_id = %d AND status = 'waiting'";
        $params = [$instance_id];

        if ($date) {
            $query .= " AND preferred_date = %s";
            $params[] = $date;
        }

        $query .= " ORDER BY created_at ASC";

        return $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A) ?: [];
    }

    /**
     * Notify waitlist when slot becomes available
     */
    public function notify_waitlist_availability(array $instance, string $date, string $time_slot): void {
        $config = FeatureManager::get_feature($instance, 'capacity_management');

        if (empty($config['waitlist_notification'])) {
            return;
        }

        $waitlist = $this->get_waitlist($instance['id'], $date);

        foreach ($waitlist as $entry) {
            // Check if slot matches preference (or no specific preference)
            if (!empty($entry['preferred_time']) && $entry['preferred_time'] !== $time_slot) {
                continue;
            }

            $this->send_waitlist_notification($instance, $entry, $date, $time_slot);

            // Only notify first person in line
            break;
        }
    }

    /**
     * Send waitlist availability notification
     */
    private function send_waitlist_notification(array $instance, array $entry, string $date, string $time_slot): void {
        $email = $entry['email'] ?? '';

        if (empty($email)) {
            return;
        }

        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'Energy Wise Rewards';

        $date_display = date('l, F j, Y', strtotime($date));
        $time_display = $this->get_time_display($time_slot);

        $subject = "Appointment Now Available - {$program_name}";

        $message = "Good news! An appointment slot has become available.\n\n";
        $message .= "Date: {$date_display}\n";
        $message .= "Time: {$time_display}\n\n";
        $message .= "To book this appointment, please visit our website as soon as possible. This slot is available on a first-come, first-served basis.";

        wp_mail($email, $subject, $message);

        // Mark as notified
        global $wpdb;
        $table = $wpdb->prefix . 'isf_waitlist';
        $wpdb->update($table, [
            'status' => 'notified',
            'notified_at' => current_time('mysql'),
        ], ['id' => $entry['id']]);

        $this->db->log('info', 'Waitlist notification sent', [
            'email' => $email,
            'date' => $date,
        ], $instance['id']);
    }

    /**
     * Get time display string
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
     * Ensure waitlist table exists
     */
    private function ensure_waitlist_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_waitlist';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NOT NULL,
            email VARCHAR(255),
            phone VARCHAR(50),
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            preferred_date DATE,
            preferred_time VARCHAR(10),
            form_data JSON,
            status ENUM('waiting', 'notified', 'booked', 'expired') DEFAULT 'waiting',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            notified_at TIMESTAMP NULL,
            INDEX idx_instance_date (instance_id, preferred_date),
            INDEX idx_status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get capacity overview for admin
     */
    public function get_capacity_overview(array $instance, string $start_date, string $end_date): array {
        $overview = [];
        $current = strtotime($start_date);
        $end = strtotime($end_date);
        $config = FeatureManager::get_feature($instance, 'capacity_management');

        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $is_blackout = $this->is_blackout_date($config, $date);
            $daily_count = $this->get_daily_appointment_count($instance['id'], $date);
            $daily_cap = $config['daily_cap'] ?? 0;

            $overview[$date] = [
                'date' => $date,
                'is_blackout' => $is_blackout,
                'appointments' => $daily_count,
                'capacity' => $daily_cap,
                'remaining' => $daily_cap > 0 ? max(0, $daily_cap - $daily_count) : null,
                'is_full' => $daily_cap > 0 && $daily_count >= $daily_cap,
            ];

            $current = strtotime('+1 day', $current);
        }

        return $overview;
    }

    /**
     * Add a blackout date
     */
    public function add_blackout_date(array $instance, string $date, ?string $end_date = null, string $reason = ''): bool {
        $settings = $instance['settings'] ?? [];
        $features = $settings['features'] ?? [];
        $config = $features['capacity_management'] ?? [];
        $blackouts = $config['blackout_dates'] ?? [];

        if (is_string($blackouts)) {
            $blackouts = json_decode($blackouts, true) ?? [];
        }

        if ($end_date && $end_date !== $date) {
            $blackouts[] = [
                'start' => $date,
                'end' => $end_date,
                'reason' => $reason,
            ];
        } else {
            $blackouts[] = [
                'date' => $date,
                'reason' => $reason,
            ];
        }

        $config['blackout_dates'] = $blackouts;
        $features['capacity_management'] = $config;
        $settings['features'] = $features;

        return $this->db->update_instance($instance['id'], ['settings' => $settings]);
    }

    /**
     * Remove a blackout date
     */
    public function remove_blackout_date(array $instance, string $date): bool {
        $settings = $instance['settings'] ?? [];
        $features = $settings['features'] ?? [];
        $config = $features['capacity_management'] ?? [];
        $blackouts = $config['blackout_dates'] ?? [];

        if (is_string($blackouts)) {
            $blackouts = json_decode($blackouts, true) ?? [];
        }

        $blackouts = array_filter($blackouts, function($blackout) use ($date) {
            if (is_string($blackout)) {
                return $blackout !== $date;
            }
            if (!empty($blackout['date'])) {
                return $blackout['date'] !== $date;
            }
            // Keep ranges that don't contain this date
            if (!empty($blackout['start']) && !empty($blackout['end'])) {
                return !($date >= $blackout['start'] && $date <= $blackout['end']);
            }
            return true;
        });

        $config['blackout_dates'] = array_values($blackouts);
        $features['capacity_management'] = $config;
        $settings['features'] = $features;

        return $this->db->update_instance($instance['id'], ['settings' => $settings]);
    }
}
