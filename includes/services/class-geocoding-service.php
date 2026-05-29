<?php
/**
 * Geocoding Service
 *
 * Provides geocoding and service territory validation.
 * Converts addresses to coordinates and checks if they fall within
 * defined utility service territories.
 *
 * @package FormFlow
 * @since 2.6.0
 */

namespace ISF\Services;

defined('ABSPATH') || exit;

class GeocodingService {

    /**
     * Rate limiting constants
     */
    const RATE_LIMIT_REQUESTS = 100;
    const RATE_LIMIT_WINDOW = 60;

    /**
     * Circuit breaker constants
     */
    const CIRCUIT_FAILURE_THRESHOLD = 5;
    const CIRCUIT_RECOVERY_TIME = 300;

    /**
     * Singleton instance
     */
    private static ?GeocodingService $instance = null;

    /**
     * Address validator instance
     */
    private ?AddressValidator $address_validator = null;

    /**
     * Service territory definitions
     */
    private array $territories = [];

    /**
     * Get singleton instance
     */
    public static function instance(): GeocodingService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->address_validator = AddressValidator::instance();
        $this->load_territories();
    }

    /**
     * Initialize hooks
     */
    public function init(): void {
        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        register_rest_route('isf/v1', '/geocode', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_geocode'],
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

        register_rest_route('isf/v1', '/territory/check', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_check_territory'],
            'permission_callback' => ['\ISF\Security', 'rate_limit_public'],
            'args' => [
                'latitude' => [
                    'required' => false,
                    'type' => 'number',
                ],
                'longitude' => [
                    'required' => false,
                    'type' => 'number',
                ],
                'street' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'city' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'state' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'zip' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'utility' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route('isf/v1', '/territories', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_territories'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('isf/v1', '/territory', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_save_territory'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
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
     * REST callback: Geocode an address
     */
    public function rest_geocode(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_error = $this->check_rest_rate_limit('isf_geocode', 20, 60);
        if ($rate_limit_error !== null) {
            return $rate_limit_error;
        }

        $address = [
            'street' => $request->get_param('street'),
            'city' => $request->get_param('city'),
            'state' => $request->get_param('state'),
            'zip' => $request->get_param('zip'),
        ];

        $result = $this->geocode_address($address);

        if (!$result) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Unable to geocode address',
            ], 404);
        }

        return new \WP_REST_Response([
            'success' => true,
            'latitude' => $result['latitude'],
            'longitude' => $result['longitude'],
            'formatted_address' => $result['formatted_address'] ?? null,
        ], 200);
    }

    /**
     * REST callback: Check service territory
     */
    public function rest_check_territory(\WP_REST_Request $request): \WP_REST_Response {
        $rate_limit_error = $this->check_rest_rate_limit('isf_territory_check', 20, 60);
        if ($rate_limit_error !== null) {
            return $rate_limit_error;
        }

        $latitude = $request->get_param('latitude');
        $longitude = $request->get_param('longitude');
        $utility = $request->get_param('utility');

        // If no coordinates provided, geocode the address
        if (!$latitude || !$longitude) {
            $address = [
                'street' => $request->get_param('street'),
                'city' => $request->get_param('city'),
                'state' => $request->get_param('state'),
                'zip' => $request->get_param('zip'),
            ];

            if (empty($address['street']) || empty($address['city'])) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Address or coordinates required',
                ], 400);
            }

            $geocode_result = $this->geocode_address($address);
            if (!$geocode_result) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Unable to geocode address',
                ], 404);
            }

            $latitude = $geocode_result['latitude'];
            $longitude = $geocode_result['longitude'];
        }

        $result = $this->check_service_territory($latitude, $longitude, $utility);

        return new \WP_REST_Response([
            'success' => true,
            'in_territory' => $result['in_territory'],
            'matching_territories' => $result['matching_territories'],
            'latitude' => $latitude,
            'longitude' => $longitude,
        ], 200);
    }

    /**
     * REST callback: Get all territories
     */
    public function rest_get_territories(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'territories' => $this->territories,
        ], 200);
    }

    /**
     * REST callback: Save territory
     */
    public function rest_save_territory(\WP_REST_Request $request): \WP_REST_Response {
        $territory_id = $request->get_param('id');
        $territory_data = [
            'name' => sanitize_text_field($request->get_param('name')),
            'utility' => sanitize_text_field($request->get_param('utility')),
            'type' => sanitize_text_field($request->get_param('type')),
            'states' => array_map('sanitize_text_field', (array)$request->get_param('states')),
            'zip_codes' => array_map('sanitize_text_field', (array)$request->get_param('zip_codes')),
            'polygon' => $request->get_param('polygon'),
            'center' => $request->get_param('center'),
            'radius' => floatval($request->get_param('radius')),
        ];

        $saved = $this->save_territory($territory_id, $territory_data);

        if (!$saved) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to save territory',
            ], 500);
        }

        return new \WP_REST_Response([
            'success' => true,
            'territory_id' => $saved,
        ], 200);
    }

    /**
     * Geocode an address to coordinates
     */
    public function geocode_address(array $address): ?array {
        // First, try to get coordinates from address validation
        try {
            $validation = $this->address_validator->validate_address($address);

            if (!empty($validation['latitude']) && !empty($validation['longitude'])) {
                return [
                    'latitude' => $validation['latitude'],
                    'longitude' => $validation['longitude'],
                    'formatted_address' => $validation['standardized']['formatted_address'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            $this->log_error('Address validation failed during geocoding', [
                'error' => $e->getMessage(),
                'address' => $address,
            ]);
        }

        // Check circuit breaker
        if ($this->is_circuit_open()) {
            $this->log_error('Geocoding skipped - circuit open', [], 'info');
            return null;
        }

        // Check rate limit
        if (!$this->check_rate_limit()) {
            return null;
        }

        // If validation didn't return coordinates, use Google Geocoding directly
        $settings = get_option('isf_address_settings', []);
        $api_key = $settings['google_api_key'] ?? '';

        if (empty($api_key)) {
            $this->log_error('Google API key not configured for geocoding', [], 'warning');
            return null;
        }

        $address_string = implode(', ', array_filter([
            $address['street'] ?? '',
            $address['city'] ?? '',
            $address['state'] ?? '',
            $address['zip'] ?? '',
        ]));

        if (empty(trim($address_string))) {
            $this->log_error('Empty address provided for geocoding', ['address' => $address], 'warning');
            return null;
        }

        // Check cache first
        $cache_key = 'isf_geocode_' . md5($address_string);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $url = 'https://maps.googleapis.com/maps/api/geocode/json';
        $params = [
            'address' => $address_string,
            'components' => 'country:US',
            'key' => $api_key,
        ];

        try {
            $response = wp_remote_get($url . '?' . http_build_query($params));

            if (is_wp_error($response)) {
                $this->record_failure($response->get_error_message());
                return null;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (!isset($body['status'])) {
                $this->record_failure('Invalid API response');
                return null;
            }

            if ($body['status'] !== 'OK') {
                if ($body['status'] !== 'ZERO_RESULTS') {
                    $this->log_error('Google Geocoding API error', [
                        'status' => $body['status'],
                        'error_message' => $body['error_message'] ?? 'Unknown',
                        'address' => $address_string,
                    ]);

                    // Only count as failure for server errors, not client errors
                    if (in_array($body['status'], ['OVER_QUERY_LIMIT', 'REQUEST_DENIED', 'UNKNOWN_ERROR'])) {
                        $this->record_failure($body['status']);
                    }
                }
                return null;
            }

            if (empty($body['results'])) {
                return null;
            }

            $this->record_success();

            $result = [
                'latitude' => $body['results'][0]['geometry']['location']['lat'],
                'longitude' => $body['results'][0]['geometry']['location']['lng'],
                'formatted_address' => $body['results'][0]['formatted_address'],
            ];

            // Cache for 30 days
            set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);

            return $result;

        } catch (\Throwable $e) {
            $this->record_failure($e->getMessage());
            $this->log_error('Exception during geocoding', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return null;
        }
    }

    /**
     * Check if coordinates are within a service territory
     */
    public function check_service_territory(float $latitude, float $longitude, ?string $utility = null): array {
        $matching = [];

        foreach ($this->territories as $territory_id => $territory) {
            // Filter by utility if specified
            if ($utility && $territory['utility'] !== $utility) {
                continue;
            }

            $in_territory = $this->is_point_in_territory($latitude, $longitude, $territory);

            if ($in_territory) {
                $matching[] = [
                    'id' => $territory_id,
                    'name' => $territory['name'],
                    'utility' => $territory['utility'],
                ];
            }
        }

        return [
            'in_territory' => !empty($matching),
            'matching_territories' => $matching,
        ];
    }

    /**
     * Check if a point is within a territory
     */
    private function is_point_in_territory(float $lat, float $lng, array $territory): bool {
        $type = $territory['type'] ?? 'state';

        switch ($type) {
            case 'state':
                return $this->is_in_states($lat, $lng, $territory['states'] ?? []);

            case 'zip':
                // For ZIP code territories, we need to do a reverse geocode
                return $this->is_in_zip_codes($lat, $lng, $territory['zip_codes'] ?? []);

            case 'polygon':
                return $this->is_point_in_polygon($lat, $lng, $territory['polygon'] ?? []);

            case 'radius':
                return $this->is_within_radius(
                    $lat, $lng,
                    $territory['center']['lat'] ?? 0,
                    $territory['center']['lng'] ?? 0,
                    $territory['radius'] ?? 0
                );

            default:
                return false;
        }
    }

    /**
     * Check if coordinates are within specified states
     */
    private function is_in_states(float $lat, float $lng, array $states): bool {
        if (empty($states)) {
            return false;
        }

        // State bounding boxes (approximate)
        $state_bounds = $this->get_state_bounds();

        foreach ($states as $state) {
            $state = strtoupper($state);
            if (isset($state_bounds[$state])) {
                $bounds = $state_bounds[$state];
                if ($lat >= $bounds['south'] && $lat <= $bounds['north'] &&
                    $lng >= $bounds['west'] && $lng <= $bounds['east']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if coordinates are within specified ZIP codes
     */
    private function is_in_zip_codes(float $lat, float $lng, array $zip_codes): bool {
        if (empty($zip_codes)) {
            return false;
        }

        // Reverse geocode to get ZIP
        $settings = get_option('isf_address_settings', []);
        $api_key = $settings['google_api_key'] ?? '';

        if (empty($api_key)) {
            return false;
        }

        $cache_key = 'isf_reverse_geo_' . md5($lat . ',' . $lng);
        $cached = get_transient($cache_key);

        if ($cached === false) {
            $url = 'https://maps.googleapis.com/maps/api/geocode/json';
            $params = [
                'latlng' => $lat . ',' . $lng,
                'key' => $api_key,
            ];

            $response = wp_remote_get($url . '?' . http_build_query($params));

            if (is_wp_error($response)) {
                return false;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($body['status'] !== 'OK' || empty($body['results'])) {
                return false;
            }

            // Extract ZIP code
            $zip = null;
            foreach ($body['results'][0]['address_components'] ?? [] as $component) {
                if (in_array('postal_code', $component['types'])) {
                    $zip = $component['long_name'];
                    break;
                }
            }

            $cached = $zip;
            set_transient($cache_key, $cached, 30 * DAY_IN_SECONDS);
        }

        if (!$cached) {
            return false;
        }

        // Check if ZIP is in list (support wildcards like "208*")
        foreach ($zip_codes as $zip_pattern) {
            if (strpos($zip_pattern, '*') !== false) {
                $pattern = str_replace('*', '', $zip_pattern);
                if (strpos($cached, $pattern) === 0) {
                    return true;
                }
            } elseif ($cached === $zip_pattern || substr($cached, 0, 5) === $zip_pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a point is within a polygon
     */
    private function is_point_in_polygon(float $lat, float $lng, array $polygon): bool {
        if (count($polygon) < 3) {
            return false;
        }

        $inside = false;
        $n = count($polygon);
        $j = $n - 1;

        for ($i = 0; $i < $n; $i++) {
            $xi = $polygon[$i]['lng'];
            $yi = $polygon[$i]['lat'];
            $xj = $polygon[$j]['lng'];
            $yj = $polygon[$j]['lat'];

            if ((($yi > $lat) !== ($yj > $lat)) &&
                ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }

            $j = $i;
        }

        return $inside;
    }

    /**
     * Check if a point is within a radius of a center point
     */
    private function is_within_radius(float $lat, float $lng, float $center_lat, float $center_lng, float $radius_miles): bool {
        $distance = $this->haversine_distance($lat, $lng, $center_lat, $center_lng);
        return $distance <= $radius_miles;
    }

    /**
     * Calculate distance between two points using Haversine formula
     */
    private function haversine_distance(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth_radius = 3959; // Miles

        $lat1_rad = deg2rad($lat1);
        $lat2_rad = deg2rad($lat2);
        $delta_lat = deg2rad($lat2 - $lat1);
        $delta_lng = deg2rad($lng2 - $lng1);

        $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
             cos($lat1_rad) * cos($lat2_rad) *
             sin($delta_lng / 2) * sin($delta_lng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earth_radius * $c;
    }

    /**
     * Load service territories from database
     */
    private function load_territories(): void {
        $this->territories = get_option('isf_service_territories', $this->get_default_territories());
    }

    /**
     * Save a service territory
     */
    public function save_territory(?string $territory_id, array $data): ?string {
        if (!$territory_id) {
            $territory_id = 'territory_' . wp_generate_password(8, false);
        }

        $this->territories[$territory_id] = $data;
        update_option('isf_service_territories', $this->territories);

        return $territory_id;
    }

    /**
     * Delete a service territory
     */
    public function delete_territory(string $territory_id): bool {
        if (!isset($this->territories[$territory_id])) {
            return false;
        }

        unset($this->territories[$territory_id]);
        update_option('isf_service_territories', $this->territories);

        return true;
    }

    /**
     * Get default service territories (common utilities)
     */
    private function get_default_territories(): array {
        return [
            'pepco_dc' => [
                'name' => 'Pepco DC',
                'utility' => 'pepco',
                'type' => 'state',
                'states' => ['DC'],
            ],
            'pepco_md' => [
                'name' => 'Pepco MD',
                'utility' => 'pepco',
                'type' => 'zip',
                'zip_codes' => ['207*', '208*', '209*'],
            ],
            'delmarva_de' => [
                'name' => 'Delmarva Power DE',
                'utility' => 'delmarva',
                'type' => 'state',
                'states' => ['DE'],
            ],
            'delmarva_md' => [
                'name' => 'Delmarva Power MD (Eastern Shore)',
                'utility' => 'delmarva',
                'type' => 'zip',
                'zip_codes' => ['216*', '218*', '219*'],
            ],
            'bge' => [
                'name' => 'BGE',
                'utility' => 'bge',
                'type' => 'zip',
                'zip_codes' => ['210*', '211*', '212*'],
            ],
            'dominion_va' => [
                'name' => 'Dominion Energy VA',
                'utility' => 'dominion',
                'type' => 'state',
                'states' => ['VA'],
            ],
        ];
    }

    /**
     * Get state bounding boxes
     */
    private function get_state_bounds(): array {
        return [
            'AL' => ['north' => 35.008, 'south' => 30.221, 'east' => -84.889, 'west' => -88.473],
            'AK' => ['north' => 71.538, 'south' => 51.214, 'east' => -129.98, 'west' => -179.15],
            'AZ' => ['north' => 37.004, 'south' => 31.332, 'east' => -109.045, 'west' => -114.818],
            'AR' => ['north' => 36.500, 'south' => 33.004, 'east' => -89.644, 'west' => -94.618],
            'CA' => ['north' => 42.009, 'south' => 32.534, 'east' => -114.131, 'west' => -124.409],
            'CO' => ['north' => 41.003, 'south' => 36.993, 'east' => -102.042, 'west' => -109.060],
            'CT' => ['north' => 42.050, 'south' => 40.987, 'east' => -71.787, 'west' => -73.728],
            'DC' => ['north' => 38.996, 'south' => 38.791, 'east' => -76.909, 'west' => -77.119],
            'DE' => ['north' => 39.839, 'south' => 38.451, 'east' => -75.049, 'west' => -75.788],
            'FL' => ['north' => 31.001, 'south' => 24.396, 'east' => -79.974, 'west' => -87.635],
            'GA' => ['north' => 35.001, 'south' => 30.357, 'east' => -80.839, 'west' => -85.605],
            'HI' => ['north' => 22.235, 'south' => 18.910, 'east' => -154.806, 'west' => -160.074],
            'ID' => ['north' => 49.001, 'south' => 41.988, 'east' => -111.043, 'west' => -117.243],
            'IL' => ['north' => 42.508, 'south' => 36.970, 'east' => -87.495, 'west' => -91.513],
            'IN' => ['north' => 41.761, 'south' => 37.772, 'east' => -84.784, 'west' => -88.098],
            'IA' => ['north' => 43.501, 'south' => 40.375, 'east' => -90.140, 'west' => -96.639],
            'KS' => ['north' => 40.003, 'south' => 36.993, 'east' => -94.588, 'west' => -102.052],
            'KY' => ['north' => 39.147, 'south' => 36.497, 'east' => -81.965, 'west' => -89.571],
            'LA' => ['north' => 33.019, 'south' => 28.926, 'east' => -88.817, 'west' => -94.043],
            'ME' => ['north' => 47.460, 'south' => 42.977, 'east' => -66.950, 'west' => -71.083],
            'MD' => ['north' => 39.723, 'south' => 37.912, 'east' => -75.049, 'west' => -79.487],
            'MA' => ['north' => 42.887, 'south' => 41.237, 'east' => -69.928, 'west' => -73.508],
            'MI' => ['north' => 48.190, 'south' => 41.696, 'east' => -82.413, 'west' => -90.418],
            'MN' => ['north' => 49.384, 'south' => 43.499, 'east' => -89.489, 'west' => -97.239],
            'MS' => ['north' => 34.996, 'south' => 30.174, 'east' => -88.098, 'west' => -91.655],
            'MO' => ['north' => 40.613, 'south' => 35.996, 'east' => -89.098, 'west' => -95.774],
            'MT' => ['north' => 49.001, 'south' => 44.358, 'east' => -104.039, 'west' => -116.050],
            'NE' => ['north' => 43.001, 'south' => 40.001, 'east' => -95.308, 'west' => -104.053],
            'NV' => ['north' => 42.002, 'south' => 35.002, 'east' => -114.040, 'west' => -120.005],
            'NH' => ['north' => 45.306, 'south' => 42.697, 'east' => -70.703, 'west' => -72.557],
            'NJ' => ['north' => 41.357, 'south' => 38.928, 'east' => -73.893, 'west' => -75.559],
            'NM' => ['north' => 37.000, 'south' => 31.332, 'east' => -103.002, 'west' => -109.050],
            'NY' => ['north' => 45.016, 'south' => 40.496, 'east' => -71.856, 'west' => -79.762],
            'NC' => ['north' => 36.588, 'south' => 33.844, 'east' => -75.460, 'west' => -84.322],
            'ND' => ['north' => 49.001, 'south' => 45.935, 'east' => -96.554, 'west' => -104.049],
            'OH' => ['north' => 42.327, 'south' => 38.403, 'east' => -80.519, 'west' => -84.820],
            'OK' => ['north' => 37.001, 'south' => 33.616, 'east' => -94.431, 'west' => -103.002],
            'OR' => ['north' => 46.292, 'south' => 41.991, 'east' => -116.463, 'west' => -124.566],
            'PA' => ['north' => 42.269, 'south' => 39.720, 'east' => -74.690, 'west' => -80.519],
            'RI' => ['north' => 42.019, 'south' => 41.146, 'east' => -71.120, 'west' => -71.862],
            'SC' => ['north' => 35.215, 'south' => 32.034, 'east' => -78.541, 'west' => -83.354],
            'SD' => ['north' => 45.945, 'south' => 42.481, 'east' => -96.436, 'west' => -104.058],
            'TN' => ['north' => 36.678, 'south' => 34.983, 'east' => -81.647, 'west' => -90.310],
            'TX' => ['north' => 36.500, 'south' => 25.837, 'east' => -93.508, 'west' => -106.646],
            'UT' => ['north' => 42.001, 'south' => 36.998, 'east' => -109.041, 'west' => -114.053],
            'VT' => ['north' => 45.017, 'south' => 42.727, 'east' => -71.465, 'west' => -73.438],
            'VA' => ['north' => 39.466, 'south' => 36.541, 'east' => -75.242, 'west' => -83.675],
            'WA' => ['north' => 49.002, 'south' => 45.543, 'east' => -116.915, 'west' => -124.849],
            'WV' => ['north' => 40.638, 'south' => 37.202, 'east' => -77.719, 'west' => -82.644],
            'WI' => ['north' => 47.080, 'south' => 42.492, 'east' => -86.250, 'west' => -92.889],
            'WY' => ['north' => 45.006, 'south' => 40.995, 'east' => -104.052, 'west' => -111.056],
        ];
    }

    /**
     * Get territories for a specific utility
     */
    public function get_utility_territories(string $utility): array {
        return array_filter($this->territories, function($territory) use ($utility) {
            return $territory['utility'] === $utility;
        });
    }

    /**
     * Validate address is in service territory
     */
    public function validate_service_address(array $address, string $utility): array {
        // First validate the address
        $validation = $this->address_validator->validate_address($address);

        if (!$validation['valid'] && $this->address_validator->is_valid_address_required()) {
            return [
                'valid' => false,
                'in_territory' => false,
                'message' => 'Please enter a valid address.',
                'issues' => $validation['issues'],
            ];
        }

        // Geocode the address
        $geocode = $this->geocode_address($validation['standardized']);

        if (!$geocode) {
            return [
                'valid' => true,
                'in_territory' => null,
                'message' => 'Unable to verify service territory.',
                'standardized' => $validation['standardized'],
            ];
        }

        // Check service territory
        $territory_check = $this->check_service_territory(
            $geocode['latitude'],
            $geocode['longitude'],
            $utility
        );

        return [
            'valid' => true,
            'in_territory' => $territory_check['in_territory'],
            'message' => $territory_check['in_territory']
                ? 'Address is within service territory.'
                : 'Address appears to be outside of the service territory.',
            'standardized' => $validation['standardized'],
            'coordinates' => [
                'latitude' => $geocode['latitude'],
                'longitude' => $geocode['longitude'],
            ],
            'matching_territories' => $territory_check['matching_territories'],
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
                '[FormFlow GeocodingService] [%s] %s | Context: %s',
                strtoupper($level),
                $message,
                wp_json_encode($context)
            );
            error_log($log_message);
        }

        /**
         * Fires when a geocoding error occurs
         *
         * @param string $message Error message
         * @param array  $context Additional context
         * @param string $level   Error level
         */
        do_action('isf_geocoding_error', $message, $context, $level);
    }

    /**
     * Check rate limit for API calls
     *
     * @return bool True if within rate limit
     */
    private function check_rate_limit(): bool {
        $transient_key = 'isf_geocode_rate_limit';
        $current = get_transient($transient_key);

        if ($current === false) {
            set_transient($transient_key, 1, self::RATE_LIMIT_WINDOW);
            return true;
        }

        if ($current >= self::RATE_LIMIT_REQUESTS) {
            $this->log_error('Geocoding rate limit exceeded', [
                'limit' => self::RATE_LIMIT_REQUESTS,
                'window' => self::RATE_LIMIT_WINDOW,
            ], 'warning');

            do_action('isf_geocoding_rate_limit_exceeded', self::RATE_LIMIT_REQUESTS);
            return false;
        }

        set_transient($transient_key, $current + 1, self::RATE_LIMIT_WINDOW);
        return true;
    }

    /**
     * Check if circuit breaker is open
     *
     * @return bool True if circuit is open (should skip API)
     */
    private function is_circuit_open(): bool {
        return get_transient('isf_geocode_circuit') === 'open';
    }

    /**
     * Record API failure for circuit breaker
     *
     * @param string $error Error message
     */
    private function record_failure(string $error): void {
        $count_key = 'isf_geocode_failures';
        $current = get_transient($count_key) ?: 0;
        $current++;

        $this->log_error('Geocoding API failed', [
            'error' => $error,
            'failure_count' => $current,
            'threshold' => self::CIRCUIT_FAILURE_THRESHOLD,
        ]);

        if ($current >= self::CIRCUIT_FAILURE_THRESHOLD) {
            set_transient('isf_geocode_circuit', 'open', self::CIRCUIT_RECOVERY_TIME);
            delete_transient($count_key);

            $this->log_error('Geocoding circuit breaker opened', [
                'recovery_time' => self::CIRCUIT_RECOVERY_TIME,
            ], 'warning');

            do_action('isf_geocoding_circuit_opened', self::CIRCUIT_RECOVERY_TIME);
        } else {
            set_transient($count_key, $current, self::CIRCUIT_RECOVERY_TIME);
        }
    }

    /**
     * Record successful API call
     */
    private function record_success(): void {
        delete_transient('isf_geocode_failures');
        delete_transient('isf_geocode_circuit');
    }

    /**
     * Get service health status
     *
     * @return array Health status
     */
    public function get_health_status(): array {
        $circuit_open = $this->is_circuit_open();
        $failure_count = get_transient('isf_geocode_failures') ?: 0;
        $rate_count = get_transient('isf_geocode_rate_limit') ?: 0;

        return [
            'healthy' => !$circuit_open,
            'circuit_state' => $circuit_open ? 'open' : 'closed',
            'failure_count' => $failure_count,
            'rate_limit_used' => $rate_count,
            'rate_limit_max' => self::RATE_LIMIT_REQUESTS,
            'address_validator_health' => $this->address_validator->get_health_status(),
        ];
    }
}
