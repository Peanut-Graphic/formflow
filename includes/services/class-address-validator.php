<?php
/**
 * Smart Address Validation Service
 *
 * Provides address autocomplete, validation, and standardization
 * with support for multiple providers (Google, USPS, SmartyStreets).
 *
 * @package FormFlow
 * @since 2.6.0
 */

namespace ISF\Services;

defined('ABSPATH') || exit;

class AddressValidator {

    /**
     * Provider constants
     */
    const PROVIDER_GOOGLE = 'google';
    const PROVIDER_USPS = 'usps';
    const PROVIDER_SMARTY = 'smartystreets';
    const PROVIDER_NONE = 'none';

    /**
     * Rate limiting constants
     */
    const RATE_LIMIT_REQUESTS = 100;  // Max requests per window
    const RATE_LIMIT_WINDOW = 60;     // Window in seconds

    /**
     * Circuit breaker constants
     */
    const CIRCUIT_FAILURE_THRESHOLD = 5;   // Failures before opening circuit
    const CIRCUIT_RECOVERY_TIME = 300;     // Seconds before trying again

    /**
     * Singleton instance
     */
    private static ?AddressValidator $instance = null;

    /**
     * Settings
     */
    private array $settings = [];

    /**
     * Cache for validated addresses
     */
    private array $cache = [];

    /**
     * Error tracking for circuit breaker
     */
    private array $error_counts = [];

    /**
     * Get singleton instance
     */
    public static function instance(): AddressValidator {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->settings = get_option('isf_address_settings', [
            'provider' => self::PROVIDER_NONE,
            'google_api_key' => '',
            'usps_user_id' => '',
            'smarty_auth_id' => '',
            'smarty_auth_token' => '',
            'enable_autocomplete' => true,
            'enable_validation' => true,
            'require_valid_address' => false,
            'cache_duration' => 86400, // 24 hours
        ]);
    }

