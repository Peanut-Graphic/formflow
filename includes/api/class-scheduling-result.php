<?php
namespace ISF\Api;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Scheduling Result
 *
 * Parses and provides access to scheduling API response data.
 * Based on the legacy SchedulingResult class from schedulingXml.php
 */


class SchedulingResult {

    private array $root_node = [];
    private string $error_message = '';

    // Equipment arrays by type
    private array $thermostats_ac = [];      // Type 05, 10 (Split AC, Package AC)
    private array $thermostats_heat = [];    // Type 20 (Heat Pump)
    private array $thermostats_ac_heat = []; // Type 15 (AC Heat Pump)

    // Equipment counts and settings
    private int $thermostats_ac_count = 0;
    private int $thermostats_heat_count = 0;
    private int $thermostats_ac_heat_count = 0;

    private string $thermostats_ac_location = '05';
    private string $thermostats_heat_location = '05';
    private string $thermostats_ac_heat_location = '05';

    private string $thermostats_ac_desired_device = '05';
    private string $thermostats_heat_desired_device = '05';
    private string $thermostats_ac_heat_desired_device = '05';

    // Available time slots
    private array $slots = [];

    /**
     * Constructor
     *
     * @param array $response Parsed XML response array
     */
    public function __construct(array $response) {
        if (isset($response['message'])) {
            $this->root_node = $response['message'];
            $this->parse_equipment();
            $this->parse_slots();
        } else {
            $this->error_message = 'Unexpected response format';
        }
    }

    // =========================================================================
    // Basic Response Data
    // =========================================================================

    /**
     * Get the message type
     */
    public function get_message_type(): string {
        return XmlParser::node_value($this->root_node['messagetype'] ?? null, '');
    }

    /**
     * Get scheduled status (Y/N)
     */
    public function get_scheduled(): string {
        return XmlParser::node_value($this->root_node['scheduled'] ?? null, '');
    }

    /**
     * Check if already scheduled
     */
    public function is_scheduled(): bool {
        return strtoupper($this->get_scheduled()) === 'Y';
    }

    /**
     * Get the FSR number
     */
    public function get_fsr_no(): string {
        return XmlParser::node_value($this->root_node['fsrno'] ?? null, '');
    }

    /**
     * Get the Comverge number
     */
    public function get_comverge_no(): string {
        return XmlParser::node_value($this->root_node['comvergeno'] ?? null, '');
    }

    /**
     * Get existing schedule date (if already scheduled)
     */
    public function get_schedule_date(): string {
        return XmlParser::node_value($this->root_node['scheduledate'] ?? null, '');
    }

    /**
     * Get existing schedule time (if already scheduled)
     */
    public function get_schedule_time(): string {
        return XmlParser::node_value($this->root_node['scheduletime'] ?? null, '');
    }

    /**
     * Get must schedule flag
     */
    public function get_must_schedule(): string {
        return XmlParser::node_value($this->root_node['mustSchedule'] ?? null, '');
    }

    // =========================================================================
    // Customer Information
    // =========================================================================

    /**
     * Get customer email
     */
    public function get_email(): string {
        return XmlParser::node_value($this->root_node['email'] ?? null, '');
    }

    /**
     * Get customer first name
     */
    public function get_first_name(): string {
        return XmlParser::node_value($this->root_node['fname'] ?? null, '');
    }

    /**
     * Get customer last name
     */
    public function get_last_name(): string {
        return XmlParser::node_value($this->root_node['lname'] ?? null, '');
    }

    /**
     * Get service address
     */
    public function get_address(): array {
        $address = $this->root_node['address'] ?? [];
        return [
            'street' => XmlParser::node_value($address['street'] ?? null, ''),
            'city' => XmlParser::node_value($address['city'] ?? null, ''),
            'state' => XmlParser::node_value($address['state'] ?? null, ''),
            'zip' => XmlParser::node_value($address['zip'] ?? null, '')
        ];
    }

