<?php
namespace ISF\Api;

if ( ! defined( 'ABSPATH' ) ) { exit; }


/**
 * Mock API Client
 *
 * Provides mock responses for testing forms without connecting to the real API.
 * Use this by enabling "Demo Mode" on a form instance.
 */


use ISF\Database\Database;

class MockApiClient {

    private ?int $instance_id;
    private Database $db;

    /**
     * Demo/test accounts that will be accepted
     */
    private array $demo_accounts = [
        // Format: 'account_number' => ['zip' => 'xxxxx', 'data' => [...]]
        '1234567890' => [
            'zip' => '20001',
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john.smith@example.com',
            'street' => '123 Main Street',
            'city' => 'Washington',
            'state' => 'DC',
            'ca_no' => 'X123456789'
        ],
        '9876543210' => [
            'zip' => '21201',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.com',
            'street' => '456 Oak Avenue',
            'city' => 'Baltimore',
            'state' => 'MD',
            'ca_no' => 'X987654321'
        ],
        '5555555555' => [
            'zip' => '19801',
            'first_name' => 'Bob',
            'last_name' => 'Johnson',
            'email' => 'bob.johnson@example.com',
            'street' => '789 Elm Street',
            'city' => 'Wilmington',
            'state' => 'DE',
            'ca_no' => 'X555555555'
        ],
        // Wildcard - any account starting with "TEST" works with ZIP 12345
        'TEST*' => [
            'zip' => '12345',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'street' => '100 Test Lane',
            'city' => 'Testville',
            'state' => 'MD',
            'ca_no' => 'XTEST00001'
        ]
    ];

    /**
     * Constructor
     *
     * @param int|null $instance_id The form instance ID for logging
     */
    public function __construct(?int $instance_id = null) {
        $this->instance_id = $instance_id;
        $this->db = new Database();
    }

    /**
     * Validate an account number and ZIP code (mock)
     *
     * @param string $utility_no The account/utility number
     * @param string $zip The ZIP code
     * @return ValidationResult The validation result
     */
    public function validate_account(string $utility_no, string $zip): ValidationResult {
        $this->log_mock_call('validate_account', ['utility_no' => $utility_no, 'zip' => $zip]);

        // Check for exact match
        if (isset($this->demo_accounts[$utility_no])) {
            $account = $this->demo_accounts[$utility_no];
            if ($account['zip'] === $zip || $zip === '12345') {
                return new ValidationResult($this->build_valid_response($account, $utility_no));
            }
        }

        // Check for TEST* wildcard
        if (strtoupper(substr($utility_no, 0, 4)) === 'TEST') {
            $account = $this->demo_accounts['TEST*'];
            if ($zip === '12345' || $zip === $account['zip']) {
                $account['ca_no'] = 'X' . strtoupper($utility_no);
                return new ValidationResult($this->build_valid_response($account, $utility_no));
            }
        }

        // Any account number with ZIP 00000 will work (for easy testing)
        if ($zip === '00000') {
            return new ValidationResult($this->build_valid_response([
                'first_name' => 'Demo',
                'last_name' => 'Customer',
                'email' => 'demo@example.com',
                'street' => '999 Demo Boulevard',
                'city' => 'Demo City',
                'state' => 'MD',
                'ca_no' => 'X' . $utility_no
            ], $utility_no));
        }

        // Return error for invalid accounts
        return new ValidationResult($this->build_error_response(
            'Account not found. For demo, use account 1234567890 with ZIP 20001, or any account with ZIP 00000.'
        ));
    }

    /**
     * Get available scheduling slots (mock)
     *
     * @param string $account_number The account number
     * @param string $start_date Start date
     * @param array $equipment Equipment configuration
     * @return SchedulingResult The scheduling result
     */
    public function get_schedule_slots(
        string $account_number,
        string $start_date,
        array $equipment = []
    ): SchedulingResult {
        $this->log_mock_call('get_schedule_slots', [
            'account' => $account_number,
            'start_date' => $start_date
        ]);

        // Generate mock schedule data for the next 14 days
        $slots = $this->generate_mock_schedule_xml_format($start_date);
        $fsr_no = 'MOCK-FSR-' . rand(100000, 999999);

        // Build equipment in XML format
        $equipment_list = [];
        $ac_count = $equipment['05']['count'] ?? 1;
        $heat_count = $equipment['20']['count'] ?? 0;
        $ac_heat_count = $equipment['15']['count'] ?? 0;

        // Add AC equipment (type 05)
        for ($i = 0; $i < $ac_count; $i++) {
            $equipment_list[] = [
                'attr' => [
                    'type' => '05',
                    'location' => '05',
                    'desiredDevice' => '05'
                ]
            ];
        }

        // Add Heat equipment (type 20)
        for ($i = 0; $i < $heat_count; $i++) {
            $equipment_list[] = [
                'attr' => [
                    'type' => '20',
                    'location' => '05',
                    'desiredDevice' => '05'
                ]
            ];
        }

        // Add AC/Heat equipment (type 15)
        for ($i = 0; $i < $ac_heat_count; $i++) {
            $equipment_list[] = [
                'attr' => [
                    'type' => '15',
                    'location' => '05',
                    'desiredDevice' => '05'
                ]
            ];
        }

        // Default to 1 AC unit if no equipment specified
        if (empty($equipment_list)) {
            $equipment_list[] = [
                'attr' => [
                    'type' => '05',
                    'location' => '05',
                    'desiredDevice' => '05'
                ]
            ];
        }

        return new SchedulingResult([
            'message' => [
                'messagetype' => ['value' => 'fsr'],
                'fsrno' => ['value' => $fsr_no],
                'comvergeno' => ['value' => ltrim($account_number, 'X')],
                'scheduled' => ['value' => 'N'],
                'mustSchedule' => ['value' => 'Y'],
                'equipments' => [
                    'equipment' => count($equipment_list) === 1 ? $equipment_list[0] : $equipment_list
                ],
                'openslots' => [
                    'slot' => $slots
                ]
            ]
        ]);
    }