    /**
     * Initialize hooks
     */
    public function init(): void {
        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Admin settings
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        register_rest_route('isf/v1', '/address/autocomplete', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_autocomplete'],
            'permission_callback' => ['\ISF\Security', 'rate_limit_public'],
            'args' => [
                'input' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'session_token' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('isf/v1', '/address/validate', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_validate'],
            'permission_callback' => ['\ISF\Security', 'rate_limit_public'],
            'args' => [
                'street' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'city' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'state' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'zip' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('isf/v1', '/address/place-details', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_place_details'],
            'permission_callback' => ['\ISF\Security', 'rate_limit_public'],
            'args' => [
                'place_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'session_token' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Check per-IP rate limit for public REST endpoints.
     *
     * @param string $action Unique action identifier.
     * @param int    $limit  Max requests per window.
     * @param int    $window Time window in seconds.
     * @return \WP_REST_Response|null Error response if exceeded, null if OK.
     */
    private function check_rest_rate_limit(string $action, int $limit = 30, int $window = 60): ?\WP_REST_Response {
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
     * REST callback: Address autocomplete
     */
    public function rest_autocomplete(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_error = $this->check_rest_rate_limit('isf_address_autocomplete', 30, 60);
        if ($rate_limit_error !== null) {
            return $rate_limit_error;
        }

        $input = $request->get_param('input');
        $session_token = $request->get_param('session_token');

        if (strlen($input) < 3) {
            return new \WP_REST_Response(['predictions' => []], 200);
        }

        $predictions = $this->get_autocomplete_predictions($input, $session_token);

        return new \WP_REST_Response(['predictions' => $predictions], 200);
    }

    /**
     * REST callback: Validate address
     */
    public function rest_validate(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_error = $this->check_rest_rate_limit('isf_address_validate', 20, 60);
        if ($rate_limit_error !== null) {
            return $rate_limit_error;
        }

        $address = [
            'street' => $request->get_param('street'),
            'city' => $request->get_param('city'),
            'state' => $request->get_param('state'),
            'zip' => $request->get_param('zip'),
        ];

        $result = $this->validate_address($address);

        return new \WP_REST_Response($result, 200);
    }

    /**
     * REST callback: Get place details
     */
    public function rest_place_details(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_error = $this->check_rest_rate_limit('isf_address_place_details', 20, 60);
        if ($rate_limit_error !== null) {
            return $rate_limit_error;
        }

        $place_id = $request->get_param('place_id');
        $session_token = $request->get_param('session_token');

        $details = $this->get_place_details($place_id, $session_token);

        if (!$details) {
            return new \WP_REST_Response(['error' => 'Place not found'], 404);
        }

        return new \WP_REST_Response($details, 200);
    }

    /**
     * Get autocomplete predictions
     */
    public function get_autocomplete_predictions(string $input, ?string $session_token = null): array {
        $provider = $this->settings['provider'] ?? self::PROVIDER_NONE;

        if ($provider === self::PROVIDER_NONE || !$this->settings['enable_autocomplete']) {
            return [];
        }

        switch ($provider) {
            case self::PROVIDER_GOOGLE:
                return $this->google_autocomplete($input, $session_token);

            case self::PROVIDER_SMARTY:
                return $this->smarty_autocomplete($input);

            default:
                return [];
        }
    }

    /**
     * Validate an address
     */
    public function validate_address(array $address): array {
        $provider = $this->settings['provider'] ?? self::PROVIDER_NONE;

        // Check cache first
        $cache_key = $this->get_cache_key($address);
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        // Check WordPress transient cache
        $cached = get_transient('isf_address_' . $cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $result = [
            'valid' => true,
            'standardized' => $address,
            'issues' => [],
            'suggestions' => [],
        ];

        if ($provider === self::PROVIDER_NONE || !$this->settings['enable_validation']) {
            return $result;
        }

        switch ($provider) {
            case self::PROVIDER_USPS:
                $result = $this->usps_validate($address);
                break;

            case self::PROVIDER_SMARTY:
                $result = $this->smarty_validate($address);
                break;

            case self::PROVIDER_GOOGLE:
                $result = $this->google_validate($address);
                break;
        }

        // Cache the result
        $this->cache[$cache_key] = $result;
        set_transient('isf_address_' . $cache_key, $result, $this->settings['cache_duration']);

        return $result;
    }

    /**
     * Get place details from Google Places API
     */
    public function get_place_details(string $place_id, ?string $session_token = null): ?array {
        if ($this->settings['provider'] !== self::PROVIDER_GOOGLE) {
            return null;
        }

        $api_key = $this->settings['google_api_key'] ?? '';
        if (empty($api_key)) {
            return null;
        }

        $url = 'https://maps.googleapis.com/maps/api/place/details/json';
        $params = [
            'place_id' => $place_id,
            'fields' => 'address_components,formatted_address,geometry',
            'key' => $api_key,
        ];

        if ($session_token) {
            $params['sessiontoken'] = $session_token;
        }

        $response = wp_remote_get($url . '?' . http_build_query($params));

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($body['status'] !== 'OK') {
            return null;
        }

        return $this->parse_google_place($body['result']);
    }

    /**
     * Google Places Autocomplete
     */
    private function google_autocomplete(string $input, ?string $session_token): array {
        $api_key = $this->settings['google_api_key'] ?? '';
        if (empty($api_key)) {
            $this->log_error('Google API key not configured', [], 'warning');
            return [];
        }

        return $this->safe_api_request(self::PROVIDER_GOOGLE, function() use ($input, $session_token, $api_key) {
            $url = 'https://maps.googleapis.com/maps/api/place/autocomplete/json';
            $params = [
                'input' => $input,
                'types' => 'address',
                'components' => 'country:us',
                'key' => $api_key,
            ];

            if ($session_token) {
                $params['sessiontoken'] = $session_token;
            }

            $response = wp_remote_get($url . '?' . http_build_query($params));

            if (is_wp_error($response)) {
                return $response; // Will be handled by safe_api_request
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (!isset($body['status'])) {
                $this->log_error('Invalid Google API response', ['body' => $body]);
                return new \WP_Error('invalid_response', 'Invalid API response');
            }

            if ($body['status'] !== 'OK') {
                if ($body['status'] !== 'ZERO_RESULTS') {
                    $this->log_error('Google API error', [
                        'status' => $body['status'],
                        'error_message' => $body['error_message'] ?? 'Unknown error',
                    ]);
                }
                return [];
            }

            $predictions = [];
            foreach ($body['predictions'] ?? [] as $prediction) {
                $predictions[] = [
                    'place_id' => $prediction['place_id'],
                    'description' => $prediction['description'],
                    'main_text' => $prediction['structured_formatting']['main_text'] ?? '',
                    'secondary_text' => $prediction['structured_formatting']['secondary_text'] ?? '',
                ];
            }

            return $predictions;
        }, []);
    }

    /**
     * SmartyStreets Autocomplete
     */
    private function smarty_autocomplete(string $input): array {
        $auth_id = $this->settings['smarty_auth_id'] ?? '';
        $auth_token = $this->settings['smarty_auth_token'] ?? '';

        if (empty($auth_id) || empty($auth_token)) {
            return [];
        }

        $url = 'https://us-autocomplete-pro.api.smartystreets.com/lookup';
        $params = [
            'search' => $input,
            'max_results' => 10,
            'auth-id' => $auth_id,
            'auth-token' => $auth_token,
        ];

        $response = wp_remote_get($url . '?' . http_build_query($params));

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['suggestions'])) {
            return [];
        }

        $predictions = [];
        foreach ($body['suggestions'] as $suggestion) {
            $predictions[] = [
                'description' => $suggestion['street_line'] . ', ' .
                                $suggestion['city'] . ', ' .
                                $suggestion['state'] . ' ' .
                                $suggestion['zipcode'],
                'main_text' => $suggestion['street_line'],
                'secondary_text' => $suggestion['city'] . ', ' . $suggestion['state'] . ' ' . $suggestion['zipcode'],
                'data' => $suggestion,
            ];
        }

        return $predictions;
    }

    /**
     * USPS Address Validation
     */
    private function usps_validate(array $address): array {
        $user_id = $this->settings['usps_user_id'] ?? '';
        if (empty($user_id)) {
            return [
                'valid' => true,
                'standardized' => $address,
                'issues' => ['USPS validation not configured'],
                'suggestions' => [],
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" ?>
            <AddressValidateRequest USERID="' . esc_attr($user_id) . '">
                <Revision>1</Revision>
                <Address ID="0">
                    <Address1>' . esc_attr($address['street2'] ?? '') . '</Address1>
                    <Address2>' . esc_attr($address['street']) . '</Address2>
                    <City>' . esc_attr($address['city']) . '</City>
                    <State>' . esc_attr($address['state']) . '</State>
                    <Zip5>' . esc_attr(substr($address['zip'], 0, 5)) . '</Zip5>
                    <Zip4></Zip4>
                </Address>
            </AddressValidateRequest>';

        $url = 'https://secure.shippingapis.com/ShippingAPI.dll';
        $response = wp_remote_get($url . '?API=Verify&XML=' . urlencode($xml));

        if (is_wp_error($response)) {
            return [
                'valid' => true,
                'standardized' => $address,
                'issues' => ['USPS service unavailable'],
                'suggestions' => [],
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $xml_response = simplexml_load_string($body);

        if (!$xml_response || isset($xml_response->Error)) {
            return [
                'valid' => false,
                'standardized' => $address,
                'issues' => ['Address could not be verified'],
                'suggestions' => [],
            ];
        }

        $addr = $xml_response->Address;

        // Check for errors
        if (isset($addr->Error)) {
            return [
                'valid' => false,
                'standardized' => $address,
                'issues' => [(string)$addr->Error->Description],
                'suggestions' => [],
            ];
        }

        // Build standardized address
        $standardized = [
            'street' => (string)$addr->Address2,
            'street2' => (string)$addr->Address1 ?: null,
            'city' => (string)$addr->City,
            'state' => (string)$addr->State,
            'zip' => (string)$addr->Zip5 . ((string)$addr->Zip4 ? '-' . (string)$addr->Zip4 : ''),
        ];

        // Check for delivery point issues
        $issues = [];
        if (isset($addr->DPVConfirmation)) {
            $dpv = (string)$addr->DPVConfirmation;
            if ($dpv === 'N') {
                $issues[] = 'Address not found in USPS database';
            } elseif ($dpv === 'S') {
                $issues[] = 'Secondary address (apartment, suite) is missing';
            } elseif ($dpv === 'D') {
                $issues[] = 'Secondary address information is incorrect';
            }
        }

        return [
            'valid' => empty($issues),
            'standardized' => $standardized,
            'issues' => $issues,
            'suggestions' => [],
            'dpv_confirmation' => (string)($addr->DPVConfirmation ?? ''),
            'carrier_route' => (string)($addr->CarrierRoute ?? ''),
        ];
    }

    /**
     * SmartyStreets Address Validation
     */
    private function smarty_validate(array $address): array {
        $auth_id = $this->settings['smarty_auth_id'] ?? '';
        $auth_token = $this->settings['smarty_auth_token'] ?? '';

        if (empty($auth_id) || empty($auth_token)) {
            return [
                'valid' => true,
                'standardized' => $address,
                'issues' => ['SmartyStreets validation not configured'],
                'suggestions' => [],
            ];
        }

        $url = 'https://us-street.api.smartystreets.com/street-address';
        $params = [
            'street' => $address['street'],
            'city' => $address['city'],
            'state' => $address['state'],
            'zipcode' => $address['zip'],
            'match' => 'invalid',
            'auth-id' => $auth_id,
            'auth-token' => $auth_token,
        ];

        $response = wp_remote_get($url . '?' . http_build_query($params));

        if (is_wp_error($response)) {
            return [
                'valid' => true,
                'standardized' => $address,
                'issues' => ['SmartyStreets service unavailable'],
                'suggestions' => [],
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body)) {
            return [
                'valid' => false,
                'standardized' => $address,
                'issues' => ['Address not found'],
                'suggestions' => [],
            ];
        }

        $result = $body[0];
        $components = $result['components'] ?? [];
        $analysis = $result['analysis'] ?? [];

        $standardized = [
            'street' => $result['delivery_line_1'] ?? $address['street'],
            'street2' => $result['delivery_line_2'] ?? null,
            'city' => $components['city_name'] ?? $address['city'],
            'state' => $components['state_abbreviation'] ?? $address['state'],
            'zip' => ($components['zipcode'] ?? $address['zip']) .
                    (isset($components['plus4_code']) ? '-' . $components['plus4_code'] : ''),
        ];

        $issues = [];
        $dpv_match = $analysis['dpv_match_code'] ?? '';

        if ($dpv_match === 'N') {
            $issues[] = 'Address not found';
        } elseif ($dpv_match === 'S') {
            $issues[] = 'Secondary address (apartment, suite) is missing';
        } elseif ($dpv_match === 'D') {
            $issues[] = 'Secondary address information is incorrect';
        }

        return [
            'valid' => in_array($dpv_match, ['Y', 'S', 'D']),
            'standardized' => $standardized,
            'issues' => $issues,
            'suggestions' => [],
            'dpv_match_code' => $dpv_match,
            'latitude' => $result['metadata']['latitude'] ?? null,
            'longitude' => $result['metadata']['longitude'] ?? null,
        ];
    }

    /**
     * Google Geocoding API for validation
     */
    private function google_validate(array $address): array {
        $api_key = $this->settings['google_api_key'] ?? '';
        if (empty($api_key)) {
            return [
                'valid' => true,
                'standardized' => $address,
                'issues' => ['Google validation not configured'],
                'suggestions' => [],
            ];
        }

        $address_string = implode(', ', array_filter([
            $address['street'],
            $address['city'],
            $address['state'],
            $address['zip'],
        ]));

        $url = 'https://maps.googleapis.com/maps/api/geocode/json';
        $params = [
            'address' => $address_string,
            'components' => 'country:US',
            'key' => $api_key,
        ];

        $response = wp_remote_get($url . '?' . http_build_query($params));

        if (is_wp_error($response)) {
            return [
                'valid' => true,
                'standardized' => $address,
                'issues' => ['Google service unavailable'],
                'suggestions' => [],
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($body['status'] !== 'OK' || empty($body['results'])) {
            return [
                'valid' => false,
                'standardized' => $address,
                'issues' => ['Address not found'],
                'suggestions' => [],
            ];
        }

        $result = $body['results'][0];
        $parsed = $this->parse_google_place($result);

        // Check for partial matches
        $issues = [];
        if (isset($result['partial_match']) && $result['partial_match']) {
            $issues[] = 'Address is a partial match - please verify';
        }

        // Check location type
        $location_type = $result['geometry']['location_type'] ?? '';
        if ($location_type === 'APPROXIMATE') {
            $issues[] = 'Address location is approximate';
        }

        return [
            'valid' => empty($issues),
            'standardized' => $parsed,
            'issues' => $issues,
            'suggestions' => [],
            'latitude' => $result['geometry']['location']['lat'] ?? null,
            'longitude' => $result['geometry']['location']['lng'] ?? null,
            'location_type' => $location_type,
        ];
    }

    /**
     * Parse Google Places result into address components
     */
    private function parse_google_place(array $result): array {
        $components = [];

        foreach ($result['address_components'] ?? [] as $component) {
            $types = $component['types'];

            if (in_array('street_number', $types)) {
                $components['street_number'] = $component['long_name'];
            }
            if (in_array('route', $types)) {
                $components['route'] = $component['short_name'];
            }
            if (in_array('locality', $types)) {
                $components['city'] = $component['long_name'];
            }
            if (in_array('administrative_area_level_1', $types)) {
                $components['state'] = $component['short_name'];
            }
            if (in_array('postal_code', $types)) {
                $components['zip'] = $component['long_name'];
            }
            if (in_array('postal_code_suffix', $types)) {
                $components['zip_suffix'] = $component['long_name'];
            }
            if (in_array('subpremise', $types)) {
                $components['unit'] = $component['long_name'];
            }
        }

        // Build street address
        $street = trim(
            ($components['street_number'] ?? '') . ' ' .
            ($components['route'] ?? '')
        );

        if (!empty($components['unit'])) {
            $street .= ' #' . $components['unit'];
        }

        return [
            'street' => $street,
            'city' => $components['city'] ?? '',
            'state' => $components['state'] ?? '',
            'zip' => ($components['zip'] ?? '') .
                    (!empty($components['zip_suffix']) ? '-' . $components['zip_suffix'] : ''),
            'formatted_address' => $result['formatted_address'] ?? '',
            'latitude' => $result['geometry']['location']['lat'] ?? null,
            'longitude' => $result['geometry']['location']['lng'] ?? null,
        ];
    }

    /**
     * Generate cache key for address
     */
    private function get_cache_key(array $address): string {
        return md5(strtolower(implode('|', [
            $address['street'] ?? '',
            $address['city'] ?? '',
            $address['state'] ?? '',
            $address['zip'] ?? '',
        ])));
    }

    /**
     * Register admin settings
     */
    public function register_settings(): void {
        register_setting('isf_address_settings', 'isf_address_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings(array $input): array {
        return [
            'provider' => in_array($input['provider'] ?? '', [
                self::PROVIDER_GOOGLE,
                self::PROVIDER_USPS,
                self::PROVIDER_SMARTY,
                self::PROVIDER_NONE,
            ]) ? $input['provider'] : self::PROVIDER_NONE,
            'google_api_key' => sanitize_text_field($input['google_api_key'] ?? ''),
            'usps_user_id' => sanitize_text_field($input['usps_user_id'] ?? ''),
            'smarty_auth_id' => sanitize_text_field($input['smarty_auth_id'] ?? ''),
            'smarty_auth_token' => sanitize_text_field($input['smarty_auth_token'] ?? ''),
            'enable_autocomplete' => !empty($input['enable_autocomplete']),
            'enable_validation' => !empty($input['enable_validation']),
            'require_valid_address' => !empty($input['require_valid_address']),
            'cache_duration' => absint($input['cache_duration'] ?? 86400),
        ];
    }

    /**
     * Get current provider
     */
    public function get_provider(): string {
        return $this->settings['provider'] ?? self::PROVIDER_NONE;
    }

    /**
     * Check if autocomplete is enabled
     */
    public function is_autocomplete_enabled(): bool {
        return $this->settings['enable_autocomplete'] &&
               $this->settings['provider'] !== self::PROVIDER_NONE;
    }

    /**
     * Check if validation is enabled
     */
    public function is_validation_enabled(): bool {
        return $this->settings['enable_validation'] &&
               $this->settings['provider'] !== self::PROVIDER_NONE;
    }

    /**
     * Check if valid address is required
     */
    public function is_valid_address_required(): bool {
        return $this->settings['require_valid_address'];
    }

    /**
     * Get client-side configuration
     */
    public function get_client_config(): array {
        return [
            'provider' => $this->get_provider(),
            'autocomplete_enabled' => $this->is_autocomplete_enabled(),
            'validation_enabled' => $this->is_validation_enabled(),
            'require_valid' => $this->is_valid_address_required(),
            'api_url' => rest_url('isf/v1/address'),
            // Don't expose API keys - use REST endpoints instead
        ];
    }

    /**
     * Log an error with context
     *
     * @param string $message Error message
     * @param array  $context Additional context data
     * @param string $level   Error level (error, warning, info)
     */
    private function log_error(string $message, array $context = [], string $level = 'error'): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = sprintf(
                '[FormFlow AddressValidator] [%s] %s | Context: %s',
                strtoupper($level),
                $message,
                wp_json_encode($context)
            );
            error_log($log_message);
        }

        /**
         * Fires when an address validation error occurs
         *
         * @param string $message Error message
         * @param array  $context Additional context
         * @param string $level   Error level
         */
        do_action('isf_address_validator_error', $message, $context, $level);
    }

    /**
     * Check rate limit for API calls
     *
     * @param string $provider The API provider being called
     * @return bool True if within rate limit, false if exceeded
     */
    private function check_rate_limit(string $provider): bool {
        $transient_key = 'isf_rate_limit_' . $provider;
        $current = get_transient($transient_key);

        if ($current === false) {
            // Start new rate limit window
            set_transient($transient_key, 1, self::RATE_LIMIT_WINDOW);
            return true;
        }

        if ($current >= self::RATE_LIMIT_REQUESTS) {
            $this->log_error('Rate limit exceeded', [
                'provider' => $provider,
                'limit' => self::RATE_LIMIT_REQUESTS,
                'window' => self::RATE_LIMIT_WINDOW,
            ], 'warning');

            /**
             * Fires when address validation rate limit is exceeded
             *
             * @param string $provider The provider that was rate limited
             * @param int    $limit    The rate limit that was exceeded
             */
            do_action('isf_address_rate_limit_exceeded', $provider, self::RATE_LIMIT_REQUESTS);

            return false;
        }

        // Increment counter
        set_transient($transient_key, $current + 1, self::RATE_LIMIT_WINDOW);
        return true;
    }

    /**
     * Check if circuit is open (service is down)
     *
     * @param string $provider The API provider to check
     * @return bool True if circuit is open (should not call API), false if closed
     */
    private function is_circuit_open(string $provider): bool {
        $transient_key = 'isf_circuit_' . $provider;
        $circuit_state = get_transient($transient_key);

        if ($circuit_state === 'open') {
            $this->log_error('Circuit breaker open - skipping API call', [
                'provider' => $provider,
            ], 'info');
            return true;
        }

        return false;
    }

    /**
     * Record an API failure for circuit breaker
     *
     * @param string $provider The API provider that failed
     * @param string $error    The error message
     */
    private function record_failure(string $provider, string $error): void {
        $count_key = 'isf_failures_' . $provider;
        $current = get_transient($count_key) ?: 0;
        $current++;

        $this->log_error('API call failed', [
            'provider' => $provider,
            'error' => $error,
            'failure_count' => $current,
            'threshold' => self::CIRCUIT_FAILURE_THRESHOLD,
        ]);

        if ($current >= self::CIRCUIT_FAILURE_THRESHOLD) {
            // Open the circuit
            set_transient('isf_circuit_' . $provider, 'open', self::CIRCUIT_RECOVERY_TIME);
            delete_transient($count_key);

            $this->log_error('Circuit breaker opened', [
                'provider' => $provider,
                'recovery_time' => self::CIRCUIT_RECOVERY_TIME,
            ], 'warning');

            /**
             * Fires when a provider's circuit breaker opens
             *
             * @param string $provider      The provider that failed
             * @param int    $recovery_time Seconds until recovery attempt
             */
            do_action('isf_circuit_breaker_opened', $provider, self::CIRCUIT_RECOVERY_TIME);
        } else {
            set_transient($count_key, $current, self::CIRCUIT_RECOVERY_TIME);
        }
    }

    /**
     * Record a successful API call (resets failure count)
     *
     * @param string $provider The API provider that succeeded
     */
    private function record_success(string $provider): void {
        delete_transient('isf_failures_' . $provider);
        delete_transient('isf_circuit_' . $provider);
    }

    /**
     * Safe API request wrapper with rate limiting and circuit breaker
     *
     * @param string   $provider  The API provider
     * @param callable $request   The request callback
     * @param mixed    $fallback  Fallback value on failure
     * @return mixed The API response or fallback
     */
    private function safe_api_request(string $provider, callable $request, $fallback = null) {
        // Check circuit breaker
        if ($this->is_circuit_open($provider)) {
            return $fallback;
        }

        // Check rate limit
        if (!$this->check_rate_limit($provider)) {
            return $fallback;
        }

        try {
            $result = $request();

            if (is_wp_error($result)) {
                $this->record_failure($provider, $result->get_error_message());
                return $fallback;
            }

            $this->record_success($provider);
            return $result;

        } catch (\Throwable $e) {
            $this->record_failure($provider, $e->getMessage());
            $this->log_error('Exception during API request', [
                'provider' => $provider,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $fallback;
        }
    }

    /**
     * Get service health status
     *
     * @return array Health status for all providers
     */
    public function get_health_status(): array {
        $providers = [self::PROVIDER_GOOGLE, self::PROVIDER_USPS, self::PROVIDER_SMARTY];
        $status = [];

        foreach ($providers as $provider) {
            $circuit_open = $this->is_circuit_open($provider);
            $failure_count = get_transient('isf_failures_' . $provider) ?: 0;
            $rate_count = get_transient('isf_rate_limit_' . $provider) ?: 0;

            $status[$provider] = [
                'healthy' => !$circuit_open,
                'circuit_state' => $circuit_open ? 'open' : 'closed',
                'failure_count' => $failure_count,
                'rate_limit_used' => $rate_count,
                'rate_limit_max' => self::RATE_LIMIT_REQUESTS,
            ];
        }

        return $status;
    }
}