    /**
     * Get formatted address HTML
     */
    public function get_formatted_address(): string {
        $addr = $this->get_address();
        if (empty($addr['street'])) {
            return '';
        }

        return sprintf(
            '%s<br>%s, %s %s',
            esc_html($addr['street']),
            esc_html($addr['city']),
            esc_html($addr['state']),
            esc_html($addr['zip'])
        );
    }

    // =========================================================================
    // Equipment Parsing
    // =========================================================================

    /**
     * Check if equipment data exists
     */
    public function has_equipment(): bool {
        return isset($this->root_node['equipments']['equipment']);
    }

    /**
     * Parse equipment from response
     */
    private function parse_equipment(): void {
        if (!$this->has_equipment()) {
            return;
        }

        $equipments = $this->root_node['equipments']['equipment'];

        // Handle single equipment vs array of equipment
        if (isset($equipments['attr'])) {
            // Single equipment
            $equipments = [$equipments];
        }

        foreach ($equipments as $equipment) {
            $type = $equipment['attr']['type'] ?? '';
            $location = $equipment['attr']['location'] ?? '05';
            $desired_device = $equipment['attr']['desiredDevice'] ?? $equipment['attr']['desireddevice'] ?? '05';

            switch ($type) {
                case '05': // Split AC
                case '10': // Package AC
                    $this->thermostats_ac[] = $equipment;
                    $this->thermostats_ac_count++;
                    $this->thermostats_ac_location = $location;
                    $this->thermostats_ac_desired_device = $desired_device;
                    break;

                case '20': // Heat Pump
                    $this->thermostats_heat[] = $equipment;
                    $this->thermostats_heat_count++;
                    $this->thermostats_heat_location = $location;
                    $this->thermostats_heat_desired_device = $desired_device;
                    break;

                case '15': // AC Heat Pump (combo)
                    $this->thermostats_ac_heat[] = $equipment;
                    $this->thermostats_ac_heat_count++;
                    $this->thermostats_ac_heat_location = $location;
                    $this->thermostats_ac_heat_desired_device = $desired_device;
                    break;
            }
        }
    }

    // =========================================================================
    // Equipment Getters - AC Only
    // =========================================================================

    public function get_thermostats_ac(): array {
        return $this->thermostats_ac;
    }

    public function get_thermostats_ac_count(): int {
        return $this->thermostats_ac_count;
    }

    public function get_thermostats_ac_location(): string {
        return $this->thermostats_ac_location;
    }

    public function get_thermostats_ac_desired_device(): string {
        return $this->thermostats_ac_desired_device;
    }

    public function are_thermostats_ac_dcu(): bool {
        return $this->is_device_dcu($this->thermostats_ac);
    }

    // =========================================================================
    // Equipment Getters - Heat Only
    // =========================================================================

    public function get_thermostats_heat(): array {
        return $this->thermostats_heat;
    }

    public function get_thermostats_heat_count(): int {
        return $this->thermostats_heat_count;
    }

    public function get_thermostats_heat_location(): string {
        return $this->thermostats_heat_location;
    }

    public function get_thermostats_heat_desired_device(): string {
        return $this->thermostats_heat_desired_device;
    }

    public function are_thermostats_heat_dcu(): bool {
        return $this->is_device_dcu($this->thermostats_heat);
    }

    // =========================================================================
    // Equipment Getters - AC/Heat Combo
    // =========================================================================

    public function get_thermostats_ac_heat(): array {
        return $this->thermostats_ac_heat;
    }

    public function get_thermostats_ac_heat_count(): int {
        return $this->thermostats_ac_heat_count;
    }

    public function get_thermostats_ac_heat_location(): string {
        return $this->thermostats_ac_heat_location;
    }

    public function get_thermostats_ac_heat_desired_device(): string {
        return $this->thermostats_ac_heat_desired_device;
    }

    public function are_thermostats_ac_heat_dcu(): bool {
        return $this->is_device_dcu($this->thermostats_ac_heat);
    }

    /**
     * Get total equipment count
     */
    public function get_total_equipment_count(): int {
        if ($this->thermostats_ac_heat_count > 0) {
            return $this->thermostats_ac_heat_count;
        }
        return $this->thermostats_ac_count + $this->thermostats_heat_count;
    }