    /**
     * Book an appointment slot (mock)
     *
     * @param string $fsr The FSR number
     * @param string $ca_no The Comverge account number
     * @param string $schedule_date The selected date
     * @param string $time The selected time slot
     * @param array $equipment Equipment configuration
     * @param string|null $user_id Optional user ID
     * @return array The booking response
     */
    public function book_appointment(
        string $fsr,
        string $ca_no,
        string $schedule_date,
        string $time,
        array $equipment,
        ?string $user_id = null
    ): array {
        $this->log_mock_call('book_appointment', [
            'fsr' => $fsr,
            'ca_no' => $ca_no,
            'date' => $schedule_date,
            'time' => $time
        ]);

        // Simulate occasional booking failures (10% chance)
        if (rand(1, 10) === 1) {
            return ['code' => '-1', 'message' => 'Slot no longer available'];
        }

        // Success
        return ['code' => '0', 'message' => 'Appointment booked successfully'];
    }

    /**
     * Submit enrollment (mock)
     *
     * @param array $data Enrollment data
     * @return array The API response
     */
    public function enroll(array $data): array {
        $this->log_mock_call('enroll', ['customer' => $data['first_name'] ?? 'Unknown']);

        return [
            'message' => [
                'messagetype' => 'enrollment',
                'status' => 'success',
                'confirmation' => 'DEMO-' . strtoupper(substr(md5(time()), 0, 8))
            ]
        ];
    }

    /**
     * Build a valid validation response
     * Format must match the XML-parsed structure that ValidationResult expects
     */
    private function build_valid_response(array $account, string $utility_no): array {
        $ca_no = $account['ca_no'] ?? 'X' . $utility_no;

        return [
            'message' => [
                'messagetype' => ['value' => 'prospect'],
                'caNo' => ['value' => $ca_no],
                'comvergeno' => ['value' => $ca_no],
                'utility_no' => ['value' => $utility_no],
                'fname' => ['value' => $account['first_name']],
                'lname' => ['value' => $account['last_name']],
                'email' => ['value' => $account['email'] ?? ''],
                'address' => [
                    'street' => ['value' => $account['street']],
                    'city' => ['value' => $account['city']],
                    'state' => ['value' => $account['state']],
                    'zip' => ['value' => $account['zip'] ?? '']
                ],
                'enrolled' => ['value' => 'N'],
                'status' => ['value' => 'valid']
            ]
        ];
    }

    /**
     * Build an error response
     * Format must match the XML-parsed structure that ValidationResult expects
     */
    private function build_error_response(string $message): array {
        return [
            'message' => [
                'messagetype' => ['value' => 'error'],
                'status' => ['value' => 'invalid'],
                'error-detail' => [
                    'error' => [
                        'value' => $message,
                        'attr' => ['code' => '001']
                    ]
                ]
            ]
        ];
    }

    /**
     * Generate mock schedule data in XML-parsed format
     */
    private function generate_mock_schedule_xml_format(string $start_date): array {
        $slots = [];
        $start = strtotime($start_date);

        // Generate slots for 14 days
        for ($i = 0; $i < 14; $i++) {
            $date = date('m/d/Y', strtotime("+{$i} days", $start));
            $day_of_week = date('w', strtotime("+{$i} days", $start));

            // Skip weekends
            if ($day_of_week == 0 || $day_of_week == 6) {
                continue;
            }

            // Build time slots with random availability
            $time_slots = [];
            $time_codes = ['AM', 'Mid-Day', 'PM', 'Evening'];

            foreach ($time_codes as $code) {
                // 75% chance each slot is available with 1-5 capacity
                $capacity = rand(1, 4) !== 1 ? rand(1, 5) : 0;
                $time_slots[] = [
                    'attr' => [
                        'id' => $code,
                        'value' => (string)$capacity
                    ]
                ];
            }

            $slots[] = [
                'attr' => ['date' => $date],
                'time' => $time_slots
            ];
        }

        return $slots;
    }

    /**
     * Get promotional codes (mock)
     *
     * @return array List of promo codes
     */
    public function get_promo_codes(): array {
        $this->log_mock_call('get_promo_codes', []);

        // Return mock promo codes that represent typical "How did you hear about us?" options
        return [
            'Bill Insert',
            'Direct Mail',
            'Email',
            'Friend/Family',
            'Online Ad',
            'Radio',
            'Social Media',
            'TV Commercial',
            'Utility Website',
            'Word of Mouth'
        ];
    }

    /**
     * Test the API connection (mock)
     *
     * @return bool Always returns true for mock
     */
    public function test_connection(): bool {
        return true;
    }

    /**
     * Log mock API call
     */
    private function log_mock_call(string $method, array $params): void {
        $this->db->log('api_call', "MOCK: {$method}", [
            'direction' => 'mock',
            'params' => $params,
            'demo_mode' => true
        ], $this->instance_id);
    }

    /**
     * Get the list of demo accounts for display
     */
    public static function get_demo_accounts_info(): array {
        return [
            [
                'account' => '1234567890',
                'zip' => '20001',
                'description' => 'DC customer'
            ],
            [
                'account' => '9876543210',
                'zip' => '21201',
                'description' => 'MD customer'
            ],
            [
                'account' => '5555555555',
                'zip' => '19801',
                'description' => 'DE customer'
            ],
            [
                'account' => 'Any account',
                'zip' => '00000',
                'description' => 'Works with any #'
            ]
        ];
    }
}
