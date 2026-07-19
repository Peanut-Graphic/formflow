<?php
namespace ISF\Api;

if ( ! defined( 'ABSPATH' ) ) { exit; }


/**
 * API Client
 *
 * Handles HTTP communication with the PowerPortal IntelliSOURCE API.
 */


use ISF\Database\Database;

class ApiClient {

    private string $endpoint;
    private string $password;
    private bool $test_mode;
    private ?int $instance_id;
    private Database $db;

    /**
     * Constructor
     *
     * @param string $endpoint The API base endpoint URL
     * @param string $password The API password
     * @param bool $test_mode Whether to use test mode
     * @param int|null $instance_id The form instance ID for logging
     */
    public function __construct(
        string $endpoint,
        string $password,
        bool $test_mode = false,
        ?int $instance_id = null
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->password = $password;
        $this->test_mode = $test_mode;
        $this->instance_id = $instance_id;
        $this->db = new Database();
    }

    /**
     * Validate an account number and ZIP code
     *
     * @param string $utility_no The account/utility number
     * @param string $zip The ZIP code
     * @return ValidationResult The validation result
     * @throws ApiException If response validation fails
     */
    public function validate_account(string $utility_no, string $zip): ValidationResult {
        $params = [
            'utility_no' => $utility_no,
            'zip' => $zip,
            'pswd' => $this->password,
            'val' => 'submit'
        ];

        $response = $this->request('/prospects/validate.xml', $params);

        // Validate response schema
        if (!ResponseValidator::validate_validation_response($response)) {
            $this->db->log('warning', 'Invalid API response schema', [
                'endpoint' => '/prospects/validate.xml',
                'errors' => ResponseValidator::get_errors(),
            ], $this->instance_id);
            throw new ApiException('Invalid response from API: ' . ResponseValidator::get_last_error(), 0);
        }

        return new ValidationResult($response);
    }

    /**
     * Submit enrollment
     *
     * @param array $data Enrollment data (form field names)
     * @param bool $use_field_mapper Whether to map field names to API format (default: true)
     * @return array The API response
     * @throws FieldMappingException If required fields are missing
     */
    public function enroll(array $data, bool $use_field_mapper = true): array {
        // Map form fields to API parameter names
        if ($use_field_mapper) {
            $api_params = FieldMapper::mapEnrollmentData($data);
        } else {
            $api_params = $data;
        }

        // Add authentication and submission flag
        $params = array_merge($api_params, [
            'pswd' => $this->password,
            'val' => 'submit'
        ]);

        // Log the mapped parameters for debugging (without sensitive data)
        $safe_log = $params;
        unset($safe_log['pswd']);
        $this->db->log('info', 'Enrollment API call with mapped fields', [
            'mapped_fields' => array_keys($safe_log),
            'field_count' => count($safe_log),
        ], $this->instance_id);

        return $this->request('/prospects/enroll.xml', $params);
    }

    /**
     * Get available scheduling slots
     *
     * @param string $account_number The account number (utility_no or caNo)
     * @param string $start_date Start date (m/d/Y format)
     * @param array $equipment Optional equipment configuration
     * @return SchedulingResult The scheduling result
     * @throws ApiException If response validation fails
     */
    public function get_schedule_slots(
        string $account_number,
        string $start_date,
        array $equipment = []
    ): SchedulingResult {
        $params = [
            'startDate' => $start_date,
            'pswd' => $this->password,
            'val' => 'submit'
        ];

        // Determine if this is a Comverge account (starts with X)
        if (strtolower(substr($account_number, 0, 1)) === 'x') {
            $params['caNo'] = substr($account_number, 1);
        } else {
            $params['utility_no'] = $account_number;
        }

        // Add equipment counts if provided
        foreach ($equipment as $type => $config) {
            if (isset($config['count'])) {
                $params["eqCount-{$type}"] = $config['count'];
            }
            if (isset($config['location'])) {
                $params["eqLoc-{$type}"] = $config['location'];
            }
        }

        $response = $this->request('/field_service_requests/scheduling.xml', $params);

        // Validate response schema
        if (!ResponseValidator::validate_scheduling_response($response)) {
            $this->db->log('warning', 'Invalid API response schema', [
                'endpoint' => '/field_service_requests/scheduling.xml',
                'errors' => ResponseValidator::get_errors(),
            ], $this->instance_id);
            throw new ApiException('Invalid response from API: ' . ResponseValidator::get_last_error(), 0);
        }

        return new SchedulingResult($response);
    }

