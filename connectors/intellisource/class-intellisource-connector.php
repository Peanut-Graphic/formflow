<?php
/**
 * IntelliSource API Connector
 *
 * Connector for the PowerPortal IntelliSOURCE API used by utility programs
 * like Delmarva Power and Pepco Energy Wise Rewards.
 *
 * @package FormFlow
 * @subpackage Connectors
 * @since 2.0.0
 */

namespace ISF\Connectors\IntelliSource;

use ISF\Api\ApiConnectorInterface;
use ISF\Api\AccountValidationResult;
use ISF\Api\EnrollmentResult;
use ISF\Api\SchedulingResult;
use ISF\Api\BookingResult;
use ISF\Database\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class IntelliSourceConnector
 *
 * Implements the API connector interface for IntelliSOURCE/PowerPortal systems.
 */
class IntelliSourceConnector implements ApiConnectorInterface {

    /**
     * Connector identifier
     */
    public const ID = 'intellisource';

    /**
     * Connector version
     */
    public const VERSION = '2.0.0';

    /**
     * Database instance
     */
    private ?Database $db = null;

    /**
     * Get connector identifier
     *
     * @return string
     */
    public function get_id(): string {
        return self::ID;
    }

    /**
     * Get connector display name
     *
     * @return string
     */
    public function get_name(): string {
        return __('IntelliSOURCE / PowerPortal', 'formflow');
    }

    /**
     * Get connector description
     *
     * @return string
     */
    public function get_description(): string {
        return __('API connector for utility demand response programs using the PowerPortal IntelliSOURCE platform.', 'formflow');
    }

    /**
     * Get connector version
     *
     * @return string
     */
    public function get_version(): string {
        return self::VERSION;
    }

    /**
     * Get required configuration fields
     *
     * @return array
     */
    public function get_config_fields(): array {
        return [
            'api_endpoint' => [
                'label' => __('API Endpoint', 'formflow'),
                'type' => 'url',
                'required' => true,
                'description' => __('Base URL for the IntelliSOURCE API (e.g., https://ph.powerportal.com/phiIntelliSOURCE/api)', 'formflow'),
                'default' => 'https://ph.powerportal.com/phiIntelliSOURCE/api',
            ],
            'api_password' => [
                'label' => __('API Password', 'formflow'),
                'type' => 'password',
                'required' => true,
                'description' => __('Authentication password for API calls', 'formflow'),
                'encrypted' => true,
            ],
            'test_mode' => [
                'label' => __('Test Mode', 'formflow'),
                'type' => 'checkbox',
                'required' => false,
                'description' => __('Enable test mode to use mock responses instead of live API', 'formflow'),
                'default' => false,
            ],
        ];
    }

    /**
     * Validate configuration
     *
     * @param array $config
     * @return array
     */
    public function validate_config(array $config): array {
        $errors = [];

        if (empty($config['api_endpoint'])) {
            $errors[] = __('API Endpoint is required', 'formflow');
        } elseif (!filter_var($config['api_endpoint'], FILTER_VALIDATE_URL)) {
            $errors[] = __('API Endpoint must be a valid URL', 'formflow');
        }

        if (empty($config['api_password'])) {
            $errors[] = __('API Password is required', 'formflow');
        }

        return $errors;
    }