    /**
     * Check if any equipment is DCU (outdoor switch)
     */
    private function is_device_dcu(array $devices): bool {
        foreach ($devices as $device) {
            $desired = $device['attr']['desiredDevice'] ?? $device['attr']['desireddevice'] ?? '';
            if ($desired === '15' || $desired === '02') {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Time Slots
    // =========================================================================

    /**
     * Parse available time slots
     */
    private function parse_slots(): void {
        // Check for no slots
        if (isset($this->root_node['openslots']['noslots'])) {
            return;
        }

        if (!isset($this->root_node['openslots']['slot'])) {
            return;
        }

        $slots_data = $this->root_node['openslots']['slot'];

        // Handle single slot vs array
        if (isset($slots_data['attr'])) {
            $slots_data = [$slots_data];
        }

        foreach ($slots_data as $slot) {
            $date = $slot['attr']['date'] ?? '';
            if (empty($date)) {
                continue;
            }

            // Parse time slots for this date
            $times = $this->parse_time_slots($slot['time'] ?? []);

            $this->slots[] = [
                'date' => $date,
                'times' => $times
            ];
        }
    }

    /**
     * Parse time slot availability for a date
     */
    private function parse_time_slots(array $time_data): array {
        $times = [
            'am' => ['available' => false, 'capacity' => 0],
            'md' => ['available' => false, 'capacity' => 0],
            'pm' => ['available' => false, 'capacity' => 0],
            'ev' => ['available' => false, 'capacity' => 0]
        ];

        // Handle single time vs array
        if (isset($time_data['attr'])) {
            $time_data = [$time_data];
        }

        foreach ($time_data as $time) {
            $id = strtolower($time['attr']['id'] ?? '');
            $value = (int)($time['attr']['value'] ?? 0);

            // Normalize time IDs
            $id_map = [
                'am' => 'am',
                'mid-day' => 'md',
                'midday' => 'md',
                'md' => 'md',
                'pm' => 'pm',
                'evening' => 'ev',
                'ev' => 'ev'
            ];

            $normalized_id = $id_map[$id] ?? null;
            if ($normalized_id) {
                $times[$normalized_id] = [
                    'available' => $value > 0,
                    'capacity' => $value
                ];
            }
        }

        return $times;
    }

    /**
     * Get all available slots
     */
    public function get_slots(): array {
        return $this->slots;
    }

    /**
     * Check if any slots are available
     */
    public function has_slots(): bool {
        return !empty($this->slots);
    }

    /**
     * Get slots formatted for frontend display
     *
     * @param int $required_capacity Required capacity for the appointment
     */
    public function get_slots_for_display(int $required_capacity = 1): array {
        $display_slots = [];

        foreach ($this->slots as $slot) {
            $date = $slot['date'];

            // Parse date and skip weekends if needed
            $timestamp = strtotime($date);
            $day_of_week = (int)date('N', $timestamp);

            // Skip Sundays (7)
            if ($day_of_week === 7) {
                continue;
            }

            $display_slot = [
                'date' => $date,
                'formatted_date' => date('l, F j', $timestamp),
                'timestamp' => $timestamp,
                'times' => []
            ];

            foreach ($slot['times'] as $time_id => $time_data) {
                $has_capacity = $time_data['capacity'] >= $required_capacity;
                $display_slot['times'][$time_id] = [
                    'available' => $time_data['available'] && $has_capacity,
                    'capacity' => $time_data['capacity'],
                    'label' => $this->get_time_label($time_id)
                ];
            }

            // Only add if at least one time slot is available
            $any_available = array_reduce(
                $display_slot['times'],
                fn($carry, $t) => $carry || $t['available'],
                false
            );

            if ($any_available) {
                $display_slots[] = $display_slot;
            }
        }

        return $display_slots;
    }

    /**
     * Get human-readable time slot label
     */
    private function get_time_label(string $time_id): string {
        $labels = [
            'am' => '8:00 AM - 11:00 AM',
            'md' => '11:00 AM - 2:00 PM',
            'pm' => '2:00 PM - 5:00 PM',
            'ev' => '5:00 PM - 8:00 PM'
        ];

        return $labels[$time_id] ?? strtoupper($time_id);
    }

    /**
     * Get existing appointment details if already scheduled
     *
     * @return array|null Appointment details or null if not scheduled
     */
    public function get_existing_appointment(): ?array {
        if (!$this->is_scheduled()) {
            return null;
        }

        $first_name = $this->get_first_name();
        $last_name = $this->get_last_name();
        $address = $this->get_address();

        // Format the time slot into readable format
        $schedule_time = $this->get_schedule_time();
        $formatted_time = $this->format_schedule_time($schedule_time);

        // Check if any equipment is DCU
        $is_dcu = $this->are_thermostats_ac_dcu() ||
                  $this->are_thermostats_heat_dcu() ||
                  $this->are_thermostats_ac_heat_dcu();

        return [
            'customer_name' => trim(ucwords(strtolower($first_name)) . ' ' . ucwords(strtolower($last_name))),
            'address' => !empty($address['street'])
                ? $address['street'] . ', ' . $address['city'] . ', ' . $address['state'] . ' ' . $address['zip']
                : '',
            'scheduled_date' => $this->get_schedule_date(),
            'scheduled_time' => $formatted_time,
            'equipment_count' => $this->get_total_equipment_count(),
            'is_dcu' => $is_dcu,
            'fsr_no' => $this->get_fsr_no(),
        ];
    }

    /**
     * Format schedule time into human-readable format
     */
    private function format_schedule_time(string $time): string {
        if (empty($time)) {
            return '';
        }

        // Parse time like "07:00 AM", "2:00 PM", etc.
        $parts = explode(':', $time);
        $hour_parts = explode(' ', $time);

        $hour = (int)$parts[0];
        $ampm = strtoupper(trim(end($hour_parts)));

        // Determine time slot based on hour
        if ($ampm === 'AM' && $hour >= 7 && $hour < 11) {
            return '8:00 AM - 11:00 AM';
        } elseif (($ampm === 'AM' && $hour >= 11) || ($ampm === 'PM' && $hour === 12) || ($ampm === 'PM' && $hour === 1)) {
            return '11:00 AM - 2:00 PM';
        } elseif ($ampm === 'PM' && $hour >= 2 && $hour < 5) {
            return '2:00 PM - 5:00 PM';
        } else {
            return '5:00 PM - 8:00 PM';
        }
    }

    /**
     * Get all response data as array
     */
    public function to_array(): array {
        return [
            'message_type' => $this->get_message_type(),
            'is_scheduled' => $this->is_scheduled(),
            'scheduled' => $this->get_scheduled(),
            'schedule_date' => $this->get_schedule_date(),
            'schedule_time' => $this->get_schedule_time(),
            'fsr_no' => $this->get_fsr_no(),
            'comverge_no' => $this->get_comverge_no(),
            'must_schedule' => $this->get_must_schedule(),
            'email' => $this->get_email(),
            'first_name' => $this->get_first_name(),
            'last_name' => $this->get_last_name(),
            'address' => $this->get_address(),
            'equipment' => [
                'ac' => [
                    'count' => $this->thermostats_ac_count,
                    'location' => $this->thermostats_ac_location,
                    'desired_device' => $this->thermostats_ac_desired_device,
                    'is_dcu' => $this->are_thermostats_ac_dcu()
                ],
                'heat' => [
                    'count' => $this->thermostats_heat_count,
                    'location' => $this->thermostats_heat_location,
                    'desired_device' => $this->thermostats_heat_desired_device,
                    'is_dcu' => $this->are_thermostats_heat_dcu()
                ],
                'ac_heat' => [
                    'count' => $this->thermostats_ac_heat_count,
                    'location' => $this->thermostats_ac_heat_location,
                    'desired_device' => $this->thermostats_ac_heat_desired_device,
                    'is_dcu' => $this->are_thermostats_ac_heat_dcu()
                ],
                'total' => $this->get_total_equipment_count()
            ],
            'slots' => $this->get_slots(),
            'has_slots' => $this->has_slots()
        ];
    }
}