    /**
     * Book an appointment slot
     *
     * @param string $fsr The FSR number
     * @param string $ca_no The Comverge account number
     * @param string $schedule_date The selected date
     * @param string $time The selected time slot (AM, PM, MD, EV)
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
        // Map time codes to full names for certain values
        $time_map = [
            'MD' => 'Mid-Day',
            'md' => 'Mid-Day',
            'EV' => 'Evening',
            'ev' => 'Evening'
        ];
        $time = $time_map[$time] ?? $time;

        $params = [
            'fsr' => $fsr,
            'caNo' => $ca_no,
            'schedule_date' => $schedule_date,
            'time' => $time,
            'pswd' => $this->password,
            'val' => 'submit'
        ];

        // Add equipment
        foreach ($equipment as $type => $config) {
            if (isset($config['count']) && $config['count'] > 0) {
                $params["eqCount-{$type}"] = $config['count'];
                if (isset($config['location'])) {
                    $params["eqLoc-{$type}"] = $config['location'];
                }
                if (isset($config['desired_device'])) {
                    $params["dd-{$type}"] = $config['desired_device'];
                }
            }
        }

        // Add user ID if provided
        if ($user_id) {
            $params['userId'] = $user_id;
        }

        // Use POST to avoid credentials in URL
        return $this->request('/field_service_requests/schedule', $params, 'POST', false);
    }

    /**
     * Get promotional codes
     *
     * @return array List of promo codes
     */
    public function get_promo_codes(): array {
        $params = [
            'pswd' => $this->password
        ];

        // Use POST to avoid credentials in URL
        $response = $this->request('/promo_codes', $params, 'POST', false);

        // Parse response - typically returns comma-separated codes
        if (is_string($response)) {
            return array_filter(array_map('trim', explode(',', $response)));
        }

        return $response;
    }

    /**
     * Make an API request
     *
     * @param string $path The API path
     * @param array $params Request parameters
     * @param string $method HTTP method (POST recommended for security, GET for non-credential calls)
     * @param bool $parse_xml Whether to parse XML response
     * @return array|string The response data
     */
    private function request(
        string $path,
        array $params,
        string $method = 'POST',
        bool $parse_xml = true
    ): array|string {
        $url = $this->endpoint . $path;

        // Build query string for GET requests
        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
            $body = null;
        } else {
            $body = http_build_query($params);
        }

        $start_time = microtime(true);

        // Log the request
        $this->log_request($method, $path, $params);

        // Make the request with retry logic
        $response = $this->do_request($url, $method, $body);

        $elapsed = round((microtime(true) - $start_time) * 1000);

        // Log the response
        $this->log_response($method, $path, $response['status'], $elapsed, $response['error'] ? $response['error_message'] : null);

        // Handle errors
        if ($response['error']) {
            throw new ApiException(
                $response['error_message'],
                $response['status']
            );
        }

        // Parse XML response if requested
        if ($parse_xml && !empty($response['body'])) {
            try {
                return XmlParser::parse($response['body']);
            } catch (\Exception $e) {
                throw new ApiException(
                    'Failed to parse API response: ' . $e->getMessage(),
                    0
                );
            }
        }