    /**
     * Test API connection
     *
     * @param array $config
     * @return array
     */
    public function test_connection(array $config): array {
        try {
            $response = $this->make_request(
                $config['api_endpoint'],
                '/promo_codes',
                ['pswd' => $config['api_password']],
                'GET',
                false
            );

            return [
                'success' => true,
                'message' => __('Connection successful', 'formflow'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => sprintf(__('Connection failed: %s', 'formflow'), $e->getMessage()),
            ];
        }
    }

    /**
     * Validate an account
     *
     * @param array $data
     * @param array $config
     * @return AccountValidationResult
     */
    public function validate_account(array $data, array $config): AccountValidationResult {
        $params = [
            'utility_no' => $data['account_number'] ?? $data['utility_no'] ?? '',
            'zip' => $data['zip'] ?? '',
            'pswd' => $config['api_password'],
            'val' => 'submit',
        ];

        try {
            $response = $this->make_request(
                $config['api_endpoint'],
                '/prospects/validate.xml',
                $params,
                'GET',
                true
            );

            return $this->parse_validation_response($response);
        } catch (\Exception $e) {
            return new AccountValidationResult([
                'is_valid' => false,
                'error_code' => 'connection_error',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Submit enrollment
     *
     * @param array $form_data
     * @param array $config
     * @return EnrollmentResult
     */
    public function submit_enrollment(array $form_data, array $config): EnrollmentResult {
        // Map form fields to API format
        $api_params = $this->map_fields($form_data, 'enrollment');

        // Add authentication
        $api_params['pswd'] = $config['api_password'];
        $api_params['val'] = 'submit';

        try {
            $response = $this->make_request(
                $config['api_endpoint'],
                '/prospects/enroll.xml',
                $api_params,
                'GET',
                true
            );

            return $this->parse_enrollment_response($response);
        } catch (\Exception $e) {
            return new EnrollmentResult([
                'success' => false,
                'error_code' => 'connection_error',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get available schedule slots
     *
     * @param array $data
     * @param array $config
     * @return SchedulingResult
     */
    public function get_schedule_slots(array $data, array $config): SchedulingResult {
        $params = [
            'startDate' => $data['start_date'] ?? date('m/d/Y'),
            'pswd' => $config['api_password'],
            'val' => 'submit',
        ];

        // Handle account number format
        $account = $data['account_number'] ?? $data['utility_no'] ?? '';
        if (strtolower(substr($account, 0, 1)) === 'x') {
            $params['caNo'] = substr($account, 1);
        } else {
            $params['utility_no'] = $account;
        }

        // Add equipment counts
        if (!empty($data['equipment'])) {
            foreach ($data['equipment'] as $type => $eq_config) {
                if (isset($eq_config['count'])) {
                    $params["eqCount-{$type}"] = $eq_config['count'];
                }
                if (isset($eq_config['location'])) {
                    $params["eqLoc-{$type}"] = $eq_config['location'];
                }
            }
        }

        try {
            $response = $this->make_request(
                $config['api_endpoint'],
                '/field_service_requests/scheduling.xml',
                $params,
                'GET',
                true
            );

            return $this->parse_scheduling_response($response);
        } catch (\Exception $e) {
            return new SchedulingResult([
                'success' => false,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Book an appointment
     *
     * @param array $data
     * @param array $config
     * @return BookingResult
     */
    public function book_appointment(array $data, array $config): BookingResult {
        // Map time codes
        $time_map = [
            'MD' => 'Mid-Day',
            'md' => 'Mid-Day',
            'EV' => 'Evening',
            'ev' => 'Evening',
        ];
        $time = $time_map[$data['time']] ?? $data['time'];

        $params = [
            'fsr' => $data['fsr'] ?? '',
            'caNo' => $data['ca_no'] ?? '',
            'schedule_date' => $data['schedule_date'] ?? '',
            'time' => $time,
            'pswd' => $config['api_password'],
            'val' => 'submit',
        ];

        // Add equipment
        if (!empty($data['equipment'])) {
            foreach ($data['equipment'] as $type => $eq_config) {
                if (isset($eq_config['count']) && $eq_config['count'] > 0) {
                    $params["eqCount-{$type}"] = $eq_config['count'];
                    if (isset($eq_config['location'])) {
                        $params["eqLoc-{$type}"] = $eq_config['location'];
                    }
                    if (isset($eq_config['desired_device'])) {
                        $params["dd-{$type}"] = $eq_config['desired_device'];
                    }
                }
            }
        }

        try {
            $response = $this->make_request(
                $config['api_endpoint'],
                '/field_service_requests/schedule',
                $params,
                'GET',
                false
            );

            return $this->parse_booking_response($response, $data);
        } catch (\Exception $e) {
            return new BookingResult([
                'success' => false,
                'error_code' => 'connection_error',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map form fields to API format
     *
     * @param array $form_data
     * @param string $type
     * @return array
     */
    public function map_fields(array $form_data, string $type = 'enrollment'): array {
        $mapper = new IntelliSourceFieldMapper();

        if ($type === 'scheduling') {
            return $mapper->map_scheduling_data($form_data);
        }

        return $mapper->map_enrollment_data($form_data);
    }

    /**
     * Get supported features
     *
     * @return array
     */
    public function get_supported_features(): array {
        return [
            'account_validation',
            'enrollment',
            'scheduling',
            'promo_codes',
            'equipment_selection',
            'cycling_levels',
        ];
    }

    /**
     * Check if feature is supported
     *
     * @param string $feature
     * @return bool
     */
    public function supports(string $feature): bool {
        return in_array($feature, $this->get_supported_features(), true);
    }

    /**
     * Get preset configurations for utilities
     *
     * @return array
     */
    public function get_presets(): array {
        return [
            'delmarva_de' => [
                'name' => __('Delmarva Power - Delaware', 'formflow'),
                'short_name' => 'Delmarva DE',
                'state' => 'DE',
                'api_endpoint' => 'https://ph.powerportal.com/phiIntelliSOURCE/api',
                'program_name' => 'Energy Wise Rewards',
                'program_url' => 'https://energywiserewards.delmarva.com',
                'support_phone' => '1-888-818-0075',
                'support_email' => 'support@energywiserewards.com',
                'branding' => [
                    'primary_color' => '#0066cc',
                    'logo_url' => '',
                ],
            ],
            'delmarva_md' => [
                'name' => __('Delmarva Power - Maryland', 'formflow'),
                'short_name' => 'Delmarva MD',
                'state' => 'MD',
                'api_endpoint' => 'https://ph.powerportal.com/phiIntelliSOURCE/api',
                'program_name' => 'Energy Wise Rewards',
                'program_url' => 'https://energywiserewards.delmarva.com',
                'support_phone' => '1-888-818-0075',
                'support_email' => 'support@energywiserewards.com',
                'branding' => [
                    'primary_color' => '#0066cc',
                    'logo_url' => '',
                ],
            ],
            'pepco_md' => [
                'name' => __('Pepco - Maryland', 'formflow'),
                'short_name' => 'Pepco MD',
                'state' => 'MD',
                'api_endpoint' => 'https://ph.powerportal.com/phiIntelliSOURCE/api',
                'program_name' => 'Energy Wise Rewards',
                'program_url' => 'https://energywiserewards.pepco.com',
                'support_phone' => '1-888-818-0075',
                'support_email' => 'support@energywiserewards.com',
                'branding' => [
                    'primary_color' => '#00a94f',
                    'logo_url' => '',
                ],
            ],
            'pepco_dc' => [
                'name' => __('Pepco - Washington DC', 'formflow'),
                'short_name' => 'Pepco DC',
                'state' => 'DC',
                'api_endpoint' => 'https://ph.powerportal.com/phiIntelliSOURCE/api',
                'program_name' => 'Energy Wise Rewards',
                'program_url' => 'https://energywiserewards.pepco.com',
                'support_phone' => '1-888-818-0075',
                'support_email' => 'support@energywiserewards.com',
                'branding' => [
                    'primary_color' => '#00a94f',
                    'logo_url' => '',
                ],
            ],
        ];
    }

    /**
     * Make an API request
     *
     * @param string $endpoint
     * @param string $path
     * @param array $params
     * @param string $method
     * @param bool $parse_xml
     * @return array|string
     */
    private function make_request(
        string $endpoint,
        string $path,
        array $params,
        string $method = 'GET',
        bool $parse_xml = true
    ): array|string {
        $url = rtrim($endpoint, '/') . $path;

        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
            $body = null;
        } else {
            $body = http_build_query($params);
        }

        // SSRF guard: the api_endpoint is admin-set and previously validated
        // only by FILTER_VALIDATE_URL, which happily accepts http://127.0.0.1,
        // http://169.254.169.254 (cloud metadata), internal hostnames, etc.
        // Before making any outbound request we assert the resolved target is a
        // public http(s) host and refuse loopback / private / link-local /
        // reserved ranges. sslverify stays TRUE below — do not disable it.
        $this->assert_safe_request_url($url);

        $args = [
            'method' => $method,
            'timeout' => 30,
            'sslverify' => true,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ];

        if ($body) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status >= 400) {
            throw new \Exception("HTTP {$status}: {$body}");
        }

        if ($parse_xml && !empty($body)) {
            return IntelliSourceXmlParser::parse($body);
        }

        return $body;
    }

    /**
     * Assert an outbound request URL is safe (anti-SSRF).
     *
     * The IntelliSOURCE api_endpoint is admin-configured. Validating it with
     * FILTER_VALIDATE_URL alone does NOT stop a misconfigured or malicious
     * endpoint from pointing the server at internal infrastructure (loopback,
     * the cloud metadata service at 169.254.169.254, RFC1918 hosts, etc.).
     *
     * This guard enforces:
     *   - scheme is http or https (no file://, gopher://, dict://, ...)
     *   - every IP the host resolves to is a public unicast address, rejecting
     *     127.0.0.0/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1 and the
     *     rest of the private/reserved space.
     *
     * @param string $url
     * @return void
     * @throws \Exception if the URL targets a disallowed scheme or IP range.
     */
    private function assert_safe_request_url(string $url): void {
        $parts  = wp_parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \Exception(
                __('Blocked API request: only http(s) endpoints are allowed.', 'formflow')
            );
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new \Exception(
                __('Blocked API request: endpoint host is missing.', 'formflow')
            );
        }

        $ips = $this->resolve_host_ips($host);
        if (empty($ips)) {
            throw new \Exception(
                __('Blocked API request: endpoint host could not be resolved.', 'formflow')
            );
        }

        foreach ($ips as $ip) {
            if ($this->is_blocked_ip($ip)) {
                throw new \Exception(
                    __('Blocked API request: endpoint resolves to a private or reserved network address.', 'formflow')
                );
            }
        }
    }

    /**
     * Resolve a host to the list of IP addresses it points at.
     *
     * IP literals (v4/v6, optionally bracketed) are returned as-is so an
     * attacker cannot smuggle a raw internal IP past DNS resolution.
     *
     * @param string $host
     * @return array<int,string>
     */
    private function resolve_host_ips(string $host): array {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Whether an IP address is outside the public unicast space.
     *
     * Uses PHP's own private/reserved-range tables, which cover loopback
     * (127.0.0.0/8, ::1), RFC1918 (10/8, 172.16/12, 192.168/16), link-local
     * (169.254/16, fe80::/10) and other reserved blocks.
     *
     * @param string $ip
     * @return bool
     */
    private function is_blocked_ip(string $ip): bool {
        // filter_var returns false when the IP falls in a private OR reserved
        // range, i.e. anything we must refuse.
        return false === filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Parse validation response
     *
     * @param array $response
     * @return AccountValidationResult
     */
    private function parse_validation_response(array $response): AccountValidationResult {
        // Check for error codes
        $error_code = $response['error_cd'] ?? $response['errorCode'] ?? '';
        $error_message = $response['error_message'] ?? $response['error_message'] ?? '';

        // Check validation status
        $valid = ($response['valid'] ?? '') === 'Y'
            || ($response['status'] ?? '') === 'valid';

        if ($error_code === '03') {
            // Already enrolled
            return new AccountValidationResult([
                'is_valid' => false,
                'error_code' => 'already_enrolled',
                'error_message' => __('This account is already enrolled in the program.', 'formflow'),
                'customer_data' => $response,
            ]);
        }

        if ($error_code === '21') {
            // Medical condition flag
            return new AccountValidationResult([
                'is_valid' => true,
                'error_code' => 'medical_condition',
                'error_message' => __('Medical condition acknowledgment required.', 'formflow'),
                'customer_data' => $response,
            ]);
        }

        return new AccountValidationResult([
            'is_valid' => $valid,
            'error_code' => $error_code,
            'error_message' => $error_message,
            'customer_data' => $response,
            'raw_response' => $response,
        ]);
    }

    /**
     * Parse enrollment response
     *
     * @param array $response
     * @return EnrollmentResult
     */
    private function parse_enrollment_response(array $response): EnrollmentResult {
        $success = ($response['status'] ?? '') === 'success'
            || !empty($response['confirmation_no'])
            || !empty($response['caNo']);

        return new EnrollmentResult([
            'success' => $success,
            'confirmation_number' => $response['confirmation_no'] ?? $response['caNo'] ?? '',
            'error_code' => $response['error_cd'] ?? '',
            'error_message' => $response['error_message'] ?? '',
            'data' => $response,
            'raw_response' => $response,
        ]);
    }

    /**
     * Parse scheduling response
     *
     * @param array $response
     * @return SchedulingResult
     */
    private function parse_scheduling_response(array $response): SchedulingResult {
        // Parse available slots from response
        $slots = [];

        if (isset($response['slots']) && is_array($response['slots'])) {
            foreach ($response['slots'] as $slot) {
                $slots[] = [
                    'date' => $slot['date'] ?? '',
                    'time' => $slot['time'] ?? '',
                    'available' => ($slot['available'] ?? 'Y') === 'Y',
                ];
            }
        }

        return new SchedulingResult([
            'success' => !empty($slots) || isset($response['fsr']),
            'fsr' => $response['fsr'] ?? '',
            'ca_no' => $response['caNo'] ?? '',
            'slots' => $slots,
            'raw_response' => $response,
        ]);
    }

    /**
     * Parse booking response
     *
     * @param mixed $response
     * @param array $data
     * @return BookingResult
     */
    private function parse_booking_response($response, array $data): BookingResult {
        // Booking typically returns a simple success or the appointment details
        $success = !empty($response) && !str_contains(strtolower($response), 'error');

        return new BookingResult([
            'success' => $success,
            'confirmation_number' => is_array($response) ? ($response['confirmation'] ?? '') : '',
            'appointment_date' => $data['schedule_date'] ?? '',
            'appointment_time' => $data['time'] ?? '',
            'raw_response' => $response,
        ]);
    }

    /**
     * Get database instance
     *
     * @return Database
     */
    private function get_db(): Database {
        if ($this->db === null) {
            $this->db = new Database();
        }
        return $this->db;
    }
}
