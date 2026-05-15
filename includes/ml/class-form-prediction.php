<?php
namespace ISF\ML;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Form Prediction
 *
 * Integrates with the ML microservice to predict form completion likelihood
 * and receive simplification recommendations.
 */


class FormPrediction {

    /**
     * ML service base URL
     */
    private string $service_url = '';

    /**
     * ML service API key
     */
    private string $api_key = '';

    /**
     * Transient cache TTL in seconds (15 minutes)
     */
    private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

    /**
     * Health check cache TTL in seconds (2 minutes)
     */
    private const HEALTH_CACHE_TTL = 2 * MINUTE_IN_SECONDS;

    /**
     * Constructor
     *
     * Reads ML service configuration from peanut_ml_settings option.
     */
    public function __construct() {
        $settings = get_option('peanut_ml_settings', []);

        $this->service_url = $settings['ml_service_url'] ?? 'http://127.0.0.1:8100';
        $this->api_key = $settings['ml_api_key'] ?? '';

        // Ensure URL doesn't have trailing slash
        $this->service_url = rtrim($this->service_url, '/');
    }

    /**
     * Predict form completion probability
     *
     * Sends form data to the ML service and receives completion prediction
     * along with recommended simplifications.
     *
     * @param array $form_data Form field data to analyze
     * @return array|null Prediction result with probability and recommendations, or null on failure
     *
     * @example
     * $prediction = $this->predict_completion([
     *     'fields' => ['name', 'email', 'account_number'],
     *     'field_count' => 3,
     *     'has_required_fields' => true,
     *     'avg_field_length' => 50,
     * ]);
     * // Returns: [
     * //     'completion_probability' => 0.87,
     * //     'risk_score' => 0.13,
     * //     'recommendations' => [
     * //         'Consider reducing field count',
     * //         'Add progress indicator',
     * //     ],
     * // ]
     */
    public function predict_completion(array $form_data): ?array {
        if (!$this->api_key) {
            error_log('[FormFlow ML] ML API key not configured');
            return null;
        }

        // Check cache first
        $cache_key = 'isf_ml_prediction_' . md5(json_encode($form_data));
        $cached_result = get_transient($cache_key);
        if ($cached_result !== false) {
            return $cached_result;
        }

        $result = $this->make_request('POST', '/form/predict', $form_data);

        if ($result) {
            // Cache the result
            set_transient($cache_key, $result, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Train the ML model
     *
     * Triggers a retraining of the form completion model using accumulated
     * historical form submission data.
     *
     * @return bool True if training was triggered successfully, false otherwise
     */
    public function train_model(): bool {
        if (!$this->api_key) {
            error_log('[FormFlow ML] ML API key not configured, cannot train model');
            return false;
        }

        $result = $this->make_request('POST', '/form/train', [
            'timestamp' => current_time('mysql'),
        ]);

        return $result !== null;
    }

    /**
     * Check if ML service is available
     *
     * Performs a health check on the ML service and caches the result
     * for 2 minutes to avoid excessive requests.
     *
     * @return bool True if service is available and responding, false otherwise
     */
    public function is_available(): bool {
        // Check cache first
        $cache_key = 'isf_ml_health_check';
        $cached_status = get_transient($cache_key);
        if ($cached_status !== false) {
            return (bool) $cached_status;
        }

        $result = $this->make_request('GET', '/health', []);
        $is_available = $result !== null;

        // Cache the health check result
        set_transient($cache_key, $is_available ? 1 : 0, self::HEALTH_CACHE_TTL);

        return $is_available;
    }

    /**
     * Make HTTP request to ML service
     *
     * Helper method for making authenticated requests to the ML microservice.
     * Handles errors gracefully and logs failures.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $endpoint API endpoint path (e.g., '/form/predict')
     * @param array $data Request body data for POST requests
     * @return array|null Decoded response body on success, null on failure
     *
     * @access private
     */
    private function make_request(string $method, string $endpoint, array $data = []): ?array {
        if (!$this->service_url) {
            error_log('[FormFlow ML] ML service URL not configured');
            return null;
        }

        $url = $this->service_url . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-ML-API-Key' => $this->api_key,
                'User-Agent' => 'FormFlow/' . ISF_VERSION . ' (WordPress)',
            ],
            'timeout' => 10,
            'sslverify' => true,
        ];

        // Add body for POST requests
        if ($method === 'POST' && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        try {
            if ($method === 'GET') {
                $response = wp_remote_get($url, $args);
            } else {
                $response = wp_remote_post($url, $args);
            }

            // Check for HTTP errors
            if (is_wp_error($response)) {
                error_log(sprintf(
                    '[FormFlow ML] HTTP request failed: %s',
                    $response->get_error_message()
                ));
                return null;
            }

            // Check response code
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code < 200 || $status_code >= 300) {
                $body = wp_remote_retrieve_body($response);
                error_log(sprintf(
                    '[FormFlow ML] Request to %s failed with status %d: %s',
                    $endpoint,
                    $status_code,
                    substr($body, 0, 200)
                ));
                return null;
            }

            // Parse JSON response
            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            if (!is_array($result)) {
                error_log('[FormFlow ML] Invalid JSON response from service');
                return null;
            }

            return $result;

        } catch (\Exception $e) {
            error_log(sprintf(
                '[FormFlow ML] Exception during request: %s',
                $e->getMessage()
            ));
            return null;
        }
    }
}