        return $response['body'];
    }

    /**
     * Perform HTTP request with retry logic
     *
     * @param string $url Full URL
     * @param string $method HTTP method
     * @param string|null $body Request body
     * @return array Response data
     */
    private function do_request(string $url, string $method, ?string $body): array {
        $max_retries = 3;
        $retry_count = 0;

        $args = [
            'method' => $method,
            'timeout' => 30,
            'sslverify' => true,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        ];

        if ($body) {
            $args['body'] = $body;
        }

        while ($retry_count < $max_retries) {
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $retry_count++;
                if ($retry_count < $max_retries) {
                    sleep(pow(2, $retry_count)); // Exponential backoff
                    continue;
                }

                return [
                    'body' => '',
                    'status' => 0,
                    'error' => true,
                    'error_message' => $response->get_error_message()
                ];
            }

            $status = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            // Retry on server errors
            if ($status >= 500) {
                $retry_count++;
                if ($retry_count < $max_retries) {
                    sleep(pow(2, $retry_count));
                    continue;
                }
            }

            return [
                'body' => $body,
                'status' => $status,
                'error' => $status >= 400,
                'error_message' => $status >= 400 ? "HTTP {$status}: {$body}" : ''
            ];
        }

        return [
            'body' => '',
            'status' => 0,
            'error' => true,
            'error_message' => 'Max retries exceeded'
        ];
    }

    /**
     * Log API request
     */
    private function log_request(string $method, string $path, array $params): void {
        // Remove sensitive data from log
        $safe_params = $params;
        unset($safe_params['pswd']);

        $this->db->log('api_call', "{$method} {$path}", [
            'direction' => 'request',
            'params' => $safe_params,
            'test_mode' => $this->test_mode
        ], $this->instance_id);
    }

    /**
     * Log API response
     */
    private function log_response(string $method, string $path, int $status, int $elapsed_ms, ?string $error = null): void {
        $this->db->log('api_call', "{$method} {$path}", [
            'direction' => 'response',
            'status' => $status,
            'elapsed_ms' => $elapsed_ms,
            'test_mode' => $this->test_mode
        ], $this->instance_id);

        // Track API usage for rate limit monitoring
        if ($this->instance_id) {
            $success = $status >= 200 && $status < 400;
            $this->db->log_api_call(
                $this->instance_id,
                $path,
                $method,
                $status,
                $elapsed_ms,
                $success,
                $error
            );
        }
    }

    /**
     * Test the API connection
     *
     * @return bool True if connection successful
     */
    public function test_connection(): bool {
        try {
            // Try to get promo codes as a simple connectivity test
            $this->get_promo_codes();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Perform a detailed health check of the API connection
     *
     * @return array Health check result with status, latency, and error details
     */
    public function health_check(): array {
        $result = [
            'status' => 'unknown',
            'latency_ms' => 0,
            'error' => null,
            'checked_at' => current_time('mysql'),
            'endpoint' => $this->endpoint,
        ];

        $start_time = microtime(true);

        try {
            // Try to get promo codes as a simple connectivity test
            $this->get_promo_codes();

            $result['latency_ms'] = round((microtime(true) - $start_time) * 1000);
            $result['status'] = 'healthy';

            // Classify latency
            if ($result['latency_ms'] > 5000) {
                $result['status'] = 'degraded';
                $result['warning'] = 'High latency detected';
            } elseif ($result['latency_ms'] > 10000) {
                $result['status'] = 'slow';
                $result['warning'] = 'Very high latency';
            }

        } catch (ApiException $e) {
            $result['latency_ms'] = round((microtime(true) - $start_time) * 1000);
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
            $result['http_status'] = $e->getHttpStatus();
        } catch (\Exception $e) {
            $result['latency_ms'] = round((microtime(true) - $start_time) * 1000);
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Get the API endpoint URL
     *
     * @return string The endpoint URL
     */
    public function get_endpoint(): string {
        return $this->endpoint;
    }

    /**
     * Is an outbound URL safe to request? (anti-SSRF)
     *
     * Rejects non-http(s) schemes and any host that resolves to a loopback,
     * link-local, private or otherwise reserved IP range (127/8, 10/8,
     * 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7, …). Fails closed: a
     * host that cannot be resolved is treated as unsafe.
     *
     * Pure/deterministic for IP-literal hosts (no network needed), which is
     * how the regression test exercises it.
     *
     * Ported from FormFlow Lite so connectors in this plugin share one guard
     * rather than each hand-rolling its own. The IntelliSource connector's
     * private assert_safe_request_url() predates this and is equivalent.
     *
     * @param string $url Absolute URL to validate.
     * @return bool True if the URL is safe to request.
     */
    public static function is_safe_outbound_url(string $url): bool {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        // Normalise host: lowercase, strip IPv6 brackets.
        $host = strtolower(trim((string) $parts['host']));
        $host = trim($host, '[]');
        if ($host === '' || $host === 'localhost') {
            return false;
        }

        $ips = self::resolve_host_ips($host);
        if (empty($ips)) {
            return false; // Unresolvable — fail closed.
        }

        foreach ($ips as $ip) {
            if (!self::is_public_ip($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a host to the set of IPs it points at.
     *
     * IP literals are returned as-is (no lookup). Host names are resolved for
     * both A (IPv4) and AAAA (IPv6) records so a name that maps to a private
     * address is caught.
     *
     * @param string $host Lowercased host, brackets already stripped.
     * @return array<int,string> List of IP strings (may be empty).
     */
    private static function resolve_host_ips(string $host): array {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
            $v6 = @dns_get_record($host, DNS_AAAA);
            if (is_array($v6)) {
                foreach ($v6 as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = $rec['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Is an IP a routable, public address?
     *
     * FILTER_FLAG_NO_PRIV_RANGE blocks 10/8, 172.16/12, 192.168/16, fc00::/7;
     * FILTER_FLAG_NO_RES_RANGE blocks 127/8, 169.254/16, ::1 and other
     * reserved space.
     *
     * @param string $ip IP string.
     * @return bool True if the IP is public.
     */
    private static function is_public_ip(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}

/**
 * Custom exception for API errors
 */
class ApiException extends \Exception {
    private int $http_status;

    public function __construct(string $message, int $http_status = 0) {
        parent::__construct($message);
        $this->http_status = $http_status;
    }

    public function getHttpStatus(): int {
        return $this->http_status;
    }
}
